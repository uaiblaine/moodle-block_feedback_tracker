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
 * Tests for the get_insights external function.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\sla\dashboard_scope;
use core_external\external_api;

/**
 * The only one of the seventeen functions that never calls
 * require_capability(). It authorises through dashboard_scope instead — an
 * empty visible-course scope is the refusal — so that bespoke gate is exactly
 * what needs pinning.
 *
 * Every test resets the scope memo: it is static and keyed by userid, and
 * PHPUnit recycles user ids between tests, so a stale entry silently answers
 * for a different user.
 *
 * @covers \block_feedback_tracker\external\get_insights
 */
final class get_insights_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * Call the function and clean the return value.
     *
     * @return array
     */
    private function call(): array {
        dashboard_scope::reset_memo();
        return external_api::clean_returnvalue(get_insights::execute_returns(), get_insights::execute());
    }

    /**
     * With the admin view-all escape hatch on, a site admin sees the whole
     * site and gets a well-formed payload.
     *
     * @return void
     */
    public function test_admin_with_view_all_enabled_receives_a_payload(): void {
        $this->resetAfterTest();
        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');
        $this->setAdminUser();

        $result = $this->call();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('lastsynced', $result);
        /* The three insight slots are VALUE_OPTIONAL, so an empty site omits
         * them entirely rather than returning nulls — assert the envelope,
         * not picks that need seeded data to exist. */
        foreach (['bright_spot', 'most_improved', 'gentle_watch'] as $slot) {
            if (array_key_exists($slot, $result)) {
                $this->assertArrayHasKey('courseid', $result[$slot]);
            }
        }
    }

    /**
     * With the setting off — the default — a site admin is deliberately
     * scoped like any other user. An admin with no enrolments therefore sees
     * nothing and is refused, which is the documented intent of
     * dashboard_scope rather than an accident.
     *
     * @return void
     */
    public function test_admin_without_view_all_is_scoped_like_a_normal_user(): void {
        $this->resetAfterTest();
        set_config('enable_admin_view_all', 0, 'block_feedback_tracker');
        $this->setAdminUser();

        dashboard_scope::reset_memo();
        $this->expectException(\required_capability_exception::class);
        get_insights::execute();
    }

    /**
     * A role granting viewalldata at system context is the assignable way in,
     * and it does not depend on being an admin.
     *
     * @return void
     */
    public function test_viewalldata_role_is_allowed(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'block/feedback_tracker:viewalldata',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id,
            true
        );
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        $result = $this->call();

        $this->assertTrue($result['success']);
    }

    /**
     * A teacher with a course in scope is allowed through.
     *
     * @return void
     */
    public function test_teacher_with_a_course_is_allowed(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course();
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        $this->setUser($teacher);

        $result = $this->call();

        $this->assertTrue($result['success']);
    }

    /**
     * The bespoke gate: a user whose visible-course scope is empty is refused.
     * With no require_capability() call in this function, this branch is the
     * only thing between an arbitrary logged-in user and the cross-course
     * insight pool.
     *
     * @return void
     */
    public function test_user_with_empty_scope_is_refused(): void {
        $this->resetAfterTest();

        $nobody = $this->getDataGenerator()->create_user();
        $this->setUser($nobody);

        dashboard_scope::reset_memo();
        $this->expectException(\required_capability_exception::class);
        get_insights::execute();
    }

    /**
     * Enrolment alone is not scope — a student holds no dashboard capability,
     * so their visible-course list is empty.
     *
     * @return void
     */
    public function test_enrolled_student_is_refused(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course();
        $student = $this->generator()->create_user_in_role((int) $course->id, 'student');
        $this->setUser($student);

        dashboard_scope::reset_memo();
        $this->expectException(\required_capability_exception::class);
        get_insights::execute();
    }

    /**
     * The payload is cached per user with the language in the key, so two
     * consecutive calls agree.
     *
     * @return void
     */
    public function test_repeated_calls_are_consistent(): void {
        $this->resetAfterTest();
        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');
        $this->setAdminUser();

        $first = $this->call();
        $second = $this->call();

        $this->assertSame($first, $second);
    }
}
