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
 * Writer of the per-marker slot table.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\calendar\day_counter;

/**
 * The only code that writes {block_feedback_tracker_alloc}: one row per ledger
 * cycle and marker slot on a multi-marking activity (Moodle 5.3, owner decision
 * D3), so each marker's own turnaround, from becoming responsible to their own
 * "Marking completed", is measured apart from the row-level figures.
 *
 * The table is history. Who holds a slot now is read from core every time,
 * through {@see marking_progress::for_rows()}, the one reading of core's
 * multi-marking state; events and the reconciler only say when to look.
 *
 * Two clock rules:
 * - A marker becomes responsible for a cycle when they are allocated, but never
 *   before its hand-in: a marker already allocated when the cycle opens is
 *   carried onto it at the hand-in instant ({@see self::SOURCE_CARRIED}), and
 *   the turnaround is measured from the later of the two instants.
 * - A re-allocation after a removal starts a new clock: the marker who comes
 *   back starts from that instant, and their earlier completion on the cycle
 *   no longer stands. A removal and re-allocation in the same second is one
 *   save moving the marker between positions, not a re-allocation.
 */
final class slot_ledger {
    /** The allocation instant comes from marker_added / marker_removed: exact. */
    public const SOURCE_OBSERVED = 'observed';

    /**
     * The marker was already allocated when the cycle opened, so they became
     * responsible at its hand-in: exact by construction.
     */
    public const SOURCE_CARRIED = 'carried';

    /**
     * Found by the reconciler's slot sweep, because no event reported it: the
     * instant is the discovery, later than the real allocation, so the
     * turnaround is under-estimated.
     */
    public const SOURCE_RECONCILED = 'reconciled';

    /**
     * Seconds after its hand-in within which a new cycle row counts as written
     * at the hand-in ({@see self::carry_onto_new_cycle()}): the submission's own
     * request, or a repair task queued by it and run by the next cron pass.
     */
    public const CARRY_WINDOW = 600;

    /** Ids per statement when deleting by a list of ledger ids. */
    private const DELETE_CHUNK = 1000;

    /**
     * Bring the slot rows of a student's current cycles into line with core.
     *
     * Covers the current cycle (`iscurrent = 1`) of the latest attempt, the only
     * one core's allocations describe, since they are not kept per attempt. For
     * each active marker ({@see marking_progress::for_rows()}): a row is
     * inserted when there is none, revived with a new clock when it was removed,
     * and its position and optional flag are updated as seen. A live row whose
     * marker no longer holds an active slot gets `timeremoved`. Does nothing on
     * an activity that does not use multiple marking.
     *
     * @param int $cmid Course-module id of the assign.
     * @param int $userid The student.
     * @param int $when The instant the change happened, or was found.
     * @param string $source One of the SOURCE_* constants, recorded on every row whose clock starts here.
     * @return int Slot rows inserted or changed.
     */
    public static function sync_for_user(int $cmid, int $userid, int $when, string $source): int {
        global $DB;

        if ($cmid <= 0 || $userid <= 0) {
            return 0;
        }
        $rows = $DB->get_records_sql(
            "SELECT l.id, cm.instance AS iteminstance, l.userid, l.attemptnumber, l.courseid, l.cmid
               FROM {block_feedback_tracker_sub} l
               JOIN {course_modules} cm ON cm.id = l.cmid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE l.cmid = :cmid
                AND l.userid = :userid
                AND l.iscurrent = 1
                AND l.islatest = 1",
            ['modname' => 'assign', 'cmid' => $cmid, 'userid' => $userid]
        );
        if (empty($rows)) {
            return 0;
        }
        $progress = marking_progress::for_rows(array_values($rows));
        if (empty($progress)) {
            return 0;
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($progress), SQL_PARAMS_NAMED, 'sls');
        $records = $DB->get_records_select(
            'block_feedback_tracker_alloc',
            "subid $insql",
            $inparams,
            'id ASC',
            'id, subid, marker, position, optional, timeremoved'
        );
        $stored = [];
        foreach ($records as $rec) {
            $stored[(int) $rec->subid][(int) $rec->marker] = $rec;
        }

        $written = 0;
        foreach ($progress as $subid => $entry) {
            $row = $rows[$subid];
            $mine = $stored[(int) $subid] ?? [];
            $active = [];
            foreach ($entry['slots'] as $slot) {
                $active[(int) $slot['marker']] = $slot;
            }
            foreach ($active as $marker => $slot) {
                $rec = $mine[$marker] ?? null;
                if ($rec === null) {
                    $written += self::insert_slot($row, $slot, $when, $source);
                    continue;
                }
                $written += self::refresh_slot($rec, $slot, $when, $source);
            }
            foreach ($mine as $marker => $rec) {
                if (!isset($active[$marker]) && $rec->timeremoved === null) {
                    $DB->update_record('block_feedback_tracker_alloc', (object) [
                        'id' => (int) $rec->id,
                        'timeremoved' => $when,
                        'timemodified' => time(),
                    ]);
                    $written++;
                }
            }
        }
        return $written;
    }

