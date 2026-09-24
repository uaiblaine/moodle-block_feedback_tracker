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
 * Weekly business-hours lookup.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Returns the business-hours intervals (minutes since midnight) for a given
 * ISO dayofweek, drawn from {block_feedback_tracker_chours}.
 *
 * Per-request memoised, keyed by calver to invalidate on calendar saves.
 */
class business_hours_lookup {
    /**
     * Per-request memo. calver → dayofweek → list of [startmin, endmin].
     *
     * @var array<int, array<int, array<int, array{0:int,1:int}>>>
     */
    private static array $memo = [];

    /**
     * Business-hour intervals for the given dayofweek.
     *
     * @param int $dayofweek 0=Mon..6=Sun (ISO 8601).
     * @return array<int, array{0:int,1:int}> Canonical list of [startmin, endmin].
     */
    public static function for_dayofweek(int $dayofweek): array {
        $calver = calendar::current_version();
        if (isset(self::$memo[$calver][$dayofweek])) {
            return self::$memo[$calver][$dayofweek];
        }

        global $DB;
        $rows = $DB->get_records(
            'block_feedback_tracker_chours',
            ['dayofweek' => $dayofweek, 'enabled' => 1],
            'starttime ASC',
            'id, starttime, endtime'
        );
        $intervals = [];
        foreach ($rows as $r) {
            $intervals[] = [(int) $r->starttime, (int) $r->endtime];
        }
        $intervals = interval_math::union($intervals);

        self::$memo[$calver][$dayofweek] = $intervals;
        return $intervals;
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
