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

use block_feedback_tracker\local\output\bulk_remove_rows;
use block_feedback_tracker\local\sla\bulk_removal_queue;
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

/* The filter travels as f_* parameters: in the removal POST, so the selection
 * can be checked against it on the server, and in the redirect after queuing,
 * so the next batch is listed at once. */
$filters = [
    'endedbefore' => optional_param('f_endedbefore', 0, PARAM_INT),
    'startedbefore' => optional_param('f_startedbefore', 0, PARAM_INT),
    'noenddate' => optional_param('f_noenddate', 0, PARAM_INT),
    'categoryid' => optional_param('f_categoryid', 0, PARAM_INT),
    'hiddenonly' => optional_param('f_hiddenonly', 0, PARAM_INT),
];
$filtered = optional_param('show', 0, PARAM_INT) === 1;

if (optional_param('confirmremoval', 0, PARAM_INT) && confirm_sesskey()) {
    /* A selection is never trusted from the browser alone: enqueue() checks
     * every id against the filter again, so a hand-edited form cannot reach a
     * course the filter never offered, nor queue a course twice. */
    $selected = array_values(array_unique(array_map('intval', optional_param_array('courseids', [], PARAM_INT))));
    $typed = optional_param('confirmcount', -1, PARAM_INT);
    $filtered = true;

    if (empty($selected)) {
        \core\notification::error(get_string('bulk_error_noselection', 'block_feedback_tracker'));
    } else if (count($selected) > course_finder::MAX_RESULTS) {
        \core\notification::error(get_string('bulk_error_toomany', 'block_feedback_tracker', course_finder::MAX_RESULTS));
    } else if ($typed !== count($selected)) {
        /* The typed number must match what is actually ticked. A fixed word
         * becomes muscle memory; a count cannot be typed without reading it,
         * and it goes stale the moment the selection changes. */
        \core\notification::error(get_string('bulk_error_confirmcount', 'block_feedback_tracker'));
    } else {
        $result = bulk_removal_queue::enqueue(
            $filters,
            $selected,
            (bool) optional_param('discardnow', 0, PARAM_INT),
            (int) $USER->id
        );
        if ($result['queued'] === 0) {
            \core\notification::error(get_string('bulk_error_nothingqueued', 'block_feedback_tracker'));
        } else {
            \core\notification::success(get_string('bulk_queued', 'block_feedback_tracker', $result['queued']));
            if ($result['skipped'] > 0) {
                \core\notification::info(get_string('bulk_skipped', 'block_feedback_tracker', $result['skipped']));
            }
            $next = new moodle_url($pageurl, ['show' => 1]);
            foreach ($filters as $key => $value) {
                $next->param('f_' . $key, $value);
            }
            redirect($next);
        }
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
} else if ($filtered) {
    $form->set_data($filters);
}
$filtered = $filtered && course_finder::has_filter($filters);

$rows = $filtered ? course_finder::candidates($filters) : [];
$total = $filtered ? course_finder::count_candidates($filters) : 0;

// Names are filtered but not escaped: the template's double stashes escape them.
$listrows = bulk_remove_rows::for_template($rows);
$queued = bulk_remove_rows::queued_for_template(bulk_removal_queue::course_ids());
$recent = [];
foreach (bulk_removal_queue::recent() as $run) {
    $run['time'] = userdate($run['time'], get_string('strftimedatetimeshort', 'langconfig'));
    if ($run['discarded'] > 0) {
        $recent[] = get_string('bulk_recent_item_discard', 'block_feedback_tracker', (object) $run);
    } else {
        $recent[] = get_string('bulk_recent_item', 'block_feedback_tracker', (object) $run);
    }
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
    'hasqueue' => !empty($queued),
    'queuecount' => count($queued),
    'queued' => $queued,
    'hasrecent' => !empty($recent),
    'recent' => $recent,
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
        'colselect' => get_string('bulk_col_select', 'block_feedback_tracker'),
        'colcourse' => get_string('bulk_col_course', 'block_feedback_tracker'),
        'colcategory' => get_string('bulk_col_category', 'block_feedback_tracker'),
        'colenddate' => get_string('bulk_col_enddate', 'block_feedback_tracker'),
        'colrows' => get_string('bulk_col_rows', 'block_feedback_tracker'),
        'hiddenlabel' => get_string('bulk_hidden', 'block_feedback_tracker'),
        'loadmore' => get_string('bulk_loadmore', 'block_feedback_tracker'),
        'showingprefix' => get_string('bulk_showing_prefix', 'block_feedback_tracker'),
        'showingmiddle' => get_string('bulk_showing_middle', 'block_feedback_tracker'),
        'truncated' => get_string('bulk_batch', 'block_feedback_tracker', [
            'max' => course_finder::MAX_RESULTS,
            'shown' => count($listrows),
            'total' => $total,
        ]),
        'queueheading' => get_string('bulk_queue_heading', 'block_feedback_tracker'),
        'queueintro' => get_string('bulk_queue_intro', 'block_feedback_tracker', count($queued)),
        'queueshow' => get_string('bulk_queue_show', 'block_feedback_tracker'),
        'recentheading' => get_string('bulk_recent_heading', 'block_feedback_tracker'),
        'selectall' => get_string('bulk_selectall', 'block_feedback_tracker', count($listrows)),
        'discardnow' => get_string('bulk_discardnow', 'block_feedback_tracker'),
        'discardnowhelp' => get_string('bulk_discardnow_help', 'block_feedback_tracker'),
        'confirmlabel' => get_string('bulk_confirmlabel', 'block_feedback_tracker'),
        'confirmhelp' => get_string('bulk_confirmhelp', 'block_feedback_tracker'),
        'submit' => get_string('bulk_submit', 'block_feedback_tracker'),
    ],
]);
echo $OUTPUT->footer();
