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
 * Tests for rollup_service::recompute_group().
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Seeds the ledger with a mix of pending + graded rows, runs recompute_group,
 * and verifies pending / critical / overgoal counts, medians, score, and band.
 *
 * @covers \block_feedback_tracker\local\sla\rollup_service
 */
final class rollup_service_test extends \advanced_testcase {
    /**
     * Mix of pending + graded rows produces a rollup row with correct
     * counts, medians, compliance, and a band reflecting the score.
     */
    public function test_recompute_group_with_mixed_rows(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $courseid = 100;
        $groupid = 200;
        $now = time();

        // 4 pending rows partitioned across the exclusive bands: 2 critical
        // (>=120h), 1 over-goal (24 < eff < 120), 1 within-goal (<=goal).
        $this->insert_ledger($courseid, $groupid, ['effectivehours' => 150.0, 'timegraded' => null]);
        $this->insert_ledger($courseid, $groupid, ['effectivehours' => 130.0, 'timegraded' => null]);
        $this->insert_ledger($courseid, $groupid, ['effectivehours' => 50.0, 'timegraded' => null]);
        $this->insert_ledger($courseid, $groupid, ['effectivehours' => 5.0, 'timegraded' => null]);

        // 5 graded rows in last 30d: effective hours 10, 15, 20, 30, 60.
        // Median = 20, compliance = 60% (3 within 24h).
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 10.0, 'waitinghours' => 12.0, 'timegraded' => $now - 86400,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 15.0, 'waitinghours' => 20.0, 'timegraded' => $now - 86400,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 20.0, 'waitinghours' => 24.0, 'timegraded' => $now - 86400,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 30.0, 'waitinghours' => 50.0, 'timegraded' => $now - 86400,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 60.0, 'waitinghours' => 80.0, 'timegraded' => $now - 86400,
        ]);

        rollup_service::recompute_group($courseid, $groupid, $now);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_group', [
            'courseid' => $courseid, 'groupid' => $groupid,
        ]);
        $this->assertNotFalse($row);

        $this->assertSame(4, (int) $row->pending);
        $this->assertSame(2, (int) $row->critical);   // Critical: 150, 130 (>=120h).
        $this->assertSame(1, (int) $row->overgoal);    // Over goal: 50 (24 < eff < 120).
        // The three exclusive bands partition the total pending; the within-goal
        // count is derived as pending - critical - overgoal (the 5h row).
        $waiting = (int) $row->pending - (int) $row->critical - (int) $row->overgoal;
        $this->assertSame(1, $waiting);
        $this->assertSame(5, (int) $row->numgraded30d);
        $this->assertSame(20.0, (float) $row->median_eff_h);
        $this->assertEqualsWithDelta(60.0, (float) $row->compliance_pct, 0.01);
        $this->assertGreaterThan(0.0, (float) $row->responsiveness_score);
        $this->assertContains($row->score_band, ['excellent', 'good', 'regular', 'critical']);
    }

    /**
     * compliance_pct_days is the display-only business-days twin of
     * compliance_pct: it counts graded work returned within sla_goal_days
     * elapsed business days. It is computed from the submit/grade dates (so
     * the result is deterministic regardless of when the suite runs) and never
     * feeds the score, which stays hour-based — so the two compliance rulers
     * are independent.
     *
     * @return void
     */
    public function test_recompute_group_compliance_days(): void {
        $this->resetAfterTest();
        $this->seed_config();
        set_config('sla_goal_days', '2', 'block_feedback_tracker');

        $courseid = 140;
        $groupid = 240;
        $now = time();
        $day = 86400;

        // 3 graded rows returned the same day (0 business days elapsed -> within
        // the 2-day goal) and 2 graded rows returned 10 calendar days later
        // (>= 6 business days whatever the weekend alignment -> over goal).
        // Effective hours are all within 24h, so the hour-based compliance is a
        // clean 100% — proving the two rulers are independent.
        for ($i = 0; $i < 3; $i++) {
            $ts = $now - (5 * $day);
            $this->insert_ledger($courseid, $groupid, [
                'effectivehours' => 5.0,
                'timesubmitted'  => $ts,
                'timegraded'     => $ts,
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            $ts = $now - (12 * $day);
            $this->insert_ledger($courseid, $groupid, [
                'effectivehours' => 5.0,
                'timesubmitted'  => $ts,
                'timegraded'     => $ts + (10 * $day),
            ]);
        }

        rollup_service::recompute_group($courseid, $groupid, $now);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_group', [
            'courseid' => $courseid, 'groupid' => $groupid,
        ]);
        $this->assertNotFalse($row);
        // 3 of 5 within the 2-business-day goal.
        $this->assertEqualsWithDelta(60.0, (float) $row->compliance_pct_days, 0.01);
        // Hour-based compliance is unaffected: all 5 are within 24 effective hours.
        $this->assertEqualsWithDelta(100.0, (float) $row->compliance_pct, 0.01);
    }

    /**
     * Empty ledger produces a rollup with zero counts and null medians. A
     * group with no submitted work at all has no responsiveness to measure,
     * so the score is null and the band is the neutral 'nodata' — never a
     * misleading charitable 100 that would top the dashboard or skew
     * averages.
     */
    public function test_recompute_group_with_no_rows(): void {
        $this->resetAfterTest();
        $this->seed_config();

        rollup_service::recompute_group(101, 201);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_group', [
            'courseid' => 101, 'groupid' => 201,
        ]);
        $this->assertNotFalse($row);
        $this->assertSame(0, (int) $row->pending);
        $this->assertSame(0, (int) $row->critical);
        $this->assertSame(0, (int) $row->numgraded30d);
        $this->assertNull($row->median_eff_h);
        $this->assertNull($row->cur_median_eff_h);
        $this->assertNull($row->cur_median_raw_h);
        $this->assertNull($row->responsiveness_score);
        $this->assertSame('nodata', $row->score_band);
    }

    /**
     * The headline "current" medians (cur_median_eff_h / cur_median_raw_h)
     * blend graded-in-window work with currently-pending work so the
     * dashboard reflects the live backlog, while the graded-only median_eff_h
     * (which feeds the score) stays untouched.
     */
    public function test_cur_medians_include_pending(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $courseid = 106;
        $groupid = 206;
        $now = time();

        // 3 graded rows in the last 30 days: effective 10, 20, 30 (median 20);
        // raw 10, 20, 30 (median 20).
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 10.0, 'waitinghours' => 10.0, 'timegraded' => $now - 86400,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 20.0, 'waitinghours' => 20.0, 'timegraded' => $now - 86400,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 30.0, 'waitinghours' => 30.0, 'timegraded' => $now - 86400,
        ]);
        // 2 pending (ungraded) rows with a large accrued wait: effective +
        // raw 100, 200.
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 100.0, 'waitinghours' => 100.0, 'timegraded' => null,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 200.0, 'waitinghours' => 200.0, 'timegraded' => null,
        ]);

        rollup_service::recompute_group($courseid, $groupid, $now);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_group', [
            'courseid' => $courseid, 'groupid' => $groupid,
        ]);
        $this->assertNotFalse($row);

        // Graded-only median (score input) is unchanged at 20.
        $this->assertSame(20.0, (float) $row->median_eff_h);
        // Combined sets [10,20,30,100,200] → median 30 for both eff and raw.
        $this->assertSame(30.0, (float) $row->cur_median_eff_h);
        $this->assertSame(30.0, (float) $row->cur_median_raw_h);
    }

    /**
     * Trend pct is the % change between this rolling 7-day window and the
     * prior 7-day window (week over week).
     */
    public function test_trend_calculation(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $courseid = 102;
        $groupid = 202;
        $now = time();

        // Prior week (8-12 days ago): median 50.
        for ($i = 0; $i < 5; $i++) {
            $this->insert_ledger($courseid, $groupid, [
                'effectivehours' => 50.0,
                'waitinghours' => 50.0,
                'timegraded' => $now - 86400 * (8 + $i),
            ]);
        }
        // Recent week (last 7 days): median 25 (improving = negative trend).
        for ($i = 0; $i < 5; $i++) {
            $this->insert_ledger($courseid, $groupid, [
                'effectivehours' => 25.0,
                'waitinghours' => 25.0,
                'timegraded' => $now - 86400 * ($i + 1),
            ]);
        }

        rollup_service::recompute_group($courseid, $groupid, $now);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_group', [
            'courseid' => $courseid, 'groupid' => $groupid,
        ]);
        // Math: (25 - 50) / 50 * 100 = -50% (improving).
        $this->assertEqualsWithDelta(-50.0, (float) $row->trend_pct_30d, 0.01);
    }

    /**
     * Trend pct is clamped to ±TREND_PCT_CAP so a near-zero prior median can't
     * produce a value that overflows the NUMBER(6,2) trend_pct_30d column:
     * a prior median of 0.05h against a recent 200h is a ~399900% change.
     */
    public function test_trend_pct_is_clamped_to_avoid_overflow(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $courseid = 105;
        $groupid = 205;
        $now = time();

        // Prior week (8-12 days ago): tiny median (0.05h) → blows the ratio up.
        for ($i = 0; $i < 5; $i++) {
            $this->insert_ledger($courseid, $groupid, [
                'effectivehours' => 0.05,
                'waitinghours' => 0.05,
                'timegraded' => $now - 86400 * (8 + $i),
            ]);
        }
        // Recent week (last 7 days): large median (200h) — a massive regression.
        for ($i = 0; $i < 5; $i++) {
            $this->insert_ledger($courseid, $groupid, [
                'effectivehours' => 200.0,
                'waitinghours' => 200.0,
                'timegraded' => $now - 86400 * ($i + 1),
            ]);
        }

        rollup_service::recompute_group($courseid, $groupid, $now);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_group', [
            'courseid' => $courseid, 'groupid' => $groupid,
        ]);
        $this->assertNotFalse($row);
        $this->assertSame(rollup_service::TREND_PCT_CAP, (float) $row->trend_pct_30d);
    }

    /**
     * Subsequent calls update the same rollup row, not insert a duplicate.
     */
    public function test_recompute_is_idempotent(): void {
        $this->resetAfterTest();
        $this->seed_config();

        rollup_service::recompute_group(103, 203);
        rollup_service::recompute_group(103, 203);
        rollup_service::recompute_group(103, 203);

        global $DB;
        $rows = $DB->get_records('block_feedback_tracker_group', [
            'courseid' => 103, 'groupid' => 203,
        ]);
        $this->assertCount(1, $rows);
    }

    /**
     * Only genuinely "submitted" work counts. Draft / new / reopened rows are
     * excluded from the pending counts and from the graded-window stats.
     */
    public function test_only_submitted_counts(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $courseid = 104;
        $groupid = 204;
        $now = time();

        // One genuinely-submitted pending row (counts).
        $this->insert_ledger($courseid, $groupid, [
            'submissionstatus' => submission_status::SUBMITTED,
            'effectivehours' => 5.0, 'timegraded' => null,
        ]);
        // Draft / new / reopened pending rows — all ignored.
        $this->insert_ledger($courseid, $groupid, [
            'submissionstatus' => submission_status::DRAFT,
            'effectivehours' => 5.0, 'timegraded' => null,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'submissionstatus' => submission_status::NEW,
            'effectivehours' => 5.0, 'timegraded' => null,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'submissionstatus' => submission_status::REOPENED,
            'effectivehours' => 5.0, 'timegraded' => null,
        ]);
        // A graded draft — must not enter the graded-window stats.
        $this->insert_ledger($courseid, $groupid, [
            'submissionstatus' => submission_status::DRAFT,
            'effectivehours' => 10.0, 'timegraded' => $now - 86400,
        ]);
        // A graded submitted row — counts.
        $this->insert_ledger($courseid, $groupid, [
            'submissionstatus' => submission_status::SUBMITTED,
            'effectivehours' => 20.0, 'timegraded' => $now - 86400,
        ]);

        rollup_service::recompute_group($courseid, $groupid, $now);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_group', [
            'courseid' => $courseid, 'groupid' => $groupid,
        ]);
        $this->assertNotFalse($row);
        $this->assertSame(1, (int) $row->pending);
        $this->assertSame(1, (int) $row->numgraded30d);
    }

    // The lock-held branch of recompute_group() has no test. The lock is held
    // per database connection and lock_config::get_lock_factory() returns a new
    // factory on every call, so a second acquisition from the same process
    // succeeds; a concurrent holder cannot be simulated without mocking the factory.

    /**
     * The day-ruler over-goal count is bounded by the SLA goal in days, as the
     * hour one is by the SLA goal in hours, not by the first bucket threshold.
     *
     * "Now" is pinned to Thursday 12 March 2026, noon UTC, so the business-day
     * counts are fixed: three since the Monday before, eight since the Monday
     * a week earlier.
     *
     * @return void
     */
    public function test_overgoal_days_is_bounded_by_the_day_sla_goal(): void {
        $this->resetAfterTest();
        $this->seed_config();
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('bucket_thresholds_days', '2,5,10', 'block_feedback_tracker');
        set_config('sla_goal_days', '4', 'block_feedback_tracker');
        \block_feedback_tracker\local\calendar\academic_time::reset_memos();

        $courseid = 150;
        $groupid = 250;
        $now = (new \DateTimeImmutable('2026-03-12 12:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $this->insert_ledger($courseid, $groupid, [
            'timesubmitted' => $now - 3 * 86400,
            'timegraded' => null,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'timesubmitted' => $now - 10 * 86400,
            'timegraded' => null,
        ]);

        rollup_service::recompute_group($courseid, $groupid, $now);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_group', ['courseid' => $courseid, 'groupid' => $groupid]);
        $this->assertSame(2, (int) $row->pending);
        $this->assertSame(1, (int) $row->overgoal_days, 'Three business days is within a four-day goal; eight is not.');
        $this->assertSame(0, (int) $row->critical_days, 'Control: eight business days is not past the ten-day cutoff.');
    }

    /**
     * Only an activity that allocates markers can leave work unallocated, so
     * pending work in an activity without marking allocation is not counted.
     *
     * @return void
     */
    public function test_unallocated_counts_only_activities_that_allocate(): void {
        $this->resetAfterTest();
        $this->seed_config();
        $course = $this->getDataGenerator()->create_course();
        $allocating = $this->assign_cmid((int) $course->id, ['markingworkflow' => 1, 'markingallocation' => 1]);
        $plain = $this->assign_cmid((int) $course->id, []);

        $this->insert_ledger((int) $course->id, 0, ['cmid' => $allocating, 'userid' => 11, 'timeallocated' => null]);
        $this->insert_ledger((int) $course->id, 0, ['cmid' => $allocating, 'userid' => 12, 'timeallocated' => time() - 600]);
        $this->insert_ledger((int) $course->id, 0, ['cmid' => $plain, 'userid' => 11, 'timeallocated' => null]);
        $this->insert_ledger((int) $course->id, 0, ['cmid' => $plain, 'userid' => 12, 'timeallocated' => null]);

        rollup_service::recompute_group((int) $course->id, 0);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_group', ['courseid' => $course->id, 'groupid' => 0]);
        $this->assertSame(4, (int) $row->pending, 'Every pending row still counts as pending.');
        $this->assertSame('1', (string) $row->unallocated);
    }

    /**
     * With no pending work in an activity that allocates markers, the figure
     * does not apply and is stored as null, not as the pending count.
     *
     * @return void
     */
    public function test_unallocated_is_null_without_an_allocating_activity(): void {
        $this->resetAfterTest();
        $this->seed_config();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        /* Allocation without marking workflow does nothing in mod_assign, which
         * clears the flag on save; written directly, as a restore could leave it. */
        $workflowoff = $this->assign_cmid((int) $course->id, ['markingworkflow' => 0]);
        $DB->set_field('assign', 'markingallocation', 1, [
            'id' => $DB->get_field('course_modules', 'instance', ['id' => $workflowoff]),
        ]);
        $plain = $this->assign_cmid((int) $course->id, []);

        $this->insert_ledger((int) $course->id, 0, ['cmid' => $workflowoff, 'timeallocated' => null]);
        $this->insert_ledger((int) $course->id, 0, ['cmid' => $plain, 'timeallocated' => null]);

        rollup_service::recompute_group((int) $course->id, 0);

        $row = $DB->get_record('block_feedback_tracker_group', ['courseid' => $course->id, 'groupid' => 0]);
        $this->assertSame(2, (int) $row->pending);
        $this->assertNull($row->unallocated);
    }

    // Helpers.

    /**
     * Create an assign in the course and return its course-module id.
     *
     * @param int $courseid
     * @param array $settings Extra {assign} settings.
     * @return int
     */
    private function assign_cmid(int $courseid, array $settings): int {
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $courseid] + $settings);
        return (int) get_coursemodule_from_instance('assign', $assign->id)->id;
    }

    /**
     * Seed score/SLA config.
     */
    private function seed_config(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('sla_goal_hours', '24', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');
        set_config('weight_compliance', '0.40', 'block_feedback_tracker');
        set_config('weight_median', '0.25', 'block_feedback_tracker');
        set_config('weight_critical', '0.15', 'block_feedback_tracker');
        set_config('weight_pending', '0.10', 'block_feedback_tracker');
        set_config('weight_trend', '0.10', 'block_feedback_tracker');
        set_config('trend_window_days', '30', 'block_feedback_tracker');
    }

    /**
     * Insert a fully-formed ledger row with sensible defaults; only the keys
     * provided in $overrides are set explicitly.
     *
     * @param int $courseid
     * @param int $groupid
     * @param array $overrides
     * @return int New row id.
     */
    private function insert_ledger(int $courseid, int $groupid, array $overrides): int {
        global $DB;
        static $cmid = 10000;
        $cmid++;
        $now = time();
        $defaults = [
            'courseid'         => $courseid,
            'groupid'          => $groupid,
            'cmid'             => $cmid,
            'iteminstance'     => $cmid,
            'userid'           => 1,
            'attemptnumber'    => 0,
            'submissionstatus' => 'submitted',
            'timesubmitted'    => $now - 7200,
            'timegraded'       => null,
            'timeopens'        => null,
            'timecloses'       => null,
            'timecutoff'       => null,
            'hasrule'          => 0,
            'waitinghours'     => 0.0,
            'effectivehours'   => 0.0,
            'effectiveasof'    => $now,
            'effectivecalver'  => 1,
            'slabucket'        => 'excellent',
            'timecreated'      => $now,
            'timemodified'     => $now,
        ];
        $rec = (object) array_merge($defaults, $overrides);
        return (int) $DB->insert_record('block_feedback_tracker_sub', $rec);
    }
}
