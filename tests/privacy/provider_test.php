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
 * Tests for the privacy provider.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;

/**
 * Spot-checks the course-context ledger rows, the allocated-marker link and
 * the user preferences. System-context data is covered by
 * provider_system_context_test.
 *
 * @covers \block_feedback_tracker\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The plugin counts as compliant with the privacy API.
     *
     * Core's own compliance test sweeps every component but is not in the plugin's testsuite,
     * which is all moodle-plugin-ci runs, so the check is repeated here: a metadata provider
     * without a request data provider fails it ({@see \core_privacy\manager::component_is_compliant()}).
     *
     * @return void
     */
    public function test_the_component_is_compliant(): void {
        $this->assertTrue((new \core_privacy\manager())->component_is_compliant('block_feedback_tracker'));
    }

    /**
     * The user's ledger course context appears in the contextlist.
     */
    public function test_get_contexts_for_userid_returns_course_context(): void {
        $this->resetAfterTest();
        [$courseid, $user] = $this->seed_user_with_ledger();

        $contextlist = provider::get_contexts_for_userid((int) $user->id);
        // Normalise to int: pgsql / mariadb drivers may return bigint columns
        // as strings, and assertContains uses strict (===) comparison.
        $contextids = array_map('intval', $contextlist->get_contextids());

        $coursecontext = \context_course::instance($courseid);
        $this->assertContains((int) $coursecontext->id, $contextids);
    }

    /**
     * export_user_data writes the submission ledger to the writer.
     */
    public function test_export_user_data_writes_submissions(): void {
        $this->resetAfterTest();
        [$courseid, $user] = $this->seed_user_with_ledger();

        $contextlist = new approved_contextlist(
            $user,
            'block_feedback_tracker',
            [\context_course::instance($courseid)->id]
        );
        provider::export_user_data($contextlist);

        $writer = writer::with_context(\context_course::instance($courseid));
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * Deleting the user drops their ledger rows and re-enqueues the
     * (course, group) tuple.
     */
    public function test_delete_data_for_user_removes_rows(): void {
        $this->resetAfterTest();
        [$courseid, $user] = $this->seed_user_with_ledger();

        global $DB;
        $this->assertSame(1, (int) $DB->count_records('block_feedback_tracker_sub', [
            'courseid' => $courseid, 'userid' => $user->id,
        ]));

        $contextlist = new approved_contextlist(
            $user,
            'block_feedback_tracker',
            [\context_course::instance($courseid)->id]
        );
        provider::delete_data_for_user($contextlist);

        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_sub', [
            'courseid' => $courseid, 'userid' => $user->id,
        ]));
        $this->assertGreaterThan(0, (int) $DB->count_records('block_feedback_tracker_queue', [
            'courseid' => $courseid,
        ]));
    }

    /**
     * delete_data_for_all_users_in_context drops everything in the course.
     */
    public function test_delete_all_users_in_context_drops_course_rows(): void {
        $this->resetAfterTest();
        [$courseid] = $this->seed_user_with_ledger();
        $this->seed_user_with_ledger($courseid);

        global $DB;
        $this->assertSame(2, (int) $DB->count_records('block_feedback_tracker_sub', [
            'courseid' => $courseid,
        ]));

        provider::delete_data_for_all_users_in_context(\context_course::instance($courseid));

        $this->assertSame(0, (int) $DB->count_records('block_feedback_tracker_sub', [
            'courseid' => $courseid,
        ]));
    }

    /**
     * The dashboard_collapsed user preference is declared in metadata
     * and surfaces via export_user_preferences() with a localised
     * human-readable description.
     */
    public function test_export_user_preferences_writes_dashboard_collapsed(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('block_feedback_tracker_dashboard_collapsed', '1', $user);

        provider::export_user_preferences((int) $user->id);

        $writer = writer::with_context(\context_system::instance());
        $this->assertTrue($writer->has_any_data());
        $prefs = $writer->get_user_preferences('block_feedback_tracker');
        $this->assertObjectHasProperty('block_feedback_tracker_dashboard_collapsed', $prefs);
        $this->assertSame('1', $prefs->block_feedback_tracker_dashboard_collapsed->value);
    }

    /**
     * The report_collapsed preference is exported too, with the "expanded"
     * description when it is stored as '0'.
     */
    public function test_export_user_preferences_writes_report_collapsed(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('block_feedback_tracker_report_collapsed', '0', $user);

        provider::export_user_preferences((int) $user->id);

        $prefs = writer::with_context(\context_system::instance())->get_user_preferences('block_feedback_tracker');
        $this->assertObjectHasProperty('block_feedback_tracker_report_collapsed', $prefs);
        $this->assertSame('0', $prefs->block_feedback_tracker_report_collapsed->value);
        $this->assertSame(
            get_string('privacy:preference:report_collapsed_expanded', 'block_feedback_tracker'),
            $prefs->block_feedback_tracker_report_collapsed->description
        );
        $this->assertObjectNotHasProperty(
            'block_feedback_tracker_dashboard_collapsed',
            $prefs,
            'Only the preference that was set is exported.'
        );
    }

    /**
     * A teacher who is only the allocated marker of a submission has no ledger
     * row of their own, and the course is still found for them.
     */
    public function test_marker_only_teacher_gets_the_course_context(): void {
        $this->resetAfterTest();
        [$courseid, $student, $marker] = $this->seed_allocated_submission();

        $contextids = array_map('intval', provider::get_contexts_for_userid((int) $marker->id)->get_contextids());
        $this->assertContains((int) \context_course::instance($courseid)->id, $contextids);

        $bystranger = provider::get_contexts_for_userid((int) $this->getDataGenerator()->create_user()->id);
        $this->assertCount(0, $bystranger, 'Control: a user with no link to the ledger gets no context.');
        $this->assertNotSame((int) $student->id, (int) $marker->id);
    }

    /**
     * The marker is listed among the course context's users.
     */
    public function test_marker_is_listed_in_the_course_context(): void {
        $this->resetAfterTest();
        [$courseid, $student, $marker] = $this->seed_allocated_submission();

        $userlist = new \core_privacy\local\request\userlist(
            \context_course::instance($courseid),
            'block_feedback_tracker'
        );
        provider::get_users_in_context($userlist);

        $userids = array_map('intval', $userlist->get_userids());
        sort($userids);
        $expected = [(int) $student->id, (int) $marker->id];
        sort($expected);
        $this->assertSame($expected, $userids);
    }

    /**
     * A marker's export lists their allocations and leaves the student out.
     */
    public function test_marker_export_lists_the_allocations(): void {
        $this->resetAfterTest();
        [$courseid, $student, $marker, $cmid] = $this->seed_allocated_submission();
        $context = \context_course::instance($courseid);

        provider::export_user_data(new approved_contextlist($marker, 'block_feedback_tracker', [$context->id]));

        $writer = writer::with_context($context);
        $data = $writer->get_data([
            get_string('pluginname', 'block_feedback_tracker'),
            get_string('privacy:path:allocations', 'block_feedback_tracker'),
        ]);
        $this->assertNotEmpty($data, 'The marker\'s allocations must be exported.');
        $this->assertCount(1, $data->allocations);
        $this->assertSame($cmid, $data->allocations[0]['cmid']);
        $this->assertEqualsWithDelta(3.5, $data->allocations[0]['allochours'], 0.001);
        $this->assertArrayNotHasKey('userid', $data->allocations[0], 'The student\'s identity is not the marker\'s data.');

        $submissions = $writer->get_data([
            get_string('pluginname', 'block_feedback_tracker'),
            get_string('privacy:path:submissions', 'block_feedback_tracker'),
        ]);
        $this->assertEmpty($submissions, 'The marker submitted nothing, so no submissions are exported for them.');
        $this->assertNotSame((int) $student->id, (int) $marker->id);
    }

    /**
     * Erasing a marker clears their id from the student's row and keeps the
     * row; a row allocated to another marker keeps its id.
     */
    public function test_deleting_a_marker_clears_the_link_and_keeps_the_row(): void {
        global $DB;
        $this->resetAfterTest();
        [$courseid, $student, $marker] = $this->seed_allocated_submission();
        $other = $this->getDataGenerator()->create_user();
        $control = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')->create_ledger_row([
            'courseid' => $courseid,
            'userid' => (int) $this->getDataGenerator()->create_user()->id,
            'allocmarkerid' => (int) $other->id,
        ]);

        provider::delete_data_for_user(new approved_contextlist(
            $marker,
            'block_feedback_tracker',
            [\context_course::instance($courseid)->id]
        ));

        $row = $DB->get_record('block_feedback_tracker_sub', ['courseid' => $courseid, 'userid' => $student->id]);
        $this->assertNotEmpty($row, 'The student\'s row is the student\'s data and must stay.');
        $this->assertSame(0, (int) $row->allocmarkerid);
        $this->assertSame(
            (int) $other->id,
            (int) $DB->get_field('block_feedback_tracker_sub', 'allocmarkerid', ['id' => $control]),
            'Control: another marker\'s allocation is untouched.'
        );
    }

    /**
     * The userlist deletion clears the marker link as well.
     */
    public function test_deleting_a_marker_by_userlist_clears_the_link(): void {
        global $DB;
        $this->resetAfterTest();
        [$courseid, $student, $marker] = $this->seed_allocated_submission();
        $context = \context_course::instance($courseid);

        provider::delete_data_for_users(new approved_userlist($context, 'block_feedback_tracker', [(int) $marker->id]));

        $row = $DB->get_record('block_feedback_tracker_sub', ['courseid' => $courseid, 'userid' => $student->id]);
        $this->assertNotEmpty($row);
        $this->assertSame(0, (int) $row->allocmarkerid);
    }

    /**
     * No preference set → export is a no-op (nothing written to the
     * writer for that user).
     */
    public function test_export_user_preferences_is_noop_when_unset(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        provider::export_user_preferences((int) $user->id);

        $writer = writer::with_context(\context_system::instance());
        $this->assertFalse($writer->has_any_data());
    }

    /**
     * Seed a student's ledger row allocated to a marker who has no row of
     * their own.
     *
     * @return array The course id, the student, the marker and the row's cmid.
     */
    private function seed_allocated_submission(): array {
        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $student = $this->getDataGenerator()->create_user();
        $marker = $this->getDataGenerator()->create_user();
        $now = time();
        $id = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')->create_ledger_row([
            'courseid' => $courseid,
            'userid' => (int) $student->id,
            'allocmarkerid' => (int) $marker->id,
            'timeallocated' => $now - 7200,
            'timeallocmarker' => $now - 7200,
            'allochours' => 3.5,
        ]);
        global $DB;
        $cmid = (int) $DB->get_field('block_feedback_tracker_sub', 'cmid', ['id' => $id]);
        return [$courseid, $student, $marker, $cmid];
    }

    /**
     * Seed one user with one ledger row in a course; reuses the course id if
     * provided. Returns [courseid, user].
     *
     * @param int|null $courseid
     * @return array{0:int, 1:\stdClass}
     */
    private function seed_user_with_ledger(?int $courseid = null): array {
        global $DB;
        if ($courseid === null) {
            $course = $this->getDataGenerator()->create_course();
            $courseid = (int) $course->id;
        }
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('block_feedback_tracker_sub', (object) [
            'courseid'         => $courseid,
            'groupid'          => 0,
            'cmid'             => 999,
            'iteminstance'     => 999,
            'userid'           => $user->id,
            'attemptnumber'    => 0,
            'submissionstatus' => 'submitted',
            'timesubmitted'    => $now - 86400,
            'timegraded'       => $now - 3600,
            'hasrule'          => 0,
            'waitinghours'     => 23.0,
            'effectivehours'   => 8.0,
            'effectiveasof'    => $now,
            'effectivecalver'  => 1,
            'slabucket'        => 'excellent',
            'timecreated'      => $now - 86400,
            'timemodified'     => $now - 3600,
        ]);
        return [$courseid, $user];
    }
}
