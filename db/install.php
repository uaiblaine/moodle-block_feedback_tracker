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
 * Post-install hook for Feedback Flow
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Seeds the default platform academic calendar and configuration after the
 * plugin's tables have been created from install.xml.
 *
 * Seeds:
 *  - calver = 1 (monotonic version that keys the calendar and payload caches).
 *  - Mon-Fri 08:00-18:00 business hours in {block_feedback_tracker_chours}.
 *  - Default calendar-behaviour flags (excludeweekends, excludeholidays, ...).
 *  - Default score-formula weights (sum 1.0) and SLA thresholds.
 *
 * Idempotent: a config value is written only when unset, and the
 * business-hours rows only when none exist.
 */
function xmldb_block_feedback_tracker_install() {
    global $DB;

    $now = time();

    $defaults = [
        // Calendar version: bumped by calendar::bump_version() on calendar saves,
        // rule-changing setting saves and a data reset.
        'calver'                     => '1',

        // Calendar behaviour.
        'timezone'                   => 'server',
        'excludeweekends'            => '1',
        'weekendmask'                => '96',
        'excludeholidays'            => '1',
        'excluderecesses'            => '1',
        'enablebusinesshours'        => '1',
        'grading_during_pause_mode'  => 'clipped',

        // Score formula weights (sum 1.0).
        'weight_compliance'          => '0.40',
        'weight_median'              => '0.25',
        'weight_critical'            => '0.15',
        'weight_pending'             => '0.10',
        'weight_trend'               => '0.10',

        // SLA goal and bucket thresholds.
        'sla_goal_hours'             => '24',
        'sla_goal_days'              => '2',
        'bucket_thresholds_eff'      => '24,48,120',

        // Score-band thresholds: CSV of three cutoffs (excellent / good /
        // regular). Same default as settings.php, parse_thresholds_band() in
        // responsiveness_calculator and DEFAULT_SCORE_THRESHOLDS in amd/src/lib/bands.js.
        'score_thresholds_band'      => '90,70,40',

        // Processing scope. Default off: hidden courses are skipped so a
        // fresh install does not start tracking archived terms.
        'process_hidden_courses'     => '0',

        // Backfill master switch. Default off: install must not scan
        // {assign_submission} on a large site without the admin's consent.
        'backfill_active'            => '0',

        // Performance. Pending recompute batch size — hourly pass that
        // re-buckets stale pending submissions.
        'pending_batch_size'         => '1000',

        // Backfill adhoc-task batch size — how many submissions one
        // backfill_one_submission adhoc task processes. Smaller = more
        // cluster parallelism, larger = less queue pressure.
        'backfill_sub_chunk'         => '50',

        // Per-course slice within a single backfill tick. The dispatcher
        // walks each active cursor row, taking up to this many rows from
        // that course before moving on.
        'backfill_chunk_per_course'  => '1000',
    ];
    foreach ($defaults as $name => $value) {
        $v = get_config('block_feedback_tracker', $name);
        if ($v === null || $v === false) {
            set_config($name, $value, 'block_feedback_tracker');
        }
    }

    // Seed Mon-Fri 08:00-18:00 business hours if none exist.
    if (!$DB->record_exists('block_feedback_tracker_chours', [])) {
        $rows = [];
        for ($dayofweek = 0; $dayofweek <= 4; $dayofweek++) {
            $rows[] = (object) [
                'dayofweek'    => $dayofweek,
                'starttime'    => 8 * 60,
                'endtime'      => 18 * 60,
                'enabled'      => 1,
                'usermodified' => null,
                'timecreated'  => $now,
                'timemodified' => $now,
            ];
        }
        $DB->insert_records('block_feedback_tracker_chours', $rows);
    }
}
