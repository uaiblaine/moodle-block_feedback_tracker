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
 * Tests for the assign date-rule resolver.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;


/**
 * Assign dates use 0 as "not set", while an override uses NULL for "inherit"
 * and 0 for "this date removed". Both conventions are easy to get backwards,
 * and getting either backwards silently changes which submissions the SLA
 * considers to have a deadline at all. The per-student resolution also has to
 * choose among several group overrides and apply extensions the way mod_assign
 * does; the last test compares it with core's own resolution.
 *
 * @covers \block_feedback_tracker\local\sla\rule_resolver
 */
final class rule_resolver_test extends \advanced_testcase {
    /**
     * Build an assign-like row.
     *
     * @param int $opens
     * @param int $closes
     * @param int $cutoff
     * @return \stdClass
     */
    private function assign(int $opens = 0, int $closes = 0, int $cutoff = 0): \stdClass {
        return (object) [
            'id' => 1,
            'allowsubmissionsfromdate' => $opens,
            'duedate' => $closes,
            'cutoffdate' => $cutoff,
        ];
    }

    /**
     * Build an override-like row. NULL leaves the activity's date in force.
     *
     * @param int|null $opens
     * @param int|null $closes
     * @param int|null $cutoff
     * @return \stdClass
     */
    private function override(?int $opens = null, ?int $closes = null, ?int $cutoff = null): \stdClass {
        return (object) [
            'allowsubmissionsfromdate' => $opens,
            'duedate' => $closes,
            'cutoffdate' => $cutoff,
        ];
    }

    /**
     * An assign with no dates at all carries no rule.
     *
     * @return void
     */
    public function test_assign_without_dates_has_no_rule(): void {
        $result = rule_resolver::merge_override($this->assign(), null);

        $this->assertNull($result['timeopens']);
        $this->assertNull($result['timecloses']);
        $this->assertNull($result['timecutoff']);
        $this->assertSame(0, $result['hasrule']);
    }

    /**
     * Any single date is enough to make it a ruled activity.
     *
     * @return void
     */
    public function test_a_single_date_sets_hasrule(): void {
        $this->assertSame(1, rule_resolver::merge_override($this->assign(100, 0, 0), null)['hasrule']);
        $this->assertSame(1, rule_resolver::merge_override($this->assign(0, 200, 0), null)['hasrule']);
        $this->assertSame(1, rule_resolver::merge_override($this->assign(0, 0, 300), null)['hasrule']);
    }

    /**
     * Zero means "not set", not "epoch". Treating it as a timestamp would give
     * every dateless assign a 1970 deadline and make everything overdue.
     *
     * @return void
     */
    public function test_zero_is_absence_not_epoch(): void {
        $result = rule_resolver::merge_override($this->assign(0, 0, 0), null);

        $this->assertNull($result['timecloses']);
        $this->assertNotSame(0, $result['timecloses']);
    }

    /**
     * The assign's own dates pass through when there is no override.
     *
     * @return void
     */
    public function test_assign_dates_pass_through(): void {
        $result = rule_resolver::merge_override($this->assign(100, 200, 300), null);

        $this->assertSame(100, $result['timeopens']);
        $this->assertSame(200, $result['timecloses']);
        $this->assertSame(300, $result['timecutoff']);
        $this->assertSame(1, $result['hasrule']);
    }

    /**
     * A non-zero override value replaces the activity's.
     *
     * @return void
     */
    public function test_override_replaces_the_assign_value(): void {
        $result = rule_resolver::merge_override(
            $this->assign(100, 200, 300),
            $this->override(111, 222, 333)
        );

        $this->assertSame(111, $result['timeopens']);
        $this->assertSame(222, $result['timecloses']);
        $this->assertSame(333, $result['timecutoff']);
    }

    /**
     * A zero in an override removes the date, as assign::update_effective_access()
     * applies it: overrideedit.php stores 0 when the date is disabled on an
     * override whose activity has one, and NULL only for an unchanged value.
     *
     * @return void
     */
    public function test_zero_in_an_override_removes_the_date(): void {
        $result = rule_resolver::merge_override(
            $this->assign(100, 200, 300),
            $this->override(0, 0, 0)
        );

        $this->assertNull($result['timeopens'], 'The override removed the open date.');
        $this->assertNull($result['timecloses'], 'The override removed the due date.');
        $this->assertNull($result['timecutoff'], 'The override removed the cut-off.');
        $this->assertSame(0, $result['hasrule'], 'Nothing is left to judge the submission against.');
    }

