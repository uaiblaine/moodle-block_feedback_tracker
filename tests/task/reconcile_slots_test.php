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
 * Tests for the reconciler's marker-slot sweep.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\group_resolver;
use block_feedback_tracker\local\sla\slot_ledger;
use block_feedback_tracker\local\sla\submission_ledger;
use block_feedback_tracker\local\sla\submission_status;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The slot sweep repairs what the events missed: (a) an active allocation with
 * no live slot row, (b) a live slot row whose marker no longer holds an active
 * slot, (c) a complete mark whose slot row has no completion. Each state is
 * produced behind the observers' back, by writing core's tables or the slot
 * table directly, as a lost event or a later-processable course leaves it.
 */
#[CoversClass(reconcile_ledger::class)]
#[CoversClass(slot_ledger::class)]
final class reconcile_slots_test extends \advanced_testcase {
    /**
     * Flush per-request statics that survive resetAfterTest().
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
     * (a) A marker holding an active slot with no slot row gets one, stamped
     * at the discovery instant and labelled reconciled.
     *
     * @return void
     */
    public function test_missing_slot_rows_are_created_as_reconciled(): void {
        global $DB;
        $env = $this->build_environment(1);
        $student = $env->students[0];
        $DB->delete_records('block_feedback_tracker_alloc', ['userid' => $student->id]);

        $before = time();
        $this->sweep($env, 500);

        $slots = $DB->get_records('block_feedback_tracker_alloc', ['userid' => $student->id], 'position ASC');
        $this->assertCount(2, $slots);
        foreach ($slots as $s) {
            $this->assertSame(slot_ledger::SOURCE_RECONCILED, $s->allocsource);
            $this->assertGreaterThanOrEqual($before, (int) $s->timeallocated);
            $this->assertNull($s->timeremoved);
        }
        $this->assertSame([1, 2], array_map(fn($s) => (int) $s->position, array_values($slots)));
    }

    /**
     * (b) A marker removed in core without an event loses their live row.
     *
     * @return void
     */
    public function test_a_silently_removed_marker_loses_the_slot(): void {
        global $DB;
        $env = $this->build_environment(1);
        $student = $env->students[0];
        $gone = $env->markers[1];
        $DB->delete_records('assign_allocated_marker', [
            'assignment' => $env->assign->get_instance()->id,
            'student' => $student->id,
            'marker' => $gone->id,
        ]);

        $this->sweep($env, 500);

        $rows = $DB->get_records('block_feedback_tracker_alloc', ['userid' => $student->id]);
        foreach ($rows as $r) {
            if ((int) $r->marker === (int) $gone->id) {
                $this->assertNotNull($r->timeremoved);
            } else {
                $this->assertNull($r->timeremoved, 'Control: the marker still allocated keeps the slot.');
            }
        }
    }

