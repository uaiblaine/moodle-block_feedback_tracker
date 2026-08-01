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
 * Tests for the get_pause_timeline external function.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\sla\group_access;
use core_external\external_api;

/**
 * The submission id is a small sequential integer, so anything this function
 * fails to gate is enumerable by a caller who holds viewresponsiveness.
 *
 * @covers \block_feedback_tracker\external\get_pause_timeline
 */
final class get_pause_timeline_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * A separate-groups course with two groups, a teacher who belongs to the
     * first only, and one ledger row in each group.
     *
     * @return array [course, teacher, mineid, theirsid]
     */
    private function two_group_course(): array {
        $this->generator()->seed_default_platform_calendar();
        $course = $this->generator()->create_tracked_course([
            'groupmode' => SEPARATEGROUPS,
            'groupmodeforce' => 1,
        ]);
        $mine = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $theirs = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'teacher', (int) $mine->id);

        $now = time();
        $mineid = $this->generator()->create_ledger_row([
            'courseid' => (int) $course->id,
            'groupid' => (int) $mine->id,
            'timesubmitted' => $now - 7200,
            'timegraded' => $now - 3600,
        ]);
        $theirsid = $this->generator()->create_ledger_row([
            'courseid' => (int) $course->id,
            'groupid' => (int) $theirs->id,
            'timesubmitted' => $now - 7200,
            'timegraded' => $now - 3600,
        ]);

        group_access::reset_memo();
        return [$course, $teacher, $mineid, $theirsid];
    }

    /**
     * A teacher reads the timeline of a submission in their own group.
     *
     * @return void
     */
    public function test_teacher_reads_own_group_timeline(): void {
        $this->resetAfterTest();

        [, $teacher, $mineid] = $this->two_group_course();
        $this->setUser($teacher);

        $raw = get_pause_timeline::execute($mineid);
        $result = external_api::clean_returnvalue(get_pause_timeline::execute_returns(), $raw);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('pauses', $result);
    }

    /**
     * The regression: a separate-groups teacher must not read timing data for
     * a submission belonging to a group they cannot see.
     *
     * Without the gate this returns the other group's timings — the caller
     * holds viewresponsiveness in the course, so the only check standing
     * between them and the data is the group whitelist.
     *
     * @return void
     */
    public function test_teacher_cannot_read_other_group_timeline(): void {
        $this->resetAfterTest();

        [$course, $teacher, , $theirsid] = $this->two_group_course();
        $this->setUser($teacher);

        $this->assertFalse(
            group_access::can_see_group(
                (int) $course->id,
                (int) $teacher->id,
                (int) $this->group_of($theirsid)
            ),
            'Precondition: the teacher must not be able to see the other group.'
        );

        $this->expectException(\required_capability_exception::class);
        get_pause_timeline::execute($theirsid);
    }

    /**
     * A privileged user with accessallgroups reads any group's timeline.
     *
     * @return void
     */
    public function test_accessallgroups_user_reads_any_group(): void {
        $this->resetAfterTest();

        [$course, , , $theirsid] = $this->two_group_course();
        $privileged = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        group_access::reset_memo();
        $this->setUser($privileged);

        $raw = get_pause_timeline::execute($theirsid);
        $result = external_api::clean_returnvalue(get_pause_timeline::execute_returns(), $raw);

        $this->assertArrayHasKey('pauses', $result);
    }

    /**
     * Under visible groups the gate is a whitelist of every named group, so a
     * plain teacher reads any of them.
     *
     * @return void
     */
    public function test_visiblegroups_teacher_reads_any_group(): void {
        $this->resetAfterTest();

        $this->generator()->seed_default_platform_calendar();
        $course = $this->generator()->create_tracked_course([
            'groupmode' => VISIBLEGROUPS,
            'groupmodeforce' => 1,
        ]);
        $mine = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $theirs = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'teacher', (int) $mine->id);

        $now = time();
        $theirsid = $this->generator()->create_ledger_row([
            'courseid' => (int) $course->id,
            'groupid' => (int) $theirs->id,
            'timesubmitted' => $now - 7200,
            'timegraded' => $now - 3600,
        ]);

        group_access::reset_memo();
        $this->setUser($teacher);

        $raw = get_pause_timeline::execute($theirsid);
        $result = external_api::clean_returnvalue(get_pause_timeline::execute_returns(), $raw);

        $this->assertArrayHasKey('pauses', $result);
    }

    /**
     * A user without viewresponsiveness is refused regardless of groups.
     *
     * @return void
     */
    public function test_student_is_refused(): void {
        $this->resetAfterTest();

        [$course, , $mineid] = $this->two_group_course();
        $student = $this->generator()->create_user_in_role((int) $course->id, 'student');
        group_access::reset_memo();
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        get_pause_timeline::execute($mineid);
    }

    /**
     * An unknown submission id is rejected rather than returning an empty
     * timeline.
     *
     * @return void
     */
    public function test_unknown_submission_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\dml_missing_record_exception::class);
        get_pause_timeline::execute(999999);
    }

    /**
     * Read the group id of a ledger row.
     *
     * @param int $rowid
     * @return int
     */
    private function group_of(int $rowid): int {
        global $DB;
        return (int) $DB->get_field('block_feedback_tracker_sub', 'groupid', ['id' => $rowid], MUST_EXIST);
    }
}
