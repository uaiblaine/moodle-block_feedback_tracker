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
 * Scheduled task: write yesterday's site-wide stats row.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\process_memos;
use block_feedback_tracker\local\sla\site_stats_service;

/**
 * Daily 03:30 — writes one {block_feedback_tracker_site} row aggregating
 * yesterday's gradings across every course/group, powering the school
 * comparison overlay.
 */
class recompute_site_stats extends \core\task\scheduled_task {
    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_recompute_site_stats', 'block_feedback_tracker');
    }

    /**
     * Compute yesterday's site row.
     *
     * @return void
     */
    public function execute(): void {
        process_memos::reset();
        site_stats_service::recompute_yesterday();
    }
}
