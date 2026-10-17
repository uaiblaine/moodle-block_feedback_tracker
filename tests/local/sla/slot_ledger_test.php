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
 * Tests for the per-marker slot table and its writers.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\calendar\calendar;

/**
 * The slot table on Moodle 5.2, driven through mod_assign's own API.
 *
 * Allocations go through assign::update_allocated_markers() and marks through
 * assign::update_mark(), so the events are the ones core fires: marker_updated
 * per marker that stays or is added, and nothing for a removal or a
 * completion. Where an hours figure is asserted, both ends of the interval are
 * pinned instants passed to the writer and the working hours are set by the
 * test, as the width rule in CLAUDE.md requires.
 *
 * The class-level covers docblock is kept, not attributes: this branch family
 * still supports Moodle 4.5, whose moodle-cs leg cannot read attributes.
 *
 * @covers \block_feedback_tracker\local\sla\slot_ledger
 * @covers \block_feedback_tracker\local\sla\observer
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 */
final class slot_ledger_test extends \advanced_testcase {
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
     * Allocating markers through core writes one observed row per allocated
     * marker, in core's order, never optional.
     *
     * @return void
     */
    public function test_allocation_creates_observed_rows_with_positions(): void {
        $before = time();
        $env = $this->build_environment('average');
        $after = time();

        $slots = $this->slots($env);
        $this->assertCount(2, $slots);
        $this->assertSame([1, 2], array_map(fn($s) => (int) $s->position, array_values($slots)));
        $this->assertSame(
            [(int) $env->markers[0]->id, (int) $env->markers[1]->id],
            array_map(fn($s) => (int) $s->marker, array_values($slots))
        );
        foreach ($slots as $s) {
            $this->assertSame(0, (int) $s->optional);
            $this->assertSame(slot_ledger::SOURCE_OBSERVED, $s->allocsource);
            $this->assertGreaterThanOrEqual($before, (int) $s->timeallocated);
            $this->assertLessThanOrEqual($after, (int) $s->timeallocated);
            $this->assertNull($s->timemarked);
            $this->assertNull($s->timeremoved);
            $this->assertSame((int) $this->row($env)->id, (int) $s->subid);
        }
    }

    /**
     * A removal through core fires nothing for the removed marker, and the
     * event for the marker who stays closes nothing: the observer only adds.
     * The reconcile sweep closes it (reconcile_slots_test).
     *
     * @return void
     */
    public function test_removal_through_core_leaves_the_slot_live(): void {
        $env = $this->build_environment('average');
        [$first, $second] = $env->markers;

        $env->assign->update_allocated_markers((int) $env->student->id, [(int) $first->id]);

        $slots = $this->slots_by_marker($env);
        $this->assertNull($slots[(int) $second->id]->timeremoved, 'The observer never closes a slot.');
        $this->assertCount(2, $slots, 'Control: no row was added or dropped.');
    }

    /**
     * The same markers saved again (core deletes every allocation row and
     * inserts them anew) keep their clocks: a live row is left as it is.
     *
     * @return void
     */
    public function test_saving_the_same_markers_again_keeps_the_clocks(): void {
        global $DB;
        $env = $this->build_environment('average');
        $pinned = time() - 5000;
        $DB->set_field('block_feedback_tracker_alloc', 'timeallocated', $pinned, ['userid' => $env->student->id]);

        $env->assign->update_allocated_markers(
            (int) $env->student->id,
            [(int) $env->markers[0]->id, (int) $env->markers[1]->id]
        );

        foreach ($this->slots($env) as $s) {
            $this->assertSame($pinned, (int) $s->timeallocated);
            $this->assertSame(slot_ledger::SOURCE_OBSERVED, $s->allocsource);
        }
    }

    /**
     * Reversing the order in a later save moves the positions and nothing else.
     *
     * @return void
     */
    public function test_reordering_updates_positions(): void {
        $env = $this->build_environment('average');
        [$first, $second] = $env->markers;
        $before = $this->slots_by_marker($env);

        $env->assign->update_allocated_markers((int) $env->student->id, [(int) $second->id, (int) $first->id]);

        $after = $this->slots_by_marker($env);
        $this->assertSame(1, (int) $after[(int) $second->id]->position);
        $this->assertSame(2, (int) $after[(int) $first->id]->position);
        $this->assertSame($before[(int) $first->id]->timeallocated, $after[(int) $first->id]->timeallocated);
    }

