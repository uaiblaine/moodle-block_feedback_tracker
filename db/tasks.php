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
 * Scheduled task registrations.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'block_feedback_tracker\task\drain_queue',
        'blocking'  => 0,
        'minute'    => '*/5',
        'hour'      => '*',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => 'block_feedback_tracker\task\recompute_pending',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '*',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => 'block_feedback_tracker\task\recompute_trend',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '3',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => 'block_feedback_tracker\task\recompute_site_stats',
        'blocking'  => 0,
        'minute'    => '30',
        'hour'      => '3',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => 'block_feedback_tracker\task\purge_calendar_cache',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '4',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => 'block_feedback_tracker\task\backfill_history',
        'blocking'  => 0,
        'minute'    => '*/5',
        'hour'      => '*',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => 'block_feedback_tracker\task\backfill_effectivedays',
        'blocking'  => 0,
        'minute'    => '*/5',
        'hour'      => '*',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    /* Repairs the mutations mod_assign performs with no usable event:
     * add_attempt, blind marking, non-latest grading, gradebook overrides,
     * course reset, unenrolment and due-date changes. Runs off-peak-ish but
     * often enough that a stale pending item is measured in hours, not days. */
    [
        'classname' => 'block_feedback_tracker\task\reconcile_ledger',
        'blocking'  => 0,
        'minute'    => '15',
        'hour'      => '*/2',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => 'block_feedback_tracker\task\prune_audit_log',
        'blocking'  => 0,
        'minute'    => '30',
        'hour'      => '4',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
];
