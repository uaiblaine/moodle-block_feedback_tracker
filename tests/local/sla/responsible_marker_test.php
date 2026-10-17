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
 * Tests for the responsible marker of a multi-marking cycle (owner decision D1).
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
 * The responsible marker on Moodle 5.2, driven through mod_assign's own API.
 *
 * Allocations go through assign::update_allocated_markers(), marks through
 * assign::update_mark() and a manual final grade through assign::update_grade()
 * with the confirming teacher as grader, as apply_grade_to_user() saves it, so
 * the events are the ones core fires. Every hours figure runs from an instant
 * the test pins seven days back to the grading, which is the current time:
 * the width rule in CLAUDE.md, with the working hours set by the test.
 *
 * Moodle 5.2 keeps no allocation instant and this branch has no per-slot
 * table, so the only marker clock on record is the stamp of the provisional
 * marker in position 1; a responsible person whose start nobody recorded gets
 * a null turnaround.
 *
 * The class-level covers docblock is kept, not attributes: this branch family
 * still supports Moodle 4.5, whose moodle-cs leg cannot read attributes.
 *
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 */
final class responsible_marker_test extends \advanced_testcase {
    /** Exactly one week: worth 50.0 effective hours under Mon-Fri 08:00-18:00. */
    private const WEEK = 7 * 86400;

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
     * While the cycle is pending the provisional marker is position 1, not the
     * lowest user id: re-allocating with a second marker of lower id moves
     * neither the marker nor their clock.
     *
     * @return void
     */
    public function test_pending_marker_is_position_one(): void {
        $env = $this->build_environment('average', false);
        $lower = $this->create_marker($env);
        $higher = $this->create_marker($env);
        $this->assertLessThan((int) $higher->id, (int) $lower->id, 'Control: the second marker has the lower id.');
        $this->seed_calendar();

        $env->assign->update_allocated_markers((int) $env->student->id, [(int) $higher->id]);
        $this->assertSame((int) $higher->id, (int) $this->row($env)->allocmarkerid);
        $start = $this->pin_marker_clock($env);
        $this->rederive($env);
        $this->assertEqualsWithDelta(50.0, (float) $this->row($env)->allochours, 0.01, 'Control: the clock runs.');

        $env->assign->update_allocated_markers((int) $env->student->id, [(int) $higher->id, (int) $lower->id]);

        $row = $this->row($env);
        $this->assertSame((int) $higher->id, (int) $row->allocmarkerid);
        $this->assertSame($start, (int) $row->timeallocmarker);
        $this->assertEqualsWithDelta(50.0, (float) $row->allochours, 0.01);
        $this->assertNull($row->allocattrib);
    }

    /**
     * Manual method: the teacher who saves the final grade is responsible,
     * though they are not allocated, from the last counted mark; a later
     * feedback-only save by someone else moves nothing.
     *
     * @return void
     */
    public function test_manual_confirmer_is_responsible_from_the_last_mark(): void {
        global $DB;
        $env = $this->build_environment('manual');
        [$first, $second] = $env->markers;
        $this->mark($env, $first, 60);
        $this->mark($env, $second, 80);
        $gradeid = (int) $this->grade($env)->id;
        $last = time() - self::WEEK;
        $DB->set_field('assign_mark', 'timemodified', $last - 2 * DAYSECS, ['gradeid' => $gradeid, 'marker' => $first->id]);
        $DB->set_field('assign_mark', 'timemodified', $last, ['gradeid' => $gradeid, 'marker' => $second->id]);
        $this->seed_calendar();
        $this->rederive($env);
        $before = $this->row($env);
        $this->assertNull($before->timegraded, 'Control: manual waits for the final grade.');
        $this->assertNull($before->allocattrib, 'Control: nothing is decided while pending.');

        $confirmer = $this->create_marker($env);
        $this->save_grade($env, $confirmer, 75.0);

        $row = $this->row($env);
        $this->assertNotNull($row->timegraded, 'Control: the final grade closed the cycle.');
        $this->assertSame(submission_ledger::ATTRIB_CONFIRMER, $row->allocattrib);
        $this->assertSame((int) $confirmer->id, (int) $row->allocmarkerid);
        $this->assertSame($last, (int) $row->timeallocmarker);
        $this->assertEqualsWithDelta(50.0, (float) $row->allochours, 0.01);
        $this->assertSame($before->timeallocated, $row->timeallocated);
        $this->assertSame($before->queuehours, $row->queuehours);

        $other = $this->create_marker($env);
        $this->save_grade($env, $other, 75.0);
        $this->assertSame(
            (int) $other->id,
            (int) $DB->get_field('assign_grades', 'grader', ['id' => $gradeid]),
            'Control: the later save overwrote the grader.'
        );

        $after = $this->row($env);
        $this->assertSame(submission_ledger::ATTRIB_CONFIRMER, $after->allocattrib);
        $this->assertSame((int) $confirmer->id, (int) $after->allocmarkerid);
        $this->assertSame($last, (int) $after->timeallocmarker);
    }

