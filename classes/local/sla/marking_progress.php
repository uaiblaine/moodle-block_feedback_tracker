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
 * Multi-marker progress of a page of ledger rows, read from core's tables.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * One reading of mod_assign's multi-marking state (Moodle 5.2), for every view
 * that shows it.
 *
 * It mirrors core rule for rule and cites each method it follows, so a change
 * in core has one place to be followed here:
 * - the slots and their positions are the student's allocation rows in id
 *   order, {@see \assign::get_allocated_markers()}; 5.2 has no optional slots;
 * - the number of marks the agreed grade waits for is the activity's
 *   markercount, as {@see \assign::update_mark()} compares it;
 * - a mark counts toward that number when the allocated marker has ANY
 *   {assign_mark} row for the grade, {@see \assign::get_marks()}: neither the
 *   mark's value nor the marker's own workflow state is read, so a mark saved
 *   "In marking", or a row holding no mark at all, already counts;
 * - an agreed grade exists when the attempt's grade is set and not negative,
 *   {@see \assign::is_graded()}.
 *
 * Each slot still carries a display state (none, in marking, complete) read
 * the way the marker sees their own work; only `completed` follows core's
 * counting rule, so a slot whose state is not MARK_COMPLETE can be counted.
 * That is 5.2's own behaviour: core computes a maximum or an average as soon
 * as every allocated marker has a row, whatever its state.
 *
 * Read time only and set-based: one query per table for the whole page, never
 * one `\assign` per row, so a page of 200 rows costs at most four queries.
 * Nothing is stored, so nothing needs recomputing when a marker finishes.
 *
 * Core allocates and marks per student, also on a team submission, so a row is
 * matched by its own userid. Allocations are not kept per attempt, so the
 * callers pass open rows of the latest attempt.
 */
final class marking_progress {
    /** The agreed grade exists: nothing is waited for. */
    public const WAIT_NONE = 'none';
    /** Some allocated marker has no mark row yet. */
    public const WAIT_MARKERS = 'markers';
    /**
     * Fewer markers are allocated than markercount: nobody can leave the
     * missing marks, so the wait is the coordinator's, even while the
     * allocated markers are still marking.
     */
    public const WAIT_ALLOCATION = 'allocation';
    /**
     * Every expected mark is in and no agreed grade exists: under the manual
     * method someone must confirm it; under maximum or average core computes
     * it at once, so there it means it has not been computed.
     */
    public const WAIT_AGREED = 'agreed';

    /** The slot's marker has not started. */
    public const MARK_NONE = 'none';
    /** The slot's marker has started but not completed. */
    public const MARK_INMARKING = 'inmarking';
    /** The slot's marker set "Marking completed" with a mark. */
    public const MARK_COMPLETE = 'complete';

