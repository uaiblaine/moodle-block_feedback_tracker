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
 * Tests for the drill-down's per-marker figures.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\task\reconcile_ledger;

/**
 * The Markers section: shown only to a viewer who may manage allocations, and
 * built from the slot rows of the page's scope.
 *
 * The slot rows are written by core's own API (allocations and marks) and, for
 * what Moodle 5.2 reports no event for (a completion, a removal), by the
 * reconcile sweep; then the stored measures and sources are set directly, so
 * the medians and coverage are known numbers and no assertion rests on the
 * clock.
 *
 * The class-level covers docblock is kept, not attributes: this branch family
 * still supports Moodle 4.5, whose moodle-cs leg cannot read attributes.
 *
 * @covers \block_feedback_tracker\local\sla\marker_figures
 */
final class marker_figures_test extends \advanced_testcase {
    /**
     * Flush per-request statics that survive resetAfterTest().
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        submission_ledger::reset_memos();
        group_resolver::reset_memo();
        group_access::reset_memo();
        course_access::reset_memo();
    }

    /**
     * A teacher without mod/assign:manageallocations does not get the section;
     * an editing teacher, who has it, does.
     *
     * @return void
     */
    public function test_hidden_without_the_capability(): void {
        $env = $this->build_environment(2);
        $teacher = $this->getDataGenerator()->create_and_enrol($env->course, 'teacher');
        $this->assertFalse(
            has_capability('mod/assign:manageallocations', \context_course::instance($env->course->id), $teacher),
            'Control: the non-editing teacher lacks the capability.'
        );

        $this->assertNull(marker_figures::for_page((int) $env->course->id, 0, (int) $teacher->id, false));
        $this->assertNotNull(marker_figures::for_page((int) $env->course->id, 0, (int) $env->coordinator->id, false));
    }

    /**
     * A course with no multi-marking work shows no section, even to a viewer
     * allowed to see it.
     *
     * @return void
     */
    public function test_hidden_without_slot_rows(): void {
        global $DB;
        $env = $this->build_environment(1);
        $DB->delete_records('block_feedback_tracker_alloc');

        $this->assertNull(marker_figures::for_page((int) $env->course->id, 0, (int) $env->coordinator->id, false));
    }

    /**
     * Open slots, completed marks, the median of the marker's own turnaround
     * and the coverage, per marker.
     *
     * Three students. Marker A completes all three (8, 2 and 5 hours; one
     * reconciled allocation), marker B completes one (4 hours) and has two
     * open slots. A's median is 5.0, coverage 2 of 3; B's median 4.0,
     * coverage 1 of 1.
     *
     * @return void
     */
    public function test_counts_medians_and_coverage(): void {
        global $DB;
        $env = $this->build_environment(3);
        [$a, $b] = $env->markers;
        foreach ($env->students as $i => $student) {
            $this->mark($env, $student, $a);
            if ($i === 0) {
                $this->mark($env, $student, $b);
            }
        }
        $hours = [[8.0, 4.0], [2.0, null], [5.0, null]];
        foreach ($env->students as $i => $student) {
            $DB->set_field('block_feedback_tracker_alloc', 'markhours', $hours[$i][0], [
                'userid' => $student->id,
                'marker' => $a->id,
            ]);
            if ($hours[$i][1] !== null) {
                $DB->set_field('block_feedback_tracker_alloc', 'markhours', $hours[$i][1], [
                    'userid' => $student->id,
                    'marker' => $b->id,
                ]);
            }
        }
        $DB->set_field('block_feedback_tracker_alloc', 'allocsource', slot_ledger::SOURCE_RECONCILED, [
            'userid' => $env->students[1]->id,
            'marker' => $a->id,
        ]);

        $rows = $this->by_marker(marker_figures::for_page((int) $env->course->id, 0, (int) $env->coordinator->id, false));

        $this->assertSame(0, $rows[(int) $a->id]['open']);
        $this->assertSame(3, $rows[(int) $a->id]['completed']);
        $this->assertEqualsWithDelta(5.0, $rows[(int) $a->id]['median'], 0.001);
        $this->assertSame(67, $rows[(int) $a->id]['coverage']);
        $this->assertSame(2, $rows[(int) $b->id]['open']);
        $this->assertSame(1, $rows[(int) $b->id]['completed']);
        $this->assertEqualsWithDelta(4.0, $rows[(int) $b->id]['median'], 0.001);
        $this->assertSame(100, $rows[(int) $b->id]['coverage']);
        $this->assertSame(fullname($a), $rows[(int) $a->id]['name']);
    }

