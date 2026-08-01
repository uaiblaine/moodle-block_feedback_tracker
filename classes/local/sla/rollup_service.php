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

use block_feedback_tracker\local\calendar\calendar;
use block_feedback_tracker\local\calendar\day_counter;
use block_feedback_tracker\local\calendar\pause_lookup;
use block_feedback_tracker\local\score\responsiveness_calculator;

/**
 * Reads the per-submission ledger and produces one row in
 * {block_feedback_tracker_group}. Called by the drain task for tuples in the
 * dirty queue and by the adhoc `recompute_one` task right after grading.
 *
 * Metrics split into three groups:
 * - Pending counts (pending / critical / overgoal) — current backlog state.
 * - Last-30d graded stats (medians / p90 / max / compliance) — historical
 *   responsiveness, used by the score formula.
 * - Trend (rolling 7d vs prior 7d median) — week-over-week direction of travel.
 *
 * Plus auxiliary `nextpause_*` / `lastpause_*` columns to power the dashboard
 * "next pause: May 25 (holiday)" indicator without extra read-path queries.
 */
class rollup_service {
    /** Recent-stats window length in days (compliance / median / counts). */
    public const TREND_WINDOW_DAYS = 30;

    /** Rolling window (days) for the trend comparison — a fixed weekly cycle. */
    public const TREND_COMPARE_DAYS = 7;

    /**
     * Display cap (±%) for trend_pct_30d. The raw ratio is unbounded when the
     * prior-window median is near zero (e.g. 0.06h → 227h ≈ 118950%), which
     * overflows the NUMBER(6,2) column. A regression beyond ~900% already
     * reads as "far worse", so clamping here loses no signal — and the score's
     * trend term saturates via clamp01() long before this bound.
     */
    public const TREND_PCT_CAP = 999.99;

