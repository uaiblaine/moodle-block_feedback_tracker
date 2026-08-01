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
 * Tests for the save_pause_window external function.
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
 * The write path here is capability-gated at a context the caller supplies,
 * so the authorisation tests matter more than the happy paths.
 *
 * @covers \block_feedback_tracker\external\save_pause_window
 */
final class save_pause_window_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * A course plus an editing teacher enrolled in it.
     *
     * @return array [course, teacher]
     */
    private function course_with_teacher(): array {
        $course = $this->generator()->create_tracked_course();
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        return [$course, $teacher];
    }

    /**
     * A site-scope pause window can be created by an admin, and every field
     * lands where the caller put it.
     *
     * @return void
     */
    public function test_creates_site_scope_window(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        $raw = save_pause_window::execute(0, 'site', 0, 'closure', $now + 86400, $now + 172800, 'Summer break');
        $result = external_api::clean_returnvalue(save_pause_window::execute_returns(), $raw);

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['id']);

        $row = $DB->get_record('block_feedback_tracker_cpause', ['id' => $result['id']], '*', MUST_EXIST);
        $this->assertSame('site', $row->scopelevel);
        $this->assertSame(0, (int) $row->scopeid);
        $this->assertSame((int) \context_system::instance()->id, (int) $row->contextid);
        $this->assertSame('closure', $row->reason);
        $this->assertSame($now + 86400, (int) $row->timestart);
        $this->assertSame($now + 172800, (int) $row->timeend);
        $this->assertSame('Summer break', $row->note);
        $this->assertSame((int) $USER->id, (int) $row->usermodified);
    }

    /**
     * An open-ended window, an empty note and an empty reason all coerce to
     * their documented storage values rather than being stored literally.
     *
     * @return void
     */
    public function test_open_ended_and_empty_values_coerce(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        $raw = save_pause_window::execute(0, 'site', 0, '', $now + 3600, 0, '   ');
        $result = external_api::clean_returnvalue(save_pause_window::execute_returns(), $raw);

        $row = $DB->get_record('block_feedback_tracker_cpause', ['id' => $result['id']], '*', MUST_EXIST);
        $this->assertNull($row->timeend);
        $this->assertNull($row->note);
        $this->assertSame('other', $row->reason);
    }

    /**
     * A group scope deliberately stores the COURSE context, not a group one.
     *
     * @return void
     */
    public function test_group_scope_stores_the_course_context(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->generator()->create_tracked_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $now = time();
        $raw = save_pause_window::execute(0, 'group', (int) $group->id, 'other', $now + 60, $now + 120, '');
        $result = external_api::clean_returnvalue(save_pause_window::execute_returns(), $raw);

        $row = $DB->get_record('block_feedback_tracker_cpause', ['id' => $result['id']], '*', MUST_EXIST);
        $this->assertSame('group', $row->scopelevel);
        $this->assertSame((int) $group->id, (int) $row->scopeid);
        $this->assertSame((int) \context_course::instance($course->id)->id, (int) $row->contextid);
    }

    /**
     * An editing teacher may manage pause windows inside their own course.
     * This is the positive control for the archetype grant in db/access.php.
     *
     * @return void
     */
    public function test_editing_teacher_may_create_window_in_own_course(): void {
        global $DB;
        $this->resetAfterTest();

        [$course, $teacher] = $this->course_with_teacher();
        $this->setUser($teacher);

        $now = time();
        $raw = save_pause_window::execute(0, 'course', (int) $course->id, 'other', $now + 60, $now + 120, '');
        $result = external_api::clean_returnvalue(save_pause_window::execute_returns(), $raw);

        $row = $DB->get_record('block_feedback_tracker_cpause', ['id' => $result['id']], '*', MUST_EXIST);
        $this->assertSame((int) $course->id, (int) $row->scopeid);
        $this->assertSame((int) \context_course::instance($course->id)->id, (int) $row->contextid);
    }

    /**
     * An update by an authorised user keeps the row id and rewrites the fields.
     *
     * @return void
     */
    public function test_update_preserves_row_id(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->generator()->create_pause_window([]);
        $now = time();

        $raw = save_pause_window::execute($id, 'site', 0, 'vacancy', $now + 60, $now + 120, 'Updated');
        $result = external_api::clean_returnvalue(save_pause_window::execute_returns(), $raw);

        $this->assertSame($id, (int) $result['id']);
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_cpause'));
        $row = $DB->get_record('block_feedback_tracker_cpause', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('vacancy', $row->reason);
        $this->assertSame('Updated', $row->note);
    }

    /**
     * A student has no business managing pause windows.
     *
     * Every parameter here is deliberately VALID: the guard clauses run before
     * require_capability(), so a bad timestamp would throw
     * invalid_parameter_exception and the capability gate would go untested.
     *
     * @return void
     */
    public function test_student_cannot_create_course_window(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course();
        $student = $this->generator()->create_user_in_role((int) $course->id, 'student');
        $this->setUser($student);

        $now = time();
        $this->expectException(\required_capability_exception::class);
        save_pause_window::execute(0, 'course', (int) $course->id, 'other', $now + 60, $now + 120, '');
    }

    /**
     * The course-level grant must not reach the system context.
     *
     * @return void
     */
    public function test_editing_teacher_cannot_create_site_window(): void {
        $this->resetAfterTest();

        [, $teacher] = $this->course_with_teacher();
        $this->setUser($teacher);

        $now = time();
        $this->expectException(\required_capability_exception::class);
        save_pause_window::execute(0, 'site', 0, 'other', $now + 60, $now + 120, '');
    }

    /**
     * A teacher in course A cannot write a window scoped to course B.
     *
     * The teacher is enrolled in B as a student on purpose: with no enrolment
     * at all, validate_context() calls require_login() first and throws
     * require_login_exception, so the test would pass without ever reaching
     * the capability check it exists to verify.
     *
     * @return void
     */
    public function test_teacher_cannot_create_window_in_another_course(): void {
        $this->resetAfterTest();

        [, $teacher] = $this->course_with_teacher();
        $other = $this->generator()->create_tracked_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $other->id, 'student');
        $this->setUser($teacher);

        $now = time();
        $this->expectException(\required_capability_exception::class);
        save_pause_window::execute(0, 'course', (int) $other->id, 'other', $now + 60, $now + 120, '');
    }

    /**
     * The IDOR regression: updating an existing row must be authorised against
     * the context that row already lives in, not against the scope the caller
     * asks for.
     *
     * Without the fix the capability is checked at the teacher's own course
     * context — which passes — and a site-wide pause window is silently
     * re-scoped into their course, corrupting effective-hours computation for
     * every course on the site.
     *
     * @return void
     */
    public function test_editing_teacher_cannot_hijack_a_site_scope_window(): void {
        global $DB;
        $this->resetAfterTest();

        $siteid = $this->generator()->create_pause_window([]);
        [$course, $teacher] = $this->course_with_teacher();
        $this->setUser($teacher);

        $now = time();
        try {
            save_pause_window::execute($siteid, 'course', (int) $course->id, 'other', $now + 60, $now + 120, 'stolen');
            $this->fail('Updating a site-scope row from a course context must raise required_capability_exception.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }

        $row = $DB->get_record('block_feedback_tracker_cpause', ['id' => $siteid], '*', MUST_EXIST);
        $this->assertSame('site', $row->scopelevel, 'The row must not have been re-scoped.');
        $this->assertSame((int) \context_system::instance()->id, (int) $row->contextid);
        $this->assertNotSame('stolen', (string) $row->note);
    }

    /**
     * The same rule across courses: a teacher of course A cannot repoint a
     * window belonging to course B, even though they hold the capability in A.
     *
     * The teacher is enrolled in B as a student so the rejection comes from
     * the capability check rather than from require_login() — otherwise this
     * would pass without exercising the gate under test.
     *
     * @return void
     */
    public function test_teacher_cannot_hijack_another_courses_window(): void {
        global $DB;
        $this->resetAfterTest();

        [$mine, $teacher] = $this->course_with_teacher();
        $other = $this->generator()->create_tracked_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $other->id, 'student');
        $otherid = $this->generator()->create_pause_window([
            'scopelevel' => 'course',
            'scopeid' => (int) $other->id,
            'contextid' => (int) \context_course::instance($other->id)->id,
        ]);

        $this->setUser($teacher);
        $now = time();

        try {
            save_pause_window::execute($otherid, 'course', (int) $mine->id, 'other', $now + 60, $now + 120, '');
            $this->fail('Repointing another course\'s row must raise required_capability_exception.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }

        $row = $DB->get_record('block_feedback_tracker_cpause', ['id' => $otherid], '*', MUST_EXIST);
        $this->assertSame((int) $other->id, (int) $row->scopeid, 'The row must still belong to the other course.');
    }

    /**
     * A caller with no access to the row's course at all is refused before the
     * capability check, by require_login(). Different exception, same outcome:
     * the row is untouched.
     *
     * @return void
     */
    public function test_unenrolled_teacher_cannot_touch_another_courses_window(): void {
        global $DB;
        $this->resetAfterTest();

        [$mine, $teacher] = $this->course_with_teacher();
        $other = $this->generator()->create_tracked_course();
        $otherid = $this->generator()->create_pause_window([
            'scopelevel' => 'course',
            'scopeid' => (int) $other->id,
            'contextid' => (int) \context_course::instance($other->id)->id,
        ]);

        $this->setUser($teacher);
        $now = time();

        $this->expectException(\moodle_exception::class);
        try {
            save_pause_window::execute($otherid, 'course', (int) $mine->id, 'other', $now + 60, $now + 120, '');
        } finally {
            $row = $DB->get_record('block_feedback_tracker_cpause', ['id' => $otherid], '*', MUST_EXIST);
            $this->assertSame((int) $other->id, (int) $row->scopeid);
        }
    }

    /**
     * An unknown scope level is rejected. 'banana' is pure alpha on purpose —
     * a value like 'group1' is stripped by PARAM_ALPHA before the in_array()
     * guard under test is ever reached.
     *
     * @return void
     */
    public function test_unknown_scopelevel_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        $this->expectException(\invalid_parameter_exception::class);
        save_pause_window::execute(0, 'banana', 0, 'other', $now + 60, $now + 120, '');
    }

    /**
     * timestart must be positive, and timeend must be strictly after it —
     * equal timestamps are rejected because the guard is <=, not <.
     *
     * @return void
     */
    public function test_rejects_non_positive_start(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        save_pause_window::execute(0, 'site', 0, 'other', 0, time() + 60, '');
    }

    /**
     * Equal start and end timestamps are not a zero-length window, they are an
     * error.
     *
     * @return void
     */
    public function test_rejects_end_equal_to_start(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time() + 60;
        $this->expectException(\invalid_parameter_exception::class);
        save_pause_window::execute(0, 'site', 0, 'other', $now, $now, '');
    }
}