    /**
     * Manual method with no grader on the final grade (a restore stores 0):
     * position 1, labelled as a fallback, keeping that marker's own clock.
     *
     * @return void
     */
    public function test_manual_without_grader_falls_back_to_position_one(): void {
        $env = $this->build_environment('manual');
        [$first, $second] = $env->markers;
        $this->mark($env, $first, 60);
        $this->mark($env, $second, 80);
        $before = $this->row($env);
        $this->assertSame((int) $first->id, (int) $before->allocmarkerid, 'Control: position 1 is provisional.');
        $this->assertNotNull($before->timeallocmarker);

        $this->save_grade($env, null, 75.0);

        $row = $this->row($env);
        $this->assertSame(submission_ledger::ATTRIB_FALLBACK, $row->allocattrib);
        $this->assertSame((int) $first->id, (int) $row->allocmarkerid);
        $this->assertSame($before->timeallocmarker, $row->timeallocmarker);
    }

    /**
     * Maximum method: the marker of the higher mark is responsible though they
     * sit in position 2. Nobody recorded when their responsibility began, so
     * their turnaround is unmeasurable rather than borrowed from position 1.
     *
     * @return void
     */
    public function test_maximum_higher_mark_wins(): void {
        $env = $this->build_environment('maximum');
        [$first, $second] = $env->markers;
        $this->pin_marker_clock($env);
        $before = $this->row($env);

        $this->mark($env, $first, 60);
        $this->mark($env, $second, 80);

        $row = $this->row($env);
        $this->assertNotNull($row->timegraded, 'Control: the agreed grade closed the cycle.');
        $this->assertSame(submission_ledger::ATTRIB_MAXMARK, $row->allocattrib);
        $this->assertSame((int) $second->id, (int) $row->allocmarkerid);
        $this->assertNull($row->timeallocmarker);
        $this->assertNull($row->allochours);
        $this->assertSame($before->timeallocated, $row->timeallocated);
    }

    /**
     * Maximum method: the winner in position 1 is the provisional marker, so
     * their recorded clock carries over and the turnaround is measured.
     *
     * @return void
     */
    public function test_maximum_winner_in_position_one_keeps_the_clock(): void {
        $env = $this->build_environment('maximum');
        [$first, $second] = $env->markers;
        $this->seed_calendar();
        $start = $this->pin_marker_clock($env);

        $this->mark($env, $first, 80);
        $this->mark($env, $second, 60);

        $row = $this->row($env);
        $this->assertSame(submission_ledger::ATTRIB_MAXMARK, $row->allocattrib);
        $this->assertSame((int) $first->id, (int) $row->allocmarkerid);
        $this->assertSame($start, (int) $row->timeallocmarker);
        $this->assertEqualsWithDelta(50.0, (float) $row->allochours, 0.01);
    }

    /**
     * Maximum method: equal marks go to the lower position, whatever the ids.
     *
     * @return void
     */
    public function test_maximum_tie_goes_to_position_one(): void {
        $env = $this->build_environment('maximum', false);
        $lower = $this->create_marker($env);
        $higher = $this->create_marker($env);
        $env->assign->update_allocated_markers((int) $env->student->id, [(int) $higher->id, (int) $lower->id]);

        $this->mark($env, $higher, 80);
        $this->mark($env, $lower, 80);

        $row = $this->row($env);
        $this->assertSame(submission_ledger::ATTRIB_MAXMARK, $row->allocattrib);
        $this->assertSame((int) $higher->id, (int) $row->allocmarkerid);
    }

