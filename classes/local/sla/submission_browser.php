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
 * Shared submission browser — filtered / sorted / paginated reads of the
 * ledger that back the report page's pending and graded tables.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * One query family powering the report page's submission tables. Both the
 * pending (`get_pending_submissions`) and graded (`get_graded_submissions`)
 * web services delegate here so the visibility scoping, search, sort, paging,
 * and band-distribution counts live in a single place.
 *
 * Read-time only: the SUBMITTED-only SLA scope is applied here (graded =
 * submitted work that now carries a timegraded; pending = submitted work that
 * does not). Drafts are a separate, de-emphasised mode and never count toward
 * the SLA.
 */
class submission_browser {
    /** Pending mode: submitted work awaiting feedback (timegraded IS NULL). */
    public const MODE_PENDING = 'pending';
    /* Graded mode is deliberately ALL-TIME while every other graded consumer is
     * windowed to 30 days. This tab is an audit surface: a teacher opens it to
     * see everything they have returned. The 30-day windows elsewhere feed the
     * score and the medians, which have to be comparable across groups. Two
     * different purposes, so the divergence is intentional — do not align them. */
    /** Graded mode: submitted work already returned (timegraded IS NOT NULL). */
    public const MODE_GRADED = 'graded';
    /** Draft mode: saved-but-not-submitted work (never counts toward the SLA). */
    public const MODE_DRAFT = 'draft';

    /** Hard upper bound on a page size to discourage all-the-things reads. */
    public const MAX_PAGE_SIZE = 200;

