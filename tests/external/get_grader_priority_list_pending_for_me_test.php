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
 * Tests for the "pending for me" rule of the Grade Now list.
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
use block_feedback_tracker\local\sla\marking_progress;
use block_feedback_tracker\local\sla\submission_ledger;
use block_feedback_tracker\local\sla\submission_status;
use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * "Pending for me" in the Grade Now list on an activity with marker allocation (Moodle 5.3, owner decision D3).
 *
 * Allocations go through assign::update_marker_allocations() and marks through
 * assign::update_mark(), so the rows the SQL reads are the ones core writes.
 * The agreed-grade method is manual throughout, so the row stays pending at
 * course level while individual markers finish.
 */
#[CoversClass(get_grader_priority_list::class)]
#[CoversClass(marking_progress::class)]
final class get_grader_priority_list_pending_for_me_test extends \advanced_testcase {
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
     * The owner's example: two required markers, three teachers, two allocated.
     * Each allocated marker sees the row until their own mark is complete; the
     * third teacher never does, even once both marks are in.
     *
     * @return void
     */
    public function test_owner_example(): void {
        $env = $this->build_environment(2, 0);
        [$a, $b, $c] = $env->teachers;
        $env->assign->update_marker_allocations((int) $env->student->id, [
            1 => [(int) $a->id, null],
            2 => [(int) $b->id, null],
        ]);

        $this->assertTrue($this->listed($env, $a));
        $this->assertTrue($this->listed($env, $b));
        $this->assertFalse($this->listed($env, $c), 'The third teacher holds no slot.');

        $this->mark($env, $a, 60);
        $this->assertFalse($this->listed($env, $a), 'A finished marker drops it from their own list.');
        $this->assertTrue($this->listed($env, $b), 'The other marker is still marking.');
        $this->assertFalse($this->listed($env, $c));

        $this->mark($env, $b, 80);
        $this->assertFalse($this->listed($env, $a));
        $this->assertFalse($this->listed($env, $b));
        $this->assertFalse($this->listed($env, $c));
        $this->assertSame(
            marking_progress::WAIT_AGREED,
            marking_progress::for_rows([$this->row($env)])[(int) $this->row($env)->id]['wait'],
            'Control: the row is still pending at course level, waiting for the manual agreed grade.'
        );
    }

    /**
     * A mark saved without "Marking completed" has not completed the slot:
     * the row stays listed for its marker.
     *
     * @return void
     */
    public function test_a_mark_in_marking_stays_listed(): void {
        $env = $this->build_environment(2, 0);
        [$a, $b] = $env->teachers;
        $env->assign->update_marker_allocations((int) $env->student->id, [
            1 => [(int) $a->id, null],
            2 => [(int) $b->id, null],
        ]);

        $this->mark($env, $a, 60, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);

        $this->assertTrue($this->listed($env, $a));
    }

    /**
     * An enabled optional slot keeps the row listed for its marker until that
     * marker completes, mirroring expected_marker_count(); a disabled one does not.
     *
     * @return void
     */
    public function test_enabled_optional_slot(): void {
        $env = $this->build_environment(2, 1);
        [$a, $b, $c] = $env->teachers;
        $env->assign->update_marker_allocations((int) $env->student->id, [
            1 => [(int) $a->id, null],
            2 => [(int) $b->id, null],
            3 => [(int) $c->id, 1],
        ]);
        $this->mark($env, $a, 60);
        $this->mark($env, $b, 70);
        $this->assertSame(3, $env->assign->expected_marker_count((int) $env->student->id), 'Control: core waits for the third.');

        $this->assertTrue($this->listed($env, $c), 'The enabled optional marker is still to mark.');
        $this->assertFalse($this->listed($env, $a));

        $env->assign->update_marker_allocations((int) $env->student->id, [3 => [(int) $c->id, 0]]);
        $this->assertFalse($this->listed($env, $c), 'A disabled optional slot is not a slot.');

        $env->assign->update_marker_allocations((int) $env->student->id, [3 => [(int) $c->id, 1]]);
        $this->assertTrue($this->listed($env, $c));
        $this->mark($env, $c, 90);
        $this->assertFalse($this->listed($env, $c), 'Completing the optional mark ends it.');
    }