    /**
     * Overrides apply field by field: a NULL leaves that date on the
     * activity's value.
     *
     * @return void
     */
    public function test_null_in_an_override_inherits_per_field(): void {
        $result = rule_resolver::merge_override(
            $this->assign(100, 200, 300),
            $this->override(null, 222, null)
        );

        $this->assertSame(100, $result['timeopens']);
        $this->assertSame(222, $result['timecloses']);
        $this->assertSame(300, $result['timecutoff']);
    }

    /**
     * An override can give a rule to an activity that had none.
     *
     * @return void
     */
    public function test_override_can_introduce_a_rule(): void {
        $result = rule_resolver::merge_override($this->assign(), $this->override(null, 500, null));

        $this->assertSame(500, $result['timecloses']);
        $this->assertSame(1, $result['hasrule']);
    }

    /**
     * Missing properties are tolerated: an assign row selected without the
     * date columns must not fatal, it simply has no rule.
     *
     * @return void
     */
    public function test_missing_properties_are_treated_as_absent(): void {
        $result = rule_resolver::merge_override((object) ['id' => 1], null);

        $this->assertNull($result['timeopens']);
        $this->assertSame(0, $result['hasrule']);
    }

    /**
     * Values arriving as numeric strings — which is how they come back from
     * the database — are normalised to ints, and a string "0" in an override
     * removes the date just as the integer does.
     *
     * @return void
     */
    public function test_string_values_from_the_database_are_normalised(): void {
        $assign = (object) [
            'id' => 1,
            'allowsubmissionsfromdate' => '100',
            'duedate' => '0',
            'cutoffdate' => '300',
        ];

        $result = rule_resolver::merge_override($assign, null);

        $this->assertSame(100, $result['timeopens']);
        $this->assertNull($result['timecloses'], 'The string "0" is absence, same as the integer.');
        $this->assertSame(300, $result['timecutoff']);

        $override = (object) ['allowsubmissionsfromdate' => '0', 'duedate' => null, 'cutoffdate' => '333'];
        $result = rule_resolver::merge_override($assign, $override);
        $this->assertNull($result['timeopens'], 'A stored "0" in an override removes the date.');
        $this->assertSame(333, $result['timecutoff']);
    }

    /**
     * With no override the activity's own dates are used.
     *
     * @return void
     */
    public function test_resolve_rule_without_an_override(): void {
        $this->resetAfterTest();
        [$course, $assignid] = $this->activity(100, 200, 0);
        $userid = $this->student($course);

        $result = rule_resolver::resolve_rule($assignid, $userid);

        $this->assertSame(100, $result['timeopens']);
        $this->assertSame(200, $result['timecloses']);
        $this->assertNull($result['timecutoff']);
        $this->assertSame(1, $result['hasrule']);
    }

    /**
     * A group override applies to the group's members, and only to them.
     *
     * @return void
     */
    public function test_a_group_override_applies_to_its_members_only(): void {
        $this->resetAfterTest();
        [$course, $assignid] = $this->activity(0, 200, 0);
        $group = $this->group($course);
        $member = $this->student($course, $group);
        $outsider = $this->student($course);
        $this->group_override($assignid, $group, 1, null, 999, null);

        $this->assertSame(999, rule_resolver::resolve_rule($assignid, $member)['timecloses']);
        $this->assertSame(
            200,
            rule_resolver::resolve_rule($assignid, $outsider)['timecloses'],
            'Control: a student outside the group keeps the activity date.'
        );
    }

