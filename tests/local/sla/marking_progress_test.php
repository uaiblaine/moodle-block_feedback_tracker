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
 * Tests for the multi-marker progress reader.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Multi-marker progress on Moodle 5.2, driven through mod_assign's own API.
 *
 * Every allocation goes through assign::update_allocated_markers() and every
 * mark through assign::update_mark(), so the rows read are the ones core
 * writes, and each expectation is checked against core's own answer where core
 * offers one (get_allocated_markers(), get_marks(), the agreed grade itself).
 *
 * The class-level covers docblock is kept, not attributes: this branch family
 * still supports Moodle 4.5, whose moodle-cs leg cannot read attributes.
 *
 * @covers \block_feedback_tracker\local\sla\marking_progress
 */
final class marking_progress_test extends \advanced_testcase {
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
     * Two allocated markers, neither started: 0 of 2, waiting for markers.
     *
     * @return void
     */
    public function test_no_mark_yet(): void {
        $env = $this->build_environment('average');

        $p = $this->progress($env);
        $this->assertSame(2, $p['expected']);
        $this->assertSame(0, $p['completed']);
        $this->assertSame(marking_progress::WAIT_MARKERS, $p['wait']);
        $this->assertSame('average', $p['method']);
        $this->assertSame(
            [
                [
                    'marker' => (int) $env->markers[0]->id,
                    'optional' => 0,
                    'enabled' => 0,
                    'position' => 1,
                    'state' => marking_progress::MARK_NONE,
                ],
                [
                    'marker' => (int) $env->markers[1]->id,
                    'optional' => 0,
                    'enabled' => 0,
                    'position' => 2,
                    'state' => marking_progress::MARK_NONE,
                ],
            ],
            $p['slots']
        );
    }

    /**
     * Positions follow the allocation rows in core's order, not the user ids:
     * a marker allocated first holds position 1 whatever their id.
     *
     * @return void
     */
    public function test_positions_follow_the_allocation_order(): void {
        $env = $this->build_environment('average', 0);
        [$lower, $higher] = $env->markers;
        $this->assertLessThan((int) $higher->id, (int) $lower->id, 'Control: the ids are ordered.');

        $env->assign->update_allocated_markers((int) $env->student->id, [(int) $higher->id, (int) $lower->id]);

        $p = $this->progress($env);
        $this->assertSame(
            array_map('intval', array_column($env->assign->get_allocated_markers((int) $env->student->id), 'marker')),
            array_column($p['slots'], 'marker')
        );
        $this->assertSame([(int) $higher->id, (int) $lower->id], array_column($p['slots'], 'marker'));
        $this->assertSame([1, 2], array_column($p['slots'], 'position'));
    }

    /**
     * One mark in and one marker not started: 1 of 2.
     *
     * @return void
     */
    public function test_one_of_two(): void {
        $env = $this->build_environment('average');
        $this->mark($env, $env->markers[0], 60);

        $p = $this->progress($env);
        $this->assertSame(2, $p['expected']);
        $this->assertSame(1, $p['completed']);
        $this->assertSame(marking_progress::WAIT_MARKERS, $p['wait']);
        $this->assertSame(
            [marking_progress::MARK_COMPLETE, marking_progress::MARK_NONE],
            array_column($p['slots'], 'state')
        );
    }

    /**
     * Moodle 5.2 counts a mark row whatever the marker's own state: a mark
     * saved "In marking" completes the count, and core computes the average at
     * once. The slot still shows as in marking.
     *
     * @return void
     */
    public function test_a_mark_in_marking_counts_as_completed(): void {
        $env = $this->build_environment('average');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 70, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);
        $this->assertEquals(65, $this->grade($env)->grade, 'Control: core agreed with a mark still in marking.');

