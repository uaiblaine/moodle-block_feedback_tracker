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
 * Tests for paused_aggregator.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Verifies the weekend / holiday / recess bucket counts of the paused-periods
 * summary on the teacher dashboard and the academic-days strip.
 *
 * @covers \block_feedback_tracker\local\calendar\paused_aggregator
 */
final class paused_aggregator_test extends \advanced_testcase {
    public function test_window_with_no_overrides_counts_weekends_only(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        // 2026-05-18 (Mon) → 2026-06-01 (Mon). 14 calendar days, 2 weekends = 4 days.
        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')))->getTimestamp();
        $result = paused_aggregator::for_window(0, $start, $end);

        $this->assertSame(4, $result['weekend']);
        $this->assertSame(0, $result['holiday']);
        $this->assertSame(0, $result['recess']);
        $this->assertSame(4, $result['total_days']);
    }

    /**
     * A holiday on a weekend day counts once, as a holiday: the holiday takes
     * precedence over the weekend.
     *
     * @return void
     */
    public function test_holiday_overrides_weekend(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        global $DB;
        // Sunday 2026-05-24, a weekend day under the default mask.
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => 20260524, 'daytype' => 'holiday',
            'note' => null, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')))->getTimestamp();
        $result = paused_aggregator::for_window(0, $start, $end);

        $this->assertSame(3, $result['weekend']);
        $this->assertSame(1, $result['holiday']);
        $this->assertSame(0, $result['recess']);
        $this->assertSame(4, $result['total_days']);
        $perday = paused_aggregator::per_day_for_window(0, $start, $end);
        $this->assertSame(['paused' => true, 'reason' => 'holiday'], $perday[20260524]);
    }

    public function test_recess_and_closed_count_as_recess(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        global $DB;
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => 20260520, 'daytype' => 'recess',
            'note' => null, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => 20260521, 'daytype' => 'closed',
            'note' => null, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')))->getTimestamp();
        $result = paused_aggregator::for_window(0, $start, $end);

        $this->assertSame(2, $result['recess']);
        $this->assertSame(0, $result['holiday']);
    }

    public function test_full_day_optional_buckets_as_recess(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        global $DB;
        // Mon 2026-05-18 marked as full-day optional (no time window).
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => 20260518, 'daytype' => 'optional',
            'starttime' => null, 'endtime' => null,
            'note' => null, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')))->getTimestamp();
        $result = paused_aggregator::for_window(0, $start, $end);

        $this->assertSame(1, $result['recess']);
        $this->assertSame([], $result['events']);
    }

    /**
     * With recesses counted as working time, a recess day is not paused, but a
     * closed day and a full-day optional day still are: the time engine treats
     * both as inactive whatever the settings.
     *
     * @return void
     */
    public function test_closed_and_full_day_optional_pause_when_recesses_count(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();
        set_config('excluderecesses', '0', 'block_feedback_tracker');

        // Tuesday optional, Wednesday recess, Thursday closed.
        foreach ([20260519 => 'optional', 20260520 => 'recess', 20260521 => 'closed'] as $daydate => $daytype) {
            $DB->insert_record('block_feedback_tracker_cday', (object) [
                'daydate' => $daydate, 'daytype' => $daytype,
                'starttime' => null, 'endtime' => null,
                'note' => null, 'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')))->getTimestamp();
        $perday = paused_aggregator::per_day_for_window(0, $start, $end);

        $this->assertSame(['paused' => true, 'reason' => 'recess'], $perday[20260519]);
        $this->assertSame(['paused' => false, 'reason' => ''], $perday[20260520]);
        $this->assertSame(['paused' => true, 'reason' => 'recess'], $perday[20260521]);
        $this->assertSame(2, paused_aggregator::for_window(0, $start, $end)['recess']);

        // The same rows agree with the time engine, which is what the counts describe.
        $this->assertFalse(calendar::is_active_day(calendar::DAYTYPE_CLOSED, false));
        $this->assertFalse(calendar::is_active_day(calendar::DAYTYPE_OPTIONAL, false));
        $this->assertTrue(calendar::is_active_day(calendar::DAYTYPE_RECESS, false));
    }

