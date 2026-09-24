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
 * Scheduled task: prune long-expired pause windows and very old cday rows.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\process_memos;

/**
 * Daily 04:00 cleanup:
 *  - drop {block_feedback_tracker_cpause} rows whose `timeend` is more than
 *    `purge_inactive_after_days` ago (default 730 days),
 *  - drop {block_feedback_tracker_cday} rows whose `daydate` is older than
 *    the same horizon.
 *
 * Active and open-ended pauses are never deleted (timeend IS NULL is skipped).
 */
class purge_calendar_cache extends \core\task\scheduled_task {
    /** Default retention in days for closed pauses + historic cday rows. */
    public const DEFAULT_RETENTION_DAYS = 730;

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_purge_calendar_cache', 'block_feedback_tracker');
    }

    /**
     * Run the prune.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;
        process_memos::reset();
        $days = (int) (get_config('block_feedback_tracker', 'purge_inactive_after_days') ?: self::DEFAULT_RETENTION_DAYS);
        if ($days <= 0) {
            $days = self::DEFAULT_RETENTION_DAYS;
        }
        $cutoffts = time() - $days * 86400;

        $DB->delete_records_select(
            'block_feedback_tracker_cpause',
            'timeend IS NOT NULL AND timeend < :cutoff',
            ['cutoff' => $cutoffts]
        );

        $cutoffymd = (int) (new \DateTimeImmutable('@' . $cutoffts))->format('Ymd');
        $DB->delete_records_select(
            'block_feedback_tracker_cday',
            'daydate < :cutoff',
            ['cutoff' => $cutoffymd]
        );
    }
}