    /**
     * Browse the ledger for one course as seen by one user.
     *
     * Callers (web services) are responsible for context validation and the
     * viewresponsiveness capability check before invoking this. Group-level
     * visibility (separate groups / accessallgroups) is applied here.
     *
     * @param int $courseid Course id.
     * @param int $userid   Viewing user (for group visibility scoping).
     * @param array $opts Filter / sort / page options: mode, groupid, search,
     *              bucket, band, sort, order, page, perpage.
     * @return array{total:int, counts:array<string, int>, rows:array<int, array<string, mixed>>}
     */
    public static function browse(int $courseid, int $userid, array $opts): array {
        global $DB;

        $mode = ($opts['mode'] ?? self::MODE_PENDING);
        if (!in_array($mode, [self::MODE_PENDING, self::MODE_GRADED, self::MODE_DRAFT], true)) {
            $mode = self::MODE_PENDING;
        }
        $groupid = max(0, (int) ($opts['groupid'] ?? 0));
        $search = trim((string) ($opts['search'] ?? ''));
        $bucket = trim((string) ($opts['bucket'] ?? ''));
        $band = trim((string) ($opts['band'] ?? ''));
        $sort = (string) ($opts['sort'] ?? '');
        $order = strtolower((string) ($opts['order'] ?? '')) === 'asc' ? 'ASC' : 'DESC';
        $page = max(0, (int) ($opts['page'] ?? 0));
        $perpage = max(1, min((int) ($opts['perpage'] ?? 25), self::MAX_PAGE_SIZE));

        // Respect Moodle group mode: SEPARATEGROUPS without accessallgroups
        // must not leak rows from groups the user can't see.
        $visibleids = group_access::visible_group_ids($courseid, $userid);
        if ($visibleids !== null && empty($visibleids)) {
            return ['total' => 0, 'counts' => self::zero_counts($mode), 'rows' => []];
        }

        // Shared FROM + JOINs. The assign join lets the activity name be both
        // searched and selected directly; groups is LEFT so the ungrouped
        // bucket (groupid 0) still returns rows.
        $from = "FROM {block_feedback_tracker_sub} sub
                  JOIN {user} u ON u.id = sub.userid
                  JOIN {course_modules} cm ON cm.id = sub.cmid
                  JOIN {assign} a ON a.id = cm.instance
             LEFT JOIN {groups} g ON g.id = sub.groupid";

        // Base predicate: course + mode + visibility + group + search. The
        // band/bucket filter is layered on top separately so the distribution
        // counts can ignore it (and stay switchable).
        [$basewhere, $baseparams] = self::base_where(
            $courseid,
            $mode,
            $visibleids,
            $groupid,
            $search
        );

        // Distribution counts over the base set (band/bucket filter excluded).
        $counts = self::counts($from, $basewhere, $baseparams, $mode);

        // Graded results use a three-band set (Excellent / Good / Regular):
        // critical results are rare and fold into Regular, mirroring the
        // academic-days strip. The critical band is kept (as zero) so the WS
        // return shape is unchanged.
        if ($mode === self::MODE_GRADED) {
            $counts[bucket::REGULAR] += $counts[bucket::CRITICAL];
            $counts[bucket::CRITICAL] = 0;
        }

        // Layer the band/bucket filter for the displayed rows + their total.
        [$rowswhere, $rowsparams] = self::apply_band_filter($basewhere, $baseparams, $mode, $band, $bucket);

        $total = (int) $DB->count_records_sql("SELECT COUNT(1) $from WHERE $rowswhere", $rowsparams);

        $select = "SELECT sub.id, sub.cmid, sub.userid, sub.groupid, sub.timesubmitted,
                          sub.timegraded, sub.timemarked, sub.timeclosed,
                          sub.closedsource, sub.gradehidden,
                          sub.queuehours, sub.allochours,
                          sub.waitinghours, sub.effectivehours, sub.effectivedays,
                          sub.slabucket, sub.submissionstatus,
                          u.firstname, u.lastname, a.name AS activityname, g.name AS groupname";
        $orderby = self::order_by($mode, $sort, $order);
        $rows = $DB->get_records_sql(
            "$select $from WHERE $rowswhere ORDER BY $orderby",
            $rowsparams,
            $page * $perpage,
            $perpage
        );

        [$goal, $crit] = self::band_bounds();
        // Banding follows the global display unit: business-days mode
        // classifies by the stored elapsed-day count against the day ruler
        // (same comparisons as the filter + distribution counts), hours mode
        // keeps the stored slabucket + hour bounds.
        $usedays = bucket::use_day_thresholds();
        [$daygoal, , $daycrit] = bucket::parse_thresholds_days();
        $out = [];
        foreach ($rows as $r) {
            $eff = $r->effectivehours !== null ? (float) $r->effectivehours : 0.0;
            $gradedrow = $r->timegraded !== null;
            // Date-based elapsed days for the "business days" display unit;
            // pending rows (timegraded null) elapse up to now.
            $t2 = $gradedrow ? (int) $r->timegraded : time();
            $days = \block_feedback_tracker\local\calendar\day_counter::between((int) $r->timesubmitted, $t2);
            $storeddays = $r->effectivedays !== null ? (float) $r->effectivedays : null;
            // Displayed result band. A graded row freezes its effective
            // measure at grading time, so its band is always knowable: when
            // the stored value still resolves to the "pending" sentinel (a
            // legacy row whose effectivedays column was never backfilled, or a
            // row graded entirely within a paused window), reclassify from the
            // frozen effective measure so a graded row never shows "pending".
            $slabucket = $usedays
                ? bucket::for_effective_days($storeddays)
                : (string) $r->slabucket;
            if ($gradedrow && $slabucket === bucket::PENDING) {
                $slabucket = $usedays
                    ? bucket::for_effective_days((float) $days['business'])
                    : bucket::for_effective($eff);
            }
            // Critical graded results fold into Regular — the three-band result
            // set (Excellent / Good / Regular) the academic-days strip uses.
            if ($mode === self::MODE_GRADED && $slabucket === bucket::CRITICAL) {
                $slabucket = bucket::REGULAR;
            }
            $out[] = [
                'submissionid'     => (int) $r->id,
                'cmid'             => (int) $r->cmid,
                'userid'           => (int) $r->userid,
                'studentname'      => trim($r->firstname . ' ' . $r->lastname),
                'activityname'     => (string) ($r->activityname ?? ''),
                'groupid'          => (int) $r->groupid,
                'groupname'        => (string) ($r->groupname ?? ''),
                'timesubmitted'    => (int) $r->timesubmitted,
                'timegraded'       => $r->timegraded !== null ? (int) $r->timegraded : 0,
                'waitinghours'     => $r->waitinghours !== null ? (float) $r->waitinghours : 0.0,
                'effectivehours'   => $eff,
                'effective_days'   => $days['business'],
                'perceived_days'   => $days['calendar'],
                'slabucket'        => $slabucket,
                // Pending band using the same bounds as the distribution
                // counts + band filter so the per-row Status badge can never
                // disagree with the bar.
                'pendingband'      => $usedays
                    ? self::pending_band_days($storeddays ?? (float) $days['business'], $daygoal, $daycrit)
                    : self::pending_band($eff, $goal, $crit),
                'submissionstatus' => (string) $r->submissionstatus,
                /* Two disclosures, on the same footing as awaitingrelease
                 * below and for the same reason. A cycle closed from the
                 * gradebook was never marked inside the activity, so a teacher
                 * looking at the grading screen finds nothing there to explain
                 * it. And a grade the gradebook is hiding has reached nobody
                 * yet, whatever the activity's own screen says. */
                'closedsource'     => (string) ($r->closedsource ?? ''),
                'gradehidden'      => (int) $r->gradehidden,
                /* A mark that the marking workflow has not released is
                 * invisible to the student, and releasing frequently needs a
                 * permission the marker does not hold — so it is surfaced
                 * explicitly instead of being folded into "graded". */
                'awaitingrelease'  => (int) (
                    $r->timemarked !== null && $r->timeclosed === null
                ),
                /* The response interval split by owner: how long the work
                 * waited to be allocated, and how long the marker then took.
                 * Per-row facts, so unlike the aggregate medians they carry no
                 * sampling caveat — a row either has both stamps or reports
                 * null. */
                'queuehours'       => $r->queuehours !== null ? (float) $r->queuehours : null,
                'allochours'       => $r->allochours !== null ? (float) $r->allochours : null,
            ];
        }

        return ['total' => $total, 'counts' => $counts, 'rows' => $out];
    }