    /**
     * Maximum method: a mark edited after the agreed grade makes core compute
     * a new maximum from the other marker, and the attribution stays with the
     * marker who produced the first one.
     *
     * @return void
     */
    public function test_maximum_attribution_survives_a_mark_edit(): void {
        $env = $this->build_environment('maximum');
        [$first, $second] = $env->markers;
        $this->mark($env, $first, 60);
        $this->mark($env, $second, 90);
        $before = $this->row($env);
        $this->assertSame((int) $second->id, (int) $before->allocmarkerid, 'Control: the higher mark won.');

        $this->mark($env, $second, 50);
        $this->assertEquals(60, $this->grade($env)->grade, 'Control: core recalculated the maximum.');

        $after = $this->row($env);
        $this->assertSame(submission_ledger::ATTRIB_MAXMARK, $after->allocattrib);
        $this->assertSame((int) $second->id, (int) $after->allocmarkerid);
        $this->assertSame($before->timegraded, $after->timegraded);
    }

    /**
     * Maximum method: replacing the winning marker after the agreed grade
     * changes nothing, neither on a re-derivation nor on a later allocation
     * event.
     *
     * @return void
     */
    public function test_maximum_attribution_survives_the_winner_replaced(): void {
        $env = $this->build_environment('maximum');
        [$first, $second] = $env->markers;
        $this->mark($env, $first, 60);
        $this->mark($env, $second, 90);
        $before = $this->row($env);
        $this->assertSame((int) $second->id, (int) $before->allocmarkerid, 'Control: the higher mark won.');

        $third = $this->create_marker($env);
        $env->assign->update_allocated_markers((int) $env->student->id, [(int) $third->id, (int) $first->id]);
        $this->rederive($env);

        $after = $this->row($env);
        $this->assertSame(submission_ledger::ATTRIB_MAXMARK, $after->allocattrib);
        $this->assertSame((int) $second->id, (int) $after->allocmarkerid);
        $this->assertSame($before->timeallocmarker, $after->timeallocmarker);
        $this->assertSame($before->allochours, $after->allochours);
    }

    /**
     * Average method: position 1, whoever gave the higher mark, keeping the
     * provisional marker's clock.
     *
     * @return void
     */
    public function test_average_is_position_one(): void {
        $env = $this->build_environment('average');
        [$first, $second] = $env->markers;
        $before = $this->row($env);

        $this->mark($env, $first, 60);
        $this->mark($env, $second, 80);

        $row = $this->row($env);
        $this->assertSame(submission_ledger::ATTRIB_POSITION, $row->allocattrib);
        $this->assertSame((int) $first->id, (int) $row->allocmarkerid);
        $this->assertSame($before->timeallocmarker, $row->timeallocmarker);
    }

    /**
     * A grade cleared by hand withdraws the agreed grade: the attribution is
     * cleared and the provisional marker in position 1 is back. Their own
     * clock was replaced when responsibility moved, so it is not invented.
     *
     * @return void
     */
    public function test_cleared_grade_returns_to_the_provisional_marker(): void {
        $env = $this->build_environment('maximum');
        [$first, $second] = $env->markers;
        $this->mark($env, $first, 60);
        $this->mark($env, $second, 80);
        $this->assertSame(submission_ledger::ATTRIB_MAXMARK, $this->row($env)->allocattrib, 'Control: attributed.');
        $this->assertSame((int) $second->id, (int) $this->row($env)->allocmarkerid);

        $this->save_grade($env, $this->create_marker($env), -1.0);

        $row = $this->row($env);
        $this->assertNull($row->timegraded, 'Control: the cycle reopened.');
        $this->assertNull($row->allocattrib);
        $this->assertSame((int) $first->id, (int) $row->allocmarkerid);
        $this->assertNull($row->timeallocmarker);
    }

    /**
     * When position 1 was the responsible marker, a cleared grade hands the
     * cycle back to them with the clock they had.
     *
     * @return void
     */
    public function test_cleared_grade_keeps_position_ones_clock(): void {
        $env = $this->build_environment('average');
        [$first, $second] = $env->markers;
        $before = $this->row($env);
        $this->mark($env, $first, 60);
        $this->mark($env, $second, 80);
        $this->assertSame(submission_ledger::ATTRIB_POSITION, $this->row($env)->allocattrib, 'Control: attributed.');

        $this->save_grade($env, $this->create_marker($env), -1.0);

        $row = $this->row($env);
        $this->assertNull($row->timegraded, 'Control: the cycle reopened.');
        $this->assertNull($row->allocattrib);
        $this->assertSame((int) $first->id, (int) $row->allocmarkerid);
        $this->assertSame($before->timeallocmarker, $row->timeallocmarker);
    }

