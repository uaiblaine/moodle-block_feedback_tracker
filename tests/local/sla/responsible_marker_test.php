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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The responsible marker on Moodle 5.3, driven through mod_assign's own API.
 *
 * Allocations go through assign::update_marker_allocations(), marks through
 * assign::update_mark() and a manual final grade through assign::update_grade()
 * with the confirming teacher as grader, as apply_grade_to_user() saves it, so
 * the events are the ones core fires. Every hours figure runs from an instant
 * the test pins seven days back to the grading, which is the current time:
 * the width rule in CLAUDE.md, with the working hours set by the test.
 */
#[CoversClass(submission_ledger::class)]
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
     * lowest user id: adding a second marker with a lower id moves neither the
     * marker nor their clock.
     *
     * @return void
     */
    public function test_pending_marker_is_position_one(): void {
        global $DB;
        $env = $this->build_environment('average', 0, false);
        $lower = $this->create_marker($env);
        $higher = $this->create_marker($env);
        $this->assertLessThan((int) $higher->id, (int) $lower->id, 'Control: the second marker has the lower id.');
        $this->seed_calendar();

        $env->assign->update_marker_allocations((int) $env->student->id, [1 => [(int) $higher->id, null]]);
        $row = $this->row($env);
        $this->assertSame((int) $higher->id, (int) $row->allocmarkerid);
        $start = time() - self::WEEK;
        $DB->update_record('block_feedback_tracker_sub', (object) [
            'id' => $row->id, 'timeallocated' => $start, 'timeallocmarker' => $start,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $env->cm->id, (int) $env->student->id, 0);
        $this->assertEqualsWithDelta(50.0, (float) $this->row($env)->allochours, 0.01, 'Control: the clock runs.');

        $env->assign->update_marker_allocations((int) $env->student->id, [2 => [(int) $lower->id, null]]);

        $row = $this->row($env);
        $this->assertSame((int) $higher->id, (int) $row->allocmarkerid);
        $this->assertSame($start, (int) $row->timeallocmarker);
        $this->assertEqualsWithDelta(50.0, (float) $row->allochours, 0.01);
        $this->assertNull($row->allocattrib);
    }

    /**
     * Manual method: the teacher who saves the final grade is responsible,
     * though they hold no slot, from the moment the last expected mark was
     * completed; a later feedback-only save by someone else moves nothing.
     *
     * @return void
     */
    public function test_manual_confirmer_is_responsible_from_the_last_completion(): void {
        global $DB;
        $env = $this->build_environment('manual');
        [$first, $second] = $env->markers;
        $this->mark($env, $first, 60);
        $this->mark($env, $second, 80);
        $slots = $this->slots_by_marker($env);
        $last = time() - self::WEEK;
        $DB->set_field('block_feedback_tracker_alloc', 'timemarked', $last - 2 * DAYSECS, ['id' => $slots[(int) $first->id]->id]);
        $DB->set_field('block_feedback_tracker_alloc', 'timemarked', $last, ['id' => $slots[(int) $second->id]->id]);
        $this->seed_calendar();
        submission_ledger::upsert_for_cm_user_attempt((int) $env->cm->id, (int) $env->student->id, 0);
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
            (int) $DB->get_field('assign_grades', 'grader', ['assignment' => $env->assign->get_instance()->id]),
            'Control: the later save overwrote the grader.'
        );

        $after = $this->row($env);
        $this->assertSame(submission_ledger::ATTRIB_CONFIRMER, $after->allocattrib);
        $this->assertSame((int) $confirmer->id, (int) $after->allocmarkerid);
        $this->assertSame($last, (int) $after->timeallocmarker);
    }

    /**
     * Manual method with no grader on the final grade (a restore stores 0):
     * position 1, labelled as a fallback, from that marker's slot allocation.
     *
     * @return void
     */
    public function test_manual_without_grader_falls_back_to_position_one(): void {
        $env = $this->build_environment('manual');
        [$first, $second] = $env->markers;
        $this->mark($env, $first, 60);
        $this->mark($env, $second, 80);
        $slot = $this->slots_by_marker($env)[(int) $first->id];

        $this->save_grade($env, null, 75.0);

        $row = $this->row($env);
        $this->assertSame(submission_ledger::ATTRIB_FALLBACK, $row->allocattrib);
        $this->assertSame((int) $first->id, (int) $row->allocmarkerid);
        $this->assertSame((int) $slot->timeallocated, (int) $row->timeallocmarker);
    }

    /**
     * Maximum method: the marker of the higher mark is responsible, from their
     * own slot allocation, though they sit in position 2.
     *
     * @return void
     */
    public function test_maximum_higher_mark_wins(): void {
        global $DB;
        $env = $this->build_environment('maximum');
        [$first, $second] = $env->markers;
        $start = time() - self::WEEK;
        $DB->set_field('block_feedback_tracker_alloc', 'timeallocated', $start, [
            'id' => $this->slots_by_marker($env)[(int) $second->id]->id,
        ]);
        $this->seed_calendar();

        $this->mark($env, $first, 60);
        $this->mark($env, $second, 80);

        $row = $this->row($env);
        $this->assertNotNull($row->timegraded, 'Control: the agreed grade closed the cycle.');
        $this->assertSame(submission_ledger::ATTRIB_MAXMARK, $row->allocattrib);
        $this->assertSame((int) $second->id, (int) $row->allocmarkerid);
        $this->assertSame($start, (int) $row->timeallocmarker);
        $this->assertEqualsWithDelta(50.0, (float) $row->allochours, 0.01);
    }

    /**
     * Maximum method: equal marks go to the lower position.
     *
     * @return void
     */
    public function test_maximum_tie_goes_to_position_one(): void {
        $env = $this->build_environment('maximum');
        [$first, $second] = $env->markers;

        $this->mark($env, $first, 80);
        $this->mark($env, $second, 80);

        $row = $this->row($env);
        $this->assertSame(submission_ledger::ATTRIB_MAXMARK, $row->allocattrib);
        $this->assertSame((int) $first->id, (int) $row->allocmarkerid);
    }

    /**
     * Maximum method: removing the winning marker after the agreed grade
     * recalculates the grade without them, and the attribution stays.
     *
     * @return void
     */
    public function test_maximum_attribution_survives_the_winner_removed(): void {
        $env = $this->build_environment('maximum', 1);
        [$first, $second] = $env->markers;
        $optional = $this->create_marker($env);
        $env->assign->update_marker_allocations((int) $env->student->id, [3 => [(int) $optional->id, 1]]);
        $this->mark($env, $first, 60);
        $this->mark($env, $second, 70);
        $this->mark($env, $optional, 90);
        $before = $this->row($env);
        $this->assertSame((int) $optional->id, (int) $before->allocmarkerid, 'Control: the optional marker won.');
        $this->assertSame(submission_ledger::ATTRIB_MAXMARK, $before->allocattrib);

        $env->assign->update_marker_allocations((int) $env->student->id, [3 => [0, 0]]);
        $this->assertEquals(
            70,
            $env->assign->get_user_grade((int) $env->student->id, false)->grade,
            'Control: core recalculated the grade without the removed marker.'
        );

        $after = $this->row($env);
        $this->assertSame(submission_ledger::ATTRIB_MAXMARK, $after->allocattrib);
        $this->assertSame((int) $optional->id, (int) $after->allocmarkerid);
        $this->assertSame($before->timeallocmarker, $after->timeallocmarker);
        $this->assertSame($before->timegraded, $after->timegraded);
    }

    /**
     * Average method: position 1, whoever gave the higher mark.
     *
     * @return void
     */
    public function test_average_is_position_one(): void {
        $env = $this->build_environment('average');
        [$first, $second] = $env->markers;
        $slot = $this->slots_by_marker($env)[(int) $first->id];

        $this->mark($env, $first, 60);
        $this->mark($env, $second, 80);

        $row = $this->row($env);
        $this->assertSame(submission_ledger::ATTRIB_POSITION, $row->allocattrib);
        $this->assertSame((int) $first->id, (int) $row->allocmarkerid);
        $this->assertSame((int) $slot->timeallocated, (int) $row->timeallocmarker);
    }

    /**
     * Enabling an optional marker on a graded, unreleased student withdraws the
     * agreed grade: the attribution is cleared and the provisional marker in
     * position 1 is back, from their slot allocation.
     *
     * @return void
     */
    public function test_withdrawn_grade_returns_to_the_provisional_marker(): void {
        $env = $this->build_environment('maximum', 1);
        [$first, $second] = $env->markers;
        $slot = $this->slots_by_marker($env)[(int) $first->id];
        $this->mark($env, $first, 60);
        $this->mark($env, $second, 80);
        $this->assertSame(submission_ledger::ATTRIB_MAXMARK, $this->row($env)->allocattrib, 'Control: attributed.');
        $this->assertSame((int) $second->id, (int) $this->row($env)->allocmarkerid);

        $extra = $this->create_marker($env);
        $env->assign->update_marker_allocations((int) $env->student->id, [3 => [(int) $extra->id, 1]]);

        $row = $this->row($env);
        $this->assertNull($row->timegraded, 'Control: the cycle reopened.');
        $this->assertNull($row->allocattrib);
        $this->assertSame((int) $first->id, (int) $row->allocmarkerid);
        $this->assertSame((int) $slot->timeallocated, (int) $row->timeallocmarker);
    }

    /**
     * A released response whose agreed grade is cleared in bulk stays recorded
     * (owner decision D2), and so does its attribution.
     *
     * @return void
     */
    public function test_released_response_cleared_in_bulk_keeps_its_attribution(): void {
        $env = $this->build_environment('maximum');
        [$first, $second] = $env->markers;
        $this->mark($env, $first, 60);
        $this->mark($env, $second, 80);
        $this->set_state($env, ASSIGN_MARKING_WORKFLOW_STATE_RELEASED);
        $before = $this->row($env);

        $env->assign->recalculate_agreed_grades(ASSIGN_MULTIMARKING_ACTION_CLEAR);
        $this->assertEquals(-1, $env->assign->get_user_grade((int) $env->student->id, false)->grade);
        submission_ledger::upsert_for_cm_user_attempt((int) $env->cm->id, (int) $env->student->id, 0);

        $after = $this->row($env);
        $this->assertSame($before->timegraded, $after->timegraded, 'Control: the response stays recorded.');
        $this->assertSame(submission_ledger::ATTRIB_MAXMARK, $after->allocattrib);
        $this->assertSame((int) $second->id, (int) $after->allocmarkerid);
        $this->assertSame($before->timeallocmarker, $after->timeallocmarker);
    }

    /**
     * A single-marker activity keeps the allocated marker and their clock when
     * someone else grades, and records no attribution.
     *
     * @return void
     */
    public function test_single_marker_activity_is_unchanged(): void {
        $env = $this->build_environment('average', 0, true, 1);
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
     * @param string $method The multimarkmethod.
     * @param int $optional Number of optional marker slots.
     * @param bool $allocate Whether to allocate a new marker to each required slot.
     * @param int $required Number of required marker slots.
     * @return \stdClass course, cm, assign, student and markers.
     */
    private function build_environment(
        string $method,
        int $optional = 0,
        bool $allocate = true,
        int $required = 2
    ): \stdClass {
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
            'optionalmarkercount' => $optional,
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
            $allocations = [];
            for ($pos = 1; $pos <= $required; $pos++) {
                $marker = $this->create_marker($env);
                $env->markers[] = $marker;
                $allocations[$pos] = [(int) $marker->id, null];
            }
            $assign->update_marker_allocations((int) $student->id, $allocations);
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
     * @param float $value
     * @return void
     */
    private function save_grade(\stdClass $env, ?\stdClass $teacher, float $value): void {
        $grade = $env->assign->get_user_grade((int) $env->student->id, true);
        $grade->grade = $value;
        $grade->grader = $teacher !== null ? (int) $teacher->id : 0;
        $this->assertTrue($env->assign->update_grade($grade));
    }

    /**
     * Set the student's workflow state and fire the event core fires for it.
     *
     * @param \stdClass $env
     * @param string $state
     * @return void
     */
    private function set_state(\stdClass $env, string $state): void {
        global $DB;
        $flags = $env->assign->get_user_flags((int) $env->student->id, true);
        $flags->workflowstate = $state;
        $env->assign->update_user_flags($flags);
        $user = $DB->get_record('user', ['id' => $env->student->id], '*', MUST_EXIST);
        \mod_assign\event\workflow_state_updated::create_from_user($env->assign, $user, $state)->trigger();
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
     * The slot rows of the student's current cycle, keyed by marker id.
     *
     * @param \stdClass $env
     * @return array
     */
    private function slots_by_marker(\stdClass $env): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('block_feedback_tracker_alloc', ['subid' => $this->row($env)->id], 'id ASC') as $s) {
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
}
