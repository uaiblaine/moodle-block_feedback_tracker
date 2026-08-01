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
 * Tests for the plugin's test data generator.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker;

use block_feedback_tracker\local\sla\course_access;

/**
 * The fixture helpers are shared by every other test file, so a defect here
 * shows up as a confusing failure somewhere else. Pin their contracts.
 *
 * @covers \block_feedback_tracker_generator
 */
final class generator_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * A tracked course carries a course-context block, so the strict opt-in
     * gate lets it through. This is the whole reason the helper exists.
     *
     * @return void
     */
    public function test_create_tracked_course_is_processable(): void {
        $this->resetAfterTest();

        $plain = $this->getDataGenerator()->create_course();
        course_access::reset_memo();
        $this->assertFalse(
            course_access::is_processable((int) $plain->id),
            'A course with no block instance must not be processable.'
        );

        $tracked = $this->generator()->create_tracked_course();
        $this->assertTrue(course_access::is_processable((int) $tracked->id));
    }

    /**
     * Course options pass through, so group-visibility tests can request a
     * group mode directly.
     *
     * @return void
     */
    public function test_create_tracked_course_honours_groupmode(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course([
            'groupmode' => SEPARATEGROUPS,
            'groupmodeforce' => 1,
        ]);

        $this->assertSame(SEPARATEGROUPS, (int) $course->groupmode);
        $this->assertSame(1, (int) $course->groupmodeforce);
    }

    /**
     * The role enrolment lands, and the optional group membership with it.
     *
     * @return void
     */
    public function test_create_user_in_role_enrols_and_groups(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $context = \context_course::instance($course->id);

        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher', (int) $group->id);

        $this->assertTrue(is_enrolled($context, $teacher));
        $this->assertTrue(has_capability('moodle/course:manageactivities', $context, $teacher));
        $this->assertTrue(groups_is_member((int) $group->id, (int) $teacher->id));

        $loner = $this->generator()->create_user_in_role((int) $course->id, 'student');
        $this->assertFalse(groups_is_member((int) $group->id, (int) $loner->id));
    }

    /**
     * Prohibiting a capability takes effect immediately — the accesslib cache
     * flush inside the helper is what makes this assertion pass.
     *
     * @return void
     */
    public function test_deny_capability_takes_effect(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course();
        $context = \context_course::instance($course->id);
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');

        $this->assertTrue(has_capability('block/feedback_tracker:managepausewindows', $context, $teacher));

        $this->generator()->deny_capability('block/feedback_tracker:managepausewindows', $context, 'editingteacher');

        $this->assertFalse(has_capability('block/feedback_tracker:managepausewindows', $context, $teacher));
    }

    /**
     * A rollup row is insertable from defaults alone, and overrides win.
     *
     * @return void
     */
    public function test_create_rollup_row_defaults_and_overrides(): void {
        global $DB;
        $this->resetAfterTest();

        $id = $this->generator()->create_rollup_row();
        $row = $DB->get_record('block_feedback_tracker_group', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $row->courseid);
        $this->assertSame('excellent', $row->score_band);

        $custom = $this->generator()->create_rollup_row([
            'courseid' => 42,
            'groupid' => 7,
            'pending' => 3,
            'score_band' => 'critical',
        ]);
        $row = $DB->get_record('block_feedback_tracker_group', ['id' => $custom], '*', MUST_EXIST);
        $this->assertSame(42, (int) $row->courseid);
        $this->assertSame(7, (int) $row->groupid);
        $this->assertSame(3, (int) $row->pending);
        $this->assertSame('critical', $row->score_band);
    }

    /**
     * Audit rows are written through the production writer, ordered oldest
     * first, with the details payload stored as JSON.
     *
     * @return void
     */
    public function test_seed_audit_log_writes_ordered_rows(): void {
        global $DB;
        $this->resetAfterTest();

        $ids = $this->generator()->seed_audit_log(3, ['reason' => 'cron', 'details' => ['courseid' => 9]]);

        $this->assertCount(3, $ids);
        $rows = $DB->get_records_list('block_feedback_tracker_log', 'id', $ids, 'timestarted ASC');
        $this->assertCount(3, $rows);

        $times = array_map(static fn($r) => (int) $r->timestarted, array_values($rows));
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'Rows must be seeded oldest first.');

        $first = reset($rows);
        $this->assertSame('cron', $first->reason);
        $this->assertSame(['courseid' => 9], json_decode($first->details, true));
    }

    /**
     * The display unit and its thresholds move together.
     *
     * @return void
     */
    public function test_set_display_unit_moves_both_settings(): void {
        $this->resetAfterTest();

        $this->generator()->set_display_unit();
        $this->assertSame('business_days', get_config('block_feedback_tracker', 'display_time_unit'));
        $this->assertSame('2,5,10', get_config('block_feedback_tracker', 'bucket_thresholds_days'));

        $this->generator()->set_display_unit('hours', '1,3,7');
        $this->assertSame('hours', get_config('block_feedback_tracker', 'display_time_unit'));
        $this->assertSame('1,3,7', get_config('block_feedback_tracker', 'bucket_thresholds_days'));
    }

    /**
     * The graded-submission helper drives the real observer path: a ledger row
     * appears with the grade time recorded.
     *
     * Crucially this must NOT raise "Unexpected debugging() call detected" —
     * advanced_testcase fails the test if the record snapshot is missing a
     * column, which is exactly what the helper's re-read prevents.
     *
     * @return void
     */
    public function test_create_graded_submission_produces_a_graded_ledger_row(): void {
        global $DB;
        $this->resetAfterTest();

        $this->generator()->seed_default_platform_calendar();
        $course = $this->generator()->create_tracked_course();
        $student = $this->generator()->create_user_in_role((int) $course->id, 'student');

        $submitted = (new \DateTime('2026-05-15 17:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $graded = (new \DateTime('2026-05-18 09:00:00', new \DateTimeZone('UTC')))->getTimestamp();

        [$cm, , $grade] = $this->generator()->create_graded_submission($course, $student, $submitted, $graded);

        $this->assertObjectHasProperty('id', $grade);

        $row = $DB->get_record('block_feedback_tracker_sub', ['cmid' => $cm->id, 'userid' => $student->id]);
        $this->assertNotFalse($row, 'The observer should have written a ledger row.');
        $this->assertSame($graded, (int) $row->timegraded);
        $this->assertSame('submitted', $row->submissionstatus);
    }

    /**
     * Passing an existing course module reuses it instead of creating another,
     * so a test can put two attempts on one assignment.
     *
     * @return void
     */
    public function test_create_graded_submission_reuses_a_supplied_cm(): void {
        $this->resetAfterTest();

        $this->generator()->seed_default_platform_calendar();
        $course = $this->generator()->create_tracked_course();
        $one = $this->generator()->create_user_in_role((int) $course->id, 'student');
        $two = $this->generator()->create_user_in_role((int) $course->id, 'student');
        $now = time();

        [$cm] = $this->generator()->create_graded_submission($course, $one, $now - 7200, $now - 3600);
        [$samecm] = $this->generator()->create_graded_submission(
            $course,
            $two,
            $now - 7200,
            $now - 3600,
            ['cm' => $cm]
        );

        $this->assertSame((int) $cm->id, (int) $samecm->id);
    }
}
