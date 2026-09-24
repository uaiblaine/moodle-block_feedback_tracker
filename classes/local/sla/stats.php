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
 * Statistics helpers (median / percentile / max).
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Pure stat functions (no DB access).
 *
 * All accept a list of numbers, return null when the list is empty and round
 * the result to 2 decimal places. Median and percentile use linear
 * interpolation between adjacent order statistics (the "C=1" definition,
 * Excel's PERCENTILE.INC).
 */
class stats {
    /**
     * Median (50th percentile).
     *
     * @param array $values List of values.
     * @return float|null
     */
    public static function median(array $values): ?float {
        return self::percentile($values, 50.0);
    }

    /**
     * Linear-interpolation percentile.
     *
     * @param array $values List of values.
     * @param float $p 0..100
     * @return float|null
     */
    public static function percentile(array $values, float $p): ?float {
        if (empty($values)) {
            return null;
        }
        $sorted = array_values($values);
        sort($sorted, SORT_NUMERIC);
        $n = count($sorted);
        if ($n === 1) {
            return round((float) $sorted[0], 2);
        }
        $p = max(0.0, min(100.0, $p));
        $rank = ($p / 100.0) * ($n - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);
        if ($lo === $hi) {
            return round((float) $sorted[$lo], 2);
        }
        $frac = $rank - $lo;
        $val = (float) $sorted[$lo] + $frac * ((float) $sorted[$hi] - (float) $sorted[$lo]);
        return round($val, 2);
    }

    /**
     * Maximum.
     *
     * @param array $values List of values.
     * @return float|null
     */
    public static function max_value(array $values): ?float {
        if (empty($values)) {
            return null;
        }
        return round((float) max($values), 2);
    }
}
