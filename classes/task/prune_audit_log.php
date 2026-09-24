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
 * Scheduled task: prune the recompute audit log.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\audit\recompute_log;
use block_feedback_tracker\local\sla\process_memos;

/**
 * Daily 04:30 — drops {block_feedback_tracker_log} rows older than 90 days.
 */
class prune_audit_log extends \core\task\scheduled_task {
    /** Default retention in days. */
    public const DEFAULT_RETENTION_DAYS = 90;

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_prune_audit_log', 'block_feedback_tracker');
    }

    /**
     * Run the prune.
     *
     * @return void
     */
    public function execute(): void {
        process_memos::reset();
        $cutoff = time() - self::DEFAULT_RETENTION_DAYS * 86400;
        recompute_log::prune_older_than($cutoff);
    }
}