    /**
     * Multi-marker progress of each row on a multi-marking activity.
     *
     * A row is left out when its activity does not use multiple marking
     * ({@see \assign::is_using_multiple_marking()}: marking workflow, marker
     * allocation and markercount above 1), when its userid is 0, or when its
     * activity row is gone.
     *
     * Each entry holds:
     * - `slots`: the allocated markers in core's order, each an array of
     *   marker, optional and enabled (always 0: Moodle 5.2 has no optional
     *   slots; kept so the shape matches the 5.3 branch), position (from 1)
     *   and state (one of the MARK_* constants, for display);
     * - `expected`: the number of marks the agreed grade needs (markercount);
     * - `completed`: the slots whose marker has a mark row, as core counts;
     * - `method`: the activity's multimarkmethod;
     * - `wait`: one of the WAIT_* constants.
     *
     * @param array $rows Ledger rows; each needs id, iteminstance (the assign id), userid and attemptnumber.
     * @return array Ledger row id => progress entry, for multi-marking rows only.
     */
    public static function for_rows(array $rows): array {
        global $DB;

        $assignids = [];
        foreach ($rows as $r) {
            if ((int) $r->userid > 0) {
                $assignids[(int) $r->iteminstance] = true;
            }
        }
        if (empty($assignids)) {
            return [];
        }

        [$asql, $aparams] = $DB->get_in_or_equal(array_keys($assignids), SQL_PARAMS_NAMED, 'mpa');
        $assigns = $DB->get_records_select(
            'assign',
            "id $asql",
            $aparams,
            '',
            'id, markingworkflow, markingallocation, markercount, multimarkmethod'
        );
        $multi = [];
        foreach ($assigns as $a) {
            if (self::uses_multiple_marking($a)) {
                $multi[(int) $a->id] = $a;
            }
        }

        $mine = [];
        $userids = [];
        $attempts = [];
        foreach ($rows as $r) {
            if ((int) $r->userid > 0 && isset($multi[(int) $r->iteminstance])) {
                $mine[] = $r;
                $userids[(int) $r->userid] = true;
                $attempts[(int) $r->attemptnumber] = true;
            }
        }
        if (empty($mine)) {
            return [];
        }

        $allocations = self::allocations(array_keys($multi), array_keys($userids));
        $grades = self::grades(array_keys($multi), array_keys($userids), array_keys($attempts));
        $marks = self::marks($grades);

        $out = [];
        foreach ($mine as $r) {
            $assign = $multi[(int) $r->iteminstance];
            $userkey = (int) $r->iteminstance . ':' . (int) $r->userid;
            $grade = $grades[$userkey . ':' . (int) $r->attemptnumber] ?? null;
            $out[(int) $r->id] = self::progress(
                $assign,
                $allocations[$userkey] ?? [],
                $grade,
                $grade !== null ? ($marks[(int) $grade->id] ?? []) : []
            );
        }
        return $out;
    }

    /**
     * Whether an activity row uses multiple marking.
     *
     * {@see \assign::is_using_multiple_marking()} on 5.2: marking workflow and
     * marker allocation on, and markercount above 1.
     *
     * @param \stdClass $assign Row of {assign} with the marking columns.
     * @return bool
     */
    public static function uses_multiple_marking(\stdClass $assign): bool {
        return !empty($assign->markingworkflow)
            && !empty($assign->markingallocation)
            && (int) $assign->markercount > 1;
    }

