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
 * Tests for the get_dashboard external function.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use core_external\external_api;

/**
 * Covers the cross-course aggregation + per-user capability filter.
 *
 * @covers \block_feedback_tracker\external\get_dashboard
 */
final class get_dashboard_test extends \advanced_testcase {
    /**
     * Reset the per-request dashboard_scope and group_access memos.
     *
     * Both are PHP statics, which resetAfterTest does not clear, while course
     * and user ids are reused across tests. A stale entry would serve another
     * test's course scope (keyed by userid) or group filter (keyed
     * "courseid:userid") and make an enrolled course vanish from the result.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        \block_feedback_tracker\local\sla\dashboard_scope::reset_memo();
        \block_feedback_tracker\local\sla\group_access::reset_memo();
    }

    /**
     * With enable_admin_view_all on, a site admin sees the aggregate for
     * every course with rollup rows regardless of enrolment. (With the
     * setting off — the default — an unenrolled admin is scoped to nothing;
     * see test_admin_without_view_all_is_scoped.)
     */
    public function test_admin_sees_all_courses(): void {
        $this->resetAfterTest();
        $this->seed_config();

        [$course1, $course2, $course3] = $this->build_three_courses();
        $this->seed_rollup($course1, 12, 4, 5, 65);
        $this->seed_rollup($course2, 5, 1, 2, 78);
        $this->seed_rollup($course3, 0, 0, 0, 90);

        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');
        $this->setAdminUser();
        \block_feedback_tracker\local\sla\dashboard_scope::reset_memo();
        $result = external_api::clean_returnvalue(
            get_dashboard::execute_returns(),
            get_dashboard::execute('')
        );

        $this->assertTrue($result['success']);
        $courseids = array_map(static fn($c) => $c['courseid'], $result['courses']);
        $this->assertContains((int) $course1->id, $courseids);
        $this->assertContains((int) $course2->id, $courseids);
        $this->assertContains((int) $course3->id, $courseids);
    }

    /**
     * With enable_admin_view_all off (default), a site admin with no
     * teaching enrolment is scoped like a normal user and is rejected —
     * the setting is the explicit gate for site-wide visibility.
     */
    public function test_admin_without_view_all_is_scoped(): void {
        $this->resetAfterTest();
        $this->seed_config();

        [$course1] = $this->build_three_courses();
        $this->seed_rollup($course1, 12, 4, 5, 65);

        // Default: enable_admin_view_all is off.
        $this->setAdminUser();
        \block_feedback_tracker\local\sla\dashboard_scope::reset_memo();

        $this->expectException(\required_capability_exception::class);
        get_dashboard::execute('');
    }

    /**
     * Editing teacher in one course sees only that course's row, even though
     * rollup rows exist for two others. The editingteacher archetype grants
     * `viewdashboard` at course context, and dashboard_scope limits the
     * result to enrolled courses where the user holds it.
     */
    public function test_teacher_sees_only_enrolled_course(): void {
        $this->resetAfterTest();
        $this->seed_config();

        [$course1, $course2, $course3] = $this->build_three_courses();
        $this->seed_rollup($course1, 12, 4, 5, 65);
        $this->seed_rollup($course2, 5, 1, 2, 78);
        $this->seed_rollup($course3, 0, 0, 0, 90);

        $teacher = $this->getDataGenerator()->create_and_enrol($course1, 'editingteacher');
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(
            get_dashboard::execute_returns(),
            get_dashboard::execute('')
        );

        $this->assertTrue($result['success']);
        $courseids = array_map(static fn($c) => (int) $c['courseid'], $result['courses']);
        $this->assertSame([(int) $course1->id], $courseids);
    }

