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

    // The five score-formula weights. Saved values may sum to anything:
    // responsiveness_calculator::load_weights() normalises at read time. Do not
    // normalise on save; the callback would fire partway through
    // admin_apply_default_settings(), which writes the defaults one by one.
    //
    // The paramtype is a regex, not PARAM_FLOAT: admin_setting_configtext
    // compares clean_param() output strictly with the input, so PARAM_FLOAT
    // rejects "0.40" (cleaned to "0.4"), including the defaults below.
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

    // Hour-ruler band cutoffs, in increasing order: bucket::for_effective()
    // tests them from the lowest up.
    $s = new \block_feedback_tracker\local\admin\thresholds_setting(
        $plugin . '/bucket_thresholds_eff',
        get_string('settings_bucket_thresholds_eff', $plugin),
        get_string('settings_bucket_thresholds_eff_desc', $plugin),
        '24,48,120',
        \block_feedback_tracker\local\admin\thresholds_setting::ASCENDING
    );
    $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
    $settings->add($s);

    // Day-ruler band cutoffs, used instead of the hour thresholds when the
    // display unit (Views section) is business days. Bounds are inclusive
    // (see bucket::for_effective_days()). Feeds the rollup's
    // critical_days/overgoal_days twins, hence the invalidate callback.
    $s = new \block_feedback_tracker\local\admin\thresholds_setting(
        $plugin . '/bucket_thresholds_days',
        get_string('settings_bucket_thresholds_days', $plugin),
        get_string('settings_bucket_thresholds_days_desc', $plugin),
        '2,5,10',
        \block_feedback_tracker\local\admin\thresholds_setting::ASCENDING
    );
    $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
    $settings->add($s);
    $settings->hide_if(
        $plugin . '/bucket_thresholds_days',
        $plugin . '/display_time_unit',
        'neq',
        'business_days'
    );

    // SLA goal in business days, the day-mode twin of sla_goal_hours. It bounds
    // the display-only day figures (compliance_pct_days, overgoal_days and the
    // report's business-days pending band); the score keeps sla_goal_hours, so
    // switching the display unit never moves the score.
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
    // of the four bands, in decreasing order because
    // responsiveness_calculator::band_for() tests them from the highest down.
    $s = new \block_feedback_tracker\local\admin\thresholds_setting(
        $plugin . '/score_thresholds_band',
        get_string('settings_score_thresholds_band', $plugin),
        get_string('settings_score_thresholds_band_desc', $plugin),
        '90,70,40',
        \block_feedback_tracker\local\admin\thresholds_setting::DESCENDING,
        0.0,
        100.0
    );
    $s->set_updatedcallback('block_feedback_tracker_invalidate_rollups');
    $settings->add($s);

    // Score simulator launcher, directly under the scoring settings so the
    // admin can try weights before saving them.
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

    // Hidden-course processing toggle. No updated callback: the change is not
    // retroactive. The new rule applies to later writes; rows already stored
    // for hidden courses stay as they are.
    $settings->add(new admin_setting_configcheckbox(
        $plugin . '/process_hidden_courses',
        get_string('settings_process_hidden_courses', $plugin),
        get_string('settings_process_hidden_courses_desc', $plugin),
        0
    ));

    // Backfill master switch. Off by default so install does not scan
    // {assign_submission} on a large site; turn it on once the block is on
    // every course to track. backfill_history reads it on each tick, so no
    // updated callback is needed.
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

    // The exclude_grader_submissions toggle deliberately has no updated callback:
    // it is applied when a ledger row is written, so flipping it is not retroactive.
    $viewbools = [
        'enable_admin_view_all'       => 0,
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

    // Display unit for the wait-time metrics: 'hours' (effective / wall-clock
    // hours) or 'business_days' (date-based day counts that skip weekends,
    // holidays and recesses and ignore the time of day). The rollup stores
    // both, so this is display-only: no updated callback, no recompute.
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

    // Group-card title composition from custom group fields; empty means the
    // group name. Display-only, no updated callback: the block payload cache
    // (15 minutes) or the block's refresh button picks up changes.
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
