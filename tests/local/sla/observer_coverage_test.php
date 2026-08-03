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
 * Event coverage added for mod_assign lifecycle actions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;

/**
 * Covers the observers registered for the mod_assign actions the plugin used
 * to miss entirely: subplugin submission edits (whose event carries the
 * subplugin row id, not the submission id), marking-workflow release, marker
 * allocation and blind-marking reveal.
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
     * the subplugin row id. Reading it against {assign_submission} looks up one
     * table's id in another; the real id is in other['submissionid'].
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

        /* An objectid that deliberately does NOT match the submission row, as
         * the real subplugin events guarantee: the id space is the
         * assignsubmission_onlinetext table's, not assign_submission's. */
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
        /* assignfeedback_editpdf observes this event and calls get_assign() on
         * it; core's own trigger sites always seed the object first. */
        $event->set_assign($this->assign_object($cm, $context));
        $event->trigger();

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotEmpty($row, 'The observer must resolve the submission from other[submissionid].');
        $this->assertSame((int) $student->id, (int) $row->userid);
        $this->assertSame($t1, (int) $row->timesubmitted);
    }

    /**
     * A student editing an already-submitted piece of work fires only the
     * subplugin *_updated event when submissiondrafts is on. Without that
     * registration the ledger never learns the work moved.
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
     * Releasing a marking-workflow grade is the moment the feedback reaches
     * the student. Core stores no timestamp for it, so the observed instant is
     * the only record there will ever be.
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
        $DB->insert_record('assign_user_flags', (object) [
            'assignment' => $assign->id, 'userid' => $student->id,
            'locked' => 0, 'mailed' => 0, 'extensionduedate' => 0,
            'workflowstate' => 'inmarking', 'allocatedmarker' => 0,
        ]);
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
     * Allocating a marker records when marking responsibility landed — a fact
     * mod_assign keeps no column for, so it is unrecoverable if not captured
     * as it happens.
     *
     * @return void
     */
    public function test_marker_allocation_stamps_the_allocation_instant(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$student, $cm, $assign, $context] = $this->build_environment([
            'markingworkflow' => 1,
            'markingallocation' => 1,
        ]);

        $t1 = $this->ts('2026-05-11 09:00:00');
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNull($row->timeallocated);
        $this->assertSame(0, (int) $row->allocmarkerid);

        $marker = $this->getDataGenerator()->create_and_enrol(
            $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST),
            'editingteacher'
        );
        $DB->insert_record('assign_user_flags', (object) [
            'assignment' => $assign->id, 'userid' => $student->id,
            'locked' => 0, 'mailed' => 0, 'extensionduedate' => 0,
            'workflowstate' => '', 'allocatedmarker' => $marker->id,
        ]);
        $user = $DB->get_record('user', ['id' => $student->id], '*', MUST_EXIST);
        $assignobj = $this->assign_object($cm, $context);
        \mod_assign\event\marker_updated::create_from_marker($assignobj, $user, $marker)->trigger();

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotNull($row->timeallocated, 'The allocation instant exists nowhere in core.');
        $this->assertNotNull($row->timeallocmarker);
        $this->assertSame((int) $marker->id, (int) $row->allocmarkerid);
        $this->assertSame(submission_ledger::ALLOC_SOURCE_OBSERVED, $row->allocsource);
    }

    /**
     * Blind marking suppresses submission_graded outright, so every grading on
     * the activity is invisible until identities are revealed. That event must
     * re-derive the activity rather than leaving it permanently pending.
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
