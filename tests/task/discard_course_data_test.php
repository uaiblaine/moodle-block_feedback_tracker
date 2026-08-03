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
 * Tests for the delayed discard of a removed block's course data.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\removal_grace;

/**
 * Removing the block from a course must eventually discard that course's
 * measured history — but only when the removal was really meant, and only
 * after enough time to undo it. Several ordinary administrative actions remove
 * every block from a course and immediately put them back; those must not cost
 * a course its history.
 *
 * @covers \block_feedback_tracker\task\discard_course_data
 * @covers \block_feedback_tracker\local\sla\removal_grace
 */
final class discard_course_data_test extends \advanced_testcase {
    /**
     * Swallow the task's mtrace() output, which PHPUnit 11 treats as risky.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        course_access::reset_memo();
        ob_start();
    }

    /**
     * Tear down the per-test output buffer.
     *
     * @return void
     */
    protected function tearDown(): void {
        ob_end_clean();
        parent::tearDown();
    }

    /**
     * Off by default, so an upgrade never starts discarding history because a
     * new version shipped a policy.
     *
     * @return void
     */
    public function test_cleanup_is_off_until_switched_on(): void {
        $this->resetAfterTest();
        $this->assertFalse(removal_grace::is_active());
        set_config('removal_cleanup_active', '1', 'block_feedback_tracker');
        $this->assertTrue(removal_grace::is_active());
    }

    /**
     * A recycle bin set to never expire must not become an infinite grace —
     * that would silently disable the cleanup, which is the failure this whole
     * feature exists to prevent.
     *
     * @return void
     */
    public function test_a_never_expiring_recycle_bin_does_not_disable_cleanup(): void {
        $this->resetAfterTest();
        set_config('removal_grace_seconds', (string) (2 * DAYSECS), 'block_feedback_tracker');
        set_config('removal_grace_follow_recyclebin', '1', 'block_feedback_tracker');
        /* The course bin ships enabled with a one-week expiry, so it has to be
         * switched off explicitly to isolate the never-expiring category bin —
         * otherwise this asserts nothing about the zero case. */
        set_config('coursebinenable', '0', 'tool_recyclebin');
        set_config('categorybinenable', '1', 'tool_recyclebin');
        set_config('categorybinexpiry', '0', 'tool_recyclebin');

        $this->assertSame(2 * DAYSECS, removal_grace::seconds());
    }

    /**
     * The site's own undo window wins when it is longer: this plugin should
     * never discard a course's history faster than the site discards the
     * course.
     *
     * @return void
     */
    public function test_the_longest_enabled_recycle_bin_window_wins(): void {
        $this->resetAfterTest();
        set_config('removal_grace_seconds', (string) DAYSECS, 'block_feedback_tracker');
        set_config('removal_grace_follow_recyclebin', '1', 'block_feedback_tracker');
        set_config('coursebinenable', '1', 'tool_recyclebin');
        set_config('coursebinexpiry', (string) (3 * DAYSECS), 'tool_recyclebin');
        set_config('categorybinenable', '1', 'tool_recyclebin');
        set_config('categorybinexpiry', (string) (30 * DAYSECS), 'tool_recyclebin');

        $this->assertSame(30 * DAYSECS, removal_grace::seconds());
    }

    /**
     * A window under an hour leaves an accident unrecoverable, so it is floored.
     *
     * @return void
     */
    public function test_the_grace_period_has_a_floor(): void {
        $this->resetAfterTest();
        set_config('removal_grace_seconds', '60', 'block_feedback_tracker');
        set_config('removal_grace_follow_recyclebin', '0', 'block_feedback_tracker');

        $this->assertSame(removal_grace::MIN_SECONDS, removal_grace::seconds());
    }

