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
 * Teacher dashboard: Preact-driven cross-course overview.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

require_login();
$sysctx = \context_system::instance();

// Access follows dashboard_scope::visible_course_ids(), which the web services
// share: null (a full-site grant) or a non-empty course list opens the page;
// an empty list is refused, for a site admin too.
global $USER;
$scope = \block_feedback_tracker\local\sla\dashboard_scope::visible_course_ids((int) $USER->id);
if ($scope !== null && empty($scope)) {
    throw new \required_capability_exception(
        $sysctx,
        'block/feedback_tracker:viewdashboard',
        'nopermissions',
        'error'
    );
}

$PAGE->set_url('/blocks/feedback_tracker/pages/teacher_dashboard.php');
$PAGE->set_context($sysctx);
$PAGE->set_title(get_string('dashboard_title', 'block_feedback_tracker'));
$PAGE->set_heading(get_string('dashboard_title', 'block_feedback_tracker'));
$PAGE->set_pagelayout('admin');

// The page ships no data: the app fetches it after mount (fetch order in
// amd/src/views/DashboardView.js). Do not call the dashboard web services
// here: get_insights' momentum pass alone runs ledger queries for every group,
// which would hold back the first byte. Each web service re-applies the
// dashboard_scope gate, so fetching from the client does not widen visibility.
$dashboard = null;
$gradenow = null;
$insights = null;

// Site-scope events for the dashboard subline (most recent named optional
// event). Day-type events are site-wide, so one aggregator pass at courseid 0
// reaches them all. Same 30-day window as the block payload.
$events = [];
try {
    $now = time();
    $aggregate = \block_feedback_tracker\local\calendar\paused_aggregator::for_window(
        0,
        $now - 30 * 86400,
        $now
    );
    $events = $aggregate['events'] ?? [];
} catch (\Throwable $e) {
    debugging('block_feedback_tracker: dashboard events fetch failed: ' . $e->getMessage());
}

// Scheduled-pause notice: up to 3 pauses visible now (from 3 days before to
// the day after), site scope. Failures are tolerated as for the events above.
$upcoming = [];
try {
    $upcoming = \block_feedback_tracker\local\calendar\upcoming_pauses::for_display(0, 0, time());
} catch (\Throwable $e) {
    debugging('block_feedback_tracker: dashboard upcoming-pauses fetch failed: ' . $e->getMessage());
}

// Collapse state of the hero + insights block, a user preference declared in
// block_feedback_tracker_user_preferences(); defaults to expanded.
$dashboardcollapsed = (bool) get_user_preferences(
    'block_feedback_tracker_dashboard_collapsed',
    '0',
    (int) $USER->id
);

$initial = [
    'userid' => (int) $USER->id,
    // Plain text: the view renders the greeting as a text node, which escapes it.
    'greeting_firstname' => format_string($USER->firstname, true, ['context' => $sysctx, 'escape' => false]),
    'dashboard' => $dashboard,
    'gradenow' => $gradenow,
    'insights' => $insights,
    'events' => $events,
    'upcoming' => $upcoming,
    'dashboard_collapsed' => $dashboardcollapsed,
    'i18n' => array_merge(
        \block_feedback_tracker\local\output\bootstrap::i18n_bundle(),
        \block_feedback_tracker\local\output\bootstrap::dashboard_i18n()
    ),
    'config' => \block_feedback_tracker\local\output\bootstrap::config_bundle(),
];

$initialjson = json_encode(
    $initial,
    JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ($initialjson === false) {
    $initialjson = '{}';
}

\block_feedback_tracker\local\output\vendor_bundle::load($PAGE);
$PAGE->requires->js_call_amd('block_feedback_tracker/dashboard_app', 'init');

// Log this page view to the standard site log, once per navigation. The web
// services the page uses do not log.
$event = \block_feedback_tracker\event\report_viewed::create([
    'context' => $sysctx,
    'other' => ['report' => 'dashboard'],
]);
$event->trigger();

echo $OUTPUT->header();
echo '<div data-bft-dashboard-root>';
echo '<script type="application/json" data-bft-init>' . $initialjson . '</script>';
echo '</div>';
echo $OUTPUT->footer();
