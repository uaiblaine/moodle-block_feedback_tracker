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
 * External: per-submission pause timeline.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\sla\group_access;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns the ordered list of pause windows that contributed to one
 * submission's effectivehours. Powers the design mock's "graded during
 * weekend" / "paused: holiday" detail view.
 *
 * Since v2.0.0 the pause windows are recomputed on demand by
 * `academic_time::elapsed_with_audit()` rather than read from a
 * persisted table — the old per-submission persistence was producing
 * gigabytes of data for a rarely-clicked drill-down view. The trade-off
 * is that retroactive calendar edits now affect what graded submissions
 * show here (the prior approach silently kept stale data for graded
 * rows because nothing ever updated them post-grading).
 */
class get_pause_timeline extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'submissionid' => new external_value(PARAM_INT, 'block_feedback_tracker_sub.id'),
        ]);
    }

    /**
     * Run.
     *
     * @param int $submissionid
     * @return array
     */
    public static function execute(int $submissionid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'submissionid' => $submissionid,
        ]);
        $submissionid = (int) $params['submissionid'];

        $sub = $DB->get_record('block_feedback_tracker_sub', ['id' => $submissionid], '*', MUST_EXIST);
        $context = \context_course::instance((int) $sub->courseid);
        self::validate_context($context);
        require_capability('block/feedback_tracker:viewresponsiveness', $context);

        /* The course capability is not enough on its own: submission ids are
         * sequential, so without the group gate a separate-groups teacher can
         * enumerate them and read timings for groups they cannot see. Every
         * other per-submission read surface goes through group_access. */
        if (!group_access::can_see_group((int) $sub->courseid, (int) $USER->id, (int) $sub->groupid)) {
            throw new \required_capability_exception(
                $context,
                'block/feedback_tracker:viewresponsiveness',
                'nopermissions',
                ''
            );
        }

        // Recompute the pause timeline from the calendar engine — same
        // method the write paths call internally. Endpoint is rarely hit
        // (per-submission drill-down only), so per-call cost is fine.
        $tsfrom = (int) $sub->timesubmitted;
        $tsto = $sub->timegraded !== null ? (int) $sub->timegraded : time();
        $audit = academic_time::elapsed_with_audit(
            (int) $sub->courseid,
            (int) $sub->groupid,
            $tsfrom,
            $tsto
        );

        $out = [];
        foreach ($audit['pauses'] as $idx => $p) {
            $out[] = [
                // Synthetic sequential id — no DB row exists. Stable
                // within a single response; not stable across calls.
                'id'         => $idx + 1,
                'reason'     => (string) $p['reason'],
                'timestart'  => (int) $p['timestart'],
                'timeend'    => (int) $p['timeend'],
                'scopelevel' => $p['scopelevel'] !== null ? (string) $p['scopelevel'] : null,
                'scopeid'    => $p['scopeid'] !== null ? (int) $p['scopeid'] : null,
                'note'       => $p['note'] !== null ? (string) $p['note'] : null,
            ];
        }

        return [
            'success'        => true,
            'submissionid'   => $submissionid,
            'timesubmitted'  => (int) $sub->timesubmitted,
            'timegraded'     => $sub->timegraded !== null ? (int) $sub->timegraded : null,
            'waitinghours'   => $sub->waitinghours !== null ? (float) $sub->waitinghours : null,
            'effectivehours' => $sub->effectivehours !== null ? (float) $sub->effectivehours : null,
            'slabucket'      => (string) $sub->slabucket,
            'pauses'         => $out,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'        => new external_value(PARAM_BOOL, ''),
            'submissionid'   => new external_value(PARAM_INT, ''),
            'timesubmitted'  => new external_value(PARAM_INT, ''),
            'timegraded'     => new external_value(PARAM_INT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'waitinghours'   => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'effectivehours' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            'slabucket'      => new external_value(PARAM_ALPHA, ''),
            'pauses'         => new external_multiple_structure(new external_single_structure([
                'id'         => new external_value(PARAM_INT, ''),
                'reason'     => new external_value(PARAM_TEXT, ''),
                'timestart'  => new external_value(PARAM_INT, ''),
                'timeend'    => new external_value(PARAM_INT, ''),
                'scopelevel' => new external_value(PARAM_TEXT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'scopeid'    => new external_value(PARAM_INT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'note'       => new external_value(PARAM_TEXT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
            ])),
        ]);
    }
}
