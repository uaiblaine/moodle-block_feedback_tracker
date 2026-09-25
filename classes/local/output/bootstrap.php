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
 * React bootstrap helpers — shared i18n + config bundles for the JS layer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

/**
 * Static helpers that build the JSON bundle every React-driven view
 * (block, pending report, teacher dashboard, score simulator) embeds in its
 * mount-point `<script type="application/json" data-bft-init>` tag.
 *
 * An autoloaded class rather than methods on the block class, which Moodle
 * loads only when it renders a block, so standalone pages can use it.
 */
class bootstrap {
    /**
     * Localised label bundle used by every React view. Keys are the lang
     * string ids (band labels nested under `bands`), so the React tree and
     * the server-rendered card show the same strings.
     *
     * @return array
     */
    public static function i18n_bundle(): array {
        return [
            'card_pending' => get_string('card_pending', 'block_feedback_tracker'),
            'card_critical' => get_string('card_critical', 'block_feedback_tracker'),
            'card_overgoal' => get_string('card_overgoal', 'block_feedback_tracker'),
            'card_refresh' => get_string('card_refresh', 'block_feedback_tracker'),
            'card_footer_cache' => get_string('card_footer_cache', 'block_feedback_tracker'),
            'card_footer_sync' => get_string('card_footer_sync', 'block_feedback_tracker'),
            'card_open_drilldown' => get_string('card_open_drilldown', 'block_feedback_tracker'),
            'card_empty' => get_string('card_empty', 'block_feedback_tracker'),
            'card_nogroup' => get_string('card_nogroup', 'block_feedback_tracker'),
            'bands' => [
                'excellent' => get_string('band_excellent', 'block_feedback_tracker'),
                'good'      => get_string('band_good', 'block_feedback_tracker'),
                'regular'   => get_string('band_regular', 'block_feedback_tracker'),
                'critical'  => get_string('band_critical', 'block_feedback_tracker'),
                'pending'   => get_string('band_pending', 'block_feedback_tracker'),
                'nodata'    => get_string('band_nodata', 'block_feedback_tracker'),
            ],
            'block_sort_label' => get_string('block_sort_label', 'block_feedback_tracker'),
            'block_sort_default' => get_string('block_sort_default', 'block_feedback_tracker'),
            'block_sort_priority' => get_string('block_sort_priority', 'block_feedback_tracker'),
            'block_sort_wait' => get_string('block_sort_wait', 'block_feedback_tracker'),
            'block_refresh_error' => get_string('block_refresh_error', 'block_feedback_tracker'),
            'block_loading' => get_string('block_loading', 'block_feedback_tracker'),
            'block_loadmore' => get_string('block_loadmore', 'block_feedback_tracker'),
            // The next two keep their placeholders for the JS to fill in.
            'block_capnotice' => get_string(
                'block_capnotice',
                'block_feedback_tracker',
                (object) ['shown' => '{shown}', 'total' => '{total}']
            ),
            'sparkline_zone_label' => get_string('sparkline_zone_label', 'block_feedback_tracker', '{$a}'),
            // Accessible name of every trend sparkline (TrendRow, CoursesTable).
            'sparkline_aria' => get_string('sparkline_aria', 'block_feedback_tracker'),
            // Block card: hero, KPI tiles, trend row, peer context, pause notice, activities.
            'card_activities_head' => get_string('card_activities_head', 'block_feedback_tracker'),
            'card_effective' => get_string('card_effective', 'block_feedback_tracker'),
            'card_effective_sub' => get_string('card_effective_sub', 'block_feedback_tracker'),
            'card_perceived' => get_string('card_perceived', 'block_feedback_tracker'),
            'card_perceived_sub' => get_string('card_perceived_sub', 'block_feedback_tracker'),
            'card_score_caption' => get_string('card_score_caption', 'block_feedback_tracker'),
            'card_sla' => get_string('card_sla', 'block_feedback_tracker'),
            'card_sla_sub' => get_string('card_sla_sub', 'block_feedback_tracker'),
            'overall_eyebrow' => get_string('overall_eyebrow', 'block_feedback_tracker'),
            'pause_type_label' => get_string('pause_type_label', 'block_feedback_tracker'),
            'pause_upcoming_label' => get_string('pause_upcoming_label', 'block_feedback_tracker'),
            'peer_department' => get_string('peer_department', 'block_feedback_tracker'),
            'peer_title' => get_string('peer_title', 'block_feedback_tracker'),
            'peer_top10' => get_string('peer_top10', 'block_feedback_tracker'),
            'peer_you' => get_string('peer_you', 'block_feedback_tracker'),
            'rule_create' => get_string('rule_create', 'block_feedback_tracker'),
            'rule_done' => get_string('rule_done', 'block_feedback_tracker'),
            'rule_off' => get_string('rule_off', 'block_feedback_tracker'),
            'rule_override' => get_string('rule_override', 'block_feedback_tracker'),
            'timeline_norule' => get_string('timeline_norule', 'block_feedback_tracker'),
            'trend_faster' => get_string('trend_faster', 'block_feedback_tracker'),
            'trend_slower' => get_string('trend_slower', 'block_feedback_tracker'),
            'trend_stable' => get_string('trend_stable', 'block_feedback_tracker'),
            'trend_window_label' => get_string('trend_window_label', 'block_feedback_tracker'),
            // Connectivity / retry affordance — shared by every async surface
            // (dashboard, report page, block, modals) via the RetryNotice component.
            'connection_lost' => get_string('connection_lost', 'block_feedback_tracker'),
            'connection_reload' => get_string('connection_reload', 'block_feedback_tracker'),
            'connection_retry' => get_string('connection_retry', 'block_feedback_tracker'),
        ];
    }

