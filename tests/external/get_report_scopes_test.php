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
 * Tests for the get_report_scopes external function.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\sla\group_access;
use block_feedback_tracker\local\sla\rollup_service;
use core_external\external_api;

/**
 * The report-scopes endpoint feeds the pending report's hero + class filter
 * straight from the materialised rollup — no per-group trend/peer/activity
 * assembly. It must honour the same group visibility as the full payload.
 *
 * @covers \block_feedback_tracker\external\get_report_scopes
 */
final class get_report_scopes_test extends \advanced_testcase {
    /**
     * Plugin + calendar config, mirroring get_responsiveness_test.
     *
     * @return void
     */
    private function seed_config(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('grading_during_pause_mode', 'clipped', 'block_feedback_tracker');
        set_config('sla_goal_hours', '24', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');
        global $DB;
        $now = time();
        for ($dow = 0; $dow <= 4; $dow++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek'    => $dow,
                'starttime'    => 480,
                'endtime'      => 1080,
                'enabled'      => 1,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Insert one graded ledger row for (course, group, student) and recompute
     * its rollup. Submitted two days ago, graded one day ago.
     *
     * @param \stdClass $course
     * @param int $groupid
     * @param \stdClass $student
     * @return void
     */
    private function seed_rollup(\stdClass $course, int $groupid, \stdClass $student): void {
        global $DB;
        $now = time();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $DB->insert_record('block_feedback_tracker_sub', (object) [
            'courseid'         => $course->id,
            'groupid'          => $groupid,
            'cmid'             => $cm->id,
            'iteminstance'     => $assign->id,
            'userid'           => $student->id,
            'attemptnumber'    => 0,
            'submissionstatus' => 'submitted',
            'timesubmitted'    => $now - 86400 * 2,
            'timegraded'       => $now - 86400,
            'hasrule'          => 0,
            'waitinghours'     => 24.0,
            'effectivehours'   => 16.0,
            'effectiveasof'    => $now,
            'effectivecalver'  => 1,
            'slabucket'        => 'excellent',
            'timecreated'      => $now - 86400 * 2,
            'timemodified'     => $now - 86400,
        ]);
        rollup_service::recompute_group((int) $course->id, $groupid, $now);
    }

    /**
     * Happy path: one named group plus the ungrouped row. The scopes come
     * straight from the rollup with the day medians present, and groupid 0
     * carries the localised "no group" label in a NOGROUPS course.
     *
     * @return void
     */
    public function test_returns_scopes_for_seeded_rollups(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $studenta = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $studentb = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group->id,
            'userid'  => $studenta->id,
        ]);

        $this->seed_rollup($course, (int) $group->id, $studenta);
        $this->seed_rollup($course, 0, $studentb);
        group_access::reset_memo();

        $this->setUser($teacher);
        $result = get_report_scopes::execute((int) $course->id);
        $result = external_api::clean_returnvalue(get_report_scopes::execute_returns(), $result);

        $this->assertTrue($result['success']);
        $this->assertSame((int) $course->id, $result['courseid']);
        $this->assertCount(2, $result['groups']);

        $byid = [];
        foreach ($result['groups'] as $g) {
            $byid[(int) $g['groupid']] = $g;
        }
        $named = $byid[(int) $group->id];
        $this->assertSame($group->name, $named['name']);
        $this->assertSame('excellent', $named['score_band']);
        $this->assertSame(0, $named['pending']);
        $this->assertEqualsWithDelta(16.0, $named['cur_median_eff_h'], 0.01);
        // Submitted -> graded spans exactly one calendar day; the business-day
        // figure depends on the weekday so only presence is asserted.
        $this->assertEqualsWithDelta(1.0, $named['cur_median_perc_days'], 0.01);
        $this->assertArrayHasKey('cur_median_eff_days', $named);

        $ungrouped = $byid[0];
        $this->assertSame(
            get_string('card_nogroup', 'block_feedback_tracker'),
            $ungrouped['name']
        );
    }

    /**
     * In a SEPARATEGROUPS course a teacher without accessallgroups sees only
     * their own groups' scopes — and never the ungrouped row.
     *
     * @return void
     */
    public function test_separate_groups_restricts_to_own_groups(): void {
        $this->resetAfterTest();
        $this->seed_config();
        global $DB;

        $course = $this->getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $studenta = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $studentb = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $groupa->id,
            'userid'  => $teacher->id,
        ]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $groupa->id,
            'userid'  => $studenta->id,
        ]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $groupb->id,
            'userid'  => $studentb->id,
        ]);

        $this->seed_rollup($course, (int) $groupa->id, $studenta);
        $this->seed_rollup($course, (int) $groupb->id, $studentb);

        // Core does not grant accessallgroups to the non-editing teacher
        // archetype; unassigning it keeps the test independent of that default.
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        unassign_capability('moodle/site:accessallgroups', $roleid);
        accesslib_clear_all_caches_for_unit_testing();
        group_access::reset_memo();

        $this->setUser($teacher);
        $result = get_report_scopes::execute((int) $course->id);
        $result = external_api::clean_returnvalue(get_report_scopes::execute_returns(), $result);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['groups']);
        $this->assertSame((int) $groupa->id, (int) $result['groups'][0]['groupid']);
    }

    /**
     * Callers without viewresponsiveness are rejected.
     *
     * @return void
     */
    public function test_requires_capability(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        get_report_scopes::execute((int) $course->id);
    }
}
