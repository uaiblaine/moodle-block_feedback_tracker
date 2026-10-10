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
 * External: site-level dashboard summary.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\calendar\calendar;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Aggregates {block_feedback_tracker_group} rows into a per-course summary
 * for the teacher dashboard. One row per course with pending/critical totals,
 * group count, the mean of the groups' medians and scores, and a band derived
 * from that mean score.
 *
 * aggregate() in amd/src/lib/aggregate.js reads the per-course shape key by
 * key; a key it reads that is missing here is silently treated as no data.
 */
class get_dashboard extends external_api {
    /** Cache TTL in seconds. */
    public const CACHE_TTL = 900;

    /**
     * Cache-key version. Bump it with any change to execute()'s WHERE clause,
     * aggregate columns, returned shape or the formatting of returned values,
     * so entries cached by an earlier plugin version stop matching without a
     * purge.
     */
    public const CACHE_KEY_VERSION = 10;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'band' => new external_value(PARAM_ALPHA, 'Filter by band, "" = no filter', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Run.
     *
     * @param string $band
     * @return array
     */
    public static function execute(string $band = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['band' => $band]);
        $band = trim((string) $params['band']);

        $sysctx = \context_system::instance();
        self::validate_context($sysctx);

        // Authorisation and result scope both come from dashboard_scope; a user
        // with no visible course has no dashboard access.
        $userid = (int) $USER->id;
        $scope = \block_feedback_tracker\local\sla\dashboard_scope::visible_course_ids($userid);
        if ($scope !== null && empty($scope)) {
            throw new \required_capability_exception(
                $sysctx,
                'block/feedback_tracker:viewdashboard',
                'nopermissions',
                'error'
            );
        }
        // The key carries the user (so per-user filtering doesn't leak across
        // teachers), whether the user is in full-site view-all mode (so gaining
        // or losing that grant re-keys at once), the calendar version, the band
        // filter, the display unit and the language the course names were
        // filtered in.
        $cache = \cache::make('block_feedback_tracker', 'dashboard_payload');
        $key = 'v' . self::CACHE_KEY_VERSION
            . '_' . calendar::current_version()
            . '_' . $USER->id
            . '_' . ($scope === null ? 'all' : 'scoped')
            . '_' . $band
            . (\block_feedback_tracker\local\sla\bucket::use_day_thresholds() ? '_d' : '')
            . '_' . current_language();
        $cached = $cache->get($key);
        if (
            $cached !== false && is_array($cached)
            && isset($cached['lastsynced'])
            && (time() - (int) $cached['lastsynced']) < self::CACHE_TTL
        ) {
            return $cached;
        }

        // Filtering by (course, group) pair, not by course, keeps a teacher in
        // separate groups mode from seeing SUM() across groups they cannot see.
        [$where, $sqlparams] = \block_feedback_tracker\local\sla\dashboard_scope::sql_visibility(
            $userid,
            'g.courseid',
            'g.groupid',
            'dc'
        );
        if ($where === \block_feedback_tracker\local\sla\dashboard_scope::MATCH_NONE) {
            $result = [
                'success'    => true,
                'lastsynced' => time(),
                'courses'    => [],
            ];
            $cache->set($key, $result);
            return $result;
        }
        if ($band !== '') {
            $where .= ' AND g.score_band = :band';
            $sqlparams['band'] = $band;
        }

        $sql = "SELECT g.courseid,
                       c.fullname AS coursename,
                       COUNT(g.id) AS numgroups,
                       SUM(g.pending) AS pending,
                       SUM(g.critical) AS critical,
                       SUM(g.overgoal) AS overgoal,
                       SUM(g.critical_days) AS critical_days,
                       SUM(g.overgoal_days) AS overgoal_days,
                       AVG(g.responsiveness_score) AS avgscore,
                       AVG(g.median_eff_h) AS median_eff_h,
                       AVG(g.median_raw_h) AS perceived_median_hours,
                       AVG(g.cur_median_eff_h) AS cur_median_eff_h,
                       AVG(g.cur_median_raw_h) AS cur_median_raw_h,
                       AVG(g.cur_median_eff_days) AS cur_median_eff_days,
                       AVG(g.cur_median_perc_days) AS cur_median_perc_days,
                       AVG(g.trend_pct_30d) AS trend_pct_30d,
                       AVG(g.compliance_pct) AS compliance_pct,
                       AVG(g.compliance_pct_days) AS compliance_pct_days
                  FROM {block_feedback_tracker_group} g
                  JOIN {course} c ON c.id = g.courseid
                 WHERE $where
              GROUP BY g.courseid, c.fullname
              ORDER BY pending DESC, g.courseid ASC";

        $rows = $DB->get_records_sql($sql, $sqlparams);

        $courses = [];
        $courseids = array_map(static fn ($r) => (int) $r->courseid, $rows);
        $trendseries = self::trend_series_for_courses($userid, $courseids);
        self::preload_course_contexts($courseids);
        // Counts follow the banding ruler: business-days mode serves the
        // day-ruler twins, falling back to the hour counts while the rollup
        // has not yet filled critical_days for the course.
        $usedays = \block_feedback_tracker\local\sla\bucket::use_day_thresholds();
        foreach ($rows as $r) {
            $avg = $r->avgscore !== null ? (float) $r->avgscore : null;
            $cid = (int) $r->courseid;
            $critical = (int) $r->critical;
            $overgoal = (int) $r->overgoal;
            if ($usedays && $r->critical_days !== null) {
                $critical = (int) $r->critical_days;
                $overgoal = (int) ($r->overgoal_days ?? 0);
            }
            $band = $avg !== null
                ? \block_feedback_tracker\local\score\responsiveness_calculator::band_for($avg)
                : null;
            $courses[] = [
                'courseid'  => $cid,
                // Filtered but not escaped: PARAM_TEXT and the dashboard's text nodes escape it.
                'coursename' => format_string(
                    (string) $r->coursename,
                    true,
                    ['context' => \context_course::instance($cid), 'escape' => false]
                ),
                'numgroups' => (int) $r->numgroups,
                'pending'   => (int) $r->pending,
                'critical'  => $critical,
                'overgoal'  => $overgoal,
                'avgscore'  => $avg !== null ? round($avg, 2) : null,
                'score_band' => $band,
                'median_eff_h' => $r->median_eff_h !== null ? round((float) $r->median_eff_h, 2) : null,
                'perceived_median_hours' => $r->perceived_median_hours !== null
                    ? round((float) $r->perceived_median_hours, 2) : null,
                'cur_median_eff_h' => $r->cur_median_eff_h !== null
                    ? round((float) $r->cur_median_eff_h, 2) : null,
                'cur_median_raw_h' => $r->cur_median_raw_h !== null
                    ? round((float) $r->cur_median_raw_h, 2) : null,
                'cur_median_eff_days' => $r->cur_median_eff_days !== null
                    ? round((float) $r->cur_median_eff_days, 2) : null,
                'cur_median_perc_days' => $r->cur_median_perc_days !== null
                    ? round((float) $r->cur_median_perc_days, 2) : null,
                'trend_pct_30d' => $r->trend_pct_30d !== null ? round((float) $r->trend_pct_30d, 2) : null,
                'compliance_pct' => $r->compliance_pct !== null ? round((float) $r->compliance_pct, 2) : null,
                'compliance_pct_days' => $r->compliance_pct_days !== null
                    ? round((float) $r->compliance_pct_days, 2) : null,
                'trend_series' => $trendseries[$cid] ?? [],
            ];
        }

        $result = [
            'success'    => true,
            'lastsynced' => time(),
            'courses'    => self::sorted_by_pending_then_name($courses),
        ];
        $cache->set($key, $result);
        return $result;
    }

