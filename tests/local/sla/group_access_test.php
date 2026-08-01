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
 * Tests for the group visibility gate.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * This class is the plugin's single decision point for group visibility, and
 * its three-valued return is the part callers get wrong: null means
 * unrestricted, [] means nothing is visible and the caller must short-circuit,
 * and an int[] is a whitelist that never contains groupid 0.
 *
 * @covers \block_feedback_tracker\local\sla\group_access
 */
final class group_access_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * A course with no group mode leaves everyone unrestricted.
     *
     * @return void
     */
    public function test_nogroups_is_unrestricted(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course(['groupmode' => NOGROUPS]);
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        group_access::reset_memo();

        $this->assertNull(group_access::visible_group_ids((int) $course->id, (int) $teacher->id));
        $this->assertTrue(group_access::is_unrestricted((int) $course->id, (int) $teacher->id));
    }

    /**
     * Visible groups means every named group, for everyone.
     *
     * @return void
     */
    public function test_visiblegroups_returns_every_named_group(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course(['groupmode' => VISIBLEGROUPS, 'groupmodeforce' => 1]);
        $one = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $two = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'teacher', (int) $one->id);
        group_access::reset_memo();

        $visible = group_access::visible_group_ids((int) $course->id, (int) $teacher->id);

        $this->assertIsArray($visible);
        sort($visible);
        $expected = [(int) $one->id, (int) $two->id];
        sort($expected);
        $this->assertSame($expected, $visible, 'Visible groups exposes groups the user is not a member of.');
    }

    /**
     * Separate groups narrows a non-privileged user to their own groups.
     *
     * @return void
     */
    public function test_separategroups_returns_only_own_groups(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $mine = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $theirs = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'teacher', (int) $mine->id);
        group_access::reset_memo();

        $visible = group_access::visible_group_ids((int) $course->id, (int) $teacher->id);

        $this->assertSame([(int) $mine->id], $visible);
        $this->assertTrue(group_access::can_see_group((int) $course->id, (int) $teacher->id, (int) $mine->id));
        $this->assertFalse(group_access::can_see_group((int) $course->id, (int) $teacher->id, (int) $theirs->id));
    }

    /**
     * A user in no group under separate groups sees nothing — and the return
     * must be an empty array, never null. Conflating the two turns "sees
     * nothing" into "sees everything", which is the failure this class exists
     * to prevent.
     *
     * @return void
     */
    public function test_separategroups_with_no_membership_returns_empty_array_not_null(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'teacher');
        group_access::reset_memo();

        $visible = group_access::visible_group_ids((int) $course->id, (int) $teacher->id);

        $this->assertIsArray($visible);
        $this->assertSame([], $visible);
        $this->assertNotNull($visible, 'An empty whitelist must not be conflated with unrestricted access.');
        $this->assertFalse(group_access::is_unrestricted((int) $course->id, (int) $teacher->id));
    }

    /**
     * accessallgroups lifts the restriction even under separate groups.
     *
     * @return void
     */
    public function test_accessallgroups_is_unrestricted_under_separategroups(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $manager = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        group_access::reset_memo();

        $this->assertTrue(
            has_capability('moodle/site:accessallgroups', \context_course::instance($course->id), $manager),
            'Precondition: editingteacher holds accessallgroups.'
        );
        $this->assertNull(group_access::visible_group_ids((int) $course->id, (int) $manager->id));
    }

    /**
     * Groupid 0 ("ungrouped") is visible only to unrestricted users. A
     * restricted user must never see it, because it is not a named group and
     * cannot appear in their whitelist.
     *
     * @return void
     */
    public function test_ungrouped_is_visible_only_to_unrestricted_users(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'teacher', (int) $group->id);
        $privileged = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        group_access::reset_memo();

        $this->assertFalse(group_access::can_see_group((int) $course->id, (int) $teacher->id, 0));
        $this->assertTrue(group_access::can_see_group((int) $course->id, (int) $privileged->id, 0));
    }

    /**
     * The result is memoised per request, and reset_memo() clears it. Tests
     * that change group state mid-run depend on this.
     *
     * @return void
     */
    public function test_memo_is_held_until_reset(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $first = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'teacher', (int) $first->id);
        group_access::reset_memo();

        $this->assertSame([(int) $first->id], group_access::visible_group_ids((int) $course->id, (int) $teacher->id));

        $second = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        groups_add_member((int) $second->id, (int) $teacher->id);

        $this->assertSame(
            [(int) $first->id],
            group_access::visible_group_ids((int) $course->id, (int) $teacher->id),
            'The memo should still hold the pre-change answer.'
        );

        group_access::reset_memo();
        $after = group_access::visible_group_ids((int) $course->id, (int) $teacher->id);
        sort($after);
        $expected = [(int) $first->id, (int) $second->id];
        sort($expected);
        $this->assertSame($expected, $after);
    }

    /**
     * An unrestricted user can see any group id, including one that does not
     * exist — the gate answers about visibility, not existence.
     *
     * @return void
     */
    public function test_unrestricted_user_can_see_any_group(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course(['groupmode' => NOGROUPS]);
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        group_access::reset_memo();

        $this->assertTrue(group_access::can_see_group((int) $course->id, (int) $teacher->id, 999999));
    }
}
