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
 * External: delete a manual pause window.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\event\cal_pause_updated;
use block_feedback_tracker\local\calendar\calendar;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Delete one {block_feedback_tracker_cpause} row. The caller must hold
 * `:managepausewindows` at the row's `contextid`, as for an update in
 * save_pause_window. Fires `cal_pause_updated` with the deleted row's scope so
 * the observer re-enqueues the rollups that pause affected.
 */
class delete_pause_window extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'cpause.id'),
        ]);
    }

    /**
     * Run.
     *
     * @param int $id
     * @return array
     */
    public static function execute(int $id): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        $id = (int) $params['id'];

        $row = $DB->get_record('block_feedback_tracker_cpause', ['id' => $id], '*', MUST_EXIST);
        $context = \context::instance_by_id((int) $row->contextid);
        self::validate_context($context);
        require_capability('block/feedback_tracker:managepausewindows', $context);

        $DB->delete_records('block_feedback_tracker_cpause', ['id' => $id]);

        $event = cal_pause_updated::create([
            'context'  => $context,
            'other'    => [
                'scopelevel' => (string) $row->scopelevel,
                'scopeid'    => (int) $row->scopeid,
                'rowid'      => $id,
                'deleted'    => true,
            ],
        ]);
        $event->trigger();

        return ['success' => true, 'id' => $id, 'calver' => calendar::current_version()];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, ''),
            'id'      => new external_value(PARAM_INT, ''),
            'calver'  => new external_value(PARAM_INT, ''),
        ]);
    }
}
