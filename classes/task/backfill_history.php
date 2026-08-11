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
 * Scheduled task: dispatch historical-backfill work to adhoc workers.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\backfill_cursor;
use block_feedback_tracker\local\sla\course_access;

/**
 * Inactive by default. When admin enables `backfill_active = 1`, iterates
 * every block-enabled course that has an active per-course cursor row in
 * {block_feedback_tracker_bfcursor} and dispatches its next chunk of
 * historical {assign_submission} rows as `backfill_one_submission`
 * adhoc tasks (one per sub-chunk of `backfill_sub_chunk`).
 *
 * Per-course cursors (v1.7.0+) mean:
 *  - Adding the block to a new course later → fresh cursor=0 row → next
 *    tick walks that course from the start, independent of every other
 *    course's progress.
 *  - Re-running backfill on one course doesn't re-walk the others.
 *  - When a course's SQL returns 0 rows the cursor row flips to active=0;
 *    admins can reset to active=1 via the CLI tool or the reset page to
 *    retry.
 *
 * Per-tick total dispatch is capped at `backfill_chunk` (master quota),
 * per-course slice at `backfill_chunk_per_course`. Soft time cap applies
 * to the whole tick.
 */
class backfill_history extends \core\task\scheduled_task {
    /** Default per-tick total dispatch cap (rows across all courses). */
    public const DEFAULT_CHUNK = 5000;
    /** Default per-course slice within a single tick. */
    public const DEFAULT_CHUNK_PER_COURSE = 1000;
    /** Default per-adhoc-task sub-chunk size. */
    public const DEFAULT_SUB_CHUNK = 50;
    /** Default soft time cap (seconds). Shared with drain_queue / pending. */
    public const DEFAULT_TIME_CAP = 50;

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_backfill_history', 'block_feedback_tracker');
    }

    /**
     * Dispatch one chunk's worth of adhoc backfill tasks.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;
        $active = (int) (get_config('block_feedback_tracker', 'backfill_active') ?: 0);
        if ($active !== 1) {
            return;
        }
        $totalcap = (int) (get_config('block_feedback_tracker', 'backfill_chunk') ?: self::DEFAULT_CHUNK);
        $percoursecap = (int) (get_config('block_feedback_tracker', 'backfill_chunk_per_course') ?: self::DEFAULT_CHUNK_PER_COURSE);
        if ($percoursecap < 1) {
            $percoursecap = self::DEFAULT_CHUNK_PER_COURSE;
        }
        $subchunk = (int) (get_config('block_feedback_tracker', 'backfill_sub_chunk') ?: self::DEFAULT_SUB_CHUNK);
        if ($subchunk < 1) {
            $subchunk = self::DEFAULT_SUB_CHUNK;
        }
        $timecap = (int) (get_config('block_feedback_tracker', 'drain_time_cap_seconds') ?: self::DEFAULT_TIME_CAP);
        $deadline = time() + $timecap;

        // Lazily create cursor rows for any block-enabled course that
        // doesn't have one yet. This makes "admin adds the block to a new
        // course" Just Work — the next tick picks it up with cursor=0.
        $processable = course_access::processable_course_ids();
        if (empty($processable)) {
            mtrace('backfill_history: no processable courses (no course-context block on a visible course) — auto-disabling.');
            set_config('backfill_active', '0', 'block_feedback_tracker');
            return;
        }
        backfill_cursor::ensure_for_courses($processable);

        $activerows = backfill_cursor::active_rows();
        // Filter to currently-processable courses only — a course whose
        // block was removed after the cursor row was created stays in
        // the table (preserves historical state) but is skipped here.
        $processableset = array_flip($processable);
        $activerows = array_values(array_filter(
            $activerows,
            static fn($r) => isset($processableset[(int) $r->courseid])
        ));
        if (empty($activerows)) {
            mtrace('backfill_history: all processable courses are at active=0 (complete) — nothing to dispatch.');
            return;
        }

        // Round-robin start point: rotate the active-rows list so we
        // resume from the course after the last one we processed. Without
        // this, lower-courseid courses could starve higher-courseid ones
        // when more than (backfill_chunk / backfill_chunk_per_course)
        // courses are actively backfilling — the per-tick total cap would
        // hit before we reach the tail of the list every tick.
        $lastvisited = (int) (get_config('block_feedback_tracker', 'bfdispatch_last_courseid') ?: 0);
        $startidx = 0;
        foreach ($activerows as $idx => $row) {
            if ((int) $row->courseid > $lastvisited) {
                $startidx = $idx;
                break;
            }
        }
        // If no courseid > lastvisited (first run, or we already wrapped
        // past the end last tick), startidx stays 0 — natural wrap.
        if ($startidx > 0) {
            $activerows = array_merge(
                array_slice($activerows, $startidx),
                array_slice($activerows, 0, $startidx)
            );
        }
        mtrace(sprintf(
            'backfill_history: %d active course cursor(s); total_cap=%d; '
            . 'per_course_cap=%d; sub_chunk=%d; rotating from courseid > %d.',
            count($activerows),
            $totalcap,
            $percoursecap,
            $subchunk,
            $lastvisited
        ));

        $dispatchedtotal = 0;
        $coursesvisited = 0;
        $coursescompleted = 0;
        $lastprocessed = $lastvisited;
        foreach ($activerows as $row) {
            if (time() > $deadline) {
                mtrace('backfill_history: soft time cap reached — remaining courses picked up next tick.');
                break;
            }
            if ($dispatchedtotal >= $totalcap) {
                mtrace('backfill_history: tick-total cap reached — remaining courses picked up next tick.');
                break;
            }
            $coursesvisited++;
            $courseid = (int) $row->courseid;
            $coursecursor = (int) $row->lastsubid;
            $remainingquota = min($percoursecap, $totalcap - $dispatchedtotal);
            [$dispatched, $newcursor, $complete] = $this->dispatch_course_chunk(
                $courseid,
                $coursecursor,
                $remainingquota,
                $subchunk
            );
            $dispatchedtotal += $dispatched;
            $lastprocessed = $courseid;
            backfill_cursor::advance($courseid, $newcursor, $complete);
            if ($complete) {
                $coursescompleted++;
                mtrace(sprintf(
                    '  course %d: dispatched %d row(s); cursor->%d; marked COMPLETE.',
                    $courseid,
                    $dispatched,
                    $newcursor
                ));
            } else {
                mtrace(sprintf(
                    '  course %d: dispatched %d row(s); cursor->%d.',
                    $courseid,
                    $dispatched,
                    $newcursor
                ));
            }
        }
        mtrace(sprintf(
            'backfill_history: dispatched %d row(s) across %d course(s); %d marked complete this tick.',
            $dispatchedtotal,
            $coursesvisited,
            $coursescompleted
        ));
        // Persist the round-robin marker so next tick resumes from the
        // course after this one we last touched.
        set_config('bfdispatch_last_courseid', (string) $lastprocessed, 'block_feedback_tracker');
    }

    /**
     * Pull the next slice of submissions for one course past its cursor
     * and dispatch as backfill_one_submission adhoc tasks. Returns a
     * tuple of (rows dispatched, new cursor, complete-flag).
     *
     * The "complete" flag is set when the SELECT returned strictly fewer
     * rows than the per-course quota — meaning we've drained every row
     * past this cursor for this course. The cursor row's active flag
     * gets flipped to 0 by the caller.
     *
     * @param int $courseid
     * @param int $cursor    Current cursor value (rows with id > cursor are eligible).
     * @param int $quota     Maximum rows to fetch this tick.
     * @param int $subchunk  Adhoc-task batch size.
     * @return array{0:int, 1:int, 2:bool}
     */
    private function dispatch_course_chunk(int $courseid, int $cursor, int $quota, int $subchunk): array {
        global $DB;
        if ($quota < 1) {
            return [0, $cursor, false];
        }
        $rows = $DB->get_records_sql(
            "SELECT s.id AS subid, s.userid, s.groupid, s.attemptnumber, cm.id AS cmid
               FROM {assign_submission} s
               JOIN {assign} a ON a.id = s.assignment
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE s.id > :cursor AND cm.course = :cid
           ORDER BY s.id ASC",
            ['modname' => 'assign', 'cursor' => $cursor, 'cid' => $courseid],
            0,
            $quota
        );
        if (empty($rows)) {
            return [0, $cursor, true];
        }

        $dispatched = 0;
        $newcursor = $cursor;
        $buffer = [];
        foreach ($rows as $r) {
            $newcursor = (int) $r->subid;
            $buffer[] = [
                'cmid'          => (int) $r->cmid,
                'userid'        => (int) $r->userid,
                'groupid'       => (int) $r->groupid,
                'attemptnumber' => (int) $r->attemptnumber,
                'courseid'      => $courseid,
            ];
            if (count($buffer) >= $subchunk) {
                self::enqueue_batch($buffer);
                $dispatched += count($buffer);
                $buffer = [];
            }
        }
        if (!empty($buffer)) {
            self::enqueue_batch($buffer);
            $dispatched += count($buffer);
        }
        // If we got fewer rows than the quota, that's the end of this
        // course's pending data — mark complete. (Equality with quota is
        // ambiguous: there could be more right after; let next tick decide.)
        $complete = count($rows) < $quota;
        return [$dispatched, $newcursor, $complete];
    }

    /**
     * Queue one adhoc backfill task for the given resolved rows. A single
     * bad batch is logged via `debugging()` and skipped rather than killing
     * the rest of the dispatcher tick — matches the defensive shape used by
     * `drain_queue`.
     *
     * @param array $rows
     * @return void
     */
    private static function enqueue_batch(array $rows): void {
        try {
            $task = new backfill_one_submission();
            $task->set_custom_data(['rows' => $rows]);
            \core\task\manager::queue_adhoc_task($task, true);
        } catch (\Throwable $e) {
            debugging(sprintf(
                'backfill_history dispatch failed for batch of %d rows: %s',
                count($rows),
                $e->getMessage()
            ));
        }
    }
}