    /**
     * A student in several overridden groups gets the override with the
     * lowest sortorder, as assign::override_exists() chooses it, whichever
     * group the student joined last.
     *
     * @return void
     */
    public function test_the_lowest_sortorder_override_among_all_groups_governs(): void {
        $this->resetAfterTest();
        [$course, $assignid] = $this->activity(0, 200, 0);
        $first = $this->group($course);
        $second = $this->group($course);
        // Joined in this order, so the second group is also the reporting group.
        $userid = $this->student($course, $first, $second);
        $this->group_override($assignid, $first, 1, null, 111, null);
        $this->group_override($assignid, $second, 2, null, 222, null);

        $this->assertSame(111, rule_resolver::resolve_rule($assignid, $userid)['timecloses']);

        // Swapping the order swaps the winner: the choice is the sortorder, not the group.
        $this->reorder($assignid, $first, 2);
        $this->reorder($assignid, $second, 1);
        $this->assertSame(222, rule_resolver::resolve_rule($assignid, $userid)['timecloses']);
    }

    /**
     * The user override wins field by field: a date it leaves NULL still comes
     * from the governing group override, as mod_assign_cm_info_dynamic()
     * dates the activity, and only then from the activity.
     *
     * @return void
     */
    public function test_the_user_override_wins_field_by_field(): void {
        $this->resetAfterTest();
        [$course, $assignid] = $this->activity(100, 200, 300);
        $group = $this->group($course);
        $userid = $this->student($course, $group);
        $this->group_override($assignid, $group, 1, null, 222, 333);
        $this->user_override($assignid, $userid, null, null, 444);

        $result = rule_resolver::resolve_rule($assignid, $userid);

        $this->assertSame(100, $result['timeopens'], 'Neither override sets it: the activity date.');
        $this->assertSame(222, $result['timecloses'], 'Only the group override sets it.');
        $this->assertSame(444, $result['timecutoff'], 'Both set it: the user override wins.');
    }

    /**
     * A zero in the student's own override removes the date even where a
     * group override and the activity both have one.
     *
     * @return void
     */
    public function test_a_removed_date_beats_the_group_and_the_activity(): void {
        $this->resetAfterTest();
        [$course, $assignid] = $this->activity(0, 200, 300);
        $group = $this->group($course);
        $userid = $this->student($course, $group);
        $this->group_override($assignid, $group, 1, null, 222, null);
        $this->user_override($assignid, $userid, null, 0, null);

        $result = rule_resolver::resolve_rule($assignid, $userid);

        $this->assertNull($result['timecloses'], 'The user override removed the due date.');
        $this->assertSame(300, $result['timecutoff'], 'Control: the cut-off it left NULL is still inherited.');
    }

    /**
     * An extension replaces the due date for that student and moves a cut-off
     * it passes, as assign::submissions_open() does; the open date stays.
     *
     * @return void
     */
    public function test_an_extension_replaces_the_due_date_and_moves_a_passed_cutoff(): void {
        $this->resetAfterTest();
        [$course, $assignid] = $this->activity(100, 200, 300);
        $userid = $this->student($course);
        $other = $this->student($course);
        $this->extension($assignid, $userid, 500);

        $result = rule_resolver::resolve_rule($assignid, $userid);

        $this->assertSame(100, $result['timeopens']);
        $this->assertSame(500, $result['timecloses']);
        $this->assertSame(500, $result['timecutoff'], 'The extension runs past the cut-off, which moves with it.');
        $this->assertSame(
            200,
            rule_resolver::resolve_rule($assignid, $other)['timecloses'],
            'Control: another student keeps the activity date.'
        );
    }

    /**
     * An extension before the cut-off leaves it where it is, and on an
     * activity without a cut-off it creates none: submissions_open() only
     * raises a final date that exists.
     *
     * @return void
     */
    public function test_an_extension_never_creates_or_lowers_a_cutoff(): void {
        $this->resetAfterTest();
        [$course, $assignid] = $this->activity(0, 200, 300);
        $userid = $this->student($course);
        $this->extension($assignid, $userid, 250);

        $result = rule_resolver::resolve_rule($assignid, $userid);
        $this->assertSame(250, $result['timecloses']);
        $this->assertSame(300, $result['timecutoff']);

        [$course, $nocutoff] = $this->activity(0, 200, 0);
        $userid = $this->student($course);
        $this->extension($nocutoff, $userid, 250);

        $result = rule_resolver::resolve_rule($nocutoff, $userid);
        $this->assertSame(250, $result['timecloses']);
        $this->assertNull($result['timecutoff']);
    }

