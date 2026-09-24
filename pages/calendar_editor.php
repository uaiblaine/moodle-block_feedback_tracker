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
 * Platform academic calendar editor.
 *
 * Three sections (days / weekly business hours / pause windows). Forms are
 * Moodle moodleform classes under classes/form/; the page renders via
 * the templates/calendar_editor.mustache template. All writes route through
 * the external function classes so the cal_*_updated events fire.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use block_feedback_tracker\form\business_hours_form;
use block_feedback_tracker\form\calendar_editor_forms;

require_login();
$context = context_system::instance();
require_capability('block/feedback_tracker:managecalendar', $context);

$PAGE->set_url('/blocks/feedback_tracker/pages/calendar_editor.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('caleditor_title', 'block_feedback_tracker'));
$PAGE->set_heading(get_string('caleditor_title', 'block_feedback_tracker'));
$PAGE->set_pagelayout('admin');

// The notice and its error lines are plain text; the template escapes them,
// so an exception message can never inject markup.
$notice = null;
$noticelevel = 'success';
$noticeerrors = [];

// Forms.
$forms = calendar_editor_forms::build($PAGE->url->out(false));
$dayform = $forms['day'];
$bulkform = $forms['bulk'];
$pauseform = $forms['pause'];
$hoursforms = $forms['hours'];

// Dispatch form submissions.
try {
    if ($data = $dayform->get_data()) {
        \block_feedback_tracker\external\save_calendar_day::execute(
            (int) $data->daydate,
            (string) $data->daytype,
            (string) ($data->note ?? ''),
            isset($data->starttime) && $data->starttime !== null ? (int) $data->starttime : null,
            isset($data->endtime) && $data->endtime !== null ? (int) $data->endtime : null
        );
        $notice = get_string('caleditor_day_saved', 'block_feedback_tracker');
        redirect($PAGE->url, $notice, null, \core\notification::SUCCESS);
    } else if ($data = $bulkform->get_data()) {
        $result = \block_feedback_tracker\external\bulk_import_calendar::execute((string) $data->csv);
        $notice = get_string('caleditor_bulk_result', 'block_feedback_tracker', (object) [
            'saved'  => $result['saved'],
            'errors' => count($result['errors']),
        ]);
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                $noticeerrors[] = get_string('caleditor_bulk_error_line', 'block_feedback_tracker', (object) [
                    'line' => (int) $err['line'],
                    'raw' => (string) $err['raw'],
                    'message' => (string) $err['message'],
                ]);
            }
            $noticelevel = 'warning';
        }
    } else if ($data = $pauseform->get_data()) {
        \block_feedback_tracker\external\save_pause_window::execute(
            0,
            (string) $data->scopelevel,
            (int) ($data->scopeid ?? 0),
            (string) ($data->reason ?? 'other'),
            (int) $data->timestart,
            (int) ($data->timeend ?? 0),
            (string) ($data->note ?? '')
        );
        redirect(
            $PAGE->url,
            get_string('caleditor_pause_saved', 'block_feedback_tracker'),
            null,
            \core\notification::SUCCESS
        );
    } else {
        foreach ($hoursforms as $dow => $form) {
            if ($data = $form->get_data()) {
                $slots = [];
                for ($i = 0; $i < business_hours_form::SLOTS_PER_DAY; $i++) {
                    $start = isset($data->{"start_$i"}) && $data->{"start_$i"} !== '' ? (int) $data->{"start_$i"} : null;
                    $end = isset($data->{"end_$i"}) && $data->{"end_$i"} !== '' ? (int) $data->{"end_$i"} : null;
                    if ($start !== null && $end !== null && $end > $start) {
                        $slots[] = ['starttime' => $start, 'endtime' => $end];
                    }
                }
                \block_feedback_tracker\external\save_business_hours::execute((int) $data->dayofweek, $slots);
                redirect(
                    $PAGE->url,
                    get_string('caleditor_hours_saved', 'block_feedback_tracker'),
                    null,
                    \core\notification::SUCCESS
                );
            }
        }
    }
} catch (\Throwable $e) {
    $notice = $e->getMessage();
    $noticelevel = 'danger';
    $noticeerrors = [];
}

// Inline GET-style delete actions (links from the data tables).
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($action !== '' && confirm_sesskey()) {
    try {
        if ($action === 'remove_day') {
            $daydate = required_param('daydate', PARAM_INT);
            \block_feedback_tracker\external\save_calendar_day::execute($daydate, 'remove', '');
            redirect(
                $PAGE->url,
                get_string('caleditor_day_removed', 'block_feedback_tracker'),
                null,
                \core\notification::SUCCESS
            );
        } else if ($action === 'delete_pause') {
            $id = required_param('id', PARAM_INT);
            \block_feedback_tracker\external\delete_pause_window::execute($id);
            redirect(
                $PAGE->url,
                get_string('caleditor_pause_deleted', 'block_feedback_tracker'),
                null,
                \core\notification::SUCCESS
            );
        }
    } catch (\Throwable $e) {
        $notice = $e->getMessage();
        $noticelevel = 'danger';
        $noticeerrors = [];
    }
}

