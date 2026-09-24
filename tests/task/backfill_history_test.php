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
 * Tests for the backfill_history scheduled-task dispatcher.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\sla\backfill_cursor;
use block_feedback_tracker\local\sla\course_access;

/**
 * The dispatcher keeps one cursor per block-enabled course and fans historical
 * ledger upserts out to backfill_one_submission adhoc tasks. It does nothing
 * until backfill_active is set, and each course's cursor row flips to
 * active=0 once that course's backfill completes.
 *
 * @covers \block_feedback_tracker\task\backfill_history
 */
final class backfill_history_test extends \advanced_testcase {
    /**
     * Buffer and discard the dispatcher's mtrace() output.
     *
     * Moodle's phpunit.xml sets beStrictAboutOutputDuringTests, so unexpected
     * output marks a test risky (setOutputCallback() no longer exists in
     * PHPUnit 10+).
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
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

    public function test_inactive_by_default_is_noop(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->build_submissions(3);

        (new backfill_history())->execute();

        $this->assertCount(0, $this->queued_adhocs());
        global $DB;
        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_sub'));
    }

    public function test_active_enqueues_adhocs_that_produce_ledger_rows(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->build_submissions(3);
        set_config('backfill_active', '1', 'block_feedback_tracker');
        set_config('backfill_chunk', '10', 'block_feedback_tracker');

        (new backfill_history())->execute();

        // Dispatcher queued one adhoc (3 rows < default sub-chunk of 50)
        // and wrote nothing inline.
        global $DB;
        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_sub'));
        $this->assertCount(1, $this->queued_adhocs());

        // Running the queued adhoc task produces the ledger rows.
        $this->run_queued_adhocs();
        $this->assertSame(3, (int) $DB->count_records('block_feedback_tracker_sub'));
    }

    public function test_sub_chunk_splits_chunk_into_multiple_adhocs(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->build_submissions(5);
        set_config('backfill_active', '1', 'block_feedback_tracker');
        set_config('backfill_chunk', '10', 'block_feedback_tracker');
        set_config('backfill_sub_chunk', '2', 'block_feedback_tracker');

        (new backfill_history())->execute();

        // 5 rows, sub-chunk of 2 → 3 adhoc tasks (2 + 2 + 1).
        $this->assertCount(3, $this->queued_adhocs());

        $this->run_queued_adhocs();
        global $DB;
        $this->assertSame(5, (int) $DB->count_records('block_feedback_tracker_sub'));
    }

    public function test_cursor_advances_to_last_processed_id(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $courseid = $this->build_submissions(2);
        set_config('backfill_active', '1', 'block_feedback_tracker');
        set_config('backfill_chunk', '10', 'block_feedback_tracker');

        (new backfill_history())->execute();

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
        $this->assertNotFalse($row, 'A cursor row should be lazily created for the block-enabled course.');
        $maxid = (int) $DB->get_field_sql('SELECT MAX(id) FROM {assign_submission}');
        $this->assertSame($maxid, (int) $row->lastsubid);
    }

    /**
     * When a course has no submissions past its cursor, the dispatcher flips
     * that course's row to active=0 and leaves backfill_active on; the master
     * setting switches itself off only when no course is processable at all
     * (see the next test).
     */
    public function test_course_cursor_auto_completes_when_no_more_rows(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $courseid = $this->build_submissions(2);
        set_config('backfill_active', '1', 'block_feedback_tracker');
        set_config('backfill_chunk', '10', 'block_feedback_tracker');

        (new backfill_history())->execute();
        $this->run_queued_adhocs();

        // Second tick: nothing left past cursor for this course → complete.
        (new backfill_history())->execute();

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
        $this->assertSame(0, (int) $row->active, 'Cursor row should flip to active=0 after a no-rows tick.');
        // The master switch stays on while some course is processable.
        $this->assertSame('1', get_config('block_feedback_tracker', 'backfill_active'));
    }

    /**
     * When no course is processable site-wide, the dispatcher switches
     * backfill_active off itself.
     */
    public function test_master_auto_disables_when_no_processable_courses(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        // The processable-course list is memoised in a static that resetAfterTest()
        // does not clear; a non-empty list cached by an earlier test would skip
        // the auto-disable branch.
        course_access::reset_memo();

        // No block on any course, so no course is processable.
        set_config('backfill_active', '1', 'block_feedback_tracker');

        (new backfill_history())->execute();

        $this->assertSame('0', get_config('block_feedback_tracker', 'backfill_active'));
        $this->assertCount(0, $this->queued_adhocs());
    }

    public function test_chunk_size_caps_dispatched_rows(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->build_submissions(5);
        set_config('backfill_active', '1', 'block_feedback_tracker');
        set_config('backfill_chunk', '2', 'block_feedback_tracker');

        (new backfill_history())->execute();

        $this->run_queued_adhocs();
        global $DB;
        $this->assertSame(2, (int) $DB->count_records('block_feedback_tracker_sub'));
    }