    /**
     * SQL expression for a row's elapsed-day count, with a fallback for rows
     * whose `effectivedays` was never backfilled.
     *
     * Without a fallback every day-mode predicate compares against NULL, which
     * is never true, so an un-backfilled row silently leaves every band while
     * still being counted in the total — the distribution stops summing to the
     * number of rows on screen.
     *
     * The fallback measures the same interval the stored column would have:
     * submission to grading for graded rows, submission to now for pending
     * ones (which is what `pending_recomputer` writes).
     *
     * It counts *calendar* days, because the business-day calendar engine has
     * no SQL equivalent. Calendar days are always >= business days, so the
     * estimate can only place a row in a worse band, never a better one. That
     * is the safe direction: an un-backfilled row may look more urgent than it
     * is, but it is never quietly reported as excellent — which for a priority
     * list would hide exactly the rows that need attention most.
     *
     * Transitional — once `backfill_effectivedays` completes, no row reaches
     * the fallback at all.
     *
     * @param string $mode Browse mode.
     * @param int $now Reference timestamp for pending rows.
     * @return string SQL expression usable anywhere sub.effectivedays was.
     */
    private static function days_expr(string $mode, int $now): string {
        /* Clamped at zero. A legacy row written before the cycle model could
         * hold timegraded < timesubmitted (the old code overwrote the hand-in
         * time on every re-save), and a negative day count sorts to the top of
         * a priority list — the loudest possible place for a bad number.
         * Expressed as CASE rather than GREATEST: core uses GREATEST nowhere,
         * and it is absent from SQL Server before 2022. */
        if ($mode === self::MODE_GRADED) {
            $raw = 'COALESCE(sub.effectivedays, (sub.timegraded - sub.timesubmitted) / 86400.0)';
        } else {
            // The reference time is server-generated, so inlining it keeps the
            // expression free of parameters the four call sites would each rebind.
            $raw = sprintf('COALESCE(sub.effectivedays, (%d - sub.timesubmitted) / 86400.0)', $now);
        }
        return sprintf('(CASE WHEN %s < 0 THEN 0 ELSE %s END)', $raw, $raw);
    }

