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
 * Differential tests for the academic-time fast path.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Holds elapsed_effective_hours() (fast path) and elapsed_with_audit()
 * (day-by-day walker) to the same value on the same inputs.
 *
 * The walker is the oracle: it is exercised by academic_time_test with
 * hand-computed expectations, so asserting bit-identical agreement across
 * calendars, pauses, timezones and interval shapes proves the fast path
 * exact without re-deriving expected values. A few absolute pins guard the
 * one blind spot of a differential test — both paths being wrong the same
 * way on plain ranges.
 *
 * @covers \block_feedback_tracker\local\calendar\academic_time
 */
final class academic_time_fastpath_test extends \advanced_testcase {
    /**
     * Eight whole plain weeks pin an absolute value through the fast path.
     */
    public function test_plain_long_range_pinned_value(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();

        // Mon 2026-01-05 00:00 -> Mon 2026-03-02 00:00: eight whole weeks,
        // 40 working days at 10 business hours each.
        $tsfrom = $this->ts('2026-01-05 00:00:00');
        $tsto = $this->ts('2026-03-02 00:00:00');

        $this->assertSame(400.0, academic_time::elapsed_effective_hours(0, 0, $tsfrom, $tsto));
        $this->assert_fast_matches_walker(0, 0, $tsfrom, $tsto, 'plain 8-week range');
    }

    /**
     * Interval shapes: partial edges, midnight edges, degenerate ranges.
     */
    public function test_plain_ranges_with_partial_edges(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();

        $cases = [
            'mid-day to mid-day, months apart' => ['2026-01-07 13:37:19', '2026-06-24 09:15:47'],
            'midnight start, mid-day end' => ['2026-02-02 00:00:00', '2026-02-19 11:30:00'],
            'mid-day start, midnight end' => ['2026-02-03 16:45:10', '2026-02-23 00:00:00'],
            'single whole day' => ['2026-05-18 00:00:00', '2026-05-19 00:00:00'],
            'single partial day' => ['2026-05-18 09:12:00', '2026-05-18 16:03:00'],
            'two partial days, no interior' => ['2026-05-18 12:00:00', '2026-05-19 12:00:00'],
            'weekend-only range' => ['2026-05-16 08:00:00', '2026-05-17 20:00:00'],
            'one full week from Sunday' => ['2026-05-17 00:00:00', '2026-05-24 00:00:00'],
        ];
        foreach ($cases as $label => $case) {
            $this->assert_fast_matches_walker(0, 0, $this->ts($case[0]), $this->ts($case[1]), $label);
        }

        $ts = $this->ts('2026-05-18 10:00:00');
        $this->assertSame(0.0, academic_time::elapsed_effective_hours(0, 0, $ts, $ts));
        $this->assertSame(0.0, academic_time::elapsed_effective_hours(0, 0, $ts, $ts - 3600));
    }

    /**
     * Holiday, recess run, closure and a schoolday-on-Saturday override.
     */
    public function test_calendar_exception_days(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();

        $this->add_cday('20260311', 'holiday', 'Mid-range holiday');
        $this->add_cday('20260314', 'schoolday', 'Working Saturday');
        $this->add_cday('20260316', 'recess');
        $this->add_cday('20260317', 'recess');
        $this->add_cday('20260318', 'recess');
        $this->add_cday('20260323', 'closed');
        academic_time::reset_memos();

        $cases = [
            'range covering all exceptions' => ['2026-03-02 09:30:00', '2026-04-01 15:00:00'],
            'exception on first interior day' => ['2026-03-10 14:00:00', '2026-03-13 10:00:00'],
            'exception on last interior day' => ['2026-03-20 14:00:00', '2026-03-24 00:00:00'],
            'exception on the partial head day' => ['2026-03-11 09:00:00', '2026-03-20 10:00:00'],
            'working Saturday inside a plain stretch' => ['2026-03-09 00:00:00', '2026-03-16 00:00:00'],
        ];
        foreach ($cases as $label => $case) {
            $this->assert_fast_matches_walker(0, 0, $this->ts($case[0]), $this->ts($case[1]), $label);
        }
    }

