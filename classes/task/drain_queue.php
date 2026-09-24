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
 * Scheduled task: dispatch dirty-queue tuples to adhoc recompute tasks.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\audit\recompute_log;
use block_feedback_tracker\local\sla\dirty_queue;
use block_feedback_tracker\local\sla\process_memos;

/**
 * Every five minutes, reads up to `recompute_batch_size` tuples from
 * {block_feedback_tracker_queue} in FIFO order and queues one `recompute_one`
 * adhoc task per tuple. A scheduled task runs on one cron worker at a time,
 * while any worker can claim an adhoc task, so dispatching spreads the
 * recompute work across the cluster.
 *
 * `queue_adhoc_task($task, true)` collapses a pending task with the same
 * component, class and custom data, so a burst of grading on one
 * (courseid, groupid) tuple, including the task the submission_graded
 * observer queues, yields a single recompute. Such a refusal is counted apart
 * from the dispatches, because the same refusal also comes from a
 * retry-exhausted task on the versions
 * {@see reconcile_ledger::queue_repair()} lists, and then nothing will
 * recompute the tuple while the dead row is kept.
 *
 * The queue row is not removed here: `recompute_one::execute()` deletes it
 * after a successful `rollup_service::recompute_group()`, so a recompute that
 * fails or is skipped leaves the tuple dirty for the next tick.
 */
class drain_queue extends \core\task\scheduled_task {
    /** Default soft time cap (seconds). */
    public const DEFAULT_TIME_CAP = 50;
    /** Default batch size per run. */
    public const DEFAULT_BATCH_SIZE = 200;

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_drain_queue', 'block_feedback_tracker');
    }

    /**
     * Read a batch of queue rows and dispatch one adhoc recompute task per
     * tuple. The rollup work itself happens on whichever cron worker picks up
     * each adhoc task.
     *
     * @return void
     */
    public function execute(): void {
        process_memos::reset();
        $started = time();
        $timecap = (int) (get_config('block_feedback_tracker', 'drain_time_cap_seconds') ?: self::DEFAULT_TIME_CAP);
        $batchsize = (int) (get_config('block_feedback_tracker', 'recompute_batch_size') ?: self::DEFAULT_BATCH_SIZE);
        $deadline = $started + $timecap;

        $batch = dirty_queue::pop_batch($batchsize);
        if (empty($batch)) {
            return;
        }

        $ok = 0;
        $refused = 0;
        $fail = 0;
        foreach ($batch as $row) {
            if (time() > $deadline) {
                break;
            }
            try {
                $task = new recompute_one();
                /*
                 * Same keys in the same order as
                 * observer::submission_graded(): core's dedup compares the
                 * JSON-encoded custom data as a string, so any difference
                 * would queue two tasks for one tuple.
                 */
                $task->set_custom_data([
                    'courseid' => (int) $row->courseid,
                    'groupid'  => (int) $row->groupid,
                ]);
                if (\core\task\manager::queue_adhoc_task($task, true) !== false) {
                    $ok++;
                } else {
                    $refused++;
                }
            } catch (\Throwable $e) {
                debugging(sprintf(
                    'drain_queue dispatch failed for courseid=%d groupid=%d: %s',
                    (int) $row->courseid,
                    (int) $row->groupid,
                    $e->getMessage()
                ));
                $fail++;
            }
        }

        if ($refused > 0) {
            mtrace(sprintf(
                'drain_queue: %d of %d tuple(s) were not queued '
                . '(a recompute already pending, or blocked by a retry-exhausted task).',
                $refused,
                $ok + $refused + $fail
            ));
        }

        if ($ok > 0 || $refused > 0 || $fail > 0) {
            recompute_log::record(
                recompute_log::REASON_DRAIN,
                $ok,
                null,
                [
                    'refused'  => $refused,
                    'failures' => $fail,
                    'took_ms'  => (time() - $started) * 1000,
                    'mode'     => 'dispatch',
                ],
                $started,
                time()
            );
        }
    }
}
