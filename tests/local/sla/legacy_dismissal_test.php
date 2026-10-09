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
 * Dismissal of the pending rows that lost their response before the cycle model.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\audit\recompute_log;

/**
 * Only cycle-0 rows with no recorded response, whose attempt carries an older
 * mark and whose hand-in predates the cutoff, are selected; a dismissed row
 * leaves every population and the writer keeps it out until new work arrives.
 *
 * @covers \block_feedback_tracker\local\sla\legacy_dismissal
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 */
final class legacy_dismissal_test extends \advanced_testcase {
    /** @var int The instant every fixture time is counted from, read once per test. */
    private int $base;

    /**
     * Fix the base instant and flush the static memos resetAfterTest() leaves
     * behind.
     *
     * The base is read once: a fixture that called time() again could cross a
     * second boundary and move a hand-in a second away from a mark meant to
     * share it.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->base = time();
        submission_ledger::reset_memos();
        group_resolver::reset_memo();
        group_access::reset_memo();
    }

    /**
     * The cutoff is the earliest record of this plugin reaching the
     * cycle-model savepoint, and there is none on a site that never ran it.
     *
     * @return void
     */
    public function test_cycle_model_time_reads_the_savepoint(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records('upgrade_log', ['plugin' => 'block_feedback_tracker']);
        $this->assertNull(legacy_dismissal::cycle_model_time());

        $log = fn(string $plugin, string $version, string $info, int $when) => $DB->insert_record('upgrade_log', (object) [
            'type' => 0, 'plugin' => $plugin, 'version' => $version, 'targetversion' => '2026100901',
            'info' => $info, 'userid' => 2, 'timemodified' => $when,
        ]);
        $log('block_feedback_tracker', '2026080200', 'Upgrade savepoint reached', 1000);
        $log('block_other', legacy_dismissal::CYCLE_MODEL_VERSION, 'Upgrade savepoint reached', 1500);
        $log('block_feedback_tracker', legacy_dismissal::CYCLE_MODEL_VERSION, 'Upgrade savepoint reached', 3000);
        $log('block_feedback_tracker', legacy_dismissal::CYCLE_MODEL_VERSION, 'Upgrade savepoint reached', 2000);
        $log('block_feedback_tracker', legacy_dismissal::CYCLE_MODEL_VERSION, 'Some other note', 1200);

        $this->assertSame(2000, legacy_dismissal::cycle_model_time());
    }

    /**
     * Every condition of the selection, each held by a row that misses only
     * that one.
     *
     * @return void
     */
    public function test_candidates_select_only_legacy_rows(): void {
        $this->resetAfterTest();
        [$cm, $assign, $course] = $this->build_environment();
        [$mark, $handin, $cutoff] = $this->times();

        $legacy = $this->legacy_row($cm, $assign, $course, $mark, []);
        $misses = [
            'handed in after the cutoff' => $this->legacy_row($cm, $assign, $course, $mark, ['timesubmitted' => $cutoff]),
            'marked after the hand-in' => $this->legacy_row($cm, $assign, $course, $handin + 60, []),
            'marked in the same second' => $this->legacy_row($cm, $assign, $course, $handin, []),
            'placeholder grade' => $this->legacy_row($cm, $assign, $course, $mark, [], -1.0),
            'cleared grade' => $this->legacy_row($cm, $assign, $course, $mark, [], null),
            'no grade row' => $this->legacy_row($cm, $assign, $course, null, []),
            'later cycle' => $this->legacy_row($cm, $assign, $course, $mark, ['cycle' => 1]),
            'draft' => $this->legacy_row($cm, $assign, $course, $mark, ['submissionstatus' => submission_status::DRAFT]),
            'not current' => $this->legacy_row($cm, $assign, $course, $mark, ['iscurrent' => 0]),
            'superseded attempt' => $this->legacy_row($cm, $assign, $course, $mark, ['islatest' => 0]),
            'answered' => $this->legacy_row($cm, $assign, $course, $mark, ['timegraded' => $handin + 60, 'timemarked' => null]),
            'mark recorded' => $this->legacy_row($cm, $assign, $course, $mark, ['timemarked' => $mark]),
            'already dismissed' => $this->legacy_row($cm, $assign, $course, $mark, ['timedismissed' => $cutoff]),
        ];

        $selected = array_map('intval', array_keys(legacy_dismissal::candidates($cutoff)));
        $this->assertSame([$legacy], $selected);
        foreach ($misses as $why => $id) {
            $this->assertNotContains($id, $selected, $why);
        }

        $row = legacy_dismissal::candidates($cutoff)[$legacy];
        $this->assertSame($mark, (int) $row->timemark);
        $this->assertSame([], legacy_dismissal::candidates($cutoff, (int) $course->id + 1000));
        $this->assertCount(1, legacy_dismissal::candidates($cutoff, (int) $course->id));
    }