    /**
     * Give a newly opened cycle the markers already allocated to the student.
     *
     * Core keeps allocations per student, not per attempt or cycle, so the
     * markers allocated when the cycle opens hold it from its hand-in: that is
     * when they became responsible for it ({@see self::SOURCE_CARRIED}). That
     * instant is exact only when the row is written at the hand-in. A row
     * written later (the backfill of a course that just gained the block, the
     * reconciler's missing-row sweep) may find a marker who was allocated
     * after the hand-in, at an instant nothing recorded, so there the markers
     * are stamped as discovered now ({@see self::SOURCE_RECONCILED}).
     *
     * @param int $cmid Course-module id of the assign.
     * @param int $userid The student.
     * @param int $timesubmitted The new cycle's hand-in.
     * @param int $now When the cycle row was written.
     * @return int Slot rows inserted or changed.
     */
    public static function carry_onto_new_cycle(int $cmid, int $userid, int $timesubmitted, int $now): int {
        if ($now - $timesubmitted <= self::CARRY_WINDOW) {
            return self::sync_for_user($cmid, $userid, $timesubmitted, self::SOURCE_CARRIED);
        }
        return self::sync_for_user($cmid, $userid, $now, self::SOURCE_RECONCILED);
    }

    /**
     * Record a marker's "Marking completed" on the student's current cycle.
     *
     * The caller has established that the mark reached that state (the
     * `submission_marked` event without `draft`). The mark row gives the
     * marker, the student and the attempt; the slot is that marker's live row
     * on the attempt's current cycle. Without one (an allocation no event
     * reported yet) nothing is written, and the reconciler's slot sweep
     * records both later. The first completion wins
     * ({@see self::complete_slot()}).
     *
     * @param int $markid {assign_mark}.id.
     * @param int $when The completion instant.
     * @return bool True when a completion was recorded.
     */
    public static function mark_completed(int $markid, int $when): bool {
        global $DB;

        $mark = $DB->get_record_sql(
            "SELECT m.id, m.marker, g.assignment, g.userid, g.attemptnumber
               FROM {assign_mark} m
               JOIN {assign_grades} g ON g.id = m.gradeid
              WHERE m.id = :markid",
            ['markid' => $markid]
        );
        if (!$mark) {
            return false;
        }
        $slotid = $DB->get_field_sql(
            "SELECT s.id
               FROM {block_feedback_tracker_alloc} s
               JOIN {block_feedback_tracker_sub} l ON l.id = s.subid
              WHERE l.iteminstance = :assignid
                AND l.userid = :userid
                AND l.attemptnumber = :attempt
                AND l.iscurrent = 1
                AND s.marker = :marker
                AND s.timeremoved IS NULL",
            [
                'assignid' => (int) $mark->assignment,
                'userid' => (int) $mark->userid,
                'attempt' => (int) $mark->attemptnumber,
                'marker' => (int) $mark->marker,
            ],
            IGNORE_MULTIPLE
        );
        if (!$slotid) {
            return false;
        }
        return self::complete_slot((int) $slotid, $when);
    }

    /**
     * Stamp one slot's first completion and measure the marker's turnaround.
     *
     * First completion wins: a slot that already has `timemarked` is left
     * alone, so a marker who goes back to "In marking" and completes again
     * keeps the instant they first delivered. The interval runs from the later
     * of `timeallocated` and the cycle's hand-in to the completion, in
     * effective hours ({@see academic_time::elapsed_effective_hours()}) and in
     * business days ({@see day_counter::business_days()}), never derived from
     * each other. An unknown or non-positive interval stores null, never zero,
     * which would read as a flawless turnaround.
     *
     * @param int $slotid {block_feedback_tracker_alloc}.id.
     * @param int $when The completion instant.
     * @return bool True when the completion was recorded.
     */
    public static function complete_slot(int $slotid, int $when): bool {
        global $DB;

        $slot = $DB->get_record_sql(
            "SELECT s.id, s.timeallocated, s.timemarked, l.courseid, l.groupid, l.timesubmitted
               FROM {block_feedback_tracker_alloc} s
               JOIN {block_feedback_tracker_sub} l ON l.id = s.subid
              WHERE s.id = :slotid",
            ['slotid' => $slotid]
        );
        if (!$slot || $slot->timemarked !== null) {
            return false;
        }

        $hours = null;
        $days = null;
        if ($slot->timeallocated !== null) {
            $start = max((int) $slot->timeallocated, (int) $slot->timesubmitted);
            if ($start < $when) {
                $hours = academic_time::elapsed_effective_hours((int) $slot->courseid, (int) $slot->groupid, $start, $when);
                $days = day_counter::business_days($start, $when);
            }
        }
        $DB->update_record('block_feedback_tracker_alloc', (object) [
            'id' => (int) $slot->id,
            'timemarked' => $when,
            'markhours' => $hours,
            'markdays' => $days,
            'timemodified' => time(),
        ]);
        return true;
    }

