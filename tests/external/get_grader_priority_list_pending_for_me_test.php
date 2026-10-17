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
 * Tests for "pending for me" in the Grade Now list (owner decision D3).
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

/**
 * "Pending for me" in the Grade Now list on Moodle 5.2.
 *
 * Allocations go through assign::update_allocated_markers(), marks through
 * assign::update_mark() and final grades through assign::update_grade(), so
 * the rows the SQL reads are the ones core writes. The agreed-grade method is
 * manual unless a test says otherwise, so the row stays pending at course
 * level while individual markers finish, and `release_stops_clock` is on, so a
 * row with an agreed grade stays pending until its release: that is the state
 * in which only the agreed-grade clause hides it.
 *
 * The class-level covers docblock is kept, not attributes: this branch family
 * still supports Moodle 4.5, whose moodle-cs leg cannot read attributes.
 *
 * @covers \block_feedback_tracker\external\get_grader_priority_list
 * @covers \block_feedback_tracker\local\sla\marking_progress
 */
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
     * The owner's example: two required markers, three teachers, two
     * allocated. Each allocated marker sees the row until their own mark is
     * complete; the third teacher never does, even once both marks are in.
     *
     * @return void
     */
    public function test_owner_example(): void {
        $env = $this->build_environment(2);
        [$a, $b, $c] = $env->teachers;
        $this->allocate($env, $env->student, [$a, $b]);

        $this->assertTrue($this->listed($env, $a));
        $this->assertTrue($this->listed($env, $b));
        $this->assertFalse($this->listed($env, $c), 'The third teacher is not allocated.');

        $this->mark($env, $a, 60);
        $this->assertFalse($this->listed($env, $a), 'A finished marker drops it from their own list.');
        $this->assertTrue($this->listed($env, $b), 'The other marker is still marking.');
        $this->assertFalse($this->listed($env, $c));

        $this->mark($env, $b, 80);
        $this->assertFalse($this->listed($env, $a));
        $this->assertFalse($this->listed($env, $b));
        $this->assertFalse($this->listed($env, $c));
        $row = $this->row($env);
        $this->assertNull($row->timegraded, 'Control: still pending at course level.');
        $this->assertSame(
            marking_progress::WAIT_AGREED,
            marking_progress::for_rows([$row])[(int) $row->id]['wait'],
            'Control: the row waits for the manual agreed grade.'
        );
    }

    /**
     * A mark saved "In marking" is not the marker's completion, though 5.2
     * core already counts the row: the marker keeps seeing it until they set
     * "Marking completed" with a mark.
     *
     * @return void
     */
    public function test_a_marker_sees_it_until_their_own_mark_is_complete(): void {
        $env = $this->build_environment(2);
        [$a, $b] = $env->teachers;
        $this->allocate($env, $env->student, [$a, $b]);

        $this->mark($env, $a, 60, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);
        $this->assertTrue($this->listed($env, $a));

        $this->mark($env, $a, 60);
        $this->assertFalse($this->listed($env, $a));
    }

    /**
     * "Marking completed" saved with no mark is not a complete mark, as the
     * marker's own state reads it: the marker keeps the row.
     *
     * @return void
     */
    public function test_a_completed_state_without_a_mark_stays_listed(): void {
        global $DB;
        $env = $this->build_environment(2);
        [$a, $b] = $env->teachers;
        $this->allocate($env, $env->student, [$a, $b]);
        $this->mark($env, $a, 60);
        $this->assertFalse($this->listed($env, $a), 'Control: complete with a mark.');

        $DB->set_field('assign_mark', 'mark', null, ['marker' => $a->id]);
        $this->assertTrue($this->listed($env, $a));
        $DB->set_field('assign_mark', 'mark', -1, ['marker' => $a->id]);
        $this->assertTrue($this->listed($env, $a), 'A negative mark is no mark.');
    }

    /**
     * Once the agreed grade exists, the remaining wait (the release) belongs
     * to no marker: the marker still in marking loses the row too.
     *
     * @return void
     */
    public function test_an_agreed_grade_hides_it_from_every_marker(): void {
        $env = $this->build_environment(2);
        [$a, $b, $c] = $env->teachers;
        $this->allocate($env, $env->student, [$a, $b]);
        $this->mark($env, $a, 60);
        $this->mark($env, $b, 70, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);
        $this->assertTrue($this->listed($env, $b), 'Control: B is still marking.');

        $this->save_grade($env, $env->student, $c, 65.0);

        $this->assertNull($this->row($env)->timegraded, 'Control: still pending at course level, awaiting release.');
        $this->assertFalse($this->listed($env, $b));
        $this->assertFalse($this->listed($env, $a));
        $this->assertFalse($this->listed($env, $c));
    }

    /**
     * A single-marker activity with allocation on is listed only for its
     * allocated marker, until the grade exists.
     *
     * @return void
     */
    public function test_single_marker_allocated_activity_lists_only_its_marker(): void {
        $env = $this->build_environment(1);
        [$a, $b] = $env->teachers;
        $this->allocate($env, $env->student, [$a]);
        $this->assertFalse($env->assign->is_using_multiple_marking(), 'Control: core does not call this multiple marking.');

        $this->assertTrue($this->listed($env, $a));
        $this->assertFalse($this->listed($env, $b), 'A teacher who is not allocated does not see it.');

        $this->save_grade($env, $env->student, $a, 70.0);
        $this->assertNull($this->row($env)->timegraded, 'Control: still pending at course level, awaiting release.');
        $this->assertFalse($this->listed($env, $a), 'Graded: no marker waits on it.');
    }

    /**
     * An unallocated submission is the coordinator's queue: every teacher sees
     * it, on a multi-marker and on a single-marker activity, until someone is
     * allocated.
     *
     * @return void
     */
    public function test_an_unallocated_submission_is_listed_for_everyone(): void {
        foreach ([2, 1] as $required) {
            $env = $this->build_environment($required);
            foreach ($env->teachers as $teacher) {
                $this->assertTrue($this->listed($env, $teacher), "Unallocated, markercount $required.");
            }
            $this->allocate($env, $env->student, [$env->teachers[0]]);
            $this->assertFalse($this->listed($env, $env->teachers[1]), "Control: allocated, markercount $required.");
        }
    }

    /**
     * Control: on an activity without marker allocation every teacher sees the
     * row, even with an allocation row left behind from when it was on.
     *
     * @return void
     */
    public function test_an_activity_without_allocation_is_unchanged(): void {
        global $DB;
        $env = $this->build_environment(2, 0);
        [$a, $b] = $env->teachers;
        $DB->insert_record('assign_allocated_marker', (object) [
            'assignment' => $env->assign->get_instance()->id,
            'student' => $env->student->id,
            'marker' => $a->id,
        ]);

        $this->assertTrue($this->listed($env, $a));
        $this->assertTrue($this->listed($env, $b), 'Allocation is off: the left-over row restricts nothing.');
    }

    /**
     * The limit applies to the viewer's own queue, not to a list filtered
     * afterwards: rows hidden from the viewer do not use up the limit.
     *
     * @return void
     */
    public function test_limit_counts_only_the_viewers_rows(): void {
        $env = $this->build_environment(2);
        [$a, $b] = $env->teachers;
        $this->allocate($env, $env->student, [$a, $b]);
        // The hidden row waits longest; a limit of 1 must still return the visible one.
        $this->mark($env, $a, 60);
        $second = $this->add_student($env, 1.0);
        $this->allocate($env, $second, [$a]);

        $result = $this->run_as($a, 1);
        $this->assertSame(1, $result['returned']);
        $this->assertSame((int) $second->id, (int) $result['submissions'][0]['userid']);
    }

    /**
     * The SQL rule agrees with marking_progress::for_rows() across the states
     * a row can be in: listed for V exactly when the entry has no slot, or V
     * holds a slot whose state is not complete and no agreed grade exists.
     *
     * @return void
     */
    public function test_sql_agrees_with_for_rows(): void {
        $env = $this->build_environment(2);
        [$a, $b, $c, $d] = $env->teachers;
        $students = [
            'marking' => $env->student,
            'unallocated' => $this->add_student($env, 2.0),
            'agreed' => $this->add_student($env, 1.0),
        ];
        $this->allocate($env, $students['marking'], [$a, $b]);
        $this->mark($env, $a, 60);
        $this->mark($env, $b, 70, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);
        $this->allocate($env, $students['agreed'], [$c, $d]);
        $this->mark($env, $c, 60, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW, $students['agreed']);
        $this->save_grade($env, $students['agreed'], $a, 60.0);

        $seen = [];
        foreach ($students as $label => $student) {
            $row = $this->row($env, $student);
            $progress = marking_progress::for_rows([$row])[(int) $row->id];
            foreach ([$a, $b, $c, $d] as $teacher) {
                $expected = empty($progress['slots']);
                foreach ($progress['slots'] as $slot) {
                    if (
                        $slot['marker'] === (int) $teacher->id
                        && $slot['state'] !== marking_progress::MARK_COMPLETE
                        && $progress['wait'] !== marking_progress::WAIT_NONE
                    ) {
                        $expected = true;
                    }
                }
                $seen[$label][] = $expected;
                $this->assertSame($expected, $this->listed($env, $teacher, $student), "$label, teacher {$teacher->id}.");
            }
        }
        $this->assertSame([
            'marking' => [false, true, false, false],
            'unallocated' => [true, true, true, true],
            'agreed' => [false, false, false, false],
        ], $seen, 'Control: the fixture covers every arm.');
    }

    /**
     * Build a course with one assign under marking workflow, one submitted
     * student with a ledger row and four editing teachers.
     *
     * Core's generator leaves markercount at 0, so it is always set here.
     *
     * @param int $required The activity's markercount.
     * @param int $allocation The activity's markingallocation.
     * @return \stdClass course, cm, assign, student, teachers.
     */
    private function build_environment(int $required, int $allocation = 1): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->setAdminUser();
        set_config('enablebusinesshours', 0, 'block_feedback_tracker');
        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');
        set_config('release_stops_clock', 1, 'block_feedback_tracker');

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
     * Allocate markers to a student through core.
     *
     * @param \stdClass $env
     * @param \stdClass $student
     * @param array $markers User records, in position order.
     * @return void
     */
    private function allocate(\stdClass $env, \stdClass $student, array $markers): void {
        $env->assign->update_allocated_markers((int) $student->id, array_map(fn($m) => (int) $m->id, $markers));
    }

    /**
     * Record one marker's mark, as the marker's own grading form does.
     *
     * @param \stdClass $env
     * @param \stdClass $marker
     * @param float $mark
     * @param string $state The marker's own workflow state; "Marking completed" by default.
     * @param \stdClass|null $student The student; the environment's first by default.
     * @return void
     */
    private function mark(
        \stdClass $env,
        \stdClass $marker,
        float $mark,
        string $state = 'readyforreview',
        ?\stdClass $student = null
    ): void {
        $grade = $env->assign->get_user_grade((int) ($student ?? $env->student)->id, true);
        $grade->grader = (int) $marker->id;
        $env->assign->update_mark($grade, $mark, $state);
    }

    /**
     * Save the final grade as a teacher, the way apply_grade_to_user() ends:
     * grader set to the saving user, then update_grade().
     *
     * @param \stdClass $env
     * @param \stdClass $student
     * @param \stdClass $teacher
     * @param float $value
     * @return void
     */
    private function save_grade(\stdClass $env, \stdClass $student, \stdClass $teacher, float $value): void {
        $grade = $env->assign->get_user_grade((int) $student->id, true);
        $grade->grade = $value;
        $grade->grader = (int) $teacher->id;
        $this->assertTrue($env->assign->update_grade($grade));
    }

    /**
     * A student's current ledger row.
     *
     * @param \stdClass $env
     * @param \stdClass|null $student The environment's first student by default.
     * @return \stdClass
     */
    private function row(\stdClass $env, ?\stdClass $student = null): \stdClass {
        global $DB;
        return $DB->get_record(
            'block_feedback_tracker_sub',
            ['cmid' => $env->cm->id, 'userid' => ($student ?? $env->student)->id, 'iscurrent' => 1],
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
     * Whether a student's row is on this teacher's Grade Now list.
     *
     * @param \stdClass $env
     * @param \stdClass $teacher
     * @param \stdClass|null $student The environment's first student by default.
     * @return bool
     */
    private function listed(\stdClass $env, \stdClass $teacher, ?\stdClass $student = null): bool {
        $studentid = (int) ($student ?? $env->student)->id;
        foreach ($this->run_as($teacher)['submissions'] as $s) {
            if ((int) $s['userid'] === $studentid) {
                return true;
            }
        }
        return false;
    }
}
