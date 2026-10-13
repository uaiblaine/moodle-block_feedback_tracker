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
 * Web service function and service-grouping definitions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_feedback_tracker_get_responsiveness' => [
        'classname'   => 'block_feedback_tracker\external\get_responsiveness',
        'description' => 'Course-scoped responsiveness payload for the block / report.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
        'capabilities' => 'block/feedback_tracker:viewresponsiveness',
    ],
    'block_feedback_tracker_get_pending_submissions' => [
        'classname'   => 'block_feedback_tracker\external\get_pending_submissions',
        'description' => 'Paginated drilldown of pending submissions.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
        'capabilities' => 'block/feedback_tracker:viewresponsiveness',
    ],
    'block_feedback_tracker_get_graded_submissions' => [
        'classname'   => 'block_feedback_tracker\external\get_graded_submissions',
        'description' => 'Paginated list of already-graded submissions.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
        'capabilities' => 'block/feedback_tracker:viewresponsiveness',
    ],
    'block_feedback_tracker_get_report_scopes' => [
        'classname'   => 'block_feedback_tracker\external\get_report_scopes',
        'description' => 'Per-group hero scopes + class-filter list for the report page (rollup-only read).',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
        'capabilities' => 'block/feedback_tracker:viewresponsiveness',
    ],
    'block_feedback_tracker_get_academic_days' => [
        'classname'   => 'block_feedback_tracker\external\get_academic_days',
        'description' => 'Last 30 academic days heatmap series for the report page.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
        'capabilities' => 'block/feedback_tracker:viewresponsiveness',
    ],
    'block_feedback_tracker_get_pause_timeline' => [
        'classname'   => 'block_feedback_tracker\external\get_pause_timeline',
        'description' => 'Per-submission pause timeline.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
        'capabilities' => 'block/feedback_tracker:viewresponsiveness',
    ],
    'block_feedback_tracker_get_calendar' => [
        'classname'   => 'block_feedback_tracker\external\get_calendar',
        'description' => 'Read the platform academic calendar for a date range.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
        'capabilities' => 'block/feedback_tracker:managecalendar',
    ],
    'block_feedback_tracker_save_calendar_day' => [
        'classname'   => 'block_feedback_tracker\external\save_calendar_day',
        'description' => 'Upsert one calendar-day row.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'block/feedback_tracker:managecalendar',
    ],
    'block_feedback_tracker_bulk_import_calendar' => [
        'classname'   => 'block_feedback_tracker\external\bulk_import_calendar',
        'description' => 'Bulk-import calendar days from CSV.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'block/feedback_tracker:managecalendar',
    ],
    'block_feedback_tracker_save_business_hours' => [
        'classname'   => 'block_feedback_tracker\external\save_business_hours',
        'description' => 'Replace business-hours slots for one dayofweek.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'block/feedback_tracker:managecalendar',
    ],
    'block_feedback_tracker_save_pause_window' => [
        'classname'   => 'block_feedback_tracker\external\save_pause_window',
        'description' => 'Create or update a manual pause window.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'block/feedback_tracker:managepausewindows',
    ],
    'block_feedback_tracker_delete_pause_window' => [
        'classname'   => 'block_feedback_tracker\external\delete_pause_window',
        'description' => 'Delete a manual pause window.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'block/feedback_tracker:managepausewindows',
    ],
    'block_feedback_tracker_get_dashboard' => [
        'classname'   => 'block_feedback_tracker\external\get_dashboard',
        'description' => 'Site-level dashboard summary.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
        'capabilities' => 'block/feedback_tracker:viewdashboard',
    ],
    'block_feedback_tracker_get_school_comparison' => [
        'classname'   => 'block_feedback_tracker\external\get_school_comparison',
        'description' => 'Site-wide median + percentile benchmarks for the last N days.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
        'capabilities' => 'block/feedback_tracker:viewschoolcomparison',
    ],
    'block_feedback_tracker_get_grader_priority_list' => [
        'classname'   => 'block_feedback_tracker\external\get_grader_priority_list',
        'description' => 'Top-N most-urgent pending submissions across all courses the caller can view.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
        'capabilities' => 'block/feedback_tracker:viewdashboard',
    ],
    'block_feedback_tracker_get_insights' => [
        'classname'   => 'block_feedback_tracker\external\get_insights',
        'description' => 'Dashboard insights: bright spot, most improved, gentle watch.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
        'capabilities' => 'block/feedback_tracker:viewdashboard',
    ],
    'block_feedback_tracker_get_audit_log' => [
        'classname'   => 'block_feedback_tracker\external\get_audit_log',
        'description' => 'Paginated recompute-audit log entries.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
        'capabilities' => 'block/feedback_tracker:viewaudit',
    ],
];