    /**
     * Config bundle: score weights, SLA goal, band thresholds, feature
     * toggles, and the separators, locale and time zone the JS formatters
     * (amd/src/lib/format.js) need. Fallbacks match the settings.php defaults,
     * for a site where a setting is not stored yet.
     *
     * @return array
     */
    public static function config_bundle(): array {
        [$threxcellent, $thrgood, $thrregular] =
            \block_feedback_tracker\local\score\responsiveness_calculator::parse_thresholds_band();
        // Peer-context toggle defaults ON: an unset value (false) is treated as
        // enabled; only an explicit '0' turns it off. A plain `?: 1` read would
        // mis-handle the off case because '0' is falsy in PHP.
        $peercfg = get_config('block_feedback_tracker', 'show_peer_context');
        $showpeer = ($peercfg === false || $peercfg === null) ? true : ((string) $peercfg !== '0');
        // Upcoming-pause notice toggle, stored under the older name
        // show_paused_today_indicator; same default-ON read as above.
        $pausecfg = get_config('block_feedback_tracker', 'show_paused_today_indicator');
        $showpause = ($pausecfg === false || $pausecfg === null) ? true : ((string) $pausecfg !== '0');
        return [
            // The weights the groups are scored with, so the simulator starts
            // from the live formula: a stored 0 drops its term there too.
            'weights' => \block_feedback_tracker\local\score\responsiveness_calculator::load_weights(),
            'sla_goal_hours' => (float) (get_config('block_feedback_tracker', 'sla_goal_hours') ?: 24),
            'score_thresholds' => [
                'excellent' => $threxcellent,
                'good'      => $thrgood,
                'regular'   => $thrregular,
            ],
            'enable_teacher_simulator' =>
                (bool) ((int) (get_config('block_feedback_tracker', 'enable_teacher_simulator') ?: 0) === 1),
            // Display-only: which wait-time representation the UI shows. Both
            // (hour medians and date-based day counts) are computed server-side;
            // the components pick the matching field at render time.
            'display_time_unit' =>
                (string) (get_config('block_feedback_tracker', 'display_time_unit') ?: 'hours'),
            'show_peer_context' => $showpeer,
            'show_scheduled_pauses' => $showpause,
            // Active language's thousands separator, so the React surfaces
            // group counts exactly as numfmt::count() does on the server.
            'thousandssep' => get_string('thousandssep', 'langconfig'),
            // Active language's decimal separator, as format_float() uses it.
            'decsep' => get_string('decsep', 'langconfig'),
            // Locale and time zone for the JS date formatters, so a timestamp
            // reads in the language's conventions and on the same day and hour
            // as userdate() prints it for this user.
            'locale' => self::date_locale(),
            'timezone' => \core_date::get_user_timezone(),
            // Whether the teacher dashboard offers this viewer the site benchmarks.
            'school_comparison' => self::can_view_school_comparison(),
        ];
    }