    /**
     * Build the base WHERE (course + mode + visibility + group + search) and
     * its bound params. Excludes the band/bucket filter so it can be reused by
     * both the distribution counts and the row query.
     *
     * @param int $courseid Course id.
     * @param string $mode One of MODE_PENDING|MODE_GRADED|MODE_DRAFT.
     * @param int[]|null $visibleids Visible group ids, or null when unrestricted.
     * @param int $groupid Single-group filter, 0 = all visible.
     * @param string $search Free-text needle over student + activity name.
     * @return array{0:string, 1:array<string, mixed>}
     */
    private static function base_where(
        int $courseid,
        string $mode,
        ?array $visibleids,
        int $groupid,
        string $search
    ): array {
        global $DB;

        $where = 'sub.courseid = :courseid';
        $params = ['courseid' => $courseid];

        /* Open work is gated on the live state of the attempt: `islatest`
         * mirrors assign_submission.latest (core gates every one of its own
         * needs-grading reads on it, so a superseded attempt is nobody's
         * outstanding task) and `iscurrent` keeps one attempt to a single live
         * observation when a resubmission opened a later cycle.
         *
         * The graded side deliberately keeps EVERY cycle: a teacher who
         * responded twice generated two genuine response events, and dropping
         * the earlier one would re-create exactly the data loss the cycle
         * model exists to prevent. */
        if ($mode === self::MODE_DRAFT) {
            $where .= ' AND sub.submissionstatus = :substatus';
            $where .= ' AND sub.islatest = 1 AND sub.iscurrent = 1';
            $params['substatus'] = submission_status::DRAFT;
        } else {
            $where .= ' AND sub.submissionstatus = :substatus';
            $params['substatus'] = submission_status::SUBMITTED;
            $where .= $mode === self::MODE_GRADED
                ? ' AND sub.timegraded IS NOT NULL'
                : ' AND sub.timegraded IS NULL AND sub.islatest = 1 AND sub.iscurrent = 1';
        }

        if ($visibleids !== null) {
            [$gsql, $gparams] = $DB->get_in_or_equal($visibleids, SQL_PARAMS_NAMED, 'gv');
            $where .= " AND sub.groupid $gsql";
            $params += $gparams;
        }
        if ($groupid > 0) {
            $where .= ' AND sub.groupid = :groupid';
            $params['groupid'] = $groupid;
        }
        if ($search !== '') {
            $fullname = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
            $like = $DB->sql_like_escape($search);
            $where .= ' AND (' . $DB->sql_like($fullname, ':searchname', false)
                . ' OR ' . $DB->sql_like('a.name', ':searchact', false) . ')';
            $params['searchname'] = '%' . $like . '%';
            $params['searchact'] = '%' . $like . '%';
        }

        return [$where, $params];
    }