    /**
     * Only submissions in courses carrying the block are dispatched: a course
     * without it gets neither a cursor row nor an adhoc task.
     */
    public function test_backfill_skips_courses_without_block(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        course_access::reset_memo();

        // Course A: block present. One submission.
        $coursea = $this->getDataGenerator()->create_course();
        $coursectxa = \context_course::instance($coursea->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectxa->id,
        ]);
        $assigna = $this->getDataGenerator()->create_module('assign', ['course' => $coursea->id]);
        $studenta = $this->getDataGenerator()->create_and_enrol($coursea, 'student');

        // Course B: no block. One submission.
        $courseb = $this->getDataGenerator()->create_course();
        $assignb = $this->getDataGenerator()->create_module('assign', ['course' => $courseb->id]);
        $studentb = $this->getDataGenerator()->create_and_enrol($courseb, 'student');

        global $DB;
        $now = time();
        $base = (object) [
            'attemptnumber' => 0,
            'timecreated'   => $now - 3600,
            'timemodified'  => $now - 3600,
            'status'        => 'submitted',
            'groupid'       => 0,
            'latest'        => 1,
        ];
        $suba = clone $base;
        $suba->assignment = $assigna->id;
        $suba->userid = $studenta->id;
        $DB->insert_record('assign_submission', $suba);
        $subb = clone $base;
        $subb->assignment = $assignb->id;
        $subb->userid = $studentb->id;
        $DB->insert_record('assign_submission', $subb);

        set_config('backfill_active', '1', 'block_feedback_tracker');
        set_config('backfill_chunk', '10', 'block_feedback_tracker');

        (new backfill_history())->execute();

        // One adhoc queued — only the block-present course's submission.
        $this->assertCount(1, $this->queued_adhocs());

        $this->run_queued_adhocs();
        $rows = $DB->get_records('block_feedback_tracker_sub');
        $this->assertCount(1, $rows, 'Only the block-present course should backfill.');
        $row = reset($rows);
        $this->assertSame((int) $studenta->id, (int) $row->userid);

