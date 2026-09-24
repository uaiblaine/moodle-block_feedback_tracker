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
 * External: last-30-academic-days heatmap series for the report page.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\calendar\calendar;
use block_feedback_tracker\local\calendar\day_counter;
use block_feedback_tracker\local\calendar\paused_aggregator;
use block_feedback_tracker\local\sla\bucket;
use block_feedback_tracker\local\sla\group_access;
use block_feedback_tracker\local\sla\stats;
use block_feedback_tracker\local\sla\submission_status;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns the last 30 calendar days for a course (optionally one group), each
 * classified as paused (with its bucketed reason) or academic, coloured by that
 * day's responsiveness band: from the per-day median effective hours in the
 * trend table, or from the median elapsed business days of that day's grading
 * when the display unit is business days. Powers the report page's
 * "last 30 academic days" heatmap, loaded asynchronously after first paint.
 */
class get_academic_days extends external_api {
    /** Window length in days. */
    public const WINDOW_DAYS = 30;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'groupid' => new external_value(PARAM_INT, 'Group id, 0 = all visible groups', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Run.
     *
     * @param int $courseid
     * @param int $groupid 0 = aggregate over all visible groups
     * @return array
     */
    public static function execute(int $courseid, int $groupid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'groupid' => $groupid,
        ]);
        $courseid = (int) $params['courseid'];
        $groupid = max(0, (int) $params['groupid']);

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('block/feedback_tracker:viewresponsiveness', $context);

        $visibleids = group_access::visible_group_ids($courseid, (int) $USER->id);

        // Build the 30-day window (oldest → newest) in the platform timezone,
        // then the [start, end) seconds boundary covering exactly those days.
        $tz = calendar::timezone();
        $today = (new \DateTimeImmutable('@' . time()))->setTimezone($tz)->setTime(0, 0, 0);
        $window = [];
        for ($i = self::WINDOW_DAYS - 1; $i >= 0; $i--) {
            $window[] = (int) $today->modify("-{$i} days")->format('Ymd');
        }
        $start = (int) $today->modify('-' . (self::WINDOW_DAYS - 1) . ' days')->getTimestamp();
        $end = (int) $today->modify('+1 day')->getTimestamp();

        // No visible groups → a well-formed empty response (no leak).
        $noaccess = ($visibleids !== null && empty($visibleids))
            || ($groupid > 0 && $visibleids !== null && !in_array($groupid, $visibleids, true));
        if ($noaccess) {
            return [
                'success' => true,
                'courseid' => $courseid,
                'groupid' => $groupid,
                'days' => [],
                'summary' => ['total_days' => 0, 'weekend' => 0, 'holiday' => 0, 'recess' => 0],
                'events' => [],
                'lastsynced' => time(),
            ];
        }

        $perday = paused_aggregator::per_day_for_window($courseid, $start, $end);
        $daymedians = self::day_medians($courseid, $groupid, $visibleids, (int) reset($window), (int) end($window));
        $daydaymedians = self::day_median_days($courseid, $groupid, $visibleids, $start, $end);

        // The cell colour follows the banding ruler: business-days mode
        // classifies each day's median elapsed-day count against the day
        // thresholds; hours mode keeps the effective-hours classification.
        $usedays = bucket::use_day_thresholds();

        $days = [];
        foreach ($window as $ymd) {
            $info = $perday[$ymd] ?? ['paused' => false, 'reason' => ''];
            if (!empty($info['paused'])) {
                $days[] = [
                    'ymd'      => $ymd,
                    'paused'   => true,
                    'reason'   => (string) $info['reason'],
                    'band'     => '',
                    'eff_h'    => null,
                    'eff_days' => null,
                ];
                continue;
            }
            $median = $daymedians[$ymd] ?? null;
            $mediandays = $daydaymedians[$ymd] ?? null;
            if ($usedays) {
                $band = $mediandays !== null ? bucket::for_effective_days($mediandays) : 'nodata';
            } else {
                $band = $median !== null ? bucket::for_effective($median) : 'nodata';
            }
            $days[] = [
                'ymd'      => $ymd,
                'paused'   => false,
                'reason'   => '',
                'band'     => $band,
                'eff_h'    => $median !== null ? $median : null,
                'eff_days' => $mediandays,
            ];
        }

        $aggregate = paused_aggregator::for_window($courseid, $start, $end);

