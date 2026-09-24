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
 * Paused-days aggregator — counts days in a window where the calendar
 * marked time as not counting toward the SLA, broken down by reason.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Counts paused days in a window by reason (weekend / holiday / recess) for
 * the "Paused periods excluded from this report" callout and the academic-days
 * strip on the report page ({@see \block_feedback_tracker\external\get_academic_days}),
 * the teacher dashboard and the block payload.
 *
 * Reasons are bucketed for display, not for SLA arithmetic:
 *  - weekend  — a weekend-mask day while weekends are excluded, unless a
 *               'schoolday' row in {block_feedback_tracker_cday} opts it into
 *               the working week.
 *  - holiday  — a 'holiday' row, while holidays are excluded.
 *  - recess   — a 'recess', 'closed' or full-day 'optional' row while recesses
 *               are excluded, or any manual pause (site, the course, or a
 *               group of the course) touching the day.
 *
 * Sub-day optional events (an 'optional' row with starttime and endtime set)
 * do not pause the day; they are returned in the `events` sidecar with their
 * window and label, so the callout can add "… · 1 event (Staff meeting)"
 * beside the day counts.
 */
class paused_aggregator {
    /**
     * Count paused days in a [start, end) window, by reason. Derived from the
     * per-day classification so the counts and the per-day map (consumed by
     * the report-page heatmap) can never drift apart. An event's label is its
     * note as plain text: tags stripped, not HTML-escaped.
     *
     * @param int $courseid Course context for manual-pause scoping; 0 for site-wide.
     * @param int $start    Unix seconds; inclusive lower bound.
     * @param int $end      Unix seconds; exclusive upper bound. Must be > $start.
     * @return array{total_days:int, weekend:int, holiday:int, recess:int,
     *               events:array<int, array{date:int, starttime:int, endtime:int, label:string}>}
     */
    public static function for_window(int $courseid, int $start, int $end): array {
        $classified = self::classify_window($courseid, $start, $end);

        $weekend = 0;
        $holiday = 0;
        $recess = 0;
        foreach ($classified['perday'] as $info) {
            if (!$info['paused']) {
                continue;
            }
            switch ($info['reason']) {
                case 'weekend':
                    $weekend++;
                    break;
                case 'holiday':
                    $holiday++;
                    break;
                case 'recess':
                    $recess++;
                    break;
            }
        }

        return [
            'total_days' => $weekend + $holiday + $recess,
            'weekend'    => $weekend,
            'holiday'    => $holiday,
            'recess'     => $recess,
            'events'     => $classified['events'],
        ];
    }

    /**
     * Per-day pause classification for a [start, end) window. Returns one
     * entry per calendar day (keyed by YYYYMMDD) carrying whether the day is
     * paused and, if so, the bucketed reason (weekend / holiday / recess).
     * Powers the report-page "last 30 academic days" heatmap.
     *
     * Sub-day optional events do not mark the day paused (they are hour-scale);
     * they still surface via for_window()'s events sidecar.
     *
     * @param int $courseid Course context for manual-pause scoping; 0 for site-wide.
     * @param int $start    Unix seconds; inclusive lower bound.
     * @param int $end      Unix seconds; exclusive upper bound. Must be > $start.
     * @return array<int, array{paused:bool, reason:string}> Keyed by YYYYMMDD, oldest first.
     */
    public static function per_day_for_window(int $courseid, int $start, int $end): array {
        return self::classify_window($courseid, $start, $end)['perday'];
    }

    /**
     * Walk every day in [start, end) and classify it as paused (with a bucketed
     * reason) or active, collecting sub-day optional events as a sidecar. The
     * single source of truth behind both for_window() (counts) and
     * per_day_for_window() (map).
     *
     * @param int $courseid Course context for manual-pause scoping; 0 for site-wide.
     * @param int $start    Unix seconds; inclusive lower bound.
     * @param int $end      Unix seconds; exclusive upper bound.
     * @return array{perday:array<int, array{paused:bool, reason:string}>,
     *               events:array<int, array{date:int, starttime:int, endtime:int, label:string}>}
     */
    private static function classify_window(int $courseid, int $start, int $end): array {
        $empty = ['perday' => [], 'events' => []];
        if ($end <= $start) {
            return $empty;
        }

        $tz = calendar::timezone();
        $excludeweekends = calendar::excludeweekends();
        $excludeholidays = calendar::excludeholidays();
        $excluderecesses = calendar::excluderecesses();
        $sysctx = \context_system::instance();

        // Walk each day in the window in the platform timezone. Window is
        // small (typically 30 days) so per-day iteration is cheap.
        $cur = (new \DateTimeImmutable('@' . $start))->setTimezone($tz)->setTime(0, 0, 0);
        $endboundary = (new \DateTimeImmutable('@' . $end))->setTimezone($tz);
        $days = [];
        $dayymds = [];
        while ($cur < $endboundary) {
            $ymd = (int) $cur->format('Ymd');
            // Day of the week counted from Monday as 0, the numbering calendar::is_weekend() reads.
            $dow = (int) $cur->format('N') - 1;
            $days[$ymd] = $dow;
            $dayymds[] = $ymd;
            $cur = $cur->modify('+1 day');
        }
        if (empty($dayymds)) {
            return $empty;
        }

        $overrides = self::day_overrides($dayymds);
        $pausespans = self::pause_spans($courseid, $start, $end);

        $perday = [];
        $events = [];

        foreach ($days as $ymd => $dow) {
            $override = $overrides[$ymd] ?? null;
            $isweekend = $excludeweekends && calendar::is_weekend($dow);
            $type = $override['daytype'] ?? null;

            // Schoolday overrides cancel the weekend classification.
            if ($type === calendar::DAYTYPE_SCHOOLDAY) {
                $isweekend = false;
            }

            // Priority: holiday > recess/closed/full-day-optional/manual-pause > weekend.
            if ($type === calendar::DAYTYPE_HOLIDAY && $excludeholidays) {
                $perday[$ymd] = ['paused' => true, 'reason' => 'holiday'];
                continue;
            }
            if (($type === calendar::DAYTYPE_RECESS || $type === calendar::DAYTYPE_CLOSED) && $excluderecesses) {
                $perday[$ymd] = ['paused' => true, 'reason' => 'recess'];
                continue;
            }
            if ($type === calendar::DAYTYPE_OPTIONAL) {
                // Sub-day event vs full-day optional.
                $optstart = $override['starttime'];
                $optend = $override['endtime'];
                if ($optstart !== null && $optend !== null) {
                    // Sub-day — surface in the events sidecar but do NOT
                    // mark the day paused (it's hour-scale, not day). The label
                    // is plain text, like {@see upcoming_pauses::clean_note()}.
                    $events[] = [
                        'date'      => (int) $ymd,
                        'starttime' => (int) $optstart,
                        'endtime'   => (int) $optend,
                        'label'     => format_string(
                            (string) ($override['note'] ?? ''),
                            true,
                            ['context' => $sysctx, 'escape' => false]
                        ),
                    ];
                } else if ($excluderecesses) {
                    // Full-day optional — fold into the recess bucket.
                    $perday[$ymd] = ['paused' => true, 'reason' => 'recess'];
                    continue;
                }
                // Fall through — sub-day events still get the weekend
                // check applied below in case the day is a Saturday.
            }
            if (self::ymd_in_pause_span($ymd, $tz, $pausespans)) {
                $perday[$ymd] = ['paused' => true, 'reason' => 'recess'];
                continue;
            }
            $perday[$ymd] = ['paused' => $isweekend, 'reason' => $isweekend ? 'weekend' : ''];
        }

        return ['perday' => $perday, 'events' => $events];
    }

