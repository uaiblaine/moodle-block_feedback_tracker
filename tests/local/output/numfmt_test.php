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
 * Tests for the integer thousands-separator formatter.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

/**
 * Covers numfmt::count() — grouping integer submission counts with the active
 * language's thousands separator (langconfig 'thousandssep').
 *
 * @covers \block_feedback_tracker\local\output\numfmt
 */
final class numfmt_test extends \advanced_testcase {
    /**
     * Counts are grouped every three digits with the active language's
     * thousands separator. PHPUnit runs in English, whose langconfig sets
     * 'thousandssep' to a comma — so the grouping is deterministic here.
     *
     * @return void
     */
    public function test_count_groups_with_language_separator(): void {
        $this->assertSame(',', get_string('thousandssep', 'langconfig'));
        $this->assertSame('999', numfmt::count(999));
        $this->assertSame('1,000', numfmt::count(1000));
        $this->assertSame('22,000', numfmt::count(22000));
        $this->assertSame('1,232,123', numfmt::count(1232123));
    }

    /**
     * Zero and negative counts format without spurious separators or signs.
     *
     * @return void
     */
    public function test_count_handles_zero_and_negative(): void {
        $this->assertSame('0', numfmt::count(0));
        $this->assertSame('-1,500', numfmt::count(-1500));
    }
}
