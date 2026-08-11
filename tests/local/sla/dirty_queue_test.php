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
 * Tests for the dirty queue.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * The queue collapses a burst of writes for one (courseid, groupid) tuple into
 * a single row, and `enqueue()` is the last statement of every ledger write
 * path — so its failure modes are charged to whatever was writing the ledger,
 * long after that write has committed.
 *
 * @covers \block_feedback_tracker\local\sla\dirty_queue
 */
final class dirty_queue_test extends \advanced_testcase {
    /**
     * A second enqueue for the same tuple refreshes the row rather than adding
     * one, and carries the newer reason.
     *
     * @return void
     */
    public function test_a_repeat_enqueue_refreshes_the_same_row(): void {
        global $DB;
        $this->resetAfterTest();

        dirty_queue::enqueue(42, 7, dirty_queue::REASON_SUBMISSION);
        $first = $DB->get_record('block_feedback_tracker_queue', ['courseid' => 42, 'groupid' => 7]);
        $this->assertNotEmpty($first);
        $this->assertSame(dirty_queue::REASON_SUBMISSION, $first->reason);

        // Rewind the stamp so the refresh is observable without waiting a second.
        $DB->set_field('block_feedback_tracker_queue', 'timeenqueued', 100, ['id' => $first->id]);

        dirty_queue::enqueue(42, 7, dirty_queue::REASON_GRADE);

        $this->assertSame(1, $DB->count_records('block_feedback_tracker_queue'));
        $second = $DB->get_record('block_feedback_tracker_queue', ['courseid' => 42, 'groupid' => 7]);
        $this->assertSame((int) $first->id, (int) $second->id, 'The row is refreshed in place.');
        $this->assertSame(dirty_queue::REASON_GRADE, $second->reason, 'The newer cause wins.');
        $this->assertGreaterThan(100, (int) $second->timeenqueued, 'And the enqueue time moves forward.');
    }

    /**
     * Distinct tuples stay distinct — including groupid 0, which is a real
     * scope ("ungrouped"), not a missing value.
     *
     * @return void
     */
    public function test_distinct_tuples_get_distinct_rows(): void {
        global $DB;
        $this->resetAfterTest();

        dirty_queue::enqueue(42, 0, dirty_queue::REASON_SUBMISSION);
        dirty_queue::enqueue(42, 7, dirty_queue::REASON_SUBMISSION);
        dirty_queue::enqueue(43, 0, dirty_queue::REASON_SUBMISSION);

        $this->assertSame(3, $DB->count_records('block_feedback_tracker_queue'));
        $this->assertSame(3, dirty_queue::size());
    }

    /**
     * The hazard `enqueue()` recovers from is real: the tuple is unique at the
     * database level, so a concurrent writer that inserts between the read and
     * the insert turns an ordinary enqueue into a write exception.
     *
     * This is the control for the recovery path rather than the path itself.
     * `enqueue()` reads, misses, then inserts; reproducing the miss while the
     * row exists needs two connections interleaved inside one statement pair,
     * which this suite has no harness for. What can be pinned is that the
     * constraint really does fire — without it the recovery code would be
     * guarding nothing, and this test would fail.
     *
     * @return void
     */
    public function test_the_tuple_is_unique_at_the_database_level(): void {
        global $DB;
        $this->resetAfterTest();

        dirty_queue::enqueue(42, 7, dirty_queue::REASON_SUBMISSION);

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('block_feedback_tracker_queue', (object) [
            'courseid' => 42,
            'groupid' => 7,
            'reason' => dirty_queue::REASON_GRADE,
            'timeenqueued' => time(),
        ]);
    }

    /**
     * Reading a batch returns the oldest tuples first, and removing one takes
     * it out of the batch.
     *
     * @return void
     */
    public function test_the_batch_is_fifo_and_removable(): void {
        global $DB;
        $this->resetAfterTest();

        dirty_queue::enqueue(1, 0, dirty_queue::REASON_SUBMISSION);
        dirty_queue::enqueue(2, 0, dirty_queue::REASON_SUBMISSION);
        $DB->set_field('block_feedback_tracker_queue', 'timeenqueued', 100, ['courseid' => 1]);
        $DB->set_field('block_feedback_tracker_queue', 'timeenqueued', 200, ['courseid' => 2]);

        $batch = array_values(dirty_queue::pop_batch(10));
        $this->assertCount(2, $batch);
        $this->assertSame(1, (int) $batch[0]->courseid, 'Oldest first.');

        dirty_queue::remove((int) $batch[0]->id);

        $this->assertSame(1, dirty_queue::size());
        $remaining = array_values(dirty_queue::pop_batch(10));
        $this->assertSame(2, (int) $remaining[0]->courseid);
    }
}
