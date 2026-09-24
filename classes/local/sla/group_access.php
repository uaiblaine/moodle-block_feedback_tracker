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
 * Group-mode access helper.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Resolves which group rows a given user is allowed to see in a course,
 * honouring the course's group mode and the accessallgroups capability.
 *
 * The single decision point for every surface that lists group rows (the
 * block payload, the dashboard via {@see dashboard_scope::sql_visibility()},
 * the reports and their web services), so no surface can show a group
 * another one hides.
 *
 * The course's group mode decides, never an activity's. A group row
 * aggregates every activity of the course, and activities can set different
 * modes, so there is no single activity mode to apply to it; the course mode
 * is the one setting that covers the whole row. Consequently, without
 * `groupmodeforce`, an activity in separate groups in a course that uses no
 * groups shows every group's row.
 *
 * Returned shapes:
 *   - `null`    → unrestricted. NOGROUPS course, or the user holds
 *                 `moodle/site:accessallgroups`. Callers must NOT add any
 *                 group filter to their SQL.
 *   - `int[]`   → the user can see exactly these group IDs (named groups
 *                 only — `groupid = 0` "ungrouped" never appears outside
 *                 NOGROUPS or accessallgroups). An empty array means the
 *                 user has zero group access in this course — callers
 *                 must short-circuit to an empty result.
 */
class group_access {
    /** @var array Per-process memo keyed by "courseid:userid". */
    private static array $memo = [];

    /**
     * Compute the visible group IDs for one (courseid, userid) pair.
     *
     * @param int $courseid
     * @param int $userid
     * @return int[]|null
     */
    public static function visible_group_ids(int $courseid, int $userid): ?array {
        $key = $courseid . ':' . $userid;
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $ctx = \context_course::instance($courseid);
        $groupmode = (int) groups_get_course_groupmode($course);
        // Resolved through dashboard_scope so a full-site grant also lifts the
        // group restriction, on top of moodle/site:accessallgroups.
        $canaccessall = dashboard_scope::can_access_all_groups($ctx, $userid);

        if ($groupmode === NOGROUPS || $canaccessall) {
            return self::$memo[$key] = null;
        }

        if ($groupmode === VISIBLEGROUPS) {
            $allnamed = groups_get_all_groups($courseid);
            return self::$memo[$key] = array_map(static fn($g) => (int) $g->id, $allnamed);
        }

        // SEPARATEGROUPS — only the user's own groups.
        $usergroupings = groups_get_user_groups($courseid, $userid);
        $own = [];
        foreach ($usergroupings as $gids) {
            foreach ($gids as $gid) {
                $own[(int) $gid] = true;
            }
        }
        return self::$memo[$key] = array_keys($own);
    }

    /**
     * True when the user can see everything in the course (NOGROUPS or
     * accessallgroups). Sugar for the common branch.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    public static function is_unrestricted(int $courseid, int $userid): bool {
        return self::visible_group_ids($courseid, $userid) === null;
    }

    /**
     * True when the user can see the given group in the course.
     * `groupid = 0` ("ungrouped") is visible to unrestricted users only.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $groupid
     * @return bool
     */
    public static function can_see_group(int $courseid, int $userid, int $groupid): bool {
        $visible = self::visible_group_ids($courseid, $userid);
        if ($visible === null) {
            return true;
        }
        return in_array($groupid, $visible, true);
    }

    /**
     * Drop the memo. Test helper.
     *
     * @return void
     */
    public static function reset_memo(): void {
        self::$memo = [];
    }
}
