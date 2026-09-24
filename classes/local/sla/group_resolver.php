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
 * Group attribution helper.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Resolves which group a user "belongs to" within a course for SLA attribution.
 *
 * Strategy: the group the user joined most recently in that course
 * ({groups_members}.timeadded), ties going to the higher group id. Returns 0
 * if the user is not in any group.
 *
 * Memoised because the same (course, user) pair is read several times per
 * upsert pass. The memo lives as long as the PHP process, so callers reset it
 * after membership changes and between long-running batches.
 */
class group_resolver {
    /**
     * @var array Per-process memo: "courseid:userid" → groupid.
     */
    private static array $memo = [];

    /**
     * Resolve a group id for the user in the course.
     *
     * @param int $courseid
     * @param int $userid
     * @return int Group id, or 0 if user has no group in this course.
     */
    public static function resolve_group_for_user(int $courseid, int $userid): int {
        $key = $courseid . ':' . $userid;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        global $DB;
        $sql = "SELECT g.id, gm.timeadded
                  FROM {groups} g
                  JOIN {groups_members} gm ON gm.groupid = g.id
                 WHERE g.courseid = :courseid
                   AND gm.userid = :userid
              ORDER BY gm.timeadded DESC, g.id DESC";
        $row = $DB->get_record_sql($sql, [
            'courseid' => $courseid,
            'userid' => $userid,
        ], IGNORE_MULTIPLE);

        $result = $row ? (int) $row->id : 0;
        self::$memo[$key] = $result;
        return $result;
    }

    /**
     * Drop the memo (tests, group membership changes, reconcile batches).
     *
     * @return void
     */
    public static function reset_memo(): void {
        self::$memo = [];
    }
}
