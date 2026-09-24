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
 * Scheduled task: re-compute stale pending submissions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\audit\recompute_log;
use block_feedback_tracker\local\sla\pending_recomputer;

/**
 * Hourly pass to move pending submissions between SLA buckets as their
 * effective waiting time accrues. Without it, a pending submission banded
 * excellent (under 24 h by default) would still read excellent at hour 25.
 *
 * Targets pending rows whose `effectivecalver` is older than the current
 * calendar version, or whose `effectiveasof` is missing or more than an hour
 * old; see {@see pending_recomputer::recompute_stale()}.
 */
class recompute_pending extends \core\task\scheduled_task {
    /** Default soft time cap (seconds). */
    public const DEFAULT_TIME_CAP = 50;
    /** Default batch size per run. */
    public const DEFAULT_BATCH_SIZE = 1000;

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_recompute_pending', 'block_feedback_tracker');
    }

    /**
     * Re-compute one batch of stale pending rows.
     *
     * @return void
     */
    public function execute(): void {
        $started = time();
        $timecap = (int) (get_config('block_feedback_tracker', 'drain_time_cap_seconds') ?: self::DEFAULT_TIME_CAP);
        $batchsize = (int) (get_config('block_feedback_tracker', 'pending_batch_size') ?: self::DEFAULT_BATCH_SIZE);

        $result = pending_recomputer::recompute_stale($batchsize, $timecap);

        if ($result['count'] > 0) {
            recompute_log::record(
                recompute_log::REASON_DAILY_PENDING,
                $result['count'],
                null,
                [
                    'tuples_enqueued' => $result['tuples'],
                    'took_ms' => (time() - $started) * 1000,
                ],
                $started,
                time()
            );
        }
    }
}
