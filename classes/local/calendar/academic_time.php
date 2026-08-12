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
     * Convenience wrapper for {@see self::elapsed_with_audit()} that drops
     * the pause-record audit trail.
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
        return self::elapsed_with_audit($courseid, $groupid, $tsfrom, $tsto)['hours'];
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
