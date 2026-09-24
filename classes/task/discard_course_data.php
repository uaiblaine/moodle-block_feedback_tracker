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
 * Adhoc task: discard one course's data after the block was removed.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\audit\recompute_log;
use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\submission_ledger;

/**
 * Runs once, no earlier than the grace period after the block was removed from
 * a course, and discards that course's measured history.
 *
 * The delay makes an accidental removal recoverable, and the run-time re-check
 * is what makes the delay work: ordinary operations remove the block and put
 * it back within seconds. Restoring or importing into an existing course with
 * "delete the current contents" runs `remove_course_contents()`, which calls
 * `blocks_delete_all_for_context()` on the course context, and the restore
 * then recreates the block. Without the re-check such a restore would destroy
 * the course's history when this task ran, with nothing in any log connecting
 * the two.
 *
 * Block presence is asked directly, not through
 * {@see course_access::is_processable()}: that method also requires the course
 * to be visible, so hiding a course, as archiving does, would read as "the
 * block is gone" and trigger the deletion.
 */
class discard_course_data extends \core\task\adhoc_task {
    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_discard_course_data', 'block_feedback_tracker');
    }

    /**
     * Discard the course's data, unless the block came back.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $data = (array) $this->get_custom_data();
        $courseid = (int) ($data['courseid'] ?? 0);
        if ($courseid <= 0) {
            return;
        }

        if (!$DB->record_exists('course', ['id' => $courseid])) {
            /* The course itself is gone, so course_deleted already cleaned up.
             * Nothing to do, and nothing to complain about. */
            return;
        }

        if (course_access::block_present_for_course($courseid)) {
            mtrace(sprintf(
                'discard_course_data: course %d has the block again; keeping its history.',
                $courseid
            ));
            return;
        }

        $rows = $DB->count_records('block_feedback_tracker_sub', ['courseid' => $courseid]);
        submission_ledger::delete_for_course($courseid);

        /* Moodle logs nothing at all when a block is removed — there is no
         * block_deleted event in core — so without this entry a course's
         * history would vanish with no record of why. */
        recompute_log::record(
            recompute_log::REASON_BLOCK_REMOVED,
            $rows,
            null,
            ['courseid' => $courseid]
        );
        mtrace(sprintf(
            'discard_course_data: discarded %d row(s) for course %d.',
            $rows,
            $courseid
        ));
    }
}
