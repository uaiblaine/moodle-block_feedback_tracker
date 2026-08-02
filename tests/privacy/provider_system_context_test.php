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
 * Tests for the privacy provider's system-context and userlist handling.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;

/**
 * The provider declares four tables holding administrator ids at system
 * context and returns that context from get_contexts_for_userid(), so the
 * export and delete paths have to honour it. A context the plugin itself puts
 * in the list and then ignores is a compliance failure, not a gap.
 *
 * @covers \block_feedback_tracker\privacy\provider
 */
final class provider_system_context_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * Seed one config row per declared table, all attributed to $userid.
     *
     * @param int $userid
     * @return array Ids keyed by table suffix.
     */
    private function seed_admin_artifacts(int $userid): array {
        global $DB;

        $day = $this->generator()->create_calendar_day(20260601, 'holiday', 'Seeded');
        $DB->set_field('block_feedback_tracker_cday', 'usermodified', $userid, ['id' => $day]);

        $this->generator()->seed_default_platform_calendar();
        $hours = (int) $DB->get_field_sql('SELECT MIN(id) FROM {block_feedback_tracker_chours}');
        $DB->set_field('block_feedback_tracker_chours', 'usermodified', $userid, ['id' => $hours]);

        $pause = $this->generator()->create_pause_window([]);
        $DB->set_field('block_feedback_tracker_cpause', 'usermodified', $userid, ['id' => $pause]);

        $log = $this->generator()->seed_audit_log(1, ['triggeredby' => $userid])[0];

        return ['cday' => $day, 'chours' => $hours, 'cpause' => $pause, 'log' => $log];
    }

    /**
     * An approved contextlist holding only the system context.
     *
     * @param \stdClass $user
     * @return approved_contextlist
     */
    private function system_contextlist(\stdClass $user): approved_contextlist {
        return new approved_contextlist(
            $user,
            'block_feedback_tracker',
            [\context_system::instance()->id]
        );
    }

    /**
     * The provider puts the system context in the list for an administrator
     * who touched the calendar — a precondition for everything below.
     *
     * @return void
     */
    public function test_system_context_is_offered_for_a_calendar_editor(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->seed_admin_artifacts((int) $user->id);

        $contextids = array_map('intval', provider::get_contexts_for_userid((int) $user->id)->get_contextids());

        $this->assertContains((int) \context_system::instance()->id, $contextids);
    }

    /**
     * A subject-access request must return the data the metadata declares.
     * Exporting nothing for a context the provider itself offered is an
     * incomplete export.
     *
     * @return void
     */
    public function test_export_includes_system_context_data(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->seed_admin_artifacts((int) $user->id);

        provider::export_user_data($this->system_contextlist($user));

        $writer = writer::with_context(\context_system::instance());
        $this->assertTrue($writer->has_any_data(), 'The system context export must not be empty.');
    }

    /**
     * Deleting one user's data must clear their attribution from the config
     * tables. The rows themselves are site configuration and stay; only the
     * user link is removed.
     *
     * @return void
     */
    public function test_delete_for_user_clears_attribution(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $ids = $this->seed_admin_artifacts((int) $user->id);
        $otherday = $this->generator()->create_calendar_day(20260602, 'holiday', 'Other');
        $DB->set_field('block_feedback_tracker_cday', 'usermodified', $other->id, ['id' => $otherday]);

        provider::delete_data_for_user($this->system_contextlist($user));

        $this->assertNull($DB->get_field('block_feedback_tracker_cday', 'usermodified', ['id' => $ids['cday']]));
        $this->assertNull($DB->get_field('block_feedback_tracker_chours', 'usermodified', ['id' => $ids['chours']]));
        $this->assertNull($DB->get_field('block_feedback_tracker_cpause', 'usermodified', ['id' => $ids['cpause']]));
        $this->assertNull($DB->get_field('block_feedback_tracker_log', 'triggeredby', ['id' => $ids['log']]));

        // The configuration rows survive; only the attribution went.
        $this->assertTrue($DB->record_exists('block_feedback_tracker_cday', ['id' => $ids['cday']]));
        $this->assertSame(
            (int) $other->id,
            (int) $DB->get_field('block_feedback_tracker_cday', 'usermodified', ['id' => $otherday]),
            'Another user\'s attribution must be untouched.'
        );
    }

    /**
     * Deleting everything in the system context clears every attribution.
     *
     * @return void
     */
    public function test_delete_all_users_in_system_context(): void {
        global $DB;
        $this->resetAfterTest();

        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $this->seed_admin_artifacts((int) $one->id);
        $second = $this->generator()->create_calendar_day(20260603, 'recess', null);
        $DB->set_field('block_feedback_tracker_cday', 'usermodified', $two->id, ['id' => $second]);

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame(
            0,
            $DB->count_records_select('block_feedback_tracker_cday', 'usermodified IS NOT NULL')
        );
        $this->assertSame(
            0,
            $DB->count_records_select('block_feedback_tracker_log', 'triggeredby IS NOT NULL')
        );
    }

    /**
     * The userlist half at system context: only the listed users lose their
     * attribution.
     *
     * @return void
     */
    public function test_delete_for_users_at_system_context_is_selective(): void {
        global $DB;
        $this->resetAfterTest();

        $target = $this->getDataGenerator()->create_user();
        $keep = $this->getDataGenerator()->create_user();
        $ids = $this->seed_admin_artifacts((int) $target->id);
        $keptday = $this->generator()->create_calendar_day(20260604, 'holiday', null);
        $DB->set_field('block_feedback_tracker_cday', 'usermodified', $keep->id, ['id' => $keptday]);

        $userlist = new approved_userlist(
            \context_system::instance(),
            'block_feedback_tracker',
            [(int) $target->id]
        );
        provider::delete_data_for_users($userlist);

        $this->assertNull($DB->get_field('block_feedback_tracker_cday', 'usermodified', ['id' => $ids['cday']]));
        $this->assertSame(
            (int) $keep->id,
            (int) $DB->get_field('block_feedback_tracker_cday', 'usermodified', ['id' => $keptday])
        );
    }

    /**
     * get_users_in_context reports the administrators at system context.
     *
     * @return void
     */
    public function test_get_users_in_system_context(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->seed_admin_artifacts((int) $user->id);

        $userlist = new \core_privacy\local\request\userlist(
            \context_system::instance(),
            'block_feedback_tracker'
        );
        provider::get_users_in_context($userlist);

        $this->assertContains((int) $user->id, array_map('intval', $userlist->get_userids()));
    }

    /**
     * The course-context userlist path had no coverage either: listed users
     * lose their ledger rows, unlisted users keep theirs.
     *
     * @return void
     */
    public function test_delete_for_users_at_course_context_is_selective(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course();
        $target = $this->generator()->create_user_in_role((int) $course->id, 'student');
        $keep = $this->generator()->create_user_in_role((int) $course->id, 'student');

        $this->generator()->create_ledger_row(['courseid' => (int) $course->id, 'userid' => (int) $target->id]);
        $this->generator()->create_ledger_row(['courseid' => (int) $course->id, 'userid' => (int) $keep->id]);

        $userlist = new approved_userlist(
            \context_course::instance($course->id),
            'block_feedback_tracker',
            [(int) $target->id]
        );
        provider::delete_data_for_users($userlist);

        $this->assertFalse($DB->record_exists('block_feedback_tracker_sub', ['userid' => $target->id]));
        $this->assertTrue($DB->record_exists('block_feedback_tracker_sub', ['userid' => $keep->id]));
    }

    /**
     * get_users_in_context at a course context reports the submitters.
     *
     * @return void
     */
    public function test_get_users_in_course_context(): void {
        $this->resetAfterTest();

        $course = $this->generator()->create_tracked_course();
        $student = $this->generator()->create_user_in_role((int) $course->id, 'student');
        $this->generator()->create_ledger_row(['courseid' => (int) $course->id, 'userid' => (int) $student->id]);

        $userlist = new \core_privacy\local\request\userlist(
            \context_course::instance($course->id),
            'block_feedback_tracker'
        );
        provider::get_users_in_context($userlist);

        $this->assertSame([(int) $student->id], array_map('intval', $userlist->get_userids()));
    }

    /**
     * Every field the export writes is declared in the metadata. An exported
     * field with no declaration under-reports what the plugin holds.
     *
     * @return void
     */
    public function test_exported_submission_fields_are_all_declared(): void {
        $this->resetAfterTest();

        $collection = provider::get_metadata(
            new \core_privacy\local\metadata\collection('block_feedback_tracker')
        );

        $declared = [];
        foreach ($collection->get_collection() as $item) {
            if ($item->get_name() === 'block_feedback_tracker_sub') {
                $declared = array_keys($item->get_privacy_fields());
            }
        }

        foreach (['attemptnumber', 'submissionstatus', 'effectivedays'] as $field) {
            $this->assertContains(
                $field,
                $declared,
                "The sub table stores $field, so the metadata must declare it."
            );
        }
    }
}