    /**
     * A marker allocated again after the sweep recorded their removal starts a
     * new clock, and a completion recorded before the removal no longer stands.
     *
     * @return void
     */
    public function test_reallocation_revives_with_a_new_clock(): void {
        global $DB;
        $env = $this->build_environment('average');
        [$first, $marker] = $env->markers;
        $env->assign->update_allocated_markers((int) $env->student->id, [(int) $first->id]);
        slot_ledger::sync_for_user((int) $env->cm->id, (int) $env->student->id, time(), slot_ledger::SOURCE_RECONCILED);
        $slot = $this->slots_by_marker($env)[(int) $marker->id];
        $this->assertNotNull($slot->timeremoved, 'Control: the sweep-side sync recorded the removal.');
        // Push the history back, so the re-allocation is clearly a later instant.
        $DB->update_record('block_feedback_tracker_alloc', (object) [
            'id' => $slot->id,
            'timeallocated' => (int) $slot->timeallocated - 5000,
            'timemarked' => (int) $slot->timeallocated - 4000,
            'markhours' => 3.0,
            'markdays' => 1,
            'timeremoved' => (int) $slot->timeremoved - 3000,
        ]);

        $before = time();
        $env->assign->update_allocated_markers((int) $env->student->id, [(int) $first->id, (int) $marker->id]);

        $revived = $this->slots_by_marker($env)[(int) $marker->id];
        $this->assertSame((int) $slot->id, (int) $revived->id, 'The same row is revived, not a second one.');
        $this->assertNull($revived->timeremoved);
        $this->assertGreaterThanOrEqual($before, (int) $revived->timeallocated);
        $this->assertNull($revived->timemarked);
        $this->assertNull($revived->markhours);
        $this->assertNull($revived->markdays);
        $this->assertSame(slot_ledger::SOURCE_OBSERVED, $revived->allocsource);
    }

    /**
     * A removal and re-allocation in the same second are one save re-inserting
     * the marker: only the removal is undone and the clock keeps running. A
     * later re-allocation restarts it.
     *
     * @return void
     */
    public function test_same_second_move_keeps_the_clock(): void {
        global $DB;
        $env = $this->build_environment('average');
        $cmid = (int) $env->cm->id;
        $studentid = (int) $env->student->id;
        $markerid = (int) $env->markers[1]->id;
        $allocation = $DB->get_record('assign_allocated_marker', [
            'assignment' => $env->assign->get_instance()->id,
            'marker' => $markerid,
        ], '*', MUST_EXIST);
        $original = (int) $this->slots_by_marker($env)[$markerid]->timeallocated;
        $w = time() + 100;

        $DB->delete_records('assign_allocated_marker', ['id' => $allocation->id]);
        slot_ledger::sync_for_user($cmid, $studentid, $w, slot_ledger::SOURCE_RECONCILED);
        $this->assertSame($w, (int) $this->slots_by_marker($env)[$markerid]->timeremoved);

        unset($allocation->id);
        $allocation->id = $DB->insert_record('assign_allocated_marker', $allocation);
        slot_ledger::sync_for_user($cmid, $studentid, $w, slot_ledger::SOURCE_RECONCILED);
        $slot = $this->slots_by_marker($env)[$markerid];
        $this->assertNull($slot->timeremoved);
        $this->assertSame($original, (int) $slot->timeallocated, 'Same second: the clock keeps running.');
        $this->assertSame(slot_ledger::SOURCE_OBSERVED, $slot->allocsource);

        $DB->delete_records('assign_allocated_marker', ['id' => $allocation->id]);
        slot_ledger::sync_for_user($cmid, $studentid, $w + 10, slot_ledger::SOURCE_RECONCILED);
        unset($allocation->id);
        $DB->insert_record('assign_allocated_marker', $allocation);
        slot_ledger::sync_for_user($cmid, $studentid, $w + 20, slot_ledger::SOURCE_RECONCILED);
        $slot = $this->slots_by_marker($env)[$markerid];
        $this->assertSame($w + 20, (int) $slot->timeallocated, 'A later re-allocation restarts the clock.');
        $this->assertSame(slot_ledger::SOURCE_RECONCILED, $slot->allocsource);
    }

