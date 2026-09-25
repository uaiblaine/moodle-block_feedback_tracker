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
 * Tests for the group drill-down cell text.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

use block_feedback_tracker\local\sla\bucket;

/**
 * Covers the wait columns in both display units and the status badge label.
 * PHPUnit runs in English, whose decimal separator is a dot.
 *
 * @covers \block_feedback_tracker\local\output\drilldown_cells
 */
final class drilldown_cells_test extends \basic_testcase {
    /**
     * Business-days mode shows the day count and ignores the hours.
     *
     * @return void
     */
    public function test_wait_in_days(): void {
        $this->assertSame('3 d', drilldown_cells::wait(true, 3, 27.5));
        $this->assertSame('0 d', drilldown_cells::wait(true, 0, 5.0));
    }

    /**
     * Hours mode shows one decimal and ignores the day count.
     *
     * @return void
     */
    public function test_wait_in_hours(): void {
        $this->assertSame('.', get_string('decsep', 'langconfig'));
        $this->assertSame('27.5 h', drilldown_cells::wait(false, 3, 27.5));
        $this->assertSame('3.3 h', drilldown_cells::wait(false, 9, 3.25));
        $this->assertSame('0.0 h', drilldown_cells::wait(false, 2, 0.0));
    }

    /**
     * Data for test_band_label: each slug and the string it shows.
     *
     * @return array
     */
    public static function band_label_provider(): array {
        return [
            'excellent' => [bucket::EXCELLENT, 'band_excellent'],
            'good' => [bucket::GOOD, 'band_good'],
            'regular' => [bucket::REGULAR, 'band_regular'],
            'critical' => [bucket::CRITICAL, 'band_critical'],
            'pending' => [bucket::PENDING, 'band_pending'],
        ];
    }

    /**
     * Each band shows its band_* string, never its slug.
     *
     * @dataProvider band_label_provider
     * @param string $slug Band slug.
     * @param string $key Lang string the badge must show.
     * @return void
     */
    public function test_band_label(string $slug, string $key): void {
        $label = drilldown_cells::band_label($slug);
        $this->assertSame(get_string($key, 'block_feedback_tracker'), $label);
        $this->assertNotSame($slug, $label);
        $this->assertSame($slug, drilldown_cells::band($slug));
    }

    /**
     * A slug outside the five bands is drawn and labelled as pending, so the
     * badge never shows an unknown slug or a class with no colour rule.
     *
     * @return void
     */
    public function test_unknown_band_reads_as_pending(): void {
        $this->assertSame(bucket::PENDING, drilldown_cells::band(''));
        $this->assertSame(bucket::PENDING, drilldown_cells::band('bogus'));
        $this->assertSame(get_string('band_pending', 'block_feedback_tracker'), drilldown_cells::band_label('bogus'));
    }
}
