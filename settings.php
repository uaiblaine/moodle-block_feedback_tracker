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
 * Admin settings for Feedback Flow.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    require_once(__DIR__ . '/lib.php');

    $plugin = 'block_feedback_tracker';

    // Heading: Scoring.
    $settings->add(new admin_setting_heading(
        $plugin . '/scoring',
        get_string('settings_scoring_heading', $plugin),
        get_string('settings_scoring_desc', $plugin)
    ));

    // The five score-formula weights. Saved values may sum to anything; the
    // score calculator normalises at read time via load_weights(). Save-time
    // normalisation is intentionally NOT performed, because it would fire
    // partway through admin_apply_default_settings() (which writes each
    // default one-by-one) and corrupt the values.
    //
    // Paramtype is a regex (admin_setting_configtext recognises /.../ as a
    // regex pattern) rather than PARAM_FLOAT. Reason: PARAM_FLOAT's validator
    // runs clean_param() and compares the result strictly against the input
    // string — so "0.40" gets normalised to (float) 0.4, stringified to
    // "0.4", and the strict comparison "0.40" === "0.4" fails. Admin would
    // see "value is not valid" whenever they typed (or defaulted to) a
    // trailing-zero float. The regex variant stores the entered string
    // verbatim; load_weights() does the (float) cast at read time.
    $weights = [
        'weight_compliance' => '0.40',
        'weight_median'     => '0.25',
        'weight_critical'   => '0.15',
        'weight_pending'    => '0.10',
        'weight_trend'      => '0.10',
    ];
    $weightpattern = '/^[0-9]+(\.[0-9]+)?$/';
    foreach ($weights as $key => $default) {
        $s = new admin_setting_configtext(
            $plugin . '/' . $key,
            get_string('settings_' . $key, $plugin),
            get_string('settings_' . $key . '_desc', $plugin),
            $default,
            $weightpattern
        );
        $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
        $settings->add($s);
    }

    $s = new admin_setting_configtext(
        $plugin . '/sla_goal_hours',
        get_string('settings_sla_goal_hours', $plugin),
        get_string('settings_sla_goal_hours_desc', $plugin),
        '24',
        PARAM_INT
    );
    $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
    $settings->add($s);

    $s = new admin_setting_configtext(
        $plugin . '/bucket_thresholds_eff',
        get_string('settings_bucket_thresholds_eff', $plugin),
        get_string('settings_bucket_thresholds_eff_desc', $plugin),
        '24,48,120',
        PARAM_TEXT
    );
    $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
    $settings->add($s);

    $s = new admin_setting_configtext(
        $plugin . '/bucket_thresholds_raw',
        get_string('settings_bucket_thresholds_raw', $plugin),
        get_string('settings_bucket_thresholds_raw_desc', $plugin),
        '24,48,120',
        PARAM_TEXT
    );
    $settings->add($s);

    // Day-ruler band cutoffs, used instead of the hour thresholds when the
    // display unit (Views section) is business days: excellent <= first,
    // good <= second, regular <= third, critical above. Inclusive bounds —
    // "up to 2 business days" is still excellent. Feeds the rollup's
    // critical_days/overgoal_days twins, hence the invalidate callback.
    $s = new admin_setting_configtext(
        $plugin . '/bucket_thresholds_days',
        get_string('settings_bucket_thresholds_days', $plugin),
        get_string('settings_bucket_thresholds_days_desc', $plugin),
        '2,5,10',
        PARAM_TEXT
    );
    $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
    $settings->add($s);
    $settings->hide_if(
        $plugin . '/bucket_thresholds_days',
        $plugin . '/display_time_unit',
        'neq',
        'business_days'
    );

    // SLA goal in business days — the day-mode twin of sla_goal_hours. It
    // feeds only the display-only compliance_pct_days figure; the score keeps
    // using sla_goal_hours, so switching the display unit never moves the
    // score. Shown only when the display unit is business days, mirroring
    // bucket_thresholds_days.
    $s = new admin_setting_configtext(
        $plugin . '/sla_goal_days',
        get_string('settings_sla_goal_days', $plugin),
        get_string('settings_sla_goal_days_desc', $plugin),
        '2',
        PARAM_INT
    );
    $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
    $settings->add($s);
    $settings->hide_if(
        $plugin . '/sla_goal_days',
        $plugin . '/display_time_unit',
        'neq',
        'business_days'
    );

    // Score-band thresholds: three CSV cutoffs that map a 0-100 score to one
    // of the four bands. Defaults 90/70/40 match the design palette. Stored
    // values are clamped + ordered at read time in
    // responsiveness_calculator::parse_thresholds_band(); admin can type any
    // numeric values and the calculator copes.
    $s = new admin_setting_configtext(
        $plugin . '/score_thresholds_band',
        get_string('settings_score_thresholds_band', $plugin),
        get_string('settings_score_thresholds_band_desc', $plugin),
        '90,70,40',
        PARAM_TEXT
    );
    $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
    $settings->add($s);

    // Score simulator launcher — sits directly under the scoring weights so
    // the admin can open the interactive sandbox and see how the weights
    // behave before committing the values above. Rendered via the shared
    // tools_links template (same pattern as the Tools section below).
    global $OUTPUT;
    $simulatorlink = $OUTPUT->render_from_template('block_feedback_tracker/tools_links', [
        'links' => [
            [
                'url'   => (new moodle_url('/blocks/feedback_tracker/pages/score_simulator.php'))->out(false),
                'label' => get_string('sim_open_button', $plugin),
            ],
        ],
    ]);
    $settings->add(new admin_setting_heading(
        $plugin . '/scoring_simulator',
        get_string('sim_settings_heading', $plugin),
        get_string('sim_settings_desc', $plugin) . $simulatorlink
    ));

    // Heading: Calendar behaviour.
    $settings->add(new admin_setting_heading(
        $plugin . '/calendar_behaviour',
        get_string('settings_calendar_behaviour_heading', $plugin),
        get_string('settings_calendar_behaviour_desc', $plugin)
    ));

    $bools = ['excludeweekends', 'excludeholidays', 'excluderecesses', 'enablebusinesshours'];
    foreach ($bools as $key) {
        $s = new admin_setting_configcheckbox(
            $plugin . '/' . $key,
            get_string('settings_' . $key, $plugin),
            get_string('settings_' . $key . '_desc', $plugin),
            1
        );
        $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
        $settings->add($s);
    }

    $s = new admin_setting_configtext(
        $plugin . '/weekendmask',
        get_string('settings_weekendmask', $plugin),
        get_string('settings_weekendmask_desc', $plugin),
        '96',
        PARAM_INT
    );
    $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
    $settings->add($s);

    $tzoptions = ['server' => get_string('settings_timezone_server', $plugin)];
    foreach (\core_date::get_list_of_timezones() as $tz => $label) {
        $tzoptions[$tz] = $label;
    }
    $s = new admin_setting_configselect(
        $plugin . '/timezone',
        get_string('settings_timezone', $plugin),
        get_string('settings_timezone_desc', $plugin),
        'server',
        $tzoptions
    );
    $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
    $settings->add($s);

    $pausemodes = [
        'clipped' => get_string('settings_pausemode_clipped', $plugin),
        'live'    => get_string('settings_pausemode_live', $plugin),
    ];
    $s = new admin_setting_configselect(
        $plugin . '/grading_during_pause_mode',
        get_string('settings_grading_during_pause_mode', $plugin),
        get_string('settings_grading_during_pause_mode_desc', $plugin),
        'clipped',
        $pausemodes
    );
    $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
    $settings->add($s);

    // Heading: Processing scope.
    $settings->add(new admin_setting_heading(
        $plugin . '/processing_scope',
        get_string('settings_processing_scope_heading', $plugin),
        get_string('settings_processing_scope_desc', $plugin)
    ));

    // Hidden-course processing toggle. No updated-callback: flipping it
    // has no retroactive effect on existing ledger rows / rollups — the
    // new rule applies from the next event onward. Existing data for
    // hidden courses stays in the tables until course_deleted fires or
    // the admin runs the reset tool.
    $settings->add(new admin_setting_configcheckbox(
        $plugin . '/process_hidden_courses',
        get_string('settings_process_hidden_courses', $plugin),
        get_string('settings_process_hidden_courses_desc', $plugin),
        0
    ));

    // Backfill master switch. Off by default so install doesn't
    // immediately scan {assign_submission} on sites with millions of
    // rows. Turn on once the block is on every course you want
    // tracked. The dispatcher reads this flag on each tick — no
    // updated-callback needed.
    $settings->add(new admin_setting_configcheckbox(
        $plugin . '/backfill_active',
        get_string('settings_backfill_active', $plugin),
        get_string('settings_backfill_active_desc', $plugin),
        0
    ));

    // Heading: Performance.
    $settings->add(new admin_setting_heading(
        $plugin . '/performance',
        get_string('settings_performance_heading', $plugin),
        get_string('settings_performance_desc', $plugin)
    ));

    $perf = [
        'recompute_batch_size'      => '200',
        'pending_batch_size'        => '1000',
        'drain_time_cap_seconds'    => '50',
        'backfill_chunk'            => '5000',
        'backfill_chunk_per_course' => '1000',
        'backfill_sub_chunk'        => '50',
        'trend_window_days'         => '30',
        'purge_inactive_after_days' => '730',
        'reconcile_batch_size'      => '500',
        'reconcile_time_cap_seconds' => '50',
        'retention_days'            => '365',
        'retention_batch_size'      => '5000',
    ];
    foreach ($perf as $key => $default) {
        $settings->add(new admin_setting_configtext(
            $plugin . '/' . $key,
            get_string('settings_' . $key, $plugin),
            get_string('settings_' . $key . '_desc', $plugin),
            $default,
            PARAM_INT
        ));
    }

    // Heading: Views.
    $settings->add(new admin_setting_heading(
        $plugin . '/views',
        get_string('settings_views_heading', $plugin),
        ''
    ));

    // Note: 'exclude_grader_submissions' deliberately has no updated-callback.
    // Flipping it has no retroactive effect — existing rows in the ledger
    // are kept as-is. The new behaviour kicks in on the next submission
    // event for an affected user.
    $viewbools = [
        'enable_admin_view_all'       => 0,
        'enable_school_comparison'    => 1,
        'enable_teacher_simulator'    => 0,
        'show_perceived_time'         => 1,
        'show_paused_today_indicator' => 1,
        'show_peer_context'           => 1,
        'exclude_grader_submissions'  => 1,
        'release_stops_clock'         => 0,
        'reconcile_active'            => 1,
        'retention_active'            => 0,
        'removal_cleanup_active'      => 0,
        'removal_grace_follow_recyclebin' => 1,
    ];
    foreach ($viewbools as $key => $default) {
        $settings->add(new admin_setting_configcheckbox(
            $plugin . '/' . $key,
            get_string('settings_' . $key, $plugin),
            get_string('settings_' . $key . '_desc', $plugin),
            $default
        ));
    }

    /* Grace period before a removed block's course data is discarded. A
     * duration control rather than a plain integer so the unit is explicit —
     * and so it reads in the same currency as tool_recyclebin's own expiry
     * settings, which this can follow. */
    $settings->add(new admin_setting_configduration(
        $plugin . '/removal_grace_seconds',
        get_string('settings_removal_grace_seconds', $plugin),
        get_string('settings_removal_grace_seconds_desc', $plugin),
        \block_feedback_tracker\local\sla\removal_grace::DEFAULT_SECONDS
    ));

    // Display unit for the wait-time metrics. 'hours' (default) keeps the
    // existing effective/wall-clock hour figures; 'business_days' switches to
    // date-based elapsed-day counts (business days skip weekends, holidays and
    // recesses; the time of day is ignored). Both representations are computed
    // and stored by the rollup, so this toggle is display-only — no rollup
    // callback, no recompute on change.
    $settings->add(new admin_setting_configselect(
        $plugin . '/display_time_unit',
        get_string('settings_display_time_unit', $plugin),
        get_string('settings_display_time_unit_desc', $plugin),
        'hours',
        [
            'hours'         => get_string('settings_display_unit_hours', $plugin),
            'business_days' => get_string('settings_display_unit_days', $plugin),
        ]
    ));

    // Group-card title composition from custom group fields. Empty = real group
    // name. Display-only — no rollup callback; the 15-min payload cache (or the
    // block's refresh button) picks up changes.
    $s = new admin_setting_configtext(
        $plugin . '/group_title_fields',
        get_string('settings_group_title_fields', $plugin),
        get_string('settings_group_title_fields_desc', $plugin),
        '',
        PARAM_TEXT
    );
    $settings->add($s);

    $s = new admin_setting_configtext(
        $plugin . '/group_subtitle_fields',
        get_string('settings_group_subtitle_fields', $plugin),
        get_string('settings_group_subtitle_fields_desc', $plugin),
        '',
        PARAM_TEXT
    );
    $settings->add($s);

    // Heading: Tools.
    global $OUTPUT;
    $toolslinks = $OUTPUT->render_from_template('block_feedback_tracker/tools_links', [
        'links' => [
            [
                'url'   => (new moodle_url('/blocks/feedback_tracker/pages/calendar_editor.php'))->out(false),
                'label' => get_string('manage_link_calendar', $plugin),
            ],
            [
                'url'   => (new moodle_url('/blocks/feedback_tracker/pages/audit_log.php'))->out(false),
                'label' => get_string('manage_link_audit', $plugin),
            ],
            [
                'url'   => (new moodle_url('/blocks/feedback_tracker/pages/bulk_remove.php'))->out(false),
                'label' => get_string('manage_link_bulkremove', $plugin),
            ],
            [
                'url'   => (new moodle_url('/blocks/feedback_tracker/pages/reset.php'))->out(false),
                'label' => get_string('manage_link_reset', $plugin),
            ],
        ],
    ]);
    $settings->add(new admin_setting_heading(
        $plugin . '/tools',
        get_string('manage_title', $plugin),
        $toolslinks
    ));
}
