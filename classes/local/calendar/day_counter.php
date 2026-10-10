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
 * Date-based elapsed-day counter for the "business days" display unit.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Counts elapsed calendar and business days between two instants by counting
 * day boundaries, not hours — so the result is independent of the time of day.
 * A business day is an "active" calendar day (weekends, holidays and recesses
 * excluded per the platform calendar); reuses the cached per-day classification
 * from {@see day_rule_resolver}. Used by the "business days" display unit
 * (display_time_unit = business_days); the responsiveness score still uses
 * effective hours and is unaffected.
 */
class day_counter {
    /**
     * Count elapsed calendar days and business (active) days between two
     * instants, in the platform timezone, over the range (date($from), date($to)]:
     * the submit day is excluded and the grade day included, so two instants
     * on the same calendar day count zero.
     *
     * @param int $from Unix seconds (submission instant).
     * @param int $to   Unix seconds (grading instant, or "now" for pending work).
     * @return array{business:int, calendar:int}
     */
    public static function between(int $from, int $to): array {
        $tz = calendar::timezone();
        $startday = (new \DateTimeImmutable('@' . $from))->setTimezone($tz)->setTime(0, 0, 0);
        $endday = (new \DateTimeImmutable('@' . $to))->setTimezone($tz)->setTime(0, 0, 0);

        $calendar = 0;
        $business = 0;
        // Same day walk and dayofweek convention (0 is Monday) as academic_time.
        $day = $startday->modify('+1 day');
        while ($day <= $endday) {
            $calendar++;
            $dayofweek = (int) $day->format('N') - 1;
            $daydate = (int) $day->format('Ymd');
            if (day_rule_resolver::for_date($daydate, $dayofweek)['is_active']) {
                $business++;
            }
            $day = $day->modify('+1 day');
        }

        return ['business' => $business, 'calendar' => $calendar];
    }

    /**
     * Elapsed business (active) days only.
     *
     * @param int $from Unix seconds.
     * @param int $to   Unix seconds.
     * @return int
     */
    public static function business_days(int $from, int $to): int {
        return self::between($from, $to)['business'];
    }

    /**
     * Elapsed calendar days only.
     *
     * @param int $from Unix seconds.
     * @param int $to   Unix seconds.
     * @return int
     */
    public static function calendar_days(int $from, int $to): int {
        return self::between($from, $to)['calendar'];
    }
}
