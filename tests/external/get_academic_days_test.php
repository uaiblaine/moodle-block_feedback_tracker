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
 * Tests for the get_academic_days external function.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\sla\group_access;
use core_external\external_api;

/**
 * Covers the 30-day heatmap series: window length, per-day pause classification
 * (a holiday overrides into a paused day) and per-day band derivation from the
 * trend table.
 *
 * @covers \block_feedback_tracker\external\get_academic_days
 */
final class get_academic_days_test extends \advanced_testcase {
    /**
     * The series spans 30 days, flags a seeded holiday as paused, and colours
     * the academic days from the per-day trend median.
     *
     * @return void
     */
    public function test_window_classifies_paused_and_band_days(): void {
        global $DB;
        $this->resetAfterTest();
        group_access::reset_memo();

        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');

        $course = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        // Seed a per-day trend median for every day in the window so each
        // academic day has data; 5h is well inside the "excellent" band.
        $tz = new \DateTimeZone('UTC');
        $today = (new \DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
        for ($i = 29; $i >= 0; $i--) {
            $ymd = (int) $today->modify("-{$i} days")->format('Ymd');
            $DB->insert_record('block_feedback_tracker_trend', (object) [
                'courseid'    => (int) $course->id,
                'groupid'     => 0,
                'day'         => $ymd,
                'medianh_eff' => 5.0,
                'medianh_raw' => 6.0,
                'numgraded'   => 1,
                'timemodified' => time(),
            ]);
        }

        // A holiday five days ago is always inside the window.
        $holidayymd = (int) $today->modify('-5 days')->format('Ymd');
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => $holidayymd, 'daytype' => 'holiday',
            'note' => null, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $this->setUser($teacher);
        $result = external_api::clean_returnvalue(
            get_academic_days::execute_returns(),
            get_academic_days::execute((int) $course->id)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, (int) $result['groupid']);
        $this->assertCount(30, $result['days']);

        // Index the series by day for direct assertions.
        $byday = [];
        foreach ($result['days'] as $day) {
            $byday[(int) $day['ymd']] = $day;
        }
        $this->assertArrayHasKey($holidayymd, $byday);
        $this->assertTrue((bool) $byday[$holidayymd]['paused']);
        $this->assertSame('holiday', $byday[$holidayymd]['reason']);
        $this->assertGreaterThanOrEqual(1, (int) $result['summary']['holiday']);

        // Every academic (non-paused) day is coloured from its 5h median.
        foreach ($result['days'] as $day) {
            if ($day['paused']) {
                $this->assertSame('', $day['band']);
            } else {
                $this->assertSame('excellent', $day['band']);
            }
        }
    }

    /**
     * A teacher restricted to separate groups with no group membership gets a
     * well-formed empty series rather than leaked data.
     *
     * @return void
     */
    public function test_separate_groups_without_membership_is_empty(): void {
        $this->resetAfterTest();
        group_access::reset_memo();
        set_config('timezone', 'UTC', 'block_feedback_tracker');

        $course = $this->getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $coursectx = \context_course::instance($course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        // A non-editing teacher has no accessallgroups and belongs to no group.
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');

        $this->setUser($teacher);
        $result = external_api::clean_returnvalue(
            get_academic_days::execute_returns(),
            get_academic_days::execute((int) $course->id)
        );

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['days']);
    }

    /**
     * A student holds no viewresponsiveness capability in the course.
     *
     * @return void
     */
    public function test_student_is_refused(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        get_academic_days::execute((int) $course->id);
    }
}
