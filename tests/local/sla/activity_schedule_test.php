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
 * Tests for the per-group assign schedule + override-action resolver.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Covers the catalog build, per-group date resolution (group override wins),
 * the SEPARATEGROUPS → 'override' branch, and the no-capability → 'norule' gate.
 *
 * @covers \block_feedback_tracker\local\sla\activity_schedule
 */
final class activity_schedule_test extends \advanced_testcase {
    /**
     * A non-zero group override replaces the assign defaults and marks the row
     * 'done'.
     *
     * @return void
     */
    public function test_group_override_wins_and_marks_done(): void {
        $this->resetAfterTest();
        [$course, $teacher, $group] = $this->base_course_group();
        $globalopen = $this->ts('2026-05-01 00:00:00');
        $globalclose = $this->ts('2026-05-10 00:00:00');
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'allowsubmissionsfromdate' => $globalopen,
            'duedate' => $globalclose,
        ]);
        $ovropen = $this->ts('2026-05-05 00:00:00');
        $ovrclose = $this->ts('2026-05-20 00:00:00');
        $this->make_group_override((int) $assign->id, (int) $group->id, $ovropen, $ovrclose);

        $catalog = activity_schedule::catalog_for_course($course, (int) $teacher->id);
        $acts = activity_schedule::for_group($catalog, (int) $group->id);

        $this->assertCount(1, $acts);
        $this->assertSame('done', $acts[0]['action']);
        $this->assertTrue($acts[0]['editable']);
        $this->assertSame($ovropen, (int) $acts[0]['opens']);
        $this->assertSame($ovrclose, (int) $acts[0]['closes']);
        $this->assertSame((int) $assign->cmid, (int) $acts[0]['cmid']);
    }

    /**
     * A global rule with no group override on a non-separate-groups assign is
     * 'done' (the global dates already apply to the group).
     *
     * @return void
     */
    public function test_global_dates_nonseparate_is_done(): void {
        $this->resetAfterTest();
        [$course, $teacher, $group] = $this->base_course_group();
        $globalclose = $this->ts('2026-05-10 00:00:00');
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'duedate' => $globalclose,
        ]);

        $catalog = activity_schedule::catalog_for_course($course, (int) $teacher->id);
        $acts = activity_schedule::for_group($catalog, (int) $group->id);

        $this->assertSame('done', $acts[0]['action']);
        $this->assertSame($globalclose, (int) $acts[0]['closes']);
    }

    /**
     * A global rule with no group override on a SEPARATEGROUPS assign prompts
     * 'override'.
     *
     * @return void
     */
    public function test_global_dates_separategroups_is_override(): void {
        $this->resetAfterTest();
        [$course, $teacher, $group] = $this->base_course_group();
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'duedate' => $this->ts('2026-05-10 00:00:00'),
            'groupmode' => SEPARATEGROUPS,
        ]);

        $catalog = activity_schedule::catalog_for_course($course, (int) $teacher->id);
        $acts = activity_schedule::for_group($catalog, (int) $group->id);

        $this->assertSame('override', $acts[0]['action']);
    }

    /**
     * No dates anywhere → 'create', with null open/close.
     *
     * @return void
     */
    public function test_no_dates_is_create(): void {
        $this->resetAfterTest();
        [$course, $teacher, $group] = $this->base_course_group();
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $catalog = activity_schedule::catalog_for_course($course, (int) $teacher->id);
        $acts = activity_schedule::for_group($catalog, (int) $group->id);

        $this->assertSame('create', $acts[0]['action']);
        $this->assertNull($acts[0]['opens']);
        $this->assertNull($acts[0]['closes']);
    }

    /**
     * Without mod/assign:manageoverrides but WITH an effective rule, the row is
     * a static 'done' (not editable) — the common moderator-teacher case. The
     * chip must not collapse to "no rule" just because edit rights are missing.
     *
     * @return void
     */
    public function test_without_manageoverrides_with_rule_is_done_static(): void {
        $this->resetAfterTest();
        [$course, $teacher, $group] = $this->base_course_group();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $ovrclose = $this->ts('2026-05-20 00:00:00');
        $this->make_group_override((int) $assign->id, (int) $group->id, null, $ovrclose);
        $this->prohibit_manageoverrides($course);

        $catalog = activity_schedule::catalog_for_course($course, (int) $teacher->id);
        $acts = activity_schedule::for_group($catalog, (int) $group->id);

        $this->assertSame('done', $acts[0]['action']);
        $this->assertFalse($acts[0]['editable']);
        $this->assertSame($ovrclose, (int) $acts[0]['closes']);
    }

    /**
     * Without mod/assign:manageoverrides AND with no schedule, the row is the
     * static 'norule'.
     *
     * @return void
     */
    public function test_without_manageoverrides_no_rule_is_norule(): void {
        $this->resetAfterTest();
        [$course, $teacher, $group] = $this->base_course_group();
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $this->prohibit_manageoverrides($course);

        $catalog = activity_schedule::catalog_for_course($course, (int) $teacher->id);
        $acts = activity_schedule::for_group($catalog, (int) $group->id);

        $this->assertSame('norule', $acts[0]['action']);
        $this->assertFalse($acts[0]['editable']);
    }

    /**
     * A group override that sets the due date to 0 removed it for the group:
     * the row shows no close date although the activity has one, and the
     * group counts as having its own override. The open date it left NULL is
     * still the activity's.
     *
     * @return void
     */
    public function test_a_group_override_that_removes_the_due_date(): void {
        $this->resetAfterTest();
        [$course, $teacher, $group] = $this->base_course_group();
        $globalopen = $this->ts('2026-05-01 00:00:00');
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'allowsubmissionsfromdate' => $globalopen,
            'duedate' => $this->ts('2026-05-10 00:00:00'),
            'groupmode' => SEPARATEGROUPS,
        ]);
        $this->make_group_override((int) $assign->id, (int) $group->id, null, 0);

        $catalog = activity_schedule::catalog_for_course($course, (int) $teacher->id);
        $acts = activity_schedule::for_group($catalog, (int) $group->id);

        $this->assertNull($acts[0]['closes'], 'The override removed the due date for this group.');
        $this->assertSame($globalopen, (int) $acts[0]['opens'], 'Control: the NULL open date is inherited.');
        $this->assertSame('done', $acts[0]['action'], 'The group has its own override, not a prompt to create one.');
    }

    /**
     * Without edit rights the chip follows the group's effective schedule: an
     * override that removed the activity's only date leaves the group with no
     * rule at all.
     *
     * @return void
     */
    public function test_without_manageoverrides_a_removed_only_date_is_norule(): void {
        $this->resetAfterTest();
        [$course, $teacher, $group] = $this->base_course_group();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'duedate' => $this->ts('2026-05-10 00:00:00'),
        ]);
        $this->make_group_override((int) $assign->id, (int) $group->id, null, 0);
        $this->prohibit_manageoverrides($course);

        $catalog = activity_schedule::catalog_for_course($course, (int) $teacher->id);
        $other = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $this->assertSame('norule', activity_schedule::for_group($catalog, (int) $group->id)[0]['action']);
        $this->assertSame(
            'done',
            activity_schedule::for_group($catalog, (int) $other->id)[0]['action'],
            'Control: a group without the override keeps the activity\'s rule.'
        );
    }

    /**
     * The catalog lists every visible assign in the course.
     *
     * @return void
     */
    public function test_catalog_lists_all_visible_assigns(): void {
        $this->resetAfterTest();
        [$course, $teacher, $group] = $this->base_course_group();
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $catalog = activity_schedule::catalog_for_course($course, (int) $teacher->id);

        $this->assertCount(2, $catalog['items']);
        $this->assertCount(2, activity_schedule::for_group($catalog, (int) $group->id));
    }

    /**
     * Activity names are filtered in the module context but not escaped: the
     * block renders them as text nodes and the web service as PARAM_TEXT.
     *
     * @return void
     */
    public function test_activity_names_are_filtered_and_plain(): void {
        $this->resetAfterTest();
        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_applies_to_strings('multilang', true);
        [$course, $teacher, $group] = $this->base_course_group();
        $multilang = '<span lang="en" class="multilang">A & B</span><span lang="es" class="multilang">C & D</span>';
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => $multilang]);
        $off = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => $multilang]);
        filter_set_local_state('multilang', \context_module::instance($off->cmid)->id, TEXTFILTER_OFF);
        \filter_manager::reset_caches();

        $rows = activity_schedule::for_group(
            activity_schedule::catalog_for_course($course, (int) $teacher->id),
            (int) $group->id
        );

        $names = [];
        foreach ($rows as $row) {
            $names[$row['cmid']] = $row['name'];
        }
        $this->assertCount(2, $names);
        $this->assertSame('A & BC & D', $names[(int) $off->cmid], 'The filter switched off for this activity stays off.');
        unset($names[(int) $off->cmid]);
        $this->assertSame(['A & B'], array_values($names));
    }

    /**
     * Create a course with an editing teacher and one group.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} [course, teacher, group]
     */
    private function base_course_group(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        return [$course, $teacher, $group];
    }

    /**
     * Insert a group-scoped assign override row. NULL leaves the activity's
     * date in force; 0 removes it for the group.
     *
     * @param int $assignid
     * @param int $groupid
     * @param int|null $opens allowsubmissionsfromdate
     * @param int|null $closes duedate
     * @return void
     */
    private function make_group_override(int $assignid, int $groupid, ?int $opens, ?int $closes): void {
        global $DB;
        $DB->insert_record('assign_overrides', (object) [
            'assignid' => $assignid,
            'groupid' => $groupid,
            'userid' => null,
            'sortorder' => 1,
            'allowsubmissionsfromdate' => $opens,
            'duedate' => $closes,
            'cutoffdate' => null,
        ]);
    }

    /**
     * Strip mod/assign:manageoverrides from the editing-teacher role at the
     * course context — emulates a moderator who can't edit overrides.
     *
     * @param \stdClass $course
     * @return void
     */
    private function prohibit_manageoverrides(\stdClass $course): void {
        global $DB;
        $coursectx = \context_course::instance($course->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/assign:manageoverrides', CAP_PROHIBIT, $roleid, $coursectx->id, true);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Parse a UTC datetime string into a unix timestamp.
     *
     * @param string $datetime
     * @return int
     */
    private function ts(string $datetime): int {
        return (new \DateTime($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