    /**
     * Layer the band (pending effective-hours ranges) or bucket (graded
     * slabucket) filter on top of the base WHERE.
     *
     * @param string $basewhere Base predicate from base_where().
     * @param array $baseparams Base params.
     * @param string $mode Browse mode.
     * @param string $band aguardando|atencao|prioridade (pending/draft only).
     * @param string $bucket excellent|good|regular|critical (slabucket).
     * @return array{0:string, 1:array<string, mixed>}
     */
    private static function apply_band_filter(
        string $basewhere,
        array $baseparams,
        string $mode,
        string $band,
        string $bucket
    ): array {
        $where = $basewhere;
        $params = $baseparams;

        $usedays = bucket::use_day_thresholds();

        if ($mode === self::MODE_GRADED) {
            // Graded result band: the stored slabucket in hours mode, or the
            // day-ruler ranges over the stored elapsed-day count in
            // business-days mode (inclusive bounds, mirroring
            // bucket::for_effective_days).
            if ($bucket !== '') {
                if ($usedays) {
                    [$d1, $d2] = bucket::parse_thresholds_days();
                    $days = self::days_expr(self::MODE_GRADED, time());
                    if ($bucket === bucket::EXCELLENT) {
                        $where .= " AND $days <= :bktda";
                        $params['bktda'] = $d1;
                    } else if ($bucket === bucket::GOOD) {
                        $where .= " AND $days > :bktda AND $days <= :bktdb";
                        $params['bktda'] = $d1;
                        $params['bktdb'] = $d2;
                    } else if ($bucket === bucket::REGULAR || $bucket === bucket::CRITICAL) {
                        // Regular folds in critical: everything past the Good ceiling.
                        $where .= " AND $days > :bktda";
                        $params['bktda'] = $d2;
                    }
                } else if ($bucket === bucket::REGULAR || $bucket === bucket::CRITICAL) {
                    // Regular folds in critical (three-band graded result).
                    $where .= ' AND sub.slabucket IN (:bucketr, :bucketc)';
                    $params['bucketr'] = bucket::REGULAR;
                    $params['bucketc'] = bucket::CRITICAL;
                } else {
                    $where .= ' AND sub.slabucket = :bucket';
                    $params['bucket'] = $bucket;
                }
            }
            return [$where, $params];
        }

        // Pending / draft: the wait band takes precedence over the slabucket
        // filter (matches the block's aguardando/atencao/prioridade tiles).
        // Business-days mode ranges over the stored elapsed-day count with
        // inclusive bounds; hours mode keeps the effective-hours ranges.
        if ($band !== '') {
            if ($usedays) {
                [$dgoal, , $dcrit] = bucket::parse_thresholds_days();
                $days = self::days_expr($mode, time());
                if ($band === 'prioridade') {
                    $where .= " AND $days > :bandcrit";
                    $params['bandcrit'] = $dcrit;
                } else if ($band === 'atencao') {
                    $where .= " AND $days > :bandgoal AND $days <= :bandcrit";
                    $params['bandgoal'] = $dgoal;
                    $params['bandcrit'] = $dcrit;
                } else if ($band === 'aguardando') {
                    $where .= " AND $days <= :bandgoal";
                    $params['bandgoal'] = $dgoal;
                }
            } else {
                [$goal, $crit] = self::band_bounds();
                if ($band === 'prioridade') {
                    $where .= ' AND sub.effectivehours >= :bandcrit';
                    $params['bandcrit'] = $crit;
                } else if ($band === 'atencao') {
                    $where .= ' AND sub.effectivehours > :bandgoal AND sub.effectivehours < :bandcrit';
                    $params['bandgoal'] = $goal;
                    $params['bandcrit'] = $crit;
                } else if ($band === 'aguardando') {
                    $where .= ' AND sub.effectivehours <= :bandgoal';
                    $params['bandgoal'] = $goal;
                }
            }
        } else if ($bucket !== '') {
            $where .= ' AND sub.slabucket = :bucket';
            $params['bucket'] = $bucket;
        }

        return [$where, $params];
    }