    /**
     * Under the business-days unit the median is the stored day count, not
     * hours divided.
     *
     * @return void
     */
    public function test_days_unit_reads_the_stored_day_count(): void {
        global $DB;
        $env = $this->build_environment(1);
        $a = $env->markers[0];
        $this->mark($env, $env->students[0], $a);
        $DB->set_field('block_feedback_tracker_alloc', 'markhours', 30.0, ['marker' => $a->id]);
        $DB->set_field('block_feedback_tracker_alloc', 'markdays', 2, ['marker' => $a->id]);

        $rows = $this->by_marker(marker_figures::for_page((int) $env->course->id, 0, (int) $env->coordinator->id, true));

        $this->assertEqualsWithDelta(2.0, $rows[(int) $a->id]['median'], 0.001);
        $this->assertSame(get_string('drilldown_value_days', 'block_feedback_tracker', '2'), $rows[(int) $a->id]['mediantext']);
    }

    /**
     * A removed slot is not open, and a slot on a cycle already answered is
     * not open either.
     *
     * @return void
     */
    public function test_removed_and_answered_slots_are_not_open(): void {
        global $DB;
        $env = $this->build_environment(2);
        $b = $env->markers[1];
        $rows = $this->by_marker(marker_figures::for_page((int) $env->course->id, 0, (int) $env->coordinator->id, false));
        $this->assertSame(2, $rows[(int) $b->id]['open'], 'Control: two open slots.');

        $env->assign->update_allocated_markers((int) $env->students[0]->id, [(int) $env->markers[0]->id]);
        $this->sweep($env);
        $DB->set_field('block_feedback_tracker_sub', 'timegraded', time(), ['userid' => $env->students[1]->id]);

        $rows = $this->by_marker(marker_figures::for_page((int) $env->course->id, 0, (int) $env->coordinator->id, false));
        $this->assertSame(0, $rows[(int) $b->id]['open']);
    }

    /**
     * A module-level prohibit of the capability hides that activity's markers
     * from the viewer; the other activity still shows (control).
     *
     * @return void
     */
    public function test_module_level_prohibit_hides_that_activity(): void {
        global $DB;
        $env = $this->build_environment(1);
        $second = $this->add_activity($env);
        $viewer = (int) $env->coordinator->id;
        $before = $this->by_marker(marker_figures::for_page((int) $env->course->id, 0, $viewer, false));
        $this->assertArrayHasKey((int) $env->markers[0]->id, $before, 'Control: the first activity shows.');
        $this->assertArrayHasKey((int) $second->markers[0]->id, $before, 'Control: the second activity shows.');

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('mod/assign:manageallocations', CAP_PROHIBIT, $roleid, $second->context->id, true);

        $after = $this->by_marker(marker_figures::for_page((int) $env->course->id, 0, $viewer, false));
        $this->assertArrayHasKey((int) $env->markers[0]->id, $after);
        $this->assertArrayNotHasKey((int) $second->markers[0]->id, $after);
        $this->assertArrayNotHasKey((int) $second->markers[1]->id, $after);
    }

    /**
     * A hidden activity is not reported to a viewer who cannot see it, even
     * though the viewer holds the capability.
     *
     * @return void
     */
    public function test_hidden_activity_is_not_reported(): void {
        $env = $this->build_environment(1);
        $second = $this->add_activity($env);
        $viewer = $this->create_grader($env->course, true, false);
        $this->assertArrayHasKey(
            (int) $second->markers[0]->id,
            $this->by_marker(marker_figures::for_page((int) $env->course->id, 0, (int) $viewer->id, false)),
            'Control: visible activities are reported.'
        );

        set_coursemodule_visible((int) $second->cm->id, 0);
        $rows = $this->by_marker(marker_figures::for_page((int) $env->course->id, 0, (int) $viewer->id, false));

        $this->assertArrayNotHasKey((int) $second->markers[0]->id, $rows);
        $this->assertArrayHasKey((int) $env->markers[0]->id, $rows);
    }

    /**
     * A viewer granted the capability at one module only sees that module's
     * figures.
     *
     * @return void
     */
    public function test_grant_on_one_module_shows_that_module_only(): void {
        $env = $this->build_environment(1);
        $second = $this->add_activity($env);
        $viewer = $this->create_grader($env->course, false, true, $second->context);

        $rows = $this->by_marker(marker_figures::for_page((int) $env->course->id, 0, (int) $viewer->id, false));

        $this->assertArrayHasKey((int) $second->markers[0]->id, $rows);
        $this->assertArrayNotHasKey((int) $env->markers[0]->id, $rows);
    }

