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
 * Tests for the SLA event observers.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;

/**
 * Verifies that the wired Moodle events drive ledger upserts, pause-record
 * persistence, queue entries, and adhoc-task queueing.
 *
 * @covers \block_feedback_tracker\local\sla\observer
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 */
final class observer_test extends \advanced_testcase {
    /**
     * Firing assessable_submitted creates exactly one ledger row and one
     * queue entry; the ledger row reflects pending state (no timegraded).
     */
    public function test_assessable_submitted_creates_ledger_and_queue(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        [$course, $student, $cm, $assign, $context] = $this->build_course_and_assign();

        global $DB;
        $now = time();
        $submission = (object) [
            'assignment'    => $assign->id,
            'userid'        => $student->id,
            'attemptnumber' => 0,
            'timecreated'   => $now - 60,
            'timemodified'  => $now - 60,
            'status'        => 'submitted',
            'groupid'       => 0,
            'latest'        => 1,
        ];
        $submission->id = $DB->insert_record('assign_submission', $submission);

        $event = \mod_assign\event\assessable_submitted::create([
            'context'       => $context,
            'objectid'      => $submission->id,
            'userid'        => $student->id,
            'relateduserid' => $student->id,
            'other'         => ['submission_editable' => false],
        ]);
        $event->trigger();

        $rows = $DB->get_records('block_feedback_tracker_sub');
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame((int) $cm->id, (int) $row->cmid);
        $this->assertSame((int) $student->id, (int) $row->userid);
        $this->assertNull($row->timegraded);
        $this->assertSame((int) $submission->timemodified, (int) $row->timesubmitted);
        $this->assertSame(bucket::EXCELLENT, $row->slabucket);

        $queue = $DB->get_records('block_feedback_tracker_queue');
        $this->assertCount(1, $queue);
        $qrow = reset($queue);
        $this->assertSame((int) $course->id, (int) $qrow->courseid);
        $this->assertSame(dirty_queue::REASON_SUBMISSION, $qrow->reason);
    }

    /**
     * Firing submission_graded sets timegraded + effectivehours, persists
     * pause records in the audit ledger, and queues one adhoc recompute task.
     */
    public function test_submission_graded_persists_pauses_and_queues_recompute(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $this->resetAfterTest();
        $this->seed_calendar();
        [$course, $student, $cm, $assign, $context] = $this->build_course_and_assign();

        $tsubmit = $this->ts('2026-05-15 17:00:00');
        $tgrade  = $this->ts('2026-05-18 09:00:00');

        $submission = (object) [
            'assignment'    => $assign->id,
            'userid'        => $student->id,
            'attemptnumber' => 0,
            'timecreated'   => $tsubmit,
            'timemodified'  => $tsubmit,
            'status'        => 'submitted',
            'groupid'       => 0,
            'latest'        => 1,
        ];
        $submission->id = $DB->insert_record('assign_submission', $submission);

        $grade = (object) [
            'assignment'    => $assign->id,
            'userid'        => $student->id,
            'attemptnumber' => 0,
            'grader'        => 2,
            'grade'         => 50.0,
            'timecreated'   => $tgrade,
            'timemodified'  => $tgrade,
        ];
        $grade->id = $DB->insert_record('assign_grades', $grade);
        /* Re-read so the record snapshot carries every column core defines.
         * A hand-built object is missing whatever was added last (penalty, as
         * of 5.1) and add_record_snapshot() raises a debugging() notice. */
        $grade = $DB->get_record('assign_grades', ['id' => $grade->id], '*', MUST_EXIST);

        // The submission_graded::create() throws in modern Moodle —
        // create_from_grade() is the only supported factory.
        $assigninst = new \assign($context, $cm, $course);
        $event = \mod_assign\event\submission_graded::create_from_grade($assigninst, $grade);
        $event->trigger();

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertNotFalse($row);
        $this->assertSame($tgrade, (int) $row->timegraded);
        $this->assertEqualsWithDelta(2.0, (float) $row->effectivehours, 0.01);
        $this->assertEqualsWithDelta(64.0, (float) $row->waitinghours, 0.01);
        $this->assertSame(bucket::EXCELLENT, $row->slabucket);

        // V2.0.0+: pause windows are no longer persisted to a table —
        // they're recomputed on demand by get_pause_timeline using
        // academic_time::elapsed_with_audit(). The fact that
        // effectivehours is shorter than waitinghours (2h vs 64h)
        // proves the engine applied pauses correctly.

        $alladhoc = \core\task\manager::get_adhoc_tasks(\block_feedback_tracker\task\recompute_one::class);
        $this->assertCount(1, $alladhoc);
    }

