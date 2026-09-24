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
 * Tests for the academic-time engine.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Hand-computed worked examples: weekend gaps, holiday clusters, manual pause
 * overlap (course / group), split shifts, timezone-aware day boundaries, and
 * calver-driven cache invalidation. academic_time_fastpath_test relies on
 * these to trust the day-by-day walker as its oracle.
 *
 * @covers \block_feedback_tracker\local\calendar\academic_time
 */
final class academic_time_test extends \advanced_testcase {
    /**
     * Submit Fri 17:00, grade Mon 09:00, Mon-Fri 08:00-18:00 business hours.
     *
     * Effective = Fri 17:00-18:00 (1h) + Mon 08:00-09:00 (1h) = 2.0
     * Raw       = 64.0 wall-clock hours.
     */
    public function test_friday_evening_to_monday_morning(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();

        $tsfrom = $this->ts('2026-05-15 17:00:00');
        $tsto   = $this->ts('2026-05-18 09:00:00');

        $result = academic_time::elapsed_with_audit(0, 0, $tsfrom, $tsto);

        $this->assertSame(2.0, $result['hours']);
        $this->assertSame(64.0, ($tsto - $tsfrom) / 3600.0);
    }

    /**
     * Grade-during-weekend, clipped mode: Fri 17:00 -> Sat 14:00.
     *
     * Effective = Fri 17:00-18:00 (1h). The Sat portion is paused.
     * Audit must include outofhours(Fri 18:00-Sat 00:00) and weekend(Sat 00:00-Sat 14:00).
     */
    public function test_grade_during_weekend_clipped(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        set_config('grading_during_pause_mode', 'clipped', 'block_feedback_tracker');
        academic_time::reset_memos();

        $tsfrom = $this->ts('2026-05-15 17:00:00');
        $tsto   = $this->ts('2026-05-16 14:00:00');

        $result = academic_time::elapsed_with_audit(0, 0, $tsfrom, $tsto);

        $this->assertSame(1.0, $result['hours']);
        $this->assertSame(21.0, ($tsto - $tsfrom) / 3600.0);

        $reasons = array_column($result['pauses'], 'reason');
        $this->assertContains('outofhours', $reasons);
        $this->assertContains('weekend', $reasons);

        $byreason = [];
        foreach ($result['pauses'] as $p) {
            $byreason[$p['reason']] = $p;
        }
        $this->assertSame($this->ts('2026-05-15 18:00:00'), $byreason['outofhours']['timestart']);
        $this->assertSame($this->ts('2026-05-16 00:00:00'), $byreason['outofhours']['timeend']);
        $this->assertSame($this->ts('2026-05-16 00:00:00'), $byreason['weekend']['timestart']);
        $this->assertSame($this->ts('2026-05-16 14:00:00'), $byreason['weekend']['timeend']);
    }

    /**
     * Grade-during-manual-pause, live mode: course-paused window does NOT
     * subtract from effective hours but is recorded in the audit.
     */
    public function test_grade_during_manual_pause_live(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        set_config('grading_during_pause_mode', 'live', 'block_feedback_tracker');
        $course = $this->getDataGenerator()->create_course();
        $this->add_cpause(
            'course',
            (int) $course->id,
            \context_course::instance($course->id)->id,
            $this->ts('2026-05-19 00:00:00'),
            $this->ts('2026-05-21 00:00:00')
        );
        academic_time::reset_memos();

        // Submit Mon 09:00, grade Wed 14:00 with course paused Tue+Wed.
        $tsfrom = $this->ts('2026-05-18 09:00:00');
        $tsto   = $this->ts('2026-05-20 14:00:00');

        $result = academic_time::elapsed_with_audit((int) $course->id, 0, $tsfrom, $tsto);

        // In live mode, manual pause does not subtract:
        // Mon 09:00-18:00 (9h) + Tue 08:00-18:00 (10h) + Wed 08:00-14:00 (6h) = 25h.
        $this->assertSame(25.0, $result['hours']);

        $coursepaused = array_filter($result['pauses'], static fn($p) => $p['reason'] === 'coursepaused');
        $this->assertNotEmpty($coursepaused);
    }