    /**
     * Full-day optional day and a sub-day optional event window.
     */
    public function test_optional_windows(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();

        $this->add_cday('20260421', 'optional', 'Full-day optional');
        $this->add_cday_window('20260428', 600, 720, 'Sub-day event 10:00-12:00');
        academic_time::reset_memos();

        $this->assert_fast_matches_walker(
            0,
            0,
            $this->ts('2026-04-13 08:30:00'),
            $this->ts('2026-05-06 17:45:00'),
            'optional full-day and sub-day windows'
        );
    }

    /**
     * Course, group and open-ended site pauses in clipped mode.
     */
    public function test_manual_pauses_clipped(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        $course = $this->getDataGenerator()->create_course();
        $courseid = (int) $course->id;
        $contextid = (int) \context_course::instance($course->id)->id;
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupid = (int) $group->id;

        $this->add_cpause('course', $courseid, $contextid, $this->ts('2026-06-09 10:00:00'), $this->ts('2026-06-11 15:00:00'));
        $this->add_cpause('group', $groupid, $contextid, $this->ts('2026-06-16 09:00:00'), $this->ts('2026-06-16 17:00:00'));
        $this->add_cpause('site', 0, (int) \context_system::instance()->id, $this->ts('2026-06-25 12:00:00'), null);
        academic_time::reset_memos();

        $tsfrom = $this->ts('2026-06-01 09:15:00');
        $tsto = $this->ts('2026-06-30 14:20:00');

        $this->assert_fast_matches_walker($courseid, $groupid, $tsfrom, $tsto, 'course+group+site pauses');
        $this->assert_fast_matches_walker($courseid, 0, $tsfrom, $tsto, 'group pause must not leak to groupid 0');

        // A pause covering the whole interval degrades every day to the walker.
        $this->add_cpause('course', $courseid, $contextid, $this->ts('2026-05-01 00:00:00'), $this->ts('2026-08-01 00:00:00'));
        calendar::bump_version();
        academic_time::reset_memos();
        $this->assert_fast_matches_walker($courseid, 0, $tsfrom, $tsto, 'pause covering the whole interval');
    }

    /**
     * Live pause mode: pauses audit but do not subtract.
     */
    public function test_manual_pauses_live(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        set_config('grading_during_pause_mode', 'live', 'block_feedback_tracker');
        $course = $this->getDataGenerator()->create_course();
        $courseid = (int) $course->id;
        $contextid = (int) \context_course::instance($course->id)->id;

        $this->add_cpause('course', $courseid, $contextid, $this->ts('2026-06-09 10:00:00'), $this->ts('2026-06-12 15:00:00'));
        academic_time::reset_memos();

        $this->assert_fast_matches_walker(
            $courseid,
            0,
            $this->ts('2026-06-01 09:15:00'),
            $this->ts('2026-06-30 14:20:00'),
            'course pause in live mode'
        );
    }

    /**
     * Business-hours window disabled: whole active days count 24 hours.
     */
    public function test_no_business_hours(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        set_config('enablebusinesshours', '0', 'block_feedback_tracker');
        calendar::bump_version();
        academic_time::reset_memos();

        // Mon 2026-01-05 00:00 -> Mon 2026-01-19 00:00: 10 working days x 24h.
        $tsfrom = $this->ts('2026-01-05 00:00:00');
        $tsto = $this->ts('2026-01-19 00:00:00');
        $this->assertSame(240.0, academic_time::elapsed_effective_hours(0, 0, $tsfrom, $tsto));
        $this->assert_fast_matches_walker(0, 0, $tsfrom, $tsto, 'no business hours, whole weeks');

        $this->assert_fast_matches_walker(
            0,
            0,
            $this->ts('2026-01-06 15:30:00'),
            $this->ts('2026-02-20 08:45:00'),
            'no business hours, partial edges'
        );
    }

