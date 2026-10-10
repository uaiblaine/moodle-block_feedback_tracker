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
 * Tests for the statistics helpers.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Median / percentile / max boundary checks.
 *
 * @covers \block_feedback_tracker\local\sla\stats
 */
final class stats_test extends \advanced_testcase {
    public function test_empty_returns_null(): void {
        $this->assertNull(stats::median([]));
        $this->assertNull(stats::percentile([], 50.0));
        $this->assertNull(stats::max_value([]));
    }

    public function test_single_value(): void {
        $this->assertSame(7.0, stats::median([7]));
        $this->assertSame(7.0, stats::percentile([7], 0.0));
        $this->assertSame(7.0, stats::percentile([7], 100.0));
        $this->assertSame(7.0, stats::max_value([7]));
    }

    public function test_median_odd(): void {
        $this->assertSame(5.0, stats::median([1, 2, 5, 8, 9]));
    }

    public function test_median_even_interpolates(): void {
        $this->assertSame(5.0, stats::median([2, 4, 6, 8])); // Midpoint between 4 and 6.
    }

    public function test_percentile_linear_interpolation(): void {
        /*
         * The implementation uses the C=1 formula (Excel's PERCENTILE.INC):
         *   rank = (p/100) * (n - 1), interpolate between sorted[floor] and sorted[ceil].
         * For [1,2,3,4,5] (n=5): p=25 → rank=1.0 → sorted[1]=2.0 (no interpolation).
         * PERCENTILE.EXC (R's type 6) would give 1.5 here.
         */
        $this->assertSame(2.0, stats::percentile([1, 2, 3, 4, 5], 25.0));
        $this->assertSame(3.0, stats::percentile([1, 2, 3, 4, 5], 50.0));
        $this->assertSame(4.6, stats::percentile([1, 2, 3, 4, 5], 90.0));
    }

    public function test_max(): void {
        $this->assertSame(8.0, stats::max_value([1, 8, 3, 5]));
    }

    public function test_handles_unsorted_input(): void {
        $this->assertSame(5.0, stats::median([9, 1, 5, 2, 8]));
    }
}
