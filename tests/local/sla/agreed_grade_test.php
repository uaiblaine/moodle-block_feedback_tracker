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
 * Tests for how multi-marker agreed grades open and close measurement cycles.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Multi-marker agreed grades on Moodle 5.3, driven through mod_assign's own API.
 *
 * Every mark goes through assign::update_mark() and every allocation through
 * assign::update_marker_allocations(), so the events are the ones core fires.
 * Only the release is announced by hand: core's release paths need a form,
 * and the release itself is covered by observer_coverage_test.
 */
#[CoversClass(submission_ledger::class)]
#[CoversClass(observer::class)]
final class agreed_grade_test extends \advanced_testcase {
    /**
     * Flush per-request statics that survive resetAfterTest().
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        submission_ledger::reset_memos();
        group_resolver::reset_memo();
    }

    /**
     * Fewer completed marks than required leave the cycle pending: no agreed
     * grade exists yet, and none is invented.
     *
     * @return void
     */
    public function test_partial_marking_stays_pending(): void {
        $env = $this->build_environment('average');
        $this->mark($env, $env->markers[0], 60);

        $row = $this->row($env);
        $this->assertNull($row->timegraded, 'One mark of two is not a response.');
        $this->assertNull($row->timemarked);

        $this->mark($env, $env->markers[1], 80);
        $row = $this->row($env);
        $this->assertNotNull($row->timemarked, 'Control: the second mark forms the agreed grade.');
    }

    /**
     * Clearing agreed grades from the activity settings does not take back a
     * response already released to the student (owner decision D2).
     *
     * @return void
     */
    public function test_bulk_clear_keeps_a_released_response(): void {
        $env = $this->build_environment('average');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 80);
        $this->release($env);
        $before = $this->row($env);
        $this->assertNotNull($before->timegraded, 'Control: the released cycle is closed.');
        $this->assertNotNull($before->timeclosed);

        $env->assign->recalculate_agreed_grades(ASSIGN_MULTIMARKING_ACTION_CLEAR);
        $this->assertEquals(-1, $env->assign->get_user_grade((int) $env->student->id, false)->grade);

        $after = $this->row($env);
        $this->assertSame($before->timegraded, $after->timegraded);
        $this->assertSame($before->timeclosed, $after->timeclosed);
        $this->assertSame($before->timemarked, $after->timemarked);
        $this->assertSame('released', $after->gradestate);
    }

    /**
     * Once the state is taken back from released, clearing the grade reopens
     * the cycle as before: the student no longer has a response.
     *
     * @return void
     */
    public function test_clear_after_unrelease_reopens(): void {
        $env = $this->build_environment('average');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 80);
        $this->release($env);
        $this->set_state($env, ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE);

        $env->assign->recalculate_agreed_grades(ASSIGN_MULTIMARKING_ACTION_CLEAR);

        $row = $this->row($env);
        $this->assertNull($row->timegraded);
        $this->assertNull($row->timeclosed);
    }

    /**
     * Enabling an optional marker on a graded, unreleased student clears the
     * agreed grade, and the cycle is pending again until the extra mark is in.
     *
     * @return void
     */
    public function test_enabling_an_optional_marker_reopens_an_unreleased_grade(): void {
        $env = $this->build_environment('average', 1);
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 80);
        $this->assertNotNull($this->row($env)->timemarked, 'Control: the agreed grade exists.');

        $extra = $this->create_marker($env);
        $env->assign->update_marker_allocations((int) $env->student->id, [3 => [(int) $extra->id, 1]]);

        $this->assertEquals(-1, $env->assign->get_user_grade((int) $env->student->id, false)->grade);
        $row = $this->row($env);
        $this->assertNull($row->timemarked);
        $this->assertNull($row->timegraded);
    }

    /**
     * Recalculating under the maximum method rewrites the grade row but keeps
     * the response instant: the first response wins.
     *
     * @return void
     */
    public function test_recalculation_keeps_the_response_instant(): void {
        $env = $this->build_environment('maximum');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 80);
        $this->release($env);
        $before = $this->row($env);
        $this->waitForSecond();

        $env->assign->recalculate_agreed_grades(ASSIGN_MULTIMARKING_ACTION_RECALCULATE);

        $after = $this->row($env);
        $this->assertSame($before->timegraded, $after->timegraded);
        $this->assertSame($before->timeclosed, $after->timeclosed);
    }

    /**
     * Build a processable course with a multi-marker assign, one submitted
     * student and the required markers allocated.
     *
     * @param string $method The multimarkmethod.
     * @param int $optional Number of optional marker slots.
     * @return \stdClass assign, cm, student and markers.
     */
    private function build_environment(string $method, int $optional = 0): \stdClass {
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
            'markercount' => 2,
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

        $env = (object) ['course' => $course, 'cm' => $cm, 'assign' => $assign, 'student' => $student];
        $env->markers = [$this->create_marker($env), $this->create_marker($env)];
        $assign->update_marker_allocations((int) $student->id, [
            1 => [(int) $env->markers[0]->id, null],
            2 => [(int) $env->markers[1]->id, null],
        ]);
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
     * Release the student's grade and announce it.
     *
     * @param \stdClass $env
     * @return void
     */
    private function release(\stdClass $env): void {
        $this->set_state($env, ASSIGN_MARKING_WORKFLOW_STATE_RELEASED);
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
}
