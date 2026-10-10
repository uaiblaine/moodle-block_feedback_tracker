<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Per-(courseid, groupid) rollup recompute service.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\day_counter;
use block_feedback_tracker\local\score\responsiveness_calculator;

/**
 * Reads the per-submission ledger and produces one row in
 * {block_feedback_tracker_group}. Called by the adhoc `recompute_one` task
 * (queued by the drain_queue task and by the submission_graded observer) and
 * by the recompute CLIs.
 *
 * Metrics split into three groups:
 * - Pending counts (pending / critical / overgoal) — current backlog state.
 * - Graded stats over the trend_window_days window (medians / p90 / max /
 *   compliance) — historical responsiveness, used by the score formula.
 * - Trend (rolling 7d vs prior 7d median) — week-over-week direction of travel.
 */
class rollup_service {
    /**
     * Default graded-stats window in days (compliance / median / counts), used when the
     * trend_window_days setting is unset. Despite the name, the trend uses TREND_COMPARE_DAYS.
     */
    public const TREND_WINDOW_DAYS = 30;

    /** Rolling window (days) for the trend comparison — a fixed weekly cycle. */
    public const TREND_COMPARE_DAYS = 7;

    /**
     * Cap (±%) for trend_pct_30d. The raw ratio is unbounded when the
     * prior-window median is near zero and would overflow the NUMBER(6,2)
     * column. Clamping loses no signal: the score's trend term already
     * saturates at ±100%.
     */
    public const TREND_PCT_CAP = 999.99;

    /**
     * Recompute and upsert the rollup row for one (courseid, groupid).
     *
     * Guarded by a non-blocking Lock API lock keyed on the tuple: when two
     * workers race on the same (courseid, groupid) — e.g. two recompute_one
     * adhoc tasks, or a CLI recompute and an adhoc task — the second arrival
     * returns without recomputing.
     *
     * Callers that retire a queue entry afterwards must not do so when this
     * returns false: the tuple would be dequeued without anyone having
     * recomputed it, leaving the materialized rollup stale until some later
     * event touches the same tuple.
     *
     * @param int $courseid
     * @param int $groupid
     * @param int|null $now Override "now" for tests; defaults to time().
     * @return bool True when the rollup was recomputed, false when the lock
     *              was held elsewhere and this call did nothing.
     */
    public static function recompute_group(int $courseid, int $groupid, ?int $now = null): bool {
        [$lock, $proceed] = self::acquire_recompute_lock($courseid, $groupid);
        if (!$proceed) {
            return false;
        }
        try {
            self::recompute_group_locked($courseid, $groupid, $now);
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }
        return true;
    }

