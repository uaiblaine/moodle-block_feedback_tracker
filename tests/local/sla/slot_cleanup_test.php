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
 * Tests that every path deleting ledger rows deletes their slot rows.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\task\prune_ledger;
use block_feedback_tracker\task\reconcile_ledger;

/**
 * A slot row belongs to a ledger cycle: when the cycle goes, its slot rows go
 * in the same place, or they outlive it as personal data no reader reaches and
 * no retention removes. Each test deletes through one production path and
 * checks that no slot row is left without its ledger row, with a second course
 * whose rows must survive as the control.
 *
 * The class-level covers docblock is kept, not attributes: this branch family
 * still supports Moodle 4.5, whose moodle-cs leg cannot read attributes.
 *
 * @covers \block_feedback_tracker\local\sla\slot_ledger
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 * @covers \block_feedback_tracker\local\sla\observer
 * @covers \block_feedback_tracker\task\prune_ledger
 * @covers \block_feedback_tracker\task\reconcile_ledger
 * @covers \block_feedback_tracker\task\discard_course_data
 * @covers \block_feedback_tracker\privacy\provider
 */
final class slot_cleanup_test extends \advanced_testcase {
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
     * Deleting the course deletes its slot rows.
     *
     * @return void
     */
    public function test_course_deleted(): void {
        [$env, $control] = $this->two_courses();
        ob_start();
        delete_course($env->course, false);
        ob_end_clean();
        $this->assert_cleaned($env, $control);
    }

    /**
     * Removing the block discards the course's data, slot rows included.
     *
     * @return void
     */
    public function test_block_removed_discards_the_course(): void {
        global $DB;
        [$env, $control] = $this->two_courses();
        $DB->delete_records('block_instances', [
            'blockname' => 'feedback_tracker',
            'parentcontextid' => \context_course::instance($env->course->id)->id,
        ]);
        course_access::reset_memo();
        $task = new \block_feedback_tracker\task\discard_course_data();
        $task->set_custom_data(['courseid' => (int) $env->course->id]);

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub', ['courseid' => $env->course->id]), 'Control.');
        $this->assert_cleaned($env, $control);
    }

    /**
     * Deleting the activity deletes its slot rows.
     *
     * @return void
     */
    public function test_course_module_deleted(): void {
        [$env, $control] = $this->two_courses();
        set_config('coursebinenable', 0, 'tool_recyclebin');
        \core_courseformat\formatactions::cm($env->course)->delete((int) $env->cm->id);
        $this->assert_cleaned($env, $control);
    }

    /**
     * The data reset deletes every slot row with the ledger.
     *
     * @return void
     */
    public function test_reset(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/blocks/feedback_tracker/lib.php');
        $this->two_courses();
        $this->assertGreaterThan(0, $DB->count_records('block_feedback_tracker_alloc'), 'Control: there are slot rows.');

        block_feedback_tracker_reset_data();

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_alloc'));
    }

