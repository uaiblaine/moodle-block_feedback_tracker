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
 * Tests for save_calendar_day input validation + capability gates.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

/**
 * Tests for saving academic calendar day data via external functions.
 *
 * @covers \block_feedback_tracker\external\save_calendar_day
 */
final class save_calendar_day_test extends \advanced_testcase {
    /**
     * Test that invalid daydate is rejected.
     */
    public function test_invalid_daydate_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        save_calendar_day::execute(20260230, 'holiday', '');
    }

    /**
     * Test that unknown daytype is rejected.
     */
    public function test_unknown_daytype_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        save_calendar_day::execute(20260101, 'banana', '');
    }

    /**
     * Removing a date that has no row changes nothing, so it must not trigger
     * the site-wide recompute that cal_day_updated causes. Removing a date that
     * does have a row is the control: it re-enqueues the seeded rollup and
     * bumps calver, which proves the event path is live in this test.
     *
     * @return void
     */
    public function test_removing_a_day_without_a_row_requeues_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $course = $generator->create_tracked_course();
        $generator->create_rollup_row(['courseid' => (int) $course->id]);
        $DB->delete_records('block_feedback_tracker_queue');
        $calver = \block_feedback_tracker\local\calendar\calendar::current_version();

        $result = save_calendar_day::execute(20260525, save_calendar_day::ACTION_REMOVE, '');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['id']);
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_queue'));
        $this->assertSame($calver, \block_feedback_tracker\local\calendar\calendar::current_version());

        $generator->create_calendar_day(20260526, 'holiday');
        save_calendar_day::execute(20260526, save_calendar_day::ACTION_REMOVE, '');

        $this->assertFalse($DB->record_exists('block_feedback_tracker_cday', ['daydate' => 20260526]));
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_queue', ['courseid' => (int) $course->id]));
        $this->assertNotSame($calver, \block_feedback_tracker\local\calendar\calendar::current_version());
    }

    /**
     * Test that unauthorised user is rejected.
     */
    public function test_unauthorised_user_rejected(): void {
        $this->resetAfterTest();
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        $this->expectException(\required_capability_exception::class);
        save_calendar_day::execute(20260101, 'holiday', '');
    }
}
