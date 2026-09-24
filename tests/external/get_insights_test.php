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
 * Tests for get_insights.
 *
 * Like get_dashboard and get_grader_priority_list, this function never calls
 * require_capability(): it authorises through dashboard_scope, where an empty
 * visible-course scope is the refusal. These tests pin that gate.
 *
 * Every test resets the scope memo: it is a PHP static keyed by userid, and
 * user ids are reused between tests, so a stale entry would answer for a
 * different user.
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
     * With the setting off (the default) a site admin is scoped like any
     * other user, so an admin with no enrolments sees nothing and is refused
     * ({@see dashboard_scope::visible_course_ids()}).
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
     * Course and group names reach the caller filtered, in the plain spelling:
     * the multilang filter picks the English half and the ampersand is not
     * escaped.
     *
     * @return void
     */
    public function test_names_are_filtered_and_plain(): void {
        $this->resetAfterTest();
        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_applies_to_strings('multilang', true);
        \filter_manager::reset_caches();

        $multilang = '<span lang="en" class="multilang">A & B</span><span lang="es" class="multilang">C & D</span>';
        $course = $this->generator()->create_tracked_course(['fullname' => $multilang]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => $multilang]);
        $this->generator()->create_rollup_row([
            'courseid' => (int) $course->id,
            'groupid' => (int) $group->id,
            'pending' => 2,
            'critical' => 2,
            'responsiveness_score' => 80.0,
            'score_band' => 'good',
        ]);
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        $this->setUser($teacher);
        dashboard_scope::reset_memo();
        $_POST['sesskey'] = sesskey();

        $response = external_api::call_external_function('block_feedback_tracker_get_insights', []);

        $this->assertFalse($response['error'], json_encode($response['exception'] ?? null));
        foreach (['bright_spot', 'gentle_watch'] as $slot) {
            $this->assertArrayHasKey($slot, $response['data']);
            $this->assertSame((int) $group->id, $response['data'][$slot]['groupid']);
            $this->assertSame('A & B', $response['data'][$slot]['coursename'], $slot);
            $this->assertSame('A & B', $response['data'][$slot]['groupname'], $slot);
        }
    }

    /**
     * Two groups share the top score: the one with more graded submissions in
     * the rollup window is the bright spot. The smaller one is inserted first,
     * so an order-only pick would name it.
     *
     * @return void
     */
    public function test_bright_spot_tie_goes_to_more_graded(): void {
        $this->resetAfterTest();
        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');
        $this->setAdminUser();

        $course = $this->generator()->create_tracked_course();
        $fewer = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $more = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->generator()->create_rollup_row([
            'courseid' => (int) $course->id,
            'groupid' => (int) $fewer->id,
            'numgraded30d' => 3,
            'responsiveness_score' => 80.0,
            'score_band' => 'good',
        ]);
        $this->generator()->create_rollup_row([
            'courseid' => (int) $course->id,
            'groupid' => (int) $more->id,
            'numgraded30d' => 9,
            'responsiveness_score' => 80.0,
            'score_band' => 'good',
        ]);

        $result = $this->call();

        $this->assertSame((int) $more->id, $result['bright_spot']['groupid']);
    }

    /**
     * The gentle watch counts with the banding ruler, like the courses table:
     * hours mode reads critical, business-days mode critical_days. Switching
     * the unit between two calls also shows the ruler is part of the cache key.
     *
     * @return void
     */
    public function test_gentle_watch_follows_the_display_unit(): void {
        $this->resetAfterTest();
        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');
        $this->setAdminUser();

        $course = $this->generator()->create_tracked_course();
        $hourly = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $daily = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->generator()->create_rollup_row([
            'courseid' => (int) $course->id,
            'groupid' => (int) $hourly->id,
            'pending' => 5,
            'critical' => 5,
            'critical_days' => 1,
        ]);
        $this->generator()->create_rollup_row([
            'courseid' => (int) $course->id,
            'groupid' => (int) $daily->id,
            'pending' => 5,
            'critical' => 2,
            'critical_days' => 4,
        ]);

        $hours = $this->call();
        $this->assertSame((int) $hourly->id, $hours['gentle_watch']['groupid']);
        $this->assertSame('5', $hours['gentle_watch']['metric_value']);

        $this->generator()->set_display_unit('business_days');
        $days = $this->call();
        $this->assertSame((int) $daily->id, $days['gentle_watch']['groupid']);
        $this->assertSame('4', $days['gentle_watch']['metric_value']);
    }

    /**
     * In business-days mode a row whose critical_days is still null (rollup
     * not recomputed since the column arrived) counts with its hour-based
     * critical, the fallback get_dashboard and get_report_scopes use.
     *
     * @return void
     */
    public function test_gentle_watch_falls_back_to_hours_without_day_counts(): void {
        $this->resetAfterTest();
        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');
        $this->setAdminUser();
        $this->generator()->set_display_unit('business_days');

        $course = $this->generator()->create_tracked_course();
        $stale = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $fresh = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->generator()->create_rollup_row([
            'courseid' => (int) $course->id,
            'groupid' => (int) $stale->id,
            'pending' => 5,
            'critical' => 5,
            'critical_days' => null,
        ]);
        $this->generator()->create_rollup_row([
            'courseid' => (int) $course->id,
            'groupid' => (int) $fresh->id,
            'pending' => 5,
            'critical' => 2,
            'critical_days' => 4,
        ]);

        $result = $this->call();

        $this->assertSame((int) $stale->id, $result['gentle_watch']['groupid']);
        $this->assertSame('5', $result['gentle_watch']['metric_value']);
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