    /**
     * Losing the last enrolment deletes the student's slot rows.
     *
     * @return void
     */
    public function test_enrolment_removed(): void {
        global $DB;
        [$env, $control] = $this->two_courses();
        $instance = $DB->get_record('enrol', ['courseid' => $env->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, (int) $env->student->id);
        $this->assert_cleaned($env, $control);
    }

    /**
     * Deleting the student's account deletes their slot rows; deleting a
     * marker's account keeps the rows where they were the marker, as core
     * keeps the allocation row.
     *
     * @return void
     */
    public function test_user_deleted(): void {
        global $DB;
        [$env, $control] = $this->two_courses();
        delete_user($DB->get_record('user', ['id' => $env->student->id]));
        $this->assert_cleaned($env, $control);

        $marker = $control->markers[0];
        $before = $DB->count_records('block_feedback_tracker_alloc', ['marker' => $marker->id]);
        delete_user($DB->get_record('user', ['id' => $marker->id]));
        $this->assertSame($before, $DB->count_records('block_feedback_tracker_alloc', ['marker' => $marker->id]));
    }

    /**
     * Retention deletes the slot rows of the closed rows it prunes.
     *
     * @return void
     */
    public function test_retention_prunes_closed_rows(): void {
        global $DB;
        [$env, $control] = $this->two_courses();
        set_config('retention_active', 1, 'block_feedback_tracker');
        $DB->set_field('block_feedback_tracker_sub', 'timegraded', time() - 800 * DAYSECS, ['courseid' => $env->course->id]);

        ob_start();
        (new prune_ledger())->execute();
        ob_end_clean();

        $this->assert_cleaned($env, $control);
    }

    /**
     * Retention deletes the slot rows of the dismissed rows it prunes.
     *
     * @return void
     */
    public function test_retention_prunes_dismissed_rows(): void {
        global $DB;
        [$env, $control] = $this->two_courses();
        set_config('retention_active', 1, 'block_feedback_tracker');
        $DB->execute(
            'UPDATE {block_feedback_tracker_sub} SET timedismissed = :t, iscurrent = 0 WHERE courseid = :c',
            ['t' => time() - 800 * DAYSECS, 'c' => $env->course->id]
        );

        ob_start();
        (new prune_ledger())->execute();
        ob_end_clean();

        $this->assert_cleaned($env, $control);
    }

    /**
     * The orphan sweep deletes the slot rows of the ledger rows it removes.
     *
     * @return void
     */
    public function test_orphan_sweep(): void {
        global $DB;
        [$env, $control] = $this->two_courses();
        $DB->delete_records('assign_submission', ['assignment' => $env->assign->get_instance()->id]);

        $task = new reconcile_ledger();
        (new \ReflectionProperty($task, 'sweepdeadline'))->setValue($task, time() + 60);
        (new \ReflectionMethod($task, 'sweep_orphans'))->invoke($task, [(int) $env->course->id], 500, 'orphan');

        $this->assert_cleaned($env, $control);
    }

    /**
     * The departed-participant sweep deletes the slot rows of the ledger rows
     * it removes.
     *
     * @return void
     */
    public function test_departed_participant_sweep(): void {
        global $DB;
        [$env, $control] = $this->two_courses();
        // Behind the observer's back, as a lost event leaves it.
        $DB->delete_records('user_enrolments', ['userid' => $env->student->id]);

        $task = new reconcile_ledger();
        (new \ReflectionProperty($task, 'sweepdeadline'))->setValue($task, time() + 60);
        (new \ReflectionMethod($task, 'sweep_departed_participants'))->invoke(
            $task,
            [(int) $env->course->id],
            500,
            'participant'
        );

        $this->assert_cleaned($env, $control);
    }

    /**
     * A privacy erasure of the whole course context deletes its slot rows.
     *
     * @return void
     */
    public function test_privacy_course_context_erasure(): void {
        [$env, $control] = $this->two_courses();
        \block_feedback_tracker\privacy\provider::delete_data_for_all_users_in_context(
            \context_course::instance($env->course->id)
        );
        $this->assert_cleaned($env, $control);
    }

    /**
     * The course under test is left with no slot row, no slot row anywhere is
     * left without its ledger row, and the control course keeps its rows.
     *
     * @param \stdClass $env The course the deletion was aimed at.
     * @param \stdClass $control The course that must be untouched.
     * @return void
     */
    private function assert_cleaned(\stdClass $env, \stdClass $control): void {
        global $DB;
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_alloc', ['userid' => $env->student->id]));
        $this->assertSame(0, $DB->count_records_select(
            'block_feedback_tracker_alloc',
            'subid NOT IN (SELECT id FROM {block_feedback_tracker_sub})'
        ), 'No slot row outlives its ledger row.');
        $this->assertSame(
            2,
            $DB->count_records('block_feedback_tracker_alloc', ['courseid' => $control->course->id]),
            'Control: the other course keeps its slot rows.'
        );
    }

    /**
     * Two processable courses, each with a two-marker activity and one student
     * with both markers allocated.
     *
     * @return array [the course under test, the control course]
     */
    private function two_courses(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablebusinesshours', 0, 'block_feedback_tracker');
        $envs = [$this->course_with_slots(), $this->course_with_slots()];
        foreach ($envs as $env) {
            $this->assertSame(
                2,
                $DB->count_records('block_feedback_tracker_alloc', ['courseid' => $env->course->id]),
                'Control: the fixture wrote two slot rows.'
            );
        }
        return $envs;
    }

    /**
     * One processable course with a two-marker assign and one handed-in
     * student whose markers are allocated through core.
     *
     * @return \stdClass course, cm, assign, student and markers.
     */
    private function course_with_slots(): \stdClass {
        global $DB;
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
            'multimarkmethod' => 'average',
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
        $markers = [
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher'),
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher'),
        ];
        $assign->update_allocated_markers((int) $student->id, [(int) $markers[0]->id, (int) $markers[1]->id]);
        return (object) ['course' => $course, 'cm' => $cm, 'assign' => $assign, 'student' => $student, 'markers' => $markers];
    }
}