    /**
     * Body of recompute_group(), invoked once the per-tuple lock is held (or
     * the lock factory was unavailable and the recompute runs unlocked).
     *
     * @param int $courseid
     * @param int $groupid
     * @param int|null $now
     * @return void
     */
    private static function recompute_group_locked(int $courseid, int $groupid, ?int $now): void {
        global $DB;
        $now = $now ?? time();

        $windowdays = (int) (get_config('block_feedback_tracker', 'trend_window_days') ?: self::TREND_WINDOW_DAYS);
        if ($windowdays <= 0) {
            $windowdays = self::TREND_WINDOW_DAYS;
        }
        $windowsec = $windowdays * 86400;
        $cutoffrecent = $now - $windowsec;

        $slagoal = (float) (get_config('block_feedback_tracker', 'sla_goal_hours') ?: 24);
        $thresholds = bucket::parse_thresholds_eff();
        $criticalmin = $thresholds[2];
        /* Business-days SLA goal: the day-ruler twin of sla_goal_hours, and
         * like it the bound of both the over-goal count and compliance. The
         * critical cutoff is the third day threshold, as the hour one is the
         * third hour threshold. All of these day figures are display-only; the
         * score reads the hour-based counts and compliance. */
        $slagoaldays = (float) (get_config('block_feedback_tracker', 'sla_goal_days') ?: 2);
        $daycrit = bucket::parse_thresholds_days()[2];

        /* 1. Pending counts, submitted work only (see submission_status). The
         * activity is resolved as the ledger writer resolves it, and LEFT
         * joined so a row whose activity is gone still counts as pending. */
        $pendingrows = $DB->get_records_sql(
            "SELECT sub.id, sub.effectivehours, sub.waitinghours, sub.timesubmitted, sub.timeallocated,
                    a.markingworkflow, a.markingallocation
               FROM {block_feedback_tracker_sub} sub
          LEFT JOIN {course_modules} cm ON cm.id = sub.cmid
          LEFT JOIN {modules} m ON m.id = cm.module AND m.name = :modname
          LEFT JOIN {assign} a ON a.id = cm.instance AND m.id IS NOT NULL
              WHERE sub.courseid = :courseid AND sub.groupid = :groupid AND sub.timegraded IS NULL
                AND sub.submissionstatus = :substatus
                AND sub.islatest = 1 AND sub.iscurrent = 1",
            [
                'modname' => 'assign',
                'courseid' => $courseid,
                'groupid' => $groupid,
                'substatus' => submission_status::SUBMITTED,
            ]
        );
        $pending = count($pendingrows);
        $critical = 0;
        $overgoal = 0;
        $criticaldays = 0;
        $overgoaldays = 0;
        /* Pending work nobody has been made responsible for yet. Only an
         * activity that allocates markers can leave work unallocated, so the
         * count covers those activities alone, and stays null when no pending
         * row belongs to one. Allocation needs marking workflow as well, as in
         * mod_assign, which clears markingallocation when workflow is off. */
        $unallocated = 0;
        $allocating = false;
        foreach ($pendingrows as $r) {
            if ((int) $r->markingworkflow === 1 && (int) $r->markingallocation === 1) {
                $allocating = true;
                if ($r->timeallocated === null) {
                    $unallocated++;
                }
            }
            $eff = (float) ($r->effectivehours ?? 0.0);
            // Date-based elapsed days (pending elapses up to now).
            $days = day_counter::between((int) $r->timesubmitted, $now);
            // Day-ruler partition (inclusive bounds, mirroring
            // bucket::for_effective_days): critical > crit | overgoal
            // goal..crit | within-goal the remainder.
            if ($days['business'] > $daycrit) {
                $criticaldays++;
            } else if ($days['business'] > $slagoaldays) {
                $overgoaldays++;
            }
            // Mutually-exclusive bands that partition $pending: critical (eff >=
            // criticalmin) | over-goal (goal < eff < criticalmin) | within-goal
            // (eff <= goal, derived at display as $pending - $overgoal - $critical).
            // $pending stays the total: the score and the pending-weighted course
            // score depend on it.
            if ($eff >= $criticalmin) {
                $critical++;
            } else if ($eff > $slagoal) {
                $overgoal++;
            }
        }

        // 2. Last-window graded stats (submitted work only).
        $gradedrows = $DB->get_records_select(
            'block_feedback_tracker_sub',
            'courseid = :courseid AND groupid = :groupid AND timegraded IS NOT NULL'
                . ' AND timegraded >= :cutoff AND submissionstatus = :substatus',
            [
                'courseid' => $courseid,
                'groupid' => $groupid,
                'cutoff' => $cutoffrecent,
                'substatus' => submission_status::SUBMITTED,
            ],
            '',
            'id, effectivehours, waitinghours, timesubmitted, timegraded, queuehours, allochours'
        );
        $effvals = [];
        $rawvals = [];
        $effdays = [];
        $percdays = [];
        $compliantcount = 0;
        $compliantdayscount = 0;
        $queuevals = [];
        $allocvals = [];
        foreach ($gradedrows as $r) {
            $eff = (float) ($r->effectivehours ?? 0.0);
            $raw = (float) ($r->waitinghours ?? 0.0);
            $effvals[] = $eff;
            $rawvals[] = $raw;
            // Date-based elapsed days (submit -> grade).
            $days = day_counter::between((int) $r->timesubmitted, (int) $r->timegraded);
            $effdays[] = $days['business'];
            $percdays[] = $days['calendar'];
            if ($eff <= $slagoal) {
                $compliantcount++;
            }
            // Day-ruler compliance twin: graded within the business-days SLA
            // goal. Inclusive bound, mirroring bucket::for_effective_days.
            if ($days['business'] <= $slagoaldays) {
                $compliantdayscount++;
            }
            /* Queue and marker hours are collected only where they were
             * measured; alloc_coverage_pct below says how much of the window
             * that is. */
            if ($r->queuehours !== null) {
                $queuevals[] = (float) $r->queuehours;
            }
            if ($r->allochours !== null) {
                $allocvals[] = (float) $r->allochours;
            }
        }
        $numgraded30d = count($gradedrows);

        $medianeff = $numgraded30d ? stats::median($effvals) : null;
        $p90eff    = $numgraded30d ? stats::percentile($effvals, 90.0) : null;
        $maxeff    = $numgraded30d ? stats::max_value($effvals) : null;
        $medianraw = $numgraded30d ? stats::median($rawvals) : null;
        $p90raw    = $numgraded30d ? stats::percentile($rawvals, 90.0) : null;
        $maxraw    = $numgraded30d ? stats::max_value($rawvals) : null;
        $compliancepct = $numgraded30d ? round(100.0 * $compliantcount / $numgraded30d, 2) : null;
        // Display-only business-days compliance (not fed to the score).
        $compliancepctdays = $numgraded30d ? round(100.0 * $compliantdayscount / $numgraded30d, 2) : null;

        /* Coordination queue vs marker turnaround. Coverage is the share of
         * the graded window that carries a marker measurement: before Moodle
         * 5.2 only one of mod_assign's three allocation paths (the batch
         * action) fires marker_updated, so the sample is partial and the
         * median must be read beside the coverage. */
        $medianqueue = !empty($queuevals) ? stats::median($queuevals) : null;
        $medianalloc = !empty($allocvals) ? stats::median($allocvals) : null;
        $alloccoverage = $numgraded30d ? round(100.0 * count($allocvals) / $numgraded30d, 2) : null;

        // 2b. Headline "current" medians over graded-in-window plus pending
        // work, so the display reflects the live backlog. Display only; the
        // score uses the graded-only $medianeff. Not a merge of the two sets
        // above, which would count an attempt twice (see current_state_values()).
        $current = self::current_state_values($courseid, $groupid, $cutoffrecent, $now);
        $curmedianeff = !empty($current['eff']) ? stats::median($current['eff']) : null;
        $curmedianraw = !empty($current['raw']) ? stats::median($current['raw']) : null;
        // Date-based day medians (graded ∪ pending) — the headline in days mode.
        $curmedianeffdays = !empty($current['effdays']) ? stats::median($current['effdays']) : null;
        $curmedianpercdays = !empty($current['percdays']) ? stats::median($current['percdays']) : null;

        // 3. Trend: median effective hours of the last 7 days vs the 7 days
        // before (submitted, graded work only). Deliberately shorter than the
        // stats window above so it reacts week over week. Stored in the
        // legacy-named trend_pct_30d column.
        $trendsec = self::TREND_COMPARE_DAYS * 86400;
        $trendrecent = self::graded_eff_hours($courseid, $groupid, $now - $trendsec, $now);
        $trendprior = self::graded_eff_hours($courseid, $groupid, $now - 2 * $trendsec, $now - $trendsec);
        $trendrecentmedian = !empty($trendrecent) ? stats::median($trendrecent) : null;
        $trendpriormedian = !empty($trendprior) ? stats::median($trendprior) : null;
        $trendpct = null;
        if ($trendrecentmedian !== null && $trendpriormedian !== null && $trendpriormedian > 0.0) {
            $trendpct = round(100.0 * ($trendrecentmedian - $trendpriormedian) / $trendpriormedian, 2);
            $trendpct = max(-self::TREND_PCT_CAP, min(self::TREND_PCT_CAP, $trendpct));
        }

        // 4. Score.
        $scoredata = responsiveness_calculator::compute([
            'compliance_pct' => $compliancepct,
            'median_eff_h'   => $medianeff,
            'critical'       => $critical,
            'pending'        => $pending,
            'numgraded30d'   => $numgraded30d,
            'trend_pct_30d'  => $trendpct,
        ]);

        // 5. Upsert.
        $existing = $DB->get_record(
            'block_feedback_tracker_group',
            ['courseid' => $courseid, 'groupid' => $groupid],
            'id'
        );
        $components = $scoredata['components'];
        $record = (object) [
            'courseid'             => $courseid,
            'groupid'              => $groupid,
            'pending'              => $pending,
            'critical'             => $critical,
            'overgoal'             => $overgoal,
            'numgraded30d'         => $numgraded30d,
            'compliance_pct'       => $compliancepct,
            'median_raw_h'         => $medianraw,
            'p90_raw_h'            => $p90raw,
            'max_raw_h'            => $maxraw,
            'median_eff_h'         => $medianeff,
            'p90_eff_h'            => $p90eff,
            'max_eff_h'            => $maxeff,
            'cur_median_eff_h'     => $curmedianeff,
            'cur_median_raw_h'     => $curmedianraw,
            'cur_median_eff_days'  => $curmedianeffdays,
            'cur_median_perc_days' => $curmedianpercdays,
            'critical_days'        => $criticaldays,
            'overgoal_days'        => $overgoaldays,
            'compliance_pct_days'  => $compliancepctdays,
            'responsiveness_score' => $scoredata['score'],
            'score_band'           => $scoredata['band'],
            'comp_compliance'      => $components['compliance'] ?? null,
            'comp_median'          => $components['median'] ?? null,
            'comp_critical'        => $components['critical'] ?? null,
            'comp_pending'         => $components['pending'] ?? null,
            'comp_trend'           => $components['trend'] ?? null,
            'trend_pct_30d'        => $trendpct,
            'unallocated'          => $allocating ? $unallocated : null,
            'median_queue_h'       => $medianqueue,
            'median_alloc_h'       => $medianalloc,
            'alloc_coverage_pct'   => $alloccoverage,
            'timerecomputed'       => $now,
            'timemodified'         => $now,
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('block_feedback_tracker_group', $record);
        } else {
            $DB->insert_record('block_feedback_tracker_group', $record);
        }
    }

