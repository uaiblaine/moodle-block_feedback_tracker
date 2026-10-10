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
 * The Resubmitted flag on listed submissions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\external\get_grader_priority_list;
use block_feedback_tracker\external\get_pending_submissions;
use core_external\external_api;

/**
 * Work handed in again after a mark (core's "Graded - resubmitted") is flagged
 * with the earlier mark's time, from the previous cycle when the ledger
 * recorded one and from the live grade when it did not; nothing else is.
 *
 * @covers \block_feedback_tracker\local\sla\resubmission
 * @covers \block_feedback_tracker\local\sla\submission_browser
 * @covers \block_feedback_tracker\external\get_pending_submissions
 * @covers \block_feedback_tracker\external\get_grader_priority_list
 */
final class resubmission_test extends \advanced_testcase {
    /**
     * Flush the static memos resetAfterTest() leaves behind.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        submission_ledger::reset_memos();
        group_resolver::reset_memo();
        group_access::reset_memo();
        dashboard_scope::reset_memo();
    }

    /**
     * Graded, then saved again in the same attempt: the open cycle is listed
     * as resubmitted with the mark's time, and the closed one is not.
     *
     * @return void
     */
    public function test_resave_after_grading_is_listed_as_resubmitted(): void {
        global $DB;
        $this->resetAfterTest();
        [$cm, $student, $assign, $course, $teacher] = $this->build_environment();
        [$t1, $t2, $t3] = $this->times();

        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->insert_grade((int) $assign->id, (int) $student->id, $t2, 75.0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        // Control: the graded first hand-in is not a resubmission.
        $graded = submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => 'graded']);
        $this->assertCount(1, $graded['rows']);
        $this->assertSame(0, $graded['rows'][0]['resubmitted']);

        $DB->set_field('assign_submission', 'timemodified', $t3, [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'attemptnumber' => 0,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $pending = submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => 'pending']);
        $this->assertCount(1, $pending['rows']);
        $this->assertSame(1, $pending['rows'][0]['resubmitted']);
        $this->assertSame($t2, $pending['rows'][0]['previousmarktime']);
        $this->assertSame($t3, $pending['rows'][0]['timesubmitted']);

