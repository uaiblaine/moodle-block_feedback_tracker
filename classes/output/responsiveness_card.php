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
 * Responsiveness card renderable.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\output;

use block_feedback_tracker\local\output\numfmt;

/**
 * One responsiveness card per (course, group): the block's no-JS first paint.
 * Composes the score gauge, counts row, metrics row, optional sparkline,
 * scheduled-pause notice and drilldown link. Rendered via Mustache; see
 * templates/responsiveness_card.mustache.
 */
class responsiveness_card implements \renderable, \templatable {
    /** @var int The course ID. */
    public int $courseid;

    /** @var array One element of the responsiveness payload `groups` array. */
    public array $payload;

    /**
     * Constructor for responsiveness_card.
     *
     * @param int $courseid The course ID.
     * @param array $payload One element of the responsiveness payload `groups` array.
     */
    public function __construct(
        int $courseid,
        array $payload
    ) {
        $this->courseid = $courseid;
        $this->payload = $payload;
    }

    /**
     * Build the Mustache template context.
     *
     * @param \renderer_base $output
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $p = $this->payload;

        // The template prints both through double stashes, which escape them,
        // so format_string() filters and strips tags but does not escape.
        $formatoptions = ['context' => \context_course::instance($this->courseid), 'escape' => false];
        $title = format_string(
            (string) ($p['groupname'] !== '' ? $p['groupname'] : $p['coursename']),
            true,
            $formatoptions
        );
        $subtitle = isset($p['groupsubtitle']) && (string) $p['groupsubtitle'] !== ''
            ? format_string((string) $p['groupsubtitle'], true, $formatoptions) : '';

        $gauge = new score_gauge(
            $p['responsiveness_score'] !== null ? (float) $p['responsiveness_score'] : null,
            $p['score_band'] !== null ? (string) $p['score_band'] : null,
            100
        );

        // Default-ON toggles: unset (get_config returns false) keeps the default and
        // only a stored '0' turns them off; a `?: 1` read could never turn them off.
        $perceivedcfg = get_config('block_feedback_tracker', 'show_perceived_time');
        $showperceived = ($perceivedcfg === false || $perceivedcfg === null) ? true : ((string) $perceivedcfg !== '0');
        $pausecfg = get_config('block_feedback_tracker', 'show_paused_today_indicator');
        $showpause = ($pausecfg === false || $pausecfg === null) ? true : ((string) $pausecfg !== '0');

        // Mutually-exclusive pending bands (sum = total pending): within-goal
        // (derived) | over-goal | critical.
        $critical = (int) $p['critical'];
        $overgoal = (int) $p['overgoal'];
        $waiting = max(0, (int) $p['pending'] - $overgoal - $critical);
        $counts = [
            ['label' => get_string('card_pending', 'block_feedback_tracker'), 'value' => numfmt::count($waiting)],
            ['label' => get_string('card_overgoal', 'block_feedback_tracker'), 'value' => numfmt::count($overgoal)],
            ['label' => get_string('card_critical', 'block_feedback_tracker'), 'value' => numfmt::count($critical)],
        ];

        $metrics = $this->build_metrics($p, $showperceived);
        $upcoming = $showpause ? $this->build_upcoming($p) : [];

        $sparklinectx = $this->build_sparkline($p, $output);

        $drilldownurl = new \moodle_url('/blocks/feedback_tracker/pages/group_drilldown.php', [
            'courseid' => $this->courseid,
            'groupid'  => (int) $p['groupid'],
        ]);

        return [
            'title'         => $title,
            'hassubtitle'   => $subtitle !== '',
            'subtitle'      => $subtitle,
            'band'          => $p['score_band'] !== null ? (string) $p['score_band'] : '',
            'bandlabel'     => self::band_label($p['score_band'] !== null ? (string) $p['score_band'] : ''),
            'gauge'         => $gauge->export_for_template($output),
            'counts'        => $counts,
            'metrics'       => $metrics,
            'hasupcoming'        => !empty($upcoming),
            'upcoming'           => $upcoming,
            'upcoming_eyebrow'   => get_string('pause_upcoming_label', 'block_feedback_tracker'),
            'upcoming_typelabel' => get_string('pause_type_label', 'block_feedback_tracker'),
            'hassparkline'  => $sparklinectx !== null,
            'sparkline'     => $sparklinectx ?? [],
            'courseid'      => $this->courseid,
            'refreshtext'   => get_string('card_refresh', 'block_feedback_tracker'),
            'drilldownurl'  => $drilldownurl->out(false),
            'drilldowntext' => get_string('card_open_drilldown', 'block_feedback_tracker'),
        ];
    }

    /**
     * Build the metric rows (median eff/perceived, compliance, trend).
     *
     * @param array $p
     * @param bool $showperceived
     * @return array
     */
    private function build_metrics(array $p, bool $showperceived): array {
        // Headline Effective / Perceived use the include-pending "current"
        // medians (cur_median_*) so the card reflects the live backlog,
        // matching the dashboard. The score keeps using graded-only median_eff_h.
        $cureff = $p['cur_median_eff_h'] ?? null;
        $curraw = $p['cur_median_raw_h'] ?? null;
        // The headline honours the global display-unit setting. Business days
        // show the date-based day medians (counted from the submit/grade dates
        // server-side); hours keep the effective/wall-clock hour medians. This
        // mirrors the React surfaces (lib/format.js).
        $unit = (string) (get_config('block_feedback_tracker', 'display_time_unit') ?: 'hours');
        if ($unit === 'business_days') {
            $effdays = $p['cur_median_eff_days'] ?? null;
            $percdays = $p['cur_median_perc_days'] ?? null;
            $median = $effdays !== null ? format_float((float) $effdays, 1) . ' d' : '—';
            if ($showperceived && $percdays !== null) {
                $median .= ' / ' . format_float((float) $percdays, 1) . ' d';
            }
        } else {
            $median = $cureff !== null ? format_float((float) $cureff, 1) . ' h' : '—';
            if ($showperceived && $curraw !== null) {
                $median .= ' / ' . format_float((float) $curraw, 1) . ' h';
            }
        }
        // Compliance honours the display unit too: business-days mode shows
        // the day-ruler twin (compliance_pct_days), hours mode the
        // effective-hours compliance. Display-only; the score is unaffected.
        $compliancesource = $unit === 'business_days'
            ? ($p['compliance_pct_days'] ?? null)
            : ($p['compliance_pct'] ?? null);
        $compliance = $compliancesource !== null
            ? format_float((float) $compliancesource, 0) . '%'
            : '—';
        $trendtxt = '—';
        if ($p['trend_pct_30d'] !== null) {
            $trendval = (float) $p['trend_pct_30d'];
            // Speed model: fewer hours (negative) = faster = ▲; more = slower = ▼.
            // Same rule as amd/src/lib/format.js formatTrend(); keep in step.
            $arrow = $trendval < 0 ? '▲' : ($trendval > 0 ? '▼' : '→');
            $trendtxt = $arrow . ' ' . format_float(abs($trendval), 0) . '%';
        }
        return [
            ['label' => get_string('card_median_eff', 'block_feedback_tracker'), 'value' => $median],
            ['label' => get_string('card_compliance', 'block_feedback_tracker'), 'value' => $compliance],
            ['label' => get_string('card_trend', 'block_feedback_tracker'), 'value' => $trendtxt],
        ];
    }

