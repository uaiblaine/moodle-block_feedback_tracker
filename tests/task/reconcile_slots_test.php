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

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\calendar\calendar;
use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\group_resolver;
use block_feedback_tracker\local\sla\slot_ledger;
use block_feedback_tracker\local\sla\submission_ledger;
use block_feedback_tracker\local\sla\submission_status;

/**
 * The slot sweep on Moodle 5.2 repairs what no event reports: (a) an
 * allocation with no live slot row, (b) a live slot row whose marker is no
 * longer allocated, which is every removal on 5.2, (c) a complete mark whose
 * slot row has no completion, which is every completion on 5.2. Removals and
 * completions go through core's own API (update_allocated_markers(),
 * update_mark()), which fires nothing the slot table could follow; a lost
 * allocation event is produced by deleting the slot rows.
 *
 * The class-level covers docblock is kept, not attributes: this branch family
 * still supports Moodle 4.5, whose moodle-cs leg cannot read attributes.
 *
 * @covers \block_feedback_tracker\task\reconcile_ledger
 * @covers \block_feedback_tracker\local\sla\slot_ledger
 */
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
     * (b) A marker removed through core, which fires nothing for them, loses
     * their live row at the discovery instant; the marker who stays keeps it.
     *
     * @return void
     */
    public function test_a_removal_is_closed_by_the_sweep(): void {
        global $DB;
        $env = $this->build_environment(1);
        $student = $env->students[0];
        [$stays, $gone] = $env->markers;
        $env->assign->update_allocated_markers((int) $student->id, [(int) $stays->id]);
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_alloc', 'timeremoved', ['userid' => $student->id, 'marker' => $gone->id]),
            'Control: nothing closed the slot before the sweep.'
        );

        $before = time();
        $this->assertSame(1, $this->sweep($env, 500));

        $removed = $DB->get_record('block_feedback_tracker_alloc', ['userid' => $student->id, 'marker' => $gone->id]);
        $this->assertGreaterThanOrEqual($before, (int) $removed->timeremoved);
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_alloc', 'timeremoved', ['userid' => $student->id, 'marker' => $stays->id]),
            'Control: the marker still allocated keeps the slot.'
        );
        $this->assertSame(0, $this->sweep($env, 500), 'Converged: a closed slot is not missing.');
    }

    /**
     * (b) Removing every marker fires no event at all; the sweep closes every
     * slot.
     *
     * @return void
     */
    public function test_removing_every_marker_is_closed_by_the_sweep(): void {
        global $DB;
        $env = $this->build_environment(1);
        $student = $env->students[0];
        $env->assign->update_allocated_markers((int) $student->id, []);

        $this->sweep($env, 500);

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_alloc', ['userid' => $student->id, 'timeremoved' => null]));
        $this->assertSame(2, $DB->count_records('block_feedback_tracker_alloc', ['userid' => $student->id]));
    }

    /**
     * (c) A mark completed through core, which fires nothing on 5.2, is
     * stamped at the mark row's last change; a mark still in marking is not.
     *
     * @return void
     */
    public function test_a_completion_is_stamped_from_the_mark_timemodified(): void {
        global $DB;
        $env = $this->build_environment(1);
        $student = $env->students[0];
        $marker = $env->markers[0];
        $env->assign->get_instance()->multimarkmethod = 'manual';
        $DB->set_field('assign', 'multimarkmethod', 'manual', ['id' => $env->assign->get_instance()->id]);
        $this->mark($env, $student, $marker, 60, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $this->mark($env, $student, $env->markers[1], 70, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);
        $this->assertSame(
            0,
            $DB->count_records_select(
                'block_feedback_tracker_alloc',
                'userid = :u AND timemarked IS NOT NULL',
                ['u' => $student->id]
            ),
            'Control: no event recorded the completion.'
        );
        $markedat = (int) $DB->get_field('assign_mark', 'timemodified', ['marker' => $marker->id], MUST_EXIST) - 50;
        $DB->set_field('assign_mark', 'timemodified', $markedat, ['marker' => $marker->id]);

        $this->assertSame(1, $this->sweep($env, 500));

        $done = $DB->get_record('block_feedback_tracker_alloc', ['userid' => $student->id, 'marker' => $marker->id]);
        $this->assertSame($markedat, (int) $done->timemarked);
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_alloc', 'timemarked', [
                'userid' => $student->id,
                'marker' => $env->markers[1]->id,
            ]),
            'Control: a mark still in marking is not a completion.'
        );
        $this->assertSame(0, $this->sweep($env, 500), 'Converged: the first completion stands.');
    }

    /**
     * A completed mark with no value (a workflow state saved without a mark)
     * is not complete.
     *
     * @return void
     */
    public function test_a_completed_state_without_a_mark_is_not_stamped(): void {
        global $DB;
        $env = $this->build_environment(1);
        $student = $env->students[0];
        $marker = $env->markers[0];
        $this->mark($env, $student, $marker, 60, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $DB->set_field('assign_mark', 'mark', null, ['marker' => $marker->id]);

        $this->sweep($env, 500);
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_alloc', 'timemarked', ['userid' => $student->id, 'marker' => $marker->id])
        );

        $DB->set_field('assign_mark', 'mark', 55, ['marker' => $marker->id]);
        $this->sweep($env, 500);
        $this->assertNotNull(
            $DB->get_field('block_feedback_tracker_alloc', 'timemarked', ['userid' => $student->id, 'marker' => $marker->id]),
            'Control: the same row with a mark is complete.'
        );
    }

    /**
     * The sweep measures the marker's turnaround from the later of the
     * allocation and the hand-in to the mark's last change, in effective hours
     * and business days.
     *
     * Handed in Monday 09:00, allocated Tuesday 10:00, completed Thursday
     * 15:00 under Mon-Fri 08:00-18:00: 8 + 10 + 7 hours, two day boundaries.
     *
     * @return void
     */
    public function test_a_stamped_completion_measures_hours_and_days(): void {
        global $DB;
        $env = $this->build_environment(1);
        $student = $env->students[0];
        $marker = $env->markers[0];
        $this->seed_calendar();
        $this->mark($env, $student, $marker, 60, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $DB->set_field('block_feedback_tracker_sub', 'timesubmitted', $this->ts('2026-05-11 09:00:00'), ['userid' => $student->id]);
        $DB->set_field('block_feedback_tracker_alloc', 'timeallocated', $this->ts('2026-05-12 10:00:00'), [
            'userid' => $student->id,
            'marker' => $marker->id,
        ]);
        $DB->set_field('assign_mark', 'timemodified', $this->ts('2026-05-14 15:00:00'), ['marker' => $marker->id]);

        $this->sweep($env, 500);

        $slot = $DB->get_record('block_feedback_tracker_alloc', ['userid' => $student->id, 'marker' => $marker->id]);
        $this->assertSame($this->ts('2026-05-14 15:00:00'), (int) $slot->timemarked);
        $this->assertEqualsWithDelta(25.0, (float) $slot->markhours, 0.001);
        $this->assertSame(2, (int) $slot->markdays);
    }

    /**
     * A mark last edited before the cycle's hand-in belongs to an earlier
     * cycle of the attempt, so it does not complete this cycle's slot; the
     * marker's next edit does.
     *
     * @return void
     */
    public function test_a_mark_older_than_the_hand_in_is_not_this_cycles_completion(): void {
        global $DB;
        $env = $this->build_environment(1);
        $student = $env->students[0];
        $marker = $env->markers[0];
        $this->mark($env, $student, $marker, 60, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $handin = (int) $DB->get_field('block_feedback_tracker_sub', 'timesubmitted', ['userid' => $student->id]);
        $DB->set_field('assign_mark', 'timemodified', $handin - 10, ['marker' => $marker->id]);

        $this->sweep($env, 500);
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_alloc', 'timemarked', ['userid' => $student->id, 'marker' => $marker->id])
        );

        $DB->set_field('assign_mark', 'timemodified', $handin, ['marker' => $marker->id]);
        $this->sweep($env, 500);
        $this->assertSame(
            $handin,
            (int) $DB->get_field('block_feedback_tracker_alloc', 'timemarked', ['userid' => $student->id, 'marker' => $marker->id]),
            'Control: an edit at the hand-in or later completes it.'
        );
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
     * @return \stdClass course, cm, assign, students and markers.
     */
    private function build_environment(int $students): \stdClass {
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
            $assign->update_allocated_markers((int) $student->id, [(int) $env->markers[0]->id, (int) $env->markers[1]->id]);
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