    /**
     * Teacher in two courses sees both, sorted by pending DESC.
     */
    public function test_teacher_sees_union_of_enrolled_courses(): void {
        $this->resetAfterTest();
        $this->seed_config();

        [$course1, $course2, $course3] = $this->build_three_courses();
        $this->seed_rollup($course1, 5, 1, 2, 78);
        $this->seed_rollup($course2, 12, 4, 5, 65);
        $this->seed_rollup($course3, 99, 50, 80, 25);

        $teacher = $this->getDataGenerator()->create_and_enrol($course1, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $course2->id, 'editingteacher');
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(
            get_dashboard::execute_returns(),
            get_dashboard::execute('')
        );

        $this->assertTrue($result['success']);
        $courseids = array_map(static fn($c) => (int) $c['courseid'], $result['courses']);
        $this->assertCount(2, $courseids);
        $this->assertContains((int) $course1->id, $courseids);
        $this->assertContains((int) $course2->id, $courseids);
        $this->assertNotContains((int) $course3->id, $courseids);

        // Default sort is pending DESC — course2 (12) before course1 (5).
        $this->assertSame((int) $course2->id, (int) $result['courses'][0]['courseid']);
        $this->assertSame((int) $course1->id, (int) $result['courses'][1]['courseid']);
    }

