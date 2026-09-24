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
 * Tests for the dates the ledger stores against each submission.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;

/**
 * The stored open / due / cut-off dates reach a ledger row through three
 * writers: the upsert, the group-override re-resolve and the per-student
 * re-resolve. They must store the same dates for the same student, whatever
 * group the row is attributed to, and follow the overrides, extensions and
 * group memberships core applies. rule_resolver_test pins the resolution
 * itself; these tests pin what reaches the ledger, through core's own events
 * where core has them.
 *
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 * @covers \block_feedback_tracker\local\sla\rule_resolver
 */
final class ledger_rule_dates_test extends \advanced_testcase {
    /**
     * Flush the per-request memos the ledger consults.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        submission_ledger::reset_memos();
        group_resolver::reset_memo();
    }

    /**
     * A student in two overridden groups gets the lowest-sortorder override
     * from every writer, including when the ledger attributes the row to the
     * other group (the one joined last).
     *
     * @return void
     */
    public function test_every_writer_stores_the_same_dates_for_a_member_of_two_groups(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $first = $this->group($course);
        $second = $this->group($course);
        $userid = $this->student($course, $first, $second);
        $this->group_override($assignid, $first, 1, $due + 86400);
        $this->group_override($assignid, $second, 2, $due + 2 * 86400);
        $this->insert_submission($assignid, $userid);

        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $userid], '*', MUST_EXIST);
        $this->assertSame(
            $second,
            (int) $row->groupid,
            'Precondition: the row is attributed to the group whose override must not win.'
        );
        $this->assertSame($due + 86400, (int) $row->timecloses, 'The upsert applies the lowest sortorder.');

        $DB->set_field('block_feedback_tracker_sub', 'timecloses', 1, ['id' => $row->id]);
        submission_ledger::re_resolve_rules_for_assign_group($assignid, $second);
        $this->assertSame(
            $due + 86400,
            (int) $DB->get_field('block_feedback_tracker_sub', 'timecloses', ['id' => $row->id]),
            'Re-resolving for the other group stores the same date.'
        );

        $DB->set_field('block_feedback_tracker_sub', 'timecloses', 1, ['id' => $row->id]);
        submission_ledger::re_resolve_rules_for_assign_user($assignid, $userid);
        $this->assertSame(
            $due + 86400,
            (int) $DB->get_field('block_feedback_tracker_sub', 'timecloses', ['id' => $row->id]),
            'Re-resolving for the student stores the same date.'
        );
    }

    /**
     * Deleting a group override through core re-resolves its members against
     * the groups they still belong to, not against the activity alone.
     *
     * @return void
     */
    public function test_a_deleted_group_override_falls_back_to_the_remaining_groups(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->setAdminUser();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $first = $this->group($course);
        $second = $this->group($course);
        $userid = $this->student($course, $first, $second);
        $firstoverride = $this->group_override($assignid, $first, 1, $due + 86400);
        $secondoverride = $this->group_override($assignid, $second, 2, $due + 2 * 86400);
        $this->insert_submission($assignid, $userid);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        $this->assertSame($due + 86400, $this->stored_close($cm, $userid), 'Sanity: the first group governs.');

        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $assign->delete_override($firstoverride);
        $this->assertSame(
            $due + 2 * 86400,
            $this->stored_close($cm, $userid),
            'With the first override gone, the second group governs.'
        );

        $assign->delete_override($secondoverride);
        $this->assertSame($due, $this->stored_close($cm, $userid), 'With no override left, the activity date.');
    }

    /**
     * An extension granted through core reaches the stored due date and a
     * cut-off it passes; revoking it restores both. The open date never moves.
     *
     * @return void
     */
    public function test_an_extension_reaches_the_stored_dates_and_is_withdrawn_with_it(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->setAdminUser();
        $opens = time() - 10 * 86400;
        $due = time() + 7 * 86400;
        $cutoff = $due + 86400;
        [$cm, $assignid, $course] = $this->build_environment([
            'allowsubmissionsfromdate' => $opens,
            'duedate' => $due,
            'cutoffdate' => $cutoff,
        ]);
        $userid = $this->student($course);
        $this->insert_submission($assignid, $userid);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);

        // Through core, so extension_granted reaches the observer as it does on a site.
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $extended = $cutoff + 3 * 86400;
        $this->assertTrue($assign->save_user_extension($userid, $extended), 'Sanity: core granted it.');

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $userid], '*', MUST_EXIST);
        $this->assertSame($extended, (int) $row->timecloses, 'The extension replaces the due date.');
        $this->assertSame($extended, (int) $row->timecutoff, 'And carries the cut-off it passes.');
        $this->assertSame($opens, (int) $row->timeopens, 'The open date is not the extension\'s business.');

        // The grant-extension form saves 0 when the extension is switched off.
        $this->assertTrue($assign->save_user_extension($userid, 0), 'Sanity: core revoked it.');

        $row = $DB->get_record('block_feedback_tracker_sub', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertSame($due, (int) $row->timecloses, 'A revoked extension gives the due date back.');
        $this->assertSame($cutoff, (int) $row->timecutoff);
    }

    /**
     * A user override that removes the due date leaves the row with no due
     * date, although the activity has one.
     *
     * @return void
     */
    public function test_an_override_that_removes_the_due_date_reaches_the_ledger(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $userid = $this->student($course);
        $this->insert_submission($assignid, $userid);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        $this->assertSame($due, $this->stored_close($cm, $userid), 'Sanity: the activity date first.');

        $DB->insert_record('assign_overrides', (object) [
            'assignid' => $assignid,
            'userid' => $userid,
            'groupid' => null,
            'sortorder' => null,
            'allowsubmissionsfromdate' => null,
            'duedate' => 0,
            'cutoffdate' => null,
        ]);
        submission_ledger::re_resolve_rules_for_assign_user($assignid, $userid);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $userid], '*', MUST_EXIST);
        $this->assertNull($row->timecloses, 'The override removed the due date for this student.');
        $this->assertSame(0, (int) $row->hasrule, 'And no other date is left.');
    }

    /**
     * The stored due date of one student's row.
     *
     * @param \stdClass $cm
     * @param int $userid
     * @return int|null
     */
    private function stored_close(\stdClass $cm, int $userid): ?int {
        global $DB;
        $value = $DB->get_field('block_feedback_tracker_sub', 'timecloses', ['cmid' => $cm->id, 'userid' => $userid]);
        return $value === null ? null : (int) $value;
    }

    /**
     * Build a processable course holding one assign.
     *
     * @param array $assignopts {assign} settings.
     * @return array The cm, the {assign} id and the course.
     */
    private function build_environment(array $assignopts): array {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();
        $instance = $this->getDataGenerator()->create_module(
            'assign',
            array_merge(['course' => $course->id], $assignopts)
        );
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        return [$cm, (int) $instance->id, $course];
    }

    /**
     * Create a group in the course.
     *
     * @param \stdClass $course
     * @return int The group id.
     */
    private function group(\stdClass $course): int {
        return (int) $this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
    }

    /**
     * Enrol a student and add them to the given groups, in order, all within
     * the same second, so the reporting group is the last one (highest id).
     *
     * @param \stdClass $course
     * @param int ...$groupids
     * @return int The student's user id.
     */
    private function student(\stdClass $course, int ...$groupids): int {
        global $DB;
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $joined = time();
        foreach ($groupids as $groupid) {
            $this->getDataGenerator()->create_group_member(['groupid' => $groupid, 'userid' => $user->id]);
            $DB->set_field('groups_members', 'timeadded', $joined, ['groupid' => $groupid, 'userid' => $user->id]);
        }
        group_resolver::reset_memo();
        return (int) $user->id;
    }

    /**
     * Insert a group override that sets only the due date.
     *
     * @param int $assignid
     * @param int $groupid
     * @param int $sortorder
     * @param int $due
     * @return int The override id.
     */
    private function group_override(int $assignid, int $groupid, int $sortorder, int $due): int {
        global $DB;
        return (int) $DB->insert_record('assign_overrides', (object) [
            'assignid' => $assignid,
            'groupid' => $groupid,
            'userid' => null,
            'sortorder' => $sortorder,
            'allowsubmissionsfromdate' => null,
            'duedate' => $due,
            'cutoffdate' => null,
        ]);
    }

    /**
     * Insert one submitted attempt, four days old.
     *
     * @param int $assignid
     * @param int $userid
     * @return void
     */
    private function insert_submission(int $assignid, int $userid): void {
        global $DB;
        $tsubmit = time() - 4 * 86400;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assignid, 'userid' => $userid, 'attemptnumber' => 0,
            'timecreated' => $tsubmit, 'timemodified' => $tsubmit,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
    }

    /**
     * Seed the calendar settings the academic-time engine needs.
     *
     * @return void
     */
    private function seed_calendar(): void {
        global $DB;
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');
        $now = time();
        for ($dow = 0; $dow <= 4; $dow++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek' => $dow, 'starttime' => 480, 'endtime' => 1080,
                'enabled' => 1, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        academic_time::reset_memos();
    }
}
