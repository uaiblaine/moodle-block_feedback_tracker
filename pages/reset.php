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
 * Full data-reset confirmation + execution page.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/blocks/feedback_tracker/lib.php');

require_login();
$context = context_system::instance();
require_capability('block/feedback_tracker:resetdata', $context);

$PAGE->set_url('/blocks/feedback_tracker/pages/reset.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('reset_title', 'block_feedback_tracker'));
$PAGE->set_heading(get_string('reset_title', 'block_feedback_tracker'));
$PAGE->set_pagelayout('admin');

$form = new \block_feedback_tracker\form\reset_form($PAGE->url);
$done = false;
$counts = [];

/* Cancel and continue both land on the plugin's admin settings page: its
 * "Tools" heading is the only navigable index of these pages. */
if ($form->is_cancelled()) {
    redirect(new moodle_url('/admin/settings.php', ['section' => 'blocksettingfeedback_tracker']));
} else if ($data = $form->get_data()) {
    $counts = block_feedback_tracker_reset_data(!empty($data->backfill));
    $done = true;
}

$countrows = [];
foreach ($counts as $name => $count) {
    $countrows[] = ['table' => $name, 'count' => (int) $count];
}

// Log this admin page view to the standard site log; user, IP and origin
// are captured automatically. Fired once per render, after any POST redirect.
$event = \block_feedback_tracker\event\tool_page_viewed::create([
    'context' => $context,
    'other' => ['page' => 'reset'],
]);
$event->trigger();

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('block_feedback_tracker/reset', [
    'heading'       => get_string('reset_title', 'block_feedback_tracker'),
    'done'          => $done,
    'doneheading'   => get_string('reset_done', 'block_feedback_tracker'),
    'warningtext'   => get_string('reset_warning', 'block_feedback_tracker'),
    'form'          => $done ? '' : $form->render(),
    'tablecols' => [
        'table' => get_string('reset_table', 'block_feedback_tracker'),
        'count' => get_string('reset_count', 'block_feedback_tracker'),
    ],
    'counts'        => $countrows,
    'continueurl'   => (new moodle_url(
        '/admin/settings.php',
        ['section' => 'blocksettingfeedback_tracker']
    ))->out(false),
    'continuelabel' => get_string('continue', 'core'),
]);
echo $OUTPUT->footer();
