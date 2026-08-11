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
 * Measurement-cycle behaviour of the submission ledger.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;

/**
 * A cycle is one measurement of teacher response. Work resubmitted after it
 * already carried a mark opens a new cycle rather than rewriting the closed
 * one, so a completed response time survives and the re-look gets its own
 * clock. These tests pin that contract, the latest-attempt gate, the
 * marking-workflow release clock and the team-submission guard.
 *
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 * @covers \block_feedback_tracker\local\sla\grading_state
 */
final class grading_cycle_test extends \advanced_testcase {
    /**
     * Flush the ledger's per-request static memos; resetAfterTest() resets the
     * database but not PHP statics, so a recycled courseid would otherwise
     * inherit a previous test's decision.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        submission_ledger::reset_memos();
        group_resolver::reset_memo();
    }

    /**
     * The reported bug. A student who re-saves an already-graded submission
     * must not un-grade it: the closed measurement keeps its response time and
     * the re-look becomes a second, correctly-pending cycle.
     *
     * @return void
     */
    public function test_resubmission_after_grading_opens_a_new_cycle(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $t1 = $this->ts('2026-05-11 09:00:00');
        $t2 = $this->ts('2026-05-12 09:00:00');
        $t3 = $this->ts('2026-05-13 09:00:00');

        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $this->insert_grade((int) $assign->id, (int) $student->id, $t2, 75.0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $cycle0 = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'cycle' => 0]);
        $this->assertSame($t2, (int) $cycle0->timegraded);
        $graded = (float) $cycle0->effectivehours;

        // The student re-opens the submission and saves; core bumps timemodified.
        $DB->set_field('assign_submission', 'timemodified', $t3, [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'attemptnumber' => 0,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $rows = $DB->get_records(
            'block_feedback_tracker_sub',
            ['cmid' => $cm->id, 'userid' => $student->id, 'attemptnumber' => 0],
            'cycle ASC'
        );
        $this->assertCount(2, $rows, 'The re-save must open a second cycle, not rewrite the first.');

        $cycle0 = array_shift($rows);
        $cycle1 = array_shift($rows);

        // The completed measurement is untouched.
        $this->assertSame($t1, (int) $cycle0->timesubmitted);
        $this->assertSame($t2, (int) $cycle0->timegraded);
        $this->assertEqualsWithDelta($graded, (float) $cycle0->effectivehours, 0.001);
        $this->assertSame(0, (int) $cycle0->iscurrent);

        // The re-look is pending, with its clock starting at the re-save.
        $this->assertSame(1, (int) $cycle1->cycle);
        $this->assertSame($t3, (int) $cycle1->timesubmitted);
        $this->assertNull($cycle1->timegraded);
        $this->assertSame(1, (int) $cycle1->iscurrent);
        $this->assertSame(submission_status::SUBMITTED, $cycle1->submissionstatus);
    }

    /**
     * Editing a submission that is still awaiting feedback must not restart
     * its clock — the work arrived at hand-in and the teacher has been on the
     * hook since then.
     *
     * @return void
     */
    public function test_edit_before_grading_keeps_one_cycle_and_the_original_clock(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $t1 = $this->ts('2026-05-11 09:00:00');
        $t2 = $this->ts('2026-05-12 09:00:00');

        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $DB->set_field('assign_submission', 'timemodified', $t2, [
            'assignment' => $assign->id,
            'userid' => $student->id,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $rows = $DB->get_records('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame(0, (int) $row->cycle);
        $this->assertSame($t1, (int) $row->timesubmitted);
    }

    /**
     * Clearing the grade is a genuine un-grading: it re-opens the SAME cycle
     * rather than opening a new one, because no new student work happened.
     *
     * @return void
     */
    public function test_clearing_the_grade_reopens_the_same_cycle(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $t1 = $this->ts('2026-05-11 09:00:00');
        $t2 = $this->ts('2026-05-12 09:00:00');

        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        $this->insert_grade((int) $assign->id, (int) $student->id, $t2, 75.0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $DB->set_field('assign_grades', 'grade', null, [
            'assignment' => $assign->id,
            'userid' => $student->id,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $rows = $DB->get_records('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertCount(1, $rows, 'An un-grading is not new student work.');
        $row = reset($rows);
        $this->assertSame(0, (int) $row->cycle);
        $this->assertNull($row->timegraded);
        $this->assertNull($row->timemarked);
        $this->assertSame(grading_state::STATUS_NOT_GRADED, $row->gradestate);
    }

    /**
     * A superseded attempt must stop counting as pending. Core gates every
     * needs-grading read on assign_submission.latest; the ledger mirrors it.
     *
     * @return void
     */
    public function test_superseded_attempt_is_no_longer_latest(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $t1 = $this->ts('2026-05-11 09:00:00');
        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'attemptnumber' => 0]);
        $this->assertSame(1, (int) $row->islatest);

        /* The teacher allows another attempt: core inserts a new reopened row
         * and flips the old one's latest flag. No event is fired at all, so
         * the next observation of either attempt must repair both. */
        $DB->set_field('assign_submission', 'latest', 0, [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'attemptnumber' => 0,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'attemptnumber' => 0]);
        $this->assertSame(0, (int) $row->islatest);
    }

    /**
     * A team submission is stored by mod_assign as one row with userid 0. The
     * ledger must never mirror that row directly: it was counted by the rollup
     * (which does not join {user}) but hidden by every list (which does), i.e.
     * a pending item nobody could ever clear.
     *
     * @return void
     */
    public function test_team_container_row_is_never_written(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, , $assign] = $this->build_environment();

        $t1 = $this->ts('2026-05-11 09:00:00');
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => 0, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => 7, 'latest' => 1,
        ]);

        $result = submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, 0, 0);

        $this->assertNull($result);
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub', ['userid' => 0]));
    }

    /**
     * A team submission is fanned out to one row per member, timed from the
     * shared group row and marked from each member's own grade row.
     *
     * @return void
     */
    public function test_team_submission_fans_out_to_members(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, , $assign, $course] = $this->build_environment(['teamsubmission' => 1]);

        $a = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $b = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        foreach ([$a, $b] as $member) {
            $this->getDataGenerator()->create_group_member([
                'groupid' => $group->id,
                'userid' => $member->id,
            ]);
        }
        group_resolver::reset_memo();

        $t1 = $this->ts('2026-05-11 09:00:00');
        $t2 = $this->ts('2026-05-12 09:00:00');
        // One shared submission row for the whole team, exactly as core writes it.
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => 0, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => $group->id, 'latest' => 1,
        ]);
        // Only member A has been graded so far.
        $this->insert_grade((int) $assign->id, (int) $a->id, $t2, 80.0);

