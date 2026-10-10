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
 * Recompute audit log viewer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

require_login();
$context = context_system::instance();
require_capability('block/feedback_tracker:viewaudit', $context);

$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$PAGE->set_url('/blocks/feedback_tracker/pages/audit_log.php', ['page' => $page]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('audit_title', 'block_feedback_tracker'));
$PAGE->set_heading(get_string('audit_title', 'block_feedback_tracker'));
$PAGE->set_pagelayout('admin');

$total = $DB->count_records('block_feedback_tracker_log');
$dbrows = $DB->get_records(
    'block_feedback_tracker_log',
    null,
    'timestarted DESC, id DESC',
    '*',
    $page * $perpage,
    $perpage
);

$rows = [];
foreach ($dbrows as $r) {
    $triggeredby = '-';
    if ($r->triggeredby) {
        // A full user record: fullname() emits a debugging notice when any
        // name field is missing.
        $user = \core_user::get_user((int) $r->triggeredby);
        $triggeredby = $user ? fullname($user) : (string) $r->triggeredby;
    }
    $details = '';
    if ($r->details) {
        $decoded = json_decode($r->details, true);
        if (is_array($decoded)) {
            $parts = [];
            foreach ($decoded as $k => $v) {
                $parts[] = $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v));
            }
            $details = implode(', ', $parts);
        }
    }
    $rows[] = [
        'when'        => userdate((int) $r->timestarted),
        'reason'      => (string) $r->reason,
        'affected'    => (int) $r->affectedrows,
        'triggeredby' => $triggeredby,
        'details'     => $details,
    ];
}

// Log this admin page view to the standard site log, once per render.
$event = \block_feedback_tracker\event\tool_page_viewed::create([
    'context' => $context,
    'other' => ['page' => 'audit'],
]);
$event->trigger();

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('block_feedback_tracker/audit_log', [
    'heading'   => get_string('audit_title', 'block_feedback_tracker'),
    'empty'     => empty($rows),
    'emptytext' => get_string('audit_empty', 'block_feedback_tracker'),
    'cols' => [
        'time'        => get_string('audit_col_time', 'block_feedback_tracker'),
        'reason'      => get_string('audit_col_reason', 'block_feedback_tracker'),
        'affected'    => get_string('audit_col_affected', 'block_feedback_tracker'),
        'triggeredby' => get_string('audit_col_triggeredby', 'block_feedback_tracker'),
        'details'     => get_string('audit_col_details', 'block_feedback_tracker'),
    ],
    'rows'      => $rows,
    'pagingbar' => $OUTPUT->paging_bar($total, $page, $perpage, $PAGE->url),
]);
echo $OUTPUT->footer();
