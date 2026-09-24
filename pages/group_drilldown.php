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
 * Pending submissions drilldown for one (course, group).
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);
$bucket = optional_param('bucket', '', PARAM_ALPHA);
$sortmode = optional_param('sort', 'longestwait', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 25;

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($courseid);
require_capability('block/feedback_tracker:viewresponsiveness', $context);

$PAGE->set_url('/blocks/feedback_tracker/pages/group_drilldown.php', [
    'courseid' => $courseid,
    'groupid' => $groupid,
    'bucket' => $bucket,
    'sort' => $sortmode,
    'page' => $page,
]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('drilldown_title', 'block_feedback_tracker'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

$result = \block_feedback_tracker\external\get_pending_submissions::execute(
    $courseid,
    $groupid,
    $bucket,
    $sortmode,
    $page,
    $perpage
);

// Both wait columns honour the global display-unit setting: business days
// show the date-based elapsed-day counts returned per row by the web service;
// hours keep the effective/wall-clock hour figures.
$unit = (string) (get_config('block_feedback_tracker', 'display_time_unit') ?: 'hours');
$usedays = $unit === 'business_days';

$rows = [];
foreach ($result['submissions'] as $s) {
    $rows[] = [
        'student'      => (string) $s['studentname'],
        'activity'     => (string) $s['activityname'],
        'group'        => (string) ($s['groupname'] ?: '-'),
        'submitted'    => userdate((int) $s['timesubmitted']),
        'submittedts'  => (int) $s['timesubmitted'],
        'waiting'      => $usedays
            ? get_string('drilldown_value_days', 'block_feedback_tracker', (int) $s['perceived_days'])
            : get_string('drilldown_value_hours', 'block_feedback_tracker', format_float((float) $s['waitinghours'], 1)),
        'waitingnum'   => (float) $s['waitinghours'],
        'effective'    => $usedays
            ? get_string('drilldown_value_days', 'block_feedback_tracker', (int) $s['effective_days'])
            : get_string('drilldown_value_hours', 'block_feedback_tracker', format_float((float) $s['effectivehours'], 1)),
        'effectivenum' => (float) $s['effectivehours'],
        'status'       => (string) $s['slabucket'],
        'bucket'       => (string) $s['slabucket'],
    ];
}

// Drafts (saved but not submitted) are surfaced separately and de-emphasised
// so the teacher can decide whether to grade them. They never count toward the
// SLA, so they bypass the bucket filter and the main paging bar — always the
// first page, most-recently-saved first.
$draftresult = \block_feedback_tracker\external\get_pending_submissions::execute(
    $courseid,
    $groupid,
    '',
    'recent',
    0,
    $perpage,
    \block_feedback_tracker\local\sla\submission_status::DRAFT
);
$draftrows = [];
foreach ($draftresult['submissions'] as $s) {
    $draftrows[] = [
        'student'     => (string) $s['studentname'],
        'activity'    => (string) $s['activityname'],
        'group'       => (string) ($s['groupname'] ?: '-'),
        'lastsaved'   => userdate((int) $s['timesubmitted']),
        'lastsavedts' => (int) $s['timesubmitted'],
    ];
}

$PAGE->requires->js_call_amd('block_feedback_tracker/pending_table', 'init');

// Log this page view to the standard site log, once per navigation. The web
// services the page uses do not log.
$event = \block_feedback_tracker\event\report_viewed::create([
    'context' => $context,
    'courseid' => (int) $courseid,
    'other' => ['report' => 'drilldown', 'groupid' => (int) $groupid],
]);
$event->trigger();

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('block_feedback_tracker/drilldown', [
    'heading'   => get_string('drilldown_title', 'block_feedback_tracker'),
    'empty'     => empty($rows),
    'emptytext' => get_string('drilldown_empty', 'block_feedback_tracker'),
    'cols' => [
        'student'   => get_string('drilldown_col_student', 'block_feedback_tracker'),
        'activity'  => get_string('drilldown_col_activity', 'block_feedback_tracker'),
        'group'     => get_string('drilldown_col_group', 'block_feedback_tracker'),
        'submitted' => get_string('drilldown_col_submitted', 'block_feedback_tracker'),
        'waiting'   => get_string('drilldown_col_waiting', 'block_feedback_tracker'),
        'effective' => get_string('drilldown_col_effective', 'block_feedback_tracker'),
        'status'    => get_string('drilldown_col_status', 'block_feedback_tracker'),
    ],
    'rows'      => $rows,
    'pagingbar' => $OUTPUT->paging_bar($result['total'], $page, $perpage, $PAGE->url),
    'hasdrafts'    => !empty($draftrows),
    'draftheading' => get_string('drilldown_drafts_heading', 'block_feedback_tracker'),
    'draftnote'    => get_string('drilldown_drafts_note', 'block_feedback_tracker'),
    'draftbadge'   => get_string('status_draft', 'block_feedback_tracker'),
    'draftcols' => [
        'student'   => get_string('drilldown_col_student', 'block_feedback_tracker'),
        'activity'  => get_string('drilldown_col_activity', 'block_feedback_tracker'),
        'group'     => get_string('drilldown_col_group', 'block_feedback_tracker'),
        'lastsaved' => get_string('drilldown_col_lastsaved', 'block_feedback_tracker'),
        'status'    => get_string('drilldown_col_status', 'block_feedback_tracker'),
    ],
    'draftrows' => $draftrows,
]);
echo $OUTPUT->footer();
