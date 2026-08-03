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
 * External: cross-course "Grade Now" prioritised list.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use block_feedback_tracker\local\calendar\day_counter;

/**
 * Returns the top-N most-urgent pending submissions across every course
 * the caller can view the dashboard for. Powers the dashboard's "Grade
 * Now" triage panel — one cheap call replaces a per-course fan-out of
 * get_pending_submissions, and the underlying SQL sorts by effective wait
 * time so the rows surfaced are genuinely the worst-offenders.
 *
 * Capability scope mirrors get_dashboard:
 * `block/feedback_tracker:viewdashboard` resolved per-course via
 * `get_user_capability_course()` so editing teachers see only their own
 * courses, category managers see all category courses (inherited), and
 * site admins see everything.
 */
class get_grader_priority_list extends external_api {
    /** Default number of submissions to return. */
    public const DEFAULT_LIMIT = 10;
    /** Maximum allowed limit — keeps the SQL bounded. */
    public const MAX_LIMIT = 50;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'limit' => new external_value(
                PARAM_INT,
                'Max submissions to return (1..50)',
                VALUE_DEFAULT,
                self::DEFAULT_LIMIT
            ),
            'bucket' => new external_value(
                PARAM_ALPHA,
                'Optional bucket filter ("" = any)',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Run.
     *
     * @param int $limit
     * @param string $bucket
     * @return array
     */
    public static function execute(int $limit = self::DEFAULT_LIMIT, string $bucket = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'limit'  => $limit,
            'bucket' => $bucket,
        ]);
        $limit = max(1, min((int) $params['limit'], self::MAX_LIMIT));
        $bucket = trim((string) $params['bucket']);

        $sysctx = \context_system::instance();
        self::validate_context($sysctx);

        // Authorisation + scope via dashboard_scope — same rules as
        // get_dashboard, since the priority list is a slice of the same
        // data. A non-admin with zero visible courses has no access.
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
        [$viswhere, $sqlparams] = \block_feedback_tracker\local\sla\dashboard_scope::sql_visibility(
            $userid,
            'sub.courseid',
            'sub.groupid',
            'pc'
        );
        if ($viswhere === \block_feedback_tracker\local\sla\dashboard_scope::MATCH_NONE) {
            return [
                'success'     => true,
                'lastsynced'  => time(),
                'limit'       => $limit,
                'returned'    => 0,
                'submissions' => [],
            ];
        }
        /* islatest / iscurrent keep superseded attempts and closed cycles off
         * the grade-now list: neither is anybody's outstanding task, and both
         * would otherwise sit at the top with an ever-growing clock. */
        $where = 'sub.timegraded IS NULL AND sub.submissionstatus = :substatus'
            . ' AND sub.islatest = 1 AND sub.iscurrent = 1'
            . ' AND ' . $viswhere;
        $sqlparams['substatus'] = \block_feedback_tracker\local\sla\submission_status::SUBMITTED;
        $usedays = \block_feedback_tracker\local\sla\bucket::use_day_thresholds();
        if ($bucket !== '') {
            if ($usedays) {
                // Day-ruler bucket ranges over the stored elapsed-day count
                // (inclusive bounds, mirroring bucket::for_effective_days).
                [$d1, $d2, $d3] = \block_feedback_tracker\local\sla\bucket::parse_thresholds_days();
                /* Rows still awaiting the effectivedays backfill would compare
                 * against NULL and drop out of every bucket — which on a
                 * priority list silently hides the most overdue work. Fall back
                 * to elapsed calendar days, always >= business days, so a row
                 * is never filed as less urgent than it is. */
                $days = sprintf(
                    'COALESCE(sub.effectivedays, (%d - sub.timesubmitted) / 86400.0)',
                    time()
                );
                if ($bucket === 'excellent') {
                    $where .= " AND $days <= :bktda";
                    $sqlparams['bktda'] = $d1;
                } else if ($bucket === 'good') {
                    $where .= " AND $days > :bktda AND $days <= :bktdb";
                    $sqlparams['bktda'] = $d1;
                    $sqlparams['bktdb'] = $d2;
                } else if ($bucket === 'regular') {
                    $where .= " AND $days > :bktda AND $days <= :bktdb";
                    $sqlparams['bktda'] = $d2;
                    $sqlparams['bktdb'] = $d3;
                } else if ($bucket === 'critical') {
                    $where .= " AND $days > :bktda";
                    $sqlparams['bktda'] = $d3;
                }
            } else {
                $where .= ' AND sub.slabucket = :bucket';
                $sqlparams['bucket'] = $bucket;
            }
        }

