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
 * External: paginated list of already-graded submissions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\sla\submission_browser;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Lists graded submissions (submitted work that now carries a timegraded) for
 * one course: the report page's "Graded" view. Each row carries its SLA result
 * band and the response carries the result-band distribution for the whole
 * filtered set. Graded results use three bands: critical folds into regular,
 * so `counts['critical']` is always 0. Shares the query, visibility scoping,
 * search, sort and banding with the pending list via {@see submission_browser}.
 */
class get_graded_submissions extends external_api {
    /** Default page size. */
    public const DEFAULT_PAGE_SIZE = 25;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'groupid' => new external_value(PARAM_INT, 'Group id, 0 = all', VALUE_DEFAULT, 0),
            'bucket' => new external_value(
                PARAM_ALPHA,
                'Result-band filter (slabucket): excellent | good | regular | critical',
                VALUE_DEFAULT,
                ''
            ),
            'sort' => new external_value(
                PARAM_ALPHA,
                'Sort key: graded|student|activity|class|submitted|effective|perceived',
                VALUE_DEFAULT,
                'graded'
            ),
            'page' => new external_value(PARAM_INT, '0-based page index', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Page size', VALUE_DEFAULT, self::DEFAULT_PAGE_SIZE),
            'search' => new external_value(
                PARAM_TEXT,
                'Free-text needle matched against student and activity name',
                VALUE_DEFAULT,
                ''
            ),
            'order' => new external_value(
                PARAM_ALPHA,
                'Sort direction for column sorts: asc | desc (default desc)',
                VALUE_DEFAULT,
                'desc'
            ),
        ]);
    }

    /**
     * Run.
     *
     * @param int $courseid
     * @param int $groupid
     * @param string $bucket excellent | good | regular | critical (slabucket result)
     * @param string $sort graded | student | activity | class | submitted | effective | perceived
     * @param int $page
     * @param int $perpage
     * @param string $search Free-text needle (student / activity name)
     * @param string $order asc | desc
     * @return array
     */
    public static function execute(
        int $courseid,
        int $groupid = 0,
        string $bucket = '',
        string $sort = 'graded',
        int $page = 0,
        int $perpage = self::DEFAULT_PAGE_SIZE,
        string $search = '',
        string $order = 'desc'
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'groupid' => $groupid, 'bucket' => $bucket,
            'sort' => $sort, 'page' => $page, 'perpage' => $perpage,
            'search' => $search, 'order' => $order,
        ]);
        $courseid = (int) $params['courseid'];
        $page = max(0, (int) $params['page']);
        $perpage = max(1, min((int) $params['perpage'], submission_browser::MAX_PAGE_SIZE));

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('block/feedback_tracker:viewresponsiveness', $context);

        $result = submission_browser::browse($courseid, (int) $USER->id, [
            'mode' => submission_browser::MODE_GRADED,
            'groupid' => (int) $params['groupid'],
            'search' => (string) $params['search'],
            'bucket' => (string) $params['bucket'],
            'sort' => (string) $params['sort'],
            'order' => (string) $params['order'],
            'page' => $page,
            'perpage' => $perpage,
        ]);

        return [
            'success'      => true,
            'courseid'     => $courseid,
            'total'        => $result['total'],
            'page'         => $page,
            'perpage'      => $perpage,
            'lastsynced'   => time(),
            'counts'       => [
                'excellent' => (int) ($result['counts']['excellent'] ?? 0),
                'good'      => (int) ($result['counts']['good'] ?? 0),
                'regular'   => (int) ($result['counts']['regular'] ?? 0),
                'critical'  => (int) ($result['counts']['critical'] ?? 0),
            ],
            'submissions'  => $result['rows'],
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
            'courseid'   => new external_value(PARAM_INT, ''),
            'total'      => new external_value(PARAM_INT, ''),
            'page'       => new external_value(PARAM_INT, ''),
            'perpage'    => new external_value(PARAM_INT, ''),
            'lastsynced' => new external_value(PARAM_INT, ''),
            'counts'     => new external_single_structure([
                'excellent' => new external_value(PARAM_INT, 'Excellent result count'),
                'good'      => new external_value(PARAM_INT, 'Good result count'),
                'regular'   => new external_value(PARAM_INT, 'Regular result count'),
                'critical'  => new external_value(PARAM_INT, 'Critical result count'),
            ]),
            'submissions' => new external_multiple_structure(get_pending_submissions::row_structure()),
        ]);
    }
}
