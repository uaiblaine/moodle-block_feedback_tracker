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
 * Tests for upcoming_pauses (the "Upcoming pause" scheduled-pause lookup).
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Verifies the visibility window (3 days before → day after), sub-day vs
 * full-day handling, span collapsing, manual-pause scoping, the result cap,
 * and the for_display() decoration.
 *
 * @covers \block_feedback_tracker\local\calendar\upcoming_pauses
 */
final class upcoming_pauses_test extends \advanced_testcase {
    /**
     * A sub-day optional event exactly 3 days out is visible (lead boundary).
     *
     * @return void
     */
    public function test_subday_event_visible_three_days_before(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->add_cday(20260629, 'optional', 16 * 60, 17 * 60, 'World Cup');

        $now = $this->ts('2026-06-26 12:00:00');
        $result = upcoming_pauses::for_course_group(0, 0, $now);

        $this->assertCount(1, $result);
        $this->assertSame('optional', $result[0]['type']);
        $this->assertTrue($result[0]['subday']);
        $this->assertSame($this->ts('2026-06-29 16:00:00'), $result[0]['start']);
        $this->assertSame($this->ts('2026-06-29 17:00:00'), $result[0]['end']);
        $this->assertSame('World Cup', $result[0]['label']);
    }

    /**
     * The same event 4 days out is still hidden (beyond the lead window).
     *
     * @return void
     */
    public function test_event_hidden_four_days_before(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->add_cday(20260629, 'optional', 16 * 60, 17 * 60, 'World Cup');

        $now = $this->ts('2026-06-25 12:00:00');
        $this->assertSame([], upcoming_pauses::for_course_group(0, 0, $now));
    }

