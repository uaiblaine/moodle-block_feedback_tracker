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
 * Per-course backfill cursor helper.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Wraps the {block_feedback_tracker_bfcursor} table: one row per tracked
 * course, holding the last {assign_submission}.id the backfill walked
 * (`lastsubid`).
 *
 * Per-course rows let a course that gains the block later be walked from the
 * start without touching other courses' progress, and let one course be
 * re-walked ({@see self::reset()}) or paused ({@see self::disable()}) alone.
 *
 * During normal operation only `task\backfill_history` writes the rows, and
 * the scheduled-task lock keeps one dispatcher running at a time, so no
 * per-row lock is needed. cli/backfill_course.php is the manual exception.
 */
class backfill_cursor {
    /**
     * Fetch the cursor row for one course, lazily creating it (with
     * lastsubid 0, active 1) if none exists. Idempotent.
     *
     * @param int $courseid
     * @return \stdClass The row.
     */
    public static function get_or_create(int $courseid): \stdClass {
        global $DB;
        $row = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
        if ($row) {
            return $row;
        }
        $now = time();
        $row = (object) [
            'courseid'    => $courseid,
            'lastsubid'   => 0,
            'active'      => 1,
            'lastrunat'   => null,
            'timecreated' => $now,
        ];
        $row->id = $DB->insert_record('block_feedback_tracker_bfcursor', $row);
        return $row;
    }

    /**
     * Make sure every listed course has a cursor row, in two queries.
     *
     * The dispatcher calls this every tick for every tracked course, before
     * its early return when all backfill is complete, so a loop over
     * {@see self::get_or_create()} would cost one SELECT per course per tick
     * even on a site whose backfill finished long ago.
     *
     * Reads the whole cursor table rather than filtering on the ids passed in:
     * the table holds at most one row per course ever tracked, while an IN list
     * over every tracked course could run to thousands of bind parameters.
     *
     * @param array $courseids Course ids that should have a cursor.
     * @return void
     */
    public static function ensure_for_courses(array $courseids): void {
        global $DB;

        if (empty($courseids)) {
            return;
        }
        $known = $DB->get_fieldset_select('block_feedback_tracker_bfcursor', 'courseid', '');
        /* Deduplicate as well as diff: the input may repeat a courseid,
         * which would be inserted twice against the UNIQUE index on courseid.
         * On PostgreSQL insert_records() batches, so the failing statement
         * would also lose every other course in its chunk. */
        $missing = array_values(array_unique(array_diff(
            array_map('intval', $courseids),
            array_map('intval', $known ?: [])
        )));
        if (empty($missing)) {
            return;
        }

        /* insert_records() throws unless every record has the same fields in
         * the same order, so each one is built from the same literal. */
        $now = time();
        $records = [];
        foreach ($missing as $courseid) {
            $records[] = (object) [
                'courseid'    => (int) $courseid,
                'lastsubid'   => 0,
                'active'      => 1,
                'lastrunat'   => null,
                'timecreated' => $now,
            ];
        }
        $DB->insert_records('block_feedback_tracker_bfcursor', $records);
    }

    /**
     * Advance the cursor for one course to the given submission id and record
     * the run timestamp. Sets active to 0 when $complete (no rows left past
     * the cursor; {@see self::reset()} retries), and back to 1 otherwise.
     *
     * @param int $courseid
     * @param int $newcursor
     * @param bool $complete
     * @return void
     */
    public static function advance(int $courseid, int $newcursor, bool $complete = false): void {
        global $DB;
        $row = self::get_or_create($courseid);
        $DB->update_record('block_feedback_tracker_bfcursor', (object) [
            'id'        => $row->id,
            'lastsubid' => $newcursor,
            'active'    => $complete ? 0 : 1,
            'lastrunat' => time(),
        ]);
    }

    /**
     * Reset one course's cursor to 0 and mark it active so the next
     * dispatcher tick walks it from the start. Lazily creates the row
     * if absent. Used by cli/backfill_course.php.
     *
     * @param int $courseid
     * @return void
     */
    public static function reset(int $courseid): void {
        global $DB;
        $row = self::get_or_create($courseid);
        $DB->update_record('block_feedback_tracker_bfcursor', (object) [
            'id'        => $row->id,
            'lastsubid' => 0,
            'active'    => 1,
            'lastrunat' => null,
        ]);
    }

    /**
     * Pause backfill for one course without resetting its progress: sets
     * active to 0 and leaves the cursor. {@see self::enable()} resumes it.
     *
     * @param int $courseid
     * @return void
     */
    public static function disable(int $courseid): void {
        global $DB;
        $row = self::get_or_create($courseid);
        $DB->update_record('block_feedback_tracker_bfcursor', (object) [
            'id'     => $row->id,
            'active' => 0,
        ]);
    }

    /**
     * Re-activate a previously-disabled course without resetting its
     * cursor — the next tick continues from where it left off.
     *
     * @param int $courseid
     * @return void
     */
    public static function enable(int $courseid): void {
        global $DB;
        $row = self::get_or_create($courseid);
        $DB->update_record('block_feedback_tracker_bfcursor', (object) [
            'id'     => $row->id,
            'active' => 1,
        ]);
    }

    /**
     * Return cursor rows for every course currently flagged active.
     * Ordered by courseid ASC for deterministic dispatch order.
     *
     * @return array<int, \stdClass> Keyed by id.
     */
    public static function active_rows(): array {
        global $DB;
        return $DB->get_records(
            'block_feedback_tracker_bfcursor',
            ['active' => 1],
            'courseid ASC'
        );
    }

    /**
     * Return every cursor row, active or complete, for the cli/backfill_course.php listing.
     *
     * @return array<int, \stdClass>
     */
    public static function all_rows(): array {
        global $DB;
        return $DB->get_records('block_feedback_tracker_bfcursor', null, 'courseid ASC');
    }

    /**
     * Drop the cursor row entirely. Course deletion does not come through
     * here: {@see submission_ledger::delete_for_course()} deletes the row itself.
     *
     * @param int $courseid
     * @return void
     */
    public static function delete(int $courseid): void {
        global $DB;
        $DB->delete_records('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
    }
}
