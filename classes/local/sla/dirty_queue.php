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
 * Dirty queue helper for the drain task.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Wraps the {block_feedback_tracker_queue} table.
 *
 * Producers (observers, calendar editors) call enqueue() to mark a
 * (courseid, groupid) tuple as needing rollup recompute. Consumers (the
 * drain task in Phase D) call pop_batch() + remove().
 *
 * Uniqueness on (courseid, groupid) collapses bursts of writes for the same
 * tuple into a single queue row; the row's `reason` reflects the most recent
 * cause, and `timeenqueued` is the most recent enqueue time.
 */
class dirty_queue {
    /** Reason: submission upserted. */
    public const REASON_SUBMISSION = 'submission';
    /** Reason: submission graded. */
    public const REASON_GRADE = 'grade';
    /** Reason: calendar saved (cday/chours). */
    public const REASON_CALENDAR = 'calendar';
    /** Reason: manual pause saved. */
    public const REASON_PAUSE = 'pause';
    /** Reason: bulk import / admin reset. */
    public const REASON_BULK = 'bulk';

    /**
     * Mark a (courseid, groupid) tuple dirty.
     *
     * @param int $courseid
     * @param int $groupid
     * @param string $reason One of self::REASON_*.
     * @return void
     */
    public static function enqueue(int $courseid, int $groupid, string $reason): void {
        global $DB;
        $now = time();
        $key = ['courseid' => $courseid, 'groupid' => $groupid];
        $existing = $DB->get_record('block_feedback_tracker_queue', $key, 'id');
        if ($existing) {
            self::touch((int) $existing->id, $reason, $now);
            return;
        }
        try {
            $DB->insert_record('block_feedback_tracker_queue', (object) [
                'courseid' => $courseid,
                'groupid' => $groupid,
                'reason' => $reason,
                'timeenqueued' => $now,
            ]);
        } catch (\dml_write_exception $e) {
            /* The read above and this insert are not atomic, and enqueue() is
             * the LAST statement of every ledger write path — so a concurrent
             * writer for the same tuple turns `uq_course_group` into an
             * exception thrown AFTER the ledger row has already been committed.
             * Left to escape, it fails the surrounding adhoc task, burns one of
             * its twelve attempts, and on Moodle 4.5 an attempts-exhausted row
             * still matches the dedup probe — so that exact payload stays
             * blocked for as long as `task_adhoc_failed_retention` keeps it.
             * The row the other writer inserted is the row we wanted; adopt it.
             *
             * Inside a transaction the connection is already aborted and
             * nothing can be read, so the exception has to travel. */
            if ($DB->is_transaction_started()) {
                throw $e;
            }
            $row = $DB->get_record('block_feedback_tracker_queue', $key, 'id', IGNORE_MISSING);
            if (!$row) {
                throw $e;
            }
            self::touch((int) $row->id, $reason, $now);
        }
    }

    /**
     * Refresh an existing queue row's reason and enqueue time.
     *
     * @param int $id Queue row id.
     * @param string $reason One of self::REASON_*.
     * @param int $now Epoch seconds to stamp.
     * @return void
     */
    private static function touch(int $id, string $reason, int $now): void {
        global $DB;
        $DB->update_record('block_feedback_tracker_queue', (object) [
            'id' => $id,
            'reason' => $reason,
            'timeenqueued' => $now,
        ]);
    }

    /**
     * Read up to $batchsize queued tuples in FIFO order. Does not remove them;
     * callers should remove() after successful processing.
     *
     * @param int $batchsize
     * @return array<int, \stdClass>
     */
    public static function pop_batch(int $batchsize): array {
        global $DB;
        return $DB->get_records(
            'block_feedback_tracker_queue',
            null,
            'timeenqueued ASC, id ASC',
            '*',
            0,
            $batchsize
        );
    }

    /**
     * Remove a queue row by id.
     *
     * @param int $id
     * @return void
     */
    public static function remove(int $id): void {
        global $DB;
        $DB->delete_records('block_feedback_tracker_queue', ['id' => $id]);
    }

    /**
     * Total pending queue length.
     *
     * @return int
     */
    public static function size(): int {
        global $DB;
        return (int) $DB->count_records('block_feedback_tracker_queue');
    }
}
