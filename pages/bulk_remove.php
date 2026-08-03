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
 * Remove the block from many courses at once.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/blocks/feedback_tracker/lib.php');

use block_feedback_tracker\local\sla\course_finder;

require_login();
$context = context_system::instance();
require_capability('block/feedback_tracker:bulkmanageblocks', $context);

$pageurl = new moodle_url('/blocks/feedback_tracker/pages/bulk_remove.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('bulk_title', 'block_feedback_tracker'));
$PAGE->set_heading(get_string('bulk_title', 'block_feedback_tracker'));
$PAGE->set_pagelayout('admin');

$form = new \block_feedback_tracker\form\bulk_remove_form($pageurl);

$filters = [];
$rows = [];
$total = 0;
$filtered = false;

/* The removal POST carries its own filter values so the list can be rebuilt
 * and re-validated server-side. A selection is never trusted from the browser
 * alone: the ids are re-checked against the candidate query before anything is
 * queued, so a hand-edited form cannot reach a course the filter never
 * offered. */
$confirmed = optional_param('confirmremoval', 0, PARAM_INT);

if ($confirmed && confirm_sesskey()) {
    $filters = [
        'endedbefore' => optional_param('f_endedbefore', 0, PARAM_INT),
        'startedbefore' => optional_param('f_startedbefore', 0, PARAM_INT),
        'noenddate' => optional_param('f_noenddate', 0, PARAM_INT),
        'categoryid' => optional_param('f_categoryid', 0, PARAM_INT),
        'hiddenonly' => optional_param('f_hiddenonly', 0, PARAM_INT),
    ];
    $selected = array_map('intval', optional_param_array('courseids', [], PARAM_INT));
    $typed = optional_param('confirmcount', -1, PARAM_INT);
    $discardnow = optional_param('discardnow', 0, PARAM_INT);

    $offered = course_finder::candidates($filters);
    $selected = array_values(array_intersect($selected, array_map('intval', array_keys($offered))));

    if (empty($selected)) {
        \core\notification::error(get_string('bulk_error_noselection', 'block_feedback_tracker'));
    } else if ($typed !== count($selected)) {
        /* The typed number must match what is actually ticked. A fixed word
         * becomes muscle memory; a count cannot be typed without reading it,
         * and it goes stale the moment the selection changes. */
        \core\notification::error(get_string('bulk_error_confirmcount', 'block_feedback_tracker'));
    } else {
        $task = new \block_feedback_tracker\task\bulk_remove_blocks();
        $task->set_custom_data([
            'courseids' => $selected,
            'discardnow' => $discardnow ? 1 : 0,
            'triggeredby' => (int) $USER->id,
        ]);
        \core\task\manager::queue_adhoc_task($task);

        \core\notification::success(get_string(
            'bulk_queued',
            'block_feedback_tracker',
            count($selected)
        ));
        redirect($pageurl);
    }
}

if ($data = $form->get_data()) {
    $filters = [
        'endedbefore' => (int) ($data->endedbefore ?? 0),
        'startedbefore' => (int) ($data->startedbefore ?? 0),
        'noenddate' => (int) ($data->noenddate ?? 0),
        'categoryid' => (int) ($data->categoryid ?? 0),
        'hiddenonly' => (int) ($data->hiddenonly ?? 0),
    ];
    $filtered = true;
    $rows = course_finder::candidates($filters);
    $total = course_finder::count_candidates($filters);
}

$listrows = [];
$index = 0;
foreach ($rows as $row) {
    $listrows[] = [
        'courseid' => (int) $row->id,
        'fullname' => format_string($row->fullname),
        'shortname' => format_string($row->shortname),
        'categoryname' => format_string($row->categoryname),
        'hidden' => !$row->visible,
        'enddate' => $row->enddate
            ? userdate((int) $row->enddate, get_string('strftimedateshort', 'langconfig'))
            : get_string('bulk_noenddate', 'block_feedback_tracker'),
        'ledgerrows' => \block_feedback_tracker\local\output\numfmt::count((int) $row->ledgerrows),
        // Rows past the first page start collapsed; the control reveals them.
        'collapsed' => $index++ >= course_finder::PAGE_SIZE,
    ];
}

$event = \block_feedback_tracker\event\tool_page_viewed::create([
    'context' => $context,
    'other' => ['page' => 'bulkremove'],
]);
$event->trigger();

$PAGE->requires->js_call_amd('block_feedback_tracker/bulk_remove', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('block_feedback_tracker/bulk_remove', [
    'formhtml' => $form->render(),
    'filtered' => $filtered,
    'hasrows' => !empty($listrows),
    'rows' => $listrows,
    'shown' => min(count($listrows), course_finder::PAGE_SIZE),
    'loaded' => count($listrows),
    'total' => $total,
    'truncated' => $total > count($listrows),
    'pagesize' => course_finder::PAGE_SIZE,
    'maxresults' => course_finder::MAX_RESULTS,
    'actionurl' => $pageurl->out(false),
    'sesskey' => sesskey(),
    'filtervalues' => [
        'endedbefore' => (int) ($filters['endedbefore'] ?? 0),
        'startedbefore' => (int) ($filters['startedbefore'] ?? 0),
        'noenddate' => (int) ($filters['noenddate'] ?? 0),
        'categoryid' => (int) ($filters['categoryid'] ?? 0),
        'hiddenonly' => (int) ($filters['hiddenonly'] ?? 0),
    ],
    'str' => [
        'intro' => get_string('bulk_intro', 'block_feedback_tracker'),
        'empty' => get_string('bulk_empty', 'block_feedback_tracker'),
        'colcourse' => get_string('bulk_col_course', 'block_feedback_tracker'),
        'colcategory' => get_string('bulk_col_category', 'block_feedback_tracker'),
        'colenddate' => get_string('bulk_col_enddate', 'block_feedback_tracker'),
        'colrows' => get_string('bulk_col_rows', 'block_feedback_tracker'),
        'hiddenlabel' => get_string('bulk_hidden', 'block_feedback_tracker'),
        'loadmore' => get_string('bulk_loadmore', 'block_feedback_tracker'),
        'showingprefix' => get_string('bulk_showing_prefix', 'block_feedback_tracker'),
        'showingmiddle' => get_string('bulk_showing_middle', 'block_feedback_tracker'),
        'truncated' => get_string('bulk_truncated', 'block_feedback_tracker', [
            'shown' => count($listrows),
            'total' => $total,
        ]),
        'discardnow' => get_string('bulk_discardnow', 'block_feedback_tracker'),
        'discardnowhelp' => get_string('bulk_discardnow_help', 'block_feedback_tracker'),
        'confirmlabel' => get_string('bulk_confirmlabel', 'block_feedback_tracker'),
        'confirmhelp' => get_string('bulk_confirmhelp', 'block_feedback_tracker'),
        'submit' => get_string('bulk_submit', 'block_feedback_tracker'),
    ],
]);
echo $OUTPUT->footer();
