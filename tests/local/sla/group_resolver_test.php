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
 * Tests for group attribution of ledger rows.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Decides which group a submission is attributed to, which in turn decides
 * which rollup row it lands in. A user in several groups gets the most
 * recently joined one — a deterministic tie-break, not an arbitrary pick.
 *
 * @covers \block_feedback_tracker\local\sla\group_resolver
 */
final class group_resolver_test extends \advanced_testcase {
    /**
     * Add a user to a group with an explicit join time, so ordering is
     * deterministic rather than dependent on insert speed.
     *
     * @param int $groupid
     * @param int $userid
     * @param int $timeadded
     * @return void
     */
    private function join_at(int $groupid, int $userid, int $timeadded): void {
        global $DB;
        groups_add_member($groupid, $userid);
        $DB->set_field(
            'groups_members',
            'timeadded',
            $timeadded,
            ['groupid' => $groupid, 'userid' => $userid]
        );
        group_resolver::reset_memo();
    }

    /**
     * A user in no group is attributed to the ungrouped bucket.
     *
     * @return void
     */
    public function test_user_without_a_group_resolves_to_zero(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        group_resolver::reset_memo();

        $this->assertSame(0, group_resolver::resolve_group_for_user((int) $course->id, (int) $user->id));
    }

    /**
     * A user in exactly one group resolves to it.
     *
     * @return void
     */
    public function test_single_group_membership(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->join_at((int) $group->id, (int) $user->id, time() - 100);

        $this->assertSame(
            (int) $group->id,
            group_resolver::resolve_group_for_user((int) $course->id, (int) $user->id)
        );
    }

    /**
     * With several memberships the most recently joined group wins. Without a
     * deterministic tie-break a user's submissions would drift between rollup
     * rows depending on row order.
     *
     * @return void
     */
    public function test_most_recently_joined_group_wins(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $older = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $newer = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $now = time();
        $this->join_at((int) $older->id, (int) $user->id, $now - 1000);
        $this->join_at((int) $newer->id, (int) $user->id, $now - 10);

        $this->assertSame(
            (int) $newer->id,
            group_resolver::resolve_group_for_user((int) $course->id, (int) $user->id)
        );
    }

    /**
     * Groups in another course are not considered.
     *
     * @return void
     */
    public function test_membership_in_another_course_is_ignored(): void {
        $this->resetAfterTest();

        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $othergroup = $this->getDataGenerator()->create_group(['courseid' => $other->id]);
        $user = $this->getDataGenerator()->create_and_enrol($other, 'student');
        $this->getDataGenerator()->enrol_user($user->id, $mine->id, 'student');
        $this->join_at((int) $othergroup->id, (int) $user->id, time() - 50);

        $this->assertSame(
            0,
            group_resolver::resolve_group_for_user((int) $mine->id, (int) $user->id),
            'A group in a different course must not be attributed here.'
        );
    }

    /**
     * The answer is memoised per request, and reset_memo() clears it. The
     * memo is also why group-membership changes must reset it — otherwise a
     * moved user keeps landing in their old rollup for the rest of the
     * request.
     *
     * @return void
     */
    public function test_memo_holds_until_reset(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->join_at((int) $group->id, (int) $user->id, time() - 100);

        $this->assertSame(
            (int) $group->id,
            group_resolver::resolve_group_for_user((int) $course->id, (int) $user->id)
        );

        // Remove the membership behind the memo's back.
        $DB->delete_records('groups_members', ['groupid' => $group->id, 'userid' => $user->id]);

        $this->assertSame(
            (int) $group->id,
            group_resolver::resolve_group_for_user((int) $course->id, (int) $user->id),
            'The memo should still hold the pre-change answer.'
        );

        group_resolver::reset_memo();
        $this->assertSame(
            0,
            group_resolver::resolve_group_for_user((int) $course->id, (int) $user->id)
        );
    }

    /**
     * Two users in the same course resolve independently — the memo key
     * carries both course and user.
     *
     * @return void
     */
    public function test_memo_distinguishes_users(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $grouped = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $ungrouped = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->join_at((int) $group->id, (int) $grouped->id, time() - 100);

        $this->assertSame(
            (int) $group->id,
            group_resolver::resolve_group_for_user((int) $course->id, (int) $grouped->id)
        );
        $this->assertSame(
            0,
            group_resolver::resolve_group_for_user((int) $course->id, (int) $ungrouped->id)
        );
    }
}