    /**
     * A required slot left empty: nobody can complete it, so it is the
     * allocated marker's row while they have not marked, and nobody else's.
     *
     * @return void
     */
    public function test_unallocated_slot_belongs_to_no_unallocated_teacher(): void {
        $env = $this->build_environment(2, 0);
        [$a, $b] = $env->teachers;
        $env->assign->update_marker_allocations((int) $env->student->id, [1 => [(int) $a->id, null]]);

        $this->assertTrue($this->listed($env, $a));
        $this->assertFalse($this->listed($env, $b));
        $this->mark($env, $a, 60);
        $this->assertFalse($this->listed($env, $a), 'Awaiting allocation is the coordinator\'s, not the marker\'s.');
    }

    /**
     * A single-marker activity with marker allocation: only the allocated marker
     * is listed; an unallocated student of the same activity is every teacher's.
     * A single marker grades through update_grade, and the grade row leaves it.
     *
     * @return void
     */
    public function test_single_marker_allocated_activity(): void {
        $env = $this->build_environment(1, 0);
        [$a, $b] = $env->teachers;
        $env->assign->update_marker_allocations((int) $env->student->id, [1 => [(int) $a->id, null]]);
        $other = $this->add_student($env, 2.0);
        $this->assertFalse($env->assign->is_using_multiple_marking(), 'Control: core does not call this multiple marking.');

        $this->assertTrue($this->listed($env, $a));
        $this->assertFalse($this->listed($env, $b), 'A teacher with no slot does not see an allocated student.');
        $this->assertTrue($this->listed($env, $b, $other), 'Control: the unallocated student is listed for everyone.');
        $this->assertTrue($this->listed($env, $a, $other));

        $this->grade($env, $a, 60);
        $this->assertFalse($this->listed($env, $a), 'Once graded it is not pending for marking.');
        $this->assertFalse($this->listed($env, $b));
    }

    /**
     * An activity that does not use marker allocation is every teacher's, even
     * with a stale allocation row left behind by a setting that was switched off.
     *
     * @return void
     */
    public function test_activity_without_marker_allocation_is_unchanged(): void {
        global $DB;
        $env = $this->build_environment(1, 0, 0);
        [$a, $b] = $env->teachers;
        $DB->insert_record('assign_allocated_marker', (object) [
            'assignment' => $env->assign->get_instance()->id, 'student' => $env->student->id,
            'marker' => $a->id, 'optional' => 0, 'enabled' => 1,
        ]);

        $this->assertTrue($this->listed($env, $a));
        $this->assertTrue($this->listed($env, $b), 'Without marker allocation nobody is the marker.');
    }

    /**
     * A multi-marking student with no allocation is the coordinator's queue:
     * every teacher sees it.
     *
     * @return void
     */
    public function test_multi_marking_without_allocation_is_listed_for_everyone(): void {
        $env = $this->build_environment(2, 0);
        foreach ($env->teachers as $teacher) {
            $this->assertTrue($this->listed($env, $teacher));
        }
    }

    /**
     * Once the agreed grade exists on a multi-marking row, no marker has it
     * left to mark, even one whose own mark is still in marking.
     *
     * @return void
     */
    public function test_agreed_grade_ends_pending_for_a_marker(): void {
        global $DB;
        $env = $this->build_environment(2, 0);
        [$a, $b] = $env->teachers;
        $env->assign->update_marker_allocations((int) $env->student->id, [
            1 => [(int) $a->id, null],
            2 => [(int) $b->id, null],
        ]);
        $this->mark($env, $a, 60);
        $this->assertTrue($this->listed($env, $b));

        // Written straight to the table: the grade event would also close the ledger row, which hides it anyway.
        $DB->set_field('assign_grades', 'grade', 65, [
            'assignment' => $env->assign->get_instance()->id,
            'userid' => $env->student->id,
        ]);
        $this->assertSame(0, (int) $DB->get_field('block_feedback_tracker_sub', 'timegraded', ['id' => $this->row($env)->id]));
        $this->assertFalse($this->listed($env, $b), 'Awaiting release belongs to no marker.');
    }

    /**
     * The limit applies to the viewer's own queue, not to a list filtered
     * afterwards: rows hidden from the viewer do not use up the limit.
     *
     * @return void
     */
    public function test_limit_counts_only_the_viewers_rows(): void {
        $env = $this->build_environment(2, 0);
        [$a, $b] = $env->teachers;
        $env->assign->update_marker_allocations((int) $env->student->id, [
            1 => [(int) $a->id, null],
            2 => [(int) $b->id, null],
        ]);
        // The hidden row waits longest; a limit of 1 must still return the visible one.
        $this->mark($env, $a, 60);
        $second = $this->add_student($env, 1.0);
        $env->assign->update_marker_allocations((int) $second->id, [1 => [(int) $a->id, null]]);

        $result = $this->run_as($a, 1);
        $this->assertSame(1, $result['returned']);
        $this->assertSame((int) $second->id, (int) $result['submissions'][0]['userid']);
    }