    /**
     * Spans an Easter cluster (Fri holiday + Sat/Sun weekend + Mon holiday)
     * with the engine producing zero effective hours.
     */
    public function test_spans_easter_cluster(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        $this->add_cday('20260403', 'holiday', 'Good Friday');
        $this->add_cday('20260406', 'holiday', 'Easter Monday');
        academic_time::reset_memos();

        $tsfrom = $this->ts('2026-04-03 08:00:00');
        $tsto   = $this->ts('2026-04-06 18:00:00');

        $result = academic_time::elapsed_with_audit(0, 0, $tsfrom, $tsto);

        $this->assertSame(0.0, $result['hours']);

        $reasoncounts = array_count_values(array_column($result['pauses'], 'reason'));
        $this->assertSame(2, $reasoncounts['holiday'] ?? 0);
        $this->assertSame(2, $reasoncounts['weekend'] ?? 0);
    }

    /**
     * Manual pause at course scope subtracts from effective hours and emits
     * one coursepaused audit record per active interval it overlaps.
     */
    public function test_manual_pause_course_subtracted(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        $course = $this->getDataGenerator()->create_course();
        $this->add_cpause(
            'course',
            (int) $course->id,
            \context_course::instance($course->id)->id,
            $this->ts('2026-05-19 09:00:00'),
            $this->ts('2026-05-20 09:00:00')
        );
        academic_time::reset_memos();

        // Tue 08:00 -> Wed 12:00.
        $tsfrom = $this->ts('2026-05-19 08:00:00');
        $tsto   = $this->ts('2026-05-20 12:00:00');

        $result = academic_time::elapsed_with_audit((int) $course->id, 0, $tsfrom, $tsto);

        // Without pause active = 10h (Tue 08-18) + 4h (Wed 08-12) = 14h.
        // Pause overlaps active on Tue 09-18 (9h) + Wed 08-09 (1h) = 10h subtracted.
        $this->assertSame(4.0, $result['hours']);

        $coursepaused = array_values(array_filter(
            $result['pauses'],
            static fn($p) => $p['reason'] === 'coursepaused'
        ));
        $this->assertCount(2, $coursepaused);
    }

    /**
     * Group-scoped pause only affects the matching groupid.
     */
    public function test_manual_pause_group_isolated(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        $course = $this->getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->add_cpause(
            'group',
            (int) $group1->id,
            \context_course::instance($course->id)->id,
            $this->ts('2026-05-19 09:00:00'),
            $this->ts('2026-05-19 17:00:00')
        );
        academic_time::reset_memos();

        $tsfrom = $this->ts('2026-05-19 08:00:00');
        $tsto   = $this->ts('2026-05-19 18:00:00');

        $r1 = academic_time::elapsed_effective_hours((int) $course->id, (int) $group1->id, $tsfrom, $tsto);
        // Active (Tue 08-18) = 10h minus 8h pause overlap = 2h.
        $this->assertSame(2.0, $r1);

        academic_time::reset_memos();
        $r2 = academic_time::elapsed_effective_hours((int) $course->id, (int) $group2->id, $tsfrom, $tsto);
        $this->assertSame(10.0, $r2);
    }