    /**
     * A dismissed row leaves the pending list and the rollup's pending count,
     * enters no graded population, re-queues its rollup and is recorded.
     *
     * @return void
     */
    public function test_dismissal_takes_the_row_out_of_every_population(): void {
        global $DB;
        $this->resetAfterTest();
        [$cm, $assign, $course, $teacher] = $this->build_environment();
        [$mark, , $cutoff] = $this->times();
        $id = $this->legacy_row($cm, $assign, $course, $mark, []);

        $this->assertTrue(rollup_service::recompute_group((int) $course->id, 0));
        $this->assertSame(1, (int) $DB->get_field('block_feedback_tracker_group', 'pending', ['courseid' => $course->id]));
        $pending = submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => 'pending']);
        $this->assertSame(1, $pending['total']);
        $DB->delete_records('block_feedback_tracker_queue');

        $this->assertSame(1, legacy_dismissal::dismiss($cutoff, 0, (int) $teacher->id));

        $row = $DB->get_record('block_feedback_tracker_sub', ['id' => $id]);
        $this->assertNotNull($row->timedismissed);
        $this->assertSame(0, (int) $row->iscurrent);
        $this->assertNull($row->timegraded);
        $this->assertTrue($DB->record_exists('block_feedback_tracker_queue', ['courseid' => $course->id, 'groupid' => 0]));

        $this->assertTrue(rollup_service::recompute_group((int) $course->id, 0));
        $this->assertSame(0, (int) $DB->get_field('block_feedback_tracker_group', 'pending', ['courseid' => $course->id]));
        foreach (['pending', 'graded'] as $mode) {
            $this->assertSame(0, submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => $mode])['total']);
        }

        $log = $DB->get_records('block_feedback_tracker_log', ['reason' => recompute_log::REASON_LEGACY_DISMISSAL]);
        $this->assertCount(1, $log);
        $log = reset($log);
        $this->assertSame(1, (int) $log->affectedrows);
        $this->assertSame((int) $teacher->id, (int) $log->triggeredby);

        // A second run finds nothing left to take.
        $this->assertSame(0, legacy_dismissal::dismiss($cutoff));
    }

    /**
     * Re-deriving a dismissed row leaves it dismissed, a later mark measures
     * nothing, and only work saved after the dismissal opens a new cycle.
     *
     * @return void
     */
    public function test_writer_keeps_a_dismissed_row_until_new_work(): void {
        global $DB;
        $this->resetAfterTest();
        [$cm, $assign, $course, , $student] = $this->build_environment();
        [$mark, $handin, $cutoff] = $this->times();

        // The legacy state, produced by the writer itself: a mark older than the hand-in.
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $handin, 'timemodified' => $handin,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
        $gradeid = $DB->insert_record('assign_grades', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => 70.0, 'timecreated' => $mark, 'timemodified' => $mark,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertSame(1, legacy_dismissal::dismiss($cutoff));
        $dismissed = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'cycle' => 0]);

        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertEquals($dismissed, $DB->get_record('block_feedback_tracker_sub', ['id' => $dismissed->id]));

        // The teacher grades again: still nothing measured, nothing listed.
        $DB->set_field('assign_grades', 'timemodified', time() + 30, ['id' => $gradeid]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertEquals($dismissed, $DB->get_record('block_feedback_tracker_sub', ['id' => $dismissed->id]));

        // The student saves new work after the dismissal.
        $later = time() + 60;
        $DB->set_field('assign_submission', 'timemodified', $later, ['assignment' => $assign->id]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $rows = $DB->get_records('block_feedback_tracker_sub', ['cmid' => $cm->id], 'cycle ASC');
        $this->assertCount(2, $rows);
        [$first, $second] = array_values($rows);
        $this->assertSame((int) $dismissed->timedismissed, (int) $first->timedismissed);
        $this->assertSame(0, (int) $first->iscurrent);
        $this->assertSame(1, (int) $second->cycle);
        $this->assertSame($later, (int) $second->timesubmitted);
        $this->assertSame(1, (int) $second->iscurrent);
        $this->assertNull($second->timedismissed);
        $this->assertNull($second->timegraded, 'The mark predates the new work, so the new cycle is pending.');
    }

    /**
     * A mark, a hand-in a day later, and a cutoff a day after that, all in the
     * past. Only instants and membership are asserted, never hours.
     *
     * @return int[] [mark, hand-in, cutoff]
     */
    private function times(): array {
        $mark = $this->base - 10 * DAYSECS;
        return [$mark, $mark + DAYSECS, $mark + 2 * DAYSECS];
    }

    /**
     * A processable course with an editing teacher, a student and an assign.
     *
     * @return array [cm, assign, course, teacher, student]
     */
    private function build_environment(): array {
        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')->seed_default_platform_calendar();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        return [$cm, $assign, $course, $teacher, $student];
    }

    /**
     * A new enrolled student with a legacy-shaped pending row: cycle 0, handed
     * in a day after the mark, nothing recorded.
     *
     * @param \stdClass $cm
     * @param \stdClass $assign
     * @param \stdClass $course
     * @param int|null $marktime When the live grade was saved; null for no grade row.
     * @param array $overrides Ledger columns.
     * @param float|null $grade The grade value.
     * @return int Ledger row id.
     */
    private function legacy_row(
        \stdClass $cm,
        \stdClass $assign,
        \stdClass $course,
        ?int $marktime,
        array $overrides,
        ?float $grade = 70.0
    ): int {
        global $DB;
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        if ($marktime !== null) {
            $DB->insert_record('assign_grades', (object) [
                'assignment' => $assign->id, 'userid' => $user->id, 'attemptnumber' => 0,
                'grader' => 2, 'grade' => $grade, 'timecreated' => $marktime, 'timemodified' => $marktime,
            ]);
        }
        [, $handin] = $this->times();
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')->create_ledger_row(
            array_merge([
                'courseid' => (int) $course->id,
                'cmid' => (int) $cm->id,
                'iteminstance' => (int) $assign->id,
                'userid' => (int) $user->id,
                'timesubmitted' => $handin,
            ], $overrides)
        );
    }
}