    public function test_sub_day_optional_event_appears_in_events_sidecar(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        global $DB;
        // Mon 2026-05-18 optional with 16:00-18:00 window — Brasil vs França.
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => 20260518, 'daytype' => 'optional',
            'starttime' => 16 * 60, 'endtime' => 18 * 60,
            'note' => 'Brasil vs Franca',
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')))->getTimestamp();
        $result = paused_aggregator::for_window(0, $start, $end);

        // A sub-day event must not inflate the recess bucket.
        $this->assertSame(0, $result['recess']);
        $this->assertCount(1, $result['events']);
        $event = $result['events'][0];
        $this->assertSame(20260518, $event['date']);
        $this->assertSame(16 * 60, $event['starttime']);
        $this->assertSame(18 * 60, $event['endtime']);
        $this->assertSame('Brasil vs Franca', $event['label']);
    }

    public function test_schoolday_override_cancels_weekend(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        global $DB;
        // Opt Sat 2026-05-23 into the working week.
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => 20260523, 'daytype' => 'schoolday',
            'note' => null, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')))->getTimestamp();
        $result = paused_aggregator::for_window(0, $start, $end);

        // 4 weekend days minus the opted-in Saturday = 3.
        $this->assertSame(3, $result['weekend']);
    }

    public function test_manual_pause_window_counts_as_recess(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        global $DB;
        // Course-scoped manual pause covering Tue 2026-05-19 → Wed 2026-05-20 (UTC).
        $DB->insert_record('block_feedback_tracker_cpause', (object) [
            'scopelevel' => 'course',
            'scopeid'    => 42,
            'contextid'  => 1,
            'reason'     => 'closure',
            'timestart'  => (new \DateTimeImmutable('2026-05-19', new \DateTimeZone('UTC')))->getTimestamp(),
            'timeend'    => (new \DateTimeImmutable('2026-05-21', new \DateTimeZone('UTC')))->getTimestamp(),
            'note'       => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')))->getTimestamp();
        $result = paused_aggregator::for_window(42, $start, $end);

        $this->assertSame(2, $result['recess']);
        $this->assertSame(4, $result['weekend']);
        $this->assertSame(6, $result['total_days']);
    }

    public function test_pause_window_only_visible_to_its_course(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        global $DB;
        $DB->insert_record('block_feedback_tracker_cpause', (object) [
            'scopelevel' => 'course',
            'scopeid'    => 42,
            'contextid'  => 1,
            'reason'     => 'closure',
            'timestart'  => (new \DateTimeImmutable('2026-05-19', new \DateTimeZone('UTC')))->getTimestamp(),
            'timeend'    => (new \DateTimeImmutable('2026-05-21', new \DateTimeZone('UTC')))->getTimestamp(),
            'note'       => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')))->getTimestamp();
        $foreignresult = paused_aggregator::for_window(99, $start, $end);

        $this->assertSame(0, $foreignresult['recess']);
    }

    public function test_empty_window_returns_zeros(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $now = time();
        $result = paused_aggregator::for_window(0, $now, $now);
        $this->assertSame(0, $result['total_days']);
    }

    public function test_weekend_excluded_setting_disables_weekend_bucket(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        set_config('excludeweekends', '0', 'block_feedback_tracker');

        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')))->getTimestamp();
        $result = paused_aggregator::for_window(0, $start, $end);

        $this->assertSame(0, $result['weekend']);
    }

    public function test_per_day_map_matches_for_window_counts(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        global $DB;
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => 20260525, 'daytype' => 'holiday',
            'note' => null, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')))->getTimestamp();

        $perday = paused_aggregator::per_day_for_window(0, $start, $end);
        $counts = paused_aggregator::for_window(0, $start, $end);

        // One entry per calendar day in the [start, end) window (14 days).
        $this->assertCount(14, $perday);
        $this->assertArrayHasKey(20260525, $perday);
        $this->assertTrue($perday[20260525]['paused']);
        $this->assertSame('holiday', $perday[20260525]['reason']);

        // Counts derived from the per-day map equal for_window()'s own counts;
        // both come from classify_window().
        $weekend = 0;
        $holiday = 0;
        $recess = 0;
        foreach ($perday as $info) {
            if (!$info['paused']) {
                continue;
            }
            $weekend += $info['reason'] === 'weekend' ? 1 : 0;
            $holiday += $info['reason'] === 'holiday' ? 1 : 0;
            $recess += $info['reason'] === 'recess' ? 1 : 0;
        }
        $this->assertSame($counts['weekend'], $weekend);
        $this->assertSame($counts['holiday'], $holiday);
        $this->assertSame($counts['recess'], $recess);
    }

    /**
     * The weekend mask is numbered from Monday, so under the default mask
     * (Saturday + Sunday) the Sunday is a weekend day and the Friday is not.
     * Whole-week windows cannot show this: a Friday + Saturday weekend has the
     * same count as a Saturday + Sunday one.
     *
     * @return void
     */
    public function test_per_day_weekend_is_saturday_and_sunday(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        // 2026-05-18 is a Monday; 22 is Friday, 23 Saturday, 24 Sunday.
        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')))->getTimestamp();
        $perday = paused_aggregator::per_day_for_window(0, $start, $end);

        $this->assertSame(['paused' => false, 'reason' => ''], $perday[20260522]);
        $this->assertSame(['paused' => true, 'reason' => 'weekend'], $perday[20260523]);
        $this->assertSame(['paused' => true, 'reason' => 'weekend'], $perday[20260524]);
        $this->assertSame(['paused' => false, 'reason' => ''], $perday[20260525]);
    }

    /**
     * A window holding a Thursday, a Friday and a Saturday, but not the
     * following Sunday, has exactly one weekend day.
     *
     * @return void
     */
    public function test_window_ending_before_sunday_counts_only_saturday(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        // Thursday 2026-05-21 up to (not including) Sunday 2026-05-24.
        $start = (new \DateTimeImmutable('2026-05-21', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-05-24', new \DateTimeZone('UTC')))->getTimestamp();
        $result = paused_aggregator::for_window(0, $start, $end);

        $this->assertSame(1, $result['weekend']);
        $this->assertSame(1, $result['total_days']);

        // Control: the same window stretched over Sunday gains the second weekend day.
        $withsunday = paused_aggregator::for_window(0, $start, $end + 86400);
        $this->assertSame(2, $withsunday['weekend']);
    }

    /**
     * Event labels are plain text: the tags of a note are stripped, but an
     * ampersand is not escaped, because every consumer escapes it itself.
     *
     * @return void
     */
    public function test_event_label_is_plain_text(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_calendar();

        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => 20260518, 'daytype' => 'optional',
            'starttime' => 16 * 60, 'endtime' => 18 * 60,
            'note' => 'A & B',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => 20260519, 'daytype' => 'optional',
            'starttime' => 9 * 60, 'endtime' => 10 * 60,
            'note' => 'C <span>D</span>',
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $start = (new \DateTimeImmutable('2026-05-18', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-05-20', new \DateTimeZone('UTC')))->getTimestamp();
        $events = paused_aggregator::for_window(0, $start, $end)['events'];

        $this->assertCount(2, $events);
        $this->assertSame('A & B', $events[0]['label']);
        // Control: the note still goes through format_string(), which strips the tags.
        $this->assertSame('C D', $events[1]['label']);
    }

    /**
     * Seed enough calendar config for the aggregator to read.
     *
     * @return void
     */
    private function seed_calendar(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
    }
}
