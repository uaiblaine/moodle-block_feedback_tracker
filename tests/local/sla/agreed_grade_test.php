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

/**
 * Multi-marker agreed grades on Moodle 5.2, driven through mod_assign's own API.
 *
 * Every allocation goes through assign::update_allocated_markers() and every
 * mark through assign::update_mark(), so the grade row is the one core writes:
 * core 5.2 computes the agreed grade inside update_mark() as soon as every
 * allocated marker has a mark row ({@see \assign::get_marks()}), and never
 * under the manual method. A mark alone fires nothing on 5.2, so each pending
 * assertion re-derives the row first: the writer itself must not close it.
 *
 * The class-level covers docblock is kept, not attributes: this branch family
 * still supports Moodle 4.5, whose moodle-cs leg cannot read attributes.
 *
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 * @covers \block_feedback_tracker\local\sla\observer
 */
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
     * Fewer marks than markercount leave the cycle pending: no agreed grade
     * exists yet, and a re-derivation invents none.
     *
     * @return void
     */
    public function test_partial_marking_stays_pending(): void {
        $env = $this->build_environment('average');
        $this->mark($env, $env->markers[0], 60);

        $this->assertLessThan(0, (float) $this->grade($env)->grade, 'Control: core computed nothing from one mark of two.');
        $this->rederive($env);
        $row = $this->row($env);
        $this->assertNull($row->timegraded, 'One mark of two is not a response.');
        $this->assertNull($row->timemarked);

        $this->mark($env, $env->markers[1], 80);
        $this->assertEquals(70, $this->grade($env)->grade, 'Control: the second mark row forms the agreed grade.');
        $this->assertNotNull($this->row($env)->timemarked, 'Control: the agreed grade closes the cycle.');
    }

    /**
     * Under the manual method every mark in still computes nothing: core
     * leaves the grade to whoever confirms it, so the cycle stays pending until
     * that save.
     *
     * @return void
     */
    public function test_manual_all_marks_in_stays_pending(): void {
        $env = $this->build_environment('manual');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 80);

        $grade = $this->grade($env);
        $this->assertCount(2, $env->assign->get_marks((int) $grade->id, (int) $env->student->id), 'Control: both marks are in.');
        $this->assertLessThan(0, (float) $grade->grade, 'Control: manual computes nothing.');
        $this->rederive($env);
        $row = $this->row($env);
        $this->assertNull($row->timegraded);
        $this->assertNull($row->timemarked);

        $confirmer = $this->getDataGenerator()->create_and_enrol($env->course, 'editingteacher');
        $grade->grade = 75.0;
        $grade->grader = (int) $confirmer->id;
        $this->assertTrue($env->assign->update_grade($grade));
        $this->assertNotNull($this->row($env)->timegraded, 'Control: the confirmed grade closes the cycle.');
    }

    /**
     * Under the average method the last mark row makes core compute the agreed
     * grade at once, and the cycle carries it.
     *
     * @return void
     */
    public function test_average_all_marks_in_closes_the_cycle(): void {
        $env = $this->build_environment('average');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 80);

        $grade = $this->grade($env);
        $this->assertEquals(70, $grade->grade);
        $row = $this->row($env);
        $this->assertSame((int) $grade->timemodified, (int) $row->timemarked);
        $this->assertSame((int) $grade->timemodified, (int) $row->timegraded);
        $this->assertNull($row->timeclosed, 'Not released: the student has not seen it yet.');
    }

    /**
     * Build a processable course with a two-marker assign, one submitted
     * student with a ledger row, and both markers allocated in one call.
     *
     * Core's generator leaves markercount at 0, so it is always set here.
     *
     * @param string $method The multimarkmethod.
     * @return \stdClass course, cm, assign, student and markers.
     */
    private function build_environment(string $method): \stdClass {
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
            'multimarkmethod' => $method,
            'grade' => 100,
        ]);
        $cm = get_coursemodule_from_instance('assign', $inst->id);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $this->assertTrue($assign->is_using_multiple_marking(), 'Control: core sees multiple marking.');

        $t1 = time() - 3 * DAYSECS;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $inst->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $env = (object) ['course' => $course, 'cm' => $cm, 'assign' => $assign, 'student' => $student];
        $env->markers = [
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher'),
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher'),
        ];
        $assign->update_allocated_markers((int) $student->id, [(int) $env->markers[0]->id, (int) $env->markers[1]->id]);
        return $env;
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
}