    /**
     * Value arrays for the "state right now" headline medians.
     *
     * One live observation per attempt: `iscurrent = 1` excludes cycles a
     * resubmission has already closed, so an attempt whose earlier cycle was
     * graded inside the window and whose current cycle is pending contributes
     * once — as pending, which is what it actually is. Graded rows elapse to
     * their grading instant, pending rows to now.
     *
     * @param int $courseid
     * @param int $groupid
     * @param int $cutoff Graded-window start (inclusive, epoch seconds).
     * @param int $now
     * @return array{eff:array, raw:array, effdays:array, percdays:array}
     */
    private static function current_state_values(int $courseid, int $groupid, int $cutoff, int $now): array {
        global $DB;

        $rows = $DB->get_records_select(
            'block_feedback_tracker_sub',
            'courseid = :courseid AND groupid = :groupid'
                . ' AND submissionstatus = :substatus'
                . ' AND islatest = 1 AND iscurrent = 1'
                . ' AND (timegraded IS NULL OR timegraded >= :cutoff)',
            [
                'courseid' => $courseid,
                'groupid' => $groupid,
                'substatus' => submission_status::SUBMITTED,
                'cutoff' => $cutoff,
            ],
            '',
            'id, effectivehours, waitinghours, timesubmitted, timegraded'
        );

        $out = ['eff' => [], 'raw' => [], 'effdays' => [], 'percdays' => []];
        foreach ($rows as $r) {
            $out['eff'][] = (float) ($r->effectivehours ?? 0.0);
            $out['raw'][] = (float) ($r->waitinghours ?? 0.0);
            $upper = $r->timegraded !== null ? (int) $r->timegraded : $now;
            $days = day_counter::between((int) $r->timesubmitted, $upper);
            $out['effdays'][] = $days['business'];
            $out['percdays'][] = $days['calendar'];
        }
        return $out;
    }