    /**
     * Build the scheduled-pause notice rows ("Upcoming pause") from the
     * payload's upcoming_pauses list. The list is already decorated with
     * localised when/typelabel strings by upcoming_pauses::for_display(), so
     * the no-JS card and the Preact block render identical text. Its label is
     * plain text, which the template's double stash escapes.
     *
     * @param array $p
     * @return array
     */
    private function build_upcoming(array $p): array {
        $rows = [];
        $entries = is_array($p['upcoming_pauses'] ?? null) ? $p['upcoming_pauses'] : [];
        foreach ($entries as $u) {
            $label = (string) ($u['label'] ?? '');
            $typelabel = (string) ($u['typelabel'] ?? '');
            $rows[] = [
                'haslabel'     => $label !== '',
                'label'        => $label,
                'when'         => (string) ($u['when'] ?? ''),
                'hastypelabel' => $typelabel !== '',
                'typelabel'    => $typelabel,
            ];
        }
        return $rows;
    }

    /**
     * Build the sparkline context, or null when no trend data is available.
     *
     * @param array $p
     * @param \renderer_base $output
     * @return array|null
     */
    private function build_sparkline(array $p, \renderer_base $output): ?array {
        $series = is_array($p['trend_series'] ?? null) ? $p['trend_series'] : [];
        if (empty($series)) {
            return null;
        }
        $hasdata = false;
        $values = [];
        foreach ($series as $point) {
            $v = $point['value'] ?? null;
            $values[] = $v !== null ? (float) $v : null;
            if ($v !== null) {
                $hasdata = true;
            }
        }
        if (!$hasdata) {
            return null;
        }
        $goal = (float) (get_config('block_feedback_tracker', 'sla_goal_hours') ?: 24);
        $spark = new sparkline($values, $goal);
        return $spark->export_for_template($output);
    }

    /**
     * Localised label for an SLA band (literal map for the string-checker).
     *
     * @param string $band
     * @return string
     */
    private static function band_label(string $band): string {
        switch ($band) {
            case 'excellent':
                return get_string('band_excellent', 'block_feedback_tracker');
            case 'good':
                return get_string('band_good', 'block_feedback_tracker');
            case 'regular':
                return get_string('band_regular', 'block_feedback_tracker');
            case 'critical':
                return get_string('band_critical', 'block_feedback_tracker');
            case 'pending':
                return get_string('band_pending', 'block_feedback_tracker');
            case 'nodata':
                return get_string('band_nodata', 'block_feedback_tracker');
            default:
                return '';
        }
    }
}