    /**
     * A single-marker activity keeps the allocated marker and their clock when
     * someone else grades, and records no attribution.
     *
     * @return void
     */
    public function test_single_marker_activity_is_unchanged(): void {
        $env = $this->build_environment('average', true, 1);
        $before = $this->row($env);
        $this->assertSame((int) $env->markers[0]->id, (int) $before->allocmarkerid, 'Control: the allocation was seen.');

        $other = $this->create_marker($env);
        $this->save_grade($env, $other, 75.0);

        $row = $this->row($env);
        $this->assertNotNull($row->timegraded, 'Control: the grade closed the cycle.');
        $this->assertNull($row->allocattrib);
        $this->assertSame((int) $env->markers[0]->id, (int) $row->allocmarkerid);
        $this->assertSame($before->timeallocmarker, $row->timeallocmarker);
    }

    /**
     * Build a processable course with a multi-marker assign and one submitted
     * student whose ledger row exists before any allocation.
     *
     * Core's generator leaves markercount at 0, so it is always set here.
     *
     * @param string $method The multimarkmethod.
     * @param bool $allocate Whether to allocate a new marker per marker slot, in one call.
     * @param int $required The activity's markercount.
     * @return \stdClass course, cm, assign, student and markers.
     */
    private function build_environment(string $method, bool $allocate = true, int $required = 2): \stdClass {
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

        $t1 = time() - 3 * DAYSECS;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $inst->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $env = (object) ['course' => $course, 'cm' => $cm, 'assign' => $assign, 'student' => $student, 'markers' => []];
        if ($allocate) {
            for ($pos = 1; $pos <= $required; $pos++) {
                $env->markers[] = $this->create_marker($env);
            }
            $assign->update_allocated_markers((int) $student->id, array_map(fn($m) => (int) $m->id, $env->markers));
        }
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
     * Record one marker's completed mark, as the marker's own grading form does.
     *
     * @param \stdClass $env
     * @param \stdClass $marker
     * @param float $mark
     * @return void
     */
    private function mark(\stdClass $env, \stdClass $marker, float $mark): void {
        $grade = $env->assign->get_user_grade((int) $env->student->id, true);
        $grade->grader = (int) $marker->id;
        $env->assign->update_mark($grade, $mark, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
    }

    /**
     * Save the final grade as a teacher, the way apply_grade_to_user() ends:
     * grader set to the saving user, then update_grade().
     *
     * @param \stdClass $env
     * @param \stdClass|null $teacher The saving teacher; null stores grader 0, as a restore does.
     * @param float $value The grade; -1 clears it.
     * @return void
     */
    private function save_grade(\stdClass $env, ?\stdClass $teacher, float $value): void {
        $grade = $env->assign->get_user_grade((int) $env->student->id, true);
        $grade->grade = $value;
        $grade->grader = $teacher !== null ? (int) $teacher->id : 0;
        $this->assertTrue($env->assign->update_grade($grade));
    }

    /**
     * Move the row's first allocation and marker clock one week back, as if
     * position 1 had been allocated then, and return that instant.
     *
     * @param \stdClass $env
     * @return int The pinned instant.
     */
    private function pin_marker_clock(\stdClass $env): int {
        global $DB;
        $start = time() - self::WEEK;
        $DB->update_record('block_feedback_tracker_sub', (object) [
            'id' => $this->row($env)->id, 'timeallocated' => $start, 'timeallocmarker' => $start,
        ]);
        return $start;
    }

    /**
     * Re-derive the student's row from core, as any later event or sweep would.
     *
     * @param \stdClass $env
     * @return void
     */
    private function rederive(\stdClass $env): void {
        submission_ledger::upsert_for_cm_user_attempt((int) $env->cm->id, (int) $env->student->id, 0);
    }

    /**
     * The student's grade row for attempt 0.
     *
     * @param \stdClass $env
     * @return \stdClass
     */
    private function grade(\stdClass $env): \stdClass {
        return $env->assign->get_user_grade((int) $env->student->id, false);
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
}
