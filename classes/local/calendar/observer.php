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
 * Calendar event observers.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

use block_feedback_tracker\local\sla\dirty_queue;

/**
 * Listens for the three custom plugin events that announce calendar changes
 * (`cal_day_updated`, `cal_hours_updated`, `cal_pause_updated`) and:
 *  1) bumps the calver so cache keys naturally invalidate,
 *  2) drops per-request memos used by the academic-time engine,
 *  3) enqueues the affected (courseid, groupid) tuples for rollup recompute.
 *
 * Calendar-day and business-hour edits affect every rollup row site-wide;
 * pause-window edits are scoped (site → all, course → that course only,
 * group → that single tuple).
 */
class observer {
    /**
     * `cal_day_updated` — one calendar day, or a CSV import of many, changed.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function day_updated(\core\event\base $event): void {
        calendar::bump_version();
        academic_time::reset_memos();
        self::enqueue_all_groups(dirty_queue::REASON_CALENDAR);
    }

    /**
     * `cal_hours_updated` — weekly business hours changed.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function hours_updated(\core\event\base $event): void {
        calendar::bump_version();
        academic_time::reset_memos();
        self::enqueue_all_groups(dirty_queue::REASON_CALENDAR);
    }

    /**
     * `cal_pause_updated` — a manual pause window changed.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function pause_updated(\core\event\base $event): void {
        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        $scopelevel = (string) ($other['scopelevel'] ?? 'site');
        $scopeid = (int) ($other['scopeid'] ?? 0);

        calendar::bump_version();
        academic_time::reset_memos();

        switch ($scopelevel) {
            case 'site':
                self::enqueue_all_groups(dirty_queue::REASON_PAUSE);
                break;
            case 'course':
                self::enqueue_course_groups($scopeid);
                break;
            case 'group':
                self::enqueue_group($scopeid);
                break;
        }
    }

    /**
     * Enqueue every existing (course, group) tuple in the rollup table.
     *
     * @param string $reason
     * @return void
     */
    private static function enqueue_all_groups(string $reason): void {
        global $DB;
        $rs = $DB->get_recordset(
            'block_feedback_tracker_group',
            null,
            '',
            'id, courseid, groupid'
        );
        foreach ($rs as $row) {
            dirty_queue::enqueue((int) $row->courseid, (int) $row->groupid, $reason);
        }
        $rs->close();
    }

    /**
     * Enqueue every (course, group) tuple for a single course.
     *
     * @param int $courseid
     * @return void
     */
    private static function enqueue_course_groups(int $courseid): void {
        global $DB;
        $rs = $DB->get_recordset(
            'block_feedback_tracker_group',
            ['courseid' => $courseid],
            '',
            'id, courseid, groupid'
        );
        foreach ($rs as $row) {
            dirty_queue::enqueue((int) $row->courseid, (int) $row->groupid, dirty_queue::REASON_PAUSE);
        }
        $rs->close();
    }

    /**
     * Enqueue exactly one (course, group) tuple identified by group id.
     *
     * @param int $groupid
     * @return void
     */
    private static function enqueue_group(int $groupid): void {
        global $DB;
        $row = $DB->get_record('groups', ['id' => $groupid], 'id, courseid');
        if (!$row) {
            return;
        }
        dirty_queue::enqueue((int) $row->courseid, (int) $row->id, dirty_queue::REASON_PAUSE);
    }
}
