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

use block_feedback_tracker\local\audit\recompute_log;
use block_feedback_tracker\local\sla\dirty_queue;

/**
 * Listens for the three custom plugin events that announce calendar changes
 * (`cal_day_updated`, `cal_hours_updated`, `cal_pause_updated`) and:
 *  1) bumps the calver, so the calver-keyed caches stop being read, and drops
 *     the per-request memos used by the academic-time engine,
 *  2) enqueues the affected (courseid, groupid) tuples for rollup recompute,
 *  3) purges the two calver-keyed MUC definitions, which have no ttl, so the
 *     entries the bump made unreachable do not stay in the store,
 *  4) records one audit row with the number of tuples re-queued.
 *
 * Calendar-day and business-hour edits affect every rollup row site-wide;
 * pause-window edits are scoped (site → all, course → that course only,
 * group → that single tuple).
 */
class observer {
    /**
     * The `daytype` of the one `cal_day_updated` event a CSV import fires
     * ({@see \block_feedback_tracker\external\bulk_import_calendar::execute()});
     * a single-day save carries the saved day type instead.
     */
    private const BULK_IMPORT_DAYTYPE = 'bulk_import';

    /**
     * `cal_day_updated` — one calendar day, or a CSV import of many, changed.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function day_updated(\core\event\base $event): void {
        $started = time();
        $other = self::other($event);
        self::invalidate();
        $count = self::enqueue_all_groups(dirty_queue::REASON_CALENDAR);
        self::purge_caches();

        $reason = ($other['daytype'] ?? null) === self::BULK_IMPORT_DAYTYPE
            ? recompute_log::REASON_BULK_IMPORT
            : recompute_log::REASON_CALENDAR_SAVE;
        self::audit($reason, $count, $event, $other, $started);
    }

    /**
     * `cal_hours_updated` — weekly business hours changed.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function hours_updated(\core\event\base $event): void {
        $started = time();
        self::invalidate();
        $count = self::enqueue_all_groups(dirty_queue::REASON_CALENDAR);
        self::purge_caches();
        self::audit(recompute_log::REASON_BUSINESS_HOURS_SAVE, $count, $event, self::other($event), $started);
    }

    /**
     * `cal_pause_updated` — a manual pause window changed.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function pause_updated(\core\event\base $event): void {
        $started = time();
        $other = self::other($event);
        $scopelevel = (string) ($other['scopelevel'] ?? 'site');
        $scopeid = (int) ($other['scopeid'] ?? 0);

        self::invalidate();

        $count = 0;
        switch ($scopelevel) {
            case 'site':
                $count = self::enqueue_all_groups(dirty_queue::REASON_PAUSE);
                break;
            case 'course':
                $count = self::enqueue_course_groups($scopeid);
                break;
            case 'group':
                $count = self::enqueue_group($scopeid);
                break;
        }
        self::purge_caches();
        self::audit(recompute_log::REASON_PAUSE_SAVE, $count, $event, $other, $started);
    }

    /**
     * The event's `other` payload as an array.
     *
     * @param \core\event\base $event
     * @return array
     */
    private static function other(\core\event\base $event): array {
        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        return is_array($other) ? $other : [];
    }

    /**
     * Move to a new calendar version and drop the engine's per-request memos.
     *
     * @return void
     */
    private static function invalidate(): void {
        calendar::bump_version();
        academic_time::reset_memos();
    }

    /**
     * Purge the calver-keyed caches. The bump already made every entry
     * unreachable; without a purge the store keeps them until it evicts them.
     *
     * @return void
     */
    private static function purge_caches(): void {
        \cache_helper::purge_by_definition('block_feedback_tracker', 'calendar_effective_day');
        \cache_helper::purge_by_definition('block_feedback_tracker', 'pause_windows_by_course');
    }

    /**
     * Record the edit in the recompute audit log.
     *
     * @param string $reason One of recompute_log::REASON_*.
     * @param int $count Tuples re-queued.
     * @param \core\event\base $event The calendar event, for the acting user.
     * @param array $details The event's `other` payload.
     * @param int $started When the observer started.
     * @return void
     */
    private static function audit(string $reason, int $count, \core\event\base $event, array $details, int $started): void {
        $details['calver'] = calendar::current_version();
        $userid = (int) $event->userid;
        recompute_log::record($reason, $count, $userid > 0 ? $userid : null, $details, $started, time());
    }

    /**
     * Enqueue every existing (course, group) tuple in the rollup table.
     *
     * @param string $reason
     * @return int Tuples enqueued.
     */
    private static function enqueue_all_groups(string $reason): int {
        global $DB;
        $count = 0;
        $rs = $DB->get_recordset(
            'block_feedback_tracker_group',
            null,
            '',
            'id, courseid, groupid'
        );
        foreach ($rs as $row) {
            dirty_queue::enqueue((int) $row->courseid, (int) $row->groupid, $reason);
            $count++;
        }
        $rs->close();
        return $count;
    }

    /**
     * Enqueue every (course, group) tuple for a single course.
     *
     * @param int $courseid
     * @return int Tuples enqueued.
     */
    private static function enqueue_course_groups(int $courseid): int {
        global $DB;
        $count = 0;
        $rs = $DB->get_recordset(
            'block_feedback_tracker_group',
            ['courseid' => $courseid],
            '',
            'id, courseid, groupid'
        );
        foreach ($rs as $row) {
            dirty_queue::enqueue((int) $row->courseid, (int) $row->groupid, dirty_queue::REASON_PAUSE);
            $count++;
        }
        $rs->close();
        return $count;
    }

    /**
     * Enqueue exactly one (course, group) tuple identified by group id.
     *
     * @param int $groupid
     * @return int Tuples enqueued: 1, or 0 when the group no longer exists.
     */
    private static function enqueue_group(int $groupid): int {
        global $DB;
        $row = $DB->get_record('groups', ['id' => $groupid], 'id, courseid');
        if (!$row) {
            return 0;
        }
        dirty_queue::enqueue((int) $row->courseid, (int) $row->id, dirty_queue::REASON_PAUSE);
        return 1;
    }
}