    /**
     * Whether the current user may see the site benchmarks on the teacher
     * dashboard: the capability the get_school_comparison web service requires,
     * at the context it checks it in, so the section is offered to exactly the
     * viewers the service answers. The service still checks it on every call.
     *
     * @return bool
     */
    public static function can_view_school_comparison(): bool {
        return has_capability('block/feedback_tracker:viewschoolcomparison', \context_system::instance());
    }

    /**
     * The active language's date locale as a BCP 47 tag for Intl in the
     * browser, e.g. 'en-AU' from langconfig's 'en_AU.UTF-8': the locale the
     * language pack declares for its own dates. Falls back to the page's
     * html lang value when the pack's locale is not a usable tag.
     *
     * @return string
     */
    private static function date_locale(): string {
        $locale = (string) get_string('locale', 'langconfig');
        // Drop the codeset and any modifier, then separate with hyphens as BCP 47 does.
        $tag = str_replace('_', '-', (string) preg_replace('/[.@].*$/', '', $locale));
        if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $tag)) {
            return $tag;
        }
        return get_html_lang_attribute_value(current_language());
    }

    /**
     * Teacher-dashboard string overlay. Merged on top of i18n_bundle()
     * by pages/teacher_dashboard.php so the hero, courses table, and
     * site-benchmarks section all share one localised label map. The
     * site-benchmarks strings ship only to the viewers config_bundle() offers
     * the section to.
     *
     * @return array
     */
    public static function dashboard_i18n(): array {
        // When the display unit is business days, the teacher-facing copy that
        // names the working-time unit reads "business days" instead of
        // "business hours" — resolved here so the React layer needs no change.
        $daysmode = ((string) (get_config('block_feedback_tracker', 'display_time_unit') ?: 'hours'))
            === 'business_days';
        $strings = [
            'dashboard_courses_title' => get_string('dashboard_courses_title', 'block_feedback_tracker'),
            'dashboard_courses_empty' => get_string('dashboard_courses_empty', 'block_feedback_tracker'),
            'dashboard_col_course' => get_string('dashboard_col_course', 'block_feedback_tracker'),
            'dashboard_col_pending' => get_string('dashboard_col_pending', 'block_feedback_tracker'),
            'dashboard_col_critical' => get_string('dashboard_col_critical', 'block_feedback_tracker'),
            'dashboard_col_avgscore' => get_string('dashboard_col_avgscore', 'block_feedback_tracker'),
            'dashboard_refresh' => get_string('dashboard_refresh', 'block_feedback_tracker'),
            'dashboard_error' => get_string('dashboard_error', 'block_feedback_tracker'),
            'gradenow_loading' => get_string('gradenow_loading', 'block_feedback_tracker'),
            // Hero, slim-hero toggle, insights, priority list, table columns.
            'dashboard_brandtag' => get_string('dashboard_brandtag', 'block_feedback_tracker'),
            'dashboard_business_chip' => $daysmode
                ? get_string('dashboard_business_chip_days', 'block_feedback_tracker')
                : get_string('dashboard_business_chip', 'block_feedback_tracker'),
            'dashboard_event_chip_label' => get_string('dashboard_event_chip_label', 'block_feedback_tracker'),
            'dashboard_event_chip_tooltip' => get_string('dashboard_event_chip_tooltip', 'block_feedback_tracker'),
            'dashboard_collapse' => get_string('dashboard_collapse', 'block_feedback_tracker'),
            'dashboard_expand' => get_string('dashboard_expand', 'block_feedback_tracker'),
            'dashboard_greeting_afternoon' => get_string('dashboard_greeting_afternoon', 'block_feedback_tracker'),
            'dashboard_greeting_evening' => get_string('dashboard_greeting_evening', 'block_feedback_tracker'),
            'dashboard_greeting_morning' => get_string('dashboard_greeting_morning', 'block_feedback_tracker'),
            'dashboard_hero_body' => $daysmode
                ? get_string('dashboard_hero_body_days', 'block_feedback_tracker')
                : get_string('dashboard_hero_body', 'block_feedback_tracker'),
            'dashboard_hero_eyebrow' => get_string('dashboard_hero_eyebrow', 'block_feedback_tracker'),
            'dashboard_hero_headline' => get_string('dashboard_hero_headline', 'block_feedback_tracker'),
            'dashboard_insights_title' => get_string('dashboard_insights_title', 'block_feedback_tracker'),
            'dashboard_open_course' => get_string('dashboard_open_course', 'block_feedback_tracker'),
            'dashboard_priority_title' => get_string('dashboard_priority_title', 'block_feedback_tracker'),
            'dashboard_simulator_button' => get_string('dashboard_simulator_button', 'block_feedback_tracker'),
            'dashboard_sort_by' => get_string('dashboard_sort_by', 'block_feedback_tracker'),
            'dashboard_subline_critical' => get_string('dashboard_subline_critical', 'block_feedback_tracker'),
            'dashboard_subline_waiting' => get_string('dashboard_subline_waiting', 'block_feedback_tracker'),
            'hero_effective_eyebrow' => get_string('hero_effective_eyebrow', 'block_feedback_tracker'),
            'hero_effective_unit' => get_string('hero_effective_unit', 'block_feedback_tracker'),
            'hero_effective_unit_days' => get_string('hero_effective_unit_days', 'block_feedback_tracker'),
            'hero_perceived_label' => get_string('hero_perceived_label', 'block_feedback_tracker'),
            'hero_perceived_unit' => get_string('hero_perceived_unit', 'block_feedback_tracker'),
            'hero_sla_eyebrow' => get_string('hero_sla_eyebrow', 'block_feedback_tracker'),
            'hero_trend_eyebrow' => get_string('hero_trend_eyebrow', 'block_feedback_tracker'),
            'hero_trend_unit' => get_string('hero_trend_unit', 'block_feedback_tracker'),
            'insight_brightspot_body' => get_string('insight_brightspot_body', 'block_feedback_tracker'),
            'insight_brightspot_eyebrow' => get_string('insight_brightspot_eyebrow', 'block_feedback_tracker'),
            'insight_gentlewatch_body' => get_string('insight_gentlewatch_body', 'block_feedback_tracker'),
            'insight_gentlewatch_eyebrow' => get_string('insight_gentlewatch_eyebrow', 'block_feedback_tracker'),
            'insight_group_label' => get_string('insight_group_label', 'block_feedback_tracker'),
            'insight_momentum_body' => get_string('insight_momentum_body', 'block_feedback_tracker'),
            'insight_momentum_eyebrow' => get_string('insight_momentum_eyebrow', 'block_feedback_tracker'),
            'insight_mostimproved_body' => get_string('insight_mostimproved_body', 'block_feedback_tracker'),
            'insight_mostimproved_eyebrow' => get_string('insight_mostimproved_eyebrow', 'block_feedback_tracker'),
            'pendingreport_col_effective' => get_string('pendingreport_col_effective', 'block_feedback_tracker'),
            'pendingreport_col_perceived' => get_string('pendingreport_col_perceived', 'block_feedback_tracker'),
            'priority_open' => get_string('priority_open', 'block_feedback_tracker'),
            'trend_window_label' => get_string('trend_window_label', 'block_feedback_tracker'),
        ];
        if (self::can_view_school_comparison()) {
            $strings += self::school_comparison_i18n();
        }
        return $strings;
    }

    /**
     * Strings of the dashboard's site-benchmarks section
     * (amd/src/components/SchoolComparison.js).
     *
     * @return array
     */
    private static function school_comparison_i18n(): array {
        $s = static fn (string $k): string => get_string($k, 'block_feedback_tracker');
        return [
            // Keeps its placeholder, the window length, for the JS to fill in.
            'dashboard_comparison_caption' => get_string('dashboard_comparison_caption', 'block_feedback_tracker', '{$a}'),
            'dashboard_comparison_chart' => $s('dashboard_comparison_chart'),
            'dashboard_comparison_col_compliance' => $s('dashboard_comparison_col_compliance'),
            'dashboard_comparison_col_day' => $s('dashboard_comparison_col_day'),
            'dashboard_comparison_col_graded' => $s('dashboard_comparison_col_graded'),
            'dashboard_comparison_col_median' => $s('dashboard_comparison_col_median'),
            'dashboard_comparison_col_p10' => $s('dashboard_comparison_col_p10'),
            'dashboard_comparison_col_p90' => $s('dashboard_comparison_col_p90'),
            'dashboard_comparison_empty' => $s('dashboard_comparison_empty'),
            'dashboard_comparison_error' => $s('dashboard_comparison_error'),
            'dashboard_comparison_loading' => $s('dashboard_comparison_loading'),
            'dashboard_comparison_subtitle' => $s('dashboard_comparison_subtitle'),
            'dashboard_comparison_title' => $s('dashboard_comparison_title'),
            'dashboard_comparison_unit_note' => $s('dashboard_comparison_unit_note'),
            'dashboard_comparison_window' => $s('dashboard_comparison_window'),
            // Keeps its placeholder, as the caption does.
            'dashboard_comparison_window_days' => get_string('dashboard_comparison_window_days', 'block_feedback_tracker', '{$a}'),
        ];
    }

    /**
     * Pending-report-page string overlay. Merged on top of i18n_bundle()
     * by pages/pending_report.php so the React filter row, table and
     * academic-days strip all share one localised label map.
     *
     * @return array
     */
    public static function pending_report_i18n(): array {
        // See dashboard_i18n(): swap the working-time unit copy to "business
        // days" when that display unit is active.
        $daysmode = ((string) (get_config('block_feedback_tracker', 'display_time_unit') ?: 'hours'))
            === 'business_days';
        return [
            'pendingreport_empty' => get_string('pendingreport_empty', 'block_feedback_tracker'),
            'pendingreport_error' => get_string('pendingreport_error', 'block_feedback_tracker'),
            'pendingreport_loading' => get_string('pendingreport_loading', 'block_feedback_tracker'),
            'pendingreport_search_placeholder' => get_string('pendingreport_search_placeholder', 'block_feedback_tracker'),
            'pendingreport_filter_group_all' => get_string('pendingreport_filter_group_all', 'block_feedback_tracker'),
            'pendingreport_page_prev' => get_string('pendingreport_page_prev', 'block_feedback_tracker'),
            'pendingreport_page_next' => get_string('pendingreport_page_next', 'block_feedback_tracker'),
            'pendingreport_page_template' => get_string('pendingreport_page_template', 'block_feedback_tracker'),
            'drilldown_col_student' => get_string('drilldown_col_student', 'block_feedback_tracker'),
            'drilldown_col_activity' => get_string('drilldown_col_activity', 'block_feedback_tracker'),
            'drilldown_col_submitted' => get_string('drilldown_col_submitted', 'block_feedback_tracker'),
            'drilldown_col_status' => get_string('drilldown_col_status', 'block_feedback_tracker'),
            'pause_reason_weekend' => get_string('pause_reason_weekend', 'block_feedback_tracker'),
            'pause_reason_holiday' => get_string('pause_reason_holiday', 'block_feedback_tracker'),
            'pause_reason_recess' => get_string('pause_reason_recess', 'block_feedback_tracker'),
            // Hero metrics, academic-days pause breakdown, status distribution.
            'distribution_hint' => get_string('distribution_hint', 'block_feedback_tracker'),
            'distribution_title' => get_string('distribution_title', 'block_feedback_tracker'),
            'distribution_title_result' => get_string('distribution_title_result', 'block_feedback_tracker'),
            'hero_effective_eyebrow' => get_string('hero_effective_eyebrow', 'block_feedback_tracker'),
            'hero_effective_unit' => get_string('hero_effective_unit', 'block_feedback_tracker'),
            'hero_effective_unit_days' => get_string('hero_effective_unit_days', 'block_feedback_tracker'),
            'hero_perceived_label' => get_string('hero_perceived_label', 'block_feedback_tracker'),
            'hero_perceived_unit' => get_string('hero_perceived_unit', 'block_feedback_tracker'),
            'hero_sla_atrisk' => get_string('hero_sla_atrisk', 'block_feedback_tracker'),
            'hero_sla_critical' => get_string('hero_sla_critical', 'block_feedback_tracker'),
            'hero_sla_eyebrow' => get_string('hero_sla_eyebrow', 'block_feedback_tracker'),
            'hero_trend_eyebrow' => get_string('hero_trend_eyebrow', 'block_feedback_tracker'),
            'hero_trend_unit' => get_string('hero_trend_unit', 'block_feedback_tracker'),
            'paused_breakdown_holiday' => get_string('paused_breakdown_holiday', 'block_feedback_tracker'),
            'paused_breakdown_recess' => get_string('paused_breakdown_recess', 'block_feedback_tracker'),
            'paused_breakdown_weekend' => get_string('paused_breakdown_weekend', 'block_feedback_tracker'),
            'paused_callout_days' => get_string('paused_callout_days', 'block_feedback_tracker'),
            'pendingreport_breadcrumb_course' => get_string('pendingreport_breadcrumb_course', 'block_feedback_tracker'),
            'pendingreport_col_effective' => get_string('pendingreport_col_effective', 'block_feedback_tracker'),
            'pendingreport_col_perceived' => get_string('pendingreport_col_perceived', 'block_feedback_tracker'),
            'pendingreport_crumb_current' => get_string('pendingreport_crumb_current', 'block_feedback_tracker'),
            'pendingreport_filter_class_label' => get_string('pendingreport_filter_class_label', 'block_feedback_tracker'),
            'pendingreport_row_paused' => get_string('pendingreport_row_paused', 'block_feedback_tracker'),
            'pendingreport_row_paused_graded_tip' => $daysmode
                ? get_string('pendingreport_row_paused_graded_tip_days', 'block_feedback_tracker')
                : get_string('pendingreport_row_paused_graded_tip', 'block_feedback_tracker'),
            'pendingreport_row_paused_tip' => $daysmode
                ? get_string('pendingreport_row_paused_tip_days', 'block_feedback_tracker')
                : get_string('pendingreport_row_paused_tip', 'block_feedback_tracker'),
            // Academic-days strip, graded view, action column, collapse toggle,
            // and hero strings shared with the dashboard.
            'acaday_holiday_one' => get_string('acaday_holiday_one', 'block_feedback_tracker'),
            'acaday_legend_good' => get_string('acaday_legend_good', 'block_feedback_tracker'),
            'acaday_legend_ongoal' => get_string('acaday_legend_ongoal', 'block_feedback_tracker'),
            'acaday_legend_paused' => get_string('acaday_legend_paused', 'block_feedback_tracker'),
            'acaday_legend_regular' => get_string('acaday_legend_regular', 'block_feedback_tracker'),
            'acaday_loading' => get_string('acaday_loading', 'block_feedback_tracker'),
            'acaday_recess_one' => get_string('acaday_recess_one', 'block_feedback_tracker'),
            'acaday_title' => get_string('acaday_title', 'block_feedback_tracker'),
            'dashboard_collapse' => get_string('dashboard_collapse', 'block_feedback_tracker'),
            'dashboard_expand' => get_string('dashboard_expand', 'block_feedback_tracker'),
            'dashboard_hero_eyebrow' => get_string('dashboard_hero_eyebrow', 'block_feedback_tracker'),
            'dashboard_simulator_button' => get_string('dashboard_simulator_button', 'block_feedback_tracker'),
            'paused_callout_event_plural' => get_string('paused_callout_event_plural', 'block_feedback_tracker'),
            'paused_callout_event_singular' => get_string('paused_callout_event_singular', 'block_feedback_tracker'),
            'pendingreport_action_grade' => get_string('pendingreport_action_grade', 'block_feedback_tracker'),
            'pendingreport_action_review' => get_string('pendingreport_action_review', 'block_feedback_tracker'),
            'pendingreport_col_action' => get_string('pendingreport_col_action', 'block_feedback_tracker'),
            'pendingreport_col_graded' => get_string('pendingreport_col_graded', 'block_feedback_tracker'),
            'pendingreport_col_result' => get_string('pendingreport_col_result', 'block_feedback_tracker'),
            'pendingreport_mode_graded' => get_string('pendingreport_mode_graded', 'block_feedback_tracker'),
            'pendingreport_mode_pending' => get_string('pendingreport_mode_pending', 'block_feedback_tracker'),
            'pendingreport_subline_graded' => get_string('pendingreport_subline_graded', 'block_feedback_tracker'),
            'pendingreport_subline_pending' => get_string('pendingreport_subline_pending', 'block_feedback_tracker'),
            'report_chip_sla' => get_string('report_chip_sla', 'block_feedback_tracker'),
            'report_hero_body' => $daysmode
                ? get_string('report_hero_body_days', 'block_feedback_tracker')
                : get_string('report_hero_body', 'block_feedback_tracker'),
            'report_hero_eyebrow' => get_string('report_hero_eyebrow', 'block_feedback_tracker'),
            'report_hero_headline' => get_string('report_hero_headline', 'block_feedback_tracker'),
        ];
    }

    /**
     * Label bundle for the interactive score simulator
     * (pages/score_simulator.php → SimulatorView). Self-contained: includes
     * the band labels + breakdown headers it reuses, so the page embeds only
     * this bundle.
     *
     * @return array
     */
    public static function simulator_i18n(): array {
        $s = static fn (string $k): string => get_string($k, 'block_feedback_tracker');
        return [
            'bands' => [
                'excellent' => $s('band_excellent'),
                'good'      => $s('band_good'),
                'regular'   => $s('band_regular'),
                'critical'  => $s('band_critical'),
                'pending'   => $s('band_pending'),
                'nodata'    => $s('band_nodata'),
            ],
            'breakdown_term'   => $s('breakdown_term'),
            'breakdown_value'  => $s('breakdown_value'),
            'breakdown_weight' => $s('breakdown_weight'),
            'breakdown_pts'    => $s('breakdown_pts'),
            'breakdown_total'  => $s('breakdown_total'),
            // The gauge substitutes the score for the placeholder left in the string.
            'gauge_aria' => $s('gauge_aria'),
            'sim_intro_eyebrow' => $s('sim_intro_eyebrow'),
            'sim_intro_heading' => $s('sim_intro_heading'),
            'sim_intro_body'    => $s('sim_intro_body'),
            'sim_term_compliance' => $s('sim_term_compliance'),
            'sim_term_median'     => $s('sim_term_median'),
            'sim_term_critical'   => $s('sim_term_critical'),
            'sim_term_pending'    => $s('sim_term_pending'),
            'sim_term_trend'      => $s('sim_term_trend'),
            'sim_crit_compliance' => $s('sim_crit_compliance'),
            'sim_crit_median'     => $s('sim_crit_median'),
            'sim_crit_critical'   => $s('sim_crit_critical'),
            'sim_crit_pending'    => $s('sim_crit_pending'),
            'sim_crit_trend'      => $s('sim_crit_trend'),
            'sim_scenarios_heading' => $s('sim_scenarios_heading'),
            'sim_scn_exemplary'  => $s('sim_scn_exemplary'),
            'sim_scn_steady'     => $s('sim_scn_steady'),
            'sim_scn_recovering' => $s('sim_scn_recovering'),
            'sim_scn_backlog'    => $s('sim_scn_backlog'),
            'sim_scn_starting'   => $s('sim_scn_starting'),
            'sim_scn_empty'      => $s('sim_scn_empty'),
            'sim_inputs_heading' => $s('sim_inputs_heading'),
            'sim_in_compliance'  => $s('sim_in_compliance'),
            'sim_in_median'      => $s('sim_in_median'),
            'sim_in_numgraded'   => $s('sim_in_numgraded'),
            'sim_in_pending'     => $s('sim_in_pending'),
            'sim_in_critical'    => $s('sim_in_critical'),
            'sim_in_trend'       => $s('sim_in_trend'),
            'sim_in_slagoal'     => $s('sim_in_slagoal'),
            'sim_in_trend_unavailable' => $s('sim_in_trend_unavailable'),
            'sim_weights_heading'    => $s('sim_weights_heading'),
            'sim_weights_normalized' => $s('sim_weights_normalized'),
            'sim_weights_reset'      => $s('sim_weights_reset'),
            'sim_nodata' => $s('sim_nodata'),
            'sim_trend_dropped_note' => $s('sim_trend_dropped_note'),
            'trend_faster' => $s('trend_faster'),
            'trend_slower' => $s('trend_slower'),
            'trend_stable' => $s('trend_stable'),
        ];
    }
}
