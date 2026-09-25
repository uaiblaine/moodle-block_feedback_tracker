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

/**
 * The task finishes a group change the observer handed to the background. It
 * re-checks the course when it runs, skips ids that are not users, and
 * re-dates as well as re-attributes. Each test runs the task on a row it would
 * move and asserts on a control row it does move.
 *
 * @covers \block_feedback_tracker\task\reattribute_users
 */
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
     * The task re-dates a row whose governing group override no longer
     * applies, as well as moving it.
     *
     * @return void
     */
    public function test_the_task_re_dates_the_rows_it_moves(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        $due = time() + 7 * 86400;
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => $due]);
        $group = (int) $this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
        $DB->insert_record('assign_overrides', (object) [
            'assignid' => $assign->id,
            'groupid' => $group,
            'userid' => null,
            'sortorder' => 1,
            'allowsubmissionsfromdate' => null,
            'duedate' => $due + 2 * 86400,
            'cutoffdate' => null,
        ]);
        $user = (int) $this->getDataGenerator()->create_and_enrol($course, 'student')->id;
        $row = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')->create_ledger_row([
            'courseid' => (int) $course->id,
            'groupid' => $group,
            'cmid' => (int) $assign->cmid,
            'iteminstance' => (int) $assign->id,
            'userid' => $user,
            'timeopens' => (int) $DB->get_field('assign', 'allowsubmissionsfromdate', ['id' => $assign->id]) ?: null,
            'timecloses' => $due + 2 * 86400,
            'hasrule' => 1,
        ]);

        $this->run_task((int) $course->id, [$user]);

        $stored = $DB->get_record('block_feedback_tracker_sub', ['id' => $row], '*', MUST_EXIST);
        $this->assertSame(0, (int) $stored->groupid, 'Moved out of the group the user is not in.');
        $this->assertSame($due, (int) $stored->timecloses, 'And given the activity date back.');
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