    /**
     * Distribution counts over the base (band-unfiltered) set. Pending / draft
     * count the effective-hours bands (aguardando/atencao/prioridade); graded
     * counts the slabucket result bands.
     *
     * @param string $from Shared FROM + JOIN clause.
     * @param string $basewhere Base predicate.
     * @param array $baseparams Base params.
     * @param string $mode Browse mode.
     * @return array<string, int>
     */
    private static function counts(string $from, string $basewhere, array $baseparams, string $mode): array {
        global $DB;

        $usedays = bucket::use_day_thresholds();

        if ($mode === self::MODE_GRADED) {
            if ($usedays) {
                // Day-ruler result bands over the stored elapsed-day count
                // (inclusive bounds, mirroring bucket::for_effective_days).
                [$d1, $d2, $d3] = bucket::parse_thresholds_days();
                $days = self::days_expr(self::MODE_GRADED, time());
                $sql = "SELECT
                            SUM(CASE WHEN $days <= :d1a THEN 1 ELSE 0 END) AS excellent,
                            SUM(CASE WHEN $days > :d1b AND $days <= :d2a THEN 1 ELSE 0 END)
                                AS good,
                            SUM(CASE WHEN $days > :d2b AND $days <= :d3a THEN 1 ELSE 0 END)
                                AS regular,
                            SUM(CASE WHEN $days > :d3b THEN 1 ELSE 0 END) AS critical
                          $from WHERE $basewhere";
                $params = $baseparams
                    + ['d1a' => $d1, 'd1b' => $d1, 'd2a' => $d2, 'd2b' => $d2, 'd3a' => $d3, 'd3b' => $d3];
                $agg = $DB->get_record_sql($sql, $params);
                return [
                    'excellent' => $agg ? (int) $agg->excellent : 0,
                    'good'      => $agg ? (int) $agg->good : 0,
                    'regular'   => $agg ? (int) $agg->regular : 0,
                    'critical'  => $agg ? (int) $agg->critical : 0,
                ];
            }
            $sql = "SELECT sub.slabucket AS bucket, COUNT(1) AS n $from WHERE $basewhere GROUP BY sub.slabucket";
            $rows = $DB->get_records_sql($sql, $baseparams);
            $counts = self::zero_counts($mode);
            foreach ($rows as $r) {
                $key = (string) $r->bucket;
                if (array_key_exists($key, $counts)) {
                    $counts[$key] = (int) $r->n;
                }
            }
            return $counts;
        }

        if ($usedays) {
            [$dgoal, , $dcrit] = bucket::parse_thresholds_days();
            $days = self::days_expr($mode, time());
            $sql = "SELECT
                        SUM(CASE WHEN $days <= :goala THEN 1 ELSE 0 END) AS aguardando,
                        SUM(CASE WHEN $days > :goalb AND $days <= :critb THEN 1 ELSE 0 END)
                            AS atencao,
                        SUM(CASE WHEN $days > :crita THEN 1 ELSE 0 END) AS prioridade
                      $from WHERE $basewhere";
            $params = $baseparams + ['goala' => $dgoal, 'goalb' => $dgoal, 'crita' => $dcrit, 'critb' => $dcrit];
            $agg = $DB->get_record_sql($sql, $params);
            return [
                'aguardando' => $agg ? (int) $agg->aguardando : 0,
                'atencao'    => $agg ? (int) $agg->atencao : 0,
                'prioridade' => $agg ? (int) $agg->prioridade : 0,
            ];
        }

        [$goal, $crit] = self::band_bounds();
        $sql = "SELECT
                    SUM(CASE WHEN sub.effectivehours <= :goala THEN 1 ELSE 0 END) AS aguardando,
                    SUM(CASE WHEN sub.effectivehours > :goalb AND sub.effectivehours < :critb THEN 1 ELSE 0 END)
                        AS atencao,
                    SUM(CASE WHEN sub.effectivehours >= :crita THEN 1 ELSE 0 END) AS prioridade
                  $from WHERE $basewhere";
        $params = $baseparams + ['goala' => $goal, 'goalb' => $goal, 'crita' => $crit, 'critb' => $crit];
        $agg = $DB->get_record_sql($sql, $params);
        return [
            'aguardando' => $agg ? (int) $agg->aguardando : 0,
            'atencao'    => $agg ? (int) $agg->atencao : 0,
            'prioridade' => $agg ? (int) $agg->prioridade : 0,
        ];
    }

