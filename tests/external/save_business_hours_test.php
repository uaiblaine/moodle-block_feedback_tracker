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
 * Tests for the save_business_hours external function.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use core_external\external_api;

/**
 * A site-wide write that replaces a whole weekday's slots inside a
 * transaction, so the validation boundaries matter: a bad slot must abort
 * before anything is deleted.
 *
 * @covers \block_feedback_tracker\external\save_business_hours
 */
final class save_business_hours_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * Slots for one weekday, ordered and stamped with the acting user.
     *
     * @return void
     */
    public function test_admin_saves_slots(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $raw = save_business_hours::execute(2, [
            ['starttime' => 480, 'endtime' => 720],
            ['starttime' => 780, 'endtime' => 1080],
        ]);
        $result = external_api::clean_returnvalue(save_business_hours::execute_returns(), $raw);

        $this->assertTrue($result['success']);
        $rows = array_values($DB->get_records('block_feedback_tracker_chours', ['dayofweek' => 2], 'starttime ASC'));
        $this->assertCount(2, $rows);
        $this->assertSame(480, (int) $rows[0]->starttime);
        $this->assertSame(1080, (int) $rows[1]->endtime);
        $this->assertSame((int) $USER->id, (int) $rows[0]->usermodified);
    }

    /**
     * The save replaces the weekday wholesale rather than appending.
     *
     * @return void
     */
    public function test_save_replaces_existing_slots_for_that_day(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        save_business_hours::execute(3, [['starttime' => 480, 'endtime' => 1080]]);
        save_business_hours::execute(3, [['starttime' => 600, 'endtime' => 900]]);

        $rows = $DB->get_records('block_feedback_tracker_chours', ['dayofweek' => 3]);
        $this->assertCount(1, $rows);
        $this->assertSame(600, (int) reset($rows)->starttime);
    }

    /**
     * An empty slot list clears the day — that is how a weekday is marked
     * non-working.
     *
     * @return void
     */
    public function test_empty_slot_list_clears_the_day(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        save_business_hours::execute(4, [['starttime' => 480, 'endtime' => 1080]]);
        save_business_hours::execute(4, []);

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_chours', ['dayofweek' => 4]));
    }

    /**
     * Writing fires cal_hours_updated so the calendar version bumps and the
     * rollups recompute.
     *
     * @return void
     */
    public function test_save_fires_the_calendar_event(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $sink = $this->redirectEvents();
        save_business_hours::execute(1, [['starttime' => 540, 'endtime' => 1020]]);
        $events = $sink->get_events();
        $sink->close();

        $found = false;
        foreach ($events as $e) {
            if ($e instanceof \block_feedback_tracker\event\cal_hours_updated) {
                $found = true;
                $this->assertSame(1, (int) ($e->other['dayofweek'] ?? -1));
            }
        }
        $this->assertTrue($found, 'Saving business hours must fire cal_hours_updated.');
    }

    /**
     * Weekday indexes outside 0..6 are rejected.
     *
     * @return void
     */
    public function test_out_of_range_weekday_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        save_business_hours::execute(7, [['starttime' => 480, 'endtime' => 1080]]);
    }

    /**
     * A slot that ends before it starts is rejected, and equality counts as
     * an error too since the guard is <=.
     *
     * @return void
     */
    public function test_zero_length_slot_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        save_business_hours::execute(1, [['starttime' => 600, 'endtime' => 600]]);
    }

    /**
     * Overlapping slots are rejected regardless of the order they arrive in —
     * the list is sorted before the comparison.
     *
     * @return void
     */
    public function test_overlapping_slots_rejected_when_supplied_out_of_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        save_business_hours::execute(1, [
            ['starttime' => 700, 'endtime' => 900],
            ['starttime' => 480, 'endtime' => 800],
        ]);
    }

    /**
     * Slots that merely touch are legal — 09:00-12:00 followed by 12:00-18:00
     * is a lunch-free day, not an overlap.
     *
     * @return void
     */
    public function test_touching_slots_are_allowed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        save_business_hours::execute(5, [
            ['starttime' => 540, 'endtime' => 720],
            ['starttime' => 720, 'endtime' => 1080],
        ]);

        $this->assertSame(2, $DB->count_records('block_feedback_tracker_chours', ['dayofweek' => 5]));
    }

    /**
     * A rejected slot must not have destroyed the day's existing rows on its
     * way out.
     *
     * @return void
     */
    public function test_invalid_slot_leaves_existing_rows_intact(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        save_business_hours::execute(6, [['starttime' => 480, 'endtime' => 1080]]);

        try {
            save_business_hours::execute(6, [['starttime' => 900, 'endtime' => 600]]);
            $this->fail('An inverted slot must be rejected.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        }

        $rows = $DB->get_records('block_feedback_tracker_chours', ['dayofweek' => 6]);
        $this->assertCount(1, $rows, 'Validation must abort before the delete-and-reinsert.');
        $this->assertSame(480, (int) reset($rows)->starttime);
    }

    /**
     * The capability is required, and it lives at system context.
     *
     * @return void
     */
    public function test_teacher_without_managecalendar_is_refused(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course();
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);
        save_business_hours::execute(1, [['starttime' => 480, 'endtime' => 1080]]);
    }
}