    /**
     * The SQL rule agrees with marking_progress::for_rows() across the states a
     * slot can be in: listed for V exactly when V holds an active slot whose
     * state is not complete.
     *
     * @return void
     */
    public function test_sql_agrees_with_for_rows(): void {
        $env = $this->build_environment(2, 1);
        [$a, $b, $c, $d] = $env->teachers;
        $env->assign->update_marker_allocations((int) $env->student->id, [
            1 => [(int) $a->id, null],
            2 => [(int) $b->id, null],
            3 => [(int) $c->id, 1],
        ]);
        $this->mark($env, $a, 60);
        $this->mark($env, $b, 70, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);

        $states = [];
        foreach ([[$a, 'complete'], [$b, 'inmarking'], [$c, 'none'], [$d, 'noslot']] as [$teacher, $label]) {
            $row = $this->row($env);
            $progress = marking_progress::for_rows([$row])[(int) $row->id];
            $expected = false;
            foreach ($progress['slots'] as $slot) {
                if ($slot['marker'] === (int) $teacher->id && $slot['state'] !== marking_progress::MARK_COMPLETE) {
                    $expected = true;
                }
            }
            $states[$label] = $expected;
            $this->assertSame($expected, $this->listed($env, $teacher), "Teacher with state $label.");
        }
        $this->assertSame(['complete' => false, 'inmarking' => true, 'none' => true, 'noslot' => false], $states);
    }

    /**
     * Save the agreed (or a single marker's) grade, as the grading form does.
     *
     * @param \stdClass $env
     * @param \stdClass $grader
     * @param float $value
     * @return void
     */
    private function grade(\stdClass $env, \stdClass $grader, float $value): void {
        $grade = $env->assign->get_user_grade((int) $env->student->id, true);
        $grade->grader = (int) $grader->id;
        $grade->grade = $value;
        $env->assign->update_grade($grade);
    }

    /**
     * Build a course with one multi-marking (or single-marker) activity, one
     * submitted student, a ledger row and four teachers.
     *
     * @param int $required Required markers.
     * @param int $optional Optional markers.
     * @param int $allocation Whether the activity uses marker allocation.
     * @return \stdClass course, cm, assign, student, teachers.
     */
    private function build_environment(int $required, int $optional, int $allocation = 1): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
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
            'markingworkflow' => 1,
            'markingallocation' => $allocation,
            'markercount' => $required,
            'optionalmarkercount' => $optional,
            'multimarkmethod' => 'manual',
            'grade' => 100,
        ]);
        $cm = get_coursemodule_from_instance('assign', $inst->id);
        $env = (object) [
            'course' => $course,
            'cm' => $cm,
            'assign' => new \assign(\context_module::instance($cm->id), $cm, $course),
        ];
        $env->student = $this->add_student($env, 3.0);
        $env->teachers = [];
        for ($i = 0; $i < 4; $i++) {
            $env->teachers[] = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        }
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
     * Record one marker's mark, as the marker's own grading form does.
     *
     * @param \stdClass $env
     * @param \stdClass $marker
     * @param float $mark
     * @param string $state The marker's own workflow state; "Marking completed" by default.
     * @return void
     */
    private function mark(\stdClass $env, \stdClass $marker, float $mark, string $state = 'readyforreview'): void {
        $grade = $env->assign->get_user_grade((int) $env->student->id, true);
        $grade->grader = (int) $marker->id;
        $env->assign->update_mark($grade, $mark, $state);
    }

    /**
     * The first student's current ledger row.
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
     * Whether a student's row (the first one by default) is on this teacher's Grade Now list.
     *
     * @param \stdClass $env
     * @param \stdClass $teacher
     * @param \stdClass|null $student
     * @return bool
     */
    private function listed(\stdClass $env, \stdClass $teacher, ?\stdClass $student = null): bool {
        $student ??= $env->student;
        foreach ($this->run_as($teacher)['submissions'] as $s) {
            if ((int) $s['userid'] === (int) $student->id) {
                return true;
            }
        }
        return false;
    }
}