        // Top-N by effective wait descending; ties broken by oldest
        // submission first so the absolute worst-offender row floats up.
        $sql = "SELECT sub.id, sub.cmid, sub.userid, sub.courseid, sub.groupid,
                       sub.timesubmitted, sub.waitinghours, sub.effectivehours,
                       sub.effectivedays, sub.slabucket,
                       u.firstname, u.lastname,
                       c.fullname AS coursename,
                       cm.instance AS assignid
                  FROM {block_feedback_tracker_sub} sub
                  JOIN {user} u ON u.id = sub.userid
                  JOIN {course} c ON c.id = sub.courseid
                  JOIN {course_modules} cm ON cm.id = sub.cmid
                 WHERE $where
              ORDER BY sub.effectivehours DESC, sub.timesubmitted ASC";

        $rows = $DB->get_records_sql($sql, $sqlparams, 0, $limit);

        // Two follow-up reads to enrich with activity + group names —
        // O(1) each because the result set is bounded by $limit.
        $assignids = array_unique(array_map(static fn($r) => (int) $r->assignid, $rows));
        $assignnames = [];
        if (!empty($assignids)) {
            [$ainsql, $ainparams] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED);
            $assignnames = $DB->get_records_select_menu('assign', "id $ainsql", $ainparams, '', 'id, name');
        }
        $groupids = array_unique(array_filter(array_map(static fn($r) => (int) $r->groupid, $rows)));
        $groupnames = [];
        if (!empty($groupids)) {
            [$ginsql, $ginparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
            $groupnames = $DB->get_records_select_menu('groups', "id $ginsql", $ginparams, '', 'id, name');
        }

        $submissions = [];
        foreach ($rows as $r) {
            // Pending-only list (timegraded IS NULL): elapsed days run up to now.
            $days = day_counter::between((int) $r->timesubmitted, time());
            $submissions[] = [
                'submissionid'   => (int) $r->id,
                'cmid'           => (int) $r->cmid,
                'userid'         => (int) $r->userid,
                'studentname'    => trim($r->firstname . ' ' . $r->lastname),
                'courseid'       => (int) $r->courseid,
                'coursename'     => (string) $r->coursename,
                'groupid'        => (int) $r->groupid,
                'groupname'      => (string) ($groupnames[(int) $r->groupid] ?? ''),
                'activityname'   => (string) ($assignnames[(int) $r->assignid] ?? ''),
                'timesubmitted'  => (int) $r->timesubmitted,
                'waitinghours'   => (float) $r->waitinghours,
                'effectivehours' => (float) $r->effectivehours,
                'effective_days' => $days['business'],
                'perceived_days' => $days['calendar'],
                'slabucket'      => $usedays
                    ? \block_feedback_tracker\local\sla\bucket::for_effective_days(
                        $r->effectivedays !== null ? (float) $r->effectivedays : null
                    )
                    : (string) $r->slabucket,
            ];
        }

        return [
            'success'     => true,
            'lastsynced'  => time(),
            'limit'       => $limit,
            'returned'    => count($submissions),
            'submissions' => $submissions,
        ];
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
            'limit'      => new external_value(PARAM_INT, ''),
            'returned'   => new external_value(PARAM_INT, ''),
            'submissions' => new external_multiple_structure(new external_single_structure([
                'submissionid'   => new external_value(PARAM_INT, ''),
                'cmid'           => new external_value(PARAM_INT, ''),
                'userid'         => new external_value(PARAM_INT, ''),
                'studentname'    => new external_value(PARAM_TEXT, ''),
                'courseid'       => new external_value(PARAM_INT, ''),
                'coursename'     => new external_value(PARAM_TEXT, ''),
                'groupid'        => new external_value(PARAM_INT, ''),
                'groupname'      => new external_value(PARAM_TEXT, ''),
                'activityname'   => new external_value(PARAM_TEXT, ''),
                'timesubmitted'  => new external_value(PARAM_INT, ''),
                'waitinghours'   => new external_value(PARAM_FLOAT, ''),
                'effectivehours' => new external_value(PARAM_FLOAT, ''),
                'effective_days' => new external_value(PARAM_INT, 'Elapsed business days (date-based)'),
                'perceived_days' => new external_value(PARAM_INT, 'Elapsed calendar days (date-based)'),
                'slabucket'      => new external_value(PARAM_ALPHA, ''),
            ])),
        ]);
    }
}
