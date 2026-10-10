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
 * External: bulk-import calendar days from CSV.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\event\cal_day_updated;
use block_feedback_tracker\local\calendar\calendar;
use block_feedback_tracker\local\calendar\csv_importer;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Parse pasted CSV text and upsert calendar days.
 *
 * Rows are saved one by one with no enclosing transaction: valid rows are
 * kept and each invalid line comes back in `errors` (line number, raw text,
 * reason). When at least one row was saved, one `cal_day_updated` event fires
 * so the calendar observer bumps calver and enqueues every rollup tuple.
 * {@see \block_feedback_tracker\local\calendar\csv_importer::import()}
 */
class bulk_import_calendar extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'csv' => new external_value(PARAM_RAW, 'CSV text'),
        ]);
    }

    /**
     * Run.
     *
     * @param string $csv
     * @return array
     */
    public static function execute(string $csv): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['csv' => $csv]);
        $csv = (string) $params['csv'];

        $sysctx = \context_system::instance();
        self::validate_context($sysctx);
        require_capability('block/feedback_tracker:managecalendar', $sysctx);

        $result = csv_importer::import($csv, (int) $USER->id);

        if ($result['saved'] > 0) {
            $event = cal_day_updated::create([
                'context' => $sysctx,
                'other' => [
                    'daydate' => 0,
                    'daytype' => 'bulk_import',
                    'saved'   => $result['saved'],
                ],
            ]);
            $event->trigger();
        }

        return [
            'success' => true,
            'saved'   => $result['saved'],
            'calver'  => calendar::current_version(),
            'errors'  => $result['errors'],
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, ''),
            'saved'   => new external_value(PARAM_INT, 'Rows upserted'),
            'calver'  => new external_value(PARAM_INT, ''),
            'errors'  => new external_multiple_structure(new external_single_structure([
                'line'    => new external_value(PARAM_INT, ''),
                'raw'     => new external_value(PARAM_RAW, ''),
                'message' => new external_value(PARAM_TEXT, ''),
            ])),
        ]);
    }
}
