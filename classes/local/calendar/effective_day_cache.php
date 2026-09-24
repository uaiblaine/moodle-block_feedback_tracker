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
 * Wrapper for the calendar_effective_day MUC cache.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Stores the resolved per-day calendar rule keyed by (calver, ymd).
 *
 * The key embeds calver, so {@see calendar::bump_version()} makes every earlier
 * entry unreachable. The definition has no TTL, so the callers that bump the
 * version at run time also purge it ({@see observer},
 * block_feedback_tracker_invalidate_rollups() and
 * block_feedback_tracker_reset_data()); otherwise each calendar edit would
 * leave a full copy of the cached days in the store.
 */
class effective_day_cache {
    /**
     * Read a cached day rule, or null if absent.
     *
     * @param int $daydate YYYYMMDD as int.
     * @return array|null
     */
    public static function get(int $daydate): ?array {
        $cache = \cache::make('block_feedback_tracker', 'calendar_effective_day');
        $val = $cache->get(self::key($daydate));
        return $val === false ? null : $val;
    }

    /**
     * Store a day rule.
     *
     * @param int $daydate YYYYMMDD as int.
     * @param array $data
     * @return void
     */
    public static function set(int $daydate, array $data): void {
        $cache = \cache::make('block_feedback_tracker', 'calendar_effective_day');
        $cache->set(self::key($daydate), $data);
    }

    /**
     * Build the cache key.
     *
     * @param int $daydate
     * @return string "{calver}_{YYYYMMDD}", e.g. "7_20260529".
     */
    private static function key(int $daydate): string {
        return calendar::current_version() . '_' . $daydate;
    }
}
