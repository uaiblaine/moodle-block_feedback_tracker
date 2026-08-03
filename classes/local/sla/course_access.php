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
 * Course-level processing gate.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Decides whether the plugin should be tracking a given course at all.
 *
 * Two independent gates, both must pass:
 *   1. A `block_feedback_tracker` instance exists at the course's own
 *      context. This is the explicit opt-in — admins drop the block on
 *      every course they want tracked, and nothing else is touched. The
 *      site can carry hundreds of courses where the plugin is installed
 *      but only a handful actively measured.
 *   2. The course is visible, OR the `process_hidden_courses` admin
 *      setting is on. Default is off so hidden / archived courses don't
 *      keep accruing ledger rows and rollups they'll never display.
 *
 * Used by the observer (write-path entry), the backfill task, and
 * potentially other batch jobs. Deliberately NOT consulted by
 * rollup_service::recompute_group() — that path is downstream of the
 * gate, and adding the check there would force a wide test-fixture
 * rewrite without closing any new leak.
 *
 * Per-request memo because both the observer hot path and the backfill
 * inner loop call this many times for the same courseids.
 */
class course_access {
    /** @var array<int, bool> Per-request memo keyed by courseid. */
    private static array $memo = [];

    /** @var int[]|null Per-request memo for the full processable-courseids enumeration. */
    private static ?array $allmemo = null;

    /**
     * True when the plugin should ingest events / backfill for this course.
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_processable(int $courseid): bool {
        if ($courseid <= 0) {
            return false;
        }
        if (array_key_exists($courseid, self::$memo)) {
            return self::$memo[$courseid];
        }

        global $DB;
        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id, visible',
            IGNORE_MISSING
        );
        if (!$course) {
            return self::$memo[$courseid] = false;
        }

        $includehidden = (int) (get_config('block_feedback_tracker', 'process_hidden_courses') ?: 0) === 1;
        if (!$includehidden && (int) $course->visible !== 1) {
            return self::$memo[$courseid] = false;
        }

        if (!self::block_present_for_course($courseid)) {
            return self::$memo[$courseid] = false;
        }

        return self::$memo[$courseid] = true;
    }

    /**
     * True when a `block_feedback_tracker` instance is attached directly
     * to the course's own context.
     *
     * Category- and system-context blocks are intentionally excluded:
     * dropping the block at category level renders it on the *category*
     * page, not on courses, and a system-level block usually lives on
     * the admin dashboard rather than indicating site-wide tracking
     * intent. Admins who want all courses tracked add the block to each
     * one (or script that via a small one-off task).
     *
     * Public because the delayed-removal task needs the block question ALONE.
     * is_processable() also requires the course to be visible, so using it as a
     * deletion guard would make hiding a course — which is what archiving one
     * looks like — destroy its measured history.
     *
     * @param int $courseid
     * @return bool
     */
    public static function block_present_for_course(int $courseid): bool {
        global $DB;
        try {
            $coursectx = \context_course::instance($courseid, IGNORE_MISSING);
        } catch (\Throwable $e) {
            return false;
        }
        if (!$coursectx) {
            return false;
        }
        return $DB->record_exists(
            'block_instances',
            [
                'blockname' => 'feedback_tracker',
                'parentcontextid' => $coursectx->id,
            ]
        );
    }

    /**
     * Return every courseid that currently passes is_processable() in one
     * cheap query — for batch jobs that want to SQL-filter their scan with
     * `WHERE courseid IN (...)` instead of doing per-row PHP checks. On
     * sites where the block is on a handful of courses but the corpus has
     * millions of submissions, the SQL pre-filter collapses backfill scan
     * cost from O(all submissions) to O(submissions in tracked courses).
     *
     * Per-request memoised. Also pre-populates the per-courseid memo for
     * the returned ids so subsequent `is_processable()` calls on them are
     * served from cache.
     *
     * @return int[] Sorted ascending. Empty when no course currently
     *               passes the gate.
     */
    public static function processable_course_ids(): array {
        if (self::$allmemo !== null) {
            return self::$allmemo;
        }
        global $DB;

        $includehidden = (int) (get_config('block_feedback_tracker', 'process_hidden_courses') ?: 0) === 1;
        $where = 'bi.blockname = :blockname AND ctx.contextlevel = :level';
        $params = ['blockname' => 'feedback_tracker', 'level' => CONTEXT_COURSE];
        if (!$includehidden) {
            $where .= ' AND c.visible = 1';
        }
        $sql = "SELECT DISTINCT ctx.instanceid AS courseid
                  FROM {block_instances} bi
                  JOIN {context} ctx ON ctx.id = bi.parentcontextid
                  JOIN {course} c ON c.id = ctx.instanceid
                 WHERE $where
              ORDER BY ctx.instanceid ASC";
        $rows = $DB->get_records_sql($sql, $params);
        $ids = array_map(static fn($r) => (int) $r->courseid, array_values($rows));

        self::$allmemo = $ids;
        // Pre-populate the per-courseid memo for everything we just
        // enumerated — any follow-up is_processable() call on these
        // courseids is now served from memo with no extra query.
        foreach ($ids as $cid) {
            self::$memo[$cid] = true;
        }
        return $ids;
    }

    /**
     * Drop the per-request memo. Test helper.
     *
     * @return void
     */
    public static function reset_memo(): void {
        self::$memo = [];
        self::$allmemo = null;
    }
}
