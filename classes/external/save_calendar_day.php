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
 * External: upsert one calendar-day row.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\event\cal_day_updated;
use block_feedback_tracker\local\calendar\calendar;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Upsert one row in {block_feedback_tracker_cday}. Sending daytype = 'remove'
 * deletes the row. Fires `cal_day_updated` so the calendar observer bumps
 * calver and enqueues every rollup (course, group) tuple; removing a date that
 * has no row changes nothing and fires nothing.
 */
class save_calendar_day extends external_api {
    /** Sentinel passed by the editor to delete a day. */
    public const ACTION_REMOVE = 'remove';

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'daydate' => new external_value(PARAM_INT, 'YYYYMMDD'),
            'daytype' => new external_value(
                PARAM_ALPHA,
                'schoolday|holiday|recess|closed|optional|remove'
            ),
            'note'    => new external_value(PARAM_TEXT, 'Free-text note', VALUE_DEFAULT, ''),
            /* Sub-day event window, only meaningful when daytype = 'optional'.
             * Both null = full-day rule. */
            'starttime' => new external_value(
                PARAM_INT,
                'Event start (minutes since midnight 0..1439); null = full-day rule',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'endtime' => new external_value(
                PARAM_INT,
                'Event end (minutes since midnight 1..1440); null = full-day rule',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
        ]);
    }

    /**
     * Run.
     *
     * @param int $daydate
     * @param string $daytype
     * @param string $note
     * @param int|null $starttime
     * @param int|null $endtime
     * @return array
     */
    public static function execute(
        int $daydate,
        string $daytype,
        string $note = '',
        ?int $starttime = null,
        ?int $endtime = null
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'daydate' => $daydate, 'daytype' => $daytype, 'note' => $note,
            'starttime' => $starttime, 'endtime' => $endtime,
        ]);
        $daydate = (int) $params['daydate'];
        $daytype = strtolower(trim((string) $params['daytype']));
        $note = trim((string) $params['note']);
        $starttime = $params['starttime'] !== null ? (int) $params['starttime'] : null;
        $endtime = $params['endtime'] !== null ? (int) $params['endtime'] : null;

        $sysctx = \context_system::instance();
        self::validate_context($sysctx);
        require_capability('block/feedback_tracker:managecalendar', $sysctx);

        if (!self::valid_daydate($daydate)) {
            throw new \invalid_parameter_exception('Invalid daydate: ' . $daydate);
        }

        $validtypes = [
            calendar::DAYTYPE_SCHOOLDAY, calendar::DAYTYPE_HOLIDAY,
            calendar::DAYTYPE_RECESS, calendar::DAYTYPE_CLOSED,
            calendar::DAYTYPE_OPTIONAL, self::ACTION_REMOVE,
        ];
        if (!in_array($daytype, $validtypes, true)) {
            throw new \invalid_parameter_exception('Invalid daytype: ' . $daytype);
        }

        // Time-window inputs are only valid when daytype = optional, and
        // must be both-set-or-both-null with start < end. Force null on
        // any other daytype so stale values from a previous "optional"
        // save don't bleed through.
        if ($daytype !== calendar::DAYTYPE_OPTIONAL) {
            $starttime = null;
            $endtime = null;
        } else if ($starttime === null xor $endtime === null) {
            throw new \invalid_parameter_exception('starttime and endtime must both be set or both null');
        } else if ($starttime !== null && $endtime !== null) {
            if ($starttime < 0 || $starttime > 1439) {
                throw new \invalid_parameter_exception('starttime out of range 0..1439');
            }
            if ($endtime < 1 || $endtime > 1440) {
                throw new \invalid_parameter_exception('endtime out of range 1..1440');
            }
            if ($starttime >= $endtime) {
                throw new \invalid_parameter_exception('starttime must be less than endtime');
            }
        }

        $existing = $DB->get_record('block_feedback_tracker_cday', ['daydate' => $daydate], 'id');
        $now = time();

        if ($daytype === self::ACTION_REMOVE) {
            if (!$existing) {
                // Nothing changed, so no event: cal_day_updated re-enqueues every rollup on the site.
                return ['success' => true, 'id' => 0, 'calver' => calendar::current_version()];
            }
            $DB->delete_records('block_feedback_tracker_cday', ['id' => $existing->id]);
            $rowid = 0;
        } else {
            $record = (object) [
                'daydate'      => $daydate,
                'daytype'      => $daytype,
                'starttime'    => $starttime,
                'endtime'      => $endtime,
                'note'         => $note !== '' ? $note : null,
                'usermodified' => (int) $USER->id,
                'timemodified' => $now,
            ];
            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('block_feedback_tracker_cday', $record);
                $rowid = (int) $existing->id;
            } else {
                $record->timecreated = $now;
                $rowid = (int) $DB->insert_record('block_feedback_tracker_cday', $record);
            }
        }

        // Note: no 'objectid' on purpose. The event class declares no
        // 'objecttable' (so bulk-import callers work without one), and
        // Moodle requires both-or-neither. The relevant identifier is in
        // 'other' as `daydate`.
        $event = cal_day_updated::create([
            'context' => $sysctx,
            'other'   => ['daydate' => $daydate, 'daytype' => $daytype, 'rowid' => $rowid],
        ]);
        $event->trigger();

        return ['success' => true, 'id' => $rowid, 'calver' => calendar::current_version()];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, ''),
            'id'      => new external_value(PARAM_INT, 'New / updated row id, or 0 if removed'),
            'calver'  => new external_value(PARAM_INT, ''),
        ]);
    }

    /**
     * Sanity check: YYYYMMDD must decode to a real calendar date.
     *
     * @param int $ymd
     * @return bool
     */
    private static function valid_daydate(int $ymd): bool {
        if ($ymd < 19700101 || $ymd > 99991231) {
            return false;
        }
        $y = (int) substr((string) $ymd, 0, 4);
        $m = (int) substr((string) $ymd, 4, 2);
        $d = (int) substr((string) $ymd, 6, 2);
        return checkdate($m, $d, $y);
    }
}