    /**
     * A sub-day event's minutes are wall-clock time on a DST day: in London on
     * 2026-03-29 the clocks go forward at 01:00, so 08:00-10:00 local is
     * 07:00-09:00 UTC, while eight elapsed hours after midnight would be 09:00
     * local.
     *
     * @return void
     */
    public function test_subday_event_is_wall_clock_on_a_dst_day(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        set_config('timezone', 'Europe/London', 'block_feedback_tracker');
        $this->add_cday(20260329, 'optional', 8 * 60, 10 * 60, 'Open day');

        $result = upcoming_pauses::for_course_group(0, 0, $this->ts('2026-03-27 12:00:00'));

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['subday']);
        $this->assertSame($this->ts('2026-03-29 07:00:00'), $result[0]['start']);
        $this->assertSame($this->ts('2026-03-29 09:00:00'), $result[0]['end']);
    }

    /**
     * A single full-day holiday stays visible on its own day and is removed
     * the day after.
     *
     * @return void
     */
    public function test_full_day_removed_the_day_after(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->add_cday(20260629, 'holiday');

        // Still visible during the holiday itself.
        $onday = upcoming_pauses::for_course_group(0, 0, $this->ts('2026-06-29 12:00:00'));
        $this->assertCount(1, $onday);
        $this->assertSame('holiday', $onday[0]['type']);
        $this->assertFalse($onday[0]['subday']);

        // Gone the next calendar day.
        $nextday = upcoming_pauses::for_course_group(0, 0, $this->ts('2026-06-30 00:05:00'));
        $this->assertSame([], $nextday);
    }

    /**
     * Consecutive same-type/same-note full days collapse into a single span.
     *
     * @return void
     */
    public function test_consecutive_days_collapse_into_one_span(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->add_cday(20260629, 'recess');
        $this->add_cday(20260630, 'recess');
        $this->add_cday(20260701, 'recess');

        $result = upcoming_pauses::for_course_group(0, 0, $this->ts('2026-06-29 09:00:00'));

        $this->assertCount(1, $result);
        $this->assertSame('recess', $result[0]['type']);
        $this->assertSame($this->ts('2026-06-29 00:00:00'), $result[0]['start']);
        // End is the exclusive midnight after the last covered day.
        $this->assertSame($this->ts('2026-07-02 00:00:00'), $result[0]['end']);
    }

    /**
     * Manual pause windows respect course scoping.
     *
     * @return void
     */
    public function test_manual_pause_scoped_to_course(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->add_cpause('course', 42, '2026-06-28 00:00:00', '2026-06-30 00:00:00');

        $now = $this->ts('2026-06-28 09:00:00');

        $owncourse = upcoming_pauses::for_course_group(42, 0, $now);
        $this->assertCount(1, $owncourse);
        $this->assertSame('coursepaused', $owncourse[0]['type']);

        pause_lookup::reset_memo();
        $foreign = upcoming_pauses::for_course_group(99, 0, $now);
        $this->assertSame([], $foreign);
    }

    /**
     * No more than the requested number of entries are returned.
     *
     * @return void
     */
    public function test_result_count_is_capped(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        // Four sub-day optional events on four consecutive days, all within the
        // lead window of "now"; sub-day events do not collapse.
        $this->add_cday(20260626, 'optional', 10 * 60, 11 * 60, 'A');
        $this->add_cday(20260627, 'optional', 10 * 60, 11 * 60, 'B');
        $this->add_cday(20260628, 'optional', 10 * 60, 11 * 60, 'C');
        $this->add_cday(20260629, 'optional', 10 * 60, 11 * 60, 'D');

        $result = upcoming_pauses::for_course_group(0, 0, $this->ts('2026-06-26 06:00:00'));

        $this->assertCount(3, $result);
        // Soonest first.
        $this->assertSame('A', $result[0]['label']);
        $this->assertSame('C', $result[2]['label']);
    }

    /**
     * for_display() decorates each entry with localised when / typelabel.
     *
     * @return void
     */
    public function test_for_display_decorates_when_and_typelabel(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->add_cday(20260629, 'optional', 16 * 60, 17 * 60, 'World Cup');

        $result = upcoming_pauses::for_display(0, 0, $this->ts('2026-06-26 12:00:00'));

        $this->assertCount(1, $result);
        $this->assertSame('Optional', $result[0]['typelabel']);
        $this->assertStringContainsString('16:00', $result[0]['when']);
        $this->assertStringContainsString('17:00', $result[0]['when']);
        // Decorated entries drop the raw end and subday fields but keep the label.
        $this->assertSame('World Cup', $result[0]['label']);
    }

    /**
     * Labels from every source (a sub-day event, a full-day span, a manual
     * pause) are plain text: tags stripped, ampersand not escaped, because
     * every consumer escapes the label itself.
     *
     * @return void
     */
    public function test_labels_are_plain_text(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->add_cday(20260629, 'optional', 16 * 60, 17 * 60, 'A & B');
        $this->add_cday(20260630, 'holiday', null, null, 'C & D');
        $this->add_cday(20260701, 'recess', null, null, 'G <span>H</span>');
        $this->add_cpause('course', 42, '2026-06-28 00:00:00', '2026-06-29 00:00:00', 'E & F');

        $now = $this->ts('2026-06-28 09:00:00');
        $labels = array_column(upcoming_pauses::for_course_group(42, 0, $now, 10), 'label', 'type');

        $this->assertSame('E & F', $labels['coursepaused']);
        $this->assertSame('A & B', $labels['optional']);
        $this->assertSame('C & D', $labels['holiday']);
        // Control: the notes still go through format_string(), which strips the tags.
        $this->assertSame('G H', $labels['recess']);

        // The decorated entries every surface renders carry the same label.
        pause_lookup::reset_memo();
        $display = array_column(upcoming_pauses::for_display(42, 0, $now, 10), 'label', 'type');
        $this->assertSame('A & B', $display['optional']);
    }

    /**
     * A holiday or a recess that counts as working time is not announced as a
     * pause; a closed day and a full-day optional day always are.
     *
     * @return void
     */
    public function test_days_counted_as_working_time_are_not_announced(): void {
        $this->resetAfterTest();
        $this->seed_calendar();
        $this->add_cday(20260629, 'holiday', null, null, 'H');
        $this->add_cday(20260630, 'recess', null, null, 'R');
        $this->add_cday(20260701, 'closed', null, null, 'C');
        $this->add_cday(20260702, 'optional', null, null, 'O');
        $now = $this->ts('2026-06-29 09:00:00');

        // Control: with both exclusions on, all four days are announced.
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        $types = array_column(upcoming_pauses::for_course_group(0, 0, $now, 10), 'type');
        $this->assertSame(['holiday', 'recess', 'closed', 'optional'], $types);

        set_config('excludeholidays', '0', 'block_feedback_tracker');
        $types = array_column(upcoming_pauses::for_course_group(0, 0, $now, 10), 'type');
        $this->assertSame(['recess', 'closed', 'optional'], $types);

        set_config('excluderecesses', '0', 'block_feedback_tracker');
        $types = array_column(upcoming_pauses::for_course_group(0, 0, $now, 10), 'type');
        $this->assertSame(['closed', 'optional'], $types);
    }

    /**
     * Seed enough calendar config for the lookup to read.
     *
     * @return void
     */
    private function seed_calendar(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        pause_lookup::reset_memo();
    }

    /**
     * Insert a calendar-day row.
     *
     * @param int $daydate YYYYMMDD.
     * @param string $daytype Day-type slug.
     * @param int|null $starttime Minutes since midnight, or null.
     * @param int|null $endtime Minutes since midnight, or null.
     * @param string|null $note Optional label.
     * @return void
     */
    private function add_cday(
        int $daydate,
        string $daytype,
        ?int $starttime = null,
        ?int $endtime = null,
        ?string $note = null
    ): void {
        global $DB;
        $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => $daydate,
            'daytype' => $daytype,
            'starttime' => $starttime,
            'endtime' => $endtime,
            'note' => $note,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Insert a manual pause window.
     *
     * @param string $scopelevel site / course / group.
     * @param int $scopeid Scope id.
     * @param string $start UTC datetime string.
     * @param string $end UTC datetime string.
     * @param string|null $note Optional note.
     * @return void
     */
    private function add_cpause(string $scopelevel, int $scopeid, string $start, string $end, ?string $note = null): void {
        global $DB;
        $DB->insert_record('block_feedback_tracker_cpause', (object) [
            'scopelevel' => $scopelevel,
            'scopeid' => $scopeid,
            'contextid' => 1,
            'reason' => 'closure',
            'timestart' => $this->ts($start),
            'timeend' => $this->ts($end),
            'note' => $note,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        pause_lookup::reset_memo();
    }

    /**
     * UTC datetime string → unix timestamp.
     *
     * @param string $datetime e.g. "2026-06-29 16:00:00".
     * @return int
     */
    private function ts(string $datetime): int {
        return (new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
