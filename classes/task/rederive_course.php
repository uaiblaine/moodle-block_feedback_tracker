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
 * Adhoc task: rebuild the ledger of one restored course from its submissions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\process_memos;
use block_feedback_tracker\local\sla\retention;

/**
 * Queued by the block's restore task for the course a backup was restored
 * into. A restore fires none of the events the ledger is written from, so the
 * course's submissions would otherwise wait for the reconciler's missing-row
 * sweep, or for `backfill_history`, which is off by default.
 *
 * Walks every {assign_submission} of the course once, through the same
 * dispatch `backfill_history` uses (the retention floor included), and queues
 * `backfill_one_submission` tasks; the writer is idempotent, so rows a full
 * backup already restored are kept and only refreshed. It runs whatever
 * `backfill_active` says: the restored history was measured on the source
 * course, and this rebuilds it rather than importing anything new.
 */
class rederive_course extends \core\task\adhoc_task {
    /** Submissions read per query while walking the course. */
    private const PAGE = 1000;

    /**
     * Queue the rebuild of one course, once.
     *
     * @param int $courseid The restored course.
     * @return void
     */
    public static function queue(int $courseid): void {
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_rederive_course', 'block_feedback_tracker');
    }

    /**
     * Dispatch every submission of the course to the backfill workers.
     *
     * @return void
     */
    public function execute(): void {
        process_memos::reset();
        $courseid = (int) ($this->get_custom_data()->courseid ?? 0);
        if (!course_access::is_processable($courseid)) {
            return;
        }
        $subchunk = (int) (get_config('block_feedback_tracker', 'backfill_sub_chunk') ?: backfill_history::DEFAULT_SUB_CHUNK);
        if ($subchunk < 1) {
            $subchunk = backfill_history::DEFAULT_SUB_CHUNK;
        }
        $floor = retention::cutoff() ?? 0;
        $cursor = 0;
        $dispatched = 0;
        do {
            [$count, $cursor, $complete] = backfill_history::dispatch_course_chunk(
                $courseid,
                $cursor,
                self::PAGE,
                $subchunk,
                $floor
            );
            $dispatched += $count;
        } while (!$complete);
        mtrace("rederive_course: course {$courseid}, {$dispatched} submission(s) queued for the ledger.");
    }
}
