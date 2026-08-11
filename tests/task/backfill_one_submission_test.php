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
 * Tests for the backfill_one_submission adhoc task.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\sla\course_access;

/**
 * Per-batch worker that takes a list of (cmid, userid, attemptnumber,
 * courseid) tuples from custom data and writes one ledger row per tuple
 * via `submission_ledger::upsert_for_cm_user_attempt()`. Idempotent;
 * re-checks `course_access::is_processable()` at execute time.
 *
 * @covers \block_feedback_tracker\task\backfill_one_submission
 */
final class backfill_one_submission_test extends \advanced_testcase {
    public function test_empty_payload_is_noop(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        $task = new backfill_one_submission();
        $task->set_custom_data(['rows' => []]);
        $task->execute();

        global $DB;
        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_sub'));
    }

    public function test_processes_each_row(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $students] = $this->build_course_with_submissions(3);

        $rows = [];
        foreach ($students as $student) {
            $rows[] = [
                'cmid'          => (int) $cm->id,
                'userid'        => (int) $student->id,
                'attemptnumber' => 0,
                'courseid'      => (int) $cm->course,
            ];
        }
        $task = new backfill_one_submission();
        $task->set_custom_data(['rows' => $rows]);
        $task->execute();

        global $DB;
        $this->assertSame(3, (int) $DB->count_records('block_feedback_tracker_sub'));
    }

    public function test_idempotent_on_repeat_execution(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $students] = $this->build_course_with_submissions(2);

        $rows = [];
        foreach ($students as $student) {
            $rows[] = [
                'cmid'          => (int) $cm->id,
                'userid'        => (int) $student->id,
                'attemptnumber' => 0,
                'courseid'      => (int) $cm->course,
            ];
        }
        $task = new backfill_one_submission();
        $task->set_custom_data(['rows' => $rows]);
        $task->execute();
        $task->execute();

        global $DB;
        $this->assertSame(2, (int) $DB->count_records('block_feedback_tracker_sub'));
    }

    public function test_skips_rows_in_non_processable_course(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        course_access::reset_memo();

        // Course with the block — processable.
        [$cma, $studentsa] = $this->build_course_with_submissions(1);

        // Course without the block — non-processable.
        $courseb = $this->getDataGenerator()->create_course();
        $assignb = $this->getDataGenerator()->create_module('assign', ['course' => $courseb->id]);
        $cmb = get_coursemodule_from_instance('assign', $assignb->id);
        $studentb = $this->getDataGenerator()->create_and_enrol($courseb, 'student');
        global $DB;
        $now = time();
        $DB->insert_record('assign_submission', (object) [
            'assignment'    => $assignb->id,
            'userid'        => $studentb->id,
            'attemptnumber' => 0,
            'timecreated'   => $now - 3600,
            'timemodified'  => $now - 3600,
            'status'        => 'submitted',
            'groupid'       => 0,
            'latest'        => 1,
        ]);
        course_access::reset_memo();

        $task = new backfill_one_submission();
        $task->set_custom_data([
            'rows' => [
                [
                    'cmid'          => (int) $cma->id,
                    'userid'        => (int) reset($studentsa)->id,
                    'attemptnumber' => 0,
                    'courseid'      => (int) $cma->course,
                ],
                [
                    'cmid'          => (int) $cmb->id,
                    'userid'        => (int) $studentb->id,
                    'attemptnumber' => 0,
                    'courseid'      => (int) $courseb->id,
                ],
            ],
        ]);
        $task->execute();

        $rows = $DB->get_records('block_feedback_tracker_sub');
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame((int) $cma->course, (int) $row->courseid);
    }

    /**
     * A repair queued before the student left is dropped when it finally runs.
     *
     * The reconciler's delete-side sweep removes the rows of people who are no
     * longer active participants, while its dispatching sweeps queue repairs
     * for rows they selected earlier. Nothing makes those two happen in the
     * same tick: core backs a failed adhoc task off from 60 seconds to 86400,
     * so a repair can legitimately land a day after it was dispatched, long
     * after the delete. Re-checking processability is not enough — the course
     * is still perfectly processable; it is the person who left.
     *
     * The still-enrolled student is the control. Without them this test would
     * pass if the task had simply not run.
     *
     * @return void
     */
    public function test_a_repair_for_someone_who_left_is_dropped_at_execute_time(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        course_access::reset_memo();

        [$cm, $students] = $this->build_course_with_submissions(2);
        [$stays, $leaves] = array_values($students);

        /* Suspending the user enrolment is what get_enrolled_sql($ctx, '', 0,
         * true) — the predicate the delete-side sweep uses — treats as gone.
         * Scoped to user_enrolments, not enrol, so the control student's own
         * enrolment is untouched: enrol.status is per instance and both share
         * one manual instance. */
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $leaves->id]);

        $task = new backfill_one_submission();
        $task->set_custom_data([
            'rows' => [
                [
                    'cmid'          => (int) $cm->id,
                    'userid'        => (int) $stays->id,
                    'attemptnumber' => 0,
                    'courseid'      => (int) $cm->course,
                ],
                [
                    'cmid'          => (int) $cm->id,
                    'userid'        => (int) $leaves->id,
                    'attemptnumber' => 0,
                    'courseid'      => (int) $cm->course,
                ],
            ],
        ]);
        $task->execute();

        $this->assertTrue(
            $DB->record_exists('block_feedback_tracker_sub', ['userid' => $stays->id]),
            'Control: the still-enrolled student must be repaired, or nothing ran.'
        );
        $this->assertFalse(
            $DB->record_exists('block_feedback_tracker_sub', ['userid' => $leaves->id]),
            'A repair must not rebuild the row of someone the delete sweep removed.'
        );
    }

    // Helpers.

    /**
     * Build a course with the block, an assign instance, and $count students
     * who each have one assign_submission row.
     *
     * @param int $count Number of students/submissions to create.
     * @return array{0: \stdClass, 1: array<int, \stdClass>} [cm, students]
     */
    private function build_course_with_submissions(int $count): array {
        $course = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        course_access::reset_memo();

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        global $DB;
        $now = time();
        $students = [];
        for ($i = 0; $i < $count; $i++) {
            $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $DB->insert_record('assign_submission', (object) [
                'assignment'    => $assign->id,
                'userid'        => $student->id,
                'attemptnumber' => 0,
                'timecreated'   => $now - ($i + 1) * 3600,
                'timemodified'  => $now - ($i + 1) * 3600,
                'status'        => 'submitted',
                'groupid'       => 0,
                'latest'        => 1,
            ]);
            $students[] = $student;
        }
        return [$cm, $students];
    }

    /**
     * Seeds calendar configuration, business hours, and SLA settings for testing.
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
}
