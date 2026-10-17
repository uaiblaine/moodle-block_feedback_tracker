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
 * Tests for the elapsed-day counter.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Date-based elapsed-day counting for the "business days" display unit. Time of
 * day is ignored: only the day boundaries crossed are counted, over
 * (date(from), date(to)]. Business days skip weekends and holidays; calendar
 * days count every day. Anchors (UTC): 2026-05-15 is a Friday, 2026-05-18 a
 * Monday.
 */
#[CoversClass(day_counter::class)]
final class day_counter_test extends \advanced_testcase {
    /**
     * Configure the platform calendar: UTC, exclude weekends (Sat+Sun),
     * holidays and recesses. Business-hour windows are irrelevant to day
     * classification, so none are seeded.
     *
     * @return void
     */
    private function seed_calendar(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', (string) calendar::WEEKEND_MASK_DEFAULT, 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        academic_time::reset_memos();
    }

    /**
     * UTC datetime string to Unix timestamp.
     *
     * @param string $datetime
     * @return int
     */
    private function ts(string $datetime): int {
        return (new \DateTime($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * Insert a full-day calendar override (e.g. a holiday).
     *
     * @param string $ymd     YYYYMMDD.
     * @param string $daytype One of calendar::DAYTYPE_*.
     * @return void
     */
    private function add_cday(string $ymd, string $daytype): void {
        global $DB;
        $now = time();
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => (int) $ymd,
            'daytype' => $daytype,
            'note' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        academic_time::reset_memos();
    }

    /**
     * Submit Mon 14:00, grade Tue 10:00 — one day apart regardless of the hour.
     *
     * @return void
     */
    public function test_consecutive_weekdays_count_one(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        $result = day_counter::between($this->ts('2026-05-18 14:00:00'), $this->ts('2026-05-19 10:00:00'));

        $this->assertSame(1, $result['business']);
        $this->assertSame(1, $result['calendar']);
    }

    /**
     * Submit Fri evening, grade Mon morning — 1 business day (Sat/Sun skipped),
     * 3 calendar days.
     *
     * @return void
     */
    public function test_weekend_skipped_for_business_days(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        $result = day_counter::between($this->ts('2026-05-15 20:00:00'), $this->ts('2026-05-18 10:00:00'));

        $this->assertSame(1, $result['business']);
        $this->assertSame(3, $result['calendar']);
    }

    /**
     * Submitted and graded the same calendar day — zero elapsed days.
     *
     * @return void
     */
    public function test_same_day_counts_zero(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        $result = day_counter::between($this->ts('2026-05-18 09:00:00'), $this->ts('2026-05-18 16:00:00'));

        $this->assertSame(0, $result['business']);
        $this->assertSame(0, $result['calendar']);
    }

    /**
     * A holiday inside the interval is excluded from business days but still
     * counts as a calendar day.
     *
     * @return void
     */
    public function test_holiday_excluded_from_business_days(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        // Tue 2026-05-19 is a holiday; submit Mon, grade Wed.
        $this->add_cday('20260519', calendar::DAYTYPE_HOLIDAY);

        $result = day_counter::between($this->ts('2026-05-18 09:00:00'), $this->ts('2026-05-20 14:00:00'));

        $this->assertSame(1, $result['business']);
        $this->assertSame(2, $result['calendar']);
    }

    /**
     * The single-value wrappers agree with between().
     *
     * @return void
     */
    public function test_single_value_wrappers(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $from = $this->ts('2026-05-15 20:00:00');
        $to = $this->ts('2026-05-18 10:00:00');

        $this->assertSame(1, day_counter::business_days($from, $to));
        $this->assertSame(3, day_counter::calendar_days($from, $to));
    }
}
