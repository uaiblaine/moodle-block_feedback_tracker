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
 * Tests for the submission ledger's public API.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;

/**
 * Direct calls to upsert / re-resolve / re-attribute / delete-cm /
 * delete-course paths.
 *
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 */
final class submission_ledger_test extends \advanced_testcase {
    /**
     * Reset the static memos the ledger upsert path consults.
     *
     * resetAfterTest() does not reset PHP statics, so the grader-filter memo
     * (keyed by courseid:userid) would otherwise skip a fresh student whose
     * recycled user id matches a teacher memoised by an earlier test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        submission_ledger::reset_memos();
        group_resolver::reset_memo();
    }

    /**
     * Upserting twice for the same (cmid, userid, attempt) updates the row
     * in place rather than inserting a duplicate.
     */
    public function test_upsert_is_idempotent(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        // The upsert mirrors an existing {assign_submission} row; without one it
        // returns null and creates no ledger row.
        $this->insert_assign_submission((int) $assign->id, (int) $student->id, time() - 3600, 'submitted');

        $a = submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $b = submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $this->assertSame($a, $b);

        global $DB;
        $count = $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame(1, $count);
    }

    /**
     * After grading, timegraded, effectivehours and slabucket are populated.
     */
    public function test_upsert_after_grading_populates_effective_and_pauses(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $tsubmit = $this->ts('2026-05-15 17:00:00');
        $tgrade  = $this->ts('2026-05-18 09:00:00');
        $this->insert_assign_submission((int) $assign->id, (int) $student->id, $tsubmit, 'submitted');
        $this->insert_assign_grade((int) $assign->id, (int) $student->id, $tgrade);

        $subid = submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertNotNull($subid);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_sub', ['id' => $subid]);
        $this->assertSame($tgrade, (int) $row->timegraded);
        $this->assertEqualsWithDelta(2.0, (float) $row->effectivehours, 0.01);
        $this->assertSame(bucket::EXCELLENT, $row->slabucket);

        /* Pause windows are not stored; 2 effective hours over a 64-hour raw
         * wait (Friday 17:00 to Monday 09:00) shows the pauses were applied. */
    }

