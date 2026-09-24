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
 * Admin-only smoke-test page: mounts a sample of the shared Preact components
 * into two roots to check that the vendored bundle, the AMD shim and the
 * components work end to end after a build.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

require_login();
if (!is_siteadmin()) {
    throw new \moodle_exception('nopermissions', 'error', '', 'block_feedback_tracker spike');
}

$PAGE->set_url('/blocks/feedback_tracker/pages/spike_react.php');
$PAGE->set_context(\context_system::instance());
$PAGE->set_title(get_string('spike_react_title', 'block_feedback_tracker'));
$PAGE->set_heading(get_string('spike_react_title', 'block_feedback_tracker'));
$PAGE->set_pagelayout('admin');

// Load the vendored Preact + htm bundle into <head> before any AMD module
// resolves, so window.bftPreact is set before lib/preact.js evaluates.
$PAGE->requires->js(
    new \moodle_url('/blocks/feedback_tracker/js/vendor/bft-vendor-10.29.2-3.1.1.min.js'),
    true
);
$PAGE->requires->js_call_amd('block_feedback_tracker/spike_react', 'init');

echo $OUTPUT->header();
// Two mount points exercise the multi-root querySelectorAll path that the
// block, report and dashboard entrypoints rely on.
echo '<div data-bft-spike-root></div>';
echo '<hr aria-hidden="true">';
echo '<div data-bft-spike-root></div>';
echo $OUTPUT->footer();
