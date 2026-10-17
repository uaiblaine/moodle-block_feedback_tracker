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
 * One reading of mod_assign's multi-marking state (Moodle 5.3), for every view
 * that shows or filters on it.
 *
 * It mirrors core rule for rule and cites each method it follows, so a change
 * in core has one place to be followed here:
 * - the active slots and their positions, {@see \assign::get_marker_allocations()}
 *   with `$includeplaceholders = false`;
 * - a slot's mark is complete as {@see \assign::mark_is_marked()} decides, and
 *   started as {@see \assign::has_started_marking()} decides;
 * - the number of marks the agreed grade waits for,
 *   {@see \assign::expected_marker_count()}, which reads the allocations WITH
 *   placeholders (an enabled optional slot with no marker yet still counts);
 * - the agreed grade becomes determinable when the completed marks of the
 *   active slots reach that number, {@see \assign::all_markers_marked()}
 *   behind {@see \assign::can_determine_agreed_grade()};
 * - an agreed grade exists when the attempt's grade is set and not negative,
 *   {@see \assign::is_graded()}.
 *
 * Read time only and set-based: one query per table for the whole page, never
 * one `\assign` per row, so a page of 200 rows costs at most four queries. Nothing
 * is stored, so nothing needs recomputing when a marker finishes.
 *
 * Core allocates and marks per student, also on a team submission, so a row is
 * matched by its own userid. Allocations are not kept per attempt, which is why
 * core only ever calculates the latest attempt
 * ({@see \assign::calculate_and_save_agreed_grade()}); the callers pass open
 * rows of the latest attempt.
 */
final class marking_progress {
    /** The agreed grade exists: nothing is waited for. */
    public const WAIT_NONE = 'none';
    /** Some active slot's mark is not complete yet. */
    public const WAIT_MARKERS = 'markers';
    /**
     * An expected slot has no marker (a required slot left empty, or an optional
     * slot ticked before anyone was named): nobody can complete it, so the wait
     * is the coordinator's, even while the other markers are still marking.
     */
    public const WAIT_ALLOCATION = 'allocation';
    /**
     * Every expected mark is complete and no agreed grade exists: under the
     * manual method someone must confirm it; under maximum or average core
     * computes it at once, so there it means it has not been computed.
     */
    public const WAIT_AGREED = 'agreed';

    /** The slot's marker has not started. */
    public const MARK_NONE = 'none';
    /** The slot's marker has started but not completed. */
    public const MARK_INMARKING = 'inmarking';
    /** The slot's mark counts toward the agreed grade. */
    public const MARK_COMPLETE = 'complete';