    /**
     * Order the courses by pending count, most first, then by the name the
     * caller reads.
     *
     * The name is sorted after format_string(), not in SQL: a multilang name
     * sorts by its markup in the database, and by the caller's language here.
     * Each sort is stable, so equal names keep the course id order of the query.
     *
     * @param array $courses Course rows as execute() builds them.
     * @return array The same rows, reordered and reindexed.
     */
    private static function sorted_by_pending_then_name(array $courses): array {
        \core_collator::asort_array_of_arrays_by_key($courses, 'coursename');
        usort($courses, static fn(array $a, array $b): int => $b['pending'] <=> $a['pending']);
        return $courses;
    }

    /**
     * Load the course contexts of a result into the context cache in one
     * query, so formatting each course name does not fetch its context alone.
     *
     * @param int[] $courseids
     * @return void
     */
    private static function preload_course_contexts(array $courseids): void {
        global $DB;
        if (empty($courseids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'ctxc');
        $params['ctxlevel'] = CONTEXT_COURSE;
        $contexts = $DB->get_records_select(
            'context',
            "contextlevel = :ctxlevel AND instanceid $insql",
            $params,
            '',
            \context_helper::get_preload_record_columns_sql('{context}')
        );
        foreach ($contexts as $ctx) {
            \context_helper::preload_from_record($ctx);
        }
    }

    /**
     * Trend-series fetcher for the courses-table sparkline. Averages the
     * effective-hours medians of the groups the user can see, per course and
     * day over the last 14 days, one entry per YYYYMMDD in the window (value
     * null on days with no data). The (course, group) filter is the one
     * execute() applies to the aggregates beside it, so a teacher in separate
     * groups mode sees no trend of a group they cannot see.
     *
     * @param int $userid The dashboard viewer.
     * @param int[] $courseids
     * @return array<int, array<int, array{day:int, value:float|null}>>
     */
    private static function trend_series_for_courses(int $userid, array $courseids): array {
        global $DB;
        if (empty($courseids)) {
            return [];
        }
        [$visibility, $params] = \block_feedback_tracker\local\sla\dashboard_scope::sql_visibility(
            $userid,
            'courseid',
            'groupid',
            'tv'
        );
        if ($visibility === \block_feedback_tracker\local\sla\dashboard_scope::MATCH_NONE) {
            return [];
        }
        // 14-day (two-week) sparkline window — matches the in-course block.
        $window = self::trend_window(14);

        [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'tc');
        $params += $inparams;
        $params['oldest'] = (int) $window[0];
        $sql = "SELECT id, courseid, day, medianh_eff
                  FROM {block_feedback_tracker_trend}
                 WHERE courseid $insql
                   AND day >= :oldest
                   AND $visibility";
        $rows = $DB->get_records_sql($sql, $params);

        // Group rows by (courseid, day) — multiple groupids per course
        // contribute to the same day; average across groups.
        $byday = [];
        foreach ($rows as $r) {
            $cid = (int) $r->courseid;
            $day = (int) $r->day;
            $val = $r->medianh_eff !== null ? (float) $r->medianh_eff : null;
            if (!isset($byday[$cid][$day])) {
                $byday[$cid][$day] = [];
            }
            if ($val !== null) {
                $byday[$cid][$day][] = $val;
            }
        }

        $out = [];
        foreach ($courseids as $cid) {
            $series = [];
            foreach ($window as $day) {
                $vals = $byday[$cid][$day] ?? null;
                $series[] = [
                    'day'   => $day,
                    'value' => is_array($vals) && !empty($vals)
                        ? round(array_sum($vals) / count($vals), 2) : null,
                ];
            }
            $out[$cid] = $series;
        }
        return $out;
    }

    /**
     * Produce the YYYYMMDD ints for the last $days days in platform tz.
     *
     * @param int $days
     * @return array<int, int>
     */
    private static function trend_window(int $days): array {
        $tz = calendar::timezone();
        $today = (new \DateTimeImmutable('@' . time()))->setTimezone($tz)->setTime(0, 0, 0);
        $window = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $window[] = (int) $today->modify("-{$i} days")->format('Ymd');
        }
        return $window;
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'    => new external_value(PARAM_BOOL, ''),
            'lastsynced' => new external_value(PARAM_INT, ''),
            'courses'    => new external_multiple_structure(new external_single_structure([
                'courseid'   => new external_value(PARAM_INT, ''),
                'coursename' => new external_value(PARAM_TEXT, ''),
                'numgroups'  => new external_value(PARAM_INT, ''),
                'pending'    => new external_value(PARAM_INT, ''),
                'critical'   => new external_value(PARAM_INT, ''),
                'overgoal'   => new external_value(PARAM_INT, ''),
                'avgscore'   => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'score_band' => new external_value(PARAM_ALPHA, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'median_eff_h' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'perceived_median_hours' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'cur_median_eff_h' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'cur_median_raw_h' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'cur_median_eff_days' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'cur_median_perc_days' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'trend_pct_30d' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'compliance_pct' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'compliance_pct_days' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'trend_series' => new external_multiple_structure(
                    new external_single_structure([
                        'day'   => new external_value(PARAM_INT, ''),
                        'value' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                    ]),
                    '',
                    VALUE_DEFAULT,
                    []
                ),
            ])),
        ]);
    }
}
