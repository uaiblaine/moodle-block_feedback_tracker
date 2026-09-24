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
 * Peer-comparison stats — department median + top-10% benchmarks.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\score;

/**
 * Aggregates the per-group rollup table into the "department median" and
 * "top 10%" benchmarks shown in the block's peer-context panel.
 *
 * There is no department concept: the pool is every scored rollup row on the
 * site, in every course, minus the group being compared. A narrower scope
 * (course category, custom field) is a filter in {@see self::source_rows()}
 * and needs no API change.
 *
 * Percentiles are computed in PHP because Moodle's supported databases have no
 * common percentile syntax, and the pool is only one row per (course, group).
 */
class peer_stats {
    /** Minimum sample size required to publish peer benchmarks. */
    public const MIN_SAMPLE = 3;

    /** @var array<int, array<string, float|null>> Per-request memo, keyed by exclusion (groupid). */
    private static array $cache = [];

    /**
     * Peer benchmarks excluding one group. Returns nulls when fewer than
     * {@see self::MIN_SAMPLE} other groups have a score; the PeerContext
     * component hides itself when both benchmarks are null.
     *
     * @param int $excludegroupid Skip this group when computing the
     *                            benchmark; 0 = include all rows.
     * @return array{
     *     department_score:float|null,
     *     department_hours:float|null,
     *     top10_score:float|null,
     *     top10_hours:float|null
     * }
     */
    public static function for_exclusion(int $excludegroupid): array {
        if (array_key_exists($excludegroupid, self::$cache)) {
            return self::$cache[$excludegroupid];
        }

        $rows = self::source_rows();
        if ($excludegroupid > 0) {
            $rows = array_values(array_filter($rows, static function ($r) use ($excludegroupid) {
                return (int) $r->groupid !== $excludegroupid;
            }));
        }

        if (count($rows) < self::MIN_SAMPLE) {
            $result = [
                'department_score' => null,
                'department_hours' => null,
                'top10_score'      => null,
                'top10_hours'      => null,
            ];
            self::$cache[$excludegroupid] = $result;
            return $result;
        }

        $scores = array_values(array_map(static fn ($r) => (float) $r->responsiveness_score, $rows));
        $hours = array_values(array_map(
            static fn ($r) => $r->median_eff_h !== null ? (float) $r->median_eff_h : null,
            $rows
        ));
        $hoursclean = array_values(array_filter($hours, static fn ($h) => $h !== null));

        $result = [
            'department_score' => self::percentile($scores, 0.5),
            'department_hours' => self::percentile($hoursclean, 0.5),
            // Top 10% by score = 90th percentile (higher is better).
            'top10_score'      => self::percentile($scores, 0.9),
            // Top 10% by hours = 10th percentile (lower hours is better).
            'top10_hours'      => self::percentile($hoursclean, 0.1),
        ];
        self::$cache[$excludegroupid] = $result;
        return $result;
    }

    /**
     * Flush the per-request memo. Used by tests that mutate the rollup
     * table between cases.
     *
     * @return void
     */
    public static function reset_memo(): void {
        self::$cache = [];
    }

    /**
     * Fetch the rollup rows that contribute to the peer pool. Only rows
     * with a non-null score are included — pending / brand-new courses
     * shouldn't drag the benchmark.
     *
     * @return array<int, \stdClass>
     */
    private static function source_rows(): array {
        global $DB;
        return $DB->get_records_select(
            'block_feedback_tracker_group',
            'responsiveness_score IS NOT NULL',
            null,
            '',
            'id, groupid, responsiveness_score, median_eff_h'
        );
    }

    /**
     * Linear-interpolation percentile (R's "type 7", numpy default). The
     * caller is responsible for filtering nulls; an empty list returns null.
     *
     * @param array $values
     * @param float $p Percentile in [0, 1].
     * @return float|null
     */
    private static function percentile(array $values, float $p): ?float {
        if (empty($values)) {
            return null;
        }
        $sorted = $values;
        sort($sorted, SORT_NUMERIC);
        $n = count($sorted);
        if ($n === 1) {
            return (float) $sorted[0];
        }
        $rank = $p * ($n - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);
        $frac = $rank - $lo;
        if ($lo === $hi) {
            return (float) $sorted[$lo];
        }
        return (float) ($sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * $frac);
    }
}