        // Course A's cursor advances to its highest submission id; course B has
        // no cursor row, because cursors are created only for processable
        // courses. The next test covers course B gaining the block later.
        $rowa = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $coursea->id]);
        $this->assertNotFalse($rowa);
        $courseasubid = (int) $DB->get_field_sql(
            'SELECT MAX(s.id) FROM {assign_submission} s WHERE s.assignment = :aid',
            ['aid' => $assigna->id]
        );
        $this->assertSame($courseasubid, (int) $rowa->lastsubid);
        $this->assertFalse(
            $DB->record_exists('block_feedback_tracker_bfcursor', ['courseid' => $courseb->id]),
            'No-block course should not have a cursor row.'
        );
    }

    /**
     * Per-course cursor independence: course A is mid-backfill, admin
     * adds the block to course B later, next tick picks up course B
     * from cursor=0 without disturbing course A's progress.
     */
    public function test_added_block_mid_flight_starts_new_course_at_zero(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        course_access::reset_memo();

        // Course A starts with block + 1 submission; backfill runs.
        $coursea = $this->getDataGenerator()->create_course();
        $coursectxa = \context_course::instance($coursea->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectxa->id,
        ]);
        $assigna = $this->getDataGenerator()->create_module('assign', ['course' => $coursea->id]);
        $studenta = $this->getDataGenerator()->create_and_enrol($coursea, 'student');

        // Course B exists but no block yet, with 1 submission.
        $courseb = $this->getDataGenerator()->create_course();
        $assignb = $this->getDataGenerator()->create_module('assign', ['course' => $courseb->id]);
        $studentb = $this->getDataGenerator()->create_and_enrol($courseb, 'student');

        global $DB;
        $now = time();
        $base = (object) [
            'attemptnumber' => 0, 'timecreated' => $now - 3600, 'timemodified' => $now - 3600,
            'status' => 'submitted', 'groupid' => 0, 'latest' => 1,
        ];
        $suba = clone $base;
        $suba->assignment = $assigna->id;
        $suba->userid = $studenta->id;
        $DB->insert_record('assign_submission', $suba);
        $subb = clone $base;
        $subb->assignment = $assignb->id;
        $subb->userid = $studentb->id;
        $DB->insert_record('assign_submission', $subb);

        set_config('backfill_active', '1', 'block_feedback_tracker');
        set_config('backfill_chunk', '10', 'block_feedback_tracker');

        // First tick: only course A is processable.
        (new backfill_history())->execute();
        $this->assertCount(1, $this->queued_adhocs());

        // Admin now adds the block to course B.
        $coursectxb = \context_course::instance($courseb->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectxb->id,
        ]);
        course_access::reset_memo();

        // Second tick: course B is now processable; its cursor is lazily
        // created at 0; course A's cursor (already past course A's row)
        // means course A produces 0 new dispatched rows and flips to
        // active=0; course B produces 1.
        (new backfill_history())->execute();

        $rowb = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $courseb->id]);
        $this->assertNotFalse($rowb, 'New course should have a cursor row after one tick.');
        $coursebsubid = (int) $DB->get_field_sql(
            'SELECT MAX(s.id) FROM {assign_submission} s WHERE s.assignment = :aid',
            ['aid' => $assignb->id]
        );
        $this->assertSame($coursebsubid, (int) $rowb->lastsubid);

        // Course A's cursor unchanged from tick 1; should be at active=0
        // now since tick 2 found nothing past its cursor.
        $rowa = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $coursea->id]);
        $this->assertSame(0, (int) $rowa->active);
    }

    /**
     * Round-robin fairness: when more active courses exist than the
     * per-tick budget allows, the dispatcher rotates the start point
     * so high-courseid courses don't starve behind low-courseid ones.
     */
    public function test_round_robin_rotates_start_across_ticks(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        course_access::reset_memo();

        // 4 courses, each with the block + 5 submissions. Total cap = 6,
        // per-course cap = 2 → at most 3 courses fit per tick.
        // Tick 1 should process the first 3 by courseid order; tick 2
        // should rotate to start at the 4th (and wrap to course 1).
        $coursemap = [];
        for ($i = 0; $i < 4; $i++) {
            $course = $this->getDataGenerator()->create_course();
            $coursectx = \context_course::instance($course->id);
            $this->getDataGenerator()->create_block('feedback_tracker', [
                'parentcontextid' => $coursectx->id,
            ]);
            $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
            global $DB;
            $now = time();
            for ($j = 0; $j < 5; $j++) {
                $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
                $DB->insert_record('assign_submission', (object) [
                    'assignment'    => $assign->id,
                    'userid'        => $student->id,
                    'attemptnumber' => 0,
                    'timecreated'   => $now - ($j + 1) * 3600,
                    'timemodified'  => $now - ($j + 1) * 3600,
                    'status'        => 'submitted',
                    'groupid'       => 0,
                    'latest'        => 1,
                ]);
            }
            $coursemap[(int) $course->id] = $course;
        }
        ksort($coursemap);
        $courseids = array_keys($coursemap);

        set_config('backfill_active', '1', 'block_feedback_tracker');
        set_config('backfill_chunk', '6', 'block_feedback_tracker');
        set_config('backfill_chunk_per_course', '2', 'block_feedback_tracker');
        set_config('backfill_sub_chunk', '50', 'block_feedback_tracker');

        // Tick 1: rotation starts at courseid > 0 → first 3 courses.
        (new backfill_history())->execute();
        $tick1last = (int) get_config('block_feedback_tracker', 'bfdispatch_last_courseid');
        $this->assertSame($courseids[2], $tick1last, 'Tick 1 should process courses 1-3 and stop at course 3.');

        // Tick 2: rotation starts at courseid > tick1last → course 4
        // first, then wraps to courses 1 and 2.
        (new backfill_history())->execute();
        $tick2last = (int) get_config('block_feedback_tracker', 'bfdispatch_last_courseid');
        $this->assertSame($courseids[1], $tick2last, 'Tick 2 should process course 4 + wrap to 1, 2 and stop at 2.');
    }

    // Helpers.

    /**
     * Pending adhoc backfill tasks queued during this test.
     *
     * @return array<int, \core\task\adhoc_task>
     */
    private function queued_adhocs(): array {
        return \core\task\manager::get_adhoc_tasks(backfill_one_submission::class);
    }

    /**
     * Execute every queued backfill adhoc task in-process. Mirrors what
     * a cron worker would do per task but without the queue lifecycle.
     */
    private function run_queued_adhocs(): void {
        foreach ($this->queued_adhocs() as $task) {
            $task->execute();
        }
    }

    /**
     * Create $count students + their assign submissions in a single course.
     *
     * @param int $count The number of submissions to create.
     * @return int The created courseid.
     */
    private function build_submissions(int $count): int {
        $course = $this->getDataGenerator()->create_course();
        // Course_access::is_processable() needs a course-context block,
        // otherwise the dispatcher skips every row. Reset the memo too,
        // because earlier tests may have cached the no-block (false)
        // result for a recycled courseid.
        $coursectx = \context_course::instance($course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        course_access::reset_memo();

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        global $DB;
        $now = time();
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
        }
        return (int) $course->id;
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
        set_config('drain_time_cap_seconds', '50', 'block_feedback_tracker');

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
