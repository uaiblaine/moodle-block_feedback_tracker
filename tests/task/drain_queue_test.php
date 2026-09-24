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
 * Tests for the drain_queue scheduled task.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\dirty_queue;

/**
 * The drain task pops queued (course, group) tuples and queues one
 * `recompute_one` adhoc task per tuple. The adhoc task owns the queue-row
 * lifecycle: it deletes the row after a successful recompute. Failures
 * leave the row in place for the next scheduled tick to redispatch.
 *
 * @covers \block_feedback_tracker\task\drain_queue
 */
final class drain_queue_test extends \advanced_testcase {
    public function test_empty_queue_is_noop(): void {
        $this->resetAfterTest();
        $this->seed_config();

        (new drain_queue())->execute();

        global $DB;
        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_queue'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(recompute_one::class));
    }

    public function test_single_queued_tuple_dispatches_adhoc_task(): void {
        $this->resetAfterTest();
        $this->seed_config();
        dirty_queue::enqueue(42, 99, dirty_queue::REASON_SUBMISSION);

        (new drain_queue())->execute();

        global $DB;
        // Queue row stays in place — recompute_one removes it after success.
        $this->assertSame(1, (int) $DB->count_records('block_feedback_tracker_queue'));
        $this->assertSame(
            0,
            (int) $DB->count_records('block_feedback_tracker_group'),
            'drain_queue must not call recompute_group itself.'
        );

        $adhoc = \core\task\manager::get_adhoc_tasks(recompute_one::class);
        $this->assertCount(1, $adhoc);
        $data = (array) reset($adhoc)->get_custom_data();
        $this->assertSame(42, (int) $data['courseid']);
        $this->assertSame(99, (int) $data['groupid']);
    }

    public function test_multiple_tuples_dispatch_one_adhoc_each(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $tuples = [[10, 1], [10, 2], [20, 3], [30, 0]];
        foreach ($tuples as [$c, $g]) {
            dirty_queue::enqueue($c, $g, dirty_queue::REASON_SUBMISSION);
        }

        (new drain_queue())->execute();

        global $DB;
        $this->assertSame(4, (int) $DB->count_records('block_feedback_tracker_queue'));
        $this->assertCount(4, \core\task\manager::get_adhoc_tasks(recompute_one::class));
    }

    public function test_drain_writes_audit_log_row(): void {
        $this->resetAfterTest();
        $this->seed_config();
        dirty_queue::enqueue(42, 99, dirty_queue::REASON_SUBMISSION);

        (new drain_queue())->execute();

        global $DB;
        $this->assertGreaterThan(0, (int) $DB->count_records('block_feedback_tracker_log', [
            'reason' => 'drain',
        ]));
    }

    public function test_running_dispatched_adhoc_recomputes_and_removes_queue_row(): void {
        $this->resetAfterTest();
        $this->seed_config();
        dirty_queue::enqueue(42, 99, dirty_queue::REASON_SUBMISSION);

        (new drain_queue())->execute();
        foreach (\core\task\manager::get_adhoc_tasks(recompute_one::class) as $task) {
            $task->execute();
        }

        global $DB;
        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_queue'));
        $row = $DB->get_record('block_feedback_tracker_group', ['courseid' => 42, 'groupid' => 99]);
        $this->assertNotFalse($row);
    }

    public function test_dispatch_dedupes_against_existing_observer_adhoc(): void {
        $this->resetAfterTest();
        $this->seed_config();

        /*
         * Mirror the submission_graded observer: queue an adhoc for
         * (42, 99) before the drain task fires. The dispatcher must
         * collapse into the same adhoc (core compares the JSON-encoded
         * custom data as a string), not create a second one.
         */
        $existing = new recompute_one();
        $existing->set_custom_data(['courseid' => 42, 'groupid' => 99]);
        \core\task\manager::queue_adhoc_task($existing, true);

        dirty_queue::enqueue(42, 99, dirty_queue::REASON_GRADE);

        (new drain_queue())->execute();

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(recompute_one::class));
    }

    /**
     * Score-formula and calendar settings read by rollup_service.
     */
    private function seed_config(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('sla_goal_hours', '24', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');
        set_config('weight_compliance', '0.40', 'block_feedback_tracker');
        set_config('weight_median', '0.25', 'block_feedback_tracker');
        set_config('weight_critical', '0.15', 'block_feedback_tracker');
        set_config('weight_pending', '0.10', 'block_feedback_tracker');
        set_config('weight_trend', '0.10', 'block_feedback_tracker');
        set_config('trend_window_days', '30', 'block_feedback_tracker');
        set_config('recompute_batch_size', '200', 'block_feedback_tracker');
        set_config('drain_time_cap_seconds', '50', 'block_feedback_tracker');
    }
}
