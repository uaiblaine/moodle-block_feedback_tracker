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
 * Tests for the bulk removal queue.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\task\bulk_remove_blocks;

/**
 * The queue is what stops a course being queued twice while cron has not run
 * yet, and enqueue() is the server-side check of a selection made in the
 * browser, so a course it lets through is a block removed by mistake.
 *
 * @covers \block_feedback_tracker\local\sla\bulk_removal_queue
 */
final class bulk_removal_queue_test extends \advanced_testcase {
    /** A filter every course built here matches: none has an end date. */
    private const FILTER = ['noenddate' => 1];

    /**
     * Flush the block-presence memo between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        course_access::reset_memo();
    }

    /**
     * Valid courses are queued in one task and read back as queued; a course
     * without the block is skipped and counted.
     *
     * @return void
     */
    public function test_enqueue_queues_the_valid_courses_and_counts_the_rest(): void {
        $this->resetAfterTest();
        $a = $this->course_with_block();
        $b = $this->course_with_block();
        $noblock = $this->getDataGenerator()->create_course(['enddate' => 0]);

        $result = bulk_removal_queue::enqueue(self::FILTER, [$a->id, $b->id, $noblock->id], false, 2);

        $this->assertSame(['queued' => 2, 'skipped' => 1], $result);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(bulk_remove_blocks::class));
        $queued = bulk_removal_queue::course_ids();
        sort($queued);
        $this->assertSame([(int) $a->id, (int) $b->id], $queued);
    }

    /**
     * A course already waiting is not queued a second time.
     *
     * @return void
     */
    public function test_a_queued_course_is_not_queued_twice(): void {
        $this->resetAfterTest();
        $course = $this->course_with_block();
        bulk_removal_queue::enqueue(self::FILTER, [$course->id], false, 2);

        $result = bulk_removal_queue::enqueue(self::FILTER, [$course->id], false, 2);

        $this->assertSame(['queued' => 0, 'skipped' => 1], $result);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(bulk_remove_blocks::class));
    }

    /**
     * A course the filter does not match is not queued, even though it carries
     * the block; the same course is queued under a filter that matches it.
     *
     * @return void
     */
    public function test_the_filter_is_checked_on_the_server(): void {
        $this->resetAfterTest();
        $course = $this->course_with_block();

        $this->assertSame(
            ['queued' => 0, 'skipped' => 1],
            bulk_removal_queue::enqueue(['hiddenonly' => 1], [$course->id], false, 2)
        );
        $this->assertSame(
            ['queued' => 0, 'skipped' => 1],
            bulk_removal_queue::enqueue([], [$course->id], false, 2),
            'An empty filter offers nothing, as the form requires.'
        );
        $this->assertSame(
            ['queued' => 0, 'skipped' => 1],
            bulk_removal_queue::enqueue(['endedbefore' => -1, 'startedbefore' => -1, 'categoryid' => -1], [$course->id], false, 2),
            'Values the filter ignores are no answer either: they would offer the whole site.'
        );
        $this->assertSame(
            ['queued' => 1, 'skipped' => 0],
            bulk_removal_queue::enqueue(self::FILTER, [$course->id], false, 2),
            'Control: a matching filter queues it.'
        );
    }

    /**
     * One request never queues more than the list can show.
     *
     * @return void
     */
    public function test_more_than_the_ceiling_is_refused(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        bulk_removal_queue::enqueue(self::FILTER, range(1, course_finder::MAX_RESULTS + 1), false, 2);
    }

    /**
     * A finished run is listed from the audit log, newest first, and leaves
     * the queue.
     *
     * @return void
     */
    public function test_finished_runs_are_listed_newest_first(): void {
        global $DB;
        $this->resetAfterTest();
        $first = $this->course_with_block();
        $second = $this->course_with_block();
        bulk_removal_queue::enqueue(self::FILTER, [$first->id], false, 2);
        ob_start();
        $this->runAdhocTasks(bulk_remove_blocks::class);
        bulk_removal_queue::enqueue(self::FILTER, [$second->id], true, 2);
        $this->runAdhocTasks(bulk_remove_blocks::class);
        ob_end_clean();
        $DB->set_field('block_feedback_tracker_log', 'timefinished', time() - 60, ['reason' => 'bulk_removal']);
        $newest = $DB->get_field('block_feedback_tracker_log', 'MAX(id)', ['reason' => 'bulk_removal']);
        $DB->set_field('block_feedback_tracker_log', 'timefinished', time(), ['id' => $newest]);

        $recent = bulk_removal_queue::recent();

        $this->assertSame([], bulk_removal_queue::course_ids());
        $this->assertCount(2, $recent);
        $this->assertSame(1, $recent[0]['courses']);
        $this->assertSame(1, $recent[0]['removed']);
        $this->assertGreaterThan($recent[1]['time'], $recent[0]['time']);
    }

    /**
     * A course with no end date carrying the block.
     *
     * @return \stdClass
     */
    private function course_with_block(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['enddate' => 0]);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();
        return $course;
    }
}
