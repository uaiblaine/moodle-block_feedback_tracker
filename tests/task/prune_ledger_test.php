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
 * Tests for the retention pruner.
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
use block_feedback_tracker\local\sla\retention;
use block_feedback_tracker\local\sla\submission_ledger;
use block_feedback_tracker\local\sla\submission_status;

/**
 * The ledger has no natural ceiling, so retention is what bounds it. These
 * tests pin the two rules that make deleting data unattended defensible — only
 * closed measurements are touched, and the reconciler agrees on the boundary
 * so it cannot resurrect what was just deleted.
 *
 * @covers \block_feedback_tracker\task\prune_ledger
 * @covers \block_feedback_tracker\local\sla\retention
 */
final class prune_ledger_test extends \advanced_testcase {
    /**
     * Flush per-request statics and swallow the task's mtrace() output, which
     * PHPUnit 11 would otherwise treat as a risky test.
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
     * Off by default: an upgrade must not start deleting a site's history
     * because a new version shipped a policy.
     *
     * @return void
     */
    public function test_retention_is_off_until_switched_on(): void {
        $this->resetAfterTest();
        $this->assertNull(retention::cutoff());
        $this->assertFalse(retention::is_active());

        set_config('retention_active', '1', 'block_feedback_tracker');
        $this->assertNotNull(retention::cutoff());
    }

    /**
     * A window shorter than the 30-day statistical window would delete the
     * score's and the medians' own inputs, so it falls back to the default
     * rather than silently corrupting them.
     *
     * @return void
     */
    public function test_too_short_a_window_falls_back_to_the_default(): void {
        $this->resetAfterTest();
        set_config('retention_active', '1', 'block_feedback_tracker');
        set_config('retention_days', '5', 'block_feedback_tracker');

        $now = 1800000000;
        $this->assertSame(
            $now - retention::DEFAULT_DAYS * 86400,
            retention::cutoff($now)
        );
    }

    /**
     * The headline behaviour: closed measurements past the window go, recent
     * ones stay.
     *
     * @return void
     */
    public function test_closed_rows_past_the_window_are_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('retention_active', '1', 'block_feedback_tracker');
        set_config('retention_days', '365', 'block_feedback_tracker');

        $gen = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $now = time();
        $old = $gen->create_ledger_row([
            'timesubmitted' => $now - 400 * 86400,
            'timegraded' => $now - 399 * 86400,
        ]);
        $recent = $gen->create_ledger_row([
            'timesubmitted' => $now - 10 * 86400,
            'timegraded' => $now - 9 * 86400,
        ]);

        (new prune_ledger())->execute();

        $this->assertFalse($DB->record_exists('block_feedback_tracker_sub', ['id' => $old]));
        $this->assertTrue($DB->record_exists('block_feedback_tracker_sub', ['id' => $recent]));
    }

    /**
     * A submission still awaiting feedback is outstanding work, and its age is
     * exactly the signal the plugin exists to surface. No age threshold may
     * reach it — deleting the oldest pending items would hide the worst of the
     * backlog.
     *
     * @return void
     */
    public function test_pending_rows_are_never_deleted_however_old(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('retention_active', '1', 'block_feedback_tracker');

        $gen = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $ancient = $gen->create_ledger_row([
            'timesubmitted' => time() - 2000 * 86400,
            'timegraded' => null,
            'submissionstatus' => submission_status::SUBMITTED,
        ]);

        (new prune_ledger())->execute();

        $this->assertTrue(
            $DB->record_exists('block_feedback_tracker_sub', ['id' => $ancient]),
            'An unanswered submission is outstanding work, not expired history.'
        );
    }

    /**
     * With retention off, nothing is ever removed — including rows far older
     * than any window.
     *
     * @return void
     */
    public function test_nothing_is_deleted_while_retention_is_off(): void {
        global $DB;
        $this->resetAfterTest();

        $gen = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $now = time();
        $id = $gen->create_ledger_row([
            'timesubmitted' => $now - 3000 * 86400,
            'timegraded' => $now - 2999 * 86400,
        ]);

        (new prune_ledger())->execute();

        $this->assertTrue($DB->record_exists('block_feedback_tracker_sub', ['id' => $id]));
    }

    /**
     * The convergence property. The reconciler recreates a ledger row for any
     * submission that lacks one, so without a shared boundary it would
     * resurrect everything the pruner deleted on the very next tick, and the
     * two would burn a batch against each other for ever.
     *
     * @return void
     */
    public function test_the_reconciler_does_not_resurrect_pruned_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        set_config('retention_active', '1', 'block_feedback_tracker');
        set_config('retention_days', '365', 'block_feedback_tracker');

        [$cm, $student, $assign] = $this->build_environment();
        $now = time();
        $tsubmit = $now - 500 * 86400;
        $tgrade = $now - 499 * 86400;

        // A real, still-present submission whose measurement is long expired.
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $tsubmit, 'timemodified' => $tsubmit,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => 75.0, 'timecreated' => $tgrade, 'timemodified' => $tgrade,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));

        (new prune_ledger())->execute();
        $this->assertSame(
            0,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'Sanity: the expired measurement was pruned.'
        );

        // The submission is still there, so a boundary-blind reconciler would
        // put the row straight back.
        (new reconcile_ledger())->execute();
        $this->runAdhocTasks('\block_feedback_tracker\task\backfill_one_submission');

        $this->assertSame(
            0,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'The reconciler must respect the retention boundary, not fight the pruner.'
        );
    }

    /**
     * With retention off the reconciler keeps its historical reach: a
     * submission of any age still gets a ledger row.
     *
     * @return void
     */
    public function test_the_reconciler_keeps_full_reach_when_retention_is_off(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();

        [$cm, $student, $assign] = $this->build_environment();
        $tsubmit = time() - 900 * 86400;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $tsubmit, 'timemodified' => $tsubmit,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);

        (new reconcile_ledger())->execute();
        $this->runAdhocTasks('\block_feedback_tracker\task\backfill_one_submission');

        $this->assertSame(1, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));
    }

    /**
     * Build a processable course with an enrolled student and an assign.
     *
     * @return array The cm, the student, the assign record and the course.
     */
    private function build_environment(): array {
        $course = $this->getDataGenerator()->create_course();
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
