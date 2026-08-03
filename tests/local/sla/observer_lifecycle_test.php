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
 * Tests for the participant and activity lifecycle observers.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * The lifecycle events cover the three ways a ledger row stops describing
 * reality without any of its own values changing: the student left, the
 * account is gone, or the activity's settings changed what the stored row
 * means. Each is pinned against the real core event, fired the way core fires
 * it, rather than against a hand-built payload.
 *
 * @covers \block_feedback_tracker\local\sla\observer
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 */
final class observer_lifecycle_test extends \advanced_testcase {
    /**
     * Flush the per-request memos that survive resetAfterTest(); recycled
     * course ids would otherwise inherit an earlier test's decision.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        submission_ledger::reset_memos();
        group_resolver::reset_memo();
        course_access::reset_memo();
    }

    /**
     * Unenrolling a student drops their rows in that course at once, rather
     * than leaving them for the reconciler's one-course-per-tick sweep.
     *
     * @return void
     */
    public function test_unenrolling_a_student_removes_their_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$course, $student, $cm, $assign] = $this->build_environment();

        $this->submit($assign, $student, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));
        $DB->delete_records('block_feedback_tracker_queue');

        $this->unenrol($course, $student, 'manual');

        $this->assertSame(
            0,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'An unenrolled student\'s rows must go immediately.'
        );
        $this->assertSame(
            1,
            $DB->count_records('block_feedback_tracker_queue', ['courseid' => $course->id]),
            'The rollup has to be told the totals moved.'
        );
    }

    /**
     * A student holding two enrolments keeps their rows when only one is
     * removed. Core has already decided this and ships the answer as
     * `lastenrol`; getting it wrong would destroy a still-enrolled student's
     * whole measured history.
     *
     * @return void
     */
    public function test_losing_one_of_two_enrolments_keeps_the_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$course, $student, $cm, $assign] = $this->build_environment();

        // A second, independent enrolment in the same course.
        $selfplugin = enrol_get_plugin('self');
        $selfid = $selfplugin->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED]);
        $selfinstance = $DB->get_record('enrol', ['id' => $selfid], '*', MUST_EXIST);
        $selfplugin->enrol_user($selfinstance, (int) $student->id, null);

        $this->submit($assign, $student, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));

        $this->unenrol($course, $student, 'manual');

        $this->assertSame(
            1,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'The student is still enrolled by another method; the rows must stay.'
        );
    }

    /**
     * Deleting an account removes its rows in every course at once, not just
     * the one the deletion happened to be noticed in.
     *
     * @return void
     */
    public function test_deleting_an_account_removes_its_rows_everywhere(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();

        [$coursea, $student, $cma, $assigna] = $this->build_environment();
        $courseb = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($courseb->id)->id,
        ]);
        course_access::reset_memo();
        $this->getDataGenerator()->enrol_user((int) $student->id, (int) $courseb->id, 'student');
        $assignb = $this->getDataGenerator()->create_module('assign', ['course' => $courseb->id]);
        $cmb = get_coursemodule_from_instance('assign', $assignb->id);

        $this->submit($assigna, $student, 0);
        $this->submit((object) ['id' => $assignb->id], $student, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cma->id, (int) $student->id, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cmb->id, (int) $student->id, 0);
        $this->assertSame(2, $DB->count_records('block_feedback_tracker_sub', ['userid' => $student->id]));

        delete_user($DB->get_record('user', ['id' => $student->id], '*', MUST_EXIST));

        $this->assertSame(
            0,
            $DB->count_records('block_feedback_tracker_sub', ['userid' => $student->id]),
            'A deleted account must not survive in any course.'
        );
    }

    /**
     * A settings save re-derives every attempt, not just the newest one.
     *
     * The dispatch list is de-duplicated on (userid, attemptnumber); building
     * it through a DISTINCT projection keyed on userid would silently collapse
     * a resubmitting student's attempts into one, so this asserts on the
     * number of descriptors rather than merely that something was queued.
     *
     * @return void
     */
    public function test_settings_save_re_derives_every_attempt(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$course, $student, $cm, $assign] = $this->build_environment();

        $this->submit($assign, $student, 0, false);
        $this->submit($assign, $student, 1);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 1);
        $this->assertSame(2, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));

        $this->fire_module_updated($cm, $course, 'assign');

        $descriptors = $this->queued_descriptors();
        $this->assertCount(
            2,
            $descriptors,
            'Both attempts must be re-derived; a userid-keyed projection would keep only one.'
        );
        $attempts = array_map(static fn($d) => (int) $d['attemptnumber'], $descriptors);
        sort($attempts);
        $this->assertSame([0, 1], $attempts);
    }

    /**
     * A team activity dispatches once per group, not once per member.
     *
     * mod_assign's DEFAULT group is groupid 0, so rows for it are stored with
     * teamgroupid = 0 — indistinguishable from an individual row. Routing on
     * the stored value would send each member back through the whole-group
     * fan-out, which is quadratic in group size; routing on the live
     * teamsubmission flag is what keeps it linear.
     *
     * @return void
     */
    public function test_team_activity_dispatches_once_per_group(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$course, , $cm, $assign] = $this->build_environment(['teamsubmission' => 1]);

        /* build_environment() already enrolled one student, and the default
         * group is every participant not in exactly one group of the activity's
         * grouping — so these three make four members of group 0. */
        for ($i = 0; $i < 3; $i++) {
            $this->getDataGenerator()->create_and_enrol($course, 'student');
        }
        // The team container row: one per group, userid 0, default group 0.
        $when = time() - 3 * 86400;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id,
            'userid' => 0,
            'attemptnumber' => 0,
            'timecreated' => $when,
            'timemodified' => $when,
            'status' => submission_status::SUBMITTED,
            'groupid' => 0,
            'latest' => 1,
        ]);
        submission_ledger::upsert_for_team_attempt((int) $cm->id, 0, 0);
        $this->assertSame(
            4,
            $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]),
            'The fixture needs one ledger row per member to be meaningful.'
        );

        $this->fire_module_updated($cm, $course, 'assign');

        $descriptors = $this->queued_descriptors();
        $this->assertCount(
            1,
            $descriptors,
            'Three member rows in one group must collapse to a single group descriptor.'
        );
        $this->assertSame(0, (int) $descriptors[0]['userid']);
        $this->assertSame(0, (int) $descriptors[0]['groupid']);
    }

    /**
     * With team submission off, rows that still carry a team shape are
     * re-derived per member.
     *
     * Reachability, stated honestly: core freezes the `teamsubmission` field
     * once the activity has any submission or grade
     * (`mod/assign/mod_form.php`), and ledger rows only exist once it does, so
     * the settings form cannot get here — a restore or a direct write can.
     * The test sets the field directly for that reason. What it pins is the
     * routing rule itself: read the live flag, not the stored `teamgroupid`. A
     * team descriptor built from the stale value would be a guaranteed no-op,
     * because `upsert_for_team_attempt()` returns immediately once the
     * activity is no longer a team one.
     *
     * @return void
     */
    public function test_rows_with_a_stale_team_shape_are_re_derived_per_member(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        [$course, , $cm, $assign] = $this->build_environment(['teamsubmission' => 1]);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $members = [];
        for ($i = 0; $i < 2; $i++) {
            $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $this->getDataGenerator()->create_group_member([
                'groupid' => $group->id,
                'userid' => $user->id,
            ]);
            $members[] = $user;
        }
        $when = time() - 3 * 86400;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id,
            'userid' => 0,
            'attemptnumber' => 0,
            'timecreated' => $when,
            'timemodified' => $when,
            'status' => submission_status::SUBMITTED,
            'groupid' => $group->id,
            'latest' => 1,
        ]);
        submission_ledger::upsert_for_team_attempt((int) $cm->id, (int) $group->id, 0);
        $this->assertSame(2, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));

        // The teacher unticks "Students submit in groups" and saves.
        $DB->set_field('assign', 'teamsubmission', 0, ['id' => $assign->id]);
        $this->fire_module_updated($cm, $course, 'assign');

        $descriptors = $this->queued_descriptors();
        $this->assertCount(2, $descriptors, 'Each member must be re-derived on their own.');
        foreach ($descriptors as $d) {
            $this->assertNotSame(
                0,
                (int) $d['userid'],
                'A team descriptor here would be a no-op: the activity is no longer a team one.'
            );
        }
    }

    /**
     * A settings save on any other module type dispatches nothing.
     *
     * Note what this does NOT pin: remove the module-name filter and the test
     * still passes, because the ledger existence check rejects a page
     * course-module just as firmly. The filter earns its place on cost — it
     * rejects before any query, and hiding a section fires this event once per
     * contained module — and cost is not assertable here, since triggering the
     * event writes to the logstore and moves the query counter on its own. The
     * test guards the outcome; the filter's value is argued in the callback.
     *
     * @return void
     */
    public function test_settings_save_on_another_module_is_ignored(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        [$course, $student, $cm, $assign] = $this->build_environment();

        $this->submit($assign, $student, 0);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);

        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $pagecm = get_coursemodule_from_instance('page', $page->id);
        $this->fire_module_updated($pagecm, $course, 'page');

        $this->assertCount(
            0,
            $this->queued_descriptors(),
            'A non-assign settings save must not dispatch any re-derivation.'
        );
    }

    /**
     * A settings save on a tracked assign nobody has submitted to dispatches
     * nothing — the common case, and the reason for the existence check.
     *
     * @return void
     */
    public function test_settings_save_with_no_measured_rows_dispatches_nothing(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        [$course, , $cm] = $this->build_environment();

        $this->fire_module_updated($cm, $course, 'assign');

        $this->assertCount(0, $this->queued_descriptors());
    }

    /**
     * Trigger core's own activity-updated event for a course module.
     *
     * @param \stdClass $cm The course module record.
     * @param \stdClass $course
     * @param string $modulename
     * @return void
     */
    private function fire_module_updated(\stdClass $cm, \stdClass $course, string $modulename): void {
        $event = \core\event\course_module_updated::create([
            'courseid' => (int) $course->id,
            'context' => \context_module::instance((int) $cm->id),
            'objectid' => (int) $cm->id,
            'other' => [
                'modulename' => $modulename,
                'instanceid' => (int) $cm->instance,
                'name' => $cm->name ?? $modulename,
            ],
        ]);
        $event->trigger();
    }

    /**
     * Every backfill descriptor queued so far, flattened across tasks.
     *
     * @return array
     */
    private function queued_descriptors(): array {
        $out = [];
        $tasks = \core\task\manager::get_adhoc_tasks('\block_feedback_tracker\task\backfill_one_submission');
        foreach ($tasks as $task) {
            $data = (array) $task->get_custom_data();
            foreach (($data['rows'] ?? []) as $row) {
                $out[] = (array) $row;
            }
        }
        return $out;
    }

    /**
     * Remove one enrolment method's enrolment for a user, the way core does,
     * so the real event fires with its real payload.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param string $enrol Plugin name.
     * @return void
     */
    private function unenrol(\stdClass $course, \stdClass $user, string $enrol): void {
        foreach (enrol_get_instances((int) $course->id, true) as $instance) {
            if ($instance->enrol === $enrol) {
                enrol_get_plugin($enrol)->unenrol_user($instance, (int) $user->id);
            }
        }
    }

    /**
     * Insert one {assign_submission} row in the submitted state.
     *
     * @param \stdClass $assign The assign record (only id is read).
     * @param \stdClass $user
     * @param int $attempt
     * @param bool $latest Whether this is core's latest attempt.
     * @return void
     */
    private function submit(\stdClass $assign, \stdClass $user, int $attempt, bool $latest = true): void {
        global $DB;
        $when = time() - (4 - $attempt) * 86400;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id,
            'userid' => $user->id,
            'attemptnumber' => $attempt,
            'timecreated' => $when,
            'timemodified' => $when,
            'status' => submission_status::SUBMITTED,
            'groupid' => 0,
            'latest' => $latest ? 1 : 0,
        ]);
    }

    /**
     * Build a processable course with an enrolled student and an assign.
     *
     * @param array $assignopts Extra {assign} settings.
     * @return array The course, the student, the cm and the assign record.
     */
    private function build_environment(array $assignopts = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module(
            'assign',
            array_merge(['course' => $course->id], $assignopts)
        );
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $assign = $DB->get_record('assign', ['id' => $instance->id], '*', MUST_EXIST);
        return [$course, $student, $cm, $assign];
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
    }
}
