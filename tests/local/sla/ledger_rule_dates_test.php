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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The stored open / due / cut-off dates reach a ledger row through the writer
 * alone: resolved from live state while the cycle is open, kept once it has
 * closed. Overrides and extensions changed through core reach an open cycle
 * at its next write, and never a closed one. rule_resolver_test pins the
 * resolution itself; these tests pin what reaches the ledger, and when.
 */
#[CoversClass(submission_ledger::class)]
#[CoversClass(rule_resolver::class)]
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
     * A student in two overridden groups gets the lowest-sortorder override,
     * also when the ledger attributes the row to the other group (the one
     * joined last).
     *
     * @return void
     */
    public function test_the_writer_stores_the_governing_override_for_a_member_of_two_groups(): void {
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
        $this->assertSame($due + 86400, (int) $row->timecloses, 'The lowest sortorder governs.');
    }

    /**
     * Deleting a group override through core changes no row; the open cycle's
     * next write falls back to the groups the student still belongs to, not
     * to the activity alone.
     *
     * @return void
     */
    public function test_a_deleted_group_override_reaches_an_open_cycle_at_its_next_write(): void {
        global $CFG;
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

        /* Through the override manager, as Moodle 5.3's own pages delete an
         * override (assign::delete_override() is deprecated there, MDL-86513);
         * it fires group_override_deleted, which the plugin does not observe. */
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $manager = new \mod_assign\override_manager($assign->get_instance(), $assign->get_context());
        $manager->delete_overrides_by_id([$firstoverride]);
        $this->assertSame($due + 86400, $this->stored_close($cm, $userid), 'The deletion itself writes nothing.');
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        $this->assertSame(
            $due + 2 * 86400,
            $this->stored_close($cm, $userid),
            'With the first override gone, the second group governs.'
        );

        $manager->delete_overrides_by_id([$secondoverride]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        $this->assertSame($due, $this->stored_close($cm, $userid), 'With no override left, the activity date.');
    }

    /**
     * An extension granted through core reaches the stored due date and a
     * cut-off it passes at the open cycle's next write; revoking it restores
     * both the same way. The open date never moves.
     *
     * @return void
     */
    public function test_an_extension_reaches_an_open_cycle_at_its_next_write(): void {
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

        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $extended = $cutoff + 3 * 86400;
        $this->assertTrue($assign->save_user_extension($userid, $extended), 'Sanity: core granted it.');
        $this->assertSame($due, $this->stored_close($cm, $userid), 'The grant itself writes nothing.');
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $userid], '*', MUST_EXIST);
        $this->assertSame($extended, (int) $row->timecloses, 'The extension replaces the due date.');
        $this->assertSame($extended, (int) $row->timecutoff, 'And carries the cut-off it passes.');
        $this->assertSame($opens, (int) $row->timeopens, 'The open date is not the extension\'s business.');

        // The grant-extension form saves 0 when the extension is switched off.
        $this->assertTrue($assign->save_user_extension($userid, 0), 'Sanity: core revoked it.');
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);

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

        $this->user_override($assignid, $userid, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $userid], '*', MUST_EXIST);
        $this->assertNull($row->timecloses, 'The override removed the due date for this student.');
        $this->assertSame(0, (int) $row->hasrule, 'And no other date is left.');
    }

    /**
     * The write that closes a cycle stores the dates in force then, and a
     * later write of the closed cycle keeps them, whatever the override says
     * by that time.
     *
     * @return void
     */
    public function test_a_closed_cycle_keeps_the_dates_it_closed_under(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $userid = $this->student($course);
        $this->insert_submission($assignid, $userid);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        $override = $this->user_override($assignid, $userid, $due + 86400);

        $this->insert_grade($assignid, $userid, 70.0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $userid], '*', MUST_EXIST);
        $this->assertNotNull($row->timegraded, 'Precondition: the grade closed the cycle.');
        $this->assertSame($due + 86400, (int) $row->timecloses, 'The closing write takes the dates in force.');

        $DB->set_field('assign_overrides', 'duedate', $due + 5 * 86400, ['id' => $override]);
        $DB->set_field('assign', 'cutoffdate', $due + 9 * 86400, ['id' => $assignid]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);

        $after = $DB->get_record('block_feedback_tracker_sub', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertSame($due + 86400, (int) $after->timecloses, 'The closed cycle keeps its due date.');
        $this->assertNull($after->timecutoff, 'And the cut-off it closed without.');
        $this->assertSame((int) $row->hasrule, (int) $after->hasrule);
    }

    /**
     * A grade withdrawn in the activity opens the cycle again, and its next
     * write takes the dates from live state.
     *
     * @return void
     */
    public function test_a_withdrawn_grade_opens_the_dates_again(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $userid = $this->student($course);
        $this->insert_submission($assignid, $userid);
        $this->insert_grade($assignid, $userid, 70.0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        $this->assertSame($due, $this->stored_close($cm, $userid), 'Sanity: closed under the activity date.');

        $this->user_override($assignid, $userid, $due + 3 * 86400);
        $DB->set_field('assign_grades', 'grade', -1, ['assignment' => $assignid, 'userid' => $userid]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $userid], '*', MUST_EXIST);
        $this->assertNull($row->timegraded, 'Precondition: the cycle is open again.');
        $this->assertSame($due + 3 * 86400, (int) $row->timecloses);
    }

    /**
     * Joining an overridden group through core moves the student's row to the
     * group and leaves its dates; the open cycle's next write takes the
     * group's date.
     *
     * @return void
     */
    public function test_a_group_change_moves_the_row_and_leaves_its_dates(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $group = $this->group($course);
        $this->group_override($assignid, $group, 1, $due + 2 * 86400);
        $userid = $this->student($course);
        $this->insert_submission($assignid, $userid);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        $this->assertSame($due, $this->stored_close($cm, $userid), 'Sanity: the activity date first.');

        // Through core, so group_member_added reaches the observer as it does on a site.
        $this->getDataGenerator()->create_group_member(['groupid' => $group, 'userid' => $userid]);
        group_resolver::reset_memo();

        $this->assertSame(
            $group,
            (int) $DB->get_field('block_feedback_tracker_sub', 'groupid', ['cmid' => $cm->id, 'userid' => $userid]),
            'Control: the observer moved the row.'
        );
        $this->assertSame($due, $this->stored_close($cm, $userid), 'The move leaves the dates.');
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        $this->assertSame($due + 2 * 86400, $this->stored_close($cm, $userid));
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
     * Insert a user override that sets only the due date.
     *
     * @param int $assignid
     * @param int $userid
     * @param int $due 0 removes the due date for the student.
     * @return int The override id.
     */
    private function user_override(int $assignid, int $userid, int $due): int {
        global $DB;
        return (int) $DB->insert_record('assign_overrides', (object) [
            'assignid' => $assignid,
            'userid' => $userid,
            'groupid' => null,
            'sortorder' => null,
            'allowsubmissionsfromdate' => null,
            'duedate' => $due,
            'cutoffdate' => null,
        ]);
    }

    /**
     * Grade attempt 0 an hour ago, after the four-day-old submission.
     *
     * @param int $assignid
     * @param int $userid
     * @param float $grade
     * @return void
     */
    private function insert_grade(int $assignid, int $userid, float $grade): void {
        global $DB;
        $tgrade = time() - 3600;
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assignid, 'userid' => $userid, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => $grade,
            'timecreated' => $tgrade, 'timemodified' => $tgrade,
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
