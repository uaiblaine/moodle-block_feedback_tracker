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
 * Tests for the group-change adhoc task.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\process_memos;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The task moves a deleted group's rows to the group each user reports under
 * now, walking the group in pages however many users it held, and still runs
 * the per-user lists queued before the walk existed. It re-checks the course
 * when it runs and skips ids that are not users. Pages of two users here stand
 * for pages of five hundred.
 */
#[CoversClass(reattribute_users::class)]
final class reattribute_users_test extends \advanced_testcase {
    /**
     * Flush the memos the task consults.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        process_memos::reset();
    }

    /**
     * A course that lost the block between queueing and running is left
     * alone; a tracked course in the same state is the control.
     *
     * @return void
     */
    public function test_a_course_that_is_no_longer_processable_is_left_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $tracked = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($tracked->id)->id,
        ]);
        $untracked = $this->getDataGenerator()->create_course();
        $trackedrow = $this->row_in_a_group_the_user_left($tracked, 101);
        $untrackedrow = $this->row_in_a_group_the_user_left($untracked, 102);

        $this->run_task((int) $untracked->id, [101, 102]);
        $this->run_task((int) $tracked->id, [101, 102]);

        $this->assertSame(0, (int) $DB->get_field('block_feedback_tracker_sub', 'groupid', ['id' => $trackedrow]), 'Control.');
        $this->assertNotSame(
            0,
            (int) $DB->get_field('block_feedback_tracker_sub', 'groupid', ['id' => $untrackedrow]),
            'The row of the course without the block keeps its group.'
        );
    }

    /**
     * An id of 0 or less is not a user and is skipped: a row stored under
     * userid 0 keeps its group, while a real user's row is moved.
     *
     * @return void
     */
    public function test_ids_that_are_not_users_are_skipped(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        $user = (int) $this->getDataGenerator()->create_and_enrol($course, 'student')->id;
        $userrow = $this->row_in_a_group_the_user_left($course, $user);
        $zerorow = $this->row_in_a_group_the_user_left($course, 0);

        $this->run_task((int) $course->id, [0, -1, $user]);

        $this->assertSame(0, (int) $DB->get_field('block_feedback_tracker_sub', 'groupid', ['id' => $userrow]), 'Control.');
        $this->assertNotSame(0, (int) $DB->get_field('block_feedback_tracker_sub', 'groupid', ['id' => $zerorow]));
    }

    /**
     * The group walk moves every user whose rows reported under the group,
     * over several pages, leaves another group's rows alone, and re-queues
     * the two tuples the rows left and joined.
     *
     * @return void
     */
    public function test_the_group_walk_moves_every_user_over_several_pages(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $deleted = (int) $this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
        $kept = (int) $this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
        for ($u = 1; $u <= 5; $u++) {
            $generator->create_ledger_row(['courseid' => (int) $course->id, 'groupid' => $deleted, 'userid' => 900000 + $u]);
        }
        // A second row of one user, so the walk reads users, not rows.
        $generator->create_ledger_row([
            'courseid' => (int) $course->id,
            'groupid' => $deleted,
            'userid' => 900001,
            'attemptnumber' => 1,
        ]);
        $member = (int) $this->getDataGenerator()->create_and_enrol($course, 'student')->id;
        $this->getDataGenerator()->create_group_member(['groupid' => $kept, 'userid' => $member]);
        $keptrow = $generator->create_ledger_row(['courseid' => (int) $course->id, 'groupid' => $kept, 'userid' => $member]);

        course_access::reset_memo();
        $task = new class extends reattribute_users {
            /** Users per page. */
            protected const PAGE = 2;
        };
        $task->set_custom_data(['courseid' => (int) $course->id, 'groupid' => $deleted]);
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub', ['groupid' => $deleted]));
        $this->assertSame(6, $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id, 'groupid' => 0]));
        $this->assertSame($kept, (int) $DB->get_field('block_feedback_tracker_sub', 'groupid', ['id' => $keptrow]));
        $queued = $DB->get_fieldset_select('block_feedback_tracker_queue', 'groupid', 'courseid = ?', [$course->id]);
        sort($queued);
        $this->assertSame([0, $deleted], array_map('intval', $queued), 'Both tuples are re-queued, once each.');
    }

    /**
     * Queueing one deleted group twice keeps one task; another group gets its
     * own.
     *
     * @return void
     */
    public function test_queue_group_collapses_one_group(): void {
        global $DB;
        $this->resetAfterTest();
        $classname = ['classname' => '\\' . reattribute_users::class];

        reattribute_users::queue_group(8001, 11);
        reattribute_users::queue_group(8001, 11);
        $this->assertSame(1, $DB->count_records('task_adhoc', $classname));

        reattribute_users::queue_group(8001, 12);
        $this->assertSame(2, $DB->count_records('task_adhoc', $classname));
    }

    /**
     * Store a ledger row attributed to a group the user is not a member of.
     *
     * @param \stdClass $course
     * @param int $userid
     * @return int The row id.
     */
    private function row_in_a_group_the_user_left(\stdClass $course, int $userid): int {
        $group = (int) $this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')->create_ledger_row([
            'courseid' => (int) $course->id,
            'groupid' => $group,
            'userid' => $userid,
        ]);
    }

    /**
     * Run the task as cron would, with fresh memos.
     *
     * @param int $courseid
     * @param array $userids The ids queued.
     * @return void
     */
    private function run_task(int $courseid, array $userids): void {
        course_access::reset_memo();
        $task = new reattribute_users();
        $task->set_custom_data(['courseid' => $courseid, 'userids' => $userids]);
        $task->execute();
    }
}