        $ids = submission_ledger::upsert_for_team_attempt((int) $cm->id, (int) $group->id, 0);

        $this->assertCount(2, $ids, 'Both members carry the team\'s work.');
        $rowa = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $a->id]);
        $rowb = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $b->id]);

        // Timing comes from the shared group row for both.
        $this->assertSame($t1, (int) $rowa->timesubmitted);
        $this->assertSame($t1, (int) $rowb->timesubmitted);
        $this->assertSame((int) $group->id, (int) $rowa->teamgroupid);
        $this->assertSame((int) $group->id, (int) $rowb->teamgroupid);

        // The mark is per member: A is closed, B is still awaiting feedback.
        $this->assertSame($t2, (int) $rowa->timegraded);
        $this->assertNull($rowb->timegraded);
    }

    /**
     * A member who never personally saved has no {assign_submission} row of
     * their own — core only mirrors per-user rows when
     * requireallteammemberssubmit is off. A per-user lookup finds nothing and
     * drops them silently, which is why the fan-out reads the group row.
     *
     * @return void
     */
    public function test_team_member_without_own_row_is_still_tracked(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, , $assign, $course] = $this->build_environment([
            'teamsubmission' => 1,
            'requireallteammemberssubmit' => 1,
        ]);

        $a = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group->id,
            'userid' => $a->id,
        ]);
        group_resolver::reset_memo();

        $t1 = $this->ts('2026-05-11 09:00:00');
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => 0, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => $group->id, 'latest' => 1,
        ]);
        $this->assertSame(
            0,
            $DB->count_records('assign_submission', ['assignment' => $assign->id, 'userid' => $a->id]),
            'Sanity: the member has no submission row of their own.'
        );

        submission_ledger::upsert_for_team_attempt((int) $cm->id, (int) $group->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $a->id]);
        $this->assertNotEmpty($row);
        $this->assertSame($t1, (int) $row->timesubmitted);
        $this->assertNull($row->timegraded);
    }

    /**
     * Grading one member of a team must resolve through the team path, so the
     * timing keeps coming from the shared group row rather than from a
     * per-user row that may not exist.
     *
     * @return void
     */
    public function test_grading_a_team_member_routes_through_the_team_path(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, , $assign, $course] = $this->build_environment(['teamsubmission' => 1]);

        $a = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group->id,
            'userid' => $a->id,
        ]);
        group_resolver::reset_memo();

        $t1 = $this->ts('2026-05-11 09:00:00');
        $t2 = $this->ts('2026-05-12 09:00:00');
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => 0, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => $group->id, 'latest' => 1,
        ]);
        $this->insert_grade((int) $assign->id, (int) $a->id, $t2, 90.0);

        // The per-user entry point is what the grading observer calls.
        $subid = submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $a->id, 0);

        $this->assertNotNull($subid);
        $row = $DB->get_record('block_feedback_tracker_sub', ['id' => $subid]);
        $this->assertSame($t1, (int) $row->timesubmitted, 'Timing comes from the group row.');
        $this->assertSame($t2, (int) $row->timegraded);
        $this->assertSame((int) $group->id, (int) $row->teamgroupid);
    }

    /**
     * With marking workflow on and the release setting at its default (off),
     * the clock still stops when the mark is saved — no displayed number moves
     * — but the row is flagged as awaiting release so the marker can see that
     * a step they may not own is still outstanding.
     *
     * @return void
     */
    public function test_marked_but_unreleased_is_flagged_without_moving_the_clock(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment(['markingworkflow' => 1]);

        $t1 = $this->ts('2026-05-11 09:00:00');
        $t2 = $this->ts('2026-05-12 09:00:00');

        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        $this->insert_grade((int) $assign->id, (int) $student->id, $t2, 75.0);
        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')
            ->set_workflow_state((int) $assign->id, (int) $student->id, 'inmarking');

        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame($t2, (int) $row->timegraded, 'Default policy: the mark stops the clock.');
        $this->assertSame($t2, (int) $row->timemarked);
        $this->assertNull($row->timeclosed, 'Nothing reached the student yet.');
        $this->assertNull($row->timereleased);
        $this->assertSame('inmarking', $row->gradestate);
    }

    /**
     * With the release setting on, a marked-but-unreleased submission stays
     * pending: the student has received nothing, so the teacher's response has
     * not landed.
     *
     * @return void
     */
    public function test_release_setting_keeps_unreleased_marks_pending(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        set_config('release_stops_clock', '1', 'block_feedback_tracker');
        [$cm, $student, $assign] = $this->build_environment(['markingworkflow' => 1]);

        $t1 = $this->ts('2026-05-11 09:00:00');
        $t2 = $this->ts('2026-05-12 09:00:00');

        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        $this->insert_grade((int) $assign->id, (int) $student->id, $t2, 75.0);
        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')
            ->set_workflow_state((int) $assign->id, (int) $student->id, 'readyforrelease');
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNull($row->timegraded);
        $this->assertSame($t2, (int) $row->timemarked, 'The mark itself is still recorded.');

        // The coordinator releases; the observed instant closes the row.
        $t3 = $this->ts('2026-05-14 09:00:00');
        $DB->set_field('assign_user_flags', 'workflowstate', 'released', [
            'assignment' => $assign->id,
            'userid' => $student->id,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0, $t3);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame($t3, (int) $row->timegraded);
        $this->assertSame($t3, (int) $row->timereleased);
        $this->assertSame($t3, (int) $row->timeclosed);
        $this->assertSame('released', $row->gradestate);
    }

    /**
     * Grade type "None" can never carry a numeric mark, so the ordinary
     * grade-value test would leave every submission pending for ever. Mirror
     * core's needs-grading counter instead: a grade row strictly later than
     * the hand-in clears it.
     *
     * @return void
     */
    public function test_grade_type_none_can_still_be_closed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment(['grade' => 0]);

        $t1 = $this->ts('2026-05-11 09:00:00');
        $t2 = $this->ts('2026-05-12 09:00:00');

        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        $this->insert_grade((int) $assign->id, (int) $student->id, $t2, null);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame($t2, (int) $row->timegraded);
    }

    /**
     * The placeholder {assign_grades} row core auto-creates when a teacher
     * merely opens the grading page carries grade = -1 and copies the
     * submission's own timestamp. It must never read as a grading.
     *
     * @return void
     */
    public function test_auto_created_placeholder_grade_is_not_a_grading(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $t1 = $this->ts('2026-05-11 09:00:00');
        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        $this->insert_grade((int) $assign->id, (int) $student->id, $t1, -1.0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNull($row->timegraded);
        $this->assertNull($row->timemarked);
        $this->assertSame(grading_state::STATUS_NOT_GRADED, $row->gradestate);
    }

    /**
     * The read paths must agree with the ledger: a superseded attempt and a
     * cycle closed by a resubmission are nobody's outstanding task, so neither
     * may reach the pending count, while the graded population keeps every
     * cycle because each is a real response event.
     *
     * @return void
     */
    public function test_pending_reads_exclude_superseded_attempts_and_closed_cycles(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment();

        /* Relative to now, because the rollup's graded stats are windowed to
         * the last 30 days — fixed calendar dates fall out of it as the suite
         * ages and the assertion would rot into a false failure. */
        $now = time();
        $t1 = $now - 5 * 86400;
        $t2 = $now - 4 * 86400;
        $t3 = $now - 3 * 86400;

        // Attempt 0: submitted, graded, then resubmitted — cycle 0 closed,
        // cycle 1 open.
        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        $this->insert_grade((int) $assign->id, (int) $student->id, $t2, 75.0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $DB->set_field('assign_submission', 'timemodified', $t3, [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'attemptnumber' => 0,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $this->assertSame(
            2,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'Sanity: two cycles exist for the single attempt.'
        );

        rollup_service::recompute_group((int) $course->id, 0);
        $rollup = $DB->get_record(
            'block_feedback_tracker_group',
            ['courseid' => $course->id, 'groupid' => 0]
        );
        $this->assertSame(1, (int) $rollup->pending, 'Only the open cycle is pending.');
        $this->assertSame(1, (int) $rollup->numgraded30d, 'The closed cycle stays a graded response.');

        // Now the teacher grants another attempt: core flips latest on the old
        // row and inserts a reopened one. The old attempt must leave pending.
        $DB->set_field('assign_submission', 'latest', 0, [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'attemptnumber' => 0,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        rollup_service::recompute_group((int) $course->id, 0);
        $rollup = $DB->get_record(
            'block_feedback_tracker_group',
            ['courseid' => $course->id, 'groupid' => 0]
        );
        $this->assertSame(0, (int) $rollup->pending, 'A superseded attempt is nobody\'s task.');
        $this->assertSame(1, (int) $rollup->numgraded30d, 'Its recorded response survives.');
    }

    /**
     * The reported fairness problem, end to end. A submission that waited a
     * long time to be allocated and was then marked quickly must not read as a
     * slow marker: the queue and the turnaround are measured separately, and
     * the student-experience clock is left alone.
     *
     * @return void
     */
    public function test_queue_and_marker_turnaround_are_measured_separately(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment([
            'markingworkflow' => 1,
            'markingallocation' => 1,
        ]);

        /* Anchored on a recent Tuesday: the rollup's graded stats are windowed
         * to 30 days, so fixed calendar dates rot out of the window as the
         * suite ages, and a weekday 09:00 lands inside the seeded business
         * hours (08:00-18:00) so the two-hour turnaround is two effective
         * hours rather than a weekend-clipped zero. */
        $talloc = $this->recent_weekday_at(9);
        $tgrade = $talloc + 2 * 3600;
        $tsubmit = $talloc - 10 * 86400;

        $this->insert_submission((int) $assign->id, (int) $student->id, $tsubmit);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $marker = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')
            ->allocate_marker((int) $assign->id, (int) $student->id, (int) $marker->id);
        submission_ledger::stamp_allocation_for_user((int) $cm->id, (int) $student->id, $talloc);

        $this->insert_grade((int) $assign->id, (int) $student->id, $tgrade, 85.0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);

        // The marker's own turnaround is two business hours, not ten days.
        $this->assertEqualsWithDelta(2.0, (float) $row->allochours, 0.01);
        $this->assertSame(bucket::EXCELLENT, $row->allocbucket);
        // The queue is the coordinator's, and it is large.
        $this->assertGreaterThan(20.0, (float) $row->queuehours);
        // The student-experience clock still spans the whole wait.
        $this->assertGreaterThan((float) $row->allochours, (float) $row->effectivehours);

        rollup_service::recompute_group((int) $course->id, 0);
        $rollup = $DB->get_record(
            'block_feedback_tracker_group',
            ['courseid' => $course->id, 'groupid' => 0]
        );
        $this->assertEqualsWithDelta(2.0, (float) $rollup->median_alloc_h, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $rollup->alloc_coverage_pct, 0.01);
    }

    /**
     * An allocation discovered at or after the grading cannot be measured. It
     * must report null, never zero: zero effective hours bands as excellent,
     * so a late-discovered stamp would read as a flawless turnaround — the
     * worst possible failure direction for a figure attached to a person.
     *
     * @return void
     */
    public function test_allocation_after_grading_is_unmeasurable_not_perfect(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment([
            'markingworkflow' => 1,
            'markingallocation' => 1,
        ]);

        $tsubmit = $this->ts('2026-05-11 09:00:00');
        $tgrade = $this->ts('2026-05-12 09:00:00');
        $tlate = $this->ts('2026-05-13 09:00:00');

        $this->insert_submission((int) $assign->id, (int) $student->id, $tsubmit);
        $this->insert_grade((int) $assign->id, (int) $student->id, $tgrade, 85.0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')
            ->allocate_marker((int) $assign->id, (int) $student->id, 9999);
        submission_ledger::stamp_allocation_for_user((int) $cm->id, (int) $student->id, $tlate);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNull($row->allochours, 'An unmeasurable interval is null, never 0.0.');
        $this->assertNull($row->allocbucket);
        $this->assertSame(submission_ledger::ALLOC_SOURCE_LATE, $row->allocsource);
    }

    /**
     * A marker who inherits a long-queued submission is measured from their
     * own start, not from the first allocation — otherwise a reassignment
     * charges the new marker for their predecessor's delay.
     *
     * @return void
     */
    public function test_reassignment_measures_the_new_marker_from_their_own_start(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment([
            'markingworkflow' => 1,
            'markingallocation' => 1,
        ]);

        $tsubmit = $this->ts('2026-05-11 09:00:00');
        $tfirst = $this->ts('2026-05-11 10:00:00');
        $tsecond = $this->ts('2026-05-21 09:00:00');
        $tgrade = $this->ts('2026-05-21 11:00:00');

        $this->insert_submission((int) $assign->id, (int) $student->id, $tsubmit);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $a = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $b = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $gen = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $gen->allocate_marker((int) $assign->id, (int) $student->id, (int) $a->id);
        submission_ledger::stamp_allocation_for_user((int) $cm->id, (int) $student->id, $tfirst);

        // Reassigned ten days later; core fires no de-allocation event at all.
        $gen->allocate_marker((int) $assign->id, (int) $student->id, (int) $b->id);
        submission_ledger::stamp_allocation_for_user((int) $cm->id, (int) $student->id, $tsecond);

        $this->insert_grade((int) $assign->id, (int) $student->id, $tgrade, 85.0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame((int) $b->id, (int) $row->allocmarkerid);
        $this->assertSame($tfirst, (int) $row->timeallocated, 'The queue metric keeps the first stamp.');
        $this->assertSame($tsecond, (int) $row->timeallocmarker);
        $this->assertEqualsWithDelta(2.0, (float) $row->allochours, 0.01);
    }

    /**
     * A grade saved in the same clock second as the submission's last change
     * counts as still needing grading, matching core's own needs-grading
     * counter (`s.timemodified >= g.timemodified`). Core needs that direction
     * because it auto-creates placeholder grade rows carrying the submission's
     * own timestamp; agreeing with it at the tie is what keeps the plugin's
     * pending count identical to the one Moodle shows for the same activity.
     *
     * @return void
     */
    public function test_grade_in_the_same_second_as_the_submission_stays_pending(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $t1 = $this->ts('2026-05-11 09:00:00');
        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        $this->insert_grade((int) $assign->id, (int) $student->id, $t1, 75.0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNull($row->timegraded);
        $this->assertNull($row->timemarked);

        // One second later is a real grading and does close the row.
        $DB->set_field('assign_grades', 'timemodified', $t1 + 1, [
            'assignment' => $assign->id,
            'userid' => $student->id,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame($t1 + 1, (int) $row->timegraded);
    }

    /**
     * A user-level override changes the dates one student is judged against
     * and reaches the plugin through no other signal — the reconciler's
     * rule-drift sweep compares against the activity's own dates and
     * {assign_user_flags}, so it cannot see an {assign_overrides} row at all.
     *
     * @return void
     */
    public function test_user_override_re_resolves_that_users_rules(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = $this->ts('2026-05-20 23:59:00');
        [$cm, $student, $assign] = $this->build_environment(['duedate' => $due]);

        $t1 = $this->ts('2026-05-11 09:00:00');
        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertSame(
            $due,
            (int) $DB->get_field('block_feedback_tracker_sub', 'timecloses', ['cmid' => $cm->id])
        );

        $extended = $this->ts('2026-05-27 23:59:00');
        $DB->insert_record('assign_overrides', (object) [
            'assignid' => $assign->id, 'groupid' => null, 'userid' => $student->id,
            'sortorder' => null, 'duedate' => $extended,
            'allowsubmissionsfromdate' => null, 'cutoffdate' => null, 'timelimit' => null,
        ]);
        submission_ledger::re_resolve_rules_for_assign_user((int) $assign->id, (int) $student->id);

        $this->assertSame(
            $extended,
            (int) $DB->get_field('block_feedback_tracker_sub', 'timecloses', ['cmid' => $cm->id]),
            'The override must reach the stored rule.'
        );
    }

    /**
     * A stamp that did not move keeps the provenance it was recorded with.
     *
     * allocsource separates an instant captured from a real marker_updated
     * event (exact) from one a reconciliation sweep discovered later (accurate
     * only to the sweep period) — the whole point being that a median is never
     * built from a mix without saying so. The label was written on every row of
     * the (cmid, userid) pair on every call, so a sweep running with
     * ALLOC_SOURCE_RECONCILED relabelled rows whose stamp came from an event
     * and had not changed, quietly folding exact measurements into the
     * discovery-time population.
     *
     * @return void
     */
    public function test_an_unchanged_allocation_keeps_its_provenance(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment(['markingallocation' => 1]);

        $talloc = $this->recent_weekday_at(9);
        $this->insert_submission((int) $assign->id, (int) $student->id, $talloc - 2 * 86400);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $marker = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')
            ->allocate_marker((int) $assign->id, (int) $student->id, (int) $marker->id);
        submission_ledger::stamp_allocation_for_user(
            (int) $cm->id,
            (int) $student->id,
            $talloc,
            submission_ledger::ALLOC_SOURCE_OBSERVED
        );

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame(submission_ledger::ALLOC_SOURCE_OBSERVED, $row->allocsource);

        /* Rewind timemodified so the control is not a same-second comparison:
         * it has to prove the second call really wrote this row, or "the label
         * did not change" would also be satisfied by nothing happening. */
        $DB->set_field('block_feedback_tracker_sub', 'timemodified', 100, ['id' => $row->id]);

        // The sweep re-runs over the same, unchanged allocation.
        submission_ledger::stamp_allocation_for_user(
            (int) $cm->id,
            (int) $student->id,
            $talloc + 3600,
            submission_ledger::ALLOC_SOURCE_RECONCILED
        );

        $after = $DB->get_record('block_feedback_tracker_sub', ['id' => $row->id]);
        $this->assertGreaterThan(100, (int) $after->timemodified, 'Control: the second call wrote this row.');
        $this->assertSame(
            (int) $row->timeallocated,
            (int) $after->timeallocated,
            'Sanity: the instant itself is sticky.'
        );
        $this->assertSame(
            submission_ledger::ALLOC_SOURCE_OBSERVED,
            $after->allocsource,
            'A label may only move when the instant it describes moves.'
        );
    }

    /**
     * Reassigning the marker DOES relabel, because it records a new instant.
     *
     * The pair with the test above: confining allocsource to the first-stamp
     * branch would be the obvious way to fix that one, and it would leave a
     * reassignment wearing the previous provenance while carrying a
     * timeallocmarker this call just wrote. The marker turnaround is measured
     * from that instant, so the label has to describe it.
     *
     * @return void
     */
    public function test_reassigning_the_marker_relabels_the_provenance(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment(['markingallocation' => 1]);

        $talloc = $this->recent_weekday_at(9);
        $this->insert_submission((int) $assign->id, (int) $student->id, $talloc - 2 * 86400);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $generator = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $first = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $generator->allocate_marker((int) $assign->id, (int) $student->id, (int) $first->id);
        submission_ledger::stamp_allocation_for_user(
            (int) $cm->id,
            (int) $student->id,
            $talloc,
            submission_ledger::ALLOC_SOURCE_OBSERVED
        );

        // A different marker inherits the work, discovered by a sweep.
        $second = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $generator->allocate_marker((int) $assign->id, (int) $student->id, (int) $second->id);
        submission_ledger::stamp_allocation_for_user(
            (int) $cm->id,
            (int) $student->id,
            $talloc + 7200,
            submission_ledger::ALLOC_SOURCE_RECONCILED
        );

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame((int) $second->id, (int) $row->allocmarkerid, 'Control: the reassignment landed.');
        $this->assertSame($talloc + 7200, (int) $row->timeallocmarker, 'The new marker starts their own clock.');
        $this->assertSame(
            submission_ledger::ALLOC_SOURCE_RECONCILED,
            $row->allocsource,
            'The label describes the instant this call recorded.'
        );
    }

    /**
     * Build a processable course with an enrolled student and an assign.
     *
     * @param array $assignopts Extra {assign} settings, e.g. markingworkflow.
     * @return array The cm, the student, the assign record and the course.
     */
    private function build_environment(array $assignopts = []): array {
        $course = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        course_access::reset_memo();

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module(
            'assign',
            array_merge(['course' => $course->id], $assignopts)
        );
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        return [$cm, $student, $assign, $course];
    }

    /**
     * Insert one {assign_submission} row in the submitted state.
     *
     * @param int $assignid
     * @param int $userid
     * @param int $tsubmit
     * @return void
     */
    private function insert_submission(int $assignid, int $userid, int $tsubmit): void {
        global $DB;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assignid, 'userid' => $userid, 'attemptnumber' => 0,
            'timecreated' => $tsubmit, 'timemodified' => $tsubmit,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
    }

    /**
     * Insert one {assign_grades} row with a caller-chosen value.
     *
     * @param int $assignid
     * @param int $userid
     * @param int $tgrade
     * @param float|null $grade Null for a cleared grade, -1.0 for core's
     *                          not-set placeholder, 0.0 or more for a real mark.
     * @return void
     */
    private function insert_grade(int $assignid, int $userid, int $tgrade, ?float $grade): void {
        global $DB;
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assignid, 'userid' => $userid, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => $grade,
            'timecreated' => $tgrade, 'timemodified' => $tgrade,
        ]);
    }

    /**
     * Seed the calendar settings the academic-time engine needs.
     *
     * @return void
     */
    private function seed_calendar(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');

        global $DB;
        $now = time();
        for ($dow = 0; $dow <= 4; $dow++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek' => $dow, 'starttime' => 480, 'endtime' => 1080,
                'enabled' => 1, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        academic_time::reset_memos();
    }

    /**
     * Parse an ISO-like datetime string into a UTC timestamp.
     *
     * @param string $datetime
     * @return int
     */
    private function ts(string $datetime): int {
        return (new \DateTime($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * The most recent Tuesday at the given UTC hour.
     *
     * Tuesday is always inside the seeded weekday business hours and always
     * within the rollup's 30-day graded window, so a fixture anchored here
     * measures the interval it means to and does not rot as the suite ages.
     *
     * @param int $hour Hour of day, UTC.
     * @return int
     */
    private function recent_weekday_at(int $hour): int {
        $d = new \DateTime('last tuesday', new \DateTimeZone('UTC'));
        $d->setTime($hour, 0, 0);
        return $d->getTimestamp();
    }
}