        return [
            'success' => true,
            'courseid' => $courseid,
            'groupid' => $groupid,
            'days' => $days,
            'summary' => [
                'total_days' => (int) $aggregate['total_days'],
                'weekend'    => (int) $aggregate['weekend'],
                'holiday'    => (int) $aggregate['holiday'],
                'recess'     => (int) $aggregate['recess'],
            ],
            'events' => array_map(static fn ($e) => [
                'date'      => (int) $e['date'],
                'starttime' => (int) $e['starttime'],
                'endtime'   => (int) $e['endtime'],
                'label'     => (string) $e['label'],
            ], $aggregate['events']),
            'lastsynced' => time(),
        ];
    }

    /**
     * Per-day mean effective-hours median over the window. For a single group
     * this is that group's per-day median; for the whole course (groupid 0) it
     * averages the visible groups' per-day medians (a visual approximation —
     * the heatmap is a glanceable strip, not a scored figure). Returns a map
     * keyed by YYYYMMDD with only the days that carry data.
     *
     * @param int $courseid Course id.
     * @param int $groupid 0 = aggregate over visible groups.
     * @param int[]|null $visibleids Visible group ids, or null when unrestricted.
     * @param int $first Oldest YYYYMMDD in the window.
     * @param int $last Newest YYYYMMDD in the window.
     * @return array<int, float> Keyed by YYYYMMDD.
     */
    private static function day_medians(
        int $courseid,
        int $groupid,
        ?array $visibleids,
        int $first,
        int $last
    ): array {
        global $DB;

        $where = 'courseid = :courseid AND day >= :first AND day <= :last';
        $params = ['courseid' => $courseid, 'first' => $first, 'last' => $last];
        if ($groupid > 0) {
            $where .= ' AND groupid = :groupid';
            $params['groupid'] = $groupid;
        } else if ($visibleids !== null) {
            [$gsql, $gparams] = $DB->get_in_or_equal($visibleids, SQL_PARAMS_NAMED, 'gv');
            $where .= " AND groupid $gsql";
            $params += $gparams;
        }

        // AVG ignores NULL medianh_eff under both PostgreSQL and MariaDB, so a
        // day with only no-data group rows yields a null average and is left
        // out by the COUNT guard below.
        $sql = "SELECT day, AVG(medianh_eff) AS m, COUNT(medianh_eff) AS c
                  FROM {block_feedback_tracker_trend}
                 WHERE $where
              GROUP BY day";
        $rows = $DB->get_records_sql($sql, $params);

        $out = [];
        foreach ($rows as $r) {
            if ((int) $r->c > 0 && $r->m !== null) {
                $out[(int) $r->day] = (float) $r->m;
            }
        }
        return $out;
    }

    /**
     * Per-day median elapsed business days (date-based) of the submissions
     * graded each day in the window: the heatmap's band and tooltip figure when
     * the display unit is business days. Read-time companion to day_medians():
     * only rows graded inside the window are fetched, and day counting goes
     * through day_rule_resolver's cached per-day classification.
     *
     * @param int $courseid Course id.
     * @param int $groupid 0 = aggregate over visible groups.
     * @param int[]|null $visibleids Visible group ids, or null when unrestricted.
     * @param int $start Unix seconds; inclusive window start.
     * @param int $end Unix seconds; exclusive window end.
     * @return array<int, float> Keyed by YYYYMMDD.
     */
    private static function day_median_days(
        int $courseid,
        int $groupid,
        ?array $visibleids,
        int $start,
        int $end
    ): array {
        global $DB;

        $where = 'courseid = :courseid AND timegraded IS NOT NULL'
            . ' AND timegraded >= :start AND timegraded < :end'
            . ' AND submissionstatus = :substatus';
        $params = [
            'courseid' => $courseid,
            'start' => $start,
            'end' => $end,
            'substatus' => submission_status::SUBMITTED,
        ];
        if ($groupid > 0) {
            $where .= ' AND groupid = :groupid';
            $params['groupid'] = $groupid;
        } else if ($visibleids !== null) {
            [$gsql, $gparams] = $DB->get_in_or_equal($visibleids, SQL_PARAMS_NAMED, 'gd');
            $where .= " AND groupid $gsql";
            $params += $gparams;
        }
        $rows = $DB->get_records_select(
            'block_feedback_tracker_sub',
            $where,
            $params,
            '',
            'id, timesubmitted, timegraded'
        );

        $tz = calendar::timezone();
        $perday = [];
        foreach ($rows as $r) {
            $gradedymd = (int) (new \DateTimeImmutable('@' . (int) $r->timegraded))
                ->setTimezone($tz)
                ->format('Ymd');
            $perday[$gradedymd][] = day_counter::business_days(
                (int) $r->timesubmitted,
                (int) $r->timegraded
            );
        }

        $out = [];
        foreach ($perday as $ymd => $vals) {
            $out[$ymd] = (float) stats::median($vals);
        }
        return $out;
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'  => new external_value(PARAM_BOOL, ''),
            'courseid' => new external_value(PARAM_INT, ''),
            'groupid'  => new external_value(PARAM_INT, ''),
            'days'     => new external_multiple_structure(new external_single_structure([
                'ymd'    => new external_value(PARAM_INT, 'YYYYMMDD'),
                'paused' => new external_value(PARAM_BOOL, 'True when the day is paused (excluded from the SLA)'),
                'reason' => new external_value(PARAM_ALPHA, 'weekend | holiday | recess | "" when active'),
                'band'   => new external_value(PARAM_ALPHA, 'Responsiveness band for active days; "" when paused'),
                'eff_h'  => new external_value(
                    PARAM_FLOAT,
                    'Median effective hours for the day; null when paused or no data',
                    VALUE_DEFAULT,
                    null,
                    NULL_ALLOWED
                ),
                'eff_days' => new external_value(
                    PARAM_FLOAT,
                    'Median elapsed business days of work graded that day; null when paused or no data',
                    VALUE_DEFAULT,
                    null,
                    NULL_ALLOWED
                ),
            ])),
            'summary' => new external_single_structure([
                'total_days' => new external_value(PARAM_INT, ''),
                'weekend'    => new external_value(PARAM_INT, ''),
                'holiday'    => new external_value(PARAM_INT, ''),
                'recess'     => new external_value(PARAM_INT, ''),
            ]),
            'events' => new external_multiple_structure(new external_single_structure([
                'date'      => new external_value(PARAM_INT, 'YYYYMMDD'),
                'starttime' => new external_value(PARAM_INT, 'Minutes since midnight'),
                'endtime'   => new external_value(PARAM_INT, 'Minutes since midnight'),
                'label'     => new external_value(PARAM_TEXT, ''),
            ])),
            'lastsynced' => new external_value(PARAM_INT, ''),
        ]);
    }
}
