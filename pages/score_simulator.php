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
 * Interactive Academic Responsiveness Score simulator — a sandbox for tuning
 * the five score weights and building intuition for how the score behaves.
 * Admin-only by default; a full-site grant (the viewalldata capability, or a
 * site admin with enable_admin_view_all) always has access, and teachers gain
 * access when the 'enable_teacher_simulator' setting is on (same scope rule as
 * the dashboard). Pure client-side; nothing is saved.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

require_login();
$sysctx = \context_system::instance();

// Same visibility rule as the teacher dashboard, gated by the master switch.
global $USER;
$simenabled = (int) (get_config('block_feedback_tracker', 'enable_teacher_simulator') ?: 0) === 1;
if (!is_siteadmin()) {
    $scope = \block_feedback_tracker\local\sla\dashboard_scope::visible_course_ids((int) $USER->id);
    // A null scope (full-site grant) is always allowed; anyone else needs the
    // simulator switch on and a non-empty teaching scope.
    if ($scope !== null && (!$simenabled || empty($scope))) {
        throw new \required_capability_exception(
            $sysctx,
            'block/feedback_tracker:viewdashboard',
            'nopermissions',
            'error'
        );
    }
}

$PAGE->set_url('/blocks/feedback_tracker/pages/score_simulator.php');
$PAGE->set_context($sysctx);
$PAGE->set_title(get_string('sim_page_title', 'block_feedback_tracker'));
$PAGE->set_heading(get_string('sim_page_title', 'block_feedback_tracker'));
$PAGE->set_pagelayout('admin');

$initial = [
    'config' => \block_feedback_tracker\local\output\bootstrap::config_bundle(),
    'i18n'   => \block_feedback_tracker\local\output\bootstrap::simulator_i18n(),
];
$initialjson = json_encode(
    $initial,
    JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ($initialjson === false) {
    $initialjson = '{}';
}

// Load the vendored Preact + htm bundle into <head> before any AMD module
// resolves, so window.bftPreact is set before lib/preact.js evaluates.
$PAGE->requires->js(
    new \moodle_url('/blocks/feedback_tracker/js/vendor/bft-vendor-10.29.2-3.1.1.min.js'),
    true
);
$PAGE->requires->js_call_amd('block_feedback_tracker/simulator_app', 'init');

echo $OUTPUT->header();
echo '<div data-bft-simulator-root>';
echo '<script type="application/json" data-bft-init>' . $initialjson . '</script>';
echo '</div>';
echo $OUTPUT->footer();
