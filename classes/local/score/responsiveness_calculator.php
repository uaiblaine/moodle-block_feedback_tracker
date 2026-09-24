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
 * Academic Responsiveness Score formula.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\score;

/**
 * Five-term weighted score on a 0-100 scale, mapped to a four-band label.
 *
 * Terms (all in [0, 1]):
 *  - compliance: compliance_pct / 100, the share of last-30d grades within the SLA goal
 *  - median:     1 - median_eff_h / (2 * sla_goal); 0.5 at goal, 0 at 2x goal
 *  - critical:   1 - critical / max(pending, 1)
 *  - pending:    1 - pending / max(numgraded30d, 20)
 *  - trend:      0.5 - trend_pct_30d / 200 (negative trend = improvement)
 *
 * Weights default to (0.40, 0.25, 0.15, 0.10, 0.10) and are admin tunable.
 * Missing data is treated charitably (compliance and median score 1.0) so a
 * group that has started work but not finished grading is not penalised. A
 * group with no submitted work at all (nothing graded, nothing pending)
 * scores null / 'nodata' instead, so empty groups never top the dashboard or
 * skew averages.
 *
 * amd/src/lib/score.js mirrors this formula for the score simulator; keep the
 * two in step.
 */
class responsiveness_calculator {
    /** Default weight for the compliance term. */
    public const DEFAULT_WEIGHT_COMPLIANCE = 0.40;
    /** Default weight for the median term. */
    public const DEFAULT_WEIGHT_MEDIAN = 0.25;
    /** Default weight for the critical term. */
    public const DEFAULT_WEIGHT_CRITICAL = 0.15;
    /** Default weight for the pending term. */
    public const DEFAULT_WEIGHT_PENDING = 0.10;
    /** Default weight for the trend term. */
    public const DEFAULT_WEIGHT_TREND = 0.10;

    /** Default SLA goal hours. */
    public const DEFAULT_SLA_GOAL_HOURS = 24.0;
    /** Minimum denominator of the pending term, used when numgraded30d is smaller. */
    public const PENDING_SOFT_CAP_MIN = 20;
    /** Minimum grades per week required to compute a meaningful momentum signal. */
    public const MOMENTUM_MIN_GRADES = 5;
    /** Momentum threshold (%) below which the dashboard prefers it over the 30-day trend. */
    public const MOMENTUM_TRIGGER_PCT = -40.0;

    /** Band slug for a group with no submitted work to measure. */
    public const BAND_NODATA = 'nodata';

    /**
     * Compute the score.
     *
     * @param array $metrics {
     * @var float|null $compliance_pct  Percentage 0..100, or null.
     * @var float|null $median_eff_h    Median effective hours (graded), or null.
     * @var int        $critical        Count of critical pending submissions.
     * @var int        $pending         Count of pending submissions.
     * @var int        $numgraded30d    Count of graded in last 30 days.
     * @var float|null $trend_pct_30d   Trend percentage (negative = improving), or null.
     * }
     * @return array{score:float|null, band:string, components:array<string, float|null>}
     */
    public static function compute(array $metrics): array {
        $weights = self::load_weights();
        $slagoal = (float) (get_config('block_feedback_tracker', 'sla_goal_hours') ?: self::DEFAULT_SLA_GOAL_HOURS);
        if ($slagoal <= 0.0) {
            $slagoal = self::DEFAULT_SLA_GOAL_HOURS;
        }

        $numgraded = max(0, (int) ($metrics['numgraded30d'] ?? 0));
        $pending = max(0, (int) ($metrics['pending'] ?? 0));
        $critical = max(0, (int) ($metrics['critical'] ?? 0));
        $compliancepct = isset($metrics['compliance_pct']) ? (float) $metrics['compliance_pct'] : null;
        $medianeff = isset($metrics['median_eff_h']) ? (float) $metrics['median_eff_h'] : null;
        $trendpct = isset($metrics['trend_pct_30d']) ? (float) $metrics['trend_pct_30d'] : null;

        // Nothing to measure. A null score is ignored by AVG(), peer_stats and
        // the insight picks, where a charitable ~100 would top the dashboard.
        if ($numgraded === 0 && $pending === 0) {
            return [
                'score' => null,
                'band'  => self::BAND_NODATA,
                'components' => [
                    'compliance' => null,
                    'median'     => null,
                    'critical'   => null,
                    'pending'    => null,
                    'trend'      => null,
                ],
            ];
        }

        $compliance = $numgraded === 0 || $compliancepct === null
            ? 1.0
            : self::clamp01($compliancepct / 100.0);

        $median = $medianeff === null
            ? 1.0
            : self::clamp01(1.0 - $medianeff / (2.0 * $slagoal));

        $criticalterm = self::clamp01(1.0 - $critical / max($pending, 1));

        $softcap = max($numgraded, self::PENDING_SOFT_CAP_MIN);
        $pendingterm = self::clamp01(1.0 - $pending / $softcap);

        // Without a prior 30-day window (typically a course that has just
        // started) there is no trend. The term is dropped and the other
        // weights renormalised; a neutral 0.5 would cap such a course below 100
        // (at 95 with the default weights).
        $trend = $trendpct === null
            ? null
            : self::clamp01(0.5 - $trendpct / 200.0);

        $available = [
            'compliance' => true,
            'median'     => true,
            'critical'   => true,
            'pending'    => true,
            'trend'      => $trend !== null,
        ];
        $effective = self::effective_weights($weights, $available);
        $contribs = [
            'compliance' => $compliance,
            'median'     => $median,
            'critical'   => $criticalterm,
            'pending'    => $pendingterm,
            'trend'      => $trend ?? 0.0,
        ];
        $score = 0.0;
        foreach ($effective as $key => $w) {
            $score += $w * $contribs[$key];
        }
        $score = round(max(0.0, min(100.0, 100.0 * $score)), 2);

        return [
            'score' => $score,
            'band'  => self::band_for($score),
            'components' => [
                'compliance' => round($compliance, 4),
                'median'     => round($median, 4),
                'critical'   => round($criticalterm, 4),
                'pending'    => round($pendingterm, 4),
                'trend'      => $trend === null ? null : round($trend, 4),
            ],
        ];
    }