    /**
     * Every allocation row of these students on these activities, keyed by
     * "assignid:studentid", each list in core's order (id).
     *
     * Over-fetches pairs that are not on the page, as the other page readers
     * do; the caller only looks up its own pairs.
     *
     * @param array $assignids Assign ids.
     * @param array $userids Student ids.
     * @return array "assignid:studentid" => list of {assign_allocated_marker} rows.
     */
    private static function allocations(array $assignids, array $userids): array {
        global $DB;

        [$asql, $aparams] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED, 'mpla');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'mplu');
        $records = $DB->get_records_select(
            'assign_allocated_marker',
            "assignment $asql AND student $usql",
            $aparams + $uparams,
            'id ASC',
            'id, assignment, student, marker'
        );
        $out = [];
        foreach ($records as $rec) {
            $out[(int) $rec->assignment . ':' . (int) $rec->student][] = $rec;
        }
        return $out;
    }

    /**
     * The grade row of each (activity, student, attempt) asked for, keyed by
     * "assignid:studentid:attemptnumber".
     *
     * Core keeps one grade row per attempt; should two exist, the newer wins,
     * as it would be the one {@see \assign::get_user_grade()} writes to.
     *
     * @param array $assignids Assign ids.
     * @param array $userids Student ids.
     * @param array $attempts Attempt numbers.
     * @return array "assignid:studentid:attemptnumber" => {assign_grades} row (id, grade).
     */
    private static function grades(array $assignids, array $userids, array $attempts): array {
        global $DB;

        [$asql, $aparams] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED, 'mpga');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'mpgu');
        [$tsql, $tparams] = $DB->get_in_or_equal($attempts, SQL_PARAMS_NAMED, 'mpgt');
        $records = $DB->get_records_select(
            'assign_grades',
            "assignment $asql AND userid $usql AND attemptnumber $tsql",
            $aparams + $uparams + $tparams,
            'id ASC',
            'id, assignment, userid, attemptnumber, grade'
        );
        $out = [];
        foreach ($records as $rec) {
            $out[(int) $rec->assignment . ':' . (int) $rec->userid . ':' . (int) $rec->attemptnumber] = $rec;
        }
        return $out;
    }

    /**
     * Every marker's mark on these grade rows, keyed by grade id then marker.
     *
     * A mark left by a marker who is no longer allocated is fetched too and
     * simply never looked up, which is how core ignores it
     * ({@see \assign::get_marks()} reads only the allocated markers' rows).
     *
     * @param array $grades Grade rows from grades().
     * @return array Grade id => [marker id => {assign_mark} row].
     */
    private static function marks(array $grades): array {
        global $DB;

        if (empty($grades)) {
            return [];
        }
        $gradeids = array_map(fn($g) => (int) $g->id, array_values($grades));
        [$gsql, $gparams] = $DB->get_in_or_equal($gradeids, SQL_PARAMS_NAMED, 'mpmg');
        $records = $DB->get_records_select(
            'assign_mark',
            "gradeid $gsql",
            $gparams,
            'id ASC',
            'id, gradeid, marker, mark, workflowstate'
        );
        $out = [];
        foreach ($records as $rec) {
            $out[(int) $rec->gradeid][(int) $rec->marker] = $rec;
        }
        return $out;
    }

    /**
     * The progress entry of one student on one multi-marking activity.
     *
     * @param \stdClass $assign Activity row with the marking columns.
     * @param array $allocations The student's allocation rows, in core's order.
     * @param \stdClass|null $grade The attempt's grade row, null when none exists yet.
     * @param array $marks The grade's marks keyed by marker id.
     * @return array The entry described in for_rows().
     */
    private static function progress(\stdClass $assign, array $allocations, ?\stdClass $grade, array $marks): array {
        /* Slots in allocation-row order, as get_allocated_markers() returns
         * them. get_marks() keys its result by marker, so a marker listed twice
         * counts once; update_allocated_markers() never writes a row without a
         * marker, and one written some other way could not be marked. */
        $slots = [];
        $seen = [];
        $completed = 0;
        foreach ($allocations as $a) {
            $marker = (int) $a->marker;
            if ($marker <= 0 || isset($seen[$marker])) {
                continue;
            }
            $seen[$marker] = true;
            $mark = $marks[$marker] ?? null;
            if ($mark !== null) {
                $completed++;
            }
            $slots[] = [
                'marker' => $marker,
                'optional' => 0,
                'enabled' => 0,
                'position' => count($slots) + 1,
                'state' => self::mark_state($mark),
            ];
        }

        /* On 5.2 the agreed and allocation waits cannot both hold: only the
         * allocated markers' rows count, so completed never exceeds the slots.
         * The order is the 5.3 branch's, where they can. */
        $expected = (int) $assign->markercount;
        $agreed = $grade !== null && $grade->grade !== null && (float) $grade->grade >= 0;
        if ($agreed) {
            $wait = self::WAIT_NONE;
        } else if ($completed >= $expected) {
            $wait = self::WAIT_AGREED;
        } else if (count($slots) < $expected) {
            $wait = self::WAIT_ALLOCATION;
        } else {
            $wait = self::WAIT_MARKERS;
        }

        return [
            'slots' => $slots,
            'expected' => $expected,
            'completed' => $completed,
            'method' => (string) $assign->multimarkmethod,
            'wait' => $wait,
        ];
    }

    /**
     * The display state of one slot's mark.
     *
     * Complete: the marker's own workflow state is readyforreview ("Marking
     * completed") and the mark is set and not negative. Started: a mark, or a
     * workflow state other than empty or notmarked. Display only: core 5.2
     * counts any row ({@see self::progress()}).
     *
     * @param \stdClass|null $mark The {assign_mark} row, null when the marker has none.
     * @return string One of the MARK_* constants.
     */
    private static function mark_state(?\stdClass $mark): string {
        if ($mark === null) {
            return self::MARK_NONE;
        }
        $hasmark = $mark->mark !== null && (float) $mark->mark >= 0;
        $state = (string) ($mark->workflowstate ?? '');
        if ($state === 'readyforreview' && $hasmark) {
            return self::MARK_COMPLETE;
        }
        if ($hasmark || ($state !== '' && $state !== 'notmarked')) {
            return self::MARK_INMARKING;
        }
        return self::MARK_NONE;
    }
}