    /**
     * (c) A complete mark whose slot has no completion is completed at the
     * mark's last change.
     *
     * @return void
     */
    public function test_a_missed_completion_is_recorded_at_the_mark_instant(): void {
        global $DB;
        $env = $this->build_environment(1);
        $student = $env->students[0];
        $marker = $env->markers[0];
        $this->mark($env, $student, $marker, 60, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $this->mark($env, $student, $env->markers[1], 70, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);
        $DB->set_field('block_feedback_tracker_alloc', 'timemarked', null, ['userid' => $student->id]);
        $markedat = (int) $DB->get_field('assign_mark', 'timemodified', ['marker' => $marker->id], MUST_EXIST) - 50;
        $DB->set_field('assign_mark', 'timemodified', $markedat, ['marker' => $marker->id]);

        $this->sweep($env, 500);

        $done = $DB->get_record('block_feedback_tracker_alloc', ['userid' => $student->id, 'marker' => $marker->id]);
        $this->assertSame($markedat, (int) $done->timemarked);
        $this->assertSame(
            null,
            $DB->get_field('block_feedback_tracker_alloc', 'timemarked', [
                'userid' => $student->id,
                'marker' => $env->markers[1]->id,
            ]),
            'Control: a mark still in marking is not a completion.'
        );
    }

    /**
     * An optional slot disabled behind core's API is not an active slot: the
     * first pass ends the marker's live row, and the second finds nothing,
     * because a disabled slot is not a missing one either.
     *
     * @return void
     */
    public function test_a_disabled_optional_slot_is_repaired_once(): void {
        global $DB;
        $env = $this->build_environment(1, 1);
        $student = $env->students[0];
        $extra = $this->getDataGenerator()->create_and_enrol($env->course, 'editingteacher');
        $env->assign->update_marker_allocations((int) $student->id, [3 => [(int) $extra->id, 1]]);
        $this->assertSame(0, $this->sweep($env, 500), 'Control: converged with the optional slot live.');
        $DB->set_field('assign_allocated_marker', 'enabled', 0, ['marker' => $extra->id]);

        $this->assertSame(1, $this->sweep($env, 500));
        $this->assertNotNull($DB->get_field('block_feedback_tracker_alloc', 'timeremoved', ['marker' => $extra->id]));
        $this->assertSame(0, $this->sweep($env, 500), 'Converged: the disabled slot is not missing a row.');
    }

    /**
     * A converged ledger repairs nothing.
     *
     * @return void
     */
    public function test_a_converged_ledger_repairs_nothing(): void {
        $env = $this->build_environment(2);
        $this->assertSame(0, $this->sweep($env, 500));
    }

    /**
     * The sweep pages over its driving set: one window per call with
     * sweepdeadline 0, the cursor at the end of each window, repairs found in
     * the window they sit in, and an empty window ending the pass.
     *
     * @return void
     */
    public function test_the_sweep_pages_across_windows(): void {
        global $DB;
        $env = $this->build_environment(5);
        $ids = array_map('intval', array_keys($DB->get_records(
            'block_feedback_tracker_sub',
            ['cmid' => $env->cm->id],
            'id ASC',
            'id'
        )));
        $this->assertCount(5, $ids);
        // Only the last row's slots are lost: three windows of two reach it.
        $last = (int) $DB->get_field('block_feedback_tracker_sub', 'userid', ['id' => end($ids)]);
        $DB->delete_records('block_feedback_tracker_alloc', ['userid' => $last]);

        $task = new reconcile_ledger();
        $sweep = new \ReflectionMethod($task, 'sweep_marker_slots');
        (new \ReflectionProperty($task, 'sweepdeadline'))->setValue($task, 0);
        $processable = [(int) $env->course->id];
        $cursor = static fn(): int => (int) get_config('block_feedback_tracker', 'reconcile_cursor_slots');
        $lost = static fn(): int => $DB->count_records('block_feedback_tracker_alloc', ['userid' => $last]);

        $this->assertSame(0, $sweep->invoke($task, $processable, 2, 'slots'));
        $this->assertSame($ids[1], $cursor());
        $this->assertFalse((new \ReflectionProperty($task, 'exhausted'))->getValue($task)['slots']);

        $this->assertSame(0, $sweep->invoke($task, $processable, 2, 'slots'));
        $this->assertSame($ids[3], $cursor());
        $this->assertSame(0, $lost(), 'Not reached yet.');

        $this->assertSame(1, $sweep->invoke($task, $processable, 2, 'slots'));
        $this->assertSame(2, $lost(), 'The third window held the lost slots.');
        $this->assertSame(0, $cursor(), 'A short window ends the pass.');
    }

    /**
     * The registry runs the sweep on an ordinary tick.
     *
     * @return void
     */
    public function test_the_tick_runs_the_slot_sweep(): void {
        global $DB;
        $env = $this->build_environment(1);
        $DB->delete_records('block_feedback_tracker_alloc');
        set_config('reconcile_time_cap_seconds', '60', 'block_feedback_tracker');

        ob_start();
        (new reconcile_ledger())->execute();
        ob_end_clean();

        $this->assertSame(2, $DB->count_records('block_feedback_tracker_alloc', ['userid' => $env->students[0]->id]));
        $details = json_decode(
            (string) $DB->get_field('block_feedback_tracker_log', 'details', ['reason' => 'reconcile']),
            true
        );
        $this->assertSame(1, (int) $details['sweeps']['slots']['rows']);
    }

    /**
     * Run the slot sweep over the environment's course, as far as it goes.
     *
     * @param \stdClass $env
     * @param int $batch Window size.
     * @return int Repairs made.
     */
    private function sweep(\stdClass $env, int $batch): int {
        $task = new reconcile_ledger();
        (new \ReflectionProperty($task, 'sweepdeadline'))->setValue($task, time() + 60);
        return (new \ReflectionMethod($task, 'sweep_marker_slots'))->invoke($task, [(int) $env->course->id], $batch, 'slots');
    }

    /**
     * A processable course with a two-marker assign and students each handed
     * in with both markers allocated through core.
     *
     * @param int $students Number of students.
     * @param int $optional Number of optional marker slots.
     * @return \stdClass course, cm, assign, students and markers.
     */
    private function build_environment(int $students, int $optional = 0): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablebusinesshours', 0, 'block_feedback_tracker');

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();

        $inst = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'markingworkflow' => 1,
            'markingallocation' => 1,
            'markercount' => 2,
            'optionalmarkercount' => $optional,
            'multimarkmethod' => 'average',
            'grade' => 100,
        ]);
        $cm = get_coursemodule_from_instance('assign', $inst->id);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $env = (object) ['course' => $course, 'cm' => $cm, 'assign' => $assign, 'students' => []];
        $env->markers = [
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher'),
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher'),
        ];

        $t1 = time() - 3 * DAYSECS;
        for ($i = 0; $i < $students; $i++) {
            $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $DB->insert_record('assign_submission', (object) [
                'assignment' => $inst->id, 'userid' => $student->id, 'attemptnumber' => 0,
                'timecreated' => $t1, 'timemodified' => $t1,
                'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
            ]);
            submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
            $assign->update_marker_allocations((int) $student->id, [
                1 => [(int) $env->markers[0]->id, null],
                2 => [(int) $env->markers[1]->id, null],
            ]);
            $env->students[] = $student;
        }
        return $env;
    }

    /**
     * Save one marker's mark for a student in a workflow state.
     *
     * @param \stdClass $env
     * @param \stdClass $student
     * @param \stdClass $marker
     * @param float $mark
     * @param string $state The marker's own workflow state.
     * @return void
     */
    private function mark(\stdClass $env, \stdClass $student, \stdClass $marker, float $mark, string $state): void {
        $grade = $env->assign->get_user_grade((int) $student->id, true);
        $grade->grader = (int) $marker->id;
        $env->assign->update_mark($grade, $mark, $state);
    }
}