    /**
     * Week-over-week change in median effective hours for one group, used
     * by the dashboard's "Most improved" insight to spot sharp recoveries
     * before the 30-day trend catches up. Never feeds the score.
     *
     * Both windows are 7-day slices of timegraded ending at `$now`:
     *   - recent window: [now - 7d, now)
     *   - prior  window: [now - 14d, now - 7d)
     *
     * Returns the % change of the recent median vs the prior median, negative
     * meaning faster turnaround. Returns null when either window has fewer
     * than {@see self::MOMENTUM_MIN_GRADES} grades (too small a sample) or the
     * prior median is not positive.
     *
     * Runs up to two ledger queries per call and caches nothing; its caller
     * {@see \block_feedback_tracker\external\get_insights} caches its result.
     *
     * @param int $courseid
     * @param int $groupid
     * @param int|null $now
     * @return float|null
     */
    public static function momentum_pct(int $courseid, int $groupid, ?int $now = null): ?float {
        $now = $now ?? time();
        $weeksec = 7 * 86400;
        $recentstart = $now - $weeksec;
        $priorstart = $now - 2 * $weeksec;

        $recentvals = self::weekly_effective_hours($courseid, $groupid, $recentstart, $now);
        if (count($recentvals) < self::MOMENTUM_MIN_GRADES) {
            return null;
        }
        $priorvals = self::weekly_effective_hours($courseid, $groupid, $priorstart, $recentstart);
        if (count($priorvals) < self::MOMENTUM_MIN_GRADES) {
            return null;
        }
        $recentmedian = \block_feedback_tracker\local\sla\stats::median($recentvals);
        $priormedian = \block_feedback_tracker\local\sla\stats::median($priorvals);
        if ($priormedian === null || $priormedian <= 0.0) {
            return null;
        }
        return round(100.0 * ($recentmedian - $priormedian) / $priormedian, 2);
    }

    /**
     * Fetch effective-hours values for grades in [$start, $end) for one
     * (course, group). Used by {@see self::momentum_pct()}.
     *
     * @param int $courseid
     * @param int $groupid
     * @param int $start
     * @param int $end
     * @return array<int, float>
     */
    private static function weekly_effective_hours(int $courseid, int $groupid, int $start, int $end): array {
        global $DB;
        $rows = $DB->get_records_select(
            'block_feedback_tracker_sub',
            'courseid = :courseid AND groupid = :groupid'
                . ' AND timegraded IS NOT NULL'
                . ' AND timegraded >= :start AND timegraded < :end'
                . ' AND submissionstatus = :substatus',
            [
                'courseid' => $courseid,
                'groupid'  => $groupid,
                'start'    => $start,
                'end'      => $end,
                'substatus' => \block_feedback_tracker\local\sla\submission_status::SUBMITTED,
            ],
            '',
            'id, effectivehours'
        );
        $out = [];
        foreach ($rows as $r) {
            if ($r->effectivehours !== null) {
                $out[] = (float) $r->effectivehours;
            }
        }
        return $out;
    }

