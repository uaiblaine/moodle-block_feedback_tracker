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
 * Assign dates use 0 as "not set" rather than NULL, and overrides use 0 as
 * "inherit" rather than "clear". Both conventions are easy to get backwards,
 * and getting either backwards silently changes which submissions the SLA
 * considers to have a deadline at all.
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
     * Build an override-like row.
     *
     * @param int $opens
     * @param int $closes
     * @param int $cutoff
     * @return \stdClass
     */
    private function override(int $opens = 0, int $closes = 0, int $cutoff = 0): \stdClass {
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
     * A zero in an override means "inherit", not "clear". Reading it as a
     * clear would silently strip deadlines from every overridden group.
     *
     * @return void
     */
    public function test_zero_in_an_override_inherits_rather_than_clears(): void {
        $result = rule_resolver::merge_override(
            $this->assign(100, 200, 300),
            $this->override(0, 0, 0)
        );

        $this->assertSame(100, $result['timeopens']);
        $this->assertSame(200, $result['timecloses']);
        $this->assertSame(300, $result['timecutoff']);
        $this->assertSame(1, $result['hasrule']);
    }

    /**
     * Overrides apply field by field: one replaced date leaves the others on
     * the activity's value.
     *
     * @return void
     */
    public function test_override_is_applied_per_field(): void {
        $result = rule_resolver::merge_override(
            $this->assign(100, 200, 300),
            $this->override(0, 222, 0)
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
        $result = rule_resolver::merge_override($this->assign(), $this->override(0, 500, 0));

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
     * the database — are normalised to ints.
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
    }

    /**
     * resolve_rule() reads a real override for the group and folds it into the
     * activity's dates.
     *
     * @return void
     */
    public function test_resolve_rule_applies_a_group_override(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'duedate' => 200,
        ]);
        $assign = $DB->get_record('assign', ['id' => $instance->id], '*', MUST_EXIST);

        $DB->insert_record('assign_overrides', (object) [
            'assignid' => $assign->id,
            'groupid' => $group->id,
            'userid' => null,
            'allowsubmissionsfromdate' => 0,
            'duedate' => 999,
            'cutoffdate' => 0,
        ]);

        $result = rule_resolver::resolve_rule($assign, (int) $user->id, (int) $group->id);

        $this->assertSame(999, $result['timecloses'], 'The group override should win over the activity date.');
        $this->assertSame(1, $result['hasrule']);
    }

    /**
     * With no override the activity's own dates are used.
     *
     * @return void
     */
    public function test_resolve_rule_without_an_override(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'duedate' => 200,
        ]);
        $assign = $DB->get_record('assign', ['id' => $instance->id], '*', MUST_EXIST);

        $result = rule_resolver::resolve_rule($assign, (int) $user->id, 0);

        $this->assertSame(200, $result['timecloses']);
    }
}