        $p = $this->progress($env);
        $this->assertSame(2, $p['completed']);
        $this->assertSame(marking_progress::WAIT_NONE, $p['wait']);
        $this->assertSame(
            [marking_progress::MARK_COMPLETE, marking_progress::MARK_INMARKING],
            array_column($p['slots'], 'state')
        );
    }

    /**
     * A mark row holding no mark at all (a marker who only moved their own
     * workflow state) is counted too, as get_marks() counts it, so under the
     * manual method the wait is the agreed grade.
     *
     * @return void
     */
    public function test_a_row_without_a_mark_counts_as_completed(): void {
        $env = $this->build_environment('manual');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], null, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);
        $grade = $this->grade($env);
        $this->assertCount(
            2,
            $env->assign->get_marks((int) $grade->id, (int) $env->student->id),
            'Control: core counts the empty row.'
        );

        $p = $this->progress($env);
        $this->assertSame(2, $p['completed']);
        $this->assertSame(marking_progress::WAIT_AGREED, $p['wait']);
        $this->assertSame(marking_progress::MARK_INMARKING, $p['slots'][1]['state']);
    }

    /**
     * Under the average method core computes the agreed grade on the second
     * mark, so nothing is waited for.
     *
     * @return void
     */
    public function test_two_of_two_average_has_the_agreed_grade(): void {
        $env = $this->build_environment('average');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 80);
        $this->assertEquals(70, $this->grade($env)->grade, 'Control: core agreed.');

        $p = $this->progress($env);
        $this->assertSame(2, $p['completed']);
        $this->assertSame(marking_progress::WAIT_NONE, $p['wait']);
    }

    /**
     * Under the manual method every mark in still waits for whoever confirms
     * the agreed grade.
     *
     * @return void
     */
    public function test_two_of_two_manual_waits_for_the_agreed_grade(): void {
        $env = $this->build_environment('manual');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 80);
        $grade = $this->grade($env);
        $this->assertTrue($grade->grade === null || $grade->grade < 0, 'Control: manual computes nothing.');

        $p = $this->progress($env);
        $this->assertSame(2, $p['expected']);
        $this->assertSame(2, $p['completed']);
        $this->assertSame(marking_progress::WAIT_AGREED, $p['wait']);
    }

    /**
     * Fewer markers allocated than markercount is the coordinator's wait, even
     * while the allocated marker has marked.
     *
     * @return void
     */
    public function test_missing_marker_waits_for_allocation(): void {
        $env = $this->build_environment('average', 1);
        $this->mark($env, $env->markers[0], 60);

        $p = $this->progress($env);
        $this->assertSame(2, $p['expected']);
        $this->assertSame(1, $p['completed']);
        $this->assertCount(1, $p['slots']);
        $this->assertSame(marking_progress::WAIT_ALLOCATION, $p['wait']);

        $env->assign->update_allocated_markers(
            (int) $env->student->id,
            [(int) $env->markers[0]->id, (int) $env->markers[1]->id]
        );
        $p = $this->progress($env);
        $this->assertSame(1, $p['completed'], 'Control: the first mark is still counted.');
        $this->assertSame(
            marking_progress::WAIT_MARKERS,
            $p['wait'],
            'Control: allocating the second marker hands the wait to the markers.'
        );
    }

    /**
     * A marker replaced after marking keeps their mark row, which core no
     * longer reads, so it no longer counts.
     *
     * @return void
     */
    public function test_a_replaced_markers_mark_is_not_counted(): void {
        global $DB;
        $env = $this->build_environment('manual');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 80);
        $this->assertSame(2, $this->progress($env)['completed'], 'Control: both marks count.');

        $other = $this->getDataGenerator()->create_and_enrol($env->course, 'editingteacher');
        $env->assign->update_allocated_markers((int) $env->student->id, [(int) $env->markers[0]->id, (int) $other->id]);

        $grade = $this->grade($env);
        $this->assertTrue(
            $DB->record_exists('assign_mark', ['gradeid' => $grade->id, 'marker' => $env->markers[1]->id]),
            'Control: core keeps the mark.'
        );
        $this->assertCount(1, $env->assign->get_marks((int) $grade->id, (int) $env->student->id));
        $p = $this->progress($env);
        $this->assertSame(1, $p['completed']);
        $this->assertSame((int) $other->id, $p['slots'][1]['marker']);
        $this->assertSame(marking_progress::WAIT_MARKERS, $p['wait']);
    }

    /**
     * A single-marker activity, an activity without marker allocation, and a
     * row with no student, get no entry.
     *
     * @return void
     */
    public function test_rows_outside_multiple_marking_get_no_entry(): void {
        global $DB;
        $env = $this->build_environment('average');
        $multirow = $this->row($env);

        $single = $this->getDataGenerator()->create_module('assign', [
            'course' => $env->course->id,
            'markingworkflow' => 1,
            'markingallocation' => 1,
            'markercount' => 1,
        ]);
        $singlerow = (object) ['id' => -1, 'iteminstance' => $single->id, 'userid' => $env->student->id, 'attemptnumber' => 0];
        $teamrow = (object) ['id' => -2, 'iteminstance' => $multirow->iteminstance, 'userid' => 0, 'attemptnumber' => 0];

        $out = marking_progress::for_rows([$multirow, $singlerow, $teamrow]);
        $this->assertSame([(int) $multirow->id], array_keys($out), 'Control: the multi-marking row has its entry.');

        $DB->set_field('assign', 'markingallocation', 0, ['id' => $multirow->iteminstance]);
        $this->assertSame([], marking_progress::for_rows([$multirow]));
        $this->assertSame([], marking_progress::for_rows([]));
    }

    /**
     * Build a processable course with a two-marker assign, one submitted
     * student with a ledger row, and some markers allocated in one call.
     *
     * Core's generator leaves markercount at 0, so it is always set here.
     *
     * @param string $method The multimarkmethod.
     * @param int $allocated How many of the two markers are allocated, in creation order.
     * @return \stdClass course, cm, assign, student and markers.
     */
    private function build_environment(string $method, int $allocated = 2): \stdClass {
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
        $ids = array_map(fn($m) => (int) $m->id, array_slice($env->markers, 0, $allocated));
        if (!empty($ids)) {
            $assign->update_allocated_markers((int) $student->id, $ids);
        }
        return $env;
    }

    /**
     * Record one marker's mark, as the marker's own grading form does.
     *
     * @param \stdClass $env
     * @param \stdClass $marker
     * @param float|null $mark Null saves a row with no mark, as a state-only change does.
     * @param string $state The marker's own workflow state; "Marking completed" by default.
     * @return void
     */
    private function mark(\stdClass $env, \stdClass $marker, ?float $mark, string $state = 'readyforreview'): void {
        $grade = $env->assign->get_user_grade((int) $env->student->id, true);
        $grade->grader = (int) $marker->id;
        $env->assign->update_mark($grade, $mark, $state);
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
     * The progress entry of the student's current ledger row.
     *
     * @param \stdClass $env
     * @return array
     */
    private function progress(\stdClass $env): array {
        $row = $this->row($env);
        $out = marking_progress::for_rows([$row]);
        $this->assertArrayHasKey((int) $row->id, $out);
        return $out[(int) $row->id];
    }
}
