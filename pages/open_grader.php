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
 * Opens mod_assign's grader on one student, first clearing the viewer's saved
 * grader filters when they would hide that student.
 *
 * The dashboards link here only when the viewer may carry such state
 * (local\grading\grader_filters::viewer_has_saved_state() or row_needs_gate());
 * every other grade link goes straight to the grader.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_feedback_tracker\local\grading\grader_filters;

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$cmid = required_param('id', PARAM_INT);
// Named userid on purpose: core's grading table reads this request parameter
// and restricts its check to the one student (grader_filters::student_listed()).
$userid = required_param('userid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
require_login($course, false, $cm);
$context = \context_module::instance($cm->id);
$assign = new \assign($context, $cm, $course);

/* prepare() requires the grader's capability itself. Without a valid sesskey
 * the link still works: it opens the grader as a direct link would and
 * changes nothing. */
$result = grader_filters::prepare($assign, $userid, confirm_sesskey());
if ($result['cleared']) {
    redirect(
        $result['url'],
        get_string('graderfilters_cleared', 'block_feedback_tracker'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}
redirect($result['url']);