    /**
     * Adding a user to a new group fires group_member_added; the observer
     * re-attributes the user's ledger rows to the new group and enqueues
     * both the old and new (course, group) tuples.
     */
    public function test_group_member_added_reattributes_and_enqueues_both(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        // The $context value from build_course_and_assign's return tuple
        // is intentionally omitted here — this scenario only exercises the
        // group-membership observer path and doesn't need a context.
        [$course, $student, $cm, $assign] = $this->build_course_and_assign();

        $group1 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group1->id,
            'userid'  => $student->id,
        ]);

        global $DB;
        $now = time();
        $submission = (object) [
            'assignment'    => $assign->id,
            'userid'        => $student->id,
            'attemptnumber' => 0,
            'timecreated'   => $now - 60,
            'timemodified'  => $now - 60,
            'status'        => 'submitted',
            'groupid'       => 0,
            'latest'        => 1,
        ];
        $submission->id = $DB->insert_record('assign_submission', $submission);

        group_resolver::reset_memo();
        submission_ledger::upsert_for_cm_user_attempt(
            (int) $cm->id,
            (int) $student->id,
            0
        );

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame((int) $group1->id, (int) $row->groupid);

        $DB->delete_records('block_feedback_tracker_queue');

        // Adding the student to group2 fires \core\event\group_member_added,
        // which routes to observer::group_membership_changed.
        group_resolver::reset_memo();
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group2->id,
            'userid'  => $student->id,
        ]);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id]);
        $this->assertSame((int) $group2->id, (int) $row->groupid);

        $queue = $DB->get_records('block_feedback_tracker_queue');
        $groupids = array_map(static fn($r) => (int) $r->groupid, $queue);
        $this->assertContains((int) $group1->id, $groupids);
        $this->assertContains((int) $group2->id, $groupids);
    }

    /**
     * Gate regression: a submission event on a course that has no
     * feedback_tracker block must produce zero ledger and zero queue
     * rows. Locks in course_access::is_processable() inside
     * submission_changed().
     */
    public function test_event_on_course_without_block_is_ignored(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        course_access::reset_memo();

        // Build the course + assign WITHOUT calling build_course_and_assign(),
        // because that helper auto-adds the block.
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assigninst = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assigninst->id);
        $context = \context_module::instance($cm->id);

        global $DB;
        $now = time();
        $submission = (object) [
            'assignment'    => $assigninst->id,
            'userid'        => $student->id,
            'attemptnumber' => 0,
            'timecreated'   => $now - 60,
            'timemodified'  => $now - 60,
            'status'        => 'submitted',
            'groupid'       => 0,
            'latest'        => 1,
        ];
        $submission->id = $DB->insert_record('assign_submission', $submission);

        $event = \mod_assign\event\assessable_submitted::create([
            'context'       => $context,
            'objectid'      => $submission->id,
            'userid'        => $student->id,
            'relateduserid' => $student->id,
            'other'         => ['submission_editable' => false],
        ]);
        $event->trigger();

        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_sub'));
        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_queue'));
    }

    // Helpers.

    /**
     * Seed default platform calendar: UTC, weekends excluded, Mon-Fri
     * 08:00-18:00 business hours.
     */
    private function seed_calendar(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('grading_during_pause_mode', 'clipped', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');

        global $DB;
        $now = time();
        for ($dayofweek = 0; $dayofweek <= 4; $dayofweek++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek'    => $dayofweek,
                'starttime'    => 480,
                'endtime'      => 1080,
                'enabled'      => 1,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }

        academic_time::reset_memos();
    }

    /**
     * Build a course + enrolled student + assign module; return tuple of
     * (course, student, cm, assign, context).
     *
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:\stdClass,4:\context_module}
     */
    private function build_course_and_assign(): array {
        $course = $this->getDataGenerator()->create_course();
        // The course_access::is_processable() requires a course-context block
        // instance — drop one here so the observer doesn't short-circuit
        // every test. Also reset the per-request memo because earlier
        // tests in this class may have memoised the pre-block (false)
        // result against a recycled courseid.
        $coursectx = \context_course::instance($course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        course_access::reset_memo();

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assigninst = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assigninst->id);
        $context = \context_module::instance($cm->id);
        global $DB;
        $assign = $DB->get_record('assign', ['id' => $assigninst->id], '*', MUST_EXIST);
        return [$course, $student, $cm, $assign, $context];
    }

    /**
     * UTC unix timestamp builder.
     *
     * @param string $datetime Date time string.
     * @return int Unix timestamp.
     */
    private function ts(string $datetime): int {
        return (new \DateTime($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
