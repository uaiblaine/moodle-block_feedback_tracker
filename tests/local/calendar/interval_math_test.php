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
 * Tests for the interval math helpers.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Interval math unit tests.
 */
#[CoversClass(interval_math::class)]
final class interval_math_test extends \advanced_testcase {
    public function test_union_empty(): void {
        $this->assertSame([], interval_math::union([]));
    }

    public function test_union_drops_empty_and_invalid(): void {
        $this->assertSame([], interval_math::union([[5, 5], [10, 8], []]));
    }

    public function test_union_merges_overlapping(): void {
        $result = interval_math::union([[5, 10], [8, 12], [15, 20]]);
        $this->assertSame([[5, 12], [15, 20]], $result);
    }

    public function test_union_merges_adjacent(): void {
        $result = interval_math::union([[10, 15], [15, 20]]);
        $this->assertSame([[10, 20]], $result);
    }

    public function test_union_sorts_unsorted_input(): void {
        $result = interval_math::union([[20, 25], [5, 10], [12, 14]]);
        $this->assertSame([[5, 10], [12, 14], [20, 25]], $result);
    }

    public function test_intersect_no_overlap(): void {
        $this->assertSame([], interval_math::intersect([[0, 5]], [[10, 15]]));
    }

    public function test_intersect_partial(): void {
        $this->assertSame([[5, 8]], interval_math::intersect([[0, 8]], [[5, 10]]));
    }

    public function test_intersect_multi(): void {
        $a = [[0, 10], [20, 30]];
        $b = [[5, 25]];
        $this->assertSame([[5, 10], [20, 25]], interval_math::intersect($a, $b));
    }

    public function test_intersect_canonicalises_inputs(): void {
        $a = [[5, 10], [0, 8]];
        $b = [[20, 30], [3, 6]];
        $this->assertSame([[3, 6]], interval_math::intersect($a, $b));
    }

    public function test_subtract_empty_a(): void {
        $this->assertSame([], interval_math::subtract([], [[0, 5]]));
    }

    public function test_subtract_empty_b(): void {
        $this->assertSame([[0, 5]], interval_math::subtract([[0, 5]], []));
    }

    public function test_subtract_disjoint(): void {
        $this->assertSame([[0, 5]], interval_math::subtract([[0, 5]], [[10, 15]]));
    }

    public function test_subtract_partial_left(): void {
        $this->assertSame([[5, 10]], interval_math::subtract([[0, 10]], [[0, 5]]));
    }

    public function test_subtract_partial_right(): void {
        $this->assertSame([[0, 5]], interval_math::subtract([[0, 10]], [[5, 10]]));
    }

    public function test_subtract_middle(): void {
        $this->assertSame([[0, 3], [7, 10]], interval_math::subtract([[0, 10]], [[3, 7]]));
    }

    public function test_subtract_engulfing(): void {
        $this->assertSame([], interval_math::subtract([[3, 7]], [[0, 10]]));
    }

    public function test_subtract_multiple_holes(): void {
        $a = [[0, 20]];
        $b = [[3, 5], [8, 11], [15, 17]];
        $this->assertSame([[0, 3], [5, 8], [11, 15], [17, 20]], interval_math::subtract($a, $b));
    }

    public function test_total_empty(): void {
        $this->assertSame(0, interval_math::total([]));
    }

    public function test_total_sums_lengths(): void {
        $this->assertSame(15, interval_math::total([[0, 10], [20, 25]]));
    }

    public function test_total_deduplicates_via_union(): void {
        // Overlapping intervals are merged before summing.
        $this->assertSame(10, interval_math::total([[0, 8], [5, 10]]));
    }
}
