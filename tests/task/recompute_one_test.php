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
 * Tests for the per-tuple rollup recompute task.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\dirty_queue;
use block_feedback_tracker\local\sla\rollup_service;

/**
 * The task retires a queue row after recomputing, so the interesting cases are
 * the ones where it must NOT: a lock-skipped recompute did no work, and a
 * re-enqueue arriving mid-recompute is fresh dirt that has to survive.
 *
 * @covers \block_feedback_tracker\task\recompute_one
 */
final class recompute_one_test extends \advanced_testcase {
    /**
     * Queue a tuple and run the task against it.
     *
     * @param int $courseid
     * @param int $groupid
     * @return void
     */
    private function run_task(int $courseid, int $groupid): void {
        $task = new recompute_one();
        $task->set_custom_data(['courseid' => $courseid, 'groupid' => $groupid]);
        $task->execute();
    }

    /**
     * Count the queue rows for one tuple.
     *
     * @param int $courseid
     * @param int $groupid
     * @return int
     */
    private function queued(int $courseid, int $groupid): int {
        global $DB;
        return $DB->count_records('block_feedback_tracker_queue', [
            'courseid' => $courseid,
            'groupid' => $groupid,
        ]);
    }

    /**
     * Switch to the one lock factory that actually denies a second acquire
     * within the same process.
     *
     * The default factories do not: postgres_lock_factory keeps its held-lock
     * map static and treats a repeat acquire of the same token as a hash
     * collision, handing back a lock; mysql_lock_factory issues GET_LOCK on
     * the same session, which also succeeds. A test relying on either would
     * pass without ever exercising the skip path.
     *
     * @return \core\lock\lock_factory
     */
    private function use_db_record_locks(): \core\lock\lock_factory {
        global $CFG;
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        return \core\lock\lock_config::get_lock_factory('block_feedback_tracker');
    }

    /**
     * The ordinary path: recompute happens and the queue row is retired.
     *
     * @return void
     */
    public function test_successful_recompute_retires_the_queue_row(): void {
        $this->resetAfterTest();

        $courseid = 4242;
        $groupid = 7;
        dirty_queue::enqueue($courseid, $groupid, 'submission');
        $this->assertSame(1, $this->queued($courseid, $groupid));

        $this->run_task($courseid, $groupid);

        $this->assertSame(0, $this->queued($courseid, $groupid));
    }

    /**
     * The regression: when another worker holds the tuple's lock nothing is
     * recomputed, so the queue row must stay put. Deleting it would retire
     * work nobody did, and the rollup would stay stale until some later event
     * happened to touch the same tuple.
     *
     * @return void
     */
    public function test_queue_row_survives_a_lock_skipped_recompute(): void {
        $this->resetAfterTest();

        $courseid = 4243;
        $groupid = 8;
        dirty_queue::enqueue($courseid, $groupid, 'submission');

        $factory = $this->use_db_record_locks();
        $held = $factory->get_lock("rollup_{$courseid}_{$groupid}", 0);
        $this->assertNotFalse($held, 'Precondition: the test must be holding the tuple lock.');

        try {
            $this->run_task($courseid, $groupid);
            $this->assertSame(
                1,
                $this->queued($courseid, $groupid),
                'A lock-skipped recompute must leave the tuple queued.'
            );
        } finally {
            $held->release();
        }
    }

    /**
     * recompute_group() reports whether it did anything — the seam the task
     * depends on. Without a truthful answer the caller cannot tell a completed
     * recompute from a skipped one.
     *
     * @return void
     */
    public function test_recompute_group_reports_whether_it_ran(): void {
        $this->resetAfterTest();

        $courseid = 4244;
        $groupid = 9;

        $this->assertTrue(rollup_service::recompute_group($courseid, $groupid));

        $factory = $this->use_db_record_locks();
        $held = $factory->get_lock("rollup_{$courseid}_{$groupid}", 0);
        $this->assertNotFalse($held);

        try {
            $this->assertFalse(
                rollup_service::recompute_group($courseid, $groupid),
                'A held lock must be reported as a skip, not a success.'
            );
        } finally {
            $held->release();
        }
    }

    /**
     * Dirt arriving while the recompute is in flight must not be swallowed by
     * the retirement that follows it.
     *
     * The re-enqueue is stamped into the future to stand in for "after our
     * reads", since timeenqueued only has second granularity and a real race
     * would otherwise be indistinguishable inside one test tick.
     *
     * @return void
     */
    public function test_re_enqueue_during_recompute_survives(): void {
        global $DB;
        $this->resetAfterTest();

        $courseid = 4245;
        $groupid = 10;
        dirty_queue::enqueue($courseid, $groupid, 'submission');

        // Stand in for an enqueue that lands after the task captured its start.
        $DB->set_field(
            'block_feedback_tracker_queue',
            'timeenqueued',
            time() + 60,
            ['courseid' => $courseid, 'groupid' => $groupid]
        );

        $this->run_task($courseid, $groupid);

        $this->assertSame(
            1,
            $this->queued($courseid, $groupid),
            'Dirt enqueued after the recompute started must outlive the retirement.'
        );
    }

    /**
     * A queue row stamped before the run is retired normally — the bound must
     * not make the task stop retiring anything.
     *
     * @return void
     */
    public function test_older_queue_row_is_still_retired(): void {
        global $DB;
        $this->resetAfterTest();

        $courseid = 4246;
        $groupid = 11;
        dirty_queue::enqueue($courseid, $groupid, 'submission');
        $DB->set_field(
            'block_feedback_tracker_queue',
            'timeenqueued',
            time() - 600,
            ['courseid' => $courseid, 'groupid' => $groupid]
        );

        $this->run_task($courseid, $groupid);

        $this->assertSame(0, $this->queued($courseid, $groupid));
    }

    /**
     * Retirement is scoped to the tuple: another group's queue row is left
     * alone.
     *
     * @return void
     */
    public function test_other_tuples_are_untouched(): void {
        $this->resetAfterTest();

        dirty_queue::enqueue(4247, 1, 'submission');
        dirty_queue::enqueue(4247, 2, 'submission');

        $this->run_task(4247, 1);

        $this->assertSame(0, $this->queued(4247, 1));
        $this->assertSame(1, $this->queued(4247, 2));
    }

    /**
     * A task with no usable course id does nothing at all.
     *
     * @return void
     */
    public function test_missing_courseid_is_a_noop(): void {
        global $DB;
        $this->resetAfterTest();

        dirty_queue::enqueue(4248, 0, 'submission');
        $before = $DB->count_records('block_feedback_tracker_queue');

        $this->run_task(0, 0);

        $this->assertSame($before, $DB->count_records('block_feedback_tracker_queue'));
    }
}
