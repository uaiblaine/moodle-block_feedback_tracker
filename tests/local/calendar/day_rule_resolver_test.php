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
 * Tests for day_rule_resolver.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Verifies how cday rows, weekend mask, and the exclude-* settings combine
 * into the final per-day rule.
 *
 * @covers \block_feedback_tracker\local\calendar\day_rule_resolver
 */
final class day_rule_resolver_test extends \advanced_testcase {
    public function test_weekday_with_no_cday_is_active(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        // Mon 2026-05-18 (dayofweek 0: ISO order, counted from zero).
        $rule = day_rule_resolver::for_date(20260518, 0);

        $this->assertSame(calendar::DAYTYPE_IMPLICIT, $rule['type']);
        $this->assertFalse($rule['is_weekend']);
        $this->assertTrue($rule['is_active']);
        $this->assertNull($rule['day_note']);
        $this->assertSame([[480, 1080]], $rule['business_hours']);
    }

    public function test_weekend_without_cday_is_inactive_when_excluded(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        // Sat 2026-05-16 (dayofweek 5).
        $rule = day_rule_resolver::for_date(20260516, 5);

        $this->assertSame(calendar::DAYTYPE_IMPLICIT, $rule['type']);
        $this->assertTrue($rule['is_weekend']);
        $this->assertFalse($rule['is_active']);
    }

    public function test_weekend_becomes_active_when_weekends_not_excluded(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        set_config('excludeweekends', '0', 'block_feedback_tracker');
        day_rule_resolver::reset_memo();

        $rule = day_rule_resolver::for_date(20260516, 5);

        $this->assertTrue($rule['is_active']);
    }

    public function test_holiday_on_a_weekday_is_inactive(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->insert_cday(20260518, 'holiday', 'Test holiday');
        day_rule_resolver::reset_memo();

        $rule = day_rule_resolver::for_date(20260518, 0);

        $this->assertSame('holiday', $rule['type']);
        $this->assertFalse($rule['is_active']);
        $this->assertSame('Test holiday', $rule['day_note']);
    }

    public function test_schoolday_overrides_weekend(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->insert_cday(20260516, 'schoolday', 'Saturday make-up');
        day_rule_resolver::reset_memo();

        $rule = day_rule_resolver::for_date(20260516, 5);

        $this->assertSame('schoolday', $rule['type']);
        $this->assertTrue($rule['is_weekend']);
        $this->assertTrue($rule['is_active']);
    }

    public function test_closed_day_is_never_active(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->insert_cday(20260518, 'closed', null);
        day_rule_resolver::reset_memo();

        $rule = day_rule_resolver::for_date(20260518, 0);

        $this->assertSame('closed', $rule['type']);
        $this->assertFalse($rule['is_active']);
    }

    public function test_optional_day_is_inactive_by_default(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->insert_cday(20260518, 'optional', null);
        day_rule_resolver::reset_memo();

        $rule = day_rule_resolver::for_date(20260518, 0);

        $this->assertFalse($rule['is_active']);
        $this->assertNull($rule['optional_window']);
    }

    public function test_sub_day_optional_event_surfaces_window(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        global $DB;
        // Mon 2026-05-18 — optional 16:00-18:00, "Brasil vs Franca".
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => 20260518, 'daytype' => 'optional',
            'starttime' => 16 * 60, 'endtime' => 18 * 60,
            'note' => 'Brasil vs Franca',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        day_rule_resolver::reset_memo();

        $rule = day_rule_resolver::for_date(20260518, 0);

        // Day is otherwise active per the weekly schedule.
        $this->assertTrue($rule['is_active']);
        $this->assertSame('optional', $rule['type']);
        $this->assertNotNull($rule['optional_window']);
        $this->assertSame(16 * 60, $rule['optional_window']['startmin']);
        $this->assertSame(18 * 60, $rule['optional_window']['endmin']);
        $this->assertSame('Brasil vs Franca', $rule['optional_window']['note']);
    }

    public function test_holiday_becomes_active_when_holidays_not_excluded(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        set_config('excludeholidays', '0', 'block_feedback_tracker');
        $this->insert_cday(20260518, 'holiday', null);
        day_rule_resolver::reset_memo();

        $rule = day_rule_resolver::for_date(20260518, 0);

        $this->assertTrue($rule['is_active']);
    }

    public function test_business_hours_disabled_returns_full_day_window(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        set_config('enablebusinesshours', '0', 'block_feedback_tracker');
        day_rule_resolver::reset_memo();

        $rule = day_rule_resolver::for_date(20260518, 0);

        $this->assertSame([[0, 24 * 60]], $rule['business_hours']);
    }

    public function test_memo_returns_cached_result(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        $a = day_rule_resolver::for_date(20260518, 0);
        $b = day_rule_resolver::for_date(20260518, 0);
        $this->assertSame($a, $b);
    }

    // Helpers.

    /**
     * Helper to seed standard calendar settings and business hours.
     *
     * @return void
     */
    private function seed_calendar(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');

        global $DB;
        $now = time();
        for ($dow = 0; $dow <= 4; $dow++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek' => $dow, 'starttime' => 480, 'endtime' => 1080,
                'enabled' => 1, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }

        day_rule_resolver::reset_memo();
        business_hours_lookup::reset_memo();
    }

    /**
     * Helper to insert a custom academic calendar day.
     *
     * @param int $daydate The day date in YYYYMMDD.
     * @param string $daytype The day type.
     * @param string|null $note The optional comment note.
     * @return void
     */
    private function insert_cday(int $daydate, string $daytype, ?string $note): void {
        global $DB;
        $now = time();
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => $daydate, 'daytype' => $daytype, 'note' => $note,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }
}