    /**
     * Fetch the day-override rows for the supplied YYYYMMDD ints. Returns
     * a per-day shape carrying daytype + starttime / endtime / note so
     * classify_window() can distinguish sub-day optional events from full-day
     * rules.
     *
     * @param int[] $ymds
     * @return array<int, array{daytype:string, starttime:?int, endtime:?int, note:?string}>
     */
    private static function day_overrides(array $ymds): array {
        global $DB;
        if (empty($ymds)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ymds, SQL_PARAMS_NAMED, 'd');
        $rows = $DB->get_records_select(
            'block_feedback_tracker_cday',
            "daydate $insql",
            $params,
            '',
            'id, daydate, daytype, starttime, endtime, note'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->daydate] = [
                'daytype'   => (string) $r->daytype,
                'starttime' => $r->starttime !== null ? (int) $r->starttime : null,
                'endtime' => $r->endtime !== null ? (int) $r->endtime : null,
                'note' => $r->note !== null ? (string) $r->note : null,
            ];
        }
        return $out;
    }

    /**
     * Fetch [start, end) manual pause windows scoped to the course (or
     * site-wide for $courseid = 0). A pause on any group of the course
     * counts too: the aggregate is per course, not per group.
     *
     * @param int $courseid
     * @param int $windowstart
     * @param int $windowend
     * @return array<int, array{start:int, end:int}>
     */
    private static function pause_spans(int $courseid, int $windowstart, int $windowend): array {
        global $DB;

        $sql = "SELECT id, scopelevel, scopeid, timestart, timeend
                  FROM {block_feedback_tracker_cpause}
                 WHERE timestart < :wend
                   AND (timeend IS NULL OR timeend > :wstart)
                   AND (
                       scopelevel = :sitescope
                       OR (scopelevel = :coursescope AND scopeid = :courseid)
                       OR (scopelevel = :groupscope AND scopeid IN (
                           SELECT g.id FROM {groups} g WHERE g.courseid = :groupcourseid
                       ))
                   )";
        $params = [
            'wend'          => $windowend,
            'wstart'        => $windowstart,
            'sitescope'     => 'site',
            'coursescope'   => 'course',
            'groupscope'    => 'group',
            'courseid'      => $courseid,
            'groupcourseid' => $courseid,
        ];
        $rows = $DB->get_records_sql($sql, $params);
        $spans = [];
        foreach ($rows as $r) {
            $spans[] = [
                'start' => (int) $r->timestart,
                'end'   => $r->timeend !== null ? (int) $r->timeend : $windowend,
            ];
        }
        return $spans;
    }

    /**
     * Whether the given calendar day overlaps any pause span. A day is
     * "paused" if any second of its 24-hour window in the platform tz is
     * inside a pause span.
     *
     * @param int $ymd
     * @param \DateTimeZone $tz
     * @param array $spans
     * @return bool
     */
    private static function ymd_in_pause_span(int $ymd, \DateTimeZone $tz, array $spans): bool {
        if (empty($spans)) {
            return false;
        }
        // Use intdiv() to keep every intermediate value as an int — `/`
        // promotes to float and `% 100` on a float triggers the
        // "implicit conversion ... loses precision" deprecation in PHP 8.x.
        $datestr = sprintf(
            '%04d-%02d-%02d',
            intdiv($ymd, 10000),
            intdiv($ymd, 100) % 100,
            $ymd % 100
        );
        $daystart = (new \DateTimeImmutable($datestr, $tz))->getTimestamp();
        $dayend = $daystart + 86400;
        foreach ($spans as $span) {
            if ($span['start'] < $dayend && $span['end'] > $daystart) {
                return true;
            }
        }
        return false;
    }
}
