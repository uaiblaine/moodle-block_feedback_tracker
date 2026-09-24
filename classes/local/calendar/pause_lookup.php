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
 * Manual pause-window lookup.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Loads manual pause windows from {block_feedback_tracker_cpause}, scoped to
 * the (courseid, groupid) tuple the engine is computing for.
 *
 * Three scope levels in the table: site, course, group. Only rows that
 * could plausibly affect the given courseid are loaded; the per-call
 * for_course_group() narrows further by groupid + time overlap.
 *
 * Cached per (calver, courseid) in MUC plus per-request static memo.
 */
class pause_lookup {
    /**
     * Per-request memo. Key: "{calver}_{courseid}" → array of pause-row stdClass.
     *
     * @var array<string, array<int, \stdClass>>
     */
    private static array $memo = [];

    /**
     * Pause windows relevant to (courseid, groupid) overlapping [$tsfrom, $tsto).
     *
     * Includes:
     *  - site-level pauses (apply everywhere),
     *  - course-level pauses where scopeid = $courseid,
     *  - group-level pauses where scopeid = $groupid AND the group belongs to
     *    $courseid (groups in other courses are filtered by the SQL).
     *
     * Returned objects have: id, scopelevel, scopeid, timestart, timeend, reason, note.
     *
     * @param int $courseid
     * @param int $groupid
     * @param int $tsfrom Inclusive unix timestamp.
     * @param int $tsto Exclusive unix timestamp.
     * @return array<int, \stdClass>
     */
    public static function for_course_group(int $courseid, int $groupid, int $tsfrom, int $tsto): array {
        $all = self::all_for_course($courseid);
        $result = [];
        foreach ($all as $row) {
            if ($row->scopelevel === 'group' && (int) $row->scopeid !== $groupid) {
                continue;
            }
            $pstart = (int) $row->timestart;
            $pend = $row->timeend !== null ? (int) $row->timeend : PHP_INT_MAX;
            if ($pend <= $tsfrom || $pstart >= $tsto) {
                continue;
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * All site + course-specific + course-bound group pauses, regardless of
     * time. Cached.
     *
     * @param int $courseid
     * @return array<int, \stdClass>
     */
    private static function all_for_course(int $courseid): array {
        $calver = calendar::current_version();
        $key = $calver . '_' . $courseid;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $cache = \cache::make('block_feedback_tracker', 'pause_windows_by_course');
        $cached = $cache->get($key);
        if ($cached !== false) {
            self::$memo[$key] = $cached;
            return $cached;
        }

        global $DB;
        $sql = "SELECT cp.id, cp.scopelevel, cp.scopeid, cp.timestart, cp.timeend, cp.reason, cp.note
                  FROM {block_feedback_tracker_cpause} cp
             LEFT JOIN {groups} g ON g.id = cp.scopeid AND cp.scopelevel = 'group'
                 WHERE cp.scopelevel = 'site'
                    OR (cp.scopelevel = 'course' AND cp.scopeid = :courseid1)
                    OR (cp.scopelevel = 'group'  AND g.courseid = :courseid2)";
        $rows = array_values($DB->get_records_sql($sql, [
            'courseid1' => $courseid,
            'courseid2' => $courseid,
        ]));

        $cache->set($key, $rows);
        self::$memo[$key] = $rows;
        return $rows;
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
