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
 * Audit-log writer for recompute events.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\audit;

/**
 * Wraps {block_feedback_tracker_log}. Used to record bulk recomputes, large
 * calendar edits, and admin resets so they can be explained later.
 *
 * Rows are pruned daily after 90 days by the `prune_audit_log` task.
 */
class recompute_log {
    /** Reason: admin reset. */
    public const REASON_MANUAL_RESET = 'manual_reset';
    /** Reason: a calendar day was saved. */
    public const REASON_CALENDAR_SAVE = 'calendar_save';
    /** Reason: business hours saved. */
    public const REASON_BUSINESS_HOURS_SAVE = 'business_hours_save';
    /** Reason: a pause window was saved. */
    public const REASON_PAUSE_SAVE = 'pause_save';
    /** Reason: a CSV bulk import was processed. */
    public const REASON_BULK_IMPORT = 'bulk_import';
    /** Reason: daily pending recompute pass. */
    public const REASON_DAILY_PENDING = 'daily_pending';
    /** Reason: drain task ran. */
    public const REASON_DRAIN = 'drain';
    /** Reason: deferred per-row effectivedays backfill batch. */
    public const REASON_BACKFILL_DAYS = 'backfill_days';

    /**
     * A course's history was discarded because the block was removed and the
     * grace period expired. Moodle triggers no event when a block is deleted,
     * so this row is the only record that the deletion ever happened.
     */
    public const REASON_BLOCK_REMOVED = 'block_removed';

    /**
     * The block was removed from a batch of courses by the bulk tool. Core
     * records nothing for a block deletion, so this is the only trace a mass
     * removal leaves anywhere.
     */
    public const REASON_BULK_REMOVAL = 'bulk_removal';

    /**
     * Insert one audit row.
     *
     * @param string $reason One of self::REASON_*.
     * @param int $affectedrows Rows touched.
     * @param int|null $triggeredby User id, or null for cron / system.
     * @param array|null $details Free-form structured payload (json-encoded).
     * @param int|null $timestarted Defaults to time().
     * @param int|null $timefinished Defaults to time().
     * @return int New row id.
     */
    public static function record(
        string $reason,
        int $affectedrows,
        ?int $triggeredby = null,
        ?array $details = null,
        ?int $timestarted = null,
        ?int $timefinished = null
    ): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('block_feedback_tracker_log', (object) [
            'triggeredby'  => $triggeredby,
            'reason'       => $reason,
            'affectedrows' => $affectedrows,
            'details'      => $details !== null ? json_encode($details) : null,
            'timestarted'  => $timestarted ?? $now,
            'timefinished' => $timefinished ?? $now,
        ]);
    }

    /**
     * Delete rows older than a cutoff timestamp; returns the count removed.
     *
     * @param int $cutoffts
     * @return int
     */
    public static function prune_older_than(int $cutoffts): int {
        global $DB;
        $count = (int) $DB->count_records_select(
            'block_feedback_tracker_log',
            'timestarted < :ts',
            ['ts' => $cutoffts]
        );
        $DB->delete_records_select(
            'block_feedback_tracker_log',
            'timestarted < :ts',
            ['ts' => $cutoffts]
        );
        return $count;
    }
}
