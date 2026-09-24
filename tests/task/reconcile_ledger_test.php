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
     * Flush per-request statics that survive resetAfterTest(), and buffer the
     * task's mtrace() output, which Moodle's PHPUnit configuration
     * (beStrictAboutOutputDuringTests) reports as a risky test.
     * test_a_refused_repair_batch_is_reported() reads this buffer.
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
     * A departed student's work is nobody's outstanding task; core's own
     * needs-grading count joins active enrolments, so the ledger must agree.
     *
     * The enrolment is suspended with a direct write, which fires no event:
     * unenrol_user() would let the enrolment observer delete the row before
     * the reconciler runs, and the test would pass without the sweep.
     *
     * @return void
     */
    public function test_rows_for_departed_participants_are_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $now = time();
        $this->insert_submission((int) $assign->id, (int) $student->id, $now - 4 * 86400, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        // The source row stays, so the orphan sweep has nothing to delete and
        // the participant sweep has to do the work.
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $student->id]);
        $this->assertSame(
            1,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'Precondition: the row is still there when the reconciler starts.'
        );

        $this->run_reconciler();

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));
    }

    /**
     * The departed-participant deletion must survive the next tick.
     *
     * The missing-row sweep reads {assign_submission} directly, so without its
     * enrolment predicate it would dispatch a rebuild of the rows the
     * participant sweep removed, on every tick. In registry order it runs
     * before the participant sweep, so only the second tick can show that.
     *
     * @return void
     */
    public function test_departed_participant_rows_stay_deleted_on_the_next_tick(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();

        $now = time();
        $this->insert_submission((int) $assign->id, (int) $student->id, $now - 4 * 86400, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        /* Suspended with a direct write, which fires no event, so the row is
         * still there for the participant sweep. The {assign_submission} row
         * deliberately stays: it is what the missing-row sweep reads, and
         * leaving it is the whole point of the test. */
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $student->id]);
        $this->assertSame(
            1,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'Precondition: the row is still there when the first tick starts.'
        );

        $this->run_reconciler();
        $this->assertSame(
            0,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'The participant sweep must delete the departed student\'s row.'
        );

        /* Asserted on the queue, before the repairs are drained: the worker
         * re-checks participation and would drop a wrongly dispatched repair,
         * so the ledger alone cannot show whether the sweep selected the row. */
        (new reconcile_ledger())->execute();
        $dispatched = [];
        foreach (\core\task\manager::get_adhoc_tasks('\block_feedback_tracker\task\backfill_one_submission') as $queued) {
            foreach ((array) ($queued->get_custom_data()->rows ?? []) as $descriptor) {
                $descriptor = (array) $descriptor;
                if ((int) $descriptor['cmid'] === (int) $cm->id) {
                    $dispatched[] = (int) $descriptor['userid'];
                }
            }
        }
        $this->assertNotContains(
            (int) $student->id,
            $dispatched,
            'The missing-row sweep must not dispatch a rebuild of a departed student\'s row.'
        );

        $this->runAdhocTasks('\block_feedback_tracker\task\backfill_one_submission');
        $this->assertSame(
            0,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'The missing-row sweep must not resurrect a departed student\'s row.'
        );
    }

    /**
     * A deleted account with its enrolment still standing is never rebuilt.
     *
     * `delete_user()` unenrols on its way through, so it exercises the
     * enrolment predicate rather than this one and would pass with or without
     * the `deleted` test. The state pinned here, `{user}.deleted = 1` with the
     * enrolment intact, is one the departed-participant sweep already deletes,
     * because `get_enrolled_sql()` filters on `u.deleted = 0`; the missing-row
     * sweep has to agree, or the two undo each other on every tick.
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
     * Nobody holds a {user_enrolments} row on the site course, and
     * `get_enrolled_join()` skips the enrolment join when the course is
     * SITEID. A missing-row sweep that demanded an enrolment unconditionally
     * would silently stop repairing front-page activities.
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
     * neither does a re-grade to the same value, because core fires
     * `user_graded` only when the final grade changes. Only the sweep can close
     * such a cycle.
     *
     * The second pass must then queue nothing. A response is only ever recorded
     * earlier, never withdrawn, so once the writer has closed the row the sweep
     * has nothing left to disagree with; a pass that kept queueing repairs
     * would mean the two read the same facts differently.
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
     * A marker allocation written with no `marker_updated` event is discovered
     * and stamped as `reconciled`, so a discovery stays separable from an
     * observed allocation. See reconcile_ledger::sweep_unstamped_allocations()
     * for which core paths allocate silently.
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
     * as the string '0'; a `?: 1` read would turn that back on.
     *
     * The control matters more than the assertion here: seven of the nine
     * sweeps queue adhoc tasks instead of writing ledger rows, so "no ledger
     * rows appeared" would pass whether the guard fires or not. The control
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
     * A team row for mod_assign's default group is not an orphan.
     *
     * The default team group is group 0, so a member row for it is stored with
     * `teamgroupid = 0`, identical to an individual row. Treated as individual,
     * the orphan probe looks for `s.userid = l.userid` while the source row
     * carries `userid = 0`, finds nothing and deletes a valid member row, which
     * the missing-team sweep then rebuilds. Only the live
     * `assign.teamsubmission` flag tells the two shapes apart.
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
        /* Identity, not existence: the missing-team sweep runs before the
         * orphan sweep, so a deleted row is rebuilt on the following tick and
         * only a changed id shows the delete-and-rebuild cycle. */
        $this->assertSame(
            $rowid,
            (int) $after->id,
            'The row must survive untouched; a new id means it was deleted and rebuilt.'
        );
    }

    /**
     * Every member of a team gets a row, and the repair is dispatched once for
     * the group rather than once per member; reconcile_ledger::buffer_repairs()
     * says why.
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

        /* Drive a ledger-rooted dispatching sweep: latest-flag drift is what
         * add_attempt() leaves behind, and the sweep selects one row per
         * member, three here, which must collapse into one descriptor. */
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
     * Due dates, cut-offs, overrides and extensions change with no per-row
     * signal, so a stale stored rule would put every "within SLA" answer
     * against a deadline that no longer exists. Only the rule sweep notices.
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

        /* Written directly: the module's own update path fires
         * course_module_updated, whose observer re-derives every row itself
         * and would leave the rule sweep nothing to repair. */
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
     * The rule sweep dispatches exactly the rows the writer would change.
     *
     * Every row below stores what the writer resolves for it: a user override,
     * a group override, a student in two overridden groups (the lowest
     * sortorder governs), an extension, and a user override that removed the
     * due date. None of them differs from the activity's date by accident, so
     * a probe that compared against the activity alone would dispatch all but
     * one on every pass, each repair writing back the same value. A due date
     * moved behind the plugin is the control: it reaches exactly the one row
     * that inherits it, and after the repair the sweep has nothing left.
     *
     * @return void
     */
    public function test_the_rule_sweep_dispatches_only_rows_the_writer_would_change(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $plain, $assign, $course] = $this->build_environment(['duedate' => $due, 'cutoffdate' => $due + 86400]);
        $assignid = (int) $assign->id;
        $first = (int) $this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
        $second = (int) $this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
        $students = [
            'plain' => (int) $plain->id,
            'user override' => $this->rule_student($course),
            'group override' => $this->rule_student($course, $first),
            'two groups' => $this->rule_student($course, $first, $second),
            'extension' => $this->rule_student($course),
            'removed due date' => $this->rule_student($course),
        ];
        $this->rule_override($assignid, ['userid' => $students['user override'], 'duedate' => $due + 86400]);
        $this->rule_override($assignid, ['groupid' => $first, 'sortorder' => 1, 'duedate' => $due + 2 * 86400]);
        $this->rule_override($assignid, ['groupid' => $second, 'sortorder' => 2, 'duedate' => $due + 3 * 86400]);
        $this->rule_override($assignid, ['userid' => $students['removed due date'], 'duedate' => 0]);
        $DB->insert_record('assign_user_flags', (object) [
            'assignment' => $assignid,
            'userid' => $students['extension'],
            'extensionduedate' => $due + 5 * 86400,
        ]);
        foreach ($students as $userid) {
            $this->insert_submission($assignid, $userid, time() - 4 * 86400, 0);
            submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        }
        $expected = [
            'plain' => $due,
            'user override' => $due + 86400,
            'group override' => $due + 2 * 86400,
            'two groups' => $due + 2 * 86400,
            'extension' => $due + 5 * 86400,
            'removed due date' => null,
        ];
        foreach ($expected as $case => $close) {
            $this->assertSame($close, $this->stored_close($cm, $students[$case]), "Sanity: $case as the writer stores it.");
        }

        $this->assertSame([], $this->sweep_dispatches($course, 'sweep_rule_drift', 'rules'));

        $moved = $due + 10 * 86400;
        $DB->set_field('assign', 'duedate', $moved, ['id' => $assignid]);
        $this->assertSame(
            [$students['plain']],
            $this->sweep_dispatches($course, 'sweep_rule_drift', 'rules'),
            'Control: the moved due date reaches the row that inherits it, and no other.'
        );
        $this->runAdhocTasks('\block_feedback_tracker\task\backfill_one_submission');
        $this->assertSame($moved, $this->stored_close($cm, $students['plain']), 'The repair wrote the moved date.');
        $this->assertSame(
            [],
            $this->sweep_dispatches($course, 'sweep_rule_drift', 'rules'),
            'After the repair the sweep has converged.'
        );

        /* The cut-off and the open date are compared too. A later cut-off
         * reaches every row that inherits it; the extension already runs past
         * it, so that row keeps the extension as its cut-off. */
        $DB->set_field('assign', 'cutoffdate', $due + 2 * 86400, ['id' => $assignid]);
        $inherits = array_values(array_diff($students, [$students['extension']]));
        sort($inherits);
        $this->assertSame($inherits, $this->sweep_dispatches($course, 'sweep_rule_drift', 'rules'));
        $this->runAdhocTasks('\block_feedback_tracker\task\backfill_one_submission');
        $DB->set_field('assign', 'allowsubmissionsfromdate', time() - 86400, ['id' => $assignid]);
        $everyone = array_values($students);
        sort($everyone);
        $this->assertSame($everyone, $this->sweep_dispatches($course, 'sweep_rule_drift', 'rules'));
    }

    /**
     * A closed cycle is not the rule sweep's to repair.
     *
     * The repair rewrites only the current cycle of a submission, so a probe
     * that selected closed cycles would dispatch the same row on every pass.
     * The current cycle is the control.
     *
     * @return void
     */
    public function test_the_rule_sweep_leaves_closed_cycles_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment(['duedate' => time() + 7 * 86400]);
        $now = time();
        $this->insert_submission((int) $assign->id, (int) $student->id, $now - 4 * 86400, 0);
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => 70.0, 'timecreated' => $now - 3 * 86400, 'timemodified' => $now - 3 * 86400,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        // A resubmission after the mark opens a second cycle.
        $DB->set_field('assign_submission', 'timemodified', $now - 86400, ['assignment' => $assign->id]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $closed = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'cycle' => 0], '*', MUST_EXIST);
        $current = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'cycle' => 1], '*', MUST_EXIST);
        $this->assertSame(0, (int) $closed->iscurrent, 'Precondition: two cycles, the first one closed.');

        $DB->set_field('block_feedback_tracker_sub', 'timecloses', 1, ['id' => $closed->id]);
        $this->assertSame([], $this->sweep_dispatches($course, 'sweep_rule_drift', 'rules'));

        $DB->set_field('block_feedback_tracker_sub', 'timecloses', 1, ['id' => $current->id]);
        $this->assertSame(
            [(int) $student->id],
            $this->sweep_dispatches($course, 'sweep_rule_drift', 'rules'),
            'Control: the same drift on the current cycle is dispatched.'
        );
    }

    /**
     * An override edited or reordered without an event is repaired.
     *
     * Core fires no event when group overrides are reordered, and a direct
     * write fires none at all. The sweep finds the students whose governing
     * override changed, and only them.
     *
     * @return void
     */
    public function test_an_override_changed_behind_the_plugin_is_repaired(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $plain, $assign, $course] = $this->build_environment(['duedate' => $due]);
        $assignid = (int) $assign->id;
        $first = (int) $this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
        $second = (int) $this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
        $own = $this->rule_student($course);
        $member = $this->rule_student($course, $first);
        $both = $this->rule_student($course, $first, $second);
        $ownoverride = $this->rule_override($assignid, ['userid' => $own, 'duedate' => $due + 86400]);
        $firstoverride = $this->rule_override($assignid, ['groupid' => $first, 'sortorder' => 1, 'duedate' => $due + 2 * 86400]);
        $secondoverride = $this->rule_override($assignid, ['groupid' => $second, 'sortorder' => 2, 'duedate' => $due + 3 * 86400]);
        foreach ([(int) $plain->id, $own, $member, $both] as $userid) {
            $this->insert_submission($assignid, $userid, time() - 4 * 86400, 0);
            submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        }
        $this->assertSame([], $this->sweep_dispatches($course, 'sweep_rule_drift', 'rules'), 'Precondition: converged.');

        $DB->set_field('assign_overrides', 'duedate', $due + 4 * 86400, ['id' => $ownoverride]);
        $this->assertSame([$own], $this->sweep_dispatches($course, 'sweep_rule_drift', 'rules'));
        $this->runAdhocTasks('\block_feedback_tracker\task\backfill_one_submission');
        $this->assertSame($due + 4 * 86400, $this->stored_close($cm, $own), 'The user override edit was repaired.');

        $DB->set_field('assign_overrides', 'duedate', $due + 5 * 86400, ['id' => $firstoverride]);
        $affected = [$member, $both];
        sort($affected);
        $this->assertSame($affected, $this->sweep_dispatches($course, 'sweep_rule_drift', 'rules'));
        $this->runAdhocTasks('\block_feedback_tracker\task\backfill_one_submission');
        $this->assertSame($due + 5 * 86400, $this->stored_close($cm, $member));
        $this->assertSame($due + 5 * 86400, $this->stored_close($cm, $both));

        $DB->set_field('assign_overrides', 'sortorder', 2, ['id' => $firstoverride]);
        $DB->set_field('assign_overrides', 'sortorder', 1, ['id' => $secondoverride]);
        $this->assertSame(
            [$both],
            $this->sweep_dispatches($course, 'sweep_rule_drift', 'rules'),
            'A reorder changes the governing override of the student in both groups only.'
        );
        $this->runAdhocTasks('\block_feedback_tracker\task\backfill_one_submission');
        $this->assertSame($due + 3 * 86400, $this->stored_close($cm, $both));
        $this->assertSame([], $this->sweep_dispatches($course, 'sweep_rule_drift', 'rules'), 'Converged again.');
    }

    /**
     * Grade type "None" is judged the way the writer judges it.
     *
     * Core stores grade -1 for a grading on an activity with no grade, and
     * grading_state::resolve() then reads only that a later grade row exists.
     * A probe testing the value would select every marked row of the activity
     * on every pass. The controls are the two real divergences on the same
     * activity: a grading the plugin never saw, and one withdrawn behind it.
     *
     * @return void
     */
    public function test_a_marked_grade_type_none_submission_is_not_re_dispatched(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $marked, $assign, $course] = $this->build_environment(['grade' => 0]);
        $unseen = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $tsubmit = time() - 4 * 86400;
        $tgrade = time() - 2 * 86400;
        $grading = static fn(int $userid): \stdClass => (object) [
            'assignment' => $assign->id, 'userid' => $userid, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => -1.0, 'timecreated' => $tgrade, 'timemodified' => $tgrade,
        ];
        foreach ([(int) $marked->id, (int) $unseen->id] as $userid) {
            $this->insert_submission((int) $assign->id, $userid, $tsubmit, 0);
        }
        $DB->insert_record('assign_grades', $grading((int) $marked->id));
        foreach ([(int) $marked->id, (int) $unseen->id] as $userid) {
            submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
        }
        $this->assertSame(
            $tgrade,
            (int) $DB->get_field('block_feedback_tracker_sub', 'timemarked', ['cmid' => $cm->id, 'userid' => $marked->id]),
            'Sanity: the writer takes the grading as the mark.'
        );

        $this->assertSame([], $this->sweep_dispatches($course, 'sweep_grade_divergence', 'gradestate'));

        $DB->insert_record('assign_grades', $grading((int) $unseen->id));
        $this->assertSame(
            [(int) $unseen->id],
            $this->sweep_dispatches($course, 'sweep_grade_divergence', 'gradestate'),
            'Control: a grading the plugin never saw is still found.'
        );

        $DB->delete_records('assign_grades', ['assignment' => $assign->id, 'userid' => $marked->id]);
        $both = [(int) $marked->id, (int) $unseen->id];
        sort($both);
        $this->assertSame(
            $both,
            $this->sweep_dispatches($course, 'sweep_grade_divergence', 'gradestate'),
            'Control: a grading withdrawn behind the plugin is found too.'
        );
    }

    /**
     * A repair that could not be queued is reported, not assumed.
     *
     * `queue_adhoc_task()` returns false when nothing was queued, most often
     * because an identical payload is already pending; see
     * reconcile_ledger::queue_repair() for the other cases. The sweep must not
     * count such a batch as dispatched.
     *
     * @return void
     */
    public function test_a_refused_repair_batch_is_reported(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [, $student, $assign] = $this->build_environment();

        $this->insert_submission((int) $assign->id, (int) $student->id, time() - 4 * 86400, 0);

        // First tick queues the repair, which is deliberately not drained.
        (new reconcile_ledger())->execute();
        $this->assertSame(
            1,
            $DB->count_records('task_adhoc'),
            'Control: the first tick must actually queue something to be refused later.'
        );

        /* The first tick's window was shorter than the batch, so the cursor
         * wrapped to 0 and this tick rebuilds a byte-identical payload, which
         * is what core's dedup compares. */
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
     * Every tick records what it cost, including the ones that repair nothing.
     *
     * Without the audit row the only visible cost would be the cron duration
     * of a task that runs nine sweeps. The converged tick matters most: nine
     * diffs that find nothing are the task's steady-state cost, the baseline
     * any optimisation has to be measured against.
     *
     * @return void
     */
    public function test_every_tick_records_what_it_cost(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [, $student, $assign] = $this->build_environment();

        $this->insert_submission((int) $assign->id, (int) $student->id, time() - 4 * 86400, 0);

        $DB->delete_records('block_feedback_tracker_log');
        $this->run_reconciler();

        $row = $DB->get_record('block_feedback_tracker_log', ['reason' => 'reconcile']);
        $this->assertNotEmpty($row, 'A tick that repaired a row must say so.');
        $this->assertSame(1, (int) $row->affectedrows);
        $details = json_decode((string) $row->details, true);
        $this->assertSame(1, (int) $details['sweeps']['missing']['rows'], 'Attributed to the sweep that found it.');
        $this->assertArrayHasKey('ms', $details['sweeps']['missing']);
        $this->assertFalse($details['timecapped'], 'A one-row tick cannot have hit the cap.');
        $this->assertSame([], $details['skipped'], 'And nothing was skipped.');

        /* The second tick is both the control and the claim: the ledger is now
         * correct, so the sweeps repair nothing, and the row must still appear
         * with the cost of having proved it. */
        $DB->delete_records('block_feedback_tracker_log');
        $this->run_reconciler();

        $second = $DB->get_record('block_feedback_tracker_log', ['reason' => 'reconcile']);
        $this->assertNotEmpty($second, 'A tick that repairs nothing still records its cost.');
        $this->assertSame(0, (int) $second->affectedrows, 'Control: the ledger really had converged.');
        $seconddetails = json_decode((string) $second->details, true);
        $this->assertArrayHasKey('emptyms', $seconddetails);
        $this->assertGreaterThan(0, (int) $seconddetails['courses'], 'The scope of the pass is recorded.');

        /* A sweep whose window came back shorter than the batch spent its
         * driving set, so its pass completed. The departed-participant sweep's
         * driving set is the tracked-course list, and one course fits in one
         * tick. */
        $this->assertTrue($seconddetails['sweeps']['missing']['exhausted'], 'The pass completed.');
        $this->assertTrue(
            $seconddetails['sweeps']['participant']['exhausted'],
            'The course list is a driving set like any other, and it fits in one tick here.'
        );
    }

    /**
     * More than one course sheds its departed participants in a single tick.
     *
     * The sweep visits several courses per tick behind a course-id cursor.
     * Every other test in this file uses one course, so this is the one that
     * notices a sweep stopping after the first.
     *
     * The still-enrolled student in each course is the control: without them
     * this would pass if the sweep deleted everything.
     *
     * @return void
     */
    public function test_two_courses_shed_their_departed_participants_in_one_tick(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();

        $left = [];
        $stayed = [];
        foreach ([0, 1] as $i) {
            [$cm, $student, $assign, $course] = $this->build_environment();
            $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $this->insert_submission((int) $assign->id, (int) $student->id, time() - 4 * 86400, 0);
            $this->insert_submission((int) $assign->id, (int) $other->id, time() - 3 * 86400, 0);
            submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
            submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $other->id, 0);

            // Suspending the enrolment is what get_enrolled_sql(..., true) reads as gone.
            $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $student->id]);
            $left[] = (int) $student->id;
            $stayed[] = (int) $other->id;
        }
        course_access::reset_memo();

        foreach ($left as $userid) {
            $this->assertTrue(
                $DB->record_exists('block_feedback_tracker_sub', ['userid' => $userid]),
                'Sanity: both departed students have a row before the tick.'
            );
        }

        $this->run_reconciler();

        foreach ($stayed as $userid) {
            $this->assertTrue(
                $DB->record_exists('block_feedback_tracker_sub', ['userid' => $userid]),
                'Control: a still-enrolled student keeps their row.'
            );
        }
        foreach ($left as $userid) {
            $this->assertFalse(
                $DB->record_exists('block_feedback_tracker_sub', ['userid' => $userid]),
                'One tick must reach every tracked course, not just the first.'
            );
        }
    }

    /**
     * Core's cron runs many tasks in one process, so the set of tracked
     * courses must be read afresh on every tick: a course given the block by
     * another process between two ticks is swept by the second.
     *
     * @return void
     */
    public function test_a_course_given_the_block_between_two_ticks_is_swept_by_the_second(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        // A tracked course, so the first tick reads the tracked set instead of returning early.
        $this->build_environment();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $this->insert_submission((int) $assign->id, (int) $student->id, time() - 4 * 86400, 0);

        (new reconcile_ledger())->execute();
        $this->assertSame(
            0,
            $DB->count_records('task_adhoc', ['component' => 'block_feedback_tracker']),
            'Control: without the block the course is not swept, and no task of the plugin runs between the ticks.'
        );

        // Added as another process would: nothing in this process resets a memo.
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);

        $this->run_reconciler();
        $this->assertSame(
            1,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'The second tick must sweep the course that gained the block.'
        );
    }

    /**
     * A tick resumes after the last sweep that ran, not at the registry head.
     *
     * The time cap is tested between sweeps, so with a fixed order a site whose
     * ticks run out of budget would never reach the end of the registry, where
     * the rule and allocation sweeps sit.
     *
     * @return void
     */
    public function test_a_tick_resumes_after_the_last_sweep_that_ran(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->build_environment();

        set_config('reconcile_last_sweep', 'gradebook', 'block_feedback_tracker');
        $DB->delete_records('block_feedback_tracker_log');

        (new reconcile_ledger())->execute();

        $details = json_decode(
            (string) $DB->get_field('block_feedback_tracker_log', 'details', ['reason' => 'reconcile']),
            true
        );
        $this->assertSame(
            'latest',
            $details['order'][0],
            'The registry lists latest straight after gradebook, so that is where this tick starts.'
        );
        $this->assertCount(9, $details['order'], 'Rotation reorders the registry; it never shrinks it.');
        $this->assertSame(
            'gradebook',
            get_config('block_feedback_tracker', 'reconcile_last_sweep'),
            'A tick that reached every sweep ends on the one it started before.'
        );
    }

    /**
     * A tick that runs no sweep at all leaves the rotation marker alone.
     *
     * The marker is seeded from its stored value: left undefined it would raise
     * a PHP warning, which fails the run under fail-on-warning; blanked, every
     * such tick would reset rotation to registry order.
     *
     * A stored '-1' is deliberate: it survives the `?:` read that a stored '0'
     * would not, so the deadline is already past when the loop starts.
     *
     * @return void
     */
    public function test_a_tick_that_runs_nothing_keeps_the_rotation_marker(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->build_environment();

        set_config('reconcile_last_sweep', 'orphan', 'block_feedback_tracker');
        set_config('reconcile_time_cap_seconds', '-1', 'block_feedback_tracker');
        $DB->delete_records('block_feedback_tracker_log');

        (new reconcile_ledger())->execute();

        $this->assertSame(
            'orphan',
            get_config('block_feedback_tracker', 'reconcile_last_sweep'),
            'Nothing ran, so nothing about where to resume changed.'
        );
        $details = json_decode(
            (string) $DB->get_field('block_feedback_tracker_log', 'details', ['reason' => 'reconcile']),
            true
        );
        $this->assertTrue($details['timecapped'], 'Control: the cap is what stopped this tick.');
        $this->assertCount(9, $details['skipped'], 'Every sweep was skipped, and every one is named.');
        $this->assertSame('participant', $details['skipped'][0], 'Skipped is sliced from the rotated order.');
    }

    /**
     * The allocation stamp runs outside the reconciler's own time budget.
     *
     * submission_ledger::stamp_allocation_for_user() runs the academic-time
     * engine once per ledger row of the (cmid, userid) pair, so the sweep
     * queues stamp_allocations instead of calling it inside the tick's shared
     * time cap. After the tick and before the queue is drained, nothing has
     * been stamped and one task is waiting.
     *
     * @return void
     */
    public function test_the_allocation_stamp_is_dispatched_not_run_inline(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment(['markingallocation' => 1]);

        $this->insert_submission((int) $assign->id, (int) $student->id, time() - 4 * 86400, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $marker = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')
            ->allocate_marker((int) $assign->id, (int) $student->id, (int) $marker->id);

        $DB->delete_records('task_adhoc');
        (new reconcile_ledger())->execute();

        $queued = \core\task\manager::get_adhoc_tasks('\block_feedback_tracker\task\stamp_allocations');
        $this->assertCount(1, $queued, 'Control: the sweep found the allocation and queued the work.');
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_sub', 'timeallocated', ['cmid' => $cm->id]),
            'The tick itself must not have run the engine.'
        );

        $this->runAdhocTasks('\block_feedback_tracker\task\stamp_allocations');

        $this->assertNotNull(
            $DB->get_field('block_feedback_tracker_sub', 'timeallocated', ['cmid' => $cm->id]),
            'And the worker must actually do it.'
        );
    }

    /**
     * A window with nothing to repair still moves the cursor on.
     *
     * The walk derives both the cursor and the end-of-pass claim from the
     * window, never from what the probe returned
     * ({@see reconcile_ledger::walk()}). Driven one window per call (a sweep
     * deadline already in the past) so each call's cursor can be read.
     *
     * Changes that must make it fail: claiming exhaustion when the probe
     * returns nothing, or moving the cursor from the probe result instead of
     * from the window.
     *
     * @return void
     */
    public function test_a_window_with_nothing_to_repair_still_moves_the_cursor(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, , $assign, $course] = $this->build_environment();
        $ids = $this->seed_ledger_rows($cm, $assign, $course, 6);
        // Only the last row drifts: three windows of two must be walked to reach it.
        $lastuser = (int) $DB->get_field('block_feedback_tracker_sub', 'userid', ['id' => end($ids)]);
        $DB->set_field('assign_submission', 'latest', 0, ['assignment' => $assign->id, 'userid' => $lastuser]);
        $DB->delete_records('task_adhoc');

        $task = new reconcile_ledger();
        $sweep = new \ReflectionMethod($task, 'sweep_latest_drift');
        (new \ReflectionProperty($task, 'sweepdeadline'))->setValue($task, 0);
        $processable = [(int) $course->id];
        $cursor = static fn(): int => (int) get_config('block_feedback_tracker', 'reconcile_cursor_latest');
        $queued = static fn(): int => count(
            \core\task\manager::get_adhoc_tasks('\block_feedback_tracker\task\backfill_one_submission')
        );

        $sweep->invoke($task, $processable, 2, 'latest');
        $this->assertSame($ids[1], $cursor(), 'The cursor moved to the end of a window that held nothing to repair.');
        $this->assertSame(0, $queued(), 'Control: nothing in the first window needed repair.');
        $this->assertFalse(
            (new \ReflectionProperty($task, 'exhausted'))->getValue($task)['latest'],
            'A full window is not the end of the pass, whatever the probe returned.'
        );

        $sweep->invoke($task, $processable, 2, 'latest');
        $this->assertSame($ids[3], $cursor(), 'And on past the second window, without wrapping.');
        $this->assertSame(0, $queued());

        $sweep->invoke($task, $processable, 2, 'latest');
        $this->assertSame($ids[5], $cursor(), 'A full third window: the pass is not over yet.');
        $this->assertSame(1, $queued(), 'The drifted row sat in the third window, and was found there.');

        $sweep->invoke($task, $processable, 2, 'latest');
        $this->assertSame(0, $cursor(), 'An empty window is the end of the pass, so the next one starts over.');
    }

    /**
     * Drift past the first window is found within the tick.
     *
     * A sweep keeps walking windows until its driving set or its share of the
     * time cap is spent, so a pass over a large ledger does not take one tick
     * per window; the audit row records how far it got.
     *
     * @return void
     */
    public function test_drift_beyond_the_first_window_is_repaired_in_one_tick(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, , $assign, $course] = $this->build_environment();
        $ids = $this->seed_ledger_rows($cm, $assign, $course, 6);
        $lastuser = (int) $DB->get_field('block_feedback_tracker_sub', 'userid', ['id' => end($ids)]);
        $DB->set_field('assign_submission', 'latest', 0, ['assignment' => $assign->id, 'userid' => $lastuser]);
        set_config('reconcile_batch_size', '2', 'block_feedback_tracker');
        $DB->delete_records('block_feedback_tracker_log');

        $this->run_reconciler();

        $this->assertSame(
            0,
            (int) $DB->get_field('block_feedback_tracker_sub', 'islatest', ['id' => end($ids)]),
            'Three windows in, the drift was still reached within the tick.'
        );
        $this->assertSame(
            1,
            (int) $DB->get_field('block_feedback_tracker_sub', 'islatest', ['id' => $ids[0]]),
            'Control: a row that did not drift is left alone.'
        );
        $details = json_decode(
            (string) $DB->get_field('block_feedback_tracker_log', 'details', ['reason' => 'reconcile']),
            true
        );
        $this->assertSame(6, (int) $details['sweeps']['latest']['examined'], 'Every ledger row was examined.');
        $this->assertSame(3, (int) $details['sweeps']['latest']['windows'], 'In three windows of two.');
        $this->assertSame(1, (int) $details['sweeps']['latest']['rows'], 'And one of them needed repair.');
        $this->assertTrue($details['sweeps']['latest']['exhausted'], 'The pass completed within the tick.');
    }

    /**
     * A ledger row whose course module is gone is the orphan sweep's to remove,
     * not the drift sweep's to repair.
     *
     * The drift probe resolves the activity through the course module, exactly
     * as the repair writer does. Reaching {assign} directly through the stored
     * `iteminstance` is cheaper but selects rows the writer cannot repair (a
     * module row deleted with its activity row surviving), dispatching the
     * same no-op on every pass. The module-present case is the
     * control: the same drift, with the module in place, is dispatched.
     *
     * @return void
     */
    public function test_a_row_whose_module_is_gone_is_left_to_the_orphan_sweep(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, $student, $assign, $course] = $this->build_environment();
        $this->insert_submission((int) $assign->id, (int) $student->id, time() - 4 * 86400, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $DB->set_field('assign_submission', 'latest', 0, ['assignment' => $assign->id, 'userid' => $student->id]);
        $DB->delete_records('task_adhoc');

        $task = new reconcile_ledger();
        $sweep = new \ReflectionMethod($task, 'sweep_latest_drift');
        $processable = [(int) $course->id];
        $queued = static fn(): int => count(
            \core\task\manager::get_adhoc_tasks('\block_feedback_tracker\task\backfill_one_submission')
        );

        $sweep->invoke($task, $processable, 500, 'latest');
        $this->assertSame(1, $queued(), 'Control: with the module in place the drift is dispatched.');

        $DB->delete_records('task_adhoc');
        // The module row goes while the activity row survives: the state the orphan sweep owns.
        $DB->delete_records('course_modules', ['id' => $cm->id]);
        $sweep->invoke($task, $processable, 500, 'latest');
        $this->assertSame(0, $queued(), 'No module, no repair the writer could perform, so nothing is dispatched.');
    }

    /**
     * The missing-rows sweep pages over {assign_submission} the same way.
     *
     * Its window is built inline rather than through the shared ledger helper,
     * so the latest-drift tests prove nothing about it. Six submissions with no
     * ledger row, windows of two, one window per call: each call must repair
     * exactly its window and leave the cursor at the window's end.
     *
     * @return void
     */
    public function test_the_missing_rows_sweep_pages_over_the_source_table(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, , $assign, $course] = $this->build_environment();
        $this->seed_submissions($assign, $course, 6);
        $subids = array_map('intval', $DB->get_fieldset_sql(
            'SELECT id FROM {assign_submission} WHERE assignment = :assignment ORDER BY id ASC',
            ['assignment' => $assign->id]
        ));
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));
        $DB->delete_records('task_adhoc');

        $task = new reconcile_ledger();
        $sweep = new \ReflectionMethod($task, 'sweep_missing_rows');
        (new \ReflectionProperty($task, 'sweepdeadline'))->setValue($task, 0);
        $processable = [(int) $course->id];
        $cursor = static fn(): int => (int) get_config('block_feedback_tracker', 'reconcile_cursor_missing');
        $queued = static fn(): int => count(
            \core\task\manager::get_adhoc_tasks('\block_feedback_tracker\task\backfill_one_submission')
        );

        $sweep->invoke($task, $processable, 2, 'missing');
        $this->assertSame($subids[1], $cursor(), 'The cursor is the last SOURCE row of the window.');
        $this->assertSame(1, $queued(), 'One repair batch for the two rows of the window.');

        $sweep->invoke($task, $processable, 2, 'missing');
        $this->assertSame($subids[3], $cursor());
        $this->assertSame(2, $queued());

        $sweep->invoke($task, $processable, 2, 'missing');
        $this->assertSame($subids[5], $cursor(), 'A full third window: the pass is not over yet.');
        $this->assertSame(3, $queued());

        $sweep->invoke($task, $processable, 2, 'missing');
        $this->assertSame(0, $cursor(), 'An empty window ends the pass.');

        $this->runAdhocTasks('\block_feedback_tracker\task\backfill_one_submission');
        $this->assertSame(
            6,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'Every window\'s repairs were real: six rows, none repaired twice.'
        );
    }

    /**
     * The orphan sweep deletes across windows without wrapping its cursor.
     *
     * It acts inside each window rather than dispatching, and its driving set
     * is the whole ledger with no course filter, so it is the third shape a
     * paging regression could hide in. The surviving row is the control.
     *
     * @return void
     */
    public function test_the_orphan_sweep_deletes_across_windows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, , $assign, $course] = $this->build_environment();
        $ids = $this->seed_ledger_rows($cm, $assign, $course, 6);
        // Every source row but the third one's is gone, as after a course reset.
        $keeper = (int) $DB->get_field('block_feedback_tracker_sub', 'userid', ['id' => $ids[2]]);
        $DB->delete_records_select(
            'assign_submission',
            'assignment = :assignment AND userid <> :keeper',
            ['assignment' => $assign->id, 'keeper' => $keeper]
        );

        $task = new reconcile_ledger();
        $sweep = new \ReflectionMethod($task, 'sweep_orphans');
        (new \ReflectionProperty($task, 'sweepdeadline'))->setValue($task, 0);
        $processable = [(int) $course->id];
        $cursor = static fn(): int => (int) get_config('block_feedback_tracker', 'reconcile_cursor_orphan');
        $left = fn(): array => $this->ledger_ids((int) $cm->id);

        $sweep->invoke($task, $processable, 2, 'orphan');
        $this->assertSame([$ids[2], $ids[3], $ids[4], $ids[5]], $left(), 'The first window\'s two orphans are gone.');
        $this->assertSame($ids[1], $cursor());

        $sweep->invoke($task, $processable, 2, 'orphan');
        $this->assertSame([$ids[2], $ids[4], $ids[5]], $left(), 'The second window held one orphan and the keeper.');
        $this->assertSame($ids[3], $cursor(), 'The cursor moved past the keeper too: it was examined, not skipped.');

        $sweep->invoke($task, $processable, 2, 'orphan');
        $this->assertSame([$ids[2]], $left(), 'Control: the row whose source survives is left alone.');
        $this->assertSame($ids[5], $cursor());

        $sweep->invoke($task, $processable, 2, 'orphan');
        $this->assertSame(0, $cursor(), 'An empty window ends the pass.');
    }

    /**
     * Team members split across two windows still produce one descriptor.
     *
     * The dedup that collapses a team's member rows into one container
     * descriptor spans the whole sweep, not one window: reset per window, a
     * three-member team whose rows straddle a window boundary would be
     * dispatched twice.
     *
     * @return void
     */
    public function test_a_team_split_across_windows_is_still_dispatched_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$cm, , $assign, $course] = $this->build_environment(['teamsubmission' => 1]);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $members = [];
        while (count($members) < 3) {
            $member = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
            $members[] = $member;
        }
        group_resolver::reset_memo();
        $submitted = time() - 4 * 86400;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => 0, 'attemptnumber' => 0,
            'timecreated' => $submitted, 'timemodified' => $submitted,
            'status' => submission_status::SUBMITTED, 'groupid' => $group->id, 'latest' => 1,
        ]);
        $this->run_reconciler();
        $this->assertSame(3, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]), 'Sanity: one row per member.');

        // Windows of two over three member rows: the boundary falls inside the team.
        $DB->set_field('block_feedback_tracker_sub', 'islatest', 0, ['cmid' => $cm->id]);
        set_config('reconcile_batch_size', '2', 'block_feedback_tracker');
        $DB->delete_records('task_adhoc');
        $DB->delete_records('block_feedback_tracker_log');
        (new reconcile_ledger())->execute();

        $descriptors = 0;
        foreach (\core\task\manager::get_adhoc_tasks('\block_feedback_tracker\task\backfill_one_submission') as $task) {
            foreach ((array) (((array) $task->get_custom_data())['rows'] ?? []) as $descriptor) {
                if ((int) ((array) $descriptor)['cmid'] === (int) $cm->id) {
                    $descriptors++;
                }
            }
        }
        $details = json_decode(
            (string) $DB->get_field('block_feedback_tracker_log', 'details', ['reason' => 'reconcile']),
            true
        );
        $this->assertSame(2, (int) $details['sweeps']['latest']['windows'], 'Control: the team really straddled two windows.');
        $this->assertSame(1, $descriptors, 'One container descriptor for the group, whatever window each member fell in.');
    }

    /**
     * A sweep's share of the tick is an equal split of what is left, floored
     * at a second and capped at the tick's own deadline.
     *
     * Pure arithmetic, pinned as a table because the failure it guards against
     * — a sweep granted the whole tick, or none of it — only shows on a site
     * whose passes take longer than a tick, which no fixture here does.
     *
     * @return void
     */
    public function test_each_sweep_gets_an_equal_share_of_what_is_left(): void {
        $share = new \ReflectionMethod(reconcile_ledger::class, 'share_deadline');
        $cases = [
            'nine sweeps left, 50 s left: a fifth of a tenth each' => [1000, 1050, 9, 1005],
            'the last sweep gets everything that is left' => [1000, 1050, 1, 1050],
            'a share under a second is rounded up to one' => [1000, 1003, 9, 1001],
            'but never past the deadline' => [1000, 1000, 9, 1000],
            'nor before it when the deadline has already gone' => [1000, 990, 9, 990],
        ];
        foreach ($cases as $label => [$now, $deadline, $left, $expected]) {
            $this->assertSame($expected, $share->invoke(null, $now, $deadline, $left), $label);
        }
    }

    /**
     * Seed one submitted attempt per newly enrolled student, with no ledger row.
     *
     * @param \stdClass $assign The {assign} row.
     * @param \stdClass $course The course.
     * @param int $count Students to create and enrol.
     * @return \stdClass[] The students, in creation order.
     */
    private function seed_submissions(\stdClass $assign, \stdClass $course, int $count): array {
        $students = [];
        $tsubmit = time() - 4 * 86400;
        while (count($students) < $count) {
            $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $this->insert_submission((int) $assign->id, (int) $student->id, $tsubmit, 0);
            $students[] = $student;
        }
        return $students;
    }

    /**
     * Seed one submitted attempt per student, each with its ledger row.
     *
     * @param \stdClass $cm The course module.
     * @param \stdClass $assign The {assign} row.
     * @param \stdClass $course The course.
     * @param int $count Students to create and enrol.
     * @return int[] The ledger row ids, ascending.
     */
    private function seed_ledger_rows(\stdClass $cm, \stdClass $assign, \stdClass $course, int $count): array {
        foreach ($this->seed_submissions($assign, $course, $count) as $student) {
            submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        }
        $ids = $this->ledger_ids((int) $cm->id);
        $this->assertCount($count, $ids, 'Sanity: one ledger row per seeded student.');
        return $ids;
    }

    /**
     * The ledger row ids of one activity, ascending.
     *
     * Ordered in SQL on purpose: `get_fieldset_select()` takes no sort
     * argument, and without one PostgreSQL returns rows in heap order, which
     * any UPDATE can reshuffle.
     *
     * @param int $cmid
     * @return int[]
     */
    private function ledger_ids(int $cmid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql(
            'SELECT id FROM {block_feedback_tracker_sub} WHERE cmid = :cmid ORDER BY id ASC',
            ['cmid' => $cmid]
        ));
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
        /* runAdhocTasks() is class-scoped, so every worker the sweeps dispatch
         * has to be named here or its repair simply sits in {task_adhoc}. */
        $this->runAdhocTasks('\block_feedback_tracker\task\stamp_allocations');
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
     * Run one sweep over a course, one window, and list the students it
     * dispatched a repair for.
     *
     * Clears the adhoc queue first, so what is listed is this sweep's alone;
     * the repairs stay queued for the caller to drain.
     *
     * @param \stdClass $course The course in scope.
     * @param string $method The sweep method.
     * @param string $key Its cursor key.
     * @return int[] The dispatched user ids, ascending.
     */
    private function sweep_dispatches(\stdClass $course, string $method, string $key): array {
        global $DB;
        $DB->delete_records('task_adhoc');
        $task = new reconcile_ledger();
        (new \ReflectionMethod($task, $method))->invoke($task, [(int) $course->id], 500, $key);
        $userids = [];
        foreach (\core\task\manager::get_adhoc_tasks('\block_feedback_tracker\task\backfill_one_submission') as $queued) {
            foreach ((array) ($queued->get_custom_data()->rows ?? []) as $descriptor) {
                $userids[] = (int) ((array) $descriptor)['userid'];
            }
        }
        sort($userids);
        return $userids;
    }

    /**
     * Enrol a student and add them to the given groups, in order.
     *
     * @param \stdClass $course
     * @param int ...$groupids
     * @return int The student's user id.
     */
    private function rule_student(\stdClass $course, int ...$groupids): int {
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        foreach ($groupids as $groupid) {
            $this->getDataGenerator()->create_group_member(['groupid' => $groupid, 'userid' => $user->id]);
        }
        group_resolver::reset_memo();
        return (int) $user->id;
    }

    /**
     * Insert an {assign_overrides} row; every date not given is NULL, which
     * leaves it to the next source.
     *
     * @param int $assignid
     * @param array $fields userid or groupid (with sortorder), and the dates it sets.
     * @return int The override id.
     */
    private function rule_override(int $assignid, array $fields): int {
        global $DB;
        return (int) $DB->insert_record('assign_overrides', (object) array_merge([
            'assignid' => $assignid,
            'userid' => null,
            'groupid' => null,
            'sortorder' => null,
            'allowsubmissionsfromdate' => null,
            'duedate' => null,
            'cutoffdate' => null,
        ], $fields));
    }

    /**
     * The stored due date of one student's ledger row.
     *
     * @param \stdClass $cm
     * @param int $userid
     * @return int|null
     */
    private function stored_close(\stdClass $cm, int $userid): ?int {
        global $DB;
        $value = $DB->get_field('block_feedback_tracker_sub', 'timecloses', ['cmid' => $cm->id, 'userid' => $userid], MUST_EXIST);
        return $value === null ? null : (int) $value;
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
