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
 * Tests for the marker-allocation rule of the Grade Now list.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\dashboard_scope;
use block_feedback_tracker\local\sla\group_resolver;
use block_feedback_tracker\local\sla\submission_ledger;
use block_feedback_tracker\local\sla\submission_status;
use core_external\external_api;

/**
 * Marker allocation in the Grade Now list (Moodle 4.5, owner decision D3).
 *
 * Allocations go through core's batch "Set allocated marker" operation, so the
 * {assign_user_flags} rows the SQL reads are the ones core writes.
 *
 * @covers \block_feedback_tracker\external\get_grader_priority_list
 * @covers \block_feedback_tracker\local\sla\allocation_scope
 */
final class get_grader_priority_list_allocation_test extends \advanced_testcase {
    /**
     * Flush per-request statics that survive resetAfterTest().
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        submission_ledger::reset_memos();
        group_resolver::reset_memo();
    }

    /**
     * A student allocated to teacher A is listed for A and not for B.
     *
     * @return void
     */
    public function test_allocated_student_is_listed_for_the_marker_only(): void {
        $env = $this->build_environment(true);
        $this->allocate($env, $env->student, $env->teachers[0]);

        $this->assertTrue($this->listed($env, $env->teachers[0]));
        $this->assertFalse($this->listed($env, $env->teachers[1]));
    }

    /**
     * A student with no allocated marker is the coordinator's queue: every teacher sees it.
     *
     * @return void
     */
    public function test_unallocated_student_is_listed_for_everybody(): void {
        $env = $this->build_environment(true);

        $this->assertTrue($this->listed($env, $env->teachers[0]));
        $this->assertTrue($this->listed($env, $env->teachers[1]));
    }

    /**
     * Once the allocated marker grades and the row awaits release, it leaves their list and does not move to anybody else's.
     *
     * @return void
     */
    public function test_graded_row_leaves_the_markers_list(): void {
        global $DB;
        // With the release stopping the clock a graded, unreleased row stays pending in the ledger.
        set_config('release_stops_clock', 1, 'block_feedback_tracker');
        $env = $this->build_environment(true);
        [$a, $b] = $env->teachers;
        $this->allocate($env, $env->student, $a);
        $this->assertTrue($this->listed($env, $a), 'Control: listed before grading.');

        $this->grade($env, $env->student, $a);
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_sub', 'timegraded', ['userid' => $env->student->id, 'iscurrent' => 1]),
            'Control: the ledger still holds the row open, awaiting release.'
        );