    /**
     * Recompute and upsert the rollup row for one (courseid, groupid).
     *
     * Guarded by a non-blocking Moodle Lock API lock keyed on the tuple. When
     * two workers race on the same (courseid, groupid) — e.g. a drain_queue
     * tick and a recompute_one adhoc task — the second arrival returns
     * silently. The math is idempotent so the winning worker produces the
     * same rollup row either way; the lock just elides duplicate I/O.
     *
     * The return value distinguishes "recomputed" from "skipped because
     * another worker held the lock". Callers that retire a queue entry
     * afterwards must not do so on a skip: the tuple would be dequeued
     * without anyone having recomputed it, leaving the materialized rollup
     * stale until some later event happens to touch the same tuple.
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
     * the lock store proved unavailable and we fell back to running uncoupled).
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
        // Day-ruler bounds for the critical_days/overgoal_days twins (the
        // hour-based critical/overgoal above keep feeding the score).
        $daythresholds = bucket::parse_thresholds_days();
        $daygoal = $daythresholds[0];
        $daycrit = $daythresholds[2];
        // Business-days SLA goal for the display-only compliance_pct_days
        // twin (the hour-based compliance_pct above keeps feeding the score).
        $slagoaldays = (float) (get_config('block_feedback_tracker', 'sla_goal_days') ?: 2);

        // 1. Pending counts. Only genuinely submitted work counts toward the
        // SLA — draft / new / reopened attempts are awaiting the student, not
        // the teacher, so they are excluded here (and everywhere downstream).
        $pendingrows = $DB->get_records_select(
            'block_feedback_tracker_sub',
            'courseid = :courseid AND groupid = :groupid AND timegraded IS NULL'
                . ' AND submissionstatus = :substatus',
            [
                'courseid' => $courseid,
                'groupid' => $groupid,
                'substatus' => submission_status::SUBMITTED,
            ],
            '',
            'id, effectivehours, waitinghours, timesubmitted'
        );
        $pending = count($pendingrows);
        $critical = 0;
        $overgoal = 0;
        $criticaldays = 0;
        $overgoaldays = 0;
        $pendingeffvals = [];
        $pendingrawvals = [];
        $pendingeffdays = [];
        $pendingpercdays = [];
        foreach ($pendingrows as $r) {
            $eff = (float) ($r->effectivehours ?? 0.0);
            $pendingeffvals[] = $eff;
            $pendingrawvals[] = (float) ($r->waitinghours ?? 0.0);
            // Date-based elapsed days (pending elapses up to now).
            $days = day_counter::between((int) $r->timesubmitted, $now);
            $pendingeffdays[] = $days['business'];
            $pendingpercdays[] = $days['calendar'];
            // Day-ruler partition (inclusive bounds, mirroring
            // bucket::for_effective_days): critical > crit | overgoal
            // goal..crit | within-goal the remainder.
            if ($days['business'] > $daycrit) {
                $criticaldays++;
            } else if ($days['business'] > $daygoal) {
                $overgoaldays++;
            }
            // Mutually-exclusive pending bands that partition $pending, so the
            // three displayed counts sum to the total: critical (eff >=
            // criticalmin) | over-goal (goal < eff < criticalmin) | within-goal
            // (the remainder, eff <= goal, derived at display as
            // $pending - $overgoal - $critical). $pending stays the total — the
            // score and the overall-weighting depend on it.
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
            'id, effectivehours, waitinghours, timesubmitted, timegraded'
        );
        $effvals = [];
        $rawvals = [];
        $effdays = [];
        $percdays = [];
        $compliantcount = 0;
        $compliantdayscount = 0;
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

        // 2b. Headline "current" medians — graded-in-window plus currently
        // pending work — so the dashboard's effective / perceived times
        // reflect the live backlog instead of reading ~0 when little has been
        // graded. These feed the display only; the score keeps using the
        // graded-only $medianeff above.
        $cureffvals = array_merge($effvals, $pendingeffvals);
        $currawvals = array_merge($rawvals, $pendingrawvals);
        $curmedianeff = !empty($cureffvals) ? stats::median($cureffvals) : null;
        $curmedianraw = !empty($currawvals) ? stats::median($currawvals) : null;
        // Date-based day medians (graded ∪ pending) — the headline in days mode.
        $cureffdays = array_merge($effdays, $pendingeffdays);
        $curpercdays = array_merge($percdays, $pendingpercdays);
        $curmedianeffdays = !empty($cureffdays) ? stats::median($cureffdays) : null;
        $curmedianpercdays = !empty($curpercdays) ? stats::median($curpercdays) : null;

        // 3. Trend — rolling 7-day cycle: this week's median effective hours vs
        // the prior week's (submitted, graded work only). A deliberately
        // SEPARATE, shorter window from the 30-day stats above, so the trend
        // reacts week-over-week while compliance / median / counts keep their
        // monthly view. Stored in the legacy-named trend_pct_30d column.
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

        // 5. Next + last pause indicators.
        [$nextts, $nextreason, $nextnote] = self::next_pause_indicator($courseid, $groupid, $now);
        [$lastendts, $lastreason] = self::last_pause_indicator($courseid, $groupid, $now);

        // 6. Upsert.
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
            'nextpause_ts'         => $nextts,
            'nextpause_reason'     => $nextreason,
            'nextpause_note'       => $nextnote,
            'lastpause_endts'      => $lastendts,
            'lastpause_reason'     => $lastreason,
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
     *  - $proceed=true, $lock=null: lock store unavailable; run without it.
     *  - $proceed=false: another worker holds the lock; caller skips silently.
     *
     * Resource key uses `_` not `:` because some lock-store backends (notably
     * the file store) treat `:` as a path separator.
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

    /**
     * Find the next pause that affects this (course, group) within 30 days.
     * Considers both cday-driven holidays/recesses/closures and overlapping
     * cpause windows.
     *
     * @param int $courseid
     * @param int $groupid
     * @param int $now
     * @return array{0:?int, 1:?string, 2:?string} [ts, reason, note]
     */
    private static function next_pause_indicator(int $courseid, int $groupid, int $now): array {
        global $DB;
        $horizon = $now + 30 * 86400;

        $tz = calendar::timezone();
        $todayymd = (int) (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Ymd');
        $horizonymd = (int) (new \DateTimeImmutable('@' . $horizon))->setTimezone($tz)->format('Ymd');

        $candidates = [];

        $cdays = $DB->get_records_select(
            'block_feedback_tracker_cday',
            'daydate >= :today AND daydate <= :horizon AND daytype IN (:t1, :t2, :t3, :t4)',
            [
                'today'   => $todayymd,
                'horizon' => $horizonymd,
                't1' => calendar::DAYTYPE_HOLIDAY,
                't2' => calendar::DAYTYPE_RECESS,
                't3' => calendar::DAYTYPE_CLOSED,
                // V1.0.9 — optional days surface as paused too. Sub-day
                // event rows resolve their start to ymd + starttime*60 so
                // PausedNote can show "Paused 16:00-18:00: {label}".
                't4' => calendar::DAYTYPE_OPTIONAL,
            ],
            'daydate ASC',
            'id, daydate, daytype, starttime, endtime, note',
            0,
            10
        );
        foreach ($cdays as $row) {
            $daystart = self::ymd_to_ts((int) $row->daydate, $tz);
            $issubday = (string) $row->daytype === calendar::DAYTYPE_OPTIONAL
                && $row->starttime !== null;
            $ts = $issubday ? $daystart + ((int) $row->starttime) * 60 : $daystart;
            if ($ts > $now) {
                $candidates[] = [$ts, (string) $row->daytype, $row->note !== null ? (string) $row->note : null];
            }
        }

        $pauses = pause_lookup::for_course_group($courseid, $groupid, $now, $horizon);
        foreach ($pauses as $p) {
            $start = (int) $p->timestart;
            if ($start > $now) {
                $reason = self::cpause_reason((string) $p->scopelevel);
                $note = $p->note !== null ? (string) $p->note : null;
                $candidates[] = [$start, $reason, $note];
            }
        }

        if (empty($candidates)) {
            return [null, null, null];
        }
        usort($candidates, static fn($a, $b) => $a[0] <=> $b[0]);
        return $candidates[0];
    }

    /**
     * Find the most recent pause that ended at or before now (within last 7 days).
     *
     * @param int $courseid
     * @param int $groupid
     * @param int $now
     * @return array{0:?int, 1:?string} [endts, reason]
     */
    private static function last_pause_indicator(int $courseid, int $groupid, int $now): array {
        global $DB;
        $lookback = $now - 7 * 86400;

        $tz = calendar::timezone();
        $todayymd = (int) (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Ymd');
        $lookbackymd = (int) (new \DateTimeImmutable('@' . $lookback))->setTimezone($tz)->format('Ymd');

        $candidates = [];

        $cdays = $DB->get_records_select(
            'block_feedback_tracker_cday',
            'daydate >= :lookback AND daydate <= :today AND daytype IN (:t1, :t2, :t3, :t4)',
            [
                'lookback' => $lookbackymd,
                'today'    => $todayymd,
                't1' => calendar::DAYTYPE_HOLIDAY,
                't2' => calendar::DAYTYPE_RECESS,
                't3' => calendar::DAYTYPE_CLOSED,
                // V1.0.9 — optional days surface as paused too.
                't4' => calendar::DAYTYPE_OPTIONAL,
            ],
            'daydate DESC',
            'id, daydate, daytype, starttime, endtime',
            0,
            10
        );
        foreach ($cdays as $row) {
            $daystart = self::ymd_to_ts((int) $row->daydate, $tz);
            $issubday = (string) $row->daytype === calendar::DAYTYPE_OPTIONAL
                && $row->starttime !== null && $row->endtime !== null;
            $endts = $issubday
                ? $daystart + ((int) $row->endtime) * 60
                : $daystart + 86400;
            if ($endts <= $now) {
                $candidates[] = [$endts, (string) $row->daytype];
            }
        }

        $pauses = pause_lookup::for_course_group($courseid, $groupid, $lookback, $now);
        foreach ($pauses as $p) {
            if ($p->timeend !== null && (int) $p->timeend <= $now && (int) $p->timeend >= $lookback) {
                $candidates[] = [(int) $p->timeend, self::cpause_reason((string) $p->scopelevel)];
            }
        }

        if (empty($candidates)) {
            return [null, null];
        }
        usort($candidates, static fn($a, $b) => $b[0] <=> $a[0]);
        return $candidates[0];
    }

    /**
     * Convert a YYYYMMDD int to a unix timestamp at midnight in a timezone.
     *
     * @param int $ymd
     * @param \DateTimeZone $tz
     * @return int
     */
    private static function ymd_to_ts(int $ymd, \DateTimeZone $tz): int {
        $year = (int) substr((string) $ymd, 0, 4);
        $month = (int) substr((string) $ymd, 4, 2);
        $day = (int) substr((string) $ymd, 6, 2);
        return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), $tz))
            ->getTimestamp();
    }

    /**
     * Translate a cpause scopelevel to a stable reason slug.
     *
     * @param string $scopelevel
     * @return string
     */
    private static function cpause_reason(string $scopelevel): string {
        switch ($scopelevel) {
            case 'course':
                return 'coursepaused';
            case 'group':
                return 'grouppaused';
            case 'site':
            default:
                return 'sitepaused';
        }
    }
}
