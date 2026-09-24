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
 * Adhoc task: stamp allocations the reconciler discovered.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\submission_ledger;

/**
 * Stamps the marker allocations `reconcile_ledger`'s allocation sweep found.
 *
 * It cannot route through {@see backfill_one_submission}: the ordinary upsert
 * writes neither `timeallocated` nor `allocsource`, so the row would still
 * match the sweep (`timeallocated IS NULL`) on every pass.
 *
 * The discovery instant travels in the payload rather than being read from the
 * clock here. `ALLOC_SOURCE_RECONCILED` declares the value accurate only to
 * the sweep period; taking `time()` in the worker would add however far behind
 * cron is running on top of that.
 *
 * Because every batch embeds its own instant, the payload never dedups; see
 * {@see reconcile_ledger::queue_stamps()}.
 */
class stamp_allocations extends \core\task\adhoc_task {
    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_stamp_allocations', 'block_feedback_tracker');
    }

    /**
     * Stamp each (cmid, userid) pair in the payload.
     *
     * Custom data shape:
     *   ['rows' => [
     *       ['cmid' => int, 'userid' => int, 'courseid' => int, 'when' => int],
     *       ...
     *   ]]
     *
     * No participation re-check, unlike {@see backfill_one_submission}: this
     * task only ever updates rows that already exist, so a departed user's rows
     * are removed by the sweep that owns that job and there is nothing here to
     * resurrect. Processability is still re-checked, because a course whose
     * block went away between dispatch and execute should stop being measured.
     *
     * @return void
     */
    public function execute(): void {
        $data = (array) $this->get_custom_data();
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $when = (int) ($row['when'] ?? 0);
            if ($when <= 0) {
                continue;
            }
            if (!course_access::is_processable((int) ($row['courseid'] ?? 0))) {
                continue;
            }
            submission_ledger::stamp_allocation_for_user(
                (int) ($row['cmid'] ?? 0),
                (int) ($row['userid'] ?? 0),
                $when,
                submission_ledger::ALLOC_SOURCE_RECONCILED
            );
        }
    }
}
