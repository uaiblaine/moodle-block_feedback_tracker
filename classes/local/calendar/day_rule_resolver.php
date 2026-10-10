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
 * Per-day rule resolver.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Resolves the merged calendar rule for a single date:
 *  - explicit daytype from {block_feedback_tracker_cday} (if any),
 *  - weekend flag from the configured weekend mask,
 *  - business-hour intervals for the dayofweek,
 *  - final "is_active" boolean (whether effective time accumulates).
 *
 * Two-level cache: per-request static memo + MUC application cache
 * (`calendar_effective_day`) keyed by (calver, ymd).
 */
class day_rule_resolver {
    /**
     * @var array Per-request memo: calver → ymd → rule array.
     */
    private static array $memo = [];

    /**
     * Resolve the rule for one date.
     *
     * An optional row with both starttime and endtime set is a sub-day event:
     * the day stays active per the weekly schedule and the window is returned
     * as `optional_window`, which {@see academic_time} subtracts from the
     * active intervals. An optional row without a window makes the whole day
     * inactive.
     *
     * @param int $daydate YYYYMMDD as int.
     * @param int $dayofweek 0=Mon..6=Sun (ISO 8601).
     * @return array{type:string, dayofweek:int, is_weekend:bool, business_hours:array,
     *               is_active:bool, day_note:?string,
     *               optional_window:?array{startmin:int,endmin:int,note:?string}}
     */
    public static function for_date(int $daydate, int $dayofweek): array {
        $calver = calendar::current_version();
        if (isset(self::$memo[$calver][$daydate])) {
            return self::$memo[$calver][$daydate];
        }
        $cached = effective_day_cache::get($daydate);
        if ($cached !== null) {
            self::$memo[$calver][$daydate] = $cached;
            return $cached;
        }

        global $DB;
        $cdayrow = $DB->get_record(
            'block_feedback_tracker_cday',
            ['daydate' => $daydate],
            'daytype, starttime, endtime, note'
        );
        $type = $cdayrow ? (string) $cdayrow->daytype : calendar::DAYTYPE_IMPLICIT;
        $note = $cdayrow ? ($cdayrow->note !== null ? (string) $cdayrow->note : null) : null;
        $starttime = ($cdayrow && $cdayrow->starttime !== null) ? (int) $cdayrow->starttime : null;
        $endtime = ($cdayrow && $cdayrow->endtime !== null) ? (int) $cdayrow->endtime : null;

        $isweekend = calendar::is_weekend($dayofweek);
        if (calendar::enablebusinesshours()) {
            $businesshours = business_hours_lookup::for_dayofweek($dayofweek);
        } else {
            $businesshours = [[0, 24 * 60]];
        }

        $optionalwindow = null;
        $isactive = calendar::is_active_day($type, $isweekend);
        if (
            $type === calendar::DAYTYPE_OPTIONAL
            && $starttime !== null && $endtime !== null
            && $endtime > $starttime
        ) {
            // Sub-day event: the day is active exactly as an implicit day
            // would be, weekend exclusion included; academic_time's day walk
            // subtracts the event window.
            $optionalwindow = [
                'startmin' => $starttime,
                'endmin'   => $endtime,
                'note'     => $note,
            ];
            $isactive = !($isweekend && calendar::excludeweekends());
        }

        $rule = [
            'type' => $type,
            'dayofweek' => $dayofweek,
            'is_weekend' => $isweekend,
            'business_hours' => $businesshours,
            'is_active' => $isactive,
            'day_note' => $note,
            'optional_window' => $optionalwindow,
        ];

        effective_day_cache::set($daydate, $rule);
        self::$memo[$calver][$daydate] = $rule;
        return $rule;
    }

    /**
     * Drop the per-request memo. {@see academic_time::reset_memos()}
     *
     * @return void
     */
    public static function reset_memo(): void {
        self::$memo = [];
    }
}