    /**
     * Without $closeabsent a sync adds and never closes, which is what the
     * observer needs between two inserts of one save; with it, a marker no
     * longer allocated is closed.
     *
     * @return void
     */
    public function test_sync_closes_only_when_asked(): void {
        global $DB;
        $env = $this->build_environment('average');
        $markerid = (int) $env->markers[1]->id;
        $DB->delete_records('assign_allocated_marker', [
            'assignment' => $env->assign->get_instance()->id,
            'marker' => $markerid,
        ]);
        $cmid = (int) $env->cm->id;
        $studentid = (int) $env->student->id;

        $this->assertSame(0, slot_ledger::sync_for_user($cmid, $studentid, time(), slot_ledger::SOURCE_OBSERVED, false));
        $this->assertNull($this->slots_by_marker($env)[$markerid]->timeremoved);

        $this->assertSame(1, slot_ledger::sync_for_user($cmid, $studentid, time(), slot_ledger::SOURCE_RECONCILED));
        $this->assertNotNull($this->slots_by_marker($env)[$markerid]->timeremoved);
    }

    /**
     * Moodle 5.2 fires no event when a marker completes, so a completed mark
     * writes nothing until the sweep finds it (reconcile_slots_test).
     *
     * @return void
     */
    public function test_a_completed_mark_writes_nothing_by_itself(): void {
        $env = $this->build_environment('manual');
        $this->mark($env, $env->markers[0], 60, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);

        foreach ($this->slots($env) as $s) {
            $this->assertNull($s->timemarked);
        }
    }

    /**
     * The first completion wins: a second stamp on the same slot changes
     * nothing.
     *
     * @return void
     */
    public function test_first_completion_wins(): void {
        $env = $this->build_environment('average');
        $slot = $this->slots_by_marker($env)[(int) $env->markers[0]->id];
        $first = time() + 100;

        $this->assertTrue(slot_ledger::complete_slot((int) $slot->id, $first));
        $this->assertFalse(slot_ledger::complete_slot((int) $slot->id, $first + 500));

        $this->assertSame($first, (int) $this->slots_by_marker($env)[(int) $env->markers[0]->id]->timemarked);
    }

    /**
     * The marker's turnaround runs from the allocation to the completion in
     * effective hours and business days, each counted on its own.
     *
     * Tuesday 10:00 to Thursday 15:00 under Mon-Fri 08:00-18:00: 8 + 10 + 7
     * hours, two day boundaries.
     *
     * @return void
     */
    public function test_completion_measures_hours_and_days(): void {
        global $DB;
        $env = $this->build_environment('average', $this->ts('2026-05-11 09:00:00'));
        $this->seed_calendar();
        $markerid = (int) $env->markers[0]->id;
        $slot = $this->slots_by_marker($env)[$markerid];
        $DB->set_field('block_feedback_tracker_alloc', 'timeallocated', $this->ts('2026-05-12 10:00:00'), ['id' => $slot->id]);

        $this->assertTrue(slot_ledger::complete_slot((int) $slot->id, $this->ts('2026-05-14 15:00:00')));

        $slot = $this->slots_by_marker($env)[$markerid];
        $this->assertSame($this->ts('2026-05-14 15:00:00'), (int) $slot->timemarked);
        $this->assertEqualsWithDelta(25.0, (float) $slot->markhours, 0.001);
        $this->assertSame(2, (int) $slot->markdays);
        $this->assertNull($this->slots_by_marker($env)[(int) $env->markers[1]->id]->timemarked);
    }

    /**
     * A marker allocated before the hand-in is measured from the hand-in.
     *
     * Allocated Friday 10:00, handed in Monday 09:00, completed Monday 15:00:
     * six working hours, no day boundary.
     *
     * @return void
     */
    public function test_turnaround_starts_no_earlier_than_the_hand_in(): void {
        global $DB;
        $env = $this->build_environment('average', $this->ts('2026-05-11 09:00:00'));
        $this->seed_calendar();
        $slot = $this->slots_by_marker($env)[(int) $env->markers[0]->id];
        $DB->set_field('block_feedback_tracker_alloc', 'timeallocated', $this->ts('2026-05-08 10:00:00'), ['id' => $slot->id]);

        $this->assertTrue(slot_ledger::complete_slot((int) $slot->id, $this->ts('2026-05-11 15:00:00')));

        $slot = $this->slots_by_marker($env)[(int) $env->markers[0]->id];
        $this->assertEqualsWithDelta(6.0, (float) $slot->markhours, 0.001);
        $this->assertSame(0, (int) $slot->markdays);
    }

