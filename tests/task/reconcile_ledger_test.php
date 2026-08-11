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
     * An activity on the front page is still repaired.
     *
     * Nobody holds a {user_enrolments} row on the site course, and core knows
     * it: `get_enrolled_join()` skips the enrolment join outright when the
     * course context is SITEID. A missing-row sweep that demanded an enrolment
     * unconditionally would therefore stop repairing front-page activities
     * altogether — silently, which is worse than the oscillation the predicate
     * exists to end.
     *
     * @return void
     */
    public function test_front_page_activity_is_still_repaired(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();

        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance(SITEID)->id,
        ]);
        course_access::reset_memo();

        $student = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => SITEID]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $this->assertFalse(
            $DB->record_exists_sql(
                'SELECT 1
                   FROM {user_enrolments} ue
                   JOIN {enrol} en ON en.id = ue.enrolid
                  WHERE ue.userid = :userid AND en.courseid = :courseid',
                ['userid' => $student->id, 'courseid' => SITEID]
            ),
            'The fixture is only meaningful while the front page has no enrolment rows.'
        );

        $this->insert_submission((int) $assign->id, (int) $student->id, time() - 4 * 86400, 0);

        $this->run_reconciler();

        $this->assertSame(
            1,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'A front-page submission must still get a ledger row.'
        );
    }

    /**
     * A grade that exists only in the gradebook is found, and then left alone.
     *
     * No event announces it: flipping a grade to overridden fires nothing, and
     * a re-grade to the same value fires nothing either, because core gates the
     * event on the final grade value having changed. So the sweep is the only
     * thing that can close such a cycle.
     *
     * The second half is the part worth testing. The whole model is monotone —
     * a response is only ever recorded earlier, never withdrawn — precisely so
     * that a sweep and the writer can never disagree about the same facts. A
     * second pass that kept queueing repairs would mean they do, which is the
     * failure this file already carries one regression test for.
     *
     * @return void
     */
    public function test_a_gradebook_only_grade_is_found_and_then_converges(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $now = time();
        $responded = $now - 2 * 86400;
        $this->insert_submission((int) $assign->id, (int) $student->id, $now - 4 * 86400, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_sub', 'timeclosed', ['cmid' => $cm->id]),
            'Nothing inside the activity has answered.'
        );

        $itemid = $DB->get_field_sql(
            "SELECT id FROM {grade_items}
              WHERE itemtype = :t AND itemmodule = :m AND iteminstance = :a AND itemnumber = 0",
            ['t' => 'mod', 'm' => 'assign', 'a' => $assign->id]
        );
        $this->assertNotEmpty($itemid);
        $DB->insert_record('grade_grades', (object) [
            'itemid' => $itemid,
            'userid' => $student->id,
            'rawgrade' => 64.0,
            'finalgrade' => 64.0,
            'overridden' => $responded,
            'hidden' => 0,
            'timecreated' => $responded,
            'timemodified' => $responded,
        ]);

        $this->run_reconciler();

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame($responded, (int) $row->timeclosed, 'The sweep must find it.');
        $this->assertSame('gradebook', $row->closedsource);

        $before = $DB->count_records('task_adhoc');
        (new reconcile_ledger())->execute();
        $this->assertSame(
            $before,
            $DB->count_records('task_adhoc'),
            'And then stop: the writer and the sweep must agree about the same facts.'
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
     * Switching reconciliation off actually stops it.
     *
     * `reconcile_active` is a default-ON checkbox, so core stores the off state
     * as the string '0'. The guard used to read it with `?: 1`, under which '0'
     * is falsy and yields 1 — the documented escape hatch never fired.
     *
     * The control matters more than the assertion here: six of the nine sweeps
     * write no ledger row at all, they queue adhoc repairs, so "assert no
     * ledger rows appeared" would pass whether the guard fires or not. This
     * proves the sweeps were queueing before the setting was touched.
     *
     * @return void
     */
    public function test_the_kill_switch_actually_stops_the_sweeps(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [, $student, $assign] = $this->build_environment();

        $this->insert_submission((int) $assign->id, (int) $student->id, time() - 4 * 86400, 0);

        // Control: with the setting untouched the missing-row sweep queues a repair.
        $DB->delete_records('task_adhoc');
        (new reconcile_ledger())->execute();
        $this->assertGreaterThan(
            0,
            $DB->count_records('task_adhoc'),
            'Control: reconciliation must be doing something before the switch is tested.'
        );

        // The repair was never drained, so the same row is still there to be found.
        $DB->delete_records('task_adhoc');
        set_config('reconcile_active', '0', 'block_feedback_tracker');
        (new reconcile_ledger())->execute();

        $this->assertSame(
            0,
            $DB->count_records('task_adhoc'),
            'A stored "0" is how the admin form records off, and it must be honoured.'
        );
    }

    /**
     * A team row for mod_assign's DEFAULT group is not an orphan.
     *
     * The default team group IS group 0, so a member row for it is stored with
     * `teamgroupid = 0` — byte-identical to an individual row. Discriminating
     * on that stored value made the orphan sweep probe for `s.userid = l.userid`
     * while the source row carries `userid = 0`; it found nothing and deleted a
     * perfectly good member row, which the missing-team sweep then recreated on
     * the next tick. The live `assign.teamsubmission` flag is the only thing
     * that can tell the two shapes apart.
     *
     * @return void
     */
    public function test_a_default_group_team_row_is_not_mistaken_for_an_orphan(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment(['teamsubmission' => 1]);

        /* No group membership at all, which is exactly what puts the student in
         * mod_assign's default group — the one numbered 0. */
        $submitted = time() - 4 * 86400;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id,
            'userid' => 0,
            'attemptnumber' => 0,
            'timecreated' => $submitted,
            'timemodified' => $submitted,
            'status' => submission_status::SUBMITTED,
            'groupid' => 0,
            'latest' => 1,
        ]);
        submission_ledger::upsert_for_team_attempt((int) $cm->id, 0, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $student->id]);
        $this->assertNotEmpty($row, 'Sanity: the member row exists before the sweeps run.');
        $this->assertSame(0, (int) $row->teamgroupid, 'Sanity: the default group is stored as 0.');
        $rowid = (int) $row->id;

        // Control: a row whose activity is genuinely gone must still be swept away.
        $orphanid = $this->generator()->create_ledger_row(['courseid' => (int) $cm->course]);

        $this->run_reconciler();
        $this->run_reconciler();

        $this->assertFalse(
            $DB->record_exists('block_feedback_tracker_sub', ['id' => $orphanid]),
            'Control: the orphan sweep must have run and removed the row with no course module.'
        );
        $after = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $student->id]);
        $this->assertNotEmpty($after, 'A default-group team member row is not an orphan.');
        /* Identity, not existence. The missing-team sweep runs before the orphan
         * sweep, so a deleted row is rebuilt on the following tick and a test
         * that only asks "is there a row" passes while the pair thrash — one
         * backfill dispatch and one rollup recompute per round trip. A changed
         * id is the fingerprint of that round trip. */
        $this->assertSame(
            $rowid,
            (int) $after->id,
            'The row must survive untouched; a new id means it was deleted and rebuilt.'
        );
    }

    /**
     * Every member of a team gets a row, and the repair is dispatched once for
     * the GROUP rather than once per member.
     *
     * Ledger rows are per member while the repair is per group:
     * `upsert_for_cm_user_attempt()` re-routes any member of a team activity
     * back through the whole-group fan-out, so one descriptor per member ran
     * the entire fan-out once per member — quadratic in group size, and split
     * across parallel adhoc tasks that then raced to write the same rows.
     *
     * @return void
     */
    public function test_a_team_repair_is_dispatched_once_for_the_group(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, , $assign, $course] = $this->build_environment(['teamsubmission' => 1]);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $members = [];
        for ($i = 0; $i < 3; $i++) {
            $member = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $this->getDataGenerator()->create_group_member([
                'groupid' => $group->id,
                'userid' => $member->id,
            ]);
            $members[] = $member;
        }
        group_resolver::reset_memo();

        $submitted = time() - 4 * 86400;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id,
            'userid' => 0,
            'attemptnumber' => 0,
            'timecreated' => $submitted,
            'timemodified' => $submitted,
            'status' => submission_status::SUBMITTED,
            'groupid' => $group->id,
            'latest' => 1,
        ]);

        // The missing-team sweep has to build all three member rows from the one source row.
        $this->run_reconciler();
        foreach ($members as $member) {
            $this->assertTrue(
                $DB->record_exists('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $member->id]),
                'Every member of the team carries the group\'s work.'
            );
        }

        /* Drive a ledger-rooted dispatching sweep: the latest flag drifting is
         * the direct fingerprint of add_attempt(), and it selects one row PER
         * MEMBER — which is the shape that used to fan out three times. */
        $DB->set_field('block_feedback_tracker_sub', 'islatest', 0, ['cmid' => $cm->id]);
        $DB->delete_records('task_adhoc');
        (new reconcile_ledger())->execute();

        $descriptors = [];
        $tasks = \core\task\manager::get_adhoc_tasks('\block_feedback_tracker\task\backfill_one_submission');
        foreach ($tasks as $task) {
            $data = (array) $task->get_custom_data();
            foreach ((array) ($data['rows'] ?? []) as $descriptor) {
                $descriptor = (array) $descriptor;
                if ((int) $descriptor['cmid'] === (int) $cm->id) {
                    $descriptors[] = $descriptor;
                }
            }
        }

        $this->assertCount(
            1,
            $descriptors,
            'A three-member team must produce ONE container descriptor, not one per member.'
        );
        $this->assertSame(0, (int) $descriptors[0]['userid'], 'The container descriptor carries userid 0.');
        $this->assertSame((int) $group->id, (int) $descriptors[0]['groupid'], 'And the team group id.');
    }

    /**
     * A due date changed with no event is repaired.
     *
     * Due dates, cut-offs, overrides and extensions all move with no per-row
     * signal, so the stored rule silently stops describing the activity and
     * every downstream "within SLA" answer is computed against a deadline that
     * no longer exists. The rule sweep is the only thing that notices.
     *
     * @return void
     */
    public function test_a_due_date_changed_behind_the_plugin_is_repaired(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();

        $duedate = time() + 7 * 86400;
        [$cm, $student, $assign] = $this->build_environment(['duedate' => $duedate]);
        $this->insert_submission((int) $assign->id, (int) $student->id, time() - 4 * 86400, 0);

        $this->run_reconciler();

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotEmpty($row, 'Sanity: the row exists before the due date moves.');
        $this->assertSame(1, (int) $row->hasrule, 'Sanity: an activity with a due date carries a rule.');
        $this->assertSame($duedate, (int) $row->timecloses, 'Sanity: the stored rule matches the activity.');

        /* Core moves the due date with no per-row event, which is exactly the
         * hole this sweep exists to cover — so the fixture writes it the same
         * silent way rather than going through the module's own update path. */
        $moved = $duedate + 3 * 86400;
        $DB->set_field('assign', 'duedate', $moved, ['id' => $assign->id]);

        $this->run_reconciler();

        $after = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame(
            $moved,
            (int) $after->timecloses,
            'The rule sweep must re-resolve a due date that moved without an event.'
        );
    }

    /**
     * A repair that could not be queued is reported, not assumed.
     *
     * `queue_adhoc_task()` returns false when an identical payload is already
     * pending — and since Moodle 5.2 also for a refused component, before the
     * dedup check runs. On 4.5 a retry-exhausted row still matches the probe,
     * so an identical payload stays blocked for as long as
     * `task_adhoc_failed_retention` keeps the dead row. Discarding that return
     * made the sweep count a repair it never dispatched.
     *
     * @return void
     */
    public function test_a_refused_repair_batch_is_reported(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [, $student, $assign] = $this->build_environment();

        $this->insert_submission((int) $assign->id, (int) $student->id, time() - 4 * 86400, 0);

        // First tick queues the repair. It is deliberately NOT drained.
        (new reconcile_ledger())->execute();
        $this->assertSame(
            1,
            $DB->count_records('task_adhoc'),
            'Control: the first tick must actually queue something to be refused later.'
        );

        /* The cursor resets whenever a sweep returns fewer rows than the batch,
         * so the next tick rebuilds a byte-identical payload — which is what
         * core's dedup compares. */
        ob_clean();
        (new reconcile_ledger())->execute();
        $output = (string) ob_get_contents();

        $this->assertSame(
            1,
            $DB->count_records('task_adhoc'),
            'Sanity: core dedup collapsed the second dispatch onto the pending one.'
        );
        $this->assertStringContainsString(
            'repair batch(es) refused',
            $output,
            'The sweep must say a batch was refused rather than counting it as dispatched.'
        );
    }

    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
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
