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
 * Tests for the stored dates following a student's group changes.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\task\reattribute_users;
use block_feedback_tracker\task\reconcile_ledger;

/**
 * A group override governs a student only while they belong to its group, so
 * joining or leaving a group, or losing one to a deletion, changes the dates
 * their rows store. Every change here goes through core's own group API, so
 * the events reach the observer as they do on a site, and no reconciler sweep
 * or adhoc task runs unless a test runs it. After each change the rule-drift
 * sweep must find nothing left to repair.
 *
 * @covers \block_feedback_tracker\local\sla\observer
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 * @covers \block_feedback_tracker\local\sla\rule_resolver
 */
final class group_change_dates_test extends \advanced_testcase {
    /**
     * Load core's group API and flush the memos the ledger consults.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/group/lib.php');
        process_memos::reset();
    }

    /**
     * A student added to an overridden group stores the group's due date as
     * the membership is saved.
     *
     * @return void
     */
    public function test_a_member_added_to_an_overridden_group_gets_its_date_at_once(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $group = $this->group($course);
        $this->group_override($assignid, $group, 1, $due + 2 * 86400);
        $userid = $this->student($course);
        $this->submit_and_store($cm, $assignid, $userid);
        $this->assertSame($due, $this->stored_close($cm, $userid), 'Precondition: not a member yet, so the activity date.');
        $this->assertSame([], $this->rule_sweep_dispatches($course), 'Precondition: nothing to repair.');

        groups_add_member($group, $userid);

        $this->assertSame($due + 2 * 86400, $this->stored_close($cm, $userid), 'The group date arrives with the membership.');
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(reattribute_users::class), 'Written inside the request.');
        $this->assertSame([], $this->rule_sweep_dispatches($course), 'The rule-drift sweep has nothing left to repair.');
    }

    /**
     * Leaving the governing group hands the dates to the next group by
     * sortorder, and leaving that one to the activity.
     *
     * @return void
     */
    public function test_removal_falls_back_to_the_remaining_group_then_to_the_activity(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $first = $this->group($course);
        $second = $this->group($course);
        $this->group_override($assignid, $first, 1, $due + 86400);
        $this->group_override($assignid, $second, 2, $due + 2 * 86400);
        $userid = $this->student($course, $first, $second);
        $this->submit_and_store($cm, $assignid, $userid);
        $this->assertSame($due + 86400, $this->stored_close($cm, $userid), 'Precondition: the first group governs.');

        groups_remove_member($first, $userid);
        $this->assertSame($due + 2 * 86400, $this->stored_close($cm, $userid), 'The second group governs now.');
        $this->assertSame([], $this->rule_sweep_dispatches($course));

        groups_remove_member($second, $userid);
        $this->assertSame($due, $this->stored_close($cm, $userid), 'With no group left, the activity date.');
        $this->assertSame([], $this->rule_sweep_dispatches($course));
    }

    /**
     * On a course with no group override the re-dating is one query and writes
     * nothing. The control is the same call once an override exists: it finds
     * and writes the row, so the query was looking in the right place.
     *
     * @return void
     */
    public function test_a_course_with_no_group_override_costs_one_query_and_changes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $group = $this->group($course);
        $userid = $this->student($course);
        $this->submit_and_store($cm, $assignid, $userid);
        groups_add_member($group, $userid);
        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $userid], '*', MUST_EXIST);
        $this->assertSame($due, (int) $row->timecloses, 'Precondition: the activity date, the group has no override.');

        $reads = $DB->perf_get_reads();
        $written = submission_ledger::re_resolve_rules_for_group_change((int) $course->id, $userid, 50);
        $this->assertSame(1, $DB->perf_get_reads() - $reads, 'One query.');
        $this->assertSame(0, $written);
        $this->assertSame(
            (int) $row->timemodified,
            (int) $DB->get_field('block_feedback_tracker_sub', 'timemodified', ['id' => $row->id]),
            'The row was not written.'
        );

        // Written directly, so no event re-dates the row before the call does.
        $this->group_override($assignid, $group, 1, $due + 3 * 86400);
        $this->assertSame(1, submission_ledger::re_resolve_rules_for_group_change((int) $course->id, $userid, 50));
        $this->assertSame($due + 3 * 86400, $this->stored_close($cm, $userid), 'Control: an override is found and written.');
    }

    /**
     * In a course that has a group override, a row whose stored dates already
     * match what it resolves to is neither rewritten nor re-queued: only rows
     * whose dates move are written. The control moves the override's date
     * without an event, and the same call then writes the row.
     *
     * @return void
     */
    public function test_a_row_whose_dates_already_agree_is_not_rewritten(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $group = $this->group($course);
        $overrideid = $this->group_override($assignid, $group, 1, $due + 2 * 86400);
        $userid = $this->student($course, $group);
        $this->submit_and_store($cm, $assignid, $userid);
        $this->assertSame($due + 2 * 86400, $this->stored_close($cm, $userid), 'Precondition: the group date is stored.');
        $DB->delete_records('block_feedback_tracker_queue');

        $this->assertSame(0, submission_ledger::re_resolve_rules_for_group_change((int) $course->id, $userid, 50));
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_queue'), 'No rollup is re-queued.');

        // Written directly, so no event re-dates the row before the call does.
        $DB->set_field('assign_overrides', 'duedate', $due + 4 * 86400, ['id' => $overrideid]);
        $this->assertSame(1, submission_ledger::re_resolve_rules_for_group_change((int) $course->id, $userid, 50));
        $this->assertSame($due + 4 * 86400, $this->stored_close($cm, $userid), 'Control: a moved date is written.');
    }

    /**
     * Up to 50 moving rows are re-dated inside the request; one more hands the
     * whole user to the background task, which writes nothing twice.
     *
     * @return void
     */
    public function test_a_large_re_dating_is_handed_to_the_background(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $group = $this->group($course);
        $this->group_override($assignid, $group, 1, $due + 2 * 86400);
        $inline = $this->student($course);
        $deferred = $this->student($course);
        $this->seed_attempts($cm, $assignid, $course, $inline, 50, $due);
        $this->seed_attempts($cm, $assignid, $course, $deferred, 51, $due);

        groups_add_member($group, $inline);
        groups_add_member($group, $deferred);

        $this->assertSame(50, $this->rows_closing($inline, $due + 2 * 86400), 'Fifty rows are written inside the request.');
        $this->assertSame(51, $this->rows_closing($deferred, $due), 'Fifty-one are not written at all.');
        $queued = \core\task\manager::get_adhoc_tasks(reattribute_users::class);
        $this->assertCount(1, $queued);
        $this->assertSame([$deferred], array_map('intval', (array) reset($queued)->get_custom_data()->userids));

        $this->runAdhocTasks('\\block_feedback_tracker\\task\\reattribute_users');

        $this->assertSame(51, $this->rows_closing($deferred, $due + 2 * 86400), 'The task re-dated every row.');
    }

    /**
     * Deleting the governing group gives its members the activity date back,
     * although core removes the memberships and the override without an
     * event of their own.
     *
     * @return void
     */
    public function test_a_deleted_group_gives_its_members_the_activity_date(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $group = $this->group($course);
        $this->group_override($assignid, $group, 1, $due + 2 * 86400);
        $userid = $this->student($course, $group);
        $this->submit_and_store($cm, $assignid, $userid);
        $this->assertSame($due + 2 * 86400, $this->stored_close($cm, $userid), 'Precondition: the group governs.');

        groups_delete_group($group);

        $this->assertSame($due, $this->stored_close($cm, $userid));
        $this->assertSame([], $this->rule_sweep_dispatches($course));
    }

    /**
     * A row still storing a group override's date is re-dated although its
     * activity has no group override left. That is the state core leaves
     * behind when a group is deleted, if its own observer removes the group's
     * overrides before the plugin's runs.
     *
     * @return void
     */
    public function test_a_row_whose_group_override_is_gone_is_still_re_dated(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        $due = time() + 7 * 86400;
        [$cm, $assignid, $course] = $this->build_environment(['duedate' => $due]);
        $group = $this->group($course);
        $override = $this->group_override($assignid, $group, 1, $due + 2 * 86400);
        $userid = $this->student($course, $group);
        $this->submit_and_store($cm, $assignid, $userid);
        $this->assertSame($due + 2 * 86400, $this->stored_close($cm, $userid), 'Precondition: the group governs.');

        // Removed without an event, as groups_delete_group() and mod_assign's observer do.
        $DB->delete_records('groups_members', ['groupid' => $group]);
        $DB->delete_records('assign_overrides', ['id' => $override]);

        $this->assertSame(1, submission_ledger::re_resolve_rules_for_group_change((int) $course->id, $userid));
        $this->assertSame($due, $this->stored_close($cm, $userid));
    }

    /**
     * Run the rule-drift sweep over one course, one window, and list the
     * students it dispatched a repair for.
     *
     * @param \stdClass $course
     * @return int[] User ids, ascending.
     */
    private function rule_sweep_dispatches(\stdClass $course): array {
        global $DB;
        $DB->delete_records('task_adhoc', ['classname' => '\\block_feedback_tracker\\task\\backfill_one_submission']);
        $task = new reconcile_ledger();
        (new \ReflectionMethod($task, 'sweep_rule_drift'))->invoke($task, [(int) $course->id], 500, 'rules');
        $userids = [];
        foreach (\core\task\manager::get_adhoc_tasks('\block_feedback_tracker\task\backfill_one_submission') as $queued) {
            foreach ((array) ($queued->get_custom_data()->rows ?? []) as $descriptor) {
                $userids[] = (int) ((array) $descriptor)['userid'];
            }
        }
        sort($userids);
        return $userids;
    }

    /**
     * Rows of one user whose stored due date is the given one.
     *
     * @param int $userid
     * @param int $close
     * @return int
     */
    private function rows_closing(int $userid, int $close): int {
        global $DB;
        return $DB->count_records('block_feedback_tracker_sub', ['userid' => $userid, 'timecloses' => $close]);
    }

    /**
     * Give a user current rows for several attempts, each storing the
     * activity's due date.
     *
     * @param \stdClass $cm
     * @param int $assignid
     * @param \stdClass $course
     * @param int $userid
     * @param int $count
     * @param int $due
     * @return void
     */
    private function seed_attempts(\stdClass $cm, int $assignid, \stdClass $course, int $userid, int $count, int $due): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        for ($attempt = 0; $attempt < $count; $attempt++) {
            $generator->create_ledger_row([
                'courseid' => (int) $course->id,
                'cmid' => (int) $cm->id,
                'iteminstance' => $assignid,
                'userid' => $userid,
                'attemptnumber' => $attempt,
                'timecloses' => $due,
                'hasrule' => 1,
            ]);
        }
    }

    /**
     * The stored due date of one student's row.
     *
     * @param \stdClass $cm
     * @param int $userid
     * @return int|null
     */
    private function stored_close(\stdClass $cm, int $userid): ?int {
        global $DB;
        $value = $DB->get_field('block_feedback_tracker_sub', 'timecloses', ['cmid' => $cm->id, 'userid' => $userid], MUST_EXIST);
        return $value === null ? null : (int) $value;
    }

    /**
     * Build a processable course holding one assign.
     *
     * @param array $assignopts {assign} settings.
     * @return array The cm, the {assign} id and the course.
     */
    private function build_environment(array $assignopts): array {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();
        $instance = $this->getDataGenerator()->create_module(
            'assign',
            array_merge(['course' => $course->id], $assignopts)
        );
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        return [$cm, (int) $instance->id, $course];
    }

    /**
     * Create a group in the course.
     *
     * @param \stdClass $course
     * @return int The group id.
     */
    private function group(\stdClass $course): int {
        return (int) $this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
    }

    /**
     * Enrol a student and add them to the given groups, in order.
     *
     * @param \stdClass $course
     * @param int ...$groupids
     * @return int The student's user id.
     */
    private function student(\stdClass $course, int ...$groupids): int {
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        foreach ($groupids as $groupid) {
            groups_add_member($groupid, $user->id);
        }
        return (int) $user->id;
    }

    /**
     * Insert a group override that sets only the due date.
     *
     * @param int $assignid
     * @param int $groupid
     * @param int $sortorder
     * @param int $due
     * @return int The override id.
     */
    private function group_override(int $assignid, int $groupid, int $sortorder, int $due): int {
        global $DB;
        return (int) $DB->insert_record('assign_overrides', (object) [
            'assignid' => $assignid,
            'groupid' => $groupid,
            'userid' => null,
            'sortorder' => $sortorder,
            'allowsubmissionsfromdate' => null,
            'duedate' => $due,
            'cutoffdate' => null,
        ]);
    }

    /**
     * Insert one submitted attempt, four days old, and store its ledger row.
     *
     * @param \stdClass $cm
     * @param int $assignid
     * @param int $userid
     * @return void
     */
    private function submit_and_store(\stdClass $cm, int $assignid, int $userid): void {
        global $DB;
        $tsubmit = time() - 4 * 86400;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assignid, 'userid' => $userid, 'attemptnumber' => 0,
            'timecreated' => $tsubmit, 'timemodified' => $tsubmit,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, $userid, 0);
    }

    /**
     * Seed the calendar settings the academic-time engine needs, with the
     * working day this suite owns.
     *
     * @return void
     */
    private function seed_calendar(): void {
        global $DB;
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');
        $DB->delete_records('block_feedback_tracker_chours');
        $now = time();
        for ($dow = 0; $dow <= 4; $dow++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek' => $dow, 'starttime' => 480, 'endtime' => 1080,
                'enabled' => 1, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        academic_time::reset_memos();
    }
}
