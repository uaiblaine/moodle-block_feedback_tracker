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
 * Tests for the dashboard read-path scope helper.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Covers which courses a user may see on the dashboard — teacher-or-higher
 * enrolment, with the site-admin view-all opt-in.
 *
 * @covers \block_feedback_tracker\local\sla\dashboard_scope
 */
final class dashboard_scope_test extends \advanced_testcase {
    /**
     * An active enrolment with a teacher-or-higher role puts the course in
     * scope.
     *
     * @return void
     */
    public function test_enrolled_teacher_in_scope(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->setUser($teacher);
        dashboard_scope::reset_memo();
        $scope = dashboard_scope::visible_course_ids((int) $teacher->id);

        $this->assertIsArray($scope);
        $this->assertEqualsCanonicalizing([(int) $course->id], $scope);
    }

    /**
     * Active enrolment without a teacher-or-higher role (e.g. a student)
     * does NOT put the course in scope — the dashboard is for graders.
     *
     * @return void
     */
    public function test_enrolled_student_not_in_scope(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $this->setUser($student);
        dashboard_scope::reset_memo();
        $scope = dashboard_scope::visible_course_ids((int) $student->id);

        $this->assertSame([], $scope);
    }

    /**
     * A site admin with enable_admin_view_all on is unrestricted (null).
     *
     * @return void
     */
    public function test_admin_view_all_on_is_unrestricted(): void {
        $this->resetAfterTest();
        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');

        $this->setAdminUser();
        global $USER;
        dashboard_scope::reset_memo();

        $this->assertNull(dashboard_scope::visible_course_ids((int) $USER->id));
    }

    /**
     * A site admin with enable_admin_view_all off is scoped like a normal
     * user: with no teaching enrolment they see nothing.
     *
     * @return void
     */
    public function test_admin_view_all_off_treated_as_normal(): void {
        $this->resetAfterTest();
        // Default: enable_admin_view_all is off.

        $this->setAdminUser();
        global $USER;
        dashboard_scope::reset_memo();

        $this->assertSame([], dashboard_scope::visible_course_ids((int) $USER->id));
    }

    /**
     * A non-admin granted block/feedback_tracker:viewalldata at system context
     * sees every course (null = unrestricted) — with no teaching enrolment and
     * without the enable_admin_view_all admin setting. This is the role-based
     * "see everything" grant teachers/coordinators can be assigned.
     *
     * @return void
     */
    public function test_viewalldata_capability_is_unrestricted(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();

        $syscontext = \context_system::instance();
        $roleid = $generator->create_role();
        assign_capability('block/feedback_tracker:viewalldata', CAP_ALLOW, $roleid, $syscontext->id);
        role_assign($roleid, $user->id, $syscontext->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($user);
        dashboard_scope::reset_memo();

        $this->assertNull(dashboard_scope::visible_course_ids((int) $user->id));
    }

    /**
     * A normal site admin (doanything) does NOT auto-pass viewalldata: the
     * capability is checked with doanything suppressed, so the legacy
     * enable_admin_view_all setting remains the only admin escape hatch. With
     * the setting off and no role grant, an unenrolled admin still sees nothing.
     *
     * @return void
     */
    public function test_viewalldata_not_auto_granted_to_admin_doanything(): void {
        $this->resetAfterTest();
        // Default: enable_admin_view_all is off, no viewalldata role assignment.

        $this->setAdminUser();
        global $USER;
        dashboard_scope::reset_memo();

        $this->assertSame([], dashboard_scope::visible_course_ids((int) $USER->id));
    }
}
