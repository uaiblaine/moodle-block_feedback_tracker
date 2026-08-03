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
 * Tests for the bulk block-removal task.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\course_access;

/**
 * The batch runs unattended over courses an administrator picked minutes or
 * hours earlier, so it has to cope with the world having moved: a course
 * deleted in between, one whose block was already taken off, one that simply
 * fails. None of those may abort the sweep.
 *
 * @covers \block_feedback_tracker\task\bulk_remove_blocks
 */
final class bulk_remove_blocks_test extends \advanced_testcase {
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
     * The block goes from every selected course; the data is left to the grace
     * period, which is the default mode.
     *
     * @return void
     */
    public function test_blocks_are_removed_and_data_left_for_the_grace_period(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->course_with_block_and_data();
        $b = $this->course_with_block_and_data();

        $this->run_task([(int) $a->id, (int) $b->id], false);

        foreach ([$a, $b] as $course) {
            $this->assertFalse(
                course_access::block_present_for_course((int) $course->id),
                'The block should be gone.'
            );
            $this->assertGreaterThan(
                0,
                $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id]),
                'Without discardnow the data waits for the grace period.'
            );
        }
    }

    /**
     * The deliberate mode skips the grace period, for archiving a finished
     * period. This is the one path here with no way back, which is why the UI
     * puts a typed confirmation in front of it.
     *
     * @return void
     */
    public function test_discard_now_removes_the_data_in_the_same_pass(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->course_with_block_and_data();

        $this->run_task([(int) $course->id], true);

        $this->assertSame(
            0,
            $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id])
        );
    }

    /**
     * A course deleted between selection and execution is skipped, not fatal.
     * Its data went with it through course_deleted, so there is nothing to do
     * and nothing worth aborting a several-hundred-course sweep over.
     *
     * @return void
     */
    public function test_a_course_deleted_in_the_meantime_does_not_abort_the_batch(): void {
        global $DB;
        $this->resetAfterTest();
        $gone = $this->course_with_block_and_data();
        $survivor = $this->course_with_block_and_data();
        $goneid = (int) $gone->id;
        delete_course($gone, false);

        $this->run_task([$goneid, (int) $survivor->id], true);

        $this->assertFalse(
            course_access::block_present_for_course((int) $survivor->id),
            'The rest of the batch must still be processed.'
        );
        $this->assertSame(
            0,
            $DB->count_records('block_feedback_tracker_sub', ['courseid' => $survivor->id])
        );
    }

    /**
     * The run is recorded. Moodle emits no event when a block is deleted, so
     * without this row a mass removal would leave no trace anywhere of who did
     * it or what it touched.
     *
     * @return void
     */
    public function test_the_batch_is_audited(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->course_with_block_and_data();

        $this->run_task([(int) $course->id], true);

        $row = $DB->get_record('block_feedback_tracker_log', ['reason' => 'bulk_removal']);
        $this->assertNotEmpty($row, 'A mass removal must leave a record.');
        $details = json_decode((string) $row->details, true);
        $this->assertSame(1, (int) $details['courses']);
        $this->assertSame(1, (int) $details['discardnow']);
    }

    /**
     * Run the task over a set of course ids.
     *
     * @param array $courseids
     * @param bool $discardnow
     * @return void
     */
    private function run_task(array $courseids, bool $discardnow): void {
        $task = new bulk_remove_blocks();
        $task->set_custom_data([
            'courseids' => $courseids,
            'discardnow' => $discardnow ? 1 : 0,
            'triggeredby' => 2,
        ]);
        $task->execute();
        course_access::reset_memo();
    }

    /**
     * A course carrying the block and one ledger row.
     *
     * @return \stdClass
     */
    private function course_with_block_and_data(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();
        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')
            ->create_ledger_row(['courseid' => $course->id]);
        return $course;
    }
}