    /**
     * A completion at or before the slot's start measures nothing: null, never
     * zero, which would read as a flawless turnaround.
     *
     * @return void
     */
    public function test_completion_before_the_allocation_measures_nothing(): void {
        global $DB;
        $env = $this->build_environment('average', $this->ts('2026-05-11 09:00:00'));
        $slot = $this->slots_by_marker($env)[(int) $env->markers[0]->id];
        $DB->set_field('block_feedback_tracker_alloc', 'timeallocated', $this->ts('2026-05-13 10:00:00'), ['id' => $slot->id]);

        $this->assertTrue(slot_ledger::complete_slot((int) $slot->id, $this->ts('2026-05-12 10:00:00')));

        $slot = $this->slots_by_marker($env)[(int) $env->markers[0]->id];
        $this->assertNotNull($slot->timemarked);
        $this->assertNull($slot->markhours);
        $this->assertNull($slot->markdays);
    }

    /**
     * Work resubmitted after a mark opens a new cycle, which inherits the
     * markers already allocated at its hand-in instant; the closed cycle keeps
     * its own rows.
     *
     * @return void
     */
    public function test_new_cycle_carries_allocated_markers_at_its_hand_in(): void {
        global $DB;
        $env = $this->build_environment('average');
        $this->mark($env, $env->markers[0], 60, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $this->mark($env, $env->markers[1], 80, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $closed = $this->row($env);
        $this->assertNotNull($closed->timemarked, 'Control: the agreed grade closed the first cycle.');

        $resubmitted = time() + 600;
        $DB->set_field('assign_submission', 'timemodified', $resubmitted, [
            'assignment' => $env->assign->get_instance()->id,
            'userid' => $env->student->id,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $env->cm->id, (int) $env->student->id, 0);

        $current = $this->row($env);
        $this->assertSame(1, (int) $current->cycle, 'Control: a new cycle opened.');
        $this->assertSame($resubmitted, (int) $current->timesubmitted);
        $slots = $this->slots($env);
        $this->assertCount(2, $slots);
        foreach ($slots as $s) {
            $this->assertSame(slot_ledger::SOURCE_CARRIED, $s->allocsource);
            $this->assertSame($resubmitted, (int) $s->timeallocated);
            $this->assertNull($s->timemarked);
        }
        $this->assertCount(
            2,
            $DB->get_records('block_feedback_tracker_alloc', ['subid' => $closed->id]),
            'The closed cycle keeps its own slot rows.'
        );
    }

    /**
     * A first cycle written at its hand-in carries the markers allocated
     * before it at the hand-in instant; one written long after (a backfill)
     * cannot know when they were allocated, so it records them as discovered.
     *
     * @return void
     */
    public function test_carried_only_when_written_at_the_hand_in(): void {
        global $DB;
        $env = $this->build_environment('average');
        $live = time() - 60;
        $late = time() - 3 * DAYSECS;
        $rows = [];
        foreach (['live' => $live, 'late' => $late] as $key => $handin) {
            $student = $this->getDataGenerator()->create_and_enrol($env->course, 'student');
            $env->assign->update_allocated_markers(
                (int) $student->id,
                [(int) $env->markers[0]->id, (int) $env->markers[1]->id]
            );
            $this->assertSame(0, $DB->count_records('block_feedback_tracker_alloc', ['userid' => $student->id]));
            $DB->delete_records('assign_submission', [
                'assignment' => $env->assign->get_instance()->id,
                'userid' => $student->id,
            ]);
            $DB->insert_record('assign_submission', (object) [
                'assignment' => $env->assign->get_instance()->id, 'userid' => $student->id, 'attemptnumber' => 0,
                'timecreated' => $handin, 'timemodified' => $handin,
                'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
            ]);
            $before = time();
            submission_ledger::upsert_for_cm_user_attempt((int) $env->cm->id, (int) $student->id, 0);
            $rows[$key] = [$DB->get_records('block_feedback_tracker_alloc', ['userid' => $student->id]), $before];
        }

        [$liveslots] = $rows['live'];
        $this->assertCount(2, $liveslots);
        foreach ($liveslots as $s) {
            $this->assertSame(slot_ledger::SOURCE_CARRIED, $s->allocsource);
            $this->assertSame($live, (int) $s->timeallocated);
        }
        [$lateslots, $before] = $rows['late'];
        $this->assertCount(2, $lateslots);
        foreach ($lateslots as $s) {
            $this->assertSame(slot_ledger::SOURCE_RECONCILED, $s->allocsource);
            $this->assertGreaterThanOrEqual($before, (int) $s->timeallocated);
        }
    }

    /**
     * An activity that does not use multiple marking writes no slot row.
     *
     * @return void
     */
    public function test_single_marker_activity_writes_nothing(): void {
        global $DB;
        $env = $this->build_environment('average', null, 1);
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_alloc'));
        $this->assertSame(
            (int) $env->markers[0]->id,
            (int) $this->row($env)->allocmarkerid,
            'Control: the allocation itself was seen by the ledger.'
        );
    }

    /**
     * A course without the block is not processed: no slot rows.
     *
     * @return void
     */
    public function test_unprocessable_course_writes_nothing(): void {
        global $DB;
        $env = $this->build_environment('average');
        $before = $DB->count_records('block_feedback_tracker_alloc');
        $DB->delete_records('block_instances', ['blockname' => 'feedback_tracker']);
        course_access::reset_memo();

        $extra = $this->create_marker($env);
        $env->assign->update_allocated_markers(
            (int) $env->student->id,
            [(int) $env->markers[0]->id, (int) $extra->id]
        );

        $this->assertSame($before, $DB->count_records('block_feedback_tracker_alloc'));
    }

    /**
     * Build a processable course with a multi-marker assign, one submitted
     * student and the markers allocated after the ledger row exists.
     *
     * Core's generator leaves markercount at 0, so it is always set here.
     *
     * @param string $method The multimarkmethod.
     * @param int|null $submitted Hand-in instant; three days ago when null.
     * @param int $required The activity's markercount, and the markers allocated.
     * @return \stdClass course, cm, assign, student and markers.
     */
    private function build_environment(string $method, ?int $submitted = null, int $required = 2): \stdClass {
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

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $inst = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'markingworkflow' => 1,
            'markingallocation' => 1,
            'markercount' => $required,
            'multimarkmethod' => $method,
            'grade' => 100,
        ]);
        $cm = get_coursemodule_from_instance('assign', $inst->id);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);

        $t1 = $submitted ?? time() - 3 * DAYSECS;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $inst->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $env = (object) ['course' => $course, 'cm' => $cm, 'assign' => $assign, 'student' => $student];
        $env->markers = [];
        for ($pos = 1; $pos <= $required; $pos++) {
            $env->markers[] = $this->create_marker($env);
        }
        $assign->update_allocated_markers((int) $student->id, array_map(fn($m) => (int) $m->id, $env->markers));
        return $env;
    }