    /**
     * Median-ready effective-hours values for submitted + graded work in
     * [$start, $end) for one (course, group). Powers the rolling weekly trend.
     *
     * @param int $courseid
     * @param int $groupid
     * @param int $start  Window start (inclusive, epoch seconds).
     * @param int $end    Window end (exclusive, epoch seconds).
     * @return array<int, float>
     */
    private static function graded_eff_hours(int $courseid, int $groupid, int $start, int $end): array {
        global $DB;
        $rows = $DB->get_records_select(
            'block_feedback_tracker_sub',
            'courseid = :courseid AND groupid = :groupid AND timegraded IS NOT NULL'
                . ' AND timegraded >= :start AND timegraded < :end'
                . ' AND submissionstatus = :substatus',
            [
                'courseid'  => $courseid,
                'groupid'   => $groupid,
                'start'     => $start,
                'end'       => $end,
                'substatus' => submission_status::SUBMITTED,
            ],
            '',
            'id, effectivehours'
        );
        $out = [];
        foreach ($rows as $r) {
            if ($r->effectivehours !== null) {
                $out[] = (float) $r->effectivehours;
            }
        }
        return $out;
    }

    /**
     * Acquire a non-blocking lock scoped to one (courseid, groupid) tuple.
     *
     * Returns [$lock, $proceed].
     *  - $proceed=true, $lock=lock object: acquired; caller must release.
     *  - $proceed=true, $lock=null: lock factory unavailable; run without it.
     *  - $proceed=false: another worker holds the lock; caller skips silently.
     *
     * @param int $courseid
     * @param int $groupid
     * @return array{0:\core\lock\lock|null, 1:bool}
     */
    private static function acquire_recompute_lock(int $courseid, int $groupid): array {
        try {
            $factory = \core\lock\lock_config::get_lock_factory('block_feedback_tracker');
        } catch (\Throwable $e) {
            debugging(sprintf(
                'rollup_service: lock factory unavailable for courseid=%d groupid=%d (%s); '
                    . 'proceeding without lock',
                $courseid,
                $groupid,
                $e->getMessage()
            ));
            return [null, true];
        }
        $resource = "rollup_{$courseid}_{$groupid}";
        $lock = $factory->get_lock($resource, 0);
        if ($lock === false) {
            return [null, false];
        }
        return [$lock, true];
    }
}
