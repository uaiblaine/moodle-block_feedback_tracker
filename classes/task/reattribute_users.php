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
 * Adhoc task: re-attribute a batch of users' ledger rows to their groups.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\dirty_queue;
use block_feedback_tracker\local\sla\process_memos;
use block_feedback_tracker\local\sla\submission_ledger;

/**
 * Moves ledger rows to the group each user reports under now, after a group
 * of their course was deleted
 * ({@see \block_feedback_tracker\local\sla\observer::group_deleted()}).
 *
 * The task walks the users whose rows reported under the deleted group in
 * pages of {@see self::PAGE}, so a group of any size is moved in full. It is
 * queued with core's duplicate check, keyed on the course and the group.
 * Re-gated on the course at execute time, like the other ledger writers, in
 * case the block went away in between.
 *
 * A task queued before the walk existed carries a list of user ids instead of
 * a group, and moves those users.
 */
class reattribute_users extends \core\task\adhoc_task {
    /** Users read per query while walking. Protected so a test can walk in small pages. */
    protected const PAGE = 500;

    /**
     * Queue the re-attribution of one deleted group's rows, once.
     *
     * @param int $courseid
     * @param int $groupid The deleted group.
     * @return void
     */
    public static function queue_group(int $courseid, int $groupid): void {
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid, 'groupid' => $groupid]);
        try {
            \core\task\manager::queue_adhoc_task($task, true);
        } catch (\Throwable $e) {
            // An observer must never abort the action that triggered it.
            debugging(sprintf(
                'block_feedback_tracker: could not queue the re-attribution of group %d in course %d: %s',
                $groupid,
                $courseid,
                $e->getMessage()
            ));
        }
    }

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_reattribute_users', 'block_feedback_tracker');
    }

    /**
     * Re-attribute the deleted group's users, or the listed ones.
     *
     * @return void
     */
    public function execute(): void {
        process_memos::reset();
        $data = (array) $this->get_custom_data();
        $courseid = (int) ($data['courseid'] ?? 0);
        if (!course_access::is_processable($courseid)) {
            return;
        }
        if (isset($data['groupid'])) {
            $this->walk_group($courseid, (int) $data['groupid']);
            return;
        }
        foreach ((array) ($data['userids'] ?? []) as $userid) {
            $userid = (int) $userid;
            if ($userid > 0) {
                submission_ledger::reattribute_user($courseid, $userid);
            }
        }
    }

    /**
     * Move every user whose rows still report under the group.
     *
     * Paged on the user id rather than an offset: each user moved leaves the
     * group, so an offset would skip a page's worth of users. Each group the
     * rows left or joined is enqueued once per page, not once per user, so a
     * walk stopped half-way leaves no moved row's tuple stale.
     *
     * @param int $courseid
     * @param int $groupid
     * @return void
     */
    protected function walk_group(int $courseid, int $groupid): void {
        global $DB;
        $moved = 0;
        $after = 0;
        do {
            $userids = array_map('intval', array_keys($DB->get_records_sql(
                'SELECT DISTINCT userid
                   FROM {block_feedback_tracker_sub}
                  WHERE courseid = :courseid AND groupid = :groupid AND userid > :after
               ORDER BY userid ASC',
                ['courseid' => $courseid, 'groupid' => $groupid, 'after' => $after],
                0,
                static::PAGE
            )));
            $groupids = [];
            foreach ($userids as $userid) {
                $after = $userid;
                foreach (submission_ledger::reattribute_user($courseid, $userid, false) as $touched) {
                    $groupids[$touched] = $touched;
                }
                $moved++;
            }
            foreach ($groupids as $touched) {
                dirty_queue::enqueue($courseid, $touched, dirty_queue::REASON_SUBMISSION);
            }
        } while (count($userids) === static::PAGE);
        mtrace("reattribute_users: group {$groupid} of course {$courseid}, {$moved} user(s) moved.");
    }
}
