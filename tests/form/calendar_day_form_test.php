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
 * Tests for calendar-day validation.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\form;

use block_feedback_tracker\local\calendar\calendar;

/**
 * Two independent checks live here: the date is real, and the optional
 * sub-day window is coherent. The date check has two distinct failure modes —
 * out of range, and well-formed but non-existent — which need separate cases
 * because only one of them goes through checkdate().
 *
 * @covers \block_feedback_tracker\form\calendar_day_form
 */
final class calendar_day_form_test extends \advanced_testcase {
    /**
     * Validate a day definition.
     *
     * @param array $data Overrides for daydate, daytype, starttime, endtime.
     * @return array Validation errors.
     */
    private function validate(array $data): array {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/');

        $defaults = ['daydate' => 20260601, 'daytype' => 'holiday', 'starttime' => '', 'endtime' => ''];
        return (new calendar_day_form())->validation(array_merge($defaults, $data), []);
    }

    /**
     * A real date passes.
     *
     * @return void
     */
    public function test_valid_date(): void {
        $this->assertSame([], $this->validate(['daydate' => 20260601]));
    }

    /**
     * Dates outside the accepted range are rejected.
     *
     * @return void
     */
    public function test_out_of_range_dates_are_rejected(): void {
        $this->assertArrayHasKey('daydate', $this->validate(['daydate' => 19600101]));
        $this->assertArrayHasKey('daydate', $this->validate(['daydate' => 100000101]));
        $this->assertArrayHasKey('daydate', $this->validate(['daydate' => 0]));
    }

    /**
     * A date that is well-formed and in range but does not exist is caught by
     * checkdate(), a separate branch from the range test.
     *
     * @return void
     */
    public function test_nonexistent_date_is_rejected(): void {
        $this->assertArrayHasKey('daydate', $this->validate(['daydate' => 20260230]));
        $this->assertArrayHasKey('daydate', $this->validate(['daydate' => 20261301]));
    }

    /**
     * A leap day in a leap year is real and must pass.
     *
     * @return void
     */
    public function test_leap_day_is_accepted(): void {
        $this->assertSame([], $this->validate(['daydate' => 20240229]));
        $this->assertArrayHasKey('daydate', $this->validate(['daydate' => 20260229]));
    }

    /**
     * An optional day with no window at all is a full-day optional.
     *
     * @return void
     */
    public function test_optional_day_without_a_window(): void {
        $this->assertSame([], $this->validate(['daytype' => calendar::DAYTYPE_OPTIONAL]));
    }

    /**
     * Supplying only one end of the window is incomplete.
     *
     * @return void
     */
    public function test_half_specified_window_is_rejected(): void {
        $this->assertArrayHasKey('endtime', $this->validate([
            'daytype' => calendar::DAYTYPE_OPTIONAL, 'starttime' => '09:00',
        ]));
        $this->assertArrayHasKey('endtime', $this->validate([
            'daytype' => calendar::DAYTYPE_OPTIONAL, 'endtime' => '17:00',
        ]));
    }

    /**
     * A malformed time is reported against the field it came from, which is
     * why the two sides need separate cases.
     *
     * @return void
     */
    public function test_malformed_times_report_on_their_own_field(): void {
        $badstart = $this->validate([
            'daytype' => calendar::DAYTYPE_OPTIONAL, 'starttime' => '25:00', 'endtime' => '17:00',
        ]);
        $this->assertArrayHasKey('starttime', $badstart);

        $badend = $this->validate([
            'daytype' => calendar::DAYTYPE_OPTIONAL, 'starttime' => '09:00', 'endtime' => '9am',
        ]);
        $this->assertArrayHasKey('endtime', $badend);
    }

    /**
     * A window must run forwards, and equal times are rejected too.
     *
     * @return void
     */
    public function test_window_must_run_forwards(): void {
        $this->assertArrayHasKey('endtime', $this->validate([
            'daytype' => calendar::DAYTYPE_OPTIONAL, 'starttime' => '17:00', 'endtime' => '09:00',
        ]));
        $this->assertArrayHasKey('endtime', $this->validate([
            'daytype' => calendar::DAYTYPE_OPTIONAL, 'starttime' => '09:00', 'endtime' => '09:00',
        ]));
    }

    /**
     * A coherent window passes.
     *
     * @return void
     */
    public function test_valid_window(): void {
        $this->assertSame([], $this->validate([
            'daytype' => calendar::DAYTYPE_OPTIONAL, 'starttime' => '09:00', 'endtime' => '17:00',
        ]));
    }

    /**
     * The window is only meaningful for optional days. On any other day type
     * the times are ignored entirely rather than validated — losing that
     * branch would produce spurious errors on ordinary holidays.
     *
     * @return void
     */
    public function test_window_is_ignored_on_non_optional_days(): void {
        $this->assertSame([], $this->validate([
            'daytype' => 'holiday', 'starttime' => '17:00', 'endtime' => '09:00',
        ]));
    }

    /**
     * Single-digit hours are accepted by the time pattern.
     *
     * @return void
     */
    public function test_single_digit_hours_are_accepted(): void {
        $this->assertSame([], $this->validate([
            'daytype' => calendar::DAYTYPE_OPTIONAL, 'starttime' => '9:00', 'endtime' => '17:30',
        ]));
    }
}