    /**
     * Cross-DB ORDER BY for a (mode, sort, order) tuple. Always tie-breaks on
     * sub.id so pagination is stable. The legacy longestwait / recent keys and
     * the per-column keys both resolve here.
     *
     * @param string $mode Browse mode.
     * @param string $sort Column key or legacy sort key.
     * @param string $order 'ASC' or 'DESC' (already normalised).
     * @return string ORDER BY clause without the leading keyword.
     */
    private static function order_by(string $mode, string $sort, string $order): string {
        if ($mode === self::MODE_DRAFT) {
            // Drafts have no SLA clock; most-recently-saved first.
            return 'sub.timesubmitted DESC, sub.id ASC';
        }
        switch ($sort) {
            case 'student':
                return "u.lastname $order, u.firstname $order, sub.id ASC";
            case 'activity':
                return "a.name $order, sub.id ASC";
            case 'class':
                return "COALESCE(g.name, '') $order, sub.id ASC";
            case 'submitted':
                return "sub.timesubmitted $order, sub.id ASC";
            case 'effective':
                return "sub.effectivehours $order, sub.id ASC";
            case 'perceived':
                return "sub.waitinghours $order, sub.id ASC";
            case 'status':
                // Status severity tracks effective hours (the band is derived
                // from it), so ordering by effective hours groups same-status
                // rows together.
                return "sub.effectivehours $order, sub.id ASC";
            case 'graded':
                return "sub.timegraded $order, sub.id ASC";
            case 'recent':
                return 'sub.timesubmitted DESC, sub.id ASC';
            case 'longestwait':
            default:
                return $mode === self::MODE_GRADED
                    ? 'sub.timegraded DESC, sub.id ASC'
                    : 'sub.effectivehours DESC, sub.timesubmitted ASC, sub.id ASC';
        }
    }

    /**
     * Classify one row's effective hours into the pending band shown on the
     * Status badge: aguardando (within goal) | atencao (over goal) | prioridade
     * (critical). Matches the distribution counts + band filter exactly.
     *
     * @param float $eff Effective hours.
     * @param float $goal SLA goal hours.
     * @param float $crit Critical-min hours.
     * @return string
     */
    private static function pending_band(float $eff, float $goal, float $crit): string {
        if ($eff >= $crit) {
            return 'prioridade';
        }
        if ($eff > $goal) {
            return 'atencao';
        }
        return 'aguardando';
    }

    /**
     * Day-ruler pending band — inclusive bounds, mirroring
     * bucket::for_effective_days: prioridade > crit | atencao goal..crit |
     * aguardando <= goal. A null (not-yet-backfilled) count reads aguardando.
     *
     * @param float|null $days Stored elapsed business days.
     * @param float $goal First day threshold ("within goal" upper bound).
     * @param float $crit Third day threshold (critical cutoff).
     * @return string
     */
    private static function pending_band_days(?float $days, float $goal, float $crit): string {
        $v = $days ?? 0.0;
        if ($v > $crit) {
            return 'prioridade';
        }
        if ($v > $goal) {
            return 'atencao';
        }
        return 'aguardando';
    }

    /**
     * The (goal, critical-min) effective-hours bounds that partition pending
     * work into aguardando / atencao / prioridade.
     *
     * @return array{0:float, 1:float}
     */
    private static function band_bounds(): array {
        $goal = (float) (get_config('block_feedback_tracker', 'sla_goal_hours') ?: 24);
        $thresholds = bucket::parse_thresholds_eff();
        return [$goal, (float) $thresholds[2]];
    }

    /**
     * Zeroed counts map for a mode (the distribution vocabulary differs
     * between pending/draft and graded).
     *
     * @param string $mode Browse mode.
     * @return array<string, int>
     */
    private static function zero_counts(string $mode): array {
        if ($mode === self::MODE_GRADED) {
            return ['excellent' => 0, 'good' => 0, 'regular' => 0, 'critical' => 0];
        }
        return ['aguardando' => 0, 'atencao' => 0, 'prioridade' => 0];
    }
}
