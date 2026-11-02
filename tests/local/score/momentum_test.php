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
 * Tests for the week-over-week momentum of the dashboard's Most improved insight.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\score;

/**
 * momentum_pcts() answers many groups from one ledger read with the rules momentum_pct() states: two 7-day windows
 * ending now, at least MOMENTUM_MIN_GRADES submitted grades in each, a positive prior median.
 *
 * @covers \block_feedback_tracker\local\score\responsiveness_calculator
 */
final class momentum_test extends \advanced_testcase {
    /** @var int A fixed "now", so the windows do not depend on the clock. */
    private const NOW = 1790000000;

    /**
     * Insert graded rows for one (course, group) at the given ages with the given effective hours.
     *
     * @param int $courseid
     * @param int $groupid
     * @param int $agedays Days before NOW the grades landed.
     * @param array $hours Effective hours, one row each.
     * @param array $overrides Extra ledger columns for every row.
     * @return void
     */
    private function grades(int $courseid, int $groupid, int $agedays, array $hours, array $overrides = []): void {
        $gen = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        foreach ($hours as $i => $h) {
            $gen->create_ledger_row(array_merge([
                'courseid' => $courseid,
                'groupid' => $groupid,
                'timesubmitted' => self::NOW - ($agedays + 2) * 86400,
                'timegraded' => self::NOW - $agedays * 86400 - $i * 60,
                'effectivehours' => (float) $h,
            ], $overrides));
        }
    }

    /**
     * Two groups in one call: each gets its own windows and median, and the rows of a group outside the pairs,
     * drafts, ungraded rows and rows older than two weeks count for nobody.
     *
     * @return void
     */
    public function test_each_pair_gets_its_own_change(): void {
        $this->resetAfterTest();
        $this->grades(10, 1, 2, [10, 10, 10, 10, 10]);
        $this->grades(10, 1, 9, [20, 20, 20, 20, 20]);
        $this->grades(11, 2, 1, [30, 30, 30, 30, 30]);
        $this->grades(11, 2, 8, [20, 20, 20, 20, 20]);
        // Noise that must not move either answer.
        // As many drafts as grades, so counting them would move the median.
        $this->grades(10, 1, 3, [500, 500, 500, 500, 500], ['submissionstatus' => 'draft']);
        $this->grades(10, 1, 20, [500, 500, 500, 500, 500]);
        $this->grades(10, 9, 2, [500, 500, 500, 500, 500]);
        // Grades without an effective-hours figure count for nothing: as zeros they would halve 10:1's recent median.
        $this->grades(10, 1, 2, [0, 0, 0, 0, 0], ['effectivehours' => null]);
        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')->create_ledger_row(
            ['courseid' => 10, 'groupid' => 1, 'timegraded' => null, 'effectivehours' => 500.0]
        );

        $pcts = responsiveness_calculator::momentum_pcts([[10, 1], [11, 2]], self::NOW);

        $this->assertSame(['10:1' => -50.0, '11:2' => 50.0], $pcts);
        $this->assertSame(-50.0, responsiveness_calculator::momentum_pct(10, 1, self::NOW));
    }

    /**
     * Too few grades in either window, or a prior median that is not positive, gives null; so does a pair with no
     * rows at all.
     *
     * @return void
     */
    public function test_thin_or_zero_windows_give_null(): void {
        $this->resetAfterTest();
        $min = responsiveness_calculator::MOMENTUM_MIN_GRADES;
        $this->grades(20, 1, 2, array_fill(0, $min - 1, 10));
        $this->grades(20, 1, 9, array_fill(0, $min, 20));
        $this->grades(21, 1, 2, array_fill(0, $min, 10));
        $this->grades(21, 1, 9, array_fill(0, $min - 1, 20));
        $this->grades(22, 1, 2, array_fill(0, $min, 10));
        $this->grades(22, 1, 9, array_fill(0, $min, 0));

        $pcts = responsiveness_calculator::momentum_pcts([[20, 1], [21, 1], [22, 1], [23, 1]], self::NOW);

        $this->assertSame(['20:1' => null, '21:1' => null, '22:1' => null, '23:1' => null], $pcts);
    }

    /**
     * No pairs, no query and no answers.
     *
     * @return void
     */
    public function test_no_pairs_gives_nothing(): void {
        $this->assertSame([], responsiveness_calculator::momentum_pcts([], self::NOW));
    }
}
