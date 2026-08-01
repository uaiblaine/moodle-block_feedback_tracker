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
 * Adhoc task to recompute a single (courseid, groupid) rollup.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\rollup_service;

/**
 * Queued by the submission_graded observer for fast turnaround on freshly
 * graded submissions. Calls rollup_service::recompute_group() directly and
 * removes the queue entry if one was waiting for the same tuple, so the
 * 5-minute drain task doesn't redo the work.
 *
 * `core\task\manager::queue_adhoc_task($task, true)` deduplicates bursts of
 * grading events on the same tuple.
 */
class recompute_one extends \core\task\adhoc_task {
    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_recompute_one', 'block_feedback_tracker');
    }

    /**
     * Run the rollup recompute.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;
        $data = (array) $this->get_custom_data();
        $courseid = (int) ($data['courseid'] ?? 0);
        $groupid = (int) ($data['groupid'] ?? 0);
        if ($courseid <= 0) {
            return;
        }
        $started = time();
        if (!rollup_service::recompute_group($courseid, $groupid)) {
            /* Another worker holds the tuple's lock, so nothing was
             * recomputed. Leaving the queue row in place keeps the tuple dirty
             * for the next drain tick; deleting it here would retire work that
             * was never done. */
            return;
        }

        /* Retire the queue row only if it has not been re-enqueued while we
         * were recomputing. dirty_queue::enqueue() refreshes timeenqueued on
         * an existing row, so a newer stamp means fresh dirt arrived after our
         * reads and must survive. (timeenqueued has second granularity, so a
         * re-enqueue inside the same second as $started is indistinguishable
         * from one that preceded it — the next event on the tuple recovers
         * it.) */
        $DB->delete_records_select(
            'block_feedback_tracker_queue',
            'courseid = :courseid AND groupid = :groupid AND timeenqueued <= :started',
            ['courseid' => $courseid, 'groupid' => $groupid, 'started' => $started]
        );
    }
}