// Data tables.
$days = $DB->get_records(
    'block_feedback_tracker_cday',
    null,
    'daydate ASC',
    'id, daydate, daytype, starttime, endtime, note',
    0,
    100
);
$dayrows = [];
foreach ($days as $d) {
    $delurl = new moodle_url('/blocks/feedback_tracker/pages/calendar_editor.php', [
        'action'  => 'remove_day',
        'daydate' => $d->daydate,
        'sesskey' => sesskey(),
    ]);
    // Localised day type; a sub-day optional row also shows its window,
    // e.g. "Optional · 16:00-18:00".
    $typecell = \block_feedback_tracker\local\calendar\calendar::daytype_label((string) $d->daytype);
    if (
        (string) $d->daytype === \block_feedback_tracker\local\calendar\calendar::DAYTYPE_OPTIONAL
        && $d->starttime !== null
        && $d->endtime !== null
    ) {
        $sh = intdiv((int) $d->starttime, 60);
        $sm = ((int) $d->starttime) % 60;
        $eh = intdiv((int) $d->endtime, 60);
        $em = ((int) $d->endtime) % 60;
        $typecell .= ' · '
            . sprintf('%02d:%02d', $sh, $sm) . '-' . sprintf('%02d:%02d', $eh, $em);
    }
    $dayrows[] = [
        'cells' => [
            (string) $d->daydate,
            $typecell,
            (string) ($d->note ?? ''),
        ],
        'deleteurl'  => $delurl->out(false),
        'deletetext' => get_string('delete', 'core'),
    ];
}

$pauses = $DB->get_records(
    'block_feedback_tracker_cpause',
    null,
    'timestart DESC',
    '*',
    0,
    100
);
$pauserows = [];
foreach ($pauses as $p) {
    $delurl = new moodle_url('/blocks/feedback_tracker/pages/calendar_editor.php', [
        'action'  => 'delete_pause',
        'id'      => $p->id,
        'sesskey' => sesskey(),
    ]);
    $scopelabel = (string) $p->scopelevel;
    if ((int) $p->scopeid > 0) {
        $scopelabel .= ':' . (int) $p->scopeid;
    }
    $pauserows[] = [
        'cells' => [
            $scopelabel,
            (string) $p->reason,
            userdate((int) $p->timestart),
            $p->timeend !== null ? userdate((int) $p->timeend) : '—',
            (string) ($p->note ?? ''),
        ],
        'deleteurl'  => $delurl->out(false),
        'deletetext' => get_string('delete', 'core'),
    ];
}

// Build the hours-section per-day list with each form rendered as a string.
// Weekday names come from a known Monday (5 January 2026): dayofweek 0 is Monday.
$basemonday = make_timestamp(2026, 1, 5, 0, 0, 0);
$hoursdays = [];
for ($dow = 0; $dow <= 6; $dow++) {
    $hoursdays[] = [
        'name' => userdate($basemonday + $dow * 86400, '%A'),
        'form' => $hoursforms[$dow]->render(),
    ];
}

// Log this admin page view to the standard site log. Fired after the POST
// redirects so a form submit is not logged twice.
$event = \block_feedback_tracker\event\tool_page_viewed::create([
    'context' => $context,
    'other' => ['page' => 'calendar'],
]);
$event->trigger();

// Render.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('block_feedback_tracker/calendar_editor', [
    'heading'  => get_string('caleditor_title', 'block_feedback_tracker'),
    'notice'   => $notice !== null ? [
        'text' => $notice,
        'level' => $noticelevel,
        'haserrors' => !empty($noticeerrors),
        'errors' => $noticeerrors,
    ] : null,
    'days' => [
        'heading'      => get_string('caleditor_days_heading', 'block_feedback_tracker'),
        'addheading'   => get_string('caleditor_days_add', 'block_feedback_tracker'),
        'bulkheading'  => get_string('caleditor_bulk_heading', 'block_feedback_tracker'),
        'empty'        => empty($dayrows),
        'emptytext'    => get_string('caleditor_days_empty', 'block_feedback_tracker'),
        'cols' => [
            get_string('caleditor_col_date', 'block_feedback_tracker'),
            get_string('caleditor_col_type', 'block_feedback_tracker'),
            get_string('caleditor_col_note', 'block_feedback_tracker'),
            get_string('caleditor_col_actions', 'block_feedback_tracker'),
        ],
        'rows'         => $dayrows,
        'addform'      => $dayform->render(),
        'bulkform'     => $bulkform->render(),
    ],
    'hours' => [
        'heading' => get_string('caleditor_hours_heading', 'block_feedback_tracker'),
        'days'    => $hoursdays,
    ],
    'pauses' => [
        'heading'    => get_string('caleditor_pauses_heading', 'block_feedback_tracker'),
        'addheading' => get_string('caleditor_pauses_add', 'block_feedback_tracker'),
        'empty'      => empty($pauserows),
        'emptytext'  => get_string('caleditor_pauses_empty', 'block_feedback_tracker'),
        'cols' => [
            get_string('caleditor_col_scope', 'block_feedback_tracker'),
            get_string('caleditor_col_reason', 'block_feedback_tracker'),
            get_string('caleditor_col_from', 'block_feedback_tracker'),
            get_string('caleditor_col_to', 'block_feedback_tracker'),
            get_string('caleditor_col_note', 'block_feedback_tracker'),
            get_string('caleditor_col_actions', 'block_feedback_tracker'),
        ],
        'rows'    => $pauserows,
        'addform' => $pauseform->render(),
    ],
]);
echo $OUTPUT->footer();
