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
 * Pending grading · detailed report — React-driven full-page view of pending
 * and graded submissions for one course: a collapsible dashboard-style hero,
 * a last-30-academic-days heatmap, a status distribution with a graded view,
 * and a table with server-side search / sort / paging plus grade actions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);
$bucket = optional_param('bucket', '', PARAM_ALPHA);
$band = optional_param('band', '', PARAM_ALPHA);
$sortmode = optional_param('sort', 'longestwait', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 25;

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$context = \context_course::instance($courseid);
require_capability('block/feedback_tracker:viewresponsiveness', $context);

$PAGE->set_url('/blocks/feedback_tracker/pages/pending_report.php', [
    'courseid' => $courseid,
    'groupid' => $groupid,
    'bucket' => $bucket,
    'band' => $band,
    'sort' => $sortmode,
    'page' => $page,
]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pendingreport_title', 'block_feedback_tracker'));
// The heading combines the report-purpose label and the course name so
// the page is self-describing — and so the Behat scenario can assert
// the visible H1 instead of relying on the browser-tab <title>.
$PAGE->set_heading(
    get_string('pendingreport_title', 'block_feedback_tracker')
        . ' — ' . format_string($course->fullname)
);
$PAGE->set_pagelayout('incourse');

// Data loads asynchronously from the Preact app after mount (see
// amd/src/views/PendingReportView.js), mirroring teacher_dashboard.php: the
// first byte ships immediately and the page never blocks on the submissions
// queries or — previously the dominant cost — the full responsiveness payload
// (per-group trend series, peer stats and activity schedules, plus the course
// paused aggregate and assign catalog) that was assembled here only to feed
// the hero scopes and the class-filter dropdown. The app fetches the
// submissions page first (the content the teacher came for) in parallel with
// the lightweight get_report_scopes web service (one indexed rollup read),
// then lazy-loads drafts and the academic-days strip. Each web service
// re-applies the same capability + group-visibility gates, so moving the
// fetch to the client does not widen visibility.
global $USER;

// Shared block-level labels (band names, card_*, breakdown_*) + page-
// specific overlay (pendingreport_*, modal_*, pause_reason_*). Both live
// in the autoloaded helper so the block class doesn't have to be
// require_once'd from a standalone page.
$i18n = array_merge(
    \block_feedback_tracker\local\output\bootstrap::i18n_bundle(),
    \block_feedback_tracker\local\output\bootstrap::pending_report_i18n()
);
// Draft-section labels (PendingReportView renders these for the muted
// "Not yet submitted" sub-table).
$i18n['drafts_heading'] = get_string('drilldown_drafts_heading', 'block_feedback_tracker');
$i18n['drafts_note'] = get_string('drilldown_drafts_note', 'block_feedback_tracker');
$i18n['drilldown_col_lastsaved'] = get_string('drilldown_col_lastsaved', 'block_feedback_tracker');
$i18n['status_draft'] = get_string('status_draft', 'block_feedback_tracker');
/* Marked-but-unreleased chip. Releasing a marking-workflow grade needs a
 * capability the marker frequently does not hold, so the row states the
 * outstanding step instead of reading as finished. */
$i18n['status_awaiting_release'] = get_string('status_awaiting_release', 'block_feedback_tracker');
$i18n['status_awaiting_release_help'] = get_string('status_awaiting_release_help', 'block_feedback_tracker');
$i18n['alloc_split_tip'] = get_string('alloc_split_tip', 'block_feedback_tracker');

// Collapse state for the hero + academic-days container (per-user pref).
$reportcollapsed = (bool) get_user_preferences(
    'block_feedback_tracker_report_collapsed',
    '0',
    (int) $USER->id
);

// Scheduled-pause notice ("Pausa prevista") — up to 3 pauses visible now
// (3 days before → day after), course scope. Cheap calendar read; ships in
// the bootstrap like the dashboard so the notice paints with the shell.
$upcoming = [];
try {
    $upcoming = \block_feedback_tracker\local\calendar\upcoming_pauses::for_display(
        (int) $courseid,
        0,
        time()
    );
} catch (\Throwable $e) {
    debugging('block_feedback_tracker: report upcoming-pauses fetch failed: ' . $e->getMessage());
}

$initial = [
    'courseid' => (int) $courseid,
    'coursename' => format_string($course->fullname),
    // Filter parameters only — no rows. The view issues the first fetch with
    // these after mount.
    'pending' => [
        'bucket' => $bucket,
        'band' => $band,
        'groupid' => $groupid,
        'sort' => $sortmode,
        'page' => $page,
        'perpage' => $perpage,
    ],
    'drafts' => null,
    'groups' => null,
    'groupscopes' => null,
    'upcoming' => $upcoming,
    'report_collapsed' => $reportcollapsed,
    'i18n' => $i18n,
    'config' => \block_feedback_tracker\local\output\bootstrap::config_bundle(),
];

$initialjson = json_encode(
    $initial,
    JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ($initialjson === false) {
    $initialjson = '{}';
}

$PAGE->requires->js(
    new \moodle_url('/blocks/feedback_tracker/js/vendor/bft-vendor-10.29.2-3.1.1.min.js'),
    true
);
$PAGE->requires->js_call_amd('block_feedback_tracker/pending_report_app', 'init');

// Log this page view to the standard site log; user, IP and origin are
// captured automatically. Fired once per navigation, not in the web services.
$event = \block_feedback_tracker\event\report_viewed::create([
    'context' => $context,
    'courseid' => (int) $courseid,
    'other' => ['report' => 'pending', 'groupid' => (int) $groupid],
]);
$event->trigger();

echo $OUTPUT->header();
echo '<div data-bft-pending-report-root>';
echo '<script type="application/json" data-bft-init>' . $initialjson . '</script>';
echo '</div>';
echo $OUTPUT->footer();
