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
 * Academic-time engine facade.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Computes the effective business/academic hours elapsed between two unix
 * timestamps for a given (courseid, groupid), excluding weekends, holidays,
 * recesses, closures, out-of-business-hours periods, and overlapping manual
 * pause windows.
 *
 * The (courseid, groupid) is used only to scope manual pause windows in
 * {block_feedback_tracker_cpause}: weekly hours, weekend mask, and per-day
 * exceptions are global platform settings.
 *
 * Behaviour when grading happens inside a paused window is governed by the
 * site setting `grading_during_pause_mode`:
 *  - `clipped` (default): manual pauses and inactive day-types both subtract
 *    from effective hours.
 *  - `live`: only inactive day-types subtract; manual pauses are recorded
 *    in the audit but do not reduce effective hours.
 */
class academic_time {
    /**
     * Effective business hours between two timestamps.
     *
     * Returns exactly what {@see self::elapsed_with_audit()} reports as
     * 'hours', without building the pause-record audit trail. Long
     * intervals take a fast path: whole days that are provably plain are
     * counted arithmetically by day of week and only the exceptional
     * stretches run through the day-by-day walker — see
     * {@see self::fast_active_seconds()}. The decomposition sums the same
     * integer seconds the walker would produce, so the result is
     * identical, not approximate.
     *
     * @param int $courseid Course context (for manual pause scoping).
     * @param int $groupid Group context (for manual pause scoping); 0 if none.
     * @param int $tsfrom Inclusive unix timestamp.
     * @param int $tsto Exclusive unix timestamp.
     * @return float Effective hours; never negative; rounded to 2 decimals.
     */
    public static function elapsed_effective_hours(
        int $courseid,
        int $groupid,
        int $tsfrom,
        int $tsto
    ): float {
        if ($tsto <= $tsfrom) {
            return 0.0;
        }
        $seconds = self::fast_active_seconds($courseid, $groupid, $tsfrom, $tsto);
        if ($seconds === null) {
            $seconds = self::active_seconds_with_audit($courseid, $groupid, $tsfrom, $tsto)['seconds'];
        }
        return round($seconds / 3600.0, 2);
    }

    /**
     * Effective business hours plus the pause records that contributed.
     *
     * Each pause record carries reason / timestart / timeend / scopelevel /
     * scopeid / note. Since v2.0.0 the per-submission pause table was
     * removed; this audit array is consumed only on demand by
     * `get_pause_timeline`.
     *
     * @param int $courseid Course context (for manual pause scoping).
     * @param int $groupid Group context (for manual pause scoping); 0 if none.
     * @param int $tsfrom Inclusive unix timestamp.
     * @param int $tsto Exclusive unix timestamp.
     * @return array{
     *     hours: float,
     *     pauses: array<int, array{
     *         reason: string,
     *         timestart: int,
     *         timeend: int,
     *         scopelevel: ?string,
     *         scopeid: ?int,
     *         note: ?string
     *     }>
     * }
     */
    public static function elapsed_with_audit(
        int $courseid,
        int $groupid,
        int $tsfrom,
        int $tsto
    ): array {
        $result = self::active_seconds_with_audit($courseid, $groupid, $tsfrom, $tsto);
        return [
            'hours' => round($result['seconds'] / 3600.0, 2),
            'pauses' => $result['pauses'],
        ];
    }

