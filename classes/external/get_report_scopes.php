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
 * External: lightweight per-group report scopes for one course.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\payload\responsiveness_payload;
use block_feedback_tracker\local\sla\bucket;
use block_feedback_tracker\local\sla\group_access;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Feeds the pending report's hero row + class-filter dropdown: one trimmed
 * scope per visible group, read straight from the materialised rollup
 * ({block_feedback_tracker_group}) plus display names. Deliberately skips
 * everything the full responsiveness payload assembles per group (trend
 * series, peer stats, activity schedules, paused aggregates): the report
 * only needs these headline numbers, and reading them as rollup columns
 * keeps the call cheap enough for the page to load it after first paint.
 */
class get_report_scopes extends external_api {
    /**
     * Declares the function parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    /**
     * Run the function.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $courseid = (int) $params['courseid'];

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('block/feedback_tracker:viewresponsiveness', $context);

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $groupmode = (int) groups_get_course_groupmode($course);

        // Same visibility rules as the full payload: null = unrestricted,
        // [] = nothing visible, int[] = named-group whitelist (which never
        // includes groupid 0 "ungrouped").
        $visible = group_access::visible_group_ids($courseid, (int) $USER->id);
        if ($visible === []) {
            return [
                'success'    => true,
                'courseid'   => $courseid,
                'lastsynced' => time(),
                'groups'     => [],
            ];
        }

        $where = 'courseid = :courseid';
        $sqlparams = ['courseid' => $courseid];
        if ($visible !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($visible, SQL_PARAMS_NAMED, 'grp');
            $where .= ' AND groupid ' . $insql;
            $sqlparams = array_merge($sqlparams, $inparams);
        }
        $rollups = $DB->get_records_select(
            'block_feedback_tracker_group',
            $where,
            $sqlparams,
            'groupid ASC',
            'id, groupid, responsiveness_score, score_band, cur_median_eff_h, cur_median_raw_h,'
                . ' cur_median_eff_days, cur_median_perc_days, compliance_pct, compliance_pct_days, trend_pct_30d,'
                . ' pending, critical, overgoal, critical_days, overgoal_days'
        );

        // Display names: real names for this result's groups only, run
        // through the same custom-field title composition as the block.
        $gids = [];
        foreach ($rollups as $r) {
            if ((int) $r->groupid > 0) {
                $gids[] = (int) $r->groupid;
            }
        }
        $groupnames = [];
        if (!empty($gids)) {
            foreach ($DB->get_records_list('groups', 'id', $gids, '', 'id, name') as $nr) {
                $groupnames[(int) $nr->id] = (string) $nr->name;
            }
        }
        $titles = responsiveness_payload::resolve_group_titles($groupnames);

        // Counts follow the banding ruler: business-days mode serves the
        // day-ruler twins, falling back to the hour counts while the rollup
        // has not yet filled critical_days for the group.
        $usedays = bucket::use_day_thresholds();

        $groups = [];
        foreach ($rollups as $r) {
            $gid = (int) $r->groupid;
            $critical = (int) $r->critical;
            $overgoal = (int) $r->overgoal;
            if ($usedays && $r->critical_days !== null) {
                $critical = (int) $r->critical_days;
                $overgoal = (int) ($r->overgoal_days ?? 0);
            }
            if ($gid === 0) {
                $name = $groupmode === NOGROUPS
                    ? get_string('card_nogroup', 'block_feedback_tracker')
                    : get_string('card_ungrouped', 'block_feedback_tracker');
            } else {
                $name = $titles[$gid]['title'] ?? ($groupnames[$gid] ?? sprintf('Group #%d', $gid));
            }
            $groups[] = [
                'groupid'              => $gid,
                'name'                 => $name,
                'responsiveness_score' => $r->responsiveness_score !== null
                    ? (float) $r->responsiveness_score : null,
                'score_band'           => $r->score_band !== null ? (string) $r->score_band : null,
                'cur_median_eff_h'     => $r->cur_median_eff_h !== null ? (float) $r->cur_median_eff_h : null,
                'cur_median_raw_h'     => $r->cur_median_raw_h !== null ? (float) $r->cur_median_raw_h : null,
                'cur_median_eff_days'  => $r->cur_median_eff_days !== null
                    ? (float) $r->cur_median_eff_days : null,
                'cur_median_perc_days' => $r->cur_median_perc_days !== null
                    ? (float) $r->cur_median_perc_days : null,
                'compliance_pct'       => $r->compliance_pct !== null ? (float) $r->compliance_pct : null,
                'compliance_pct_days'  => $r->compliance_pct_days !== null ? (float) $r->compliance_pct_days : null,
                'trend_pct_30d'        => $r->trend_pct_30d !== null ? (float) $r->trend_pct_30d : null,
                'pending'              => (int) $r->pending,
                'critical'             => $critical,
                'overgoal'             => $overgoal,
            ];
        }

        return [
            'success'    => true,
            'courseid'   => $courseid,
            'lastsynced' => time(),
            'groups'     => $groups,
        ];
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
            'lastsynced' => new external_value(PARAM_INT, 'Unix ts when the scopes were read'),
            'groups'     => new external_multiple_structure(new external_single_structure([
                'groupid'              => new external_value(PARAM_INT, 'Group id; 0 = ungrouped'),
                'name'                 => new external_value(PARAM_TEXT, 'Composed display name'),
                'responsiveness_score' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'score_band'           => new external_value(PARAM_ALPHA, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'cur_median_eff_h'     => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'cur_median_raw_h'     => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'cur_median_eff_days'  => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'cur_median_perc_days' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'compliance_pct'       => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'compliance_pct_days'  => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'trend_pct_30d'        => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'pending'              => new external_value(PARAM_INT, ''),
                'critical'             => new external_value(PARAM_INT, ''),
                'overgoal'             => new external_value(PARAM_INT, ''),
            ])),
        ]);
    }
}