    /**
     * Compute effective per-term weights given which terms have data.
     *
     * Terms not flagged true in $available are dropped (weight 0) and the
     * remaining weights are scaled to sum 1.0, so even with every term
     * available a load_weights() result inside its [0.95, 1.05] tolerance is
     * rescaled here. When no term is available every weight is 0; the caller
     * must guard against that case.
     *
     * @param array $weights Admin-configured weights (already normalised by load_weights()).
     * @param array $available Per-term availability flags.
     * @return array<string, float> Renormalised weights, same keys as $weights.
     */
    public static function effective_weights(array $weights, array $available): array {
        $keepsum = 0.0;
        foreach ($weights as $k => $w) {
            if (!empty($available[$k])) {
                $keepsum += (float) $w;
            }
        }
        $out = [];
        foreach ($weights as $k => $w) {
            if (empty($available[$k]) || $keepsum <= 0.0) {
                $out[$k] = 0.0;
            } else {
                $out[$k] = ((float) $w) / $keepsum;
            }
        }
        return $out;
    }

    /**
     * Map a score to one of the four bands using the configured cutoffs.
     *
     * @param float $score
     * @return string excellent|good|regular|critical
     */
    public static function band_for(float $score): string {
        [$excellent, $good, $regular] = self::parse_thresholds_band();
        if ($score >= $excellent) {
            return 'excellent';
        }
        if ($score >= $good) {
            return 'good';
        }
        if ($score >= $regular) {
            return 'regular';
        }
        return 'critical';
    }

    /**
     * Parse the score-band thresholds setting (CSV) into a three-element
     * float array [excellent_min, good_min, regular_min].
     *
     * Each missing or non-numeric element falls back to its own default
     * (90, 70, 40). The values are neither clamped nor sorted: the admin
     * setting rejects a value out of descending order or outside 0-100
     * ({@see \block_feedback_tracker\local\admin\thresholds_setting}), and a
     * value stored before that check is read as it was typed.
     *
     * @return array{0:float, 1:float, 2:float}
     */
    public static function parse_thresholds_band(): array {
        $raw = (string) (get_config('block_feedback_tracker', 'score_thresholds_band') ?: '90,70,40');
        $parts = array_map('trim', explode(',', $raw));
        $t1 = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 90.0;
        $t2 = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : 70.0;
        $t3 = isset($parts[2]) && is_numeric($parts[2]) ? (float) $parts[2] : 40.0;
        return [$t1, $t2, $t3];
    }

    /**
     * Load the five weights from config_plugins.
     *
     * A missing, non-numeric or negative weight falls back to its own default;
     * if the weights then sum to zero, all defaults are returned. A sum outside
     * [0.95, 1.05] is normalised to 1.0. Normalisation happens here, at read
     * time, so the stored settings keep the values the admin typed.
     *
     * @return array{compliance:float, median:float, critical:float, pending:float, trend:float}
     */
    public static function load_weights(): array {
        $raw = [
            'compliance' => get_config('block_feedback_tracker', 'weight_compliance'),
            'median'     => get_config('block_feedback_tracker', 'weight_median'),
            'critical'   => get_config('block_feedback_tracker', 'weight_critical'),
            'pending'    => get_config('block_feedback_tracker', 'weight_pending'),
            'trend'      => get_config('block_feedback_tracker', 'weight_trend'),
        ];
        $defaults = [
            'compliance' => self::DEFAULT_WEIGHT_COMPLIANCE,
            'median'     => self::DEFAULT_WEIGHT_MEDIAN,
            'critical'   => self::DEFAULT_WEIGHT_CRITICAL,
            'pending'    => self::DEFAULT_WEIGHT_PENDING,
            'trend'      => self::DEFAULT_WEIGHT_TREND,
        ];
        $weights = [];
        foreach ($defaults as $k => $def) {
            $v = $raw[$k];
            $weights[$k] = (is_numeric($v) && (float) $v >= 0.0) ? (float) $v : $def;
        }
        $sum = array_sum($weights);
        if ($sum <= 0.0) {
            return $defaults;
        }
        if ($sum < 0.95 || $sum > 1.05) {
            foreach ($weights as $k => $v) {
                $weights[$k] = $v / $sum;
            }
        }
        return $weights;
    }

    /**
     * Clamp a value into [0, 1].
     *
     * @param float $v
     * @return float
     */
    private static function clamp01(float $v): float {
        if ($v < 0.0) {
            return 0.0;
        }
        if ($v > 1.0) {
            return 1.0;
        }
        return $v;
    }
}
