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
 * Tests for the get_calendar / save_calendar_day external functions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use core_external\external_api;

/**
 * Tests for retrieving academic calendar data via external functions.
 *
 * @covers \block_feedback_tracker\external\get_calendar
 * @covers \block_feedback_tracker\external\save_calendar_day
 */
final class get_calendar_test extends \advanced_testcase {
    /**
     * Reading the calendar returns the seeded day rows and the business
     * hours plus the platform settings.
     */
    public function test_get_calendar_returns_days_hours_and_settings(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        $admin = get_admin();
        $this->setUser($admin);

        global $DB;
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate'      => 20260403,
            'daytype'      => 'holiday',
            'note'         => 'Good Friday',
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate'      => 20260406,
            'daytype'      => 'holiday',
            'note'         => 'Easter Monday',
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $result = get_calendar::execute(20260401, 20260430);
        $result = external_api::clean_returnvalue(get_calendar::execute_returns(), $result);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['days']);
        $this->assertSame(20260403, $result['days'][0]['daydate']);
        $this->assertSame('Good Friday', $result['days'][0]['note']);
        $this->assertCount(5, $result['businesshours']);
        $this->assertSame('UTC', $result['settings']['timezone']);
    }

    /**
     * Saving and removing a day works end-to-end via the WS layer.
     */
    public function test_save_calendar_day_upsert_and_remove(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->setUser(get_admin());

        $r1 = save_calendar_day::execute(20260525, 'holiday', 'Memorial Day');
        $r1 = external_api::clean_returnvalue(save_calendar_day::execute_returns(), $r1);
        $this->assertTrue($r1['success']);
        $this->assertGreaterThan(0, $r1['id']);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_cday', ['daydate' => 20260525]);
        $this->assertSame('holiday', $row->daytype);
        $this->assertSame('Memorial Day', $row->note);

        $r2 = save_calendar_day::execute(20260525, 'remove');
        $r2 = external_api::clean_returnvalue(save_calendar_day::execute_returns(), $r2);
        $this->assertTrue($r2['success']);
        $this->assertFalse($DB->record_exists('block_feedback_tracker_cday', ['daydate' => 20260525]));
    }

    /**
     * Seed a minimal calendar config + Mon-Fri 08:00-18:00 business hours.
     */
    private function seed_calendar(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('grading_during_pause_mode', 'clipped', 'block_feedback_tracker');

        global $DB;
        $now = time();
        for ($dow = 0; $dow <= 4; $dow++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek'    => $dow,
                'starttime'    => 480,
                'endtime'      => 1080,
                'enabled'      => 1,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Reading the calendar requires managecalendar at system context.
     *
     * @return void
     */
    public function test_teacher_without_managecalendar_is_refused(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);
        get_calendar::execute(20260601, 20260630);
    }
}
