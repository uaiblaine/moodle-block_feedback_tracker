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
 * Adhoc task: re-queue the rollup tuples of the site or of one course.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\dirty_queue;
use block_feedback_tracker\local\sla\process_memos;

/**
 * Queued by a calendar edit ({@see \block_feedback_tracker\local\calendar\observer})
 * and by a settings save ({@see block_feedback_tracker_invalidate_rollups()}),
 * which used to walk every rollup tuple inside the admin's request: two
 * statements per tuple, 200,000 for a site with 100,000 tuples.
 *
 * The task is queued with core's duplicate check, so consecutive edits of one
 * scope collapse into one walk. A queued task that has started still counts as
 * a duplicate, so an edit made while the walk runs queues nothing; the walk
 * therefore repeats while the calendar version moves under it, and every such
 * edit bumps it. An edit landing after the last check is covered by
 * {@see \block_feedback_tracker\local\sla\pending_recomputer}, which re-dates
 * every pending row whose calendar version is behind and re-queues its tuple.
 */
class requeue_rollups extends \core\task\adhoc_task {
    /**
     * Queue the walk of one scope, once.
     *
     * @param int $courseid The course whose tuples to re-queue; 0 for every course.
     * @param string $reason One of dirty_queue::REASON_*, stamped on each queue row.
     * @return void
     */
    public static function queue(int $courseid, string $reason): void {
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid, 'reason' => $reason]);
        try {
            \core\task\manager::queue_adhoc_task($task, true);
        } catch (\Throwable $e) {
            // An observer or a settings save must never fail on this.
            debugging('block_feedback_tracker: could not queue the rollup re-queue: ' . $e->getMessage());
        }
    }

    /**
     * How many tuples a walk of this scope re-queues, for the audit log.
     *
     * @param int $courseid The course; 0 for every course.
     * @return int
     */
    public static function count_tuples(int $courseid): int {
        global $DB;
        return $DB->count_records('block_feedback_tracker_group', $courseid > 0 ? ['courseid' => $courseid] : null);
    }

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_requeue_rollups', 'block_feedback_tracker');
    }

    /**
     * Re-queue every tuple of the scope until the calendar version holds still.
     *
     * @return void
     */
    public function execute(): void {
        process_memos::reset();
        $data = $this->get_custom_data();
        $courseid = (int) ($data->courseid ?? 0);
        $reason = (string) ($data->reason ?? dirty_queue::REASON_BULK);
        $walks = 0;
        do {
            $calver = self::stored_calver();
            $count = $this->walk($courseid, $reason);
            $walks++;
        } while (self::stored_calver() !== $calver);
        mtrace("requeue_rollups: course {$courseid}, {$count} tuple(s) re-queued in {$walks} walk(s).");
    }

    /**
     * Re-queue every tuple of the scope once.
     *
     * Protected so a test can move the calendar version during a walk.
     *
     * @param int $courseid The course; 0 for every course.
     * @param string $reason One of dirty_queue::REASON_*.
     * @return int Tuples re-queued.
     */
    protected function walk(int $courseid, string $reason): int {
        global $DB;
        $count = 0;
        $rs = $DB->get_recordset(
            'block_feedback_tracker_group',
            $courseid > 0 ? ['courseid' => $courseid] : null,
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
     * The calendar version as stored, read past the config cache, which a
     * long cron process may hold from before another process bumped it.
     *
     * @return int
     */
    private static function stored_calver(): int {
        global $DB;
        $value = $DB->get_field('config_plugins', 'value', ['plugin' => 'block_feedback_tracker', 'name' => 'calver']);
        return $value === false ? 1 : (int) $value;
    }
}