    /**
     * delete_for_cm drops ledger rows and enqueues the affected
     * (course, group) tuple.
     */
    public function test_delete_for_cm_drops_rows_and_enqueues(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $this->insert_assign_submission((int) $assign->id, (int) $student->id, time() - 3600, 'submitted');
        $subid = submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertNotNull($subid);

        global $DB;
        $DB->delete_records('block_feedback_tracker_queue');

        submission_ledger::delete_for_cm((int) $cm->id);

        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));
        $this->assertGreaterThanOrEqual(1, (int) $DB->count_records('block_feedback_tracker_queue'));
    }

    /**
     * delete_for_course drops every plugin-owned course row.
     */
    public function test_delete_for_course_drops_everything(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment();

        $this->insert_assign_submission((int) $assign->id, (int) $student->id, time() - 3600, 'submitted');
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        global $DB;
        $this->assertGreaterThan(0, $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id]));

        submission_ledger::delete_for_course((int) $course->id);

        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id]));
        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_group', ['courseid' => $course->id]));
        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_queue', ['courseid' => $course->id]));
    }

    /**
     * reattribute_user moves the ledger row to the user's new latest-joined
     * group and enqueues both the old and new (course, group) tuples.
     */
    public function test_reattribute_user_moves_row_and_enqueues_both(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment();

        $group1 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group1->id, 'userid' => $student->id,
        ]);

        $this->insert_assign_submission((int) $assign->id, (int) $student->id, time() - 3600, 'submitted');
        group_resolver::reset_memo();
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame((int) $group1->id, (int) $row->groupid);

        // Clear the queue from the initial upsert, then add the student to
        // group2: group_member_added reaches reattribute_user() through
        // observer::group_membership_changed().
        $DB->delete_records('block_feedback_tracker_queue');
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group2->id, 'userid' => $student->id,
        ]);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame((int) $group2->id, (int) $row->groupid);

        $queueids = array_map(static fn($r) => (int) $r->groupid, $DB->get_records('block_feedback_tracker_queue'));
        $this->assertContains((int) $group1->id, $queueids);
        $this->assertContains((int) $group2->id, $queueids);
    }

    /**
     * A submission made by a user who holds mod/assign:grade in the course
     * (editing teacher, manager, role-switched admin) is filtered out — no
     * ledger row is created. A real student's submission in the same
     * assignment is still recorded.
     */
    public function test_grader_submission_is_skipped(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        set_config('exclude_grader_submissions', '1', 'block_feedback_tracker');

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $this->insert_assign_submission((int) $assign->id, (int) $teacher->id, time() - 3600, 'submitted');
        $this->insert_assign_submission((int) $assign->id, (int) $student->id, time() - 1800, 'submitted');

        $teacherresult = submission_ledger::upsert_for_cm_user_attempt(
            (int) $cm->id,
            (int) $teacher->id,
            0
        );
        $studentresult = submission_ledger::upsert_for_cm_user_attempt(
            (int) $cm->id,
            (int) $student->id,
            0
        );

        $this->assertNull($teacherresult);
        $this->assertNotNull($studentresult);

        global $DB;
        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_sub', [
            'cmid' => $cm->id, 'userid' => $teacher->id,
        ]));
        $this->assertSame(1, (int) $DB->count_records('block_feedback_tracker_sub', [
            'cmid' => $cm->id, 'userid' => $student->id,
        ]));
    }

    /**
     * An {assign_grades} row without a real grade does not make the
     * submission graded, even when it is newer than the submission.
     *
     * mod_assign has two "no grade" values: -1, written when
     * assign::get_user_grade() creates the row, and NULL, stored when the
     * grading form saves an empty grade. A grade of 0 is a real grade.
     */
    public function test_upsert_with_null_or_negative_grade_stays_pending(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $tsubmit = $this->ts('2026-05-15 17:00:00');
        $ttouched = $this->ts('2026-05-15 17:30:00'); // After the submission, so only the grade value decides.

        $this->insert_assign_submission((int) $assign->id, (int) $student->id, $tsubmit, 'submitted');
        // Variant A: grade column NULL.
        $this->insert_assign_grade_raw(
            (int) $assign->id,
            (int) $student->id,
            $ttouched,
            null
        );

        $subid = submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertNotNull($subid);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_sub', ['id' => $subid]);
        $this->assertNull($row->timegraded, 'NULL grade must not count as graded.');

        // Variant B: grade column -1, as assign::get_user_grade() writes when it creates the row.
        $DB->delete_records('assign_grades', ['assignment' => $assign->id, 'userid' => $student->id]);
        $this->insert_assign_grade_raw(
            (int) $assign->id,
            (int) $student->id,
            $ttouched,
            -1.0
        );

        $subid = submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $row = $DB->get_record('block_feedback_tracker_sub', ['id' => $subid]);
        $this->assertNull($row->timegraded, 'Sentinel grade=-1 must not count as graded.');

        // Sanity: a real grade of 0 (zero score, but actually graded) IS graded.
        $DB->delete_records('assign_grades', ['assignment' => $assign->id, 'userid' => $student->id]);
        $this->insert_assign_grade_raw(
            (int) $assign->id,
            (int) $student->id,
            $ttouched,
            0.0
        );
        $subid = submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $row = $DB->get_record('block_feedback_tracker_sub', ['id' => $subid]);
        $this->assertSame($ttouched, (int) $row->timegraded, 'Grade=0 IS a real grade.');
    }

    /**
     * With exclude_grader_submissions off, teacher submissions are recorded.
     */
    public function test_grader_filter_disabled_records_teacher_submission(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        set_config('exclude_grader_submissions', '0', 'block_feedback_tracker');

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $this->insert_assign_submission((int) $assign->id, (int) $teacher->id, time() - 3600, 'submitted');

        $result = submission_ledger::upsert_for_cm_user_attempt(
            (int) $cm->id,
            (int) $teacher->id,
            0
        );

        $this->assertNotNull($result);

        global $DB;
        $this->assertSame(1, (int) $DB->count_records('block_feedback_tracker_sub', [
            'cmid' => $cm->id, 'userid' => $teacher->id,
        ]));
    }

    /**
     * Grading a student who never submitted — an {assign_grades} row exists but
     * there is no {assign_submission} row — must NOT create a ledger row. The
     * plugin measures feedback turnaround on real submissions, not gradings
     * entered without one.
     */
    public function test_grade_without_submission_creates_no_row(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        // A real grade, but deliberately NO assign_submission row.
        $this->insert_assign_grade((int) $assign->id, (int) $student->id, time() - 1800);

        $subid = submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $this->assertNull($subid);

        global $DB;
        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_sub', [
            'cmid' => $cm->id, 'userid' => $student->id,
        ]));
    }

    // Helpers.

    /**
     * Build a tracked course with an enrolled student and an assign.
     *
     * @return array{0:\stdClass, 1:\stdClass, 2:\stdClass, 3:\stdClass} The cm, the student, the assign and the course.
     */
    private function build_environment(): array {
        $course = $this->getDataGenerator()->create_course();
        /* Direct submission_ledger calls bypass the course_access gate, but
         * the event-driven paths (group_member_added reaching
         * reattribute_user()) need a course-context block instance. The memo
         * is reset for recycled course ids. */
        $coursectx = \context_course::instance($course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        course_access::reset_memo();

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        return [$cm, $student, $assign, $course];
    }

    /**
     * Helper to insert a mock assignment submission.
     *
     * @param int $assignid The assignment ID.
     * @param int $userid The user ID.
     * @param int $tsubmit The submission timestamp.
     * @param string $status The submission status.
     * @return void
     */
    private function insert_assign_submission(int $assignid, int $userid, int $tsubmit, string $status): void {
        global $DB;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assignid, 'userid' => $userid, 'attemptnumber' => 0,
            'timecreated' => $tsubmit, 'timemodified' => $tsubmit,
            'status' => $status, 'groupid' => 0, 'latest' => 1,
        ]);
    }

    /**
     * Helper to insert a mock assignment grade.
     *
     * @param int $assignid The assignment ID.
     * @param int $userid The user ID.
     * @param int $tgrade The grading timestamp.
     * @return void
     */
    private function insert_assign_grade(int $assignid, int $userid, int $tgrade): void {
        global $DB;
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assignid, 'userid' => $userid, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => 75.0,
            'timecreated' => $tgrade, 'timemodified' => $tgrade,
        ]);
    }

    /**
     * Insert an assign_grades row with a caller-chosen grade value, for the
     * "no grade" cases the fixed 75.0 of insert_assign_grade() cannot express.
     *
     * @param int $assignid
     * @param int $userid
     * @param int $tgrade
     * @param float|null $grade Pass null for "grade column is NULL", -1.0 for
     *                          the "not yet graded" sentinel, 0.0+ for real grades.
     * @return void
     */
    private function insert_assign_grade_raw(int $assignid, int $userid, int $tgrade, ?float $grade): void {
        global $DB;
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assignid, 'userid' => $userid, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => $grade,
            'timecreated' => $tgrade, 'timemodified' => $tgrade,
        ]);
    }

    /**
     * Helper to seed standard calendar settings and business hours.
     *
     * @return void
     */
    private function seed_calendar(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('grading_during_pause_mode', 'clipped', 'block_feedback_tracker');
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
     * Helper to parse a datetime string into a UTC timestamp.
     *
     * @param string $datetime The ISO-like datetime string.
     * @return int The UTC timestamp.
     */
    private function ts(string $datetime): int {
        return (new \DateTime($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
