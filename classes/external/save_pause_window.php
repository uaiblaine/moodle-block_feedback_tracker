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
 * External: create or update a manual pause window.
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
 * Upsert one row in {block_feedback_tracker_cpause}. Capability gate
 * depends on scope: `:managepausewindows` at system context for site
 * pauses; at course context for course/group pauses (the cap is granted
 * to editingteacher at COURSE in db/access.php).
 *
 * Fires `cal_pause_updated` so the observer scopes the re-enqueue (site →
 * all groups, course → that course, group → one tuple).
 */
class save_pause_window extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id'         => new external_value(PARAM_INT, 'Existing row id, 0 = new', VALUE_DEFAULT, 0),
            'scopelevel' => new external_value(PARAM_ALPHA, 'site|course|group'),
            'scopeid'    => new external_value(PARAM_INT, 'courseid or groupid; 0 for site'),
            'reason'     => new external_value(PARAM_ALPHANUMEXT, 'Reason slug', VALUE_DEFAULT, 'other'),
            'timestart'  => new external_value(PARAM_INT, 'Unix ts'),
            'timeend'    => new external_value(PARAM_INT, 'Unix ts; 0 = open-ended', VALUE_DEFAULT, 0),
            'note'       => new external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Run.
     *
     * @param int $id
     * @param string $scopelevel
     * @param int $scopeid
     * @param string $reason
     * @param int $timestart
     * @param int $timeend
     * @param string $note
     * @return array
     */
    public static function execute(
        int $id,
        string $scopelevel,
        int $scopeid,
        string $reason = 'other',
        int $timestart = 0,
        int $timeend = 0,
        string $note = ''
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id, 'scopelevel' => $scopelevel, 'scopeid' => $scopeid,
            'reason' => $reason, 'timestart' => $timestart, 'timeend' => $timeend, 'note' => $note,
        ]);
        $id = max(0, (int) $params['id']);
        $scopelevel = strtolower(trim((string) $params['scopelevel']));
        $scopeid = max(0, (int) $params['scopeid']);
        $reason = trim((string) $params['reason']);
        $timestart = (int) $params['timestart'];
        $timeend = (int) $params['timeend'];
        $note = trim((string) $params['note']);

        if (!in_array($scopelevel, ['site', 'course', 'group'], true)) {
            throw new \invalid_parameter_exception('Invalid scopelevel: ' . $scopelevel);
        }
        if ($timestart <= 0) {
            throw new \invalid_parameter_exception('timestart must be a positive unix timestamp');
        }
        if ($timeend !== 0 && $timeend <= $timestart) {
            throw new \invalid_parameter_exception('timeend must be > timestart, or 0 for open-ended');
        }

        $context = self::context_for($scopelevel, $scopeid);
        self::validate_context($context);
        require_capability('block/feedback_tracker:managepausewindows', $context);

        $now = time();
        $record = (object) [
            'scopelevel'   => $scopelevel,
            'scopeid'      => $scopeid,
            'contextid'    => (int) $context->id,
            'reason'       => $reason !== '' ? $reason : 'other',
            'timestart'    => $timestart,
            'timeend'      => $timeend > 0 ? $timeend : null,
            'note'         => $note !== '' ? $note : null,
            'usermodified' => (int) $USER->id,
            'timemodified' => $now,
        ];

        if ($id > 0) {
            $existing = $DB->get_record('block_feedback_tracker_cpause', ['id' => $id], '*', MUST_EXIST);
            /* Authorise against the context the row already lives in, not just
             * the scope the caller asked for. Checking only the requested scope
             * would let anyone holding the capability in any course rewrite a
             * row belonging to another course — or to the whole site. The
             * sibling delete_pause_window does the same. */
            $existingcontext = \context::instance_by_id((int) $existing->contextid);
            self::validate_context($existingcontext);
            require_capability('block/feedback_tracker:managepausewindows', $existingcontext);

            $record->id = (int) $existing->id;
            $DB->update_record('block_feedback_tracker_cpause', $record);
        } else {
            $record->timecreated = $now;
            $id = (int) $DB->insert_record('block_feedback_tracker_cpause', $record);
        }

        // The cal_pause_updated event doesn't declare 'objecttable' (so bulk-import
        // and delete paths can also fire it), and Moodle requires both
        // 'objectid' and 'objecttable' to be set together or not at all.
        $event = cal_pause_updated::create([
            'context'  => $context,
            'other'    => ['scopelevel' => $scopelevel, 'scopeid' => $scopeid, 'rowid' => $id],
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

    /**
     * Resolve the Moodle context for a pause scope.
     *
     * @param string $scopelevel
     * @param int $scopeid
     * @return \context
     */
    private static function context_for(string $scopelevel, int $scopeid): \context {
        global $DB;
        switch ($scopelevel) {
            case 'site':
                return \context_system::instance();
            case 'course':
                return \context_course::instance($scopeid);
            case 'group':
                $g = $DB->get_record('groups', ['id' => $scopeid], 'id, courseid', MUST_EXIST);
                return \context_course::instance((int) $g->courseid);
            default:
                throw new \invalid_parameter_exception('Unknown scopelevel');
        }
    }
}
