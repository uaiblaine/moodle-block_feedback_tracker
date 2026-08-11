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
 * Tests for the site-wide daily stats writer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Tests that the site aggregate counts only genuinely "submitted" graded work.
 *
 * @covers \block_feedback_tracker\local\sla\site_stats_service
 */
final class site_stats_service_test extends \advanced_testcase {
    /**
     * A graded draft on the same day is excluded from the site aggregate.
     *
     * @return void
     */
    public function test_excludes_non_submitted(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $gen->seed_default_platform_calendar();

        $course = $this->getDataGenerator()->create_course();
        $tz = new \DateTimeZone('UTC');
        $dt = new \DateTimeImmutable('2026-03-15 10:00:00', $tz);
        $ts = $dt->getTimestamp();
        $day = (int) $dt->format('Ymd');

        $gen->create_ledger_row([
            'courseid' => (int) $course->id, 'groupid' => 0,
            'submissionstatus' => submission_status::SUBMITTED,
            'timegraded' => $ts, 'effectivehours' => 8.0,
        ]);
        $gen->create_ledger_row([
            'courseid' => (int) $course->id, 'groupid' => 0,
            'submissionstatus' => submission_status::DRAFT,
            'timegraded' => $ts, 'effectivehours' => 80.0,
        ]);

        site_stats_service::recompute_for_day($day);

        $row = $DB->get_record('block_feedback_tracker_site', ['day' => $day]);
        $this->assertNotFalse($row);
        $this->assertSame(1, (int) $row->numgraded);
        $this->assertEqualsWithDelta(8.0, (float) $row->medianh_eff, 0.01);
    }

    /**
     * Every field of the day row, across several rows and groups.
     *
     * The aggregate is built by walking one day's graded submissions once and
     * accumulating six things at the same time. Only two of them were pinned,
     * so a change to how that walk is done — the read was materialising the
     * whole day into objects before building the arrays it actually needed —
     * could have altered the other four without any test noticing.
     *
     * @return void
     */
    public function test_the_whole_day_row_is_derived_from_the_graded_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $gen->seed_default_platform_calendar();
        set_config('sla_goal_hours', 24, 'block_feedback_tracker');

        $coursea = (int) $this->getDataGenerator()->create_course()->id;
        $courseb = (int) $this->getDataGenerator()->create_course()->id;
        $tz = new \DateTimeZone('UTC');
        $dt = new \DateTimeImmutable('2026-03-15 10:00:00', $tz);
        $ts = $dt->getTimestamp();
        $day = (int) $dt->format('Ymd');

        /* Odd count so the median is the middle value under any tie-breaking
         * rule. Four distinct (course, group) tuples over TWO courses that
         * reuse the same group numbers: keying the tuple set on groupid alone
         * would also yield 2 on a single-course fixture, which is how a real
         * site — where every course reuses group 0 — could under-report its
         * breadth without any test noticing.
         *
         * One value sits exactly ON the 24-hour goal, because the comparison is
         * `<=` and no fixture that avoids the boundary can tell that from `<`. */
        $rows = [
            [$coursea, 0, 4.0],
            [$coursea, 1, 8.0],
            [$courseb, 0, 12.0],
            [$courseb, 1, 24.0],
            [$coursea, 0, 100.0],
        ];
        foreach ($rows as [$courseid, $groupid, $eff]) {
            $gen->create_ledger_row([
                'courseid' => $courseid,
                'groupid' => $groupid,
                'submissionstatus' => submission_status::SUBMITTED,
                'timegraded' => $ts,
                'effectivehours' => $eff,
                'waitinghours' => $eff + 1.0,
            ]);
        }

        site_stats_service::recompute_for_day($day);

        $row = $DB->get_record('block_feedback_tracker_site', ['day' => $day]);
        $this->assertNotFalse($row);
        $this->assertSame(5, (int) $row->numgraded);
        $this->assertSame(4, (int) $row->numgroups, 'Four distinct (course, group) tuples contributed.');
        $this->assertEqualsWithDelta(12.0, (float) $row->medianh_eff, 0.01);
        $this->assertEqualsWithDelta(13.0, (float) $row->medianh_raw, 0.01, 'The raw clock has its own median.');
        $this->assertEqualsWithDelta(
            80.0,
            (float) $row->compliance_pct_site,
            0.01,
            'Four of five within goal — the one sitting exactly on it counts.'
        );
        /* Asserted as numbers, not as an ordering. An ordering against the
         * median holds just as well when the percentiles are computed from the
         * raw clock instead of the effective one, so a column named p10h_eff
         * could be filled from the wrong array and stay green. These values are
         * the linear interpolation stats::percentile() documents: rank is
         * p/100 * (n - 1) over the sorted effective hours. */
        $this->assertEqualsWithDelta(5.6, (float) $row->p10h_eff, 0.01);
        $this->assertEqualsWithDelta(69.6, (float) $row->p90h_eff, 0.01);
    }

    /**
     * A day with nothing graded writes a row of nulls, not a row of zeroes.
     *
     * Zero effective hours bands as the best possible result, so a quiet day
     * recorded as zero would read as a site-wide flawless turnaround. The
     * distinction lives in the `count ? … : null` arms, which only a day with
     * no rows at all reaches.
     *
     * @return void
     */
    public function test_a_day_with_nothing_graded_records_nulls(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $gen->seed_default_platform_calendar();

        $tz = new \DateTimeZone('UTC');
        $day = (int) (new \DateTimeImmutable('2026-03-16 10:00:00', $tz))->format('Ymd');

        site_stats_service::recompute_for_day($day);

        $row = $DB->get_record('block_feedback_tracker_site', ['day' => $day]);
        $this->assertNotFalse($row, 'The day is still recorded, so the absence is a fact rather than a gap.');
        $this->assertSame(0, (int) $row->numgraded);
        $this->assertSame(0, (int) $row->numgroups);
        $this->assertNull($row->medianh_eff);
        $this->assertNull($row->medianh_raw);
        $this->assertNull($row->compliance_pct_site);
    }
}
