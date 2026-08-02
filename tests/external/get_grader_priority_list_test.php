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
 * Tests for the get_grader_priority_list external function.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use core_external\external_api;

/**
 * Covers cross-course aggregation, per-user capability filter, sort order,
 * limit ceiling, and bucket filtering for the "Grade Now" panel.
 *
 * @covers \block_feedback_tracker\external\get_grader_priority_list
 */
final class get_grader_priority_list_test extends \advanced_testcase {
    /**
     * Reset the dashboard_scope memo and grant site admins site-wide
     * visibility for the admin-based cases. dashboard_scope's static cache is
     * keyed by userid and survives resetAfterTest, so it must be cleared each
     * test; enable_admin_view_all is the gate that lets an unenrolled admin
     * see every course (the admin tests below rely on that). It has no effect
     * on the non-admin tests (student / custom-role teacher).
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');
        \block_feedback_tracker\local\sla\dashboard_scope::reset_memo();
    }

    /**
     * Site admin (with enable_admin_view_all on) sees every pending
     * submission across every course, sorted by effective wait descending.
     */
    public function test_admin_sees_all_courses_sorted_by_effective(): void {
        $course1 = $this->getDataGenerator()->create_course(['fullname' => 'Course A']);
        $course2 = $this->getDataGenerator()->create_course(['fullname' => 'Course B']);
        [$cm1, $user1] = $this->seed_pending($course1, 'Ana Silva', 10.0);
        [$cm2, $user2] = $this->seed_pending($course2, 'João Santos', 36.0);
        [$cm3, $user3] = $this->seed_pending($course1, 'Marina Costa', 24.0);

        $this->setAdminUser();
        $result = external_api::clean_returnvalue(
            get_grader_priority_list::execute_returns(),
            get_grader_priority_list::execute(50, '')
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, (int) $result['returned']);
        $names = array_map(static fn($s) => $s['studentname'], $result['submissions']);
        // Sorted by effectivehours DESC: 36, 24, 10.
        $this->assertSame(['João Santos', 'Marina Costa', 'Ana Silva'], $names);
    }

    /**
     * Teacher only sees submissions from courses they have viewdashboard
     * in (via the editingteacher archetype on the course context).
     */
    public function test_teacher_sees_only_enrolled_courses(): void {
        $this->resetAfterTest();

        $course1 = $this->getDataGenerator()->create_course(['fullname' => 'Mine']);
        $course2 = $this->getDataGenerator()->create_course(['fullname' => 'Not mine']);
        [$cm1] = $this->seed_pending($course1, 'My Student', 20.0);
        [$cm2] = $this->seed_pending($course2, 'Their Student', 99.0);

        $teacher = $this->getDataGenerator()->create_and_enrol($course1, 'editingteacher');
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(
            get_grader_priority_list::execute_returns(),
            get_grader_priority_list::execute(50, '')
        );

        $courseids = array_map(static fn($s) => (int) $s['courseid'], $result['submissions']);
        $this->assertSame([(int) $course1->id], array_values(array_unique($courseids)));
        $this->assertSame(1, (int) $result['returned']);
    }

    /**
     * The `limit` parameter caps the result count and is clamped to the
     * MAX_LIMIT ceiling.
     */
    public function test_limit_is_honoured_and_clamped(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        for ($i = 0; $i < 5; $i++) {
            $this->seed_pending($course, "Student {$i}", 10.0 + $i);
        }
        $this->setAdminUser();

        $result = external_api::clean_returnvalue(
            get_grader_priority_list::execute_returns(),
            get_grader_priority_list::execute(3, '')
        );
        $this->assertSame(3, (int) $result['returned']);
        $this->assertSame(3, (int) $result['limit']);

        // 99 > MAX_LIMIT (50) → clamped.
        $result = external_api::clean_returnvalue(
            get_grader_priority_list::execute_returns(),
            get_grader_priority_list::execute(99, '')
        );
        $this->assertSame(50, (int) $result['limit']);
    }

    /**
     * Bucket filter narrows results to one slabucket.
     */
    public function test_bucket_filter(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->seed_pending($course, 'Critical Student', 60.0, 'critical');
        $this->seed_pending($course, 'Good Student', 4.0, 'good');

        $this->setAdminUser();
        $result = external_api::clean_returnvalue(
            get_grader_priority_list::execute_returns(),
            get_grader_priority_list::execute(50, 'critical')
        );
        $this->assertSame(1, (int) $result['returned']);
        $this->assertSame('Critical Student', $result['submissions'][0]['studentname']);
        $this->assertSame('critical', $result['submissions'][0]['slabucket']);
    }