    /**
     * A separate-groups viewer who is in no group sees no rows.
     *
     * @return void
     */
    public function test_separate_groups_viewer_outside_every_group_sees_nothing(): void {
        $env = $this->build_environment(1);
        $this->getDataGenerator()->create_group(['courseid' => $env->course->id]);
        update_course((object) ['id' => $env->course->id, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $viewer = $this->create_grader($env->course, true, false);
        group_access::reset_memo();

        $this->assertSame([], group_access::visible_group_ids((int) $env->course->id, (int) $viewer->id), 'Control.');
        $this->assertNull(marker_figures::for_page((int) $env->course->id, 0, (int) $viewer->id, false));
    }

    /**
     * Enrol a user under a new role that holds manageallocations at the course
     * or at one module, and optionally lacks the right to see hidden activities.
     *
     * @param \stdClass $course
     * @param bool $atcourse Grant the capability in the course context.
     * @param bool $canviewhidden Whether the role may see hidden activities.
     * @param \context|null $module Grant the capability here instead of the course.
     * @return \stdClass The enrolled user.
     */
    private function create_grader(\stdClass $course, bool $atcourse, bool $canviewhidden, ?\context $module = null): \stdClass {
        static $n = 0;
        $n++;
        $roleid = create_role('Grader ' . $n, 'grader' . $n, '');
        $ctx = $atcourse ? \context_course::instance($course->id) : $module;
        assign_capability('mod/assign:manageallocations', CAP_ALLOW, $roleid, $ctx->id, true);
        if ($canviewhidden) {
            $coursectx = \context_course::instance($course->id);
            assign_capability('moodle/course:viewhiddenactivities', CAP_ALLOW, $roleid, $coursectx->id, true);
        }
        assign_capability('mod/assign:view', CAP_ALLOW, $roleid, \context_course::instance($course->id)->id, true);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);
        return $user;
    }

    /**
     * Add a second multi-marking assign to the environment's course, with its
     * own two markers and one submitted student.
     *
     * @param \stdClass $env
     * @return \stdClass cm, context and markers.
     */
    private function add_activity(\stdClass $env): \stdClass {
        global $DB;
        $course = $env->course;
        $inst = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'markingworkflow' => 1,
            'markingallocation' => 1,
            'markercount' => 2,
            'multimarkmethod' => 'maximum',
            'grade' => 100,
        ]);
        $cm = get_coursemodule_from_instance('assign', $inst->id);
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);
        $markers = [
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher'),
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher'),
        ];
        $student = $env->students[0];
        $t1 = time() - 3 * DAYSECS;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $inst->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $assign->update_allocated_markers((int) $student->id, [(int) $markers[0]->id, (int) $markers[1]->id]);
        return (object) ['cm' => $cm, 'context' => $context, 'markers' => $markers];
    }

    /**
     * Key the section's rows by marker id.
     *
     * @param array|null $rows
     * @return array
     */
    private function by_marker(?array $rows): array {
        $this->assertNotNull($rows);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['markerid']] = $r;
        }
        return $out;
    }

    /**
     * Complete one marker's mark for a student through core, then let the
     * reconcile sweep record it, as it does on 5.2.
     *
     * @param \stdClass $env
     * @param \stdClass $student
     * @param \stdClass $marker
     * @return void
     */
    private function mark(\stdClass $env, \stdClass $student, \stdClass $marker): void {
        $grade = $env->assign->get_user_grade((int) $student->id, true);
        $grade->grader = (int) $marker->id;
        $env->assign->update_mark($grade, 70, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        $this->sweep($env);
    }

    /**
     * Run the reconcile sweep over the slot table of the environment's course.
     *
     * @param \stdClass $env
     * @return void
     */
    private function sweep(\stdClass $env): void {
        $task = new reconcile_ledger();
        (new \ReflectionProperty($task, 'sweepdeadline'))->setValue($task, time() + 60);
        (new \ReflectionMethod($task, 'sweep_marker_slots'))->invoke($task, [(int) $env->course->id], 500, 'slots');
        set_config('reconcile_cursor_slots', 0, 'block_feedback_tracker');
    }

    /**
     * A processable course with a two-marker assign under the maximum method
     * (no agreed grade closes a cycle before the test says so: maximum needs
     * both marks), students handed in with both markers allocated, and an
     * editing teacher as the viewer.
     *
     * @param int $students Number of students.
     * @return \stdClass course, assign, students, markers and coordinator.
     */
    private function build_environment(int $students): \stdClass {
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
        $inst = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'markingworkflow' => 1,
            'markingallocation' => 1,
            'markercount' => 2,
            'multimarkmethod' => 'maximum',
            'grade' => 100,
        ]);
        $cm = get_coursemodule_from_instance('assign', $inst->id);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $env = (object) ['course' => $course, 'assign' => $assign, 'students' => []];
        $env->markers = [
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher', ['firstname' => 'Ana']),
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher', ['firstname' => 'Bia']),
        ];
        $env->coordinator = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $t1 = time() - 3 * DAYSECS;
        for ($i = 0; $i < $students; $i++) {
            $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $DB->insert_record('assign_submission', (object) [
                'assignment' => $inst->id, 'userid' => $student->id, 'attemptnumber' => 0,
                'timecreated' => $t1, 'timemodified' => $t1,
                'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
            ]);
            submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
            $assign->update_allocated_markers((int) $student->id, [(int) $env->markers[0]->id, (int) $env->markers[1]->id]);
            $env->students[] = $student;
        }
        return $env;
    }
}