    /**
     * An extension applies on top of the overridden dates, not the activity's:
     * it replaces a due date an override set, as the grading table's
     * col_status() does with the instance get_instance($userid) returns, and
     * it is measured against the override's cut-off, as submissions_open()
     * does after update_effective_access().
     *
     * Every extension here runs past the overridden due date, where core's
     * views all agree on the due date the student has.
     *
     * @return void
     */
    public function test_an_extension_applies_over_an_override(): void {
        $this->resetAfterTest();
        [$course, $assignid] = $this->activity(100, 200, 300);
        $group = $this->group($course);
        $late = $this->group($course);
        $cleared = $this->group($course);

        $useroverride = $this->student($course);
        $this->user_override($assignid, $useroverride, null, 400, 450);
        $this->extension($assignid, $useroverride, 500);

        $groupoverride = $this->student($course, $group);
        $this->group_override($assignid, $group, 1, null, 410, 450);
        $this->extension($assignid, $groupoverride, 480);

        $latecutoff = $this->student($course, $late);
        $this->group_override($assignid, $late, 2, null, 420, 600);
        $this->extension($assignid, $latecutoff, 500);

        $removedcutoff = $this->student($course, $cleared);
        $this->group_override($assignid, $cleared, 3, null, 430, 0);
        $this->extension($assignid, $removedcutoff, 500);

        $result = rule_resolver::resolve_rule($assignid, $useroverride);
        $this->assertSame(100, $result['timeopens'], 'The open date is never affected.');
        $this->assertSame(500, $result['timecloses'], 'The extension replaces the user override\'s due date.');
        $this->assertSame(500, $result['timecutoff'], 'The extension moves the user override\'s cut-off it passes.');

        $result = rule_resolver::resolve_rule($assignid, $groupoverride);
        $this->assertSame(480, $result['timecloses'], 'The extension replaces the group override\'s due date.');
        $this->assertSame(480, $result['timecutoff'], 'The extension moves the group override\'s cut-off it passes.');

        $result = rule_resolver::resolve_rule($assignid, $latecutoff);
        $this->assertSame(500, $result['timecloses']);
        $this->assertSame(600, $result['timecutoff'], 'The override\'s later cut-off stands, not the activity\'s passed one.');

        $result = rule_resolver::resolve_rule($assignid, $removedcutoff);
        $this->assertSame(500, $result['timecloses']);
        $this->assertNull($result['timecutoff'], 'The override removed the cut-off; the extension does not bring one back.');

        // Control: the same overrides without an extension are in force.
        $plainuser = $this->student($course);
        $this->user_override($assignid, $plainuser, null, 400, 450);
        $result = rule_resolver::resolve_rule($assignid, $plainuser);
        $this->assertSame(400, $result['timecloses']);
        $this->assertSame(450, $result['timecutoff']);
        $result = rule_resolver::resolve_rule($assignid, $this->student($course, $group));
        $this->assertSame(410, $result['timecloses']);
        $this->assertSame(450, $result['timecutoff']);
    }

