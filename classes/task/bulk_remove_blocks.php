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
 * Adhoc task: remove the block from a batch of courses.
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
 * Removes the block from every course in a selected batch, out of band.
 *
 * Runs as a task rather than in the request that submitted the form: an
 * end-of-year sweep can select hundreds of courses, each removal tears down a
 * block context, and a request that timed out part-way would leave a partial
 * result nobody can see.
 *
 * Each removal goes through the block's own instance_delete(), which defers
 * the discard of the course's history by the grace period
 * ({@see discard_course_data}). The `discardnow` mode, for archiving a
 * finished year, deletes the history at once instead and cannot be undone;
 * pages/bulk_remove.php queues either mode only after the administrator types
 * the number of selected courses.
 */
class bulk_remove_blocks extends \core\task\adhoc_task {
    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_bulk_remove_blocks', 'block_feedback_tracker');
    }

    /**
     * Remove the block from each course in the batch.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $data = (array) $this->get_custom_data();
        $courseids = array_map('intval', (array) ($data['courseids'] ?? []));
        $discardnow = !empty($data['discardnow']);
        $triggeredby = (int) ($data['triggeredby'] ?? 0) ?: null;
        if (empty($courseids)) {
            return;
        }

        $removed = 0;
        $discarded = 0;
        $skipped = 0;

        foreach ($courseids as $courseid) {
            if (!$DB->record_exists('course', ['id' => $courseid])) {
                /* Deleted between selecting and running. Its data went with it
                 * via course_deleted, so there is nothing to do and nothing
                 * worth failing the batch over. */
                $skipped++;
                continue;
            }

            try {
                $coursectx = \context_course::instance($courseid, IGNORE_MISSING);
            } catch (\Throwable $e) {
                $skipped++;
                continue;
            }
            if (!$coursectx) {
                $skipped++;
                continue;
            }

            $instances = $DB->get_records('block_instances', [
                'blockname' => 'feedback_tracker',
                'parentcontextid' => $coursectx->id,
            ]);
            foreach ($instances as $instance) {
                try {
                    blocks_delete_instance($instance);
                    $removed++;
                } catch (\Throwable $e) {
                    /* One bad course must not abort the batch — the rest of an
                     * end-of-year sweep still needs to happen, and the count
                     * below is what tells an administrator it was partial. */
                    $skipped++;
                    debugging(sprintf(
                        'bulk_remove_blocks: course %d failed: %s',
                        $courseid,
                        $e->getMessage()
                    ));
                }
            }

            if ($discardnow) {
                course_access::reset_memo();
                if (!course_access::block_present_for_course($courseid)) {
                    $discarded += $DB->count_records(
                        'block_feedback_tracker_sub',
                        ['courseid' => $courseid]
                    );
                    submission_ledger::delete_for_course($courseid);
                }
            }
        }

        /* Moodle emits no event when a block is deleted, so without this the
         * only trace of a mass removal would be its absence. */
        recompute_log::record(
            recompute_log::REASON_BULK_REMOVAL,
            $discardnow ? $discarded : $removed,
            $triggeredby,
            [
                'courses' => count($courseids),
                'blocksremoved' => $removed,
                'rowsdiscarded' => $discarded,
                'skipped' => $skipped,
                'discardnow' => $discardnow ? 1 : 0,
            ]
        );

        mtrace(sprintf(
            'bulk_remove_blocks: %d course(s), %d block(s) removed, %d row(s) discarded, %d skipped.',
            count($courseids),
            $removed,
            $discarded,
            $skipped
        ));
    }
}