    /**
     * Weekend variants: counted weekends and a custom weekend mask.
     */
    public function test_weekend_variants(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();

        set_config('excludeweekends', '0', 'block_feedback_tracker');
        calendar::bump_version();
        academic_time::reset_memos();
        $this->assert_fast_matches_walker(
            0,
            0,
            $this->ts('2026-02-04 10:20:00'),
            $this->ts('2026-03-18 16:40:00'),
            'weekends counted'
        );

        // Sunday-only weekend mask (bit 6 = 64).
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '64', 'block_feedback_tracker');
        calendar::bump_version();
        academic_time::reset_memos();
        $this->assert_fast_matches_walker(
            0,
            0,
            $this->ts('2026-02-04 10:20:00'),
            $this->ts('2026-03-18 16:40:00'),
            'Sunday-only weekend mask'
        );
    }

    /**
     * Split-shift Mondays with no hours configured on other weekdays.
     */
    public function test_split_shift_long_range(): void {
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

        // Mon 2026-01-05 00:00 -> Mon 2026-01-26 00:00: 3 Mondays x (4h + 5h).
        $tsfrom = $this->ts('2026-01-05 00:00:00');
        $tsto = $this->ts('2026-01-26 00:00:00');
        $this->assertSame(27.0, academic_time::elapsed_effective_hours(0, 0, $tsfrom, $tsto));
        $this->assert_fast_matches_walker(0, 0, $tsfrom, $tsto, 'split-shift Mondays, whole weeks');

        $this->assert_fast_matches_walker(
            0,
            0,
            $this->ts('2026-01-05 10:00:00'),
            $this->ts('2026-03-09 14:00:00'),
            'split-shift Mondays, partial edges'
        );
    }

    /**
     * Auckland DST: 02:00/03:00 transitions keep local midnights intact.
     */
    public function test_dst_transitions_auckland(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        set_config('timezone', 'Pacific/Auckland', 'block_feedback_tracker');
        calendar::bump_version();
        academic_time::reset_memos();

        $tz = new \DateTimeZone('Pacific/Auckland');
        $cases = [
            'spanning the April fall-back' => ['2026-03-20 14:00:00', '2026-04-20 10:30:00'],
            'spanning the September spring-forward' => ['2026-09-14 09:10:00', '2026-10-09 16:40:00'],
            'spanning both transitions' => ['2026-03-30 11:00:00', '2026-10-05 13:00:00'],
        ];
        foreach ($cases as $label => $case) {
            $this->assert_fast_matches_walker(
                0,
                0,
                (new \DateTimeImmutable($case[0], $tz))->getTimestamp(),
                (new \DateTimeImmutable($case[1], $tz))->getTimestamp(),
                $label
            );
        }
    }

    /**
     * DST transition days whose active windows overlap the shifted hours.
     *
     * The 2026 Auckland transitions fall on Sundays at 02:00/03:00 local,
     * so with weekends excluded and office-hours windows the transition
     * days contribute the same seconds as plain days and a broken
     * transition-day routing stays invisible. Count weekends and use a
     * 24-hour window (real length 23h/25h on transition days), then an
     * early-morning shift crossing the transition hour, so the days' real
     * lengths become observable.
     */
    public function test_dst_transition_days_with_sensitive_windows(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        set_config('timezone', 'Pacific/Auckland', 'block_feedback_tracker');
        set_config('excludeweekends', '0', 'block_feedback_tracker');
        set_config('enablebusinesshours', '0', 'block_feedback_tracker');
        calendar::bump_version();
        academic_time::reset_memos();

        $tz = new \DateTimeZone('Pacific/Auckland');
        $cases = [
            'across the April fall-back' => ['2026-03-25 00:00:00', '2026-04-15 00:00:00'],
            'across the September spring-forward' => ['2026-09-16 10:00:00', '2026-10-07 14:00:00'],
        ];
        foreach ($cases as $label => $case) {
            $this->assert_fast_matches_walker(
                0,
                0,
                (new \DateTimeImmutable($case[0], $tz))->getTimestamp(),
                (new \DateTimeImmutable($case[1], $tz))->getTimestamp(),
                '24h windows ' . $label
            );
        }

        // Early-morning shift 00:00-06:00 crossing the transition hour.
        global $DB;
        $DB->delete_records('block_feedback_tracker_chours');
        $now = time();
        for ($dayofweek = 0; $dayofweek <= 6; $dayofweek++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek' => $dayofweek, 'starttime' => 0, 'endtime' => 360, 'enabled' => 1,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        calendar::bump_version();
        academic_time::reset_memos();

        foreach ($cases as $label => $case) {
            $this->assert_fast_matches_walker(
                0,
                0,
                (new \DateTimeImmutable($case[0], $tz))->getTimestamp(),
                (new \DateTimeImmutable($case[1], $tz))->getTimestamp(),
                'early-morning shift ' . $label
            );
        }
    }

    /**
     * Santiago DST transitions sit at local midnight: the ambiguous
     * fall-back keeps the walker's day chain intact, while the
     * spring-forward gap swallows a midnight and must force the fallback.
     */
    public function test_dst_midnight_transitions_santiago(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        set_config('timezone', 'America/Santiago', 'block_feedback_tracker');
        calendar::bump_version();
        academic_time::reset_memos();

        $tz = new \DateTimeZone('America/Santiago');
        $cases = [
            'spanning the ambiguous midnight fall-back' => ['2026-03-23 10:00:00', '2026-04-17 15:30:00'],
            'spanning the midnight DST gap' => ['2026-08-24 10:00:00', '2026-09-18 15:30:00'],
        ];
        foreach ($cases as $label => $case) {
            $this->assert_fast_matches_walker(
                0,
                0,
                (new \DateTimeImmutable($case[0], $tz))->getTimestamp(),
                (new \DateTimeImmutable($case[1], $tz))->getTimestamp(),
                $label
            );
        }
    }

    /**
     * London DST transitions at 01:00 UTC.
     */
    public function test_dst_transitions_london(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        set_config('timezone', 'Europe/London', 'block_feedback_tracker');
        calendar::bump_version();
        academic_time::reset_memos();

        $tz = new \DateTimeZone('Europe/London');
        $this->assert_fast_matches_walker(
            0,
            0,
            (new \DateTimeImmutable('2026-03-16 09:00:00', $tz))->getTimestamp(),
            (new \DateTimeImmutable('2026-11-02 17:00:00', $tz))->getTimestamp(),
            'spanning both London transitions'
        );
    }

    /**
     * A timezone that skipped a whole calendar date: Pacific/Apia crossed
     * the date line at the end of 2011-12-29, so 2011-12-30 never existed
     * there. The walker's +1 day chain hops the phantom date while calendar
     * arithmetic counts it, so the fast path must stand down. The absolute
     * pin is the walker's own value — seven real weekdays at 10 hours —
     * so agreement cannot come from both paths drifting together.
     */
    public function test_dateline_skip_apia(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        set_config('timezone', 'Pacific/Apia', 'block_feedback_tracker');
        calendar::bump_version();
        academic_time::reset_memos();

        $tz = new \DateTimeZone('Pacific/Apia');
        $tsfrom = (new \DateTimeImmutable('2011-12-25 00:00:00', $tz))->getTimestamp();
        $tsto = (new \DateTimeImmutable('2012-01-05 00:00:00', $tz))->getTimestamp();

        $this->assert_fast_matches_walker(0, 0, $tsfrom, $tsto, 'Apia date-line skip');
        $this->assertSame(70.0, academic_time::elapsed_effective_hours(0, 0, $tsfrom, $tsto));
    }

    /**
     * The repeated-date direction of a date-line move: Kwajalein crossed
     * the line westward-to-eastward on 1969-09-30 and replayed most of a
     * calendar day, with the jump landing at the replayed date's own
     * midnight. The local date sequence stays monotone, so the fast path
     * legitimately stays engaged — the walker folds the doubled date into
     * one long day cell, which the transition slow range hands to it
     * verbatim — and the result must still match exactly.
     */
    public function test_dateline_repeat_kwajalein(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        set_config('timezone', 'Pacific/Kwajalein', 'block_feedback_tracker');
        calendar::bump_version();
        academic_time::reset_memos();

        $tz = new \DateTimeZone('Pacific/Kwajalein');
        $tsfrom = (new \DateTimeImmutable('1969-09-20 09:30:00', $tz))->getTimestamp();
        $tsto = (new \DateTimeImmutable('1969-10-10 16:45:00', $tz))->getTimestamp();

        $this->assert_fast_matches_walker(0, 0, $tsfrom, $tsto, 'Kwajalein date-line repeat');

        // The probe must stay silent here: agreement through engagement,
        // not through a blanket fallback.
        $method = new \ReflectionMethod(academic_time::class, 'fast_active_seconds');
        $this->assertNotNull($method->invoke(null, 0, 0, $tsfrom, $tsto));
    }

    /**
     * Engagement control: a plain multi-week range must actually take the
     * fast path, and a midnight-gap timezone must disengage it. Without
     * this pin the whole differential suite would stay green if
     * fast_active_seconds() bailed out on every input — every comparison
     * degrades to walker-vs-walker.
     */
    public function test_fast_path_engagement(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();

        $method = new \ReflectionMethod(academic_time::class, 'fast_active_seconds');
        $tsfrom = $this->ts('2026-01-05 00:00:00');
        $tsto = $this->ts('2026-03-02 00:00:00');
        $this->assertSame(40 * 10 * 3600, $method->invoke(null, 0, 0, $tsfrom, $tsto));

        // The Santiago midnight DST gap must force the walker fallback.
        set_config('timezone', 'America/Santiago', 'block_feedback_tracker');
        calendar::bump_version();
        academic_time::reset_memos();
        $tz = new \DateTimeZone('America/Santiago');
        $this->assertNull($method->invoke(
            null,
            0,
            0,
            (new \DateTimeImmutable('2026-08-24 10:00:00', $tz))->getTimestamp(),
            (new \DateTimeImmutable('2026-09-18 15:30:00', $tz))->getTimestamp()
        ));
    }

    /**
     * Deterministic random sweep over a busy calendar.
     */
    public function test_randomised_differential_matrix(): void {
        $this->resetAfterTest();
        $this->seed_default_calendar();
        $course = $this->getDataGenerator()->create_course();
        $courseid = (int) $course->id;
        $contextid = (int) \context_course::instance($course->id)->id;
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupid = (int) $group->id;

        $this->add_cday('20260213', 'holiday');
        $this->add_cday('20260427', 'recess');
        $this->add_cday('20260428', 'recess');
        $this->add_cday('20260620', 'schoolday', 'Working Saturday');
        $this->add_cday('20260901', 'closed');
        $this->add_cday_window('20261014', 540, 660, 'Mid-morning event');
        $this->add_cpause('course', $courseid, $contextid, $this->ts('2026-03-10 09:00:00'), $this->ts('2026-03-12 18:00:00'));
        $this->add_cpause('group', $groupid, $contextid, $this->ts('2026-07-06 08:00:00'), $this->ts('2026-07-10 18:00:00'));
        $this->add_cpause('site', 0, (int) \context_system::instance()->id, $this->ts('2026-11-23 12:00:00'), null);
        academic_time::reset_memos();

        mt_srand(20260811);
        $base = $this->ts('2026-01-01 00:00:00');
        $groupids = [0, $groupid, 0];
        for ($i = 0; $i < 40; $i++) {
            $tsfrom = $base + mt_rand(0, 300 * 86400);
            $tsto = $tsfrom + mt_rand(1, 220 * 86400);
            $this->assert_fast_matches_walker(
                $courseid,
                $groupids[$i % 3],
                $tsfrom,
                $tsto,
                'random case ' . $i
            );
        }
    }

    // Helpers.

    /**
     * Assert the fast path and the day-by-day walker agree exactly.
     *
     * @param int $courseid Course id (0 for none).
     * @param int $groupid Group id (0 for none).
     * @param int $tsfrom Inclusive unix timestamp.
     * @param int $tsto Exclusive unix timestamp.
     * @param string $label Failure message prefix.
     */
    private function assert_fast_matches_walker(
        int $courseid,
        int $groupid,
        int $tsfrom,
        int $tsto,
        string $label
    ): void {
        $walker = academic_time::elapsed_with_audit($courseid, $groupid, $tsfrom, $tsto)['hours'];
        $fast = academic_time::elapsed_effective_hours($courseid, $groupid, $tsfrom, $tsto);
        $this->assertSame($walker, $fast, $label . " [{$tsfrom}, {$tsto})");
    }

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
     * 08:00-18:00 defaults, which would contaminate split-shift tests.
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
     * Insert a sub-day optional event {block_feedback_tracker_cday} row.
     *
     * @param string $daydate The day date as YYYYMMDD.
     * @param int $startmin Window start in minutes since midnight.
     * @param int $endmin Window end in minutes since midnight.
     * @param string|null $note The note.
     */
    private function add_cday_window(string $daydate, int $startmin, int $endmin, ?string $note = null): void {
        global $DB;
        $now = time();
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => (int) $daydate,
            'daytype' => 'optional',
            'starttime' => $startmin,
            'endtime' => $endmin,
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
