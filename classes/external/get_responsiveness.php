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
 * External: get course-scoped responsiveness payload.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\payload\responsiveness_payload;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns one card per group the caller can see in this course: score,
 * band, pending/critical/overgoal counts, raw + effective medians/p90/max,
 * compliance %, trend %, and the next/last pause indicators.
 *
 * The actual payload assembly lives in {@see responsiveness_payload::
 * for_course()} so it can be reused from the block's get_content()
 * without going through external_api::validate_context() (which calls
 * $PAGE->set_context() — illegal after page output has begun).
 */
class get_responsiveness extends external_api {
    /**
     * Declares the function parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'force'    => new external_value(PARAM_BOOL, 'Bypass session cache', VALUE_DEFAULT, false),
            'limit'    => new external_value(PARAM_INT, 'Page size; 0 returns every visible group', VALUE_DEFAULT, 0),
            'offset'   => new external_value(PARAM_INT, 'Zero-based offset into the group list', VALUE_DEFAULT, 0),
            'sort'     => new external_value(
                PARAM_ALPHA,
                'Order key: default (groupid), priority, or wait',
                VALUE_DEFAULT,
                'default'
            ),
        ]);
    }

    /**
     * Run the function.
     *
     * @param int $courseid
     * @param bool $force
     * @param int $limit
     * @param int $offset
     * @param string $sort
     * @return array
     */
    public static function execute(
        int $courseid,
        bool $force = false,
        int $limit = 0,
        int $offset = 0,
        string $sort = 'default'
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'force' => $force, 'limit' => $limit, 'offset' => $offset, 'sort' => $sort,
        ]);
        $courseid = (int) $params['courseid'];
        $force = (bool) $params['force'];
        $limit = (int) $params['limit'];
        $offset = (int) $params['offset'];
        $sort = (string) $params['sort'];

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('block/feedback_tracker:viewresponsiveness', $context);

        return responsiveness_payload::for_course($courseid, (int) $USER->id, $force, $limit, $offset, $sort);
    }

    /**
     * Declares the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'    => new external_value(PARAM_BOOL, 'Whether the call succeeded'),
            'courseid'   => new external_value(PARAM_INT, 'Course id'),
            'lastsynced' => new external_value(PARAM_INT, 'Unix ts when payload was assembled'),
            'total'      => new external_value(PARAM_INT, 'Total visible groups across all pages'),
            'offset'     => new external_value(PARAM_INT, 'Zero-based offset of this page'),
            'limit'      => new external_value(PARAM_INT, 'Page size requested; 0 means all groups'),
            'hasmore'    => new external_value(PARAM_BOOL, 'Whether more groups remain after this page'),
            'overall_score' => new external_value(
                PARAM_FLOAT,
                'Pending-weighted mean score across the whole visible course',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'groups'     => new external_multiple_structure(self::group_structure()),
        ]);
    }

    /**
     * One group card structure.
     *
     * @return external_single_structure
     */
    private static function group_structure(): external_single_structure {
        return new external_single_structure([
            'groupid'              => new external_value(PARAM_INT, ''),
            'groupname'            => new external_value(PARAM_TEXT, ''),
            'groupsubtitle'        => new external_value(PARAM_TEXT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'coursename'           => new external_value(PARAM_TEXT, ''),
            'pending'              => new external_value(PARAM_INT, ''),
            'critical'             => new external_value(PARAM_INT, ''),
            'overgoal'             => new external_value(PARAM_INT, ''),
            'numgraded30d'         => new external_value(PARAM_INT, ''),
            'compliance_pct'       => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'compliance_pct_days'  => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'median_eff_h'         => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'p90_eff_h'            => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'max_eff_h'            => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'median_raw_h'         => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'p90_raw_h'            => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'max_raw_h'            => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'perceived_median_hours' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'cur_median_eff_h'     => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'cur_median_raw_h'     => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'cur_median_eff_days'  => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'cur_median_perc_days' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'unallocated' => new external_value(
                PARAM_INT,
                'Pending submissions with no marker allocated yet',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'median_queue_h' => new external_value(
                PARAM_FLOAT,
                'Median effective hours from hand-in to first allocation',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'median_alloc_h' => new external_value(
                PARAM_FLOAT,
                'Median effective hours from allocation to grading (marker turnaround)',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'alloc_coverage_pct' => new external_value(
                PARAM_FLOAT,
                'Share of the graded window carrying a usable marker-turnaround measurement',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'responsiveness_score' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'score_band'           => new external_value(PARAM_ALPHA, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'comp_compliance'      => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'comp_median'          => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'comp_critical'        => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'comp_pending'         => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'comp_trend'           => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'trend_pct_30d'        => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'trend_series'         => new external_multiple_structure(
                new external_single_structure([
                    'day'   => new external_value(PARAM_INT, 'YYYYMMDD'),
                    'value' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                ]),
                '',
                VALUE_DEFAULT,
                []
            ),
            'nextpause_ts'         => new external_value(PARAM_INT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'nextpause_reason'     => new external_value(PARAM_TEXT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'nextpause_note'       => new external_value(PARAM_TEXT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'lastpause_endts'      => new external_value(PARAM_INT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'lastpause_reason'     => new external_value(PARAM_TEXT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            /* Scheduled-pause notice ("Pausa prevista"): up to 3 upcoming pauses. */
            'upcoming_pauses' => new external_multiple_structure(
                new external_single_structure([
                    'start'     => new external_value(PARAM_INT, 'Pause start unix ts (sort key)'),
                    'type'      => new external_value(PARAM_ALPHA, 'Pause type / reason slug'),
                    'label'     => new external_value(PARAM_RAW, 'Pre-sanitised pause label'),
                    'when'      => new external_value(PARAM_TEXT, 'Localised date / time window'),
                    'typelabel' => new external_value(PARAM_TEXT, 'Localised pause-type label'),
                ]),
                '',
                VALUE_DEFAULT,
                []
            ),
            'paused_days_30d'      => new external_value(PARAM_INT, '', VALUE_DEFAULT, 0),
            'paused_breakdown_30d' => new external_single_structure([
                'weekend' => new external_value(PARAM_INT, ''),
                'holiday' => new external_value(PARAM_INT, ''),
                'recess'  => new external_value(PARAM_INT, ''),
            ]),
            /* v1.0.9 — sub-day optional events sidecar. */
            'paused_events_30d' => new external_multiple_structure(
                new external_single_structure([
                    'date'      => new external_value(PARAM_INT, 'YYYYMMDD'),
                    'starttime' => new external_value(PARAM_INT, 'Minutes since midnight'),
                    'endtime'   => new external_value(PARAM_INT, 'Minutes since midnight'),
                    'label'     => new external_value(PARAM_RAW, 'Pre-sanitised event label'),
                ]),
                '',
                VALUE_DEFAULT,
                []
            ),
            'peer_department_score' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'peer_department_hours' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'peer_top10_score'      => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'peer_top10_hours'      => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'activities'            => new external_multiple_structure(
                new external_single_structure([
                    'cmid'   => new external_value(PARAM_INT, 'Course-module id of the assign'),
                    'name'   => new external_value(PARAM_TEXT, 'Activity name'),
                    'opens'  => new external_value(PARAM_INT, 'Effective open ts', VALUE_DEFAULT, null, NULL_ALLOWED),
                    'closes' => new external_value(PARAM_INT, 'Effective close ts', VALUE_DEFAULT, null, NULL_ALLOWED),
                    'action' => new external_value(PARAM_ALPHA, 'done | create | override | norule'),
                    'editable' => new external_value(PARAM_BOOL, 'Whether the chip links to the override editor'),
                ]),
                '',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