    /**
     * The headline behaviour: with the block genuinely gone, the course's
     * history is discarded and the reason is recorded — Moodle logs nothing at
     * all when a block is deleted, so this row is the only trace.
     *
     * @return void
     */
    public function test_data_is_discarded_when_the_block_is_really_gone(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->build_course_with_data();

        // Remove every instance, as a teacher deleting the block would.
        $this->remove_block_instances((int) $course->id);
        course_access::reset_memo();

        $task = new discard_course_data();
        $task->set_custom_data(['courseid' => (int) $course->id]);
        $task->execute();

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id]));
        $this->assertTrue(
            $DB->record_exists('block_feedback_tracker_log', ['reason' => 'block_removed']),
            'A silent mass deletion needs a record of why it happened.'
        );
    }

    /**
     * The defence that makes the delay worth having. Restoring a backup into an
     * existing course with "delete the current contents" calls
     * remove_course_contents(), which removes every block from the course and
     * then the restore puts them back. Course import does the same. Without the
     * run-time re-check, a routine restore would destroy a year of measurement
     * a week later with nothing linking the two.
     *
     * @return void
     */
    public function test_data_survives_when_the_block_came_back(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $coursectx] = $this->build_course_with_data();
        $before = $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id]);
        $this->assertGreaterThan(0, $before);

        // What a delete-mode restore does: blocks go, then come back.
        $this->remove_block_instances((int) $course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        course_access::reset_memo();

        $task = new discard_course_data();
        $task->set_custom_data(['courseid' => (int) $course->id]);
        $task->execute();

        $this->assertSame(
            $before,
            $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id]),
            'A restore that removes and re-adds the block must not cost the course its history.'
        );
    }

    /**
     * Hiding a course is what archiving one looks like, and it must never read
     * as "the block is gone". The guard asks about block presence directly
     * rather than through is_processable(), which also requires visibility.
     *
     * @return void
     */
    public function test_hiding_the_course_does_not_discard_its_data(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->build_course_with_data();
        $before = $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id]);

        $DB->set_field('course', 'visible', 0, ['id' => $course->id]);
        course_access::reset_memo();
        $this->assertFalse(
            course_access::is_processable((int) $course->id),
            'Sanity: a hidden course is not processable.'
        );

        $task = new discard_course_data();
        $task->set_custom_data(['courseid' => (int) $course->id]);
        $task->execute();

        $this->assertSame(
            $before,
            $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id]),
            'Hiding a course must not be a data-destruction trigger.'
        );
    }

    /**
     * A course with a second instance of the block still on it has not really
     * lost the block, so nothing is discarded.
     *
     * @return void
     */
    public function test_a_surviving_second_instance_keeps_the_data(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $coursectx] = $this->build_course_with_data();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        $before = $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id]);

        // One of the two goes.
        $instances = $DB->get_records('block_instances', [
            'blockname' => 'feedback_tracker',
            'parentcontextid' => $coursectx->id,
        ], 'id ASC', '*', 0, 1);
        blocks_delete_instance(reset($instances));
        course_access::reset_memo();

        $task = new discard_course_data();
        $task->set_custom_data(['courseid' => (int) $course->id]);
        $task->execute();

        $this->assertSame(
            $before,
            $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id])
        );
    }

    /**
     * Removing the block queues the discard, and queues it once however many
     * times it happens inside one window.
     *
     * @return void
     */
    public function test_removal_queues_one_delayed_task(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('removal_cleanup_active', '1', 'block_feedback_tracker');
        [$course, $coursectx] = $this->build_course_with_data();

        $before = $DB->count_records('task_adhoc');
        $this->remove_block_instances((int) $course->id);
        $queued = $DB->count_records('task_adhoc') - $before;
        $this->assertSame(1, $queued);

        $row = $DB->get_record_sql(
            "SELECT * FROM {task_adhoc} WHERE classname = :cn ORDER BY id DESC",
            ['cn' => '\\block_feedback_tracker\\task\\discard_course_data'],
            IGNORE_MULTIPLE
        );
        $this->assertNotEmpty($row);
        $this->assertGreaterThan(
            time() + removal_grace::MIN_SECONDS - 5,
            (int) $row->nextruntime,
            'The discard must not be runnable before the grace period elapses.'
        );
    }

    /**
     * With the feature off, removing the block queues nothing — the historical
     * behaviour, where the data simply stops being processed.
     *
     * @return void
     */
    public function test_removal_queues_nothing_while_the_feature_is_off(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->build_course_with_data();

        $before = $DB->count_records('task_adhoc');
        $this->remove_block_instances((int) $course->id);
        $this->assertSame($before, $DB->count_records('task_adhoc'));
    }

    /**
     * Delete every feedback_tracker instance on a course, the way core does.
     *
     * @param int $courseid
     * @return void
     */
    private function remove_block_instances(int $courseid): void {
        global $DB;
        $coursectx = \context_course::instance($courseid);
        $instances = $DB->get_records('block_instances', [
            'blockname' => 'feedback_tracker',
            'parentcontextid' => $coursectx->id,
        ]);
        foreach ($instances as $instance) {
            blocks_delete_instance($instance);
        }
    }

    /**
     * A course carrying the block and one ledger row.
     *
     * @return array The course and its context.
     */
    private function build_course_with_data(): array {
        $course = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        course_access::reset_memo();

        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')
            ->create_ledger_row(['courseid' => $course->id]);

        return [$course, $coursectx];
    }
}