    /**
     * Delete the slot rows of every ledger row a predicate selects.
     *
     * Called before the ledger rows themselves go, with the same predicate, so
     * the two deletions cannot disagree about which rows they meant.
     *
     * @param string $where SQL predicate over {block_feedback_tracker_sub}, unaliased.
     * @param array $params Its named parameters.
     * @return void
     */
    public static function delete_for_ledger_where(string $where, array $params): void {
        global $DB;
        $DB->delete_records_select(
            'block_feedback_tracker_alloc',
            "subid IN (SELECT id FROM {block_feedback_tracker_sub} WHERE $where)",
            $params
        );
    }

    /**
     * Delete the slot rows of a list of ledger rows.
     *
     * @param array $subids Ledger row ids.
     * @return void
     */
    public static function delete_for_subids(array $subids): void {
        global $DB;
        $subids = array_values(array_unique(array_map('intval', $subids)));
        foreach (array_chunk($subids, self::DELETE_CHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'sld');
            $DB->delete_records_select('block_feedback_tracker_alloc', "subid $insql", $inparams);
        }
    }

    /**
     * Delete every slot row of one course.
     *
     * @param int $courseid
     * @return void
     */
    public static function delete_for_course(int $courseid): void {
        global $DB;
        $DB->delete_records('block_feedback_tracker_alloc', ['courseid' => $courseid]);
    }

    /**
     * Delete every slot row of one course module.
     *
     * @param int $cmid
     * @return void
     */
    public static function delete_for_cm(int $cmid): void {
        global $DB;
        $DB->delete_records('block_feedback_tracker_alloc', ['cmid' => $cmid]);
    }

    /**
     * Delete the rows where one user is the marker, in one course.
     *
     * A marker's erasure request: the per-marker figures lose them, and the
     * students' ledger rows stay.
     *
     * @param int $courseid
     * @param int $markerid
     * @return void
     */
    public static function delete_for_marker_in_course(int $courseid, int $markerid): void {
        global $DB;
        $DB->delete_records('block_feedback_tracker_alloc', ['courseid' => $courseid, 'marker' => $markerid]);
    }

    /**
     * Delete every slot row, for the data reset.
     *
     * @return void
     */
    public static function delete_all(): void {
        global $DB;
        $DB->delete_records('block_feedback_tracker_alloc');
    }

    /**
     * Insert the row of a marker newly seen in an active slot.
     *
     * Tolerates a concurrent writer: the unique index on (subid, marker) turns
     * the race into an exception, and outside a transaction the other writer's
     * row is the same fact, so it is kept. Inside one the exception is rethrown,
     * because a failed INSERT has already poisoned the transaction on
     * PostgreSQL.
     *
     * @param \stdClass $row Ledger row (id, courseid, cmid, userid).
     * @param array $slot Slot entry from marking_progress (marker, optional, position).
     * @param int $when Allocation instant.
     * @param string $source One of the SOURCE_* constants.
     * @return int 1 when a row was inserted, else 0.
     */
    private static function insert_slot(\stdClass $row, array $slot, int $when, string $source): int {
        global $DB;
        $now = time();
        try {
            $DB->insert_record('block_feedback_tracker_alloc', (object) [
                'subid' => (int) $row->id,
                'courseid' => (int) $row->courseid,
                'cmid' => (int) $row->cmid,
                'userid' => (int) $row->userid,
                'marker' => (int) $slot['marker'],
                'position' => (int) $slot['position'],
                'optional' => (int) $slot['optional'],
                'timeallocated' => $when,
                'allocsource' => $source,
                'timemarked' => null,
                'markhours' => null,
                'markdays' => null,
                'timeremoved' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            return 1;
        } catch (\dml_write_exception $e) {
            if ($DB->is_transaction_started()) {
                throw $e;
            }
            return 0;
        }
    }

    /**
     * Update a stored row whose marker holds an active slot.
     *
     * A removed row is revived. Its clock restarts when the removal is older
     * than this instant; a removal in the same second is the same save moving
     * the marker to another position ({@see \assign::update_marker_allocations()}
     * processes the positions one by one and fires per position), so only the
     * removal is undone.
     *
     * @param \stdClass $rec Stored row (id, position, optional, timeremoved).
     * @param array $slot Slot entry from marking_progress.
     * @param int $when The instant of the change.
     * @param string $source One of the SOURCE_* constants.
     * @return int 1 when the row was written, else 0.
     */
    private static function refresh_slot(\stdClass $rec, array $slot, int $when, string $source): int {
        global $DB;
        $update = ['id' => (int) $rec->id];
        if ($rec->timeremoved !== null) {
            $update['timeremoved'] = null;
            if ((int) $rec->timeremoved < $when) {
                $update['timeallocated'] = $when;
                $update['allocsource'] = $source;
                $update['timemarked'] = null;
                $update['markhours'] = null;
                $update['markdays'] = null;
            }
        }
        if ((int) $rec->position !== (int) $slot['position']) {
            $update['position'] = (int) $slot['position'];
        }
        if ((int) $rec->optional !== (int) $slot['optional']) {
            $update['optional'] = (int) $slot['optional'];
        }
        if (count($update) === 1) {
            return 0;
        }
        $update['timemodified'] = time();
        $DB->update_record('block_feedback_tracker_alloc', (object) $update);
        return 1;
    }
}
