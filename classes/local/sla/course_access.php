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
 *      context. This is the explicit opt-in: nothing is tracked on a course
 *      without the block.
 *   2. The course is visible, or the `process_hidden_courses` admin
 *      setting is on. Default is off so hidden / archived courses don't
 *      keep accruing ledger rows and rollups they'll never display.
 *
 * Every write-path entry (the event observers, the backfill and allocation
 * tasks) calls this. Cleanup paths do not, so tracked data is still removed when its
 * course or user goes away. rollup_service::recompute_group() does not
 * either: it runs downstream of the gate, so checking there closes no leak.
 *
 * The results are memoised in static properties because the observer hot
 * path and the backfill loop ask about the same courses many times. The
 * memo lives as long as the PHP process: one web request, or a cron process
 * that runs many tasks, which is why every task starts with
 * {@see process_memos::reset()}. Call {@see self::reset_memo()} after changing
 * a course's block in the same process.
 */
class course_access {
    /** @var array<int, bool> Per-process memo keyed by courseid. */
    private static array $memo = [];

    /** @var int[]|null Per-process memo for the full processable-courseids enumeration. */
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
     * Category- and system-context blocks are intentionally excluded: a
     * category-level block renders on the category page, not on courses, and
     * a system-level one usually sits on the dashboard rather than signalling
     * site-wide tracking intent.
     *
     * Public because the delayed-removal task
     * {@see \block_feedback_tracker\task\discard_course_data} needs the block
     * question alone: is_processable() also requires the course to be visible,
     * so as a deletion guard it would let hiding (archiving) a course destroy
     * its measured history.
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
     * Return every courseid that currently passes is_processable(), in one
     * query, for batch jobs that filter their scan with
     * `WHERE courseid IN (...)` instead of checking row by row: the scan then
     * costs O(submissions in tracked courses) rather than O(all submissions).
     *
     * Memoised, and also fills the per-courseid memo for the returned ids.
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
        // Every enumerated course passes the gate, so seed the per-courseid memo.
        foreach ($ids as $cid) {
            self::$memo[$cid] = true;
        }
        return $ids;
    }

    /**
     * Drop both memos, after a block is added or removed within the same
     * process (tests, task\bulk_remove_blocks).
     *
     * @return void
     */
    public static function reset_memo(): void {
        self::$memo = [];
        self::$allmemo = null;
    }
}