        $graded = submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => 'graded']);
        $this->assertCount(1, $graded['rows']);
        $this->assertSame(0, $graded['rows'][0]['resubmitted'], 'The closed first cycle answered an original hand-in.');
    }

    /**
     * Edited before anybody marked it: still the first hand-in.
     *
     * @return void
     */
    public function test_edit_before_grading_is_not_resubmitted(): void {
        global $DB;
        $this->resetAfterTest();
        [$cm, $student, $assign, $course, $teacher] = $this->build_environment();
        [$t1, , $t3] = $this->times();

        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $DB->set_field('assign_submission', 'timemodified', $t3, ['assignment' => $assign->id]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $pending = submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => 'pending']);
        $this->assertCount(1, $pending['rows']);
        $this->assertSame(0, $pending['rows'][0]['resubmitted']);
        $this->assertSame(0, $pending['rows'][0]['previousmarktime']);
    }

    /**
     * A cycle-0 row with no recorded response, whose attempt carries a mark
     * older than its hand-in (a row written before the cycle model), is
     * flagged from the live grade.
     *
     * @return void
     */
    public function test_cycle_zero_row_reads_the_live_grade(): void {
        $this->resetAfterTest();
        [$cm, $student, $assign] = $this->build_environment();
        [, $t2, $t3] = $this->times();

        $this->insert_grade((int) $assign->id, (int) $student->id, $t2, 75.0);
        $row = $this->ledger_row($cm, $assign, $student, ['timesubmitted' => $t3]);

        $this->assertSame([(int) $row->id => $t2], resubmission::previous_marks([$row]));
    }

    /**
     * The live grade counts only when it is older than the hand-in and holds
     * a real value: a later grade answers the row, and -1 (core's placeholder
     * for a grader who only opened the submission) or a cleared grade is no
     * mark at all.
     *
     * @return void
     */
    public function test_live_grade_must_be_an_earlier_real_mark(): void {
        $this->resetAfterTest();
        [$cm, $student, $assign, $course] = $this->build_environment();
        [, $t2, $t3] = $this->times();
        $others = [];
        for ($i = 0; $i < 3; $i++) {
            $others[] = $this->getDataGenerator()->create_and_enrol($course, 'student');
        }

        $this->insert_grade((int) $assign->id, (int) $student->id, $t3 + 60, 75.0);
        $this->insert_grade((int) $assign->id, (int) $others[0]->id, $t2, -1.0);
        $this->insert_grade((int) $assign->id, (int) $others[1]->id, $t2, null);
        // The same second as the hand-in: the cycle rule is strict, so is this.
        $this->insert_grade((int) $assign->id, (int) $others[2]->id, $t3, 75.0);

        $rows = [];
        foreach (array_merge([$student], $others) as $user) {
            $rows[] = $this->ledger_row($cm, $assign, $user, ['timesubmitted' => $t3]);
        }

        $this->assertSame([], resubmission::previous_marks($rows));
    }

    /**
     * A later cycle takes the previous cycle's mark; one answered from the
     * gradebook has no mark and gives its closing time instead.
     *
     * @return void
     */
    public function test_later_cycle_reads_the_previous_cycle(): void {
        $this->resetAfterTest();
        [$cm, $student, $assign, $course] = $this->build_environment();
        [$t1, $t2, $t3] = $this->times();
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->ledger_row($cm, $assign, $student, [
            'timesubmitted' => $t1, 'timegraded' => $t2, 'timemarked' => $t2, 'iscurrent' => 0,
        ]);
        $open = $this->ledger_row($cm, $assign, $student, ['cycle' => 1, 'timesubmitted' => $t3]);

        $this->ledger_row($cm, $assign, $other, [
            'timesubmitted' => $t1, 'timegraded' => $t2 + 60, 'timemarked' => null, 'timeclosed' => $t2 + 60,
            'closedsource' => gradebook_response::SOURCE_GRADEBOOK, 'iscurrent' => 0,
        ]);
        $fromgradebook = $this->ledger_row($cm, $assign, $other, ['cycle' => 1, 'timesubmitted' => $t3]);

        $this->assertSame(
            [(int) $open->id => $t2, (int) $fromgradebook->id => $t2 + 60],
            resubmission::previous_marks([$open, $fromgradebook])
        );
    }

    /**
     * A later cycle whose predecessor retention pruned is still a
     * resubmission; only the date is gone.
     *
     * @return void
     */
    public function test_later_cycle_without_its_predecessor_keeps_the_flag(): void {
        $this->resetAfterTest();
        [$cm, $student, $assign] = $this->build_environment();
        [, , $t3] = $this->times();

        $row = $this->ledger_row($cm, $assign, $student, ['cycle' => 2, 'timesubmitted' => $t3]);

        $this->assertSame([(int) $row->id => 0], resubmission::previous_marks([$row]));
    }

    /**
     * A draft is not handed in, whatever came before it.
     *
     * @return void
     */
    public function test_draft_is_never_resubmitted(): void {
        $this->resetAfterTest();
        [$cm, $student, $assign] = $this->build_environment();
        [$t1, $t2, $t3] = $this->times();

        $this->insert_grade((int) $assign->id, (int) $student->id, $t2, 75.0);
        $this->ledger_row($cm, $assign, $student, [
            'timesubmitted' => $t1, 'timegraded' => $t2, 'iscurrent' => 0,
        ]);
        $draft = $this->ledger_row($cm, $assign, $student, [
            'cycle' => 1, 'timesubmitted' => $t3, 'submissionstatus' => submission_status::DRAFT,
        ]);

        $this->assertSame([], resubmission::previous_marks([$draft]));
    }

    /**
     * Both web services return the two fields, through their own return
     * structures.
     *
     * @return void
     */
    public function test_web_services_return_the_flag(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');
        [$cm, $student, $assign, $course, $teacher] = $this->build_environment();
        [$t1, $t2, $t3] = $this->times();

        $this->insert_submission((int) $assign->id, (int) $student->id, $t1);
        $this->insert_grade((int) $assign->id, (int) $student->id, $t2, 75.0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $DB->set_field('assign_submission', 'timemodified', $t3, ['assignment' => $assign->id]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $this->setUser($teacher);
        $pending = external_api::clean_returnvalue(
            get_pending_submissions::execute_returns(),
            get_pending_submissions::execute((int) $course->id)
        );
        $this->assertCount(1, $pending['submissions']);
        $this->assertSame(1, $pending['submissions'][0]['resubmitted']);
        $this->assertSame($t2, $pending['submissions'][0]['previousmarktime']);

        $priority = external_api::clean_returnvalue(
            get_grader_priority_list::execute_returns(),
            get_grader_priority_list::execute(10, '')
        );
        $this->assertCount(1, $priority['submissions']);
        $this->assertSame(1, $priority['submissions'][0]['resubmitted']);
        $this->assertSame($t2, $priority['submissions'][0]['previousmarktime']);
    }

    /**
     * Hand-in, mark and re-save instants, a day apart and well in the past.
     * Only flags and instants are asserted, never hours, so the weekday the
     * suite runs on does not matter.
     *
     * @return int[] [hand-in, mark, re-save]
     */
    private function times(): array {
        $t1 = time() - 10 * DAYSECS;
        return [$t1, $t1 + DAYSECS, $t1 + 2 * DAYSECS];
    }

    /**
     * A processable course with a student, an editing teacher and an assign.
     *
     * @return array [cm, student, assign, course, teacher]
     */
    private function build_environment(): array {
        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')->seed_default_platform_calendar();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        return [$cm, $student, $assign, $course, $teacher];
    }

    /**
     * Insert one {assign_submission} row in the submitted state.
     *
     * @param int $assignid
     * @param int $userid
     * @param int $tsubmit
     * @return void
     */
    private function insert_submission(int $assignid, int $userid, int $tsubmit): void {
        global $DB;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assignid, 'userid' => $userid, 'attemptnumber' => 0,
            'timecreated' => $tsubmit, 'timemodified' => $tsubmit,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
    }

    /**
     * Insert one {assign_grades} row.
     *
     * @param int $assignid
     * @param int $userid
     * @param int $tgrade
     * @param float|null $grade Null for a cleared grade, -1.0 for core's placeholder.
     * @return void
     */
    private function insert_grade(int $assignid, int $userid, int $tgrade, ?float $grade): void {
        global $DB;
        $DB->insert_record('assign_grades', (object) [
            'assignment' => $assignid, 'userid' => $userid, 'attemptnumber' => 0,
            'grader' => 2, 'grade' => $grade,
            'timecreated' => $tgrade, 'timemodified' => $tgrade,
        ]);
    }

    /**
     * Insert a ledger row for this activity and user, read back as the
     * listing queries return it.
     *
     * @param \stdClass $cm
     * @param \stdClass $assign
     * @param \stdClass $user
     * @param array $overrides Ledger columns.
     * @return \stdClass
     */
    private function ledger_row(\stdClass $cm, \stdClass $assign, \stdClass $user, array $overrides): \stdClass {
        global $DB;
        $id = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')->create_ledger_row(
            array_merge([
                'courseid' => (int) $cm->course,
                'cmid' => (int) $cm->id,
                'iteminstance' => (int) $assign->id,
                'userid' => (int) $user->id,
            ], $overrides)
        );
        return $DB->get_record('block_feedback_tracker_sub', ['id' => $id], '*', MUST_EXIST);
    }
}
