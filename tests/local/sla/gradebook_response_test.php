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
 * Tests for the gradebook as a second source of the student's response clock.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;

/**
 * The measurement model: a response is what reached the student, from either
 * surface, dated when it landed, and never withdrawn. Four rules carry it, and
 * each is pinned here because each was a decision rather than a consequence.
 *
 * @covers \block_feedback_tracker\local\sla\gradebook_response
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 */
final class gradebook_response_test extends \advanced_testcase {
    /**
     * Flush per-request memos that survive resetAfterTest().
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        submission_ledger::reset_memos();
        group_resolver::reset_memo();
        course_access::reset_memo();
    }

    /**
     * A grade entered only in the gradebook closes the cycle, and says so.
     *
     * Without this the submission is pending for ever: {assign_grades} has no
     * row at all, so every stamp the plugin reads is null while the student is
     * looking at their mark.
     *
     * @return void
     */
    public function test_a_gradebook_only_grade_closes_the_cycle(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $submitted = time() - 5 * 86400;
        $responded = time() - 2 * 86400;
        $this->submit($assign, $student, $submitted);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_sub', 'timeclosed', ['cmid' => $cm->id]),
            'Nothing has answered yet.'
        );

        $this->gradebook_grade($assign, $student, 70.0, $responded);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame($responded, (int) $row->timeclosed);
        $this->assertSame(gradebook_response::SOURCE_GRADEBOOK, $row->closedsource);
    }

    /**
     * A hidden gradebook grade is not a response.
     *
     * Same reasoning the marking-workflow branch already applies to an
     * unreleased mark, applied to the gradebook's own release gate: with the
     * grade hidden the student sees no feedback block at all.
     *
     * @return void
     */
    public function test_a_hidden_gradebook_grade_does_not_close_the_cycle(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $this->submit($assign, $student, time() - 5 * 86400);
        $this->gradebook_grade($assign, $student, 70.0, time() - 2 * 86400, 1);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNull($row->timeclosed, 'A grade the student cannot see has reached nobody.');
        $this->assertNull($row->closedsource);
        $this->assertSame(1, (int) $row->gradehidden, 'But the fact is disclosed rather than hidden.');
    }

    /**
     * Deleting the gradebook grade does not withdraw the response.
     *
     * This is the earliest-wins half of the model, and the reason the whole
     * thing is monotone: a later administrative act cannot un-happen a
     * response the student already received.
     *
     * @return void
     */
    public function test_deleting_the_gradebook_grade_does_not_reopen(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $responded = time() - 2 * 86400;
        $this->submit($assign, $student, time() - 5 * 86400);
        $this->gradebook_grade($assign, $student, 70.0, $responded);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertSame(
            $responded,
            (int) $DB->get_field('block_feedback_tracker_sub', 'timeclosed', ['cmid' => $cm->id])
        );

        $DB->set_field_select(
            'grade_grades',
            'finalgrade',
            null,
            'itemid IN (SELECT id FROM {grade_items} WHERE iteminstance = :a AND itemmodule = :m)',
            ['a' => $assign->id, 'm' => 'assign']
        );
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame($responded, (int) $row->timeclosed, 'The response already reached the student.');
        $this->assertSame(gradebook_response::SOURCE_GRADEBOOK, $row->closedsource);
    }

    /**
     * A gradebook instant that predates the hand-in never closes the cycle.
     *
     * {grade_grades} holds one grade per user per item, with no attempt or
     * cycle dimension, so a resubmission opens a cycle whose hand-in postdates
     * an override made against the previous one. Applying that stale instant
     * would close the new cycle before it began — a zero-hour interval, which
     * bands as the best possible result and enters the medians and the score.
     * The activity side has always required the response to postdate the work;
     * this is the same rule.
     *
     * @return void
     */
    public function test_a_gradebook_instant_before_the_hand_in_is_ignored(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $stale = time() - 9 * 86400;
        $submitted = time() - 3 * 86400;
        $this->submit($assign, $student, $submitted);
        $this->gradebook_grade($assign, $student, 55.0, $stale);

        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNull($row->timeclosed, 'A response cannot predate the work it answers.');
        $this->assertNull($row->timegraded, 'And it must not clear the pending clock either.');
        $this->assertNotSame(
            'excellent',
            (string) $row->slabucket,
            'A zero-hour interval would band as the best possible result.'
        );
    }

    /**
     * Once the gradebook has answered, hiding the grade does not take it back.
     *
     * {grade_grades} keeps no history, so the live read simply goes quiet when
     * the grade is hidden or cleared. Any re-derivation driven by something
     * else entirely — a student save, an activity settings change, a rule
     * sweep — would then silently withdraw the response. Both clocks have to
     * be restored from the stored row, not just the disclosure one: restoring
     * `timeclosed` alone leaves the row closed and pending at the same time.
     *
     * @return void
     */
    public function test_hiding_the_grade_afterwards_does_not_withdraw_the_response(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $responded = time() - 2 * 86400;
        $this->submit($assign, $student, time() - 5 * 86400);
        $this->gradebook_grade($assign, $student, 70.0, $responded);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertSame(
            $responded,
            (int) $DB->get_field('block_feedback_tracker_sub', 'timegraded', ['cmid' => $cm->id])
        );

        // The coordinator hides the column, then something unrelated re-derives.
        $this->gradebook_grade($assign, $student, 70.0, $responded, 1);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame($responded, (int) $row->timeclosed, 'The response stands.');
        $this->assertSame(
            $responded,
            (int) $row->timegraded,
            'And the row must not slide back into the pending count.'
        );
    }

    /**
     * The gradebook never closes the marker's own clock.
     *
     * queuehours and allochours measure the allocated marker's turnaround, and
     * a grade typed into the gradebook is frequently a coordinator's act.
     * Crediting it would measure the wrong person on a figure carrying their
     * name — the same refusal the ALLOC_SOURCE_LATE constant already encodes.
     *
     * @return void
     */
    public function test_a_gradebook_response_leaves_the_marker_clock_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $marker = $this->getDataGenerator()->create_user();
        $this->submit($assign, $student, time() - 5 * 86400);
        /* The ledger row has to exist before the allocation can be stamped on
         * to it — stamping first would write nothing at all, and the assertion
         * below would then hold for the wrong reason. */
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->allocate_marker($assign, $student, $marker);
        submission_ledger::stamp_allocation_for_user((int) $cm->id, (int) $student->id, time() - 4 * 86400);
        $this->assertNotNull(
            $DB->get_field('block_feedback_tracker_sub', 'timeallocmarker', ['cmid' => $cm->id]),
            'The fixture is only meaningful once a marker allocation is on the row.'
        );

        $this->gradebook_grade($assign, $student, 70.0, time() - 2 * 86400);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotNull($row->timeclosed, 'The student clock did close.');
        $this->assertNotNull($row->timegraded, 'And so did the pending clock — that is the point.');

        /* The marker's interval is still running. Measured against the
         * gradebook response it would read as the two days from allocation to
         * that grade; measured correctly it runs to now, because nobody has
         * marked inside the activity yet. Comparing against the closed value
         * rather than asserting a business-hours figure keeps the test exact
         * without pinning the academic calendar. */
        $closedvalue = academic_time::elapsed_with_audit(
            (int) $row->courseid,
            (int) $row->groupid,
            (int) $row->timeallocmarker,
            (int) $row->timeclosed
        )['hours'];
        $this->assertNotEquals(
            round((float) $closedvalue, 2),
            round((float) $row->allochours, 2),
            'A coordinator grading in the gradebook must not close the allocated marker\'s clock.'
        );
    }

    /**
     * Allocate a marker to a student, on whichever table this Moodle keeps
     * marking allocation in.
     *
     * 5.2 moved allocation out of {assign_user_flags}.allocatedmarker into its
     * own {assign_allocated_marker} table. Production code already branches on
     * that; a fixture that wrote one table unconditionally would pass on the
     * branch it was written against and silently stop allocating on the other,
     * leaving the assertions here true for the wrong reason.
     *
     * @param \stdClass $assign
     * @param \stdClass $student
     * @param \stdClass $marker
     * @return void
     */
    private function allocate_marker(\stdClass $assign, \stdClass $student, \stdClass $marker): void {
        global $DB;
        if ($DB->get_manager()->table_exists('assign_allocated_marker')) {
            $DB->insert_record('assign_allocated_marker', (object) [
                'assignment' => $assign->id,
                'student' => $student->id,
                'marker' => $marker->id,
            ]);
            return;
        }
        $DB->insert_record('assign_user_flags', (object) [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'locked' => 0,
            'mailed' => 0,
            'extensionduedate' => 0,
            'workflowstate' => '',
            'allocatedmarker' => $marker->id,
        ]);
    }

    /**
     * Write a gradebook grade for one user on one assign, the way a teacher
     * grading in the grader report does: an overridden final grade.
     *
     * @param \stdClass $assign
     * @param \stdClass $user
     * @param float $grade
     * @param int $when The override instant.
     * @param int $hidden {grade_grades}.hidden — 1 hides, a larger value is hidden-until.
     * @return void
     */
    private function gradebook_grade(
        \stdClass $assign,
        \stdClass $user,
        float $grade,
        int $when,
        int $hidden = 0
    ): void {
        global $DB;
        $itemid = $DB->get_field_sql(
            "SELECT id FROM {grade_items}
              WHERE itemtype = :t AND itemmodule = :m AND iteminstance = :a AND itemnumber = 0",
            ['t' => 'mod', 'm' => 'assign', 'a' => $assign->id]
        );
        $this->assertNotEmpty($itemid, 'The assign must own a grade item for the fixture to mean anything.');

        $existing = $DB->get_record('grade_grades', ['itemid' => $itemid, 'userid' => $user->id]);
        $record = (object) [
            'itemid' => $itemid,
            'userid' => $user->id,
            'rawgrade' => $grade,
            'finalgrade' => $grade,
            'overridden' => $when,
            'hidden' => $hidden,
            'timecreated' => $when,
            /* Deliberately later than the override, and by a lot. A course
             * regrade, a calculated-item recompute or 5.2's penalty manager
             * all move timemodified and never touch overridden, so a model
             * keyed on the wrong column would date every response to whenever
             * an admin last touched the gradebook. Keeping the two apart is
             * what makes that mistake visible to these tests. */
            'timemodified' => $when + 3 * 86400,
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('grade_grades', $record);
            return;
        }
        $DB->insert_record('grade_grades', $record);
    }

    /**
     * Insert one submitted {assign_submission} row.
     *
     * @param \stdClass $assign
     * @param \stdClass $user
     * @param int $when
     * @return void
     */
    private function submit(\stdClass $assign, \stdClass $user, int $when): void {
        global $DB;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id,
            'userid' => $user->id,
            'attemptnumber' => 0,
            'timecreated' => $when,
            'timemodified' => $when,
            'status' => submission_status::SUBMITTED,
            'groupid' => 0,
            'latest' => 1,
        ]);
    }

    /**
     * Build a processable course with an enrolled student and an assign.
     *
     * @return array The cm, the student and the assign record.
     */
    private function build_environment(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $assign = $DB->get_record('assign', ['id' => $instance->id], '*', MUST_EXIST);
        return [$cm, $student, $assign];
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
    }
}
