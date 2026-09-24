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
 * Pins the measurement model: a response is what reached the student, from
 * either surface (the activity or the gradebook), dated when it landed, and
 * never withdrawn.
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
     * The gradebook counterpart of an unreleased mark under marking workflow:
     * with the grade hidden the student sees no feedback at all.
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

        /* The visibility fact is not stored on the row: core fires no event
         * when a grade is hidden or un-hidden, and a hide-until date expires
         * by time alone, so a stored copy would go stale. It is read live, here
         * and by submission_browser at display time. */
        $live = gradebook_response::for_assign_user((int) $assign->id, (int) $student->id);
        $this->assertTrue($live['hidden'], 'But the fact is disclosed rather than left silent.');
        $this->assertTrue($live['hasgrade']);
    }

    /**
     * Deleting the gradebook grade does not withdraw the response.
     *
     * A later administrative act cannot withdraw a response the student
     * already received.
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
     * would close the new cycle before it began: a zero-hour interval, which
     * bands as the best possible result. The activity side applies the same
     * rule.
     *
     * The hand-in is exactly 7 * 86400 seconds ago, with business hours
     * seeded, so the open interval is worth five business days on whatever
     * weekday the suite runs: the partial first and last days are the same
     * weekday and sum to a whole one. A shorter window can span a weekend and
     * fall under the 24-hour `excellent` threshold on some weekdays.
     *
     * @return void
     */
    public function test_a_gradebook_instant_before_the_hand_in_is_ignored(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->seed_business_hours();
        [$cm, $student, $assign] = $this->build_environment();

        $submitted = time() - 7 * 86400;
        $stale = $submitted - 5 * 86400;
        $this->submit($assign, $student, $submitted);
        $this->gradebook_grade($assign, $student, 55.0, $stale);

        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNull($row->timeclosed, 'A response cannot predate the work it answers.');
        $this->assertNull($row->timegraded, 'And it must not clear the pending clock either.');
        /* Five business days of ten hours (08:00-18:00), measured to now because
         * the cycle is still open. Asserting the figure as well as the band
         * makes a drift in the window fail here with a number rather than
         * silently re-band. */
        $this->assertEqualsWithDelta(50.0, (float) $row->effectivehours, 0.5, 'Five ten-hour days.');
        $this->assertNotSame(
            'excellent',
            (string) $row->slabucket,
            'A zero-hour interval would band as the best possible result.'
        );
    }

    /**
     * Once the gradebook has answered, hiding the grade does not take it back.
     *
     * {grade_grades} keeps no history, so once the grade is hidden the live
     * read returns no response instant, and a later re-derivation must restore
     * both `timeclosed` and `timegraded` from the stored row. Restoring
     * `timeclosed` alone would leave the row closed and pending at once.
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

        // The grade is hidden, then something unrelated re-derives.
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
     * A held-until grade is dated when it was released, not when it was typed.
     *
     * Core overloads `hidden`: any value above 1 is a hide-until date, which is
     * the ordinary held-results workflow — mark now, publish on results day.
     * Dating the response at entry would understate the interval by the whole
     * hold, on a column whose stated meaning is when the response reached the
     * student.
     *
     * @return void
     */
    public function test_a_held_until_grade_is_dated_at_its_release(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $typed = time() - 20 * 86400;
        $released = time() - 2 * 86400;
        $this->submit($assign, $student, time() - 25 * 86400);
        $this->gradebook_grade($assign, $student, 70.0, $typed, $released);

        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame(
            $released,
            (int) $row->timeclosed,
            'The student could not see it until the hold expired.'
        );
        $this->assertSame(gradebook_response::SOURCE_GRADEBOOK, $row->closedsource);
    }

    /**
     * Feedback with no mark is still a response.
     *
     * The gradebook's feedback field runs through the same update_final_grade()
     * path and takes the same `overridden` stamp, but leaves finalgrade null.
     * Testing the grade value alone would leave a teacher who returned written
     * feedback and no mark permanently pending.
     *
     * @return void
     */
    public function test_feedback_with_no_mark_is_a_response(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $responded = time() - 2 * 86400;
        $this->submit($assign, $student, time() - 5 * 86400);
        $this->gradebook_grade($assign, $student, null, $responded, 0, 'See my comments inline.');

        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame($responded, (int) $row->timeclosed, 'Feedback reached the student.');
        $this->assertSame($responded, (int) $row->timegraded, 'So the row is no longer pending.');
    }

    /**
     * A mark made inside the activity still closes the clock while the
     * gradebook hides the grade — and the row says so.
     *
     * The model deliberately leaves this case as it is, because changing it
     * would move figures already reported; the hidden grade is disclosed
     * instead. The disclosure is asserted through `submission_browser`, which
     * the pending report reads, so it is proven to reach the teacher. The
     * gradebook row carries no `overridden` stamp: mod_assign pushes its mark
     * through `grade_update()`, which does not set that column, so detection
     * cannot key on it.
     *
     * @return void
     */
    public function test_an_activity_mark_hidden_in_the_gradebook_is_flagged(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $submitted = time() - 5 * 86400;
        $marked = time() - 2 * 86400;
        $this->submit($assign, $student, $submitted);
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'attemptnumber' => 0,
            'grader' => 2,
            'grade' => 82.0,
            'timecreated' => $marked,
            'timemodified' => $marked,
        ]);
        /* The gradebook copy mod_assign pushes: a real grade, no override
         * stamp — and the item hidden, which is how results are held back. */
        $this->gradebook_grade($assign, $student, 82.0, 0);
        $itemid = $DB->get_field_sql(
            "SELECT id FROM {grade_items}
              WHERE itemtype = :t AND itemmodule = :m AND iteminstance = :a AND itemnumber = 0",
            ['t' => 'mod', 'm' => 'assign', 'a' => $assign->id]
        );
        $DB->set_field('grade_items', 'hidden', 1, ['id' => $itemid]);

        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame(
            $marked,
            (int) $row->timeclosed,
            'The activity mark still stops the clock — that is the deliberate exception.'
        );
        $this->assertSame(gradebook_response::SOURCE_ASSIGN, $row->closedsource);

        $teacher = $this->getDataGenerator()->create_and_enrol(
            get_course((int) $row->courseid),
            'editingteacher'
        );
        $browsed = submission_browser::browse(
            (int) $row->courseid,
            (int) $teacher->id,
            ['mode' => submission_browser::MODE_GRADED]
        );
        $this->assertCount(1, $browsed['rows'], 'The row belongs on the graded tab.');
        $this->assertSame(
            1,
            (int) $browsed['rows'][0]['gradehidden'],
            'And the teacher has to be told the grade still needs releasing.'
        );
    }

    /**
     * The gradebook never closes the marker's own clock.
     *
     * queuehours and allochours measure the allocated marker's turnaround, and
     * a grade typed into the gradebook is often a coordinator's act, so
     * crediting it would measure the wrong person.
     *
     * allochours runs to time(), which no fixture can pin, so the fixture
     * controls the width between the gradebook answer and now: exactly
     * 7 * 86400. That width covers every weekday once whatever the hour (the
     * partial head and tail days are the same weekday and sum to one whole
     * day), so the gap is a constant 50.0 effective hours, five ten-hour days.
     * A narrower window can fall inside the business-hour-free stretch from
     * Friday 18:00 to Monday 08:00 UTC, where the two measures coincide.
     * Pinning an absolute instant, as recent_weekday_at() in
     * grading_cycle_test does, settles nothing when the far end is now.
     *
     * @return void
     */
    public function test_a_gradebook_response_leaves_the_marker_clock_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        /* The assertion below states an hours figure, so the test seeds the
         * working day itself rather than relying on db/install.php. */
        $this->seed_business_hours();
        [$cm, $student, $assign] = $this->build_environment();

        /* Every instant hangs off the gradebook answer, so the week between
         * it and now stays exactly a week; see the docblock. */
        $answered = time() - 7 * 86400;
        $submitted = $answered - 2 * 86400;
        $allocated = $answered - 86400;

        $marker = $this->getDataGenerator()->create_user();
        $this->submit($assign, $student, $submitted);
        /* The ledger row must exist before the allocation is stamped on to it:
         * stamping first writes nothing, and the assertions below would then
         * hold vacuously. */
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->allocate_marker($assign, $student, $marker);
        submission_ledger::stamp_allocation_for_user((int) $cm->id, (int) $student->id, $allocated);
        $this->assertNotNull(
            $DB->get_field('block_feedback_tracker_sub', 'timeallocmarker', ['cmid' => $cm->id]),
            'The fixture is only meaningful once a marker allocation is on the row.'
        );

        $this->gradebook_grade($assign, $student, 70.0, $answered);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotNull($row->timeclosed, 'The student clock did close.');
        $this->assertNotNull($row->timegraded, 'And so did the pending clock — that is the point.');

        /* The marker's interval runs to now, because nobody has marked inside
         * the activity yet; stopped at the gradebook response it would equal
         * the closed value below. That value is read through
         * elapsed_effective_hours(), the entry point production uses for
         * allochours: elapsed_with_audit() does not take the same fast path. */
        $closedvalue = academic_time::elapsed_effective_hours(
            (int) $row->courseid,
            (int) $row->groupid,
            (int) $row->timeallocmarker,
            (int) $row->timeclosed
        );
        $this->assertGreaterThan(
            round((float) $closedvalue, 2),
            round((float) $row->allochours, 2),
            'A coordinator grading in the gradebook must not close the allocated marker\'s clock.'
        );
        /* The inequality above holds for any upper bound after the gradebook
         * answer; the exact figure (the week since the answer is worth five
         * ten-hour days) also catches a clock stopped short of now. */
        $this->assertEqualsWithDelta(
            $closedvalue + 50.0,
            (float) $row->allochours,
            0.05,
            'The marker\'s clock ran on for the whole week since the gradebook answered.'
        );
    }

    /**
     * Allocate a marker to a student, on whichever table this Moodle keeps
     * marking allocation in.
     *
     * Moodle 5.2 moved allocation from {assign_user_flags}.allocatedmarker to
     * its own {assign_allocated_marker} table, and production code branches on
     * which exists; a fixture writing one table unconditionally would silently
     * stop allocating on the other branches.
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
     * @param float|null $grade Null for a feedback-only response.
     * @param int $when The override instant.
     * @param int $hidden {grade_grades}.hidden — 1 hides, a larger value is hidden-until.
     * @param string|null $feedback Written feedback, when there is no mark.
     * @return void
     */
    private function gradebook_grade(
        \stdClass $assign,
        \stdClass $user,
        ?float $grade,
        int $when,
        int $hidden = 0,
        ?string $feedback = null
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
            'feedback' => $feedback,
            'overridden' => $when,
            'hidden' => $hidden,
            'timecreated' => $when,
            /* Deliberately well after the override: regrades and recomputes
             * move timemodified but never overridden, so keeping the two apart
             * makes a model keyed on the wrong column fail these tests. */
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

    /**
     * Seed Monday-to-Friday business hours, 08:00 to 18:00.
     *
     * `seed_calendar()` switches business hours on without inserting any rows,
     * so the width of a working day would come from the db/install.php defaults
     * rather than from the test. The installed rows are deleted first: the
     * engine unions every row of a day, so rows added beside them could only
     * widen the day, never set it. Needed by any test that asserts on hours or
     * on a band derived from them.
     *
     * @return void
     */
    private function seed_business_hours(): void {
        global $DB;
        $DB->delete_records('block_feedback_tracker_chours');
        $now = time();
        for ($dow = 0; $dow <= 4; $dow++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek' => $dow,
                'starttime' => 480,
                'endtime' => 1080,
                'enabled' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        academic_time::reset_memos();
    }
}