    /**
     * A plain student has no viewdashboard capability anywhere and is
     * rejected by the same path as the dashboard WS.
     */
    public function test_student_is_rejected(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\moodle_exception::class);
        get_grader_priority_list::execute();
    }

    /**
     * SEPARATEGROUPS without accessallgroups: a teacher only sees
     * submissions from groups they belong to. Other groups' rows are
     * filtered out by the per-course visibility WHERE-clause.
     */
    public function test_separategroups_filters_to_user_groups(): void {
        $this->resetAfterTest();
        \block_feedback_tracker\local\sla\group_access::reset_memo();

        $course = $this->getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        // Two students, one in each group, both with pending submissions.
        [$cma, $studenta] = $this->seed_pending($course, 'Ana Silva', 40.0);
        [$cmb, $studentb] = $this->seed_pending($course, 'Bruno Lima', 50.0);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        // Push the rollup rows to reflect the new groupids (seed_pending
        // inserts with groupid=0; reset it to match the membership).
        global $DB;
        $DB->set_field(
            'block_feedback_tracker_sub',
            'groupid',
            $groupa->id,
            ['userid' => $studenta->id, 'courseid' => $course->id]
        );
        $DB->set_field(
            'block_feedback_tracker_sub',
            'groupid',
            $groupb->id,
            ['userid' => $studentb->id, 'courseid' => $course->id]
        );

        // Use a CUSTOM role rather than editingteacher. The archetype
        // defaults to accessallgroups = allow, which would bypass
        // SEPARATEGROUPS; modifying its capabilities mid-test pollutes
        // accesslib's static role-cap cache (PHP statics survive
        // resetAfterTest). Touching a fresh custom role isolates the
        // change so subsequent tests in this class don't see polluted
        // role state.
        $coursectx = \context_course::instance($course->id);
        $roleid = create_role(
            'Test teacher (no allgroups)',
            'tnoallgroups_grader',
            'Test role with viewdashboard but without accessallgroups'
        );
        assign_capability(
            'block/feedback_tracker:viewdashboard',
            CAP_ALLOW,
            $roleid,
            $coursectx->id
        );
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'tnoallgroups_grader');
        $this->getDataGenerator()->create_group_member([
            'groupid' => $groupa->id, 'userid' => $teacher->id,
        ]);
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(
            get_grader_priority_list::execute_returns(),
            get_grader_priority_list::execute(50, '')
        );

        $names = array_map(static fn($s) => $s['studentname'], $result['submissions']);
        $this->assertSame(['Ana Silva'], $names, 'SEPARATEGROUPS teacher must only see own-group rows.');
    }

    /**
     * Already-graded submissions (timegraded NOT NULL) are excluded.
     */
    public function test_graded_submissions_are_excluded(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->seed_pending($course, 'Still Pending', 12.0);
        $this->seed_pending($course, 'Already Graded', 20.0, 'good', true);

        $this->setAdminUser();
        $result = external_api::clean_returnvalue(
            get_grader_priority_list::execute_returns(),
            get_grader_priority_list::execute(50, '')
        );

        $this->assertSame(1, (int) $result['returned']);
        $this->assertSame('Still Pending', $result['submissions'][0]['studentname']);
    }

    /**
     * Draft / new / reopened rows never surface in the priority list, even
     * when their effective hours would otherwise float them to the top.
     */
    public function test_non_submitted_are_excluded(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        // A high-hours draft that would otherwise top the list.
        $this->seed_pending($course, 'Draft Student', 500.0, 'critical', false, 'draft');
        $this->seed_pending($course, 'Submitted Student', 10.0, 'good', false, 'submitted');

        $this->setAdminUser();
        $result = external_api::clean_returnvalue(
            get_grader_priority_list::execute_returns(),
            get_grader_priority_list::execute(50, '')
        );

        $this->assertSame(1, (int) $result['returned']);
        $this->assertSame('Submitted Student', $result['submissions'][0]['studentname']);
    }

    // Helpers.

    /**
     * Insert one pending submission (with course-module + user + ledger
     * row) for the priority-list query to find. Returns [cm, user].
     *
     * @param \stdClass $course
     * @param string $studentname Used as the user's full name.
     * @param float $effective    Effective wait in hours (drives sort).
     * @param string $bucket
     * @param bool $alreadygraded If true, timegraded is filled (row should be excluded).
     * @param string $status Submission status to store (submitted|draft|new|reopened).
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function seed_pending(
        \stdClass $course,
        string $studentname,
        float $effective,
        string $bucket = 'good',
        bool $alreadygraded = false,
        string $status = 'submitted'
    ): array {
        global $DB;

        $parts = explode(' ', $studentname, 2);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', [
            'firstname' => $parts[0],
            'lastname'  => $parts[1] ?? '',
        ]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $now = time();
        $tsubmit = $now - 3600 * 12;
        $DB->insert_record('block_feedback_tracker_sub', (object) [
            'courseid'         => (int) $course->id,
            'groupid'          => 0,
            'cmid'             => (int) $cm->id,
            'iteminstance'     => (int) $assign->id,
            'userid'           => (int) $user->id,
            'attemptnumber'    => 0,
            'submissionstatus' => $status,
            'timesubmitted'    => $tsubmit,
            'timegraded'       => $alreadygraded ? $now : null,
            'hasrule'          => 0,
            'waitinghours'     => $effective * 1.2,
            'effectivehours'   => $effective,
            'effectiveasof'    => $now,
            'effectivecalver'  => 1,
            'slabucket'        => $bucket,
            'timecreated'      => $tsubmit,
            'timemodified'     => $now,
        ]);

        return [$cm, $user];
    }

    /**
     * The priority list authorises through dashboard_scope rather than
     * require_capability: an empty visible-course scope is the refusal.
     *
     * @return void
     */
    public function test_user_with_empty_scope_is_refused(): void {
        $this->resetAfterTest();

        $nobody = $this->getDataGenerator()->create_user();
        $this->setUser($nobody);
        \block_feedback_tracker\local\sla\dashboard_scope::reset_memo();

        $this->expectException(\required_capability_exception::class);
        get_grader_priority_list::execute();
    }
}
