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
 * Reset of every static memo the plugin keeps.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\score\peer_stats;

/**
 * Drops every static memo the plugin keeps, at once.
 *
 * The memos live as long as the PHP process. That is one request on the web,
 * but core's cron keeps its main process alive across many scheduled and adhoc
 * tasks, so a decision memoised by one task (a course's block, a user's group,
 * the grader filter) would otherwise reach every later task in that process,
 * after another process has changed the answer. Every scheduled and adhoc
 * task of the plugin calls {@see self::reset()} before anything else.
 */
final class process_memos {
    /**
     * Drop every memo.
     *
     * @return void
     */
    public static function reset(): void {
        course_access::reset_memo();
        dashboard_scope::reset_memo();
        group_access::reset_memo();
        group_resolver::reset_memo();
        submission_ledger::reset_memos();
        academic_time::reset_memos();
        peer_stats::reset_memo();
    }
}