    /**
     * Multi-marker progress of each row on a multi-marking activity.
     *
     * A row is left out when its activity does not use multiple marking
     * ({@see \assign::is_using_multiple_marking()}: marking workflow, marker
     * allocation and more than one marker slot in all), when its userid is 0,
     * or when its activity row is gone.
     *
     * Each entry holds:
     * - `slots`: the active slots in core's order, each an array of marker,
     *   optional (0/1), enabled (0/1), position (as core numbers it) and
     *   state (one of the MARK_* constants);
     * - `expected`: the number of complete marks the agreed grade needs;
     * - `completed`: the active slots whose mark is complete;
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
            'id, markingworkflow, markingallocation, markercount, optionalmarkercount, multimarkmethod'
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
     * SQL condition: this ledger row is pending for the viewer as a marker.
     *
     * A row passes when any of these holds:
     * - the activity does not use marker allocation (marking workflow or marker
     *   allocation off), so nobody is its marker and every teacher sees it;
     * - the student has no active allocation, a required slot or an optional one
     *   with enabled = 1 holding a marker ({@see \assign::get_marker_allocations()}
     *   with placeholders left out): the row is unallocated, the coordinator's
     *   queue, and every teacher sees it. This also lists, for every teacher, a
     *   multi-marking row with no allocation at all, which the earlier
     *   slot-only rule hid from everyone; that is intended;
     * - the viewer holds an active slot for the student, the viewer's own mark
     *   on the row's attempt is not complete (the negation of
     *   {@see \assign::mark_is_marked()}: workflow state readyforreview and a
     *   mark that is set and not negative), and no agreed grade exists for that
     *   attempt (an {assign_grades} row with grade >= 0). Once the agreed grade
     *   exists the remaining wait, release, belongs to no marker. A
     *   single-marker activity stores its grade there directly, so a graded row
     *   leaves its marker's list too.
     *
     * Core's third condition for "still waiting", completed marks fewer than
     * expected ({@see \assign::all_markers_marked()}), needs no clause of its
     * own: while the viewer's active slot is incomplete the completed count
     * cannot reach the expected count.
     *
     * Consequences: on an allocated student a teacher with no slot (not the
     * coordinator's queue) passes nothing, and "awaiting agreed grade"
     * ({@see self::WAIT_AGREED}) belongs to no marker. The condition is
     * correlated, so it adds no join and the caller's LIMIT applies to rows
     * that already satisfy it. Whenever the student is allocated it must agree
     * with for_rows(): a row is listed for V exactly when V holds an active slot
     * whose state is not MARK_COMPLETE.
     *
     * @param int $viewerid The marker the list is for.
     * @param string $itemcolumn Column holding the assign id, e.g. "sub.iteminstance".
     * @param string $usercolumn Column holding the student id, e.g. "sub.userid".
     * @param string $attemptcolumn Column holding the attempt number, e.g. "sub.attemptnumber".
     * @param string $prefix Prefix of the inner aliases and placeholders; each placeholder is used once.
     * @return array [SQL condition in parentheses, named parameters].
     */
    public static function pending_for_viewer_sql(
        int $viewerid,
        string $itemcolumn,
        string $usercolumn,
        string $attemptcolumn,
        string $prefix = 'mpv'
    ): array {
        $sql = "(NOT EXISTS (SELECT 1
                               FROM {assign} {$prefix}a
                              WHERE {$prefix}a.id = $itemcolumn
                                AND {$prefix}a.markingworkflow = 1
                                AND {$prefix}a.markingallocation = 1)
                 OR NOT EXISTS (SELECT 1
                                  FROM {assign_allocated_marker} {$prefix}n
                                 WHERE {$prefix}n.assignment = $itemcolumn
                                   AND {$prefix}n.student = $usercolumn
                                   AND {$prefix}n.marker > 0
                                   AND ({$prefix}n.optional = 0 OR {$prefix}n.enabled = 1))
                 OR (EXISTS (SELECT 1
                               FROM {assign_allocated_marker} {$prefix}s
                              WHERE {$prefix}s.assignment = $itemcolumn
                                AND {$prefix}s.student = $usercolumn
                                AND {$prefix}s.marker = :{$prefix}slot
                                AND ({$prefix}s.optional = 0 OR {$prefix}s.enabled = 1))
                     AND NOT EXISTS (SELECT 1
                                       FROM {assign_grades} {$prefix}g
                                       JOIN {assign_mark} {$prefix}m ON {$prefix}m.gradeid = {$prefix}g.id
                                      WHERE {$prefix}g.assignment = $itemcolumn
                                        AND {$prefix}g.userid = $usercolumn
                                        AND {$prefix}g.attemptnumber = $attemptcolumn
                                        AND {$prefix}m.marker = :{$prefix}mark
                                        AND {$prefix}m.workflowstate = 'readyforreview'
                                        AND {$prefix}m.mark IS NOT NULL
                                        AND {$prefix}m.mark >= 0)
                     AND NOT EXISTS (SELECT 1
                                       FROM {assign_grades} {$prefix}y
                                      WHERE {$prefix}y.assignment = $itemcolumn
                                        AND {$prefix}y.userid = $usercolumn
                                        AND {$prefix}y.attemptnumber = $attemptcolumn
                                        AND {$prefix}y.grade >= 0)))";
        return [$sql, [$prefix . 'slot' => $viewerid, $prefix . 'mark' => $viewerid]];
    }

    /**
     * Whether an activity row uses multiple marking.
     *
     * {@see \assign::is_using_multiple_marking()}: marking workflow and marker
     * allocation on, and more than one slot counting the optional ones
     * ({@see \assign::total_marker_count()}).
     *
     * @param \stdClass $assign Row of {assign} with the marking columns.
     * @return bool
     */
    public static function uses_multiple_marking(\stdClass $assign): bool {
        return !empty($assign->markingworkflow)
            && !empty($assign->markingallocation)
            && ((int) $assign->markercount + (int) $assign->optionalmarkercount) > 1;
    }

    /**
     * Every allocation row of these students on these activities, keyed by
     * "assignid:studentid", each list in core's order (optional, then id).
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
            'optional ASC, id ASC',
            'id, assignment, student, marker, optional, enabled'
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
     * A mark left by a marker who no longer holds an active slot is fetched
     * too and simply never looked up, which is how core ignores it
     * ({@see \assign::get_mark_records()} reads only the active slots' markers).
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
        $required = (int) $assign->markercount;

        /* Active slots and their positions, as get_marker_allocations($id, false):
         * placeholders and disabled optional slots are filtered out first, then
         * positions count up, jumping to required + 1 at the first optional one. */
        $slots = [];
        $completed = 0;
        $pos = 1;
        foreach ($allocations as $a) {
            $optional = (int) $a->optional === 1;
            if ((int) $a->marker <= 0 || ($optional && (int) $a->enabled !== 1)) {
                continue;
            }
            if ($optional && $pos <= $required) {
                $pos = $required + 1;
            }
            $state = self::mark_state($marks[(int) $a->marker] ?? null);
            if ($state === self::MARK_COMPLETE) {
                $completed++;
            }
            $slots[] = [
                'marker' => (int) $a->marker,
                'optional' => (int) $optional,
                'enabled' => (int) ((int) $a->enabled === 1),
                'position' => $pos,
                'state' => $state,
            ];
            $pos++;
        }

        $expected = self::expected($assign, $allocations);
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
     * The number of complete marks the agreed grade waits for.
     *
     * {@see \assign::expected_marker_count()}: the required count, plus every
     * optional row with enabled = 1, read WITH placeholders, so an optional slot
     * ticked before anyone is allocated to it already counts. With no optional
     * slot configured, core returns the required count without reading rows.
     *
     * @param \stdClass $assign Activity row with the marking columns.
     * @param array $allocations The student's allocation rows.
     * @return int
     */
    private static function expected(\stdClass $assign, array $allocations): int {
        $required = (int) $assign->markercount;
        if ((int) $assign->optionalmarkercount <= 0) {
            return $required;
        }
        $enabled = 0;
        foreach ($allocations as $a) {
            if ((int) $a->optional === 1 && (int) $a->enabled === 1) {
                $enabled++;
            }
        }
        return $required + $enabled;
    }

    /**
     * The state of one slot's mark.
     *
     * Complete as {@see \assign::mark_is_marked()}: the marker's own workflow
     * state is readyforreview ("Marking completed") and the mark is set and not
     * negative. Started as {@see \assign::has_started_marking()}: a mark, or a
     * workflow state other than empty or notmarked.
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
