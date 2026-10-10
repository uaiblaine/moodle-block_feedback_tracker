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
 * Upcoming scheduled-pause lookup for the "Upcoming pause" notice.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Lists scheduled calendar pauses that should be advertised "now" on the
 * block, dashboard and report as a short upcoming-pauses notice.
 *
 * Two data sources, both already used by the academic-time engine:
 *  - day-type rows in {block_feedback_tracker_cday} (holiday / recess /
 *    closed / optional). Sub-day optional rows (starttime + endtime set)
 *    surface as timed events; everything else is a full-day pause, and
 *    consecutive same-type/same-note days are collapsed into one span so a
 *    five-day recess shows as a single entry, not five.
 *  - manual pause windows in {block_feedback_tracker_cpause}, scoped to the
 *    (courseid, groupid) tuple via {@see pause_lookup}.
 *
 * Visibility window, in whole calendar days: a pause is advertised from
 * {@see self::LEAD_DAYS} days before the day it starts, and disappears once
 * the calendar day after its last day begins.
 */
class upcoming_pauses {
    /** Calendar days before a pause that the notice starts to show. */
    public const LEAD_DAYS = 3;

    /**
     * How far back to scan for day-type rows / manual windows so a pause that
     * is already running (a long recess, an open-ended manual window) is still
     * caught. A pause longer than a year before today is implausible.
     */
    private const LOOKBACK_DAYS = 366;

    /**
     * Scheduled pauses visible now for a (course, group), soonest first.
     *
     * @param int $courseid Course context for manual-pause scoping; 0 for site-wide.
     * @param int $groupid Group context for manual-pause scoping; 0 if none.
     * @param int $now Reference unix timestamp ("today").
     * @param int $limit Maximum number of entries to return.
     * @return array<int, array{start:int, end:int|null, type:string, label:string, subday:bool}>
     */
    public static function for_course_group(int $courseid, int $groupid, int $now, int $limit = 3): array {
        $tz = calendar::timezone();
        $todaystart = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0, 0)->getTimestamp();

        $candidates = array_merge(
            self::cday_candidates($tz, $now),
            self::cpause_candidates($courseid, $groupid, $now)
        );

        $visible = [];
        foreach ($candidates as $c) {
            if (self::is_visible($c, $tz, $now, $todaystart)) {
                $visible[] = $c;
            }
        }

        usort($visible, static fn($a, $b) => $a['start'] <=> $b['start']);

