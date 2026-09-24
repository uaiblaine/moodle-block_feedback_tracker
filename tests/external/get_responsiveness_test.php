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
 * Tests for the get_responsiveness external function.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\sla\rollup_service;
use core_external\external_api;

/**
 * Tests for retrieving responsiveness data via external functions.
 *
 * @covers \block_feedback_tracker\external\get_responsiveness
 * @covers \block_feedback_tracker\local\payload\responsiveness_payload
 */
final class get_responsiveness_test extends \advanced_testcase {
    /**
     * After seeding ledger + rollup, the WS returns the expected payload
     * shape and surfaces the score, band, and counts.
     */
    public function test_returns_group_card_for_seeded_rollup(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group->id,
            'userid'  => $teacher->id,
        ]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group->id,
            'userid'  => $student->id,
        ]);

        $now = time();
        global $DB;
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $DB->insert_record('block_feedback_tracker_sub', (object) [
            'courseid'         => $course->id,
            'groupid'          => $group->id,
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
        rollup_service::recompute_group((int) $course->id, (int) $group->id, $now);

        $this->setUser($teacher);
        $result = get_responsiveness::execute((int) $course->id);
        $result = external_api::clean_returnvalue(
            get_responsiveness::execute_returns(),
            $result
        );

        $this->assertTrue($result['success']);
        $this->assertSame((int) $course->id, $result['courseid']);
        // Without a limit the call returns every group at once and reports no
        // further page.
        $this->assertSame(1, $result['total']);
        $this->assertSame(0, $result['offset']);
        $this->assertSame(0, $result['limit']);
        $this->assertFalse($result['hasmore']);
        // Whole-course overall score is the pending-weighted mean; with one
        // scored group it equals that group's score.
        $this->assertArrayHasKey('overall_score', $result);
        $this->assertNotNull($result['overall_score']);
        $this->assertCount(1, $result['groups']);
        $card = $result['groups'][0];
        $this->assertEqualsWithDelta(
            (float) $card['responsiveness_score'],
            (float) $result['overall_score'],
            0.01
        );
        $this->assertSame((int) $group->id, $card['groupid']);
        $this->assertSame(0, $card['pending']);
        $this->assertSame(1, $card['numgraded30d']);
        $this->assertEqualsWithDelta(16.0, $card['median_eff_h'], 0.01);
        /*
         * Score for one graded submission with median 16h (renormalised without trend):
         *   compliance=1 (1/1 within 24h), median=1-16/48=0.667,
         *   critical=1, pending=1.
         *   100 * (0.4 + 0.25*0.667 + 0.15 + 0.10) / 0.90 ≈ 90.7 → "excellent".
         */
        $this->assertSame('excellent', $card['score_band']);

        // The payload carries the perceived / paused / peer keys with their
        // defaults. perceived_median_hours mirrors median_raw_h (waitinghours
        // 24.0 of the only ledger row).
        $this->assertArrayHasKey('perceived_median_hours', $card);
        $this->assertEqualsWithDelta(24.0, $card['perceived_median_hours'], 0.01);
        // The include-pending "current" medians feed the block's Effective /
        // Perceived tiles. With no pending work they equal the graded medians;
        // see rollup_service_test::test_cur_medians_include_pending.
        $this->assertArrayHasKey('cur_median_eff_h', $card);
        $this->assertEqualsWithDelta(16.0, $card['cur_median_eff_h'], 0.01);
        $this->assertArrayHasKey('cur_median_raw_h', $card);
        $this->assertEqualsWithDelta(24.0, $card['cur_median_raw_h'], 0.01);
        $this->assertArrayHasKey('paused_days_30d', $card);
        $this->assertIsInt($card['paused_days_30d']);
        $this->assertGreaterThanOrEqual(0, $card['paused_days_30d']);
        $this->assertArrayHasKey('paused_breakdown_30d', $card);
        $this->assertArrayHasKey('weekend', $card['paused_breakdown_30d']);
        $this->assertArrayHasKey('holiday', $card['paused_breakdown_30d']);
        $this->assertArrayHasKey('recess', $card['paused_breakdown_30d']);
        // Sub-day events of 'optional' calendar days; empty by default.
        $this->assertArrayHasKey('paused_events_30d', $card);
        $this->assertIsArray($card['paused_events_30d']);
        // Single-group fixture < MIN_SAMPLE for peer_stats, so peer
        // benchmarks come back null and the JS PeerContext hides itself.
        $this->assertArrayHasKey('peer_department_score', $card);
        $this->assertNull($card['peer_department_score']);
        $this->assertNull($card['peer_top10_score']);

        // Per-group assign schedule: the single date-less assign surfaces with
        // the create-rule action and null open/close, viewed by an editing
        // teacher (who holds mod/assign:manageoverrides).
        $this->assertArrayHasKey('activities', $card);
        $this->assertCount(1, $card['activities']);
        $this->assertSame((int) $cm->id, $card['activities'][0]['cmid']);
        $this->assertSame('create', $card['activities'][0]['action']);
        $this->assertTrue($card['activities'][0]['editable']);
        $this->assertNull($card['activities'][0]['opens']);
        $this->assertNull($card['activities'][0]['closes']);
    }

    /**
     * Strangers (unenrolled, no role) are rejected. Moodle raises a
     * require_login_exception in this case before the explicit
     * require_capability check fires; either is acceptable evidence of
     * the auth gate, so we expect the common base class.
     */
    public function test_capability_enforced(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $course = $this->getDataGenerator()->create_course();
        $stranger = $this->getDataGenerator()->create_user();

        $this->setUser($stranger);

        $this->expectException(\moodle_exception::class);
        get_responsiveness::execute((int) $course->id);
    }

    /**
     * limit + offset page the group list: each page returns at most $limit
     * groups in groupid order, total counts every visible group, and hasmore
     * flips to false only on the final page.
     */
    public function test_pagination_limit_and_offset(): void {
        $this->resetAfterTest();
        $this->seed_config();
        \block_feedback_tracker\local\sla\group_access::reset_memo();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $ids = $this->seed_groups_with_rollups($course, 3);

        $this->setUser($teacher);

        // First page: two groups, more to come.
        $page1 = external_api::clean_returnvalue(
            get_responsiveness::execute_returns(),
            get_responsiveness::execute((int) $course->id, false, 2, 0)
        );
        $this->assertSame(3, $page1['total']);
        $this->assertSame(0, $page1['offset']);
        $this->assertSame(2, $page1['limit']);
        $this->assertTrue($page1['hasmore']);
        $this->assertCount(2, $page1['groups']);
        $this->assertSame(
            [$ids[0], $ids[1]],
            array_map(static fn($g) => (int) $g['groupid'], $page1['groups'])
        );

        // Second page: the remaining group, nothing more.
        $page2 = external_api::clean_returnvalue(
            get_responsiveness::execute_returns(),
            get_responsiveness::execute((int) $course->id, false, 2, 2)
        );
        $this->assertSame(3, $page2['total']);
        $this->assertSame(2, $page2['offset']);
        $this->assertFalse($page2['hasmore']);
        $this->assertCount(1, $page2['groups']);
        $this->assertSame($ids[2], (int) $page2['groups'][0]['groupid']);
    }

    /**
     * limit = 0 returns every visible group in one call, with offset 0 and
     * hasmore false, for callers that pass no page size.
     */
    public function test_no_limit_returns_all_groups(): void {
        $this->resetAfterTest();
        $this->seed_config();
        \block_feedback_tracker\local\sla\group_access::reset_memo();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->seed_groups_with_rollups($course, 3);

        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(
            get_responsiveness::execute_returns(),
            get_responsiveness::execute((int) $course->id)
        );

        $this->assertSame(3, $result['total']);
        $this->assertSame(0, $result['offset']);
        $this->assertSame(0, $result['limit']);
        $this->assertFalse($result['hasmore']);
        $this->assertCount(3, $result['groups']);
    }

    /**
     * Pagination counts and pages only the groups the caller can see. A
     * SEPARATEGROUPS teacher who belongs to one group of three gets total = 1
     * and only that group, even with a page size that would otherwise span
     * the whole course (and the groupid 0 row, which only unrestricted users
     * see, stays hidden).
     */
    public function test_pagination_respects_visible_groups(): void {
        $this->resetAfterTest();
        $this->seed_config();
        \block_feedback_tracker\local\sla\group_access::reset_memo();

        $course = $this->getDataGenerator()->create_course([
            'groupmode'      => SEPARATEGROUPS,
            'groupmodeforce' => 1,
        ]);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupc = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->seed_rollup($course, (int) $groupa->id);
        $this->seed_rollup($course, (int) $groupb->id);
        $this->seed_rollup($course, (int) $groupc->id);
        // The "Ungrouped" row, which a restricted teacher must not see.
        $this->seed_rollup($course, 0);

        // Custom role: viewresponsiveness without moodle/site:accessallgroups.
        // The editingteacher archetype grants the latter, which would bypass
        // the SEPARATEGROUPS filter.
        $coursectx = \context_course::instance($course->id);
        $roleid = create_role(
            'Test teacher (no allgroups)',
            'tnoallgroups_resp',
            'Test role with viewresponsiveness but without accessallgroups'
        );
        assign_capability(
            'block/feedback_tracker:viewresponsiveness',
            CAP_ALLOW,
            $roleid,
            $coursectx->id
        );
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'tnoallgroups_resp');
        $this->getDataGenerator()->create_group_member([
            'groupid' => $groupa->id,
            'userid'  => $teacher->id,
        ]);
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(
            get_responsiveness::execute_returns(),
            get_responsiveness::execute((int) $course->id, false, 2, 0)
        );

        $this->assertSame(1, $result['total'], 'total must count only visible groups.');
        $this->assertFalse($result['hasmore']);
        $this->assertCount(1, $result['groups']);
        $this->assertSame((int) $groupa->id, (int) $result['groups'][0]['groupid']);
    }

    /**
     * Create $count real groups in $course, each with a materialised rollup
     * row, and return their ids sorted ascending (the order for_course pages).
     *
     * @param \stdClass $course
     * @param int $count
     * @return int[]
     */
    private function seed_groups_with_rollups(\stdClass $course, int $count): array {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
            $this->seed_rollup($course, (int) $group->id);
            $ids[] = (int) $group->id;
        }
        sort($ids);
        return $ids;
    }

    /**
     * sort=priority orders by most critical, then most overgoal, then the
     * worse (lower) score, regardless of groupid. Verified across two pages
     * so the server-side ORDER BY (not a client subset sort) is exercised.
     */
    public function test_sort_priority_orders_by_urgency(): void {
        $this->resetAfterTest();
        $this->seed_config();
        \block_feedback_tracker\local\sla\group_access::reset_memo();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        // Critical / overgoal / score chosen so priority order is B, A, C:
        // A,B tie on critical (5); B wins on overgoal (2 > 0); C last (0 crit).
        $ga = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $gb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $gc = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->seed_rollup($course, (int) $ga->id, 5, 0, 90.0, 5.0);
        $this->seed_rollup($course, (int) $gb->id, 5, 2, 50.0, 8.0);
        $this->seed_rollup($course, (int) $gc->id, 0, 9, 10.0, 99.0);

        $this->setUser($teacher);

        $page1 = external_api::clean_returnvalue(
            get_responsiveness::execute_returns(),
            get_responsiveness::execute((int) $course->id, false, 2, 0, 'priority')
        );
        $page2 = external_api::clean_returnvalue(
            get_responsiveness::execute_returns(),
            get_responsiveness::execute((int) $course->id, false, 2, 2, 'priority')
        );

        $order = array_merge(
            array_map(static fn($g) => (int) $g['groupid'], $page1['groups']),
            array_map(static fn($g) => (int) $g['groupid'], $page2['groups'])
        );
        $this->assertSame([(int) $gb->id, (int) $ga->id, (int) $gc->id], $order);
    }

    /**
     * sort=wait orders by longest median effective wait first.
     */
    public function test_sort_wait_orders_by_median(): void {
        $this->resetAfterTest();
        $this->seed_config();
        \block_feedback_tracker\local\sla\group_access::reset_memo();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $ga = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $gb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $gc = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->seed_rollup($course, (int) $ga->id, 1, 1, 80.0, 5.0);
        $this->seed_rollup($course, (int) $gb->id, 1, 1, 80.0, 99.0);
        $this->seed_rollup($course, (int) $gc->id, 1, 1, 80.0, 50.0);

        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(
            get_responsiveness::execute_returns(),
            get_responsiveness::execute((int) $course->id, false, 3, 0, 'wait')
        );

        $order = array_map(static fn($g) => (int) $g['groupid'], $result['groups']);
        $this->assertSame([(int) $gb->id, (int) $gc->id, (int) $ga->id], $order);
    }

    /**
     * overall_score is the pending-weighted mean of per-group scores across
     * the whole visible course (weight = max(1, pending)), independent of the
     * page window.
     */
    public function test_overall_score_is_pending_weighted_mean(): void {
        $this->resetAfterTest();
        $this->seed_config();
        \block_feedback_tracker\local\sla\group_access::reset_memo();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $ga = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $gb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        // Score 80 weight max(1,2)=2 -> 160; score 20 weight max(1,0)=1 -> 20.
        // (160 + 20) / (2 + 1) = 60.
        $this->seed_rollup($course, (int) $ga->id, 1, 1, 80.0, 10.0, 2);
        $this->seed_rollup($course, (int) $gb->id, 1, 1, 20.0, 10.0, 0);

        $this->setUser($teacher);

        // Fetch only the first group; overall still reflects the whole course.
        $result = external_api::clean_returnvalue(
            get_responsiveness::execute_returns(),
            get_responsiveness::execute((int) $course->id, false, 1, 0, 'default')
        );
        $this->assertSame(2, $result['total']);
        $this->assertCount(1, $result['groups']);
        $this->assertEqualsWithDelta(60.0, (float) $result['overall_score'], 0.01);
    }

    /**
     * overall_score is null when no visible group carries a score.
     */
    public function test_overall_score_null_without_scores(): void {
        $this->resetAfterTest();
        $this->seed_config();
        \block_feedback_tracker\local\sla\group_access::reset_memo();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->seed_rollup($course, (int) $group->id, 0, 0, null, 10.0, 0);

        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(
            get_responsiveness::execute_returns(),
            get_responsiveness::execute((int) $course->id)
        );
        $this->assertSame(1, $result['total']);
        $this->assertNull($result['overall_score']);
    }

    /**
     * Insert one materialised rollup row for a (course, group) pair. Mirrors
     * the shape rollup_service produces so group_payload reads non-null KPIs.
     * The metric params let ordering / overall-score tests vary the inputs.
     *
     * @param \stdClass $course
     * @param int $groupid
     * @param int $critical
     * @param int $overgoal
     * @param float|null $score
     * @param float $median
     * @param int $pending
     * @return void
     */
    private function seed_rollup(
        \stdClass $course,
        int $groupid,
        int $critical = 1,
        int $overgoal = 1,
        ?float $score = 80.0,
        float $median = 10.0,
        int $pending = 2
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
            'median_eff_h'         => $median,
            'p90_eff_h'            => 36.0,
            'max_eff_h'            => 50.0,
            'cur_median_eff_h'     => 18.0,
            'cur_median_raw_h'     => 22.0,
            'median_raw_h'         => 12.0,
            'p90_raw_h'            => 48.0,
            'max_raw_h'            => 72.0,
            'compliance_pct'       => 75.0,
            'compliance_pct_days'  => 66.0,
            'trend_pct_30d'        => -40.0,
            'responsiveness_score' => $score,
            'score_band'           => 'good',
            'timemodified'         => $now,
            'timecreated'          => $now,
        ]);
    }

    /**
     * Seed config used by the WS path.
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
        set_config('weight_compliance', '0.40', 'block_feedback_tracker');
        set_config('weight_median', '0.25', 'block_feedback_tracker');
        set_config('weight_critical', '0.15', 'block_feedback_tracker');
        set_config('weight_pending', '0.10', 'block_feedback_tracker');
        set_config('weight_trend', '0.10', 'block_feedback_tracker');

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
     * Titles composed from group custom fields pass the PARAM_TEXT return
     * validation and arrive as plain text: a text field holding "A & B" is
     * not escaped, and a text field with a link configured gives its text
     * instead of an <a>, which would make clean_returnvalue() reject the
     * whole response.
     *
     * @return void
     */
    public function test_custom_field_titles_are_plain_text(): void {
        $this->resetAfterTest();
        $this->seed_config();
        \block_feedback_tracker\local\sla\group_access::reset_memo();

        // Custom-field data is saved only for a user who can edit it.
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $category = $generator->create_custom_field_category([
            'component' => 'core_group',
            'area' => 'group',
        ]);
        $generator->create_custom_field([
            'categoryid' => (int) $category->get('id'),
            'type' => 'text',
            'shortname' => 'room',
        ]);
        $generator->create_custom_field([
            'categoryid' => (int) $category->get('id'),
            'type' => 'text',
            'shortname' => 'roomlink',
            'configdata' => ['link' => 'https://example.com/rooms/$$'],
        ]);
        set_config('group_title_fields', 'room', 'block_feedback_tracker');
        set_config('group_subtitle_fields', 'roomlink', 'block_feedback_tracker');

        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $group = $generator->create_group([
            'courseid' => $course->id,
            'name' => 'Raw name',
            'customfield_room' => 'A & B',
            'customfield_roomlink' => 'C & D',
        ]);
        $this->seed_rollup($course, (int) $group->id);

        $this->setUser($teacher);
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function(
            'block_feedback_tracker_get_responsiveness',
            ['courseid' => (int) $course->id]
        );

        $this->assertFalse($response['error'], (string) json_encode($response['exception'] ?? null));
        $result = external_api::clean_returnvalue(get_responsiveness::execute_returns(), $response['data']);
        $this->assertCount(1, $result['groups']);
        $this->assertSame((int) $group->id, (int) $result['groups'][0]['groupid']);
        $this->assertSame('A & B', $result['groups'][0]['groupname']);
        $this->assertSame('C & D', $result['groups'][0]['groupsubtitle']);
    }

    /**
     * Group and course names go through format_string() without escaping:
     * markup is stripped, so the PARAM_TEXT return validation accepts the
     * response, and the ampersand stays as typed.
     *
     * @return void
     */
    public function test_group_and_course_names_are_plain_text(): void {
        $this->resetAfterTest();
        $this->seed_config();
        \block_feedback_tracker\local\sla\group_access::reset_memo();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'A & <b>B</b>']);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'C & <b>D</b>']);
        $this->seed_rollup($course, (int) $group->id);

        $this->setUser($teacher);
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function(
            'block_feedback_tracker_get_responsiveness',
            ['courseid' => (int) $course->id]
        );

        $this->assertFalse($response['error'], (string) json_encode($response['exception'] ?? null));
        $card = $response['data']['groups'][0];
        $this->assertSame('C & D', $card['groupname']);
        $this->assertSame('A & B', $card['coursename']);
    }

    /**
     * The cached payload is keyed by the language: a second call in another
     * language within the cache lifetime rebuilds the group and activity names
     * in that language instead of serving the first language's payload.
     *
     * @return void
     */
    public function test_cached_payload_is_per_language(): void {
        global $SESSION;
        $this->resetAfterTest();
        $this->seed_config();
        \block_feedback_tracker\local\sla\group_access::reset_memo();
        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_applies_to_strings('multilang', true);
        \filter_manager::reset_caches();

        $multilang = '<span lang="en" class="multilang">A & B</span><span lang="es" class="multilang">C & D</span>';
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => $multilang]);
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => $multilang]);
        $this->seed_rollup($course, (int) $group->id);

        $this->setUser($teacher);
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function(
            'block_feedback_tracker_get_responsiveness',
            ['courseid' => (int) $course->id]
        );
        $this->assertFalse($response['error'], (string) json_encode($response['exception'] ?? null));
        $card = $response['data']['groups'][0];
        $this->assertSame('A & B', $card['groupname']);
        $this->assertCount(1, $card['activities']);
        $this->assertSame('A & B', $card['activities'][0]['name']);

        /* Set directly rather than through force_current_language(), which
         * refuses a language whose pack is not installed on the test site. */
        $SESSION->forcelang = 'es';
        $this->assertSame('es', current_language());
        try {
            $response = external_api::call_external_function(
                'block_feedback_tracker_get_responsiveness',
                ['courseid' => (int) $course->id]
            );
        } finally {
            unset($SESSION->forcelang);
        }
        $this->assertFalse($response['error'], (string) json_encode($response['exception'] ?? null));
        $card = $response['data']['groups'][0];
        $this->assertSame('C & D', $card['groupname']);
        $this->assertSame('C & D', $card['activities'][0]['name']);
    }

    /**
     * A student cannot read the responsiveness payload for their course.
     *
     * @return void
     */
    public function test_student_is_refused(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        get_responsiveness::execute((int) $course->id);
    }
}
