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
 * Tests for the observers of mod_assign subplugin, workflow, allocation and blind-marking events.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;

/**
 * Covers the observers for subplugin submission edits (whose event carries the
 * subplugin row id, not the submission id), marking-workflow release, marker
 * allocation and the blind-marking identity reveal.
 *
 * @covers \block_feedback_tracker\local\sla\observer
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 */
final class observer_coverage_test extends \advanced_testcase {
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
     * The assignsubmission_* events override objecttable, so their objectid is
     * the subplugin row id; the submission id is in other['submissionid'].
     * See observer::submission_changed().
     *
     * @return void
     */
    public function test_subplugin_event_uses_other_submissionid(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$student, $cm, $assign, $context] = $this->build_environment();

        $t1 = $this->ts('2026-05-11 09:00:00');
        $submissionid = $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);

        /* An objectid that does not match the submission row, as with the real
         * events: it is an {assignsubmission_onlinetext} id. */
        $event = \assignsubmission_onlinetext\event\submission_created::create([
            'context' => $context,
            'courseid' => $cm->course,
            'objectid' => $submissionid + 4242,
            'userid' => $student->id,
            'relateduserid' => $student->id,
            'other' => [
                'submissionid' => $submissionid,
                'submissionattempt' => 0,
                'submissionstatus' => submission_status::SUBMITTED,
                'onlinetextwordcount' => 12,
            ],
        ]);
        /* assignfeedback_editpdf observes the parent mod_assign event and calls
         * get_assign() on it; core's own trigger sites always set it first. */
        $event->set_assign($this->assign_object($cm, $context));
        $event->trigger();

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotEmpty($row, 'The observer must resolve the submission from other[submissionid].');
        $this->assertSame((int) $student->id, (int) $row->userid);
        $this->assertSame($t1, (int) $row->timesubmitted);
    }

    /**
     * With submissiondrafts on, saving changes to a draft fires only the
     * subplugin *_updated event (assign::save_submission() triggers
     * assessable_submitted only when drafts are off), so that event is the
     * ledger's only signal that the draft changed.
     *
     * @return void
     */
    public function test_subplugin_update_event_is_observed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$student, $cm, $assign, $context] = $this->build_environment();

        $t1 = $this->ts('2026-05-11 09:00:00');
        $submissionid = $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::DRAFT, 'groupid' => 0, 'latest' => 1,
        ]);

        $event = \assignsubmission_file\event\submission_updated::create([
            'context' => $context,
            'courseid' => $cm->course,
            'objectid' => $submissionid + 99,
            'userid' => $student->id,
            'relateduserid' => $student->id,
            'other' => [
                'submissionid' => $submissionid,
                'submissionattempt' => 0,
                'submissionstatus' => submission_status::DRAFT,
                'filesubmissioncount' => 2,
            ],
        ]);
        $event->set_assign($this->assign_object($cm, $context));
        $event->trigger();

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotEmpty($row);
        $this->assertSame(submission_status::DRAFT, $row->submissionstatus);
    }

    /**
     * Releasing a marking-workflow grade is when the feedback reaches the
     * student. mod_assign stores no release timestamp, so the ledger takes the
     * instant from the event. See observer::workflow_state_changed().
     *
     * @return void
     */
    public function test_workflow_release_stamps_the_release_instant(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$student, $cm, $assign, $context] = $this->build_environment(['markingworkflow' => 1]);

        $t1 = $this->ts('2026-05-11 09:00:00');
        $t2 = $this->ts('2026-05-12 09:00:00');
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => 70.0, 'timecreated' => $t2, 'timemodified' => $t2,
        ]);
        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')
            ->set_workflow_state((int) $assign->id, (int) $student->id, 'inmarking');
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNull($row->timereleased);
        $this->assertNull($row->timeclosed);

        $DB->set_field('assign_user_flags', 'workflowstate', 'released', [
            'assignment' => $assign->id,
            'userid' => $student->id,
        ]);
        $user = $DB->get_record('user', ['id' => $student->id], '*', MUST_EXIST);
        $assignobj = $this->assign_object($cm, $context);
        \mod_assign\event\workflow_state_updated::create_from_user($assignobj, $user, 'released')->trigger();

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotNull($row->timereleased, 'The release instant must be captured from the event.');
        $this->assertNotNull($row->timeclosed);
        $this->assertSame('released', $row->gradestate);
    }

    /**
     * Allocating a marker records when marking responsibility landed, which
     * mod_assign keeps no column for, so it can only be captured from the event.
     *
     * The allocation goes through core's own API, so the test proves that the
     * event core sends today reaches the observer: Moodle 5.3 stopped sending
     * marker_updated from this path, and a test that fired the event by hand
     * stayed green while production lost every stamp.
     *
     * @return void
     */
    public function test_marker_allocation_stamps_the_allocation_instant(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->seed_calendar();
        [$student, $cm, $assign, $context] = $this->build_environment([
            'markingworkflow' => 1,
            'markingallocation' => 1,
            'markercount' => 1,
        ]);
        $this->submit_student($assign, $student);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNull($row->timeallocated);
        $this->assertSame(0, (int) $row->allocmarkerid);

        $marker = $this->create_marker($cm);
        $this->assign_object($cm, $context)->update_allocated_markers((int) $student->id, [(int) $marker->id]);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotNull($row->timeallocated, 'The allocation instant exists nowhere in core.');
        $this->assertNotNull($row->timeallocmarker);
        $this->assertSame((int) $marker->id, (int) $row->allocmarkerid);
        $this->assertSame(submission_ledger::ALLOC_SOURCE_OBSERVED, $row->allocsource);
    }

    /**
     * Moodle 5.2 fires one marker_updated per marker, so allocating two markers
     * in one call reaches the observer twice. The row is still stamped once, as
     * observed, and on a multi-marking activity the marker is the one in
     * position 1, the first allocation row core writes
     * ({@see \assign::update_allocated_markers()}), not the lowest user id and
     * not whichever event came last.
     *
     * @return void
     */
    public function test_two_markers_allocated_in_one_call_stamp_one_row(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->seed_calendar();
        [$student, $cm, $assign, $context] = $this->build_environment([
            'markingworkflow' => 1,
            'markingallocation' => 1,
            'markercount' => 2,
        ]);
        $this->submit_student($assign, $student);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $first = $this->create_marker($cm);
        $second = $this->create_marker($cm);
        $this->assign_object($cm, $context)->update_allocated_markers(
            (int) $student->id,
            [(int) $second->id, (int) $first->id]
        );

        $this->assertLessThan((int) $second->id, (int) $first->id, 'Control: position 1 is not the lowest id.');
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));
        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame((int) $second->id, (int) $row->allocmarkerid);
        $this->assertNotNull($row->timeallocated);
        $this->assertSame(submission_ledger::ALLOC_SOURCE_OBSERVED, $row->allocsource);
    }

    /**
     * Unless markinganonymous is set, blind marking suppresses submission_graded,
     * so every grading stays invisible until identities are revealed; that event
     * must re-derive the activity. See observer::identities_revealed().
     *
     * @return void
     */
    public function test_identities_revealed_queues_a_rebuild(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$student, $cm, $assign, $context] = $this->build_environment(['blindmarking' => 1]);

        $t1 = $this->ts('2026-05-11 09:00:00');
        $t2 = $this->ts('2026-05-12 09:00:00');
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => 65.0, 'timecreated' => $t2, 'timemodified' => $t2,
        ]);

        $before = $DB->count_records('task_adhoc');
        $assignobj = $this->assign_object($cm, $context);
        \mod_assign\event\identities_revealed::create_from_assign($assignobj)->trigger();
        $this->assertGreaterThan($before, $DB->count_records('task_adhoc'));

        // Draining the queue must produce the grading the blind period hid.
        $this->runAdhocTasks('\block_feedback_tracker\task\backfill_one_submission');
        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotEmpty($row);
        $this->assertSame($t2, (int) $row->timegraded);
    }

    /**
     * Instantiate mod_assign's assign object, which the event factories need.
     *
     * @param \stdClass $cm
     * @param \context_module $context
     * @return \assign
     */
    private function assign_object(\stdClass $cm, \context_module $context): \assign {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        return new \assign($context, $cm, $course);
    }

    /**
     * Build a processable course with an enrolled student and an assign.
     *
     * @param array $assignopts Extra {assign} settings.
     * @return array The student, the cm, the assign row and the module context.
     */
    private function build_environment(array $assignopts = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        course_access::reset_memo();

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $inst = $this->getDataGenerator()->create_module(
            'assign',
            array_merge(['course' => $course->id], $assignopts)
        );
        $cm = get_coursemodule_from_instance('assign', $inst->id);
        $context = \context_module::instance($cm->id);
        $assign = $DB->get_record('assign', ['id' => $inst->id], '*', MUST_EXIST);
        return [$student, $cm, $assign, $context];
    }

    /**
     * Insert a submitted attempt for the student, at a fixed instant.
     *
     * @param \stdClass $assign The assign row.
     * @param \stdClass $student The student.
     * @return void
     */
    private function submit_student(\stdClass $assign, \stdClass $student): void {
        global $DB;
        $t1 = $this->ts('2026-05-11 09:00:00');
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
    }

    /**
     * Create a teacher enrolled in the activity's course.
     *
     * @param \stdClass $cm The course module.
     * @return \stdClass The teacher.
     */
    private function create_marker(\stdClass $cm): \stdClass {
        global $DB;
        return $this->getDataGenerator()->create_and_enrol(
            $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST),
            'editingteacher'
        );
    }

    /**
     * Seed the calendar settings the academic-time engine needs.
     *
     * @return void
     */
    private function seed_calendar(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');

        global $DB;
        $now = time();
        for ($dow = 0; $dow <= 4; $dow++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek' => $dow, 'starttime' => 480, 'endtime' => 1080,
                'enabled' => 1, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        academic_time::reset_memos();
    }

    /**
     * Parse an ISO-like datetime string into a UTC timestamp.
     *
     * @param string $datetime
     * @return int
     */
    private function ts(string $datetime): int {
        return (new \DateTime($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