        return array_slice($visible, 0, max(0, $limit));
    }

    /**
     * Like {@see self::for_course_group()} but decorated with the localised
     * display strings every surface renders: `when` (date / time window) and
     * `typelabel` (the value after "Type:"). Formatting is centralised here — in
     * the platform calendar timezone — so the block (WS + no-JS card),
     * dashboard and report all show identical text.
     *
     * @param int $courseid Course context for manual-pause scoping; 0 for site-wide.
     * @param int $groupid Group context for manual-pause scoping; 0 if none.
     * @param int $now Reference unix timestamp ("today").
     * @param int $limit Maximum number of entries to return.
     * @return array<int, array{start:int, type:string, label:string, when:string, typelabel:string}>
     */
    public static function for_display(int $courseid, int $groupid, int $now, int $limit = 3): array {
        $tz = calendar::timezone();
        $out = [];
        foreach (self::for_course_group($courseid, $groupid, $now, $limit) as $entry) {
            $out[] = [
                'start' => (int) $entry['start'],
                'type' => (string) $entry['type'],
                'label' => (string) $entry['label'],
                'when' => self::format_when($entry, $tz),
                'typelabel' => self::type_label((string) $entry['type']),
            ];
        }
        return $out;
    }

    /**
     * Localised "when" line for an entry: a timed window for sub-day events
     * ("06/29/2026, 16:00–17:00" in English), a single date for one full day,
     * a date range for a multi-day span, or an open-ended start.
     *
     * @param array $entry One raw entry from for_course_group().
     * @param \DateTimeZone $tz Platform timezone.
     * @return string
     */
    private static function format_when(array $entry, \DateTimeZone $tz): string {
        $start = (int) $entry['start'];
        $startdate = self::format_date($start, $tz);

        if (!empty($entry['subday']) && $entry['end'] !== null) {
            return get_string('pause_when_timed', 'block_feedback_tracker', (object) [
                'date' => $startdate,
                'start' => self::format_hour($start, $tz),
                'end' => self::format_hour((int) $entry['end'], $tz),
            ]);
        }
        if ($entry['end'] === null) {
            return get_string('pause_when_from', 'block_feedback_tracker', $startdate);
        }
        // The end timestamp is exclusive, so the last covered day holds (end − 1).
        $lastdate = self::format_date((int) $entry['end'] - 1, $tz);
        if ($lastdate === $startdate) {
            return $startdate;
        }
        return get_string('pause_when_range', 'block_feedback_tracker', (object) [
            'start' => $startdate,
            'end' => $lastdate,
        ]);
    }

    /**
     * Localised date in the platform timezone (format per language).
     *
     * @param int $ts Unix timestamp.
     * @param \DateTimeZone $tz Platform timezone.
     * @return string
     */
    private static function format_date(int $ts, \DateTimeZone $tz): string {
        // Passing fixday off keeps the day zero-padded ("09/06/2026"), to
        // match the plugin's existing JS date rendering.
        return userdate($ts, get_string('pause_date_format', 'block_feedback_tracker'), $tz->getName(), false);
    }

    /**
     * Localised hour-of-day: "16h" / "16h30" (pt_br) or "16:00" / "16:30" (en).
     *
     * @param int $ts Unix timestamp.
     * @param \DateTimeZone $tz Platform timezone.
     * @return string
     */
    private static function format_hour(int $ts, \DateTimeZone $tz): string {
        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $hour = (int) $dt->format('G');
        $min = (int) $dt->format('i');
        if ($min === 0) {
            return get_string('pause_time_oclock', 'block_feedback_tracker', $hour);
        }
        return get_string('pause_time_minutes', 'block_feedback_tracker', (object) [
            'h' => $hour,
            'm' => sprintf('%02d', $min),
        ]);
    }

    /**
     * Localised label for a pause type/reason slug. Day-types reuse
     * calendar::daytype_label(); manual-pause scopes reuse the pause_reason_*
     * family.
     *
     * @param string $type Type / reason slug.
     * @return string
     */
    private static function type_label(string $type): string {
        switch ($type) {
            case calendar::DAYTYPE_HOLIDAY:
                return calendar::daytype_label(calendar::DAYTYPE_HOLIDAY);
            case calendar::DAYTYPE_RECESS:
                return calendar::daytype_label(calendar::DAYTYPE_RECESS);
            case calendar::DAYTYPE_CLOSED:
                return calendar::daytype_label(calendar::DAYTYPE_CLOSED);
            case calendar::DAYTYPE_OPTIONAL:
                return calendar::daytype_label(calendar::DAYTYPE_OPTIONAL);
            case 'sitepaused':
                return get_string('pause_reason_sitepaused', 'block_feedback_tracker');
            case 'coursepaused':
                return get_string('pause_reason_coursepaused', 'block_feedback_tracker');
            case 'grouppaused':
                return get_string('pause_reason_grouppaused', 'block_feedback_tracker');
            default:
                return '';
        }
    }

    /**
     * Whether a candidate is inside its [start − LEAD_DAYS, end + 1 day) window.
     *
     * @param array $candidate One candidate from cday_candidates / cpause_candidates.
     * @param \DateTimeZone $tz Platform timezone.
     * @param int $now Reference timestamp.
     * @param int $todaystart Start-of-today timestamp (platform tz).
     * @return bool
     */
    private static function is_visible(array $candidate, \DateTimeZone $tz, int $now, int $todaystart): bool {
        $startdaystart = self::day_start($candidate['start'], $tz);
        $leadstart = (new \DateTimeImmutable('@' . $startdaystart))
            ->setTimezone($tz)
            ->modify('-' . self::LEAD_DAYS . ' days')
            ->getTimestamp();
        if ($now < $leadstart) {
            return false;
        }
        // Open-ended manual windows never reach a removal point while active.
        if ($candidate['end'] === null) {
            return true;
        }
        // Removal begins the calendar day after the pause's last day. The end
        // timestamp is exclusive, so the last day contains (end − 1).
        $lastdaystart = self::day_start((int) $candidate['end'] - 1, $tz);
        $removal = (new \DateTimeImmutable('@' . $lastdaystart))
            ->setTimezone($tz)
            ->modify('+1 day')
            ->getTimestamp();
        return $now < $removal;
    }

    /**
     * Day-type candidates from {block_feedback_tracker_cday}. Sub-day optional
     * rows become timed events; every other holiday / recess / closed /
     * optional row becomes a full-day span (consecutive same-type/same-note
     * days collapsed). Holidays are skipped while excludeholidays is off, and
     * recesses while excluderecesses is off: those days then count as working
     * time ({@see calendar::is_active_day()}), so they pause nothing.
     *
     * @param \DateTimeZone $tz Platform timezone.
     * @param int $now Reference timestamp.
     * @return array<int, array{start:int, end:int|null, type:string, label:string, subday:bool}>
     */
    private static function cday_candidates(\DateTimeZone $tz, int $now): array {
        global $DB;

        $from = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)
            ->modify('-' . self::LOOKBACK_DAYS . ' days');
        $to = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)
            ->modify('+' . self::LEAD_DAYS . ' days');
        $fromymd = (int) $from->format('Ymd');
        $toymd = (int) $to->format('Ymd');

        $types = [calendar::DAYTYPE_CLOSED, calendar::DAYTYPE_OPTIONAL];
        if (calendar::excludeholidays()) {
            $types[] = calendar::DAYTYPE_HOLIDAY;
        }
        if (calendar::excluderecesses()) {
            $types[] = calendar::DAYTYPE_RECESS;
        }
        [$typesql, $typeparams] = $DB->get_in_or_equal($types, SQL_PARAMS_NAMED, 'dtype');

        $rows = $DB->get_records_select(
            'block_feedback_tracker_cday',
            "daydate >= :fromymd AND daydate <= :toymd AND daytype $typesql",
            ['fromymd' => $fromymd, 'toymd' => $toymd] + $typeparams,
            'daydate ASC',
            'id, daydate, daytype, starttime, endtime, note'
        );

        $events = [];
        $fulldays = [];
        foreach ($rows as $r) {
            $issubday = (string) $r->daytype === calendar::DAYTYPE_OPTIONAL
                && $r->starttime !== null && $r->endtime !== null;
            if ($issubday) {
                $midnight = self::ymd_midnight((int) $r->daydate, $tz);
                $events[] = [
                    'start' => self::wall_clock_ts($midnight, (int) $r->starttime),
                    'end' => self::wall_clock_ts($midnight, (int) $r->endtime),
                    'type' => (string) $r->daytype,
                    'label' => self::clean_note($r->note),
                    'subday' => true,
                ];
            } else {
                $fulldays[] = $r;
            }
        }

        return array_merge($events, self::collapse_spans($fulldays, $tz));
    }

    /**
     * Collapse consecutive same-type/same-note full-day rows into spans.
     *
     * @param array $rows Full-day cday rows, ordered by daydate ascending.
     * @param \DateTimeZone $tz Platform timezone.
     * @return array<int, array{start:int, end:int|null, type:string, label:string, subday:bool}>
     */
    private static function collapse_spans(array $rows, \DateTimeZone $tz): array {
        $spans = [];
        $current = null;
        foreach ($rows as $r) {
            $type = (string) $r->daytype;
            $note = self::clean_note($r->note);
            $daystart = self::ymd_to_ts((int) $r->daydate, $tz);
            $nextday = (new \DateTimeImmutable('@' . $daystart))->setTimezone($tz)
                ->modify('+1 day')->getTimestamp();

            if (
                $current !== null
                && $current['type'] === $type
                && $current['label'] === $note
                && $current['end'] === $daystart
            ) {
                $current['end'] = $nextday;
                continue;
            }
            if ($current !== null) {
                $spans[] = $current;
            }
            $current = [
                'start' => $daystart,
                'end' => $nextday,
                'type' => $type,
                'label' => $note,
                'subday' => false,
            ];
        }
        if ($current !== null) {
            $spans[] = $current;
        }
        return $spans;
    }

    /**
     * Manual pause-window candidates scoped to the (course, group) tuple.
     *
     * @param int $courseid Course context; 0 for site-wide.
     * @param int $groupid Group context; 0 if none.
     * @param int $now Reference timestamp.
     * @return array<int, array{start:int, end:int|null, type:string, label:string, subday:bool}>
     */
    private static function cpause_candidates(int $courseid, int $groupid, int $now): array {
        $from = $now - self::LOOKBACK_DAYS * 86400;
        $to = $now + (self::LEAD_DAYS + 1) * 86400;
        $pauses = pause_lookup::for_course_group($courseid, $groupid, $from, $to);

        $out = [];
        foreach ($pauses as $p) {
            $out[] = [
                'start' => (int) $p->timestart,
                'end' => $p->timeend !== null ? (int) $p->timeend : null,
                'type' => pause_lookup::reason_for_scope((string) $p->scopelevel),
                'label' => self::clean_note($p->note),
                'subday' => false,
            ];
        }
        return $out;
    }

    /**
     * A stored note as a plain-text label: filtered and stripped of tags by
     * format_string(), but not HTML-escaped. Every consumer escapes for itself
     * (the card's Mustache double stash, the text nodes of the JS views), so an
     * escaped label would show a typed "A & B" as "A &amp; B".
     *
     * @param string|null $note Raw note value.
     * @return string Plain text; '' for no note.
     */
    private static function clean_note(?string $note): string {
        if ($note === null || $note === '') {
            return '';
        }
        return format_string($note, true, ['context' => \context_system::instance(), 'escape' => false]);
    }

    /**
     * Start-of-day timestamp for the calendar day containing a timestamp.
     *
     * @param int $ts Unix timestamp.
     * @param \DateTimeZone $tz Platform timezone.
     * @return int
     */
    private static function day_start(int $ts, \DateTimeZone $tz): int {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->setTime(0, 0, 0)->getTimestamp();
    }

    /**
     * Convert a YYYYMMDD int to the midnight timestamp of that day.
     *
     * @param int $ymd Date as YYYYMMDD.
     * @param \DateTimeZone $tz Platform timezone.
     * @return int
     */
    private static function ymd_to_ts(int $ymd, \DateTimeZone $tz): int {
        return self::ymd_midnight($ymd, $tz)->getTimestamp();
    }

    /**
     * Midnight of a YYYYMMDD day in the platform timezone.
     *
     * @param int $ymd Date as YYYYMMDD.
     * @param \DateTimeZone $tz Platform timezone.
     * @return \DateTimeImmutable
     */
    private static function ymd_midnight(int $ymd, \DateTimeZone $tz): \DateTimeImmutable {
        $datestr = sprintf(
            '%04d-%02d-%02d 00:00:00',
            intdiv($ymd, 10000),
            intdiv($ymd, 100) % 100,
            $ymd % 100
        );
        return new \DateTimeImmutable($datestr, $tz);
    }

    /**
     * The instant a stored minutes-since-midnight value names on a day.
     *
     * The minutes are wall-clock time, as for business hours: on a day with a
     * DST transition, 480 is 08:00 local rather than eight elapsed hours after
     * midnight. Same construction as academic_time::business_hours_absolute().
     *
     * @param \DateTimeImmutable $midnight Midnight of the day, platform timezone.
     * @param int $minutes Minutes since midnight.
     * @return int Unix timestamp.
     */
    private static function wall_clock_ts(\DateTimeImmutable $midnight, int $minutes): int {
        return $midnight->modify('+' . $minutes . ' minutes')->getTimestamp();
    }
}
