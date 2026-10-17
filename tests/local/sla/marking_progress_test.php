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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Multi-marker progress on Moodle 5.3, driven through mod_assign's own API.
 *
 * Every allocation goes through assign::update_marker_allocations() and every
 * mark through assign::update_mark(), so the rows read are the ones core
 * writes, and each expectation is checked against core's own answer where core
 * offers one (expected_marker_count(), get_marker_allocations()).
 */
#[CoversClass(marking_progress::class)]
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
     * One mark completed and one in marking: 1 of 2. A mark saved without
     * "Marking completed" has started but does not count.
     *
     * @return void
     */
    public function test_one_of_two(): void {
        $env = $this->build_environment('average');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 70, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);

        $p = $this->progress($env);
        $this->assertSame(2, $p['expected']);
        $this->assertSame(1, $p['completed']);
        $this->assertSame(marking_progress::WAIT_MARKERS, $p['wait']);
        $this->assertSame(
            [marking_progress::MARK_COMPLETE, marking_progress::MARK_INMARKING],
            array_column($p['slots'], 'state')
        );
    }

    /**
     * Under the average method core computes the agreed grade on the second
     * completed mark, so nothing is waited for.
     *
     * @return void
     */
    public function test_two_of_two_average_has_the_agreed_grade(): void {
        $env = $this->build_environment('average');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 80);
        $this->assertEquals(70, $env->assign->get_user_grade((int) $env->student->id, false)->grade, 'Control: core agreed.');

        $p = $this->progress($env);
        $this->assertSame(2, $p['completed']);
        $this->assertSame(marking_progress::WAIT_NONE, $p['wait']);
    }

    /**
     * Under the manual method every mark complete still waits for whoever
     * confirms the agreed grade.
     *
     * @return void
     */
    public function test_two_of_two_manual_waits_for_the_agreed_grade(): void {
        $env = $this->build_environment('manual');
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 80);
        $grade = $env->assign->get_user_grade((int) $env->student->id, false);
        $this->assertTrue($grade->grade === null || $grade->grade < 0, 'Control: manual computes nothing.');

        $p = $this->progress($env);
        $this->assertSame(2, $p['expected']);
        $this->assertSame(2, $p['completed']);
        $this->assertSame(marking_progress::WAIT_AGREED, $p['wait']);
    }

    /**
     * Enabling an optional slot with a marker raises the expected count from 2
     * to 3 and lists the slot at position 3; core clears the agreed grade it
     * had computed, so the student waits for the third mark.
     *
     * @return void
     */
    public function test_enabled_optional_marker_is_expected(): void {
        $env = $this->build_environment('average', 1);
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $env->markers[1], 80);
        $this->assertSame(2, $this->progress($env)['expected'], 'Control: the optional slot is not expected yet.');
        $this->assertSame(marking_progress::WAIT_NONE, $this->progress($env)['wait']);

        $extra = $this->create_marker($env);
        $env->assign->update_marker_allocations((int) $env->student->id, [3 => [(int) $extra->id, 1]]);

        $p = $this->progress($env);
        $this->assertSame($env->assign->expected_marker_count((int) $env->student->id), $p['expected']);
        $this->assertSame(3, $p['expected']);
        $this->assertSame(2, $p['completed']);
        $this->assertSame(marking_progress::WAIT_MARKERS, $p['wait']);
        $this->assertSame(
            [
                'marker' => (int) $extra->id,
                'optional' => 1,
                'enabled' => 1,
                'position' => 3,
                'state' => marking_progress::MARK_NONE,
            ],
            $p['slots'][2]
        );
    }

    /**
     * An optional slot enabled before anyone is allocated to it is expected
     * (core counts the placeholder) but listed as no slot, since nobody holds it,
     * so the wait is the coordinator's: nobody can complete that mark.
     *
     * @return void
     */
    public function test_enabled_placeholder_is_expected_but_not_a_slot(): void {
        global $DB;
        $env = $this->build_environment('average', 1);
        $env->assign->update_marker_allocations((int) $env->student->id, [3 => [0, 1]]);
        $this->assertTrue(
            $DB->record_exists('assign_allocated_marker', [
                'assignment' => $env->assign->get_instance()->id,
                'student' => $env->student->id,
                'marker' => 0,
                'enabled' => 1,
            ]),
            'Control: core stored the placeholder.'
        );

        $p = $this->progress($env);
        $this->assertSame($env->assign->expected_marker_count((int) $env->student->id), $p['expected']);
        $this->assertSame(3, $p['expected']);
        $this->assertCount(2, $p['slots']);
        $this->assertSame(
            array_keys($env->assign->get_marker_allocations((int) $env->student->id, false)),
            array_column($p['slots'], 'position')
        );
        $this->assertSame(marking_progress::WAIT_ALLOCATION, $p['wait']);
    }

    /**
     * Disabling the optional slot again drops the expected count back to 2,
     * and the optional marker's mark, still stored, no longer counts.
     *
     * @return void
     */
    public function test_disabled_optional_marker_is_not_counted(): void {
        global $DB;
        $env = $this->build_environment('average', 1);
        $extra = $this->create_marker($env);
        $env->assign->update_marker_allocations((int) $env->student->id, [3 => [(int) $extra->id, 1]]);
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $extra, 90);
        $before = $this->progress($env);
        $this->assertSame(3, $before['expected']);
        $this->assertSame(2, $before['completed'], 'Control: the optional mark counts while the slot is enabled.');

        $env->assign->update_marker_allocations((int) $env->student->id, [3 => [(int) $extra->id, 0]]);

        $gradeid = $env->assign->get_user_grade((int) $env->student->id, false)->id;
        $this->assertTrue(
            $DB->record_exists('assign_mark', ['gradeid' => $gradeid, 'marker' => $extra->id]),
            'Control: core keeps the mark.'
        );
        $p = $this->progress($env);
        $this->assertSame(2, $p['expected']);
        $this->assertSame(1, $p['completed']);
        $this->assertCount(2, $p['slots']);
        $this->assertSame(marking_progress::WAIT_MARKERS, $p['wait']);
    }

    /**
     * A required slot with no marker is the coordinator's wait.
     *
     * @return void
     */
    public function test_empty_required_slot_waits_for_allocation(): void {
        $env = $this->build_environment('average', 0, 1);
        $this->mark($env, $env->markers[0], 60);

        $p = $this->progress($env);
        $this->assertSame(2, $p['expected']);
        $this->assertSame(1, $p['completed']);
        $this->assertCount(1, $p['slots']);
        $this->assertSame(marking_progress::WAIT_ALLOCATION, $p['wait']);

        $env->assign->update_marker_allocations((int) $env->student->id, [2 => [(int) $env->markers[1]->id, null]]);
        $this->assertSame(
            marking_progress::WAIT_MARKERS,
            $this->progress($env)['wait'],
            'Control: allocating the slot hands the wait to the marker.'
        );
    }

    /**
     * With no optional slot configured core expects the required count and
     * reads no allocation row, so an optional row left behind does not count.
     *
     * Core refuses to lower the optional count while such a row exists
     * ({@see \assign::can_change_marker_count()}), so the state is written
     * straight into the activity row.
     *
     * @return void
     */
    public function test_no_optional_slot_configured_ignores_optional_rows(): void {
        global $DB;
        $env = $this->build_environment('average', 1);
        $extra = $this->create_marker($env);
        $env->assign->update_marker_allocations((int) $env->student->id, [3 => [(int) $extra->id, 1]]);
        $this->assertSame(3, $this->progress($env)['expected'], 'Control: the optional slot is expected.');

        $DB->set_field('assign', 'optionalmarkercount', 0, ['id' => $env->assign->get_instance()->id]);

        $this->assertSame(2, $this->progress($env)['expected']);
    }

    /**
     * When the completed marks reach the expected count, core can determine
     * the agreed grade ({@see \assign::all_markers_marked()}) even with a
     * required slot empty, so the wait is the agreed grade, not the allocation.
     *
     * Under core's own writes the completed marks cannot reach the expected
     * count while a required slot is empty, so the state is reached as in the
     * test above: an optional row left behind once no optional slot is configured.
     *
     * @return void
     */
    public function test_agreed_takes_precedence_over_an_empty_required_slot(): void {
        global $DB;
        $env = $this->build_environment('manual', 1, 1);
        $extra = $this->create_marker($env);
        $env->assign->update_marker_allocations((int) $env->student->id, [3 => [(int) $extra->id, 1]]);
        $this->mark($env, $env->markers[0], 60);
        $this->mark($env, $extra, 80);
        $DB->set_field('assign', 'optionalmarkercount', 0, ['id' => $env->assign->get_instance()->id]);
        $env->assign = new \assign(\context_module::instance($env->cm->id), $env->cm, $env->course);
        $gradeid = (int) $env->assign->get_user_grade((int) $env->student->id, false)->id;
        $this->assertTrue(
            $env->assign->all_markers_marked($gradeid, (int) $env->student->id),
            'Control: core can determine the agreed grade.'
        );

        $p = $this->progress($env);
        $this->assertSame(2, $p['expected']);
        $this->assertSame(2, $p['completed']);
        $this->assertSame(marking_progress::WAIT_AGREED, $p['wait']);
    }

    /**
     * A single-marker activity, and a row with no student, get no entry.
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
            'optionalmarkercount' => 0,
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
     * Build a processable course with a multi-marker assign, one submitted
     * student with a ledger row, and some of the required markers allocated.
     *
     * Core's generator leaves markercount at 0, so it is always set here.
     *
     * @param string $method The multimarkmethod.
     * @param int $optional Number of optional marker slots.
     * @param int $allocated How many of the two required slots get a marker.
     * @return \stdClass course, cm, assign, student and markers.
     */
    private function build_environment(string $method, int $optional = 0, int $allocated = 2): \stdClass {
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
        $allocations = [];
        for ($i = 0; $i < $allocated; $i++) {
            $allocations[$i + 1] = [(int) $env->markers[$i]->id, null];
        }
        $assign->update_marker_allocations((int) $student->id, $allocations);
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
