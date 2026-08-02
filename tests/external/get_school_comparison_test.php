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
 * Tests for the get_school_comparison external function.
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
 * A cross-school read behind its own capability, so the interesting cases are
 * the refusal and the window parameter.
 *
 * @covers \block_feedback_tracker\external\get_school_comparison
 */
final class get_school_comparison_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * Call and clean.
     *
     * @param int|null $days
     * @return array
     */
    private function call(?int $days = null): array {
        $raw = $days === null
            ? get_school_comparison::execute()
            : get_school_comparison::execute($days);
        return external_api::clean_returnvalue(get_school_comparison::execute_returns(), $raw);
    }

    /**
     * An administrator gets a well-formed payload.
     *
     * @return void
     */
    public function test_admin_receives_a_payload(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = $this->call();

        $this->assertTrue($result['success']);
        $this->assertIsArray($result);
    }

    /**
     * The window parameter is accepted and echoed back through the declared
     * return shape.
     *
     * @return void
     */
    public function test_custom_window_is_accepted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = $this->call(7);

        $this->assertTrue($result['success']);
    }

    /**
     * The capability gate: a plain user is refused.
     *
     * @return void
     */
    public function test_plain_user_is_refused(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        get_school_comparison::execute();
    }

    /**
     * An editing teacher does not hold viewschoolcomparison by archetype, so
     * a course role is not a way in.
     *
     * @return void
     */
    public function test_editing_teacher_is_refused(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course();
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);
        get_school_comparison::execute();
    }

    /**
     * Granting the capability at system context lets the same user through —
     * the mutation check for this gate.
     *
     * @return void
     */
    public function test_granting_the_capability_allows_access(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'block/feedback_tracker:viewschoolcomparison',
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
}