    /**
     * A plain student has no `viewdashboard` cap anywhere. The WS throws —
     * required_capability_exception is a subclass of moodle_exception.
     */
    public function test_student_is_rejected(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\moodle_exception::class);
        get_dashboard::execute('');
    }

    /**
     * Under SEPARATEGROUPS a teacher in only group A of a multi-group course
     * sees numgroups=1 and only group A's aggregates, not the SUM across the
     * whole course. Pins the group_access::visible_group_ids() filter that
     * dashboard_scope::sql_visibility() applies.
     */
    public function test_separategroups_filters_numgroups_and_aggregates(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_config();

        $course = $this->getDataGenerator()->create_course([
            'groupmode' => SEPARATEGROUPS,
            'groupmodeforce' => 1,
        ]);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        // One rollup row per group, plus the groupid=0 ("Ungrouped") row that
        // only unrestricted users see.
        $this->seed_rollup($course, 3, 1, 1, 75, 'good', (int) $groupa->id);
        $this->seed_rollup($course, 10, 5, 4, 30, 'critical', (int) $groupb->id);
        $this->seed_rollup($course, 0, 0, 0, 95, 'excellent', 0);

        // A custom role holding viewdashboard but not accessallgroups: the
        // editingteacher archetype grants moodle/site:accessallgroups, which
        // would lift the SEPARATEGROUPS restriction.
        $coursectx = \context_course::instance($course->id);
        $roleid = create_role(
            'Test teacher (no allgroups)',
            'tnoallgroups_dash',
            'Test role with viewdashboard but without accessallgroups'
        );
        assign_capability(
            'block/feedback_tracker:viewdashboard',
            CAP_ALLOW,
            $roleid,
            $coursectx->id
        );
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'tnoallgroups_dash');
        $this->getDataGenerator()->create_group_member([
            'groupid' => $groupa->id, 'userid' => $teacher->id,
        ]);
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(
            get_dashboard::execute_returns(),
            get_dashboard::execute('')
        );

        $this->assertCount(1, $result['courses']);
        $row = $result['courses'][0];
        $this->assertSame(1, (int) $row['numgroups'], 'numgroups must count only visible groups.');
        $this->assertSame(3, (int) $row['pending'], 'pending must sum only visible groups.');
        $this->assertSame(1, (int) $row['critical']);
        $this->assertSame(1, (int) $row['overgoal']);
    }

    /**
     * Band filter narrows the result. Teacher in two courses; only one of
     * them has a "good" band.
     */
    public function test_band_filter_narrows_courses(): void {
        $this->resetAfterTest();
        $this->seed_config();

        [$course1, $course2] = $this->build_three_courses();
        $this->seed_rollup($course1, 5, 1, 2, 78, 'good');
        $this->seed_rollup($course2, 12, 4, 5, 65, 'regular');

        $teacher = $this->getDataGenerator()->create_and_enrol($course1, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $course2->id, 'editingteacher');
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(
            get_dashboard::execute_returns(),
            get_dashboard::execute('good')
        );

        $courseids = array_map(static fn($c) => (int) $c['courseid'], $result['courses']);
        $this->assertSame([(int) $course1->id], $courseids);
    }

    /**
     * The per-course row carries the include-pending headline medians
     * (cur_median_eff_h / cur_median_raw_h) plus the trend and compliance
     * figures DashboardView's aggregate() reads for the hero; a key the WS
     * omits leaves the hero's trend and SLA blank without any error.
     */
    public function test_returns_headline_trend_and_compliance(): void {
        $this->resetAfterTest();
        $this->seed_config();

        [$course1] = $this->build_three_courses();
        $this->seed_rollup($course1, 12, 4, 5, 65);

        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');
        $this->setAdminUser();
        \block_feedback_tracker\local\sla\dashboard_scope::reset_memo();

        $result = external_api::clean_returnvalue(
            get_dashboard::execute_returns(),
            get_dashboard::execute('')
        );

        $row = null;
        foreach ($result['courses'] as $c) {
            if ((int) $c['courseid'] === (int) $course1->id) {
                $row = $c;
            }
        }
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(18.0, (float) $row['cur_median_eff_h'], 0.01);
        $this->assertEqualsWithDelta(22.0, (float) $row['cur_median_raw_h'], 0.01);
        $this->assertEqualsWithDelta(-40.0, (float) $row['trend_pct_30d'], 0.01);
        $this->assertEqualsWithDelta(75.0, (float) $row['compliance_pct'], 0.01);
        $this->assertEqualsWithDelta(88.0, (float) $row['compliance_pct_days'], 0.01);
    }

    // Helpers.

    /**
     * Build three throwaway courses.
     *
     * @return array<int, \stdClass>
     */
    private function build_three_courses(): array {
        return [
            $this->getDataGenerator()->create_course(['fullname' => 'Course A']),
            $this->getDataGenerator()->create_course(['fullname' => 'Course B']),
            $this->getDataGenerator()->create_course(['fullname' => 'Course C']),
        ];
    }

    /**
     * Insert one rollup row for (courseid, groupid), groupid 0 by default.
     * One row per course is enough to exercise the aggregate path since the
     * SQL groups by courseid.
     *
     * @param \stdClass $course
     * @param int $pending
     * @param int $critical
     * @param int $overgoal
     * @param int $score
     * @param string $band
     * @param int $groupid
     * @return void
     */
    private function seed_rollup(
        \stdClass $course,
        int $pending,
        int $critical,
        int $overgoal,
        int $score,
        string $band = 'regular',
        int $groupid = 0
    ): void {
        global $DB;
        $now = time();
        $DB->insert_record('block_feedback_tracker_group', (object) [
            'courseid'             => (int) $course->id,
            'groupid'              => $groupid,
            'pending'              => $pending,
            'critical'             => $critical,
            'overgoal'             => $overgoal,
            'numgraded30d'         => 30,
            'median_eff_h'         => 10.0,
            'p90_eff_h'            => 36.0,
            'max_eff_h'            => 50.0,
            'cur_median_eff_h'     => 18.0,
            'cur_median_raw_h'     => 22.0,
            'median_raw_h'         => 12.0,
            'p90_raw_h'            => 48.0,
            'max_raw_h'            => 72.0,
            'compliance_pct'       => 75.0,
            'compliance_pct_days'  => 88.0,
            'trend_pct_30d'        => -40.0,
            'responsiveness_score' => $score,
            'score_band'           => $band,
            'timemodified'         => $now,
            'timecreated'          => $now,
        ]);
    }

    /**
     * Seed the minimal block config used by the WS path. The dashboard
     * doesn't recompute SLA or run the academic-time engine, so this is
     * lighter than get_responsiveness_test::seed_config().
     *
     * @return void
     */
    private function seed_config(): void {
        set_config('calver', '1', 'block_feedback_tracker');
    }
}
