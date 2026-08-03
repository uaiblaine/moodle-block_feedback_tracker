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
 * External: paginated drilldown of pending submissions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\sla\submission_browser;
use block_feedback_tracker\local\sla\submission_status;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Lists pending submissions for one course, optionally narrowed by group,
 * SLA bucket / pending band, and a free-text name search, sorted by any
 * column. Returns student names, activity names, group, submission timestamp,
 * current effective/wall-clock waits, plus the pending-band distribution
 * counts for the whole filtered set. Delegates the query to
 * {@see submission_browser}.
 */
class get_pending_submissions extends external_api {
    /** Default page size. */
    public const DEFAULT_PAGE_SIZE = 25;
    /** Maximum page size to discourage all-the-things queries. */
    public const MAX_PAGE_SIZE = submission_browser::MAX_PAGE_SIZE;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'groupid' => new external_value(PARAM_INT, 'Group id, 0 = all', VALUE_DEFAULT, 0),
            'bucket' => new external_value(PARAM_ALPHA, 'Bucket filter (slabucket)', VALUE_DEFAULT, ''),
            'sort' => new external_value(
                PARAM_ALPHA,
                'Sort key: longestwait|recent|student|activity|class|submitted|effective|perceived',
                VALUE_DEFAULT,
                'longestwait'
            ),
            'page' => new external_value(PARAM_INT, '0-based page index', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Page size', VALUE_DEFAULT, self::DEFAULT_PAGE_SIZE),
            'status' => new external_value(
                PARAM_ALPHA,
                'Submission status: submitted (default) lists work awaiting feedback; '
                    . 'draft lists not-yet-submitted work',
                VALUE_DEFAULT,
                'submitted'
            ),
            'band' => new external_value(
                PARAM_ALPHA,
                'Pending-band filter (effective-hours range): aguardando | atencao | prioridade. '
                    . 'Takes precedence over bucket when set.',
                VALUE_DEFAULT,
                ''
            ),
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
     * @param string $bucket
     * @param string $sort
     * @param int $page
     * @param int $perpage
     * @param string $status submitted (default) | draft
     * @param string $band aguardando | atencao | prioridade (effective-hours range)
     * @param string $search Free-text needle (student / activity name)
     * @param string $order asc | desc (column sorts)
     * @return array
     */
    public static function execute(
        int $courseid,
        int $groupid = 0,
        string $bucket = '',
        string $sort = 'longestwait',
        int $page = 0,
        int $perpage = self::DEFAULT_PAGE_SIZE,
        string $status = submission_status::SUBMITTED,
        string $band = '',
        string $search = '',
        string $order = 'desc'
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'groupid' => $groupid, 'bucket' => $bucket,
            'sort' => $sort, 'page' => $page, 'perpage' => $perpage, 'status' => $status,
            'band' => $band, 'search' => $search, 'order' => $order,
        ]);
        $courseid = (int) $params['courseid'];
        $page = max(0, (int) $params['page']);
        $perpage = max(1, min((int) $params['perpage'], self::MAX_PAGE_SIZE));
        // Only two statuses are listable here: submitted (work awaiting
        // feedback) and draft (saved but not submitted). Anything else falls
        // back to submitted so the default list is never accidentally widened.
        $mode = $params['status'] === submission_status::DRAFT
            ? submission_browser::MODE_DRAFT
            : submission_browser::MODE_PENDING;

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('block/feedback_tracker:viewresponsiveness', $context);

        $result = submission_browser::browse($courseid, (int) $USER->id, [
            'mode' => $mode,
            'groupid' => (int) $params['groupid'],
            'search' => (string) $params['search'],
            'bucket' => (string) $params['bucket'],
            'band' => (string) $params['band'],
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
                'aguardando' => (int) ($result['counts']['aguardando'] ?? 0),
                'atencao'    => (int) ($result['counts']['atencao'] ?? 0),
                'prioridade' => (int) ($result['counts']['prioridade'] ?? 0),
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
                'aguardando' => new external_value(PARAM_INT, 'Within-goal pending count'),
                'atencao'    => new external_value(PARAM_INT, 'Over-goal pending count'),
                'prioridade' => new external_value(PARAM_INT, 'Critical pending count'),
            ]),
            'submissions' => new external_multiple_structure(self::row_structure()),
        ]);
    }

    /**
     * Shared row structure for a listed submission. Reused by
     * {@see get_graded_submissions} so the two tables share one shape.
     *
     * @return external_single_structure
     */
    public static function row_structure(): external_single_structure {
        return new external_single_structure([
            'submissionid'   => new external_value(PARAM_INT, ''),
            'cmid'           => new external_value(PARAM_INT, ''),
            'userid'         => new external_value(PARAM_INT, ''),
            'studentname'    => new external_value(PARAM_TEXT, ''),
            'activityname'   => new external_value(PARAM_TEXT, ''),
            'groupid'        => new external_value(PARAM_INT, ''),
            'groupname'      => new external_value(PARAM_TEXT, ''),
            'timesubmitted'  => new external_value(PARAM_INT, ''),
            'timegraded'     => new external_value(PARAM_INT, '0 while pending'),
            'waitinghours'   => new external_value(PARAM_FLOAT, ''),
            'effectivehours' => new external_value(PARAM_FLOAT, ''),
            'effective_days' => new external_value(PARAM_INT, 'Elapsed business days (date-based)'),
            'perceived_days' => new external_value(PARAM_INT, 'Elapsed calendar days (date-based)'),
            'slabucket'      => new external_value(PARAM_ALPHA, ''),
            'pendingband'    => new external_value(PARAM_ALPHA, 'aguardando | atencao | prioridade'),
            'submissionstatus' => new external_value(PARAM_ALPHA, ''),
            'awaitingrelease' => new external_value(
                PARAM_INT,
                '1 when a mark exists but the marking workflow has not released it to the student'
            ),
            'queuehours' => new external_value(
                PARAM_FLOAT,
                'Effective hours from hand-in to first marker allocation; null when never allocated',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'allochours' => new external_value(
                PARAM_FLOAT,
                'Effective hours from the current marker\'s allocation to grading; null when unmeasurable',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
        ]);
    }
}