    /**
     * Split-shift Monday (08:00-12:00 and 13:00-18:00). Submit Mon 10:30,
     * grade Mon 14:30 -> 3.0 effective hours plus one outofhours pause for
     * the lunch break.
     */
    public function test_split_shift(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar_no_chours();
        global $DB;
        $now = time();
        $DB->insert_record('block_feedback_tracker_chours', (object) [
            'dayofweek' => 0, 'starttime' => 480, 'endtime' => 720, 'enabled' => 1,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('block_feedback_tracker_chours', (object) [
            'dayofweek' => 0, 'starttime' => 780, 'endtime' => 1080, 'enabled' => 1,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        academic_time::reset_memos();

        $tsfrom = $this->ts('2026-05-18 10:30:00');
        $tsto   = $this->ts('2026-05-18 14:30:00');

        $result = academic_time::elapsed_with_audit(0, 0, $tsfrom, $tsto);

        $this->assertSame(3.0, $result['hours']);
        $outofhours = array_values(array_filter(
            $result['pauses'],
            static fn($p) => $p['reason'] === 'outofhours'
        ));
        $this->assertCount(1, $outofhours);
        $this->assertSame($this->ts('2026-05-18 12:00:00'), $outofhours[0]['timestart']);
        $this->assertSame($this->ts('2026-05-18 13:00:00'), $outofhours[0]['timeend']);
    }

    /**
     * With the platform timezone set to Pacific/Auckland, day boundaries
     * align to NZ local time, not the server clock.
     */
    public function test_timezone_auckland(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        set_config('timezone', 'Pacific/Auckland', 'block_feedback_tracker');
        academic_time::reset_memos();

        $aucktz = new \DateTimeZone('Pacific/Auckland');
        $tsfrom = (new \DateTime('2026-05-18 17:00:00', $aucktz))->getTimestamp();
        $tsto   = (new \DateTime('2026-05-19 09:00:00', $aucktz))->getTimestamp();

        $result = academic_time::elapsed_effective_hours(0, 0, $tsfrom, $tsto);

        // Mon 17:00-18:00 (1h) + Tue 08:00-09:00 (1h), all NZ local.
        $this->assertSame(2.0, $result);
    }

    /**
     * A calver bump invalidates the per-day cache so a freshly added holiday
     * affects subsequent queries.
     */
    public function test_calver_bump_invalidates(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();

        $tsfrom = $this->ts('2026-05-18 09:00:00');
        $tsto   = $this->ts('2026-05-18 17:00:00');

        $r1 = academic_time::elapsed_effective_hours(0, 0, $tsfrom, $tsto);
        $this->assertSame(8.0, $r1);

        $this->add_cday('20260518', 'holiday', 'Custom Holiday');
        calendar::bump_version();
        academic_time::reset_memos();

        $r2 = academic_time::elapsed_effective_hours(0, 0, $tsfrom, $tsto);
        $this->assertSame(0.0, $r2);
    }

    /**
     * Identical timestamps produce zero hours and no pauses.
     */
    public function test_zero_range(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();

        $ts = $this->ts('2026-05-18 10:00:00');
        $result = academic_time::elapsed_with_audit(0, 0, $ts, $ts);

        $this->assertSame(0.0, $result['hours']);
        $this->assertSame([], $result['pauses']);
    }

    /**
     * Business hours are wall-clock hours on the two DST-transition Sundays of
     * 2026 in Europe/London: 08:00-18:00 local is 07:00-17:00 UTC on the
     * spring-forward day (clocks jump from 01:00 GMT to 02:00 BST) and
     * 08:00-18:00 UTC on the fall-back day (clocks return from 02:00 BST to
     * 01:00 GMT). Counting elapsed minutes from local midnight instead would
     * shift the window an hour late in spring and an hour early in autumn
     * while keeping its ten-hour length, so the window edges are asserted,
     * not only the total.
     *
     * @return void
     */
    public function test_business_hours_are_wall_clock_on_dst_days(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_default_calendar_no_chours();
        set_config('timezone', 'Europe/London', 'block_feedback_tracker');
        set_config('excludeweekends', '0', 'block_feedback_tracker');
        $now = time();
        // Both transition days are Sundays (dayofweek 6).
        $DB->insert_record('block_feedback_tracker_chours', (object) [
            'dayofweek' => 6, 'starttime' => 8 * 60, 'endtime' => 18 * 60, 'enabled' => 1,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        academic_time::reset_memos();

        $london = new \DateTimeZone('Europe/London');
        $cases = [
            'spring forward' => ['2026-03-29', '2026-03-29 07:00:00', '2026-03-29 17:00:00'],
            'fall back' => ['2026-10-25', '2026-10-25 08:00:00', '2026-10-25 18:00:00'],
        ];
        foreach ($cases as $label => [$date, $openutc, $closeutc]) {
            $midnight = (new \DateTimeImmutable($date . ' 00:00:00', $london))->getTimestamp();
            $nextmidnight = (new \DateTimeImmutable($date . ' 00:00:00', $london))->modify('+1 day')->getTimestamp();

            $result = academic_time::elapsed_with_audit(0, 0, $midnight, $nextmidnight);
            $this->assertSame(10.0, $result['hours'], $label);
            $outofhours = array_values(array_filter(
                $result['pauses'],
                static fn($p) => $p['reason'] === 'outofhours'
            ));
            $this->assertCount(2, $outofhours, $label);
            $this->assertSame($this->ts($openutc), $outofhours[0]['timeend'], $label . ': opens at 08:00 local');
            $this->assertSame($this->ts($closeutc), $outofhours[1]['timestart'], $label . ': closes at 18:00 local');

            // The first local hour of the window, through the production entry point.
            $open = (new \DateTimeImmutable($date . ' 08:00:00', $london))->getTimestamp();
            $this->assertSame($this->ts($openutc), $open, $label . ': fixture check');
            $this->assertSame(1.0, academic_time::elapsed_effective_hours(0, 0, $open, $open + 3600), $label);
        }
    }

    // Helpers.
    /**
     * Seed a clean platform calendar: UTC tz, weekends excluded, no cday rows,
     * Mon-Fri 08:00-18:00 business hours, clipped grading-during-pause.
     */
    private function seed_default_calendar(): void {
        $this->seed_default_calendar_no_chours();
        global $DB;
        $now = time();
        for ($dayofweek = 0; $dayofweek <= 4; $dayofweek++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek' => $dayofweek,
                'starttime' => 480,
                'endtime' => 1080,
                'enabled' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        academic_time::reset_memos();
    }

    /**
     * Seed configuration without inserting any business-hours rows. Drops any
     * pre-existing chours rows first — the plugin install hook seeds Mon-Fri
     * 08:00-18:00 defaults, which would contaminate split-shift tests if
     * they re-inserted rows on top.
     */
    private function seed_default_calendar_no_chours(): void {
        global $DB;
        $DB->delete_records('block_feedback_tracker_chours');
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', (string) calendar::WEEKEND_MASK_DEFAULT, 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('grading_during_pause_mode', 'clipped', 'block_feedback_tracker');
        academic_time::reset_memos();
    }

    /**
     * Build a UTC unix timestamp for a "YYYY-MM-DD HH:MM:SS" string.
     *
     * @param string $datetime The datetime string.
     * @return int The unix timestamp.
     */
    private function ts(string $datetime): int {
        return (new \DateTime($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * Insert a {block_feedback_tracker_cday} row.
     *
     * @param string $daydate The day date as YYYYMMDD.
     * @param string $daytype The day type slug.
     * @param string|null $note The note.
     */
    private function add_cday(string $daydate, string $daytype, ?string $note = null): void {
        global $DB;
        $now = time();
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => (int) $daydate,
            'daytype' => $daytype,
            'note' => $note,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Insert a {block_feedback_tracker_cpause} row.
     *
     * @param string $scopelevel Scope level string.
     * @param int $scopeid Scope ID.
     * @param int $contextid Context ID.
     * @param int $tsstart Start timestamp.
     * @param int|null $tsend End timestamp; null for open-ended.
     */
    private function add_cpause(
        string $scopelevel,
        int $scopeid,
        int $contextid,
        int $tsstart,
        ?int $tsend
    ): void {
        global $DB;
        $now = time();
        $DB->insert_record('block_feedback_tracker_cpause', (object) [
            'scopelevel' => $scopelevel,
            'scopeid' => $scopeid,
            'contextid' => $contextid,
            'reason' => 'other',
            'timestart' => $tsstart,
            'timeend' => $tsend,
            'note' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
