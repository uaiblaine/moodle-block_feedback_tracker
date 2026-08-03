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
 * Tests for the ledger reconciliation task.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\group_resolver;
use block_feedback_tracker\local\sla\submission_ledger;
use block_feedback_tracker\local\sla\submission_status;

/**
 * The reconciler is the only repair path for the mod_assign mutations that
 * emit no usable event. These tests pin one representative of each class, plus
 * the convergence property: a second sweep over an already-correct ledger must
 * find nothing, or the task would re-dispatch the same repair for ever.
 *
 * @covers \block_feedback_tracker\task\reconcile_ledger
 */
final class reconcile_ledger_test extends \advanced_testcase {
    /**
     * Flush per-request statics that survive resetAfterTest(), and swallow the
     * task's mtrace() output — PHPUnit 11 treats unexpected stdout as a risky
     * test. The trace lines are operational logging, not assertions
     * (setOutputCallback() was removed in PHPUnit 10+).
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        submission_ledger::reset_memos();
        group_resolver::reset_memo();
        ob_start();
    }

    /**
     * Tear down the per-test output buffer.
     *
     * @return void
     */
    protected function tearDown(): void {
        ob_end_clean();
        parent::tearDown();
    }

    /**
     * A submission the plugin never heard about — the fingerprint of
     * `add_attempt()`, which fires no event at all — is picked up and given a
     * ledger row.
     *
     * @return void
     */
    public function test_submission_with_no_ledger_row_is_repaired(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment();

        $now = time();
        $this->insert_submission((int) $assign->id, (int) $student->id, $now - 4 * 86400, 0);
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));

        $this->run_reconciler();

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotEmpty($row, 'The reconciler must notice a submission with no ledger row.');
        $this->assertSame((int) $student->id, (int) $row->userid);
        $this->assertSame((int) $course->id, (int) $row->courseid);
    }

    /**
     * Blind marking suppresses `submission_graded` for the whole activity, so
     * a graded submission sits in the ledger as pending with no event ever
     * arriving. The divergence sweep is what closes it.
     *
     * @return void
     */
    public function test_grade_the_plugin_never_saw_is_repaired(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment(['blindmarking' => 1]);

        $now = time();
        $tsubmit = $now - 4 * 86400;
        $tgrade = $now - 2 * 86400;
        $this->insert_submission((int) $assign->id, (int) $student->id, $tsubmit, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_sub', 'timegraded', ['cmid' => $cm->id])
        );

        // Graded behind the blind-marking curtain: no event is emitted.
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => 70.0, 'timecreated' => $tgrade, 'timemodified' => $tgrade,
        ]);

        $this->run_reconciler();

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame($tgrade, (int) $row->timegraded);
    }

    /**
     * `add_attempt()` flips the previous row's latest flag with no event, so a
     * superseded attempt keeps claiming to be outstanding.
     *
     * @return void
     */
    public function test_latest_flag_drift_is_repaired(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $now = time();
        $this->insert_submission((int) $assign->id, (int) $student->id, $now - 4 * 86400, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertSame(
            1,
            (int) $DB->get_field('block_feedback_tracker_sub', 'islatest', ['cmid' => $cm->id])
        );

        // Core supersedes the attempt; nothing is emitted.
        $DB->set_field('assign_submission', 'latest', 0, [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'attemptnumber' => 0,
        ]);

        $this->run_reconciler();

        $this->assertSame(
            0,
            (int) $DB->get_field('block_feedback_tracker_sub', 'islatest', ['cmid' => $cm->id])
        );
    }

    /**
     * Course reset deletes {assign_submission} rows with a bare
     * `delete_records_select()`, leaving orphan ledger rows that would count
     * as pending for ever.
     *
     * @return void
     */
    public function test_orphaned_rows_are_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $now = time();
        $this->insert_submission((int) $assign->id, (int) $student->id, $now - 4 * 86400, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));

        // Exactly what reset_userdata() does: a raw delete, no events.
        $DB->delete_records_select('assign_submission', 'assignment = :a', ['a' => $assign->id]);

        $this->run_reconciler();

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));
    }

    /**
     * An unenrolled student's work is nobody's outstanding task; core's own
     * needs-grading count joins active enrolments, so the ledger must agree.
     *
     * @return void
     */
    public function test_rows_for_departed_participants_are_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment();

        $now = time();
        $this->insert_submission((int) $assign->id, (int) $student->id, $now - 4 * 86400, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));

        // Unenrol, then remove the source row too so the orphan sweep is not
        // what does the work — this must be the participant sweep.
        $instances = enrol_get_instances($course->id, true);
        $plugin = enrol_get_plugin('manual');
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $plugin->unenrol_user($instance, $student->id);
            }
        }

        $this->run_reconciler();

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));
    }

    /**
     * The departed-participant deletion must survive the next tick.
     *
     * The missing-row sweep runs first on every tick and reads
     * {assign_submission} directly, so without an enrolment predicate it
     * rebuilds precisely the rows the participant sweep removed on the tick
     * before — an unenrolled student's work reappearing for ever, one backfill
     * dispatch and one rollup recompute per round trip. One tick cannot show
     * this: the deletion happens after the rebuild within a single run, so the
     * assertion has to be made on the second.
     *
     * @return void
     */
    public function test_departed_participant_rows_stay_deleted_on_the_next_tick(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment();

        $now = time();
        $this->insert_submission((int) $assign->id, (int) $student->id, $now - 4 * 86400, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));

        /* Unenrol only. The {assign_submission} row deliberately stays: it is
         * what the missing-row sweep reads, and leaving it is the whole point
         * of the test. */
        foreach (enrol_get_instances($course->id, true) as $instance) {
            if ($instance->enrol === 'manual') {
                enrol_get_plugin('manual')->unenrol_user($instance, $student->id);
            }
        }

        $this->run_reconciler();
        $this->assertSame(
            0,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'The participant sweep must delete the unenrolled student\'s row.'
        );

        $this->run_reconciler();
        $this->assertSame(
            0,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'The missing-row sweep must not resurrect an unenrolled student\'s row.'
        );
    }

    /**
     * A deleted account with its enrolment still standing is never rebuilt.
     *
     * `delete_user()` unenrols on its way through, so it exercises the
     * enrolment predicate rather than this one and would pass with or without
     * the `deleted` test. The state pinned here — `{user}.deleted = 1` with the
     * enrolment intact — is what a delete interrupted partway leaves behind,
     * and it is the state the departed-participant sweep already deletes,
     * because `get_enrolled_sql()` joins `{user}` with `u.deleted = 0`. The two
     * sweeps have to agree on it or they resume fighting.
     *
     * @return void
     */
    public function test_deleted_account_with_live_enrolment_is_not_rebuilt(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $now = time();
        $this->insert_submission((int) $assign->id, (int) $student->id, $now - 4 * 86400, 0);
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));

        $DB->set_field('user', 'deleted', 1, ['id' => $student->id]);
        $this->assertTrue(
            $DB->record_exists_sql(
                'SELECT 1
                   FROM {user_enrolments} ue
                   JOIN {enrol} en ON en.id = ue.enrolid
                  WHERE ue.userid = :userid AND en.courseid = :courseid',
                ['userid' => $student->id, 'courseid' => $cm->course]
            ),
            'The fixture is only meaningful while the enrolment survives the deletion.'
        );

        $this->run_reconciler();

        $this->assertSame(
            0,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'A deleted account must never be given a fresh ledger row.'
        );
    }

    /**
     * Convergence. A second pass over a ledger the first pass already made
     * correct must dispatch nothing — otherwise the task would re-repair the
     * same rows on every cron tick for ever.
     *
     * @return void
     */
    public function test_second_pass_over_a_correct_ledger_is_a_no_op(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $now = time();
        $tsubmit = $now - 4 * 86400;
        $tgrade = $now - 2 * 86400;
        $this->insert_submission((int) $assign->id, (int) $student->id, $tsubmit, 0);
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => 88.0, 'timecreated' => $tgrade, 'timemodified' => $tgrade,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        // One pass to settle any residual drift, draining what it queues.
        $this->run_reconciler();

        $before = $DB->count_records('task_adhoc');
        (new reconcile_ledger())->execute();
        $this->assertSame(
            $before,
            $DB->count_records('task_adhoc'),
            'A converged ledger must not keep queueing repairs.'
        );
    }

    /**
     * An auto-created placeholder grade must not read as a divergence, or the
     * sweep would dispatch a repair for every such row on every tick — a
     * whole-cohort storm that never settles.
     *
     * @return void
     */
    public function test_placeholder_grade_is_not_a_divergence(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $now = time();
        $tsubmit = $now - 4 * 86400;
        $this->insert_submission((int) $assign->id, (int) $student->id, $tsubmit, 0);
        // Core's placeholder: grade -1, timestamp copied from the submission.
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'grader' => -1, 'grade' => -1.0, 'timecreated' => $tsubmit, 'timemodified' => $tsubmit,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->run_reconciler();

        $before = $DB->count_records('task_adhoc');
        (new reconcile_ledger())->execute();
        $this->assertSame($before, $DB->count_records('task_adhoc'));
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_sub', 'timegraded', ['cmid' => $cm->id])
        );
    }

    /**
     * On 4.5 and 5.1 only the batch "Set allocated marker" operation fires
     * `marker_updated`; quick grading and the grading form write the flag in
     * silence. Without this sweep the marker turnaround is measurable only for
     * batch-allocated work, which on most sites is a small and non-random
     * slice — so the discovery is recorded, and recorded as a discovery.
     *
     * @return void
     */
    public function test_silent_allocation_is_discovered_and_marked_as_reconciled(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment([
            'markingworkflow' => 1,
            'markingallocation' => 1,
        ]);

        $now = time();
        $this->insert_submission((int) $assign->id, (int) $student->id, $now - 4 * 86400, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_sub', 'timeallocated', ['cmid' => $cm->id])
        );

        // Allocated through a path that emits nothing at all.
        $marker = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')
            ->allocate_marker((int) $assign->id, (int) $student->id, (int) $marker->id);

        $this->run_reconciler();

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotNull($row->timeallocated);
        $this->assertSame((int) $marker->id, (int) $row->allocmarkerid);
        $this->assertSame(
            submission_ledger::ALLOC_SOURCE_RECONCILED,
            $row->allocsource,
            'A discovery is not an observation, and the two must stay separable.'
        );

        // Converged: a second pass finds nothing more to stamp.
        $before = $DB->count_records('task_adhoc');
        (new reconcile_ledger())->execute();
        $this->assertSame($before, $DB->count_records('task_adhoc'));
    }

    /**
     * Run the task and drain the adhoc repairs it queued.
     *
     * @return void
     */
    private function run_reconciler(): void {
        (new reconcile_ledger())->execute();
        $this->runAdhocTasks('\block_feedback_tracker\task\backfill_one_submission');
    }

    /**
     * Insert one {assign_submission} row in the submitted state.
     *
     * @param int $assignid
     * @param int $userid
     * @param int $tsubmit
     * @param int $attempt
     * @return void
     */
    private function insert_submission(int $assignid, int $userid, int $tsubmit, int $attempt): void {
        global $DB;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assignid, 'userid' => $userid, 'attemptnumber' => $attempt,
            'timecreated' => $tsubmit, 'timemodified' => $tsubmit,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
    }

    /**
     * Build a processable course with an enrolled student and an assign.
     *
     * @param array $assignopts Extra {assign} settings.
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
}