    /**
     * Walk the interval day by day: exact active seconds plus the audit trail.
     *
     * This is the full engine. Every day in the interval is resolved through
     * {@see day_rule_resolver} and intersected with its absolute
     * business-hours windows; manual pauses are subtracted afterwards over
     * the union of all active intervals. Seconds are returned UNROUNDED so
     * callers can aggregate results over sub-intervals exactly; rounding to
     * hours happens once, at the public API boundary.
     *
     * @param int $courseid Course context (for manual pause scoping).
     * @param int $groupid Group context (for manual pause scoping); 0 if none.
     * @param int $tsfrom Inclusive unix timestamp.
     * @param int $tsto Exclusive unix timestamp.
     * @return array{seconds: int|float, pauses: array} Unrounded active
     *     seconds (never negative) and the pause records that contributed.
     */
    private static function active_seconds_with_audit(
        int $courseid,
        int $groupid,
        int $tsfrom,
        int $tsto
    ): array {
        if ($tsto <= $tsfrom) {
            return ['seconds' => 0, 'pauses' => []];
        }

        $tz = calendar::timezone();
        $pausemode = calendar::grading_during_pause_mode();

        $activeintervals = [];
        $pauses = [];

        $day = (new \DateTimeImmutable('@' . $tsfrom))
            ->setTimezone($tz)
            ->setTime(0, 0, 0);
        $stopday = (new \DateTimeImmutable('@' . ($tsto - 1)))
            ->setTimezone($tz)
            ->setTime(0, 0, 0);

        while ($day <= $stopday) {
            $dayofweek = (int) $day->format('N') - 1;
            $daydate = (int) $day->format('Ymd');
            $rule = day_rule_resolver::for_date($daydate, $dayofweek);

            $daystartts = $day->getTimestamp();
            $nextdayts = $day->modify('+1 day')->getTimestamp();
            $windowstart = max($daystartts, $tsfrom);
            $windowend = min($nextdayts, $tsto);

            if ($windowstart < $windowend) {
                if (!$rule['is_active']) {
                    $pauses[] = [
                        'reason' => self::pause_reason_for_day($rule),
                        'timestart' => $windowstart,
                        'timeend' => $windowend,
                        'scopelevel' => null,
                        'scopeid' => null,
                        'note' => $rule['day_note'],
                    ];
                } else {
                    $bhabsolute = self::business_hours_absolute($day, $rule['business_hours']);
                    $windowinterval = [[$windowstart, $windowend]];

                    $active = interval_math::intersect($windowinterval, $bhabsolute);

                    /* v1.0.9 — sub-day optional event window. The day is
                     * otherwise active per its weekly rule (see
                     * day_rule_resolver), but the event window must be
                     * subtracted from active intervals and recorded as a
                     * pause with reason='optional' so PausedNote can
                     * surface the event label. */
                    if (!empty($rule['optional_window'])) {
                        $eventabs = self::business_hours_absolute(
                            $day,
                            [[(int) $rule['optional_window']['startmin'],
                              (int) $rule['optional_window']['endmin']]]
                        );
                        if (!empty($eventabs)) {
                            $eventoverlap = interval_math::intersect($active, $eventabs);
                            if (!empty($eventoverlap)) {
                                $active = interval_math::subtract($active, $eventabs);
                                foreach ($eventoverlap as $iv) {
                                    $pauses[] = [
                                        'reason'     => 'optional',
                                        'timestart'  => $iv[0],
                                        'timeend'    => $iv[1],
                                        'scopelevel' => null,
                                        'scopeid'    => null,
                                        'note'       => $rule['optional_window']['note'] ?? null,
                                    ];
                                }
                            }
                        }
                    }

                    foreach ($active as $iv) {
                        $activeintervals[] = $iv;
                    }

                    $inactive = interval_math::subtract($windowinterval, $bhabsolute);
                    foreach ($inactive as $iv) {
                        $pauses[] = [
                            'reason' => 'outofhours',
                            'timestart' => $iv[0],
                            'timeend' => $iv[1],
                            'scopelevel' => null,
                            'scopeid' => null,
                            'note' => null,
                        ];
                    }
                }
            }

            $day = $day->modify('+1 day');
        }

        $activeintervals = interval_math::union($activeintervals);

        $manualpauses = pause_lookup::for_course_group($courseid, $groupid, $tsfrom, $tsto);
        $clipintervals = [];
        foreach ($manualpauses as $row) {
            $pstart = max((int) $row->timestart, $tsfrom);
            $pend = min($row->timeend !== null ? (int) $row->timeend : $tsto, $tsto);
            if ($pstart >= $pend) {
                continue;
            }

            $overlap = interval_math::intersect($activeintervals, [[$pstart, $pend]]);
            if (empty($overlap)) {
                continue;
            }

            foreach ($overlap as $iv) {
                $pauses[] = [
                    'reason' => self::pause_reason_for_scope((string) $row->scopelevel),
                    'timestart' => $iv[0],
                    'timeend' => $iv[1],
                    'scopelevel' => (string) $row->scopelevel,
                    'scopeid' => (int) $row->scopeid,
                    'note' => $row->note !== null ? (string) $row->note : null,
                ];
            }

            if ($pausemode === calendar::PAUSE_MODE_CLIPPED) {
                $clipintervals[] = [$pstart, $pend];
            }
        }

        if (!empty($clipintervals)) {
            $activeintervals = interval_math::subtract(
                $activeintervals,
                interval_math::union($clipintervals)
            );
        }

        $activeseconds = interval_math::total($activeintervals);

        usort($pauses, static fn($a, $b) => $a['timestart'] <=> $b['timestart']);

        return ['seconds' => $activeseconds, 'pauses' => $pauses];
    }

