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
 * The courses waiting for a bulk block removal.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\audit\recompute_log;
use block_feedback_tracker\task\bulk_remove_blocks;

/**
 * Reads and feeds the queue behind pages/bulk_remove.php.
 *
 * The page queues a {@see bulk_remove_blocks} adhoc task and returns at once;
 * cron removes the blocks on its next run. Until then the courses still carry
 * the block, so without this class the finder would offer them again and an
 * administrator who saw nothing change would queue them twice. The queue is
 * read from the waiting tasks themselves, not from a table of its own, so it
 * cannot disagree with what cron is about to do.
 */
final class bulk_removal_queue {
    /** Completed removals listed under "Recently completed". */
    public const RECENT_LIMIT = 5;

    /**
     * Ids of the courses in every bulk removal not yet finished, running ones
     * included.
     *
     * @return int[]
     */
    public static function course_ids(): array {
        $ids = [];
        foreach (\core\task\manager::get_adhoc_tasks(bulk_remove_blocks::class) as $task) {
            $data = (array) $task->get_custom_data();
            foreach ((array) ($data['courseids'] ?? []) as $id) {
                $ids[(int) $id] = (int) $id;
            }
        }
        return array_values($ids);
    }

    /**
     * Queue the removal of the selected courses, keeping only those the filter
     * still offers.
     *
     * The ids come from the browser, so they are checked again here: a course
     * the filter does not match, one without the block and one already queued
     * are skipped and counted, never queued. A filter with no question
     * answered offers nothing, as the form requires.
     *
     * @param array $filters The filter the list was built from ({@see course_finder::candidates()}).
     * @param array $courseids Selected course ids, at most {@see course_finder::MAX_RESULTS}.
     * @param bool $discardnow Discard the measured history at once instead of after the grace period.
     * @param int $userid The administrator queuing the removal.
     * @return array{queued:int, skipped:int}
     * @throws \coding_exception When more than {@see course_finder::MAX_RESULTS} ids are passed.
     */
    public static function enqueue(array $filters, array $courseids, bool $discardnow, int $userid): array {
        $courseids = array_values(array_unique(array_map('intval', $courseids)));
        if (count($courseids) > course_finder::MAX_RESULTS) {
            throw new \coding_exception('At most ' . course_finder::MAX_RESULTS . ' courses per bulk removal.');
        }
        $valid = course_finder::has_filter($filters) ? course_finder::matching_ids($filters, $courseids) : [];
        if (!empty($valid)) {
            $task = new bulk_remove_blocks();
            $task->set_custom_data([
                'courseids' => $valid,
                'discardnow' => $discardnow ? 1 : 0,
                'triggeredby' => $userid,
            ]);
            \core\task\manager::queue_adhoc_task($task);
        }
        return ['queued' => count($valid), 'skipped' => count($courseids) - count($valid)];
    }

    /**
     * The latest finished bulk removals, newest first, from the audit log the
     * task writes when it ends.
     *
     * @return array List of arrays with time, courses, removed, discarded and skipped.
     */
    public static function recent(): array {
        global $DB;
        $rows = $DB->get_records(
            'block_feedback_tracker_log',
            ['reason' => recompute_log::REASON_BULK_REMOVAL],
            'timefinished DESC, id DESC',
            'id, details, timefinished',
            0,
            self::RECENT_LIMIT
        );
        $out = [];
        foreach ($rows as $row) {
            $details = json_decode((string) $row->details, true) ?: [];
            $out[] = [
                'time' => (int) $row->timefinished,
                'courses' => (int) ($details['courses'] ?? 0),
                'removed' => (int) ($details['blocksremoved'] ?? 0),
                'discarded' => (int) ($details['rowsdiscarded'] ?? 0),
                'skipped' => (int) ($details['skipped'] ?? 0),
            ];
        }
        return $out;
    }
}