    /**
     * Create a teacher in the environment's course.
     *
     * @param \stdClass $env
     * @return \stdClass The user record.
     */
    private function create_marker(\stdClass $env): \stdClass {
        return $this->getDataGenerator()->create_and_enrol($env->course, 'editingteacher');
    }

    /**
     * Save one marker's mark in a workflow state, as their grading form does.
     *
     * @param \stdClass $env
     * @param \stdClass $marker
     * @param float $mark
     * @param string $state The marker's own workflow state.
     * @return void
     */
    private function mark(\stdClass $env, \stdClass $marker, float $mark, string $state): void {
        $grade = $env->assign->get_user_grade((int) $env->student->id, true);
        $grade->grader = (int) $marker->id;
        $env->assign->update_mark($grade, $mark, $state);
    }

    /**
     * The student's current ledger row.
     *
     * @param \stdClass $env
     * @return \stdClass
     */
    private function row(\stdClass $env): \stdClass {
        global $DB;
        return $DB->get_record(
            'block_feedback_tracker_sub',
            ['cmid' => $env->cm->id, 'userid' => $env->student->id, 'iscurrent' => 1],
            '*',
            MUST_EXIST
        );
    }

    /**
     * The slot rows of the student's current cycle, in id order.
     *
     * @param \stdClass $env
     * @return array
     */
    private function slots(\stdClass $env): array {
        global $DB;
        return $DB->get_records('block_feedback_tracker_alloc', ['subid' => $this->row($env)->id], 'id ASC');
    }

    /**
     * The slot rows of the student's current cycle, keyed by marker id.
     *
     * @param \stdClass $env
     * @return array
     */
    private function slots_by_marker(\stdClass $env): array {
        $out = [];
        foreach ($this->slots($env) as $s) {
            $out[(int) $s->marker] = $s;
        }
        return $out;
    }

    /**
     * Mon-Fri 08:00-18:00 UTC working hours, set by the test itself.
     *
     * @return void
     */
    private function seed_calendar(): void {
        global $DB;
        // A fresh calendar version: days the fixture's own writes measured stay cached under the old one.
        calendar::bump_version();
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        $DB->delete_records('block_feedback_tracker_chours');
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
     * Parse a datetime string as UTC.
     *
     * @param string $datetime
     * @return int
     */
    private function ts(string $datetime): int {
        return (new \DateTime($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