    /**
     * Active seconds without walking plain days one by one.
     *
     * Splits [$tsfrom, $tsto) into whole "plain" days and exceptional
     * stretches. A day is plain when it is entirely inside the interval,
     * has no {block_feedback_tracker_cday} row, sits nowhere near a
     * timezone offset transition, and no manual pause window overlaps it.
     * Plain days resolve to the implicit weekly rule, a pure function of
     * the day of week, so their active seconds are seven precomputed
     * values multiplied by day counts obtained arithmetically. Every other
     * stretch — the partial first/last day, calendar-exception days,
     * pause-covered days, DST-transition days — is day-aligned and runs
     * through {@see self::active_seconds_with_audit()} unchanged.
     *
     * The manual-pause restriction is structural, not an optimisation: the
     * walker subtracts pause overlap AFTER unioning active intervals, so a
     * day collapsed to a bare seconds count could not have an overlapping
     * pause subtracted from it exactly.
     *
     * @param int $courseid Course context (for manual pause scoping).
     * @param int $groupid Group context (for manual pause scoping); 0 if none.
     * @param int $tsfrom Inclusive unix timestamp; must be < $tsto.
     * @param int $tsto Exclusive unix timestamp.
     * @return int|float|null Exact active seconds, or null when the caller
     *     must fall back to the full day-by-day walk.
     */
    private static function fast_active_seconds(
        int $courseid,
        int $groupid,
        int $tsfrom,
        int $tsto
    ): int|float|null {
        global $DB;

        $tz = calendar::timezone();
        $day0 = self::day_start($tsfrom, $tz);
        $stopday = self::day_start($tsto - 1, $tz);
        $stopnext = $stopday->modify('+1 day');

        $first = $day0->getTimestamp() === $tsfrom ? $day0 : $day0->modify('+1 day');
        $tailboundary = $stopnext->getTimestamp() === $tsto ? $stopnext : $stopday;

        if ($first->getTimestamp() >= $tailboundary->getTimestamp()) {
            // No whole day inside the interval; nothing to shortcut.
            return null;
        }

        /* Day-aligned [start, end) stretches that must run through the full
         * walker. Head and tail partial days first. */
        $slowranges = [];
        if ($day0->getTimestamp() < $first->getTimestamp()) {
            $slowranges[] = [$day0, $first];
        }
        if ($tailboundary->getTimestamp() < $stopnext->getTimestamp()) {
            $slowranges[] = [$stopday, $stopnext];
        }

        /* Days near a timezone offset transition: their local midnights can
         * be mapped forward and their length differs from 86400 seconds, so
         * the per-day-of-week constants do not apply. getTransitions()'s
         * first entry describes the state at the query start, not a real
         * transition, so it is dropped before the scan. */
        $transitions = $tz->getTransitions($tsfrom - 2 * 86400, $tsto + 2 * 86400);
        if (is_array($transitions)) {
            array_shift($transitions);
            foreach ($transitions as $transition) {
                $transitionts = (int) $transition['ts'];

                /* A date-line move skips or repeats a whole calendar date
                 * (Pacific/Apia jumped from UTC-11 to UTC+13 at the end of
                 * 2011-12-29; 2011-12-30 never existed there). The walker's
                 * +1 day chain hops straight over a skipped date while
                 * calendar arithmetic — diff()->days below — counts it, and
                 * such a gap is midnight-aligned, so the wall-time probes
                 * further down cannot see it. Compare the local date on each
                 * side of the transition instant: anything other than "same
                 * date" or "next date" means the local calendar itself is
                 * discontinuous here, and only a full walk reproduces the
                 * walker's behaviour. */
                $datebefore = (new \DateTimeImmutable('@' . ($transitionts - 1)))->setTimezone($tz)->format('Ymd');
                $dateafter = (new \DateTimeImmutable('@' . $transitionts))->setTimezone($tz)->format('Ymd');
                $nextdate = \DateTimeImmutable::createFromFormat('!Ymd', $datebefore, new \DateTimeZone('UTC'))
                    ->modify('+1 day')
                    ->format('Ymd');
                if ($dateafter !== $datebefore && $dateafter !== $nextdate) {
                    return null;
                }

                $transitionday = self::day_start($transitionts, $tz);
                foreach ([$transitionday, $transitionday->modify('+1 day')] as $probe) {
                    if ($probe->format('H:i:s') !== '00:00:00') {
                        /* A DST gap swallowed a local midnight (transition at
                         * 00:00, e.g. America/Santiago). The walker's +1 day
                         * chain shifts permanently off midnight from that day
                         * onward, so only a full walk reproduces its
                         * boundaries. */
                        return null;
                    }
                }
                $slowranges[] = [$transitionday->modify('-1 day'), $transitionday->modify('+2 days')];
            }
        }

        // Calendar-exception days: any cday row makes the day non-implicit.
        $exceptiondates = $DB->get_fieldset_select(
            'block_feedback_tracker_cday',
            'daydate',
            'daydate >= :dfrom AND daydate <= :dto',
            ['dfrom' => (int) $day0->format('Ymd'), 'dto' => (int) $stopday->format('Ymd')]
        );
        foreach ($exceptiondates as $daydate) {
            $exceptionday = \DateTimeImmutable::createFromFormat('!Ymd', (string) $daydate, $tz);
            if ($exceptionday === false) {
                continue;
            }
            $slowranges[] = [$exceptionday, $exceptionday->modify('+1 day')];
        }

        // Days any manual pause window overlaps, regardless of pause mode.
        foreach (pause_lookup::for_course_group($courseid, $groupid, $tsfrom, $tsto) as $row) {
            $pstart = max((int) $row->timestart, $tsfrom);
            $pend = min($row->timeend !== null ? (int) $row->timeend : $tsto, $tsto);
            if ($pstart >= $pend) {
                continue;
            }
            $slowranges[] = [self::day_start($pstart, $tz), self::day_start($pend - 1, $tz)->modify('+1 day')];
        }

        usort($slowranges, static fn($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());
        $merged = [];
        foreach ($slowranges as $range) {
            $lastidx = count($merged) - 1;
            if ($lastidx >= 0 && $range[0]->getTimestamp() <= $merged[$lastidx][1]->getTimestamp()) {
                if ($range[1]->getTimestamp() > $merged[$lastidx][1]->getTimestamp()) {
                    $merged[$lastidx][1] = $range[1];
                }
            } else {
                $merged[] = $range;
            }
        }

        // Whole-day tally, minus the slow stretches, priced by day of week.
        $counts = self::dow_counts((int) $first->diff($tailboundary)->days, (int) $first->format('N') - 1);
        $seconds = 0;
        foreach ($merged as $range) {
            $overlapstart = $range[0]->getTimestamp() >= $first->getTimestamp() ? $range[0] : $first;
            $overlapend = $range[1]->getTimestamp() <= $tailboundary->getTimestamp() ? $range[1] : $tailboundary;
            if ($overlapstart->getTimestamp() < $overlapend->getTimestamp()) {
                $sub = self::dow_counts(
                    (int) $overlapstart->diff($overlapend)->days,
                    (int) $overlapstart->format('N') - 1
                );
                for ($dow = 0; $dow <= 6; $dow++) {
                    $counts[$dow] -= $sub[$dow];
                }
            }

            $slowfrom = max($range[0]->getTimestamp(), $tsfrom);
            $slowto = min($range[1]->getTimestamp(), $tsto);
            if ($slowfrom < $slowto) {
                $seconds += self::active_seconds_with_audit($courseid, $groupid, $slowfrom, $slowto)['seconds'];
            }
        }

        foreach ($counts as $dow => $ndays) {
            if ($ndays > 0) {
                $seconds += $ndays * self::implicit_day_seconds($dow);
            }
        }

        return $seconds;
    }

    /**
     * Active seconds one whole plain implicit day of a weekday contributes.
     *
     * A plain day has no calendar-exception row and no nearby DST
     * transition, so the walker's absolute business-hours windows collapse
     * to fixed lengths: exactly 60 seconds per configured minute, clipped
     * to the 24-hour day. Must agree with what a full walk produces for
     * such a day; the differential test in
     * tests/local/calendar/academic_time_fastpath_test.php holds the two
     * together.
     *
     * @param int $dayofweek 0=Mon..6=Sun (ISO 8601).
     * @return int Active seconds for one whole plain day.
     */
    private static function implicit_day_seconds(int $dayofweek): int {
        $isweekend = calendar::is_weekend($dayofweek);
        if (!calendar::is_active_day(calendar::DAYTYPE_IMPLICIT, $isweekend)) {
            return 0;
        }
        if (calendar::enablebusinesshours()) {
            $intervals = business_hours_lookup::for_dayofweek($dayofweek);
        } else {
            $intervals = [[0, 24 * 60]];
        }
        $seconds = 0;
        foreach ($intervals as $interval) {
            $startsec = max(0, min((int) $interval[0], 1440)) * 60;
            $endsec = max(0, min((int) $interval[1], 1440)) * 60;
            if ($endsec > $startsec) {
                $seconds += $endsec - $startsec;
            }
        }
        return $seconds;
    }

    /**
     * Occurrences of each weekday in a run of consecutive days.
     *
     * @param int $ndays Number of days in the run; may be 0.
     * @param int $firstdow Weekday of the first day (0=Mon..6=Sun).
     * @return array Count per weekday, indexed 0..6.
     */
    private static function dow_counts(int $ndays, int $firstdow): array {
        $counts = array_fill(0, 7, intdiv($ndays, 7));
        $remainder = $ndays % 7;
        for ($i = 0; $i < $remainder; $i++) {
            $counts[($firstdow + $i) % 7]++;
        }
        return $counts;
    }

    /**
     * Local midnight of the day containing a timestamp, as the walker sees it.
     *
     * When a DST gap swallows the local midnight this maps forward, exactly
     * like the walker's own setTime(0, 0, 0) does.
     *
     * @param int $ts Unix timestamp.
     * @param \DateTimeZone $tz Platform timezone.
     * @return \DateTimeImmutable Start-of-day instant.
     */
    private static function day_start(int $ts, \DateTimeZone $tz): \DateTimeImmutable {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->setTime(0, 0, 0);
    }

    /**
     * Drop all per-request memos used by the engine (test helper).
     *
     * @return void
     */
    public static function reset_memos(): void {
        business_hours_lookup::reset_memo();
        pause_lookup::reset_memo();
        day_rule_resolver::reset_memo();
    }

    /**
     * Convert business-hours (minutes-since-midnight) intervals to absolute
     * unix-timestamp intervals for a specific day in the platform timezone.
     *
     * Handles DST correctly via DateTime arithmetic.
     *
     * @param \DateTimeImmutable $daymidnight Midnight in platform tz.
     * @param array $intervals List of [startmin, endmin].
     * @return array Canonical list of unix intervals.
     */
    private static function business_hours_absolute(\DateTimeImmutable $daymidnight, array $intervals): array {
        $result = [];
        foreach ($intervals as $iv) {
            $startts = $daymidnight->modify('+' . $iv[0] . ' minutes')->getTimestamp();
            $endts = $daymidnight->modify('+' . $iv[1] . ' minutes')->getTimestamp();
            if ($endts > $startts) {
                $result[] = [$startts, $endts];
            }
        }
        return interval_math::union($result);
    }

    /**
     * Translate a day's resolved rule to the appropriate pause-reason slug.
     *
     * @param array $rule
     * @return string
     */
    private static function pause_reason_for_day(array $rule): string {
        switch ($rule['type']) {
            case calendar::DAYTYPE_HOLIDAY:
                return 'holiday';
            case calendar::DAYTYPE_RECESS:
                return 'recess';
            case calendar::DAYTYPE_CLOSED:
                return 'closed';
            case calendar::DAYTYPE_OPTIONAL:
                /* v1.0.9 — full-day optional gets its own pause reason so
                 * PausedNote can render the event-style note instead of
                 * collapsing into a generic 'closed' chip. Sub-day optional
                 * never reaches this branch (is_active=true above). */
                return 'optional';
            default:
                return $rule['is_weekend'] ? 'weekend' : 'outofhours';
        }
    }

    /**
     * Translate a manual-pause scopelevel to its pause-reason slug.
     *
     * @param string $scopelevel
     * @return string
     */
    private static function pause_reason_for_scope(string $scopelevel): string {
        switch ($scopelevel) {
            case 'course':
                return 'coursepaused';
            case 'group':
                return 'grouppaused';
            case 'site':
            default:
                return 'sitepaused';
        }
    }
}
