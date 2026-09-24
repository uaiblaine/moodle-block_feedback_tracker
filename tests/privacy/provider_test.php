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
 * Spot-checks the course-context ledger rows and the user preferences.
 * System-context data is covered by provider_system_context_test.
 *
 * @covers \block_feedback_tracker\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
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