    /**
     * The resolver agrees with mod_assign's own resolution,
     * assign::update_effective_access(), on every case where core applies one
     * rule: the lowest-sortorder group override among several groups, a date
     * an override removed, a user override and no override at all.
     *
     * The case the two differ on by design, a user override with a NULL date
     * beside a group override that sets it, is pinned above instead: core's
     * override_exists() merges whole rows there, the resolver follows
     * mod_assign_cm_info_dynamic().
     *
     * @return void
     */
    public function test_resolution_agrees_with_assign_update_effective_access(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        // Core filters the groups it considers by the current user's capabilities.
        $this->setAdminUser();

        [$course, $assignid] = $this->activity(100, 200, 300);
        $first = $this->group($course);
        $second = $this->group($course);
        $bare = $this->group($course);
        $this->group_override($assignid, $first, 2, 150, 250, 350);
        $this->group_override($assignid, $second, 1, null, 260, 0);

        $students = [
            'no override' => $this->student($course),
            'user override' => $this->student($course),
            'removed due date' => $this->student($course),
            'two groups' => $this->student($course, $first, $second),
            'one group' => $this->student($course, $first),
            'group without override' => $this->student($course, $bare),
        ];
        $this->user_override($assignid, $students['user override'], 120, 220, 320);
        $this->user_override($assignid, $students['removed due date'], null, 0, null);

        $cm = get_coursemodule_from_instance('assign', $assignid, 0, false, MUST_EXIST);
        $core = new \assign(\context_module::instance($cm->id), $cm, $course);
        $fields = ['timeopens' => 'allowsubmissionsfromdate', 'timecloses' => 'duedate', 'timecutoff' => 'cutoffdate'];
        foreach ($students as $case => $userid) {
            $core->update_effective_access($userid);
            $instance = $core->get_instance($userid);
            $resolved = rule_resolver::resolve_rule($assignid, $userid);
            foreach ($fields as $key => $column) {
                $expected = (int) $instance->{$column} === 0 ? null : (int) $instance->{$column};
                $this->assertSame($expected, $resolved[$key], "$case: $column differs from core.");
            }
        }
        // Precondition: the matrix really exercises the removed date and the sortorder choice.
        $this->assertSame(0, (int) $core->get_instance($students['removed due date'])->duedate);
        $this->assertSame(260, (int) $core->get_instance($students['two groups'])->duedate);
    }

    /**
     * Create a course holding one assign with the given dates.
     *
     * @param int $opens allowsubmissionsfromdate
     * @param int $due duedate
     * @param int $cutoff cutoffdate
     * @return array The course and the {assign} id.
     */
    private function activity(int $opens, int $due, int $cutoff): array {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'allowsubmissionsfromdate' => $opens,
            'duedate' => $due,
            'cutoffdate' => $cutoff,
        ]);
        return [$course, (int) $instance->id];
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
            $this->getDataGenerator()->create_group_member(['groupid' => $groupid, 'userid' => $user->id]);
        }
        return (int) $user->id;
    }

    /**
     * Insert a group override. NULL leaves the activity's date in force.
     *
     * @param int $assignid
     * @param int $groupid
     * @param int $sortorder
     * @param int|null $opens
     * @param int|null $due
     * @param int|null $cutoff
     * @return void
     */
    private function group_override(int $assignid, int $groupid, int $sortorder, ?int $opens, ?int $due, ?int $cutoff): void {
        global $DB;
        $DB->insert_record('assign_overrides', (object) [
            'assignid' => $assignid,
            'groupid' => $groupid,
            'userid' => null,
            'sortorder' => $sortorder,
            'allowsubmissionsfromdate' => $opens,
            'duedate' => $due,
            'cutoffdate' => $cutoff,
        ]);
    }

    /**
     * Insert a user override. NULL leaves the date to the group or the activity.
     *
     * @param int $assignid
     * @param int $userid
     * @param int|null $opens
     * @param int|null $due
     * @param int|null $cutoff
     * @return void
     */
    private function user_override(int $assignid, int $userid, ?int $opens, ?int $due, ?int $cutoff): void {
        global $DB;
        $DB->insert_record('assign_overrides', (object) [
            'assignid' => $assignid,
            'groupid' => null,
            'userid' => $userid,
            'sortorder' => null,
            'allowsubmissionsfromdate' => $opens,
            'duedate' => $due,
            'cutoffdate' => $cutoff,
        ]);
    }

    /**
     * Give a group's override a new sortorder.
     *
     * @param int $assignid
     * @param int $groupid
     * @param int $sortorder
     * @return void
     */
    private function reorder(int $assignid, int $groupid, int $sortorder): void {
        global $DB;
        $DB->set_field('assign_overrides', 'sortorder', $sortorder, ['assignid' => $assignid, 'groupid' => $groupid]);
    }

    /**
     * Grant an extension by writing the flags row core keeps it in.
     *
     * @param int $assignid
     * @param int $userid
     * @param int $until
     * @return void
     */
    private function extension(int $assignid, int $userid, int $until): void {
        global $DB;
        $DB->insert_record('assign_user_flags', (object) [
            'assignment' => $assignid,
            'userid' => $userid,
            'extensionduedate' => $until,
        ]);
    }
}