        $this->assertFalse($this->listed($env, $a), 'The wait for release belongs to no marker.');
        $this->assertFalse($this->listed($env, $b));
    }

    /**
     * Control: an activity without marker allocation is listed for both teachers,
     * even when a stale allocation row exists.
     *
     * @return void
     */
    public function test_activity_without_allocation_is_unchanged(): void {
        global $DB;
        $env = $this->build_environment(false);
        [$a, $b] = $env->teachers;
        $DB->insert_record('assign_user_flags', (object) [
            'assignment' => $env->assign->get_instance()->id,
            'userid' => $env->student->id,
            'allocatedmarker' => $a->id,
        ]);

        $this->assertTrue($this->listed($env, $a));
        $this->assertTrue($this->listed($env, $b));
    }

    /**
     * Control: marking workflow on but allocation switched off after allocating
     * lists the student for everyone; the allocation half of arm (a) is what
     * lets the second teacher see it.
     *
     * @return void
     */
    public function test_allocation_switched_off_lists_for_everyone(): void {
        global $DB;
        $env = $this->build_environment(true);
        [$a, $b] = $env->teachers;
        $this->allocate($env, $env->student, $a);
        $this->assertFalse($this->listed($env, $b), 'Control: allocated to A, hidden from B.');

        $DB->set_field('assign', 'markingallocation', 0, ['id' => $env->assign->get_instance()->id]);

        $this->assertTrue($this->listed($env, $a));
        $this->assertTrue($this->listed($env, $b));
    }

    /**
     * The limit applies to the viewer's own queue, not to a list filtered afterwards:
     * rows hidden from the viewer do not use up the limit.
     *
     * @return void
     */
    public function test_limit_counts_only_the_viewers_rows(): void {
        $env = $this->build_environment(true);
        [$a, $b] = $env->teachers;
        // The hidden row waits longest; a limit of 1 must still return the visible one.
        $this->allocate($env, $env->student, $b);
        $second = $this->add_student($env, 1.0);
        $this->allocate($env, $second, $a);

        $result = $this->run_as($a, 1);
        $this->assertSame(1, $result['returned']);
        $this->assertSame((int) $second->id, (int) $result['submissions'][0]['userid']);
    }

    /**
     * Build a course with one assignment, one submitted student and two teachers.
     *
     * @param bool $allocation Whether marking workflow and marker allocation are on.
     * @return \stdClass course, cm, assign, student, teachers.
     */
    private function build_environment(bool $allocation): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/tests/fixtures/testable_assign.php');
        $this->setAdminUser();
        set_config('enablebusinesshours', 0, 'block_feedback_tracker');
        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();

        $inst = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'markingworkflow' => (int) $allocation,
            'markingallocation' => (int) $allocation,
            'grade' => 100,
        ]);
        $cm = get_coursemodule_from_instance('assign', $inst->id);
        $env = (object) [
            'course' => $course,
            'cm' => $cm,
            'assign' => new \mod_assign_testable_assign(\context_module::instance($cm->id), $cm, $course),
        ];
        $env->student = $this->add_student($env, 3.0);
        $env->teachers = [
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher'),
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher'),
        ];
        return $env;
    }

    /**
     * Enrol a student with a submitted attempt and a ledger row.
     *
     * @param \stdClass $env
     * @param float $days How long ago it was submitted.
     * @return \stdClass The student.
     */
    private function add_student(\stdClass $env, float $days): \stdClass {
        global $DB;
        $student = $this->getDataGenerator()->create_and_enrol($env->course, 'student');
        $time = time() - (int) ($days * DAYSECS);
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $env->assign->get_instance()->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $time, 'timemodified' => $time,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $env->cm->id, (int) $student->id, 0);
        return $student;
    }

    /**
     * Allocate a student to a marker through core's batch operation, as admin.
     *
     * @param \stdClass $env
     * @param \stdClass $student
     * @param \stdClass $marker
     * @return void
     */
    private function allocate(\stdClass $env, \stdClass $student, \stdClass $marker): void {
        $this->setAdminUser();
        $env->assign->testable_process_set_batch_marking_allocation((string) $student->id, (int) $marker->id);
    }

    /**
     * Save a numeric grade, as the marker's grading form does.
     *
     * @param \stdClass $env
     * @param \stdClass $student
     * @param \stdClass $marker
     * @return void
     */
    private function grade(\stdClass $env, \stdClass $student, \stdClass $marker): void {
        $grade = $env->assign->get_user_grade((int) $student->id, true);
        $grade->grade = 60;
        $grade->grader = (int) $marker->id;
        $env->assign->update_grade($grade);
    }

    /**
     * Run the web service as a user.
     *
     * @param \stdClass $user
     * @param int $limit
     * @return array The cleaned response.
     */
    private function run_as(\stdClass $user, int $limit = 50): array {
        $this->setUser($user);
        dashboard_scope::reset_memo();
        return external_api::clean_returnvalue(
            get_grader_priority_list::execute_returns(),
            get_grader_priority_list::execute($limit, '')
        );
    }

    /**
     * Whether the first student's row is on this teacher's Grade Now list.
     *
     * @param \stdClass $env
     * @param \stdClass $teacher
     * @return bool
     */
    private function listed(\stdClass $env, \stdClass $teacher): bool {
        foreach ($this->run_as($teacher)['submissions'] as $s) {
            if ((int) $s['userid'] === (int) $env->student->id) {
                return true;
            }
        }
        return false;
    }
}
