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
 * Tests for the delete_pause_window external function.
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
 * This function already derives its context from the stored row rather than
 * from anything the caller supplies — the shape save_pause_window had to be
 * corrected to. These tests pin that so it stays that way.
 *
 * @covers \block_feedback_tracker\external\delete_pause_window
 */
final class delete_pause_window_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * An administrator deletes a site-scope window and the row goes.
     *
     * @return void
     */
    public function test_admin_deletes_site_window(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->generator()->create_pause_window([]);

        $raw = delete_pause_window::execute($id);
        $result = external_api::clean_returnvalue(delete_pause_window::execute_returns(), $raw);

        $this->assertTrue($result['success']);
        $this->assertSame($id, (int) $result['id']);
        $this->assertFalse($DB->record_exists('block_feedback_tracker_cpause', ['id' => $id]));
    }

    /**
     * The delete fires cal_pause_updated so the observer re-enqueues the
     * affected tuples — without it the rollups keep the removed pause folded
     * into their effective hours.
     *
     * @return void
     */
    public function test_delete_fires_the_calendar_event(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->generator()->create_pause_window([]);

        $sink = $this->redirectEvents();
        delete_pause_window::execute($id);
        $events = $sink->get_events();
        $sink->close();

        $found = false;
        foreach ($events as $e) {
            if ($e instanceof \block_feedback_tracker\event\cal_pause_updated) {
                $found = true;
                $this->assertTrue((bool) ($e->other['deleted'] ?? false));
                $this->assertSame($id, (int) ($e->other['rowid'] ?? 0));
            }
        }
        $this->assertTrue($found, 'Deleting a pause window must fire cal_pause_updated.');
    }

    /**
     * An editing teacher may remove a window scoped to their own course.
     *
     * @return void
     */
    public function test_editing_teacher_deletes_own_course_window(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course();
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        $id = $this->generator()->create_pause_window([
            'scopelevel' => 'course',
            'scopeid' => (int) $course->id,
            'contextid' => (int) \context_course::instance($course->id)->id,
        ]);

        $this->setUser($teacher);
        delete_pause_window::execute($id);

        $this->assertFalse($DB->record_exists('block_feedback_tracker_cpause', ['id' => $id]));
    }

    /**
     * The gate is evaluated at the row's own context, so a course-level grant
     * cannot reach a site-scope window.
     *
     * @return void
     */
    public function test_editing_teacher_cannot_delete_a_site_window(): void {
        global $DB;
        $this->resetAfterTest();

        $id = $this->generator()->create_pause_window([]);
        $course = $this->generator()->create_tracked_course();
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        $this->setUser($teacher);

        try {
            delete_pause_window::execute($id);
            $this->fail('A course-level grant must not delete a site-scope window.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }

        $this->assertTrue($DB->record_exists('block_feedback_tracker_cpause', ['id' => $id]));
    }

    /**
     * A student holds no such capability anywhere.
     *
     * @return void
     */
    public function test_student_cannot_delete(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course();
        $id = $this->generator()->create_pause_window([
            'scopelevel' => 'course',
            'scopeid' => (int) $course->id,
            'contextid' => (int) \context_course::instance($course->id)->id,
        ]);
        $student = $this->generator()->create_user_in_role((int) $course->id, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        delete_pause_window::execute($id);
    }

    /**
     * Deleting something that is not there is an error, not a silent success.
     *
     * @return void
     */
    public function test_unknown_id_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\dml_missing_record_exception::class);
        delete_pause_window::execute(999999);
    }
}
