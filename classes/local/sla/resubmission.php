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
 * Which listed submissions are work handed in again after a mark.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Tells the lists which rows are core's "Graded - resubmitted": an attempt that
 * already carried a mark when the student saved the submission again, without a
 * new attempt being opened.
 *
 * Two recorded facts say so. A row with `cycle > 0` is one the ledger opened
 * because the work changed after the previous cycle was marked
 * ({@see submission_ledger::upsert_for_cm_user_attempt()}); the previous cycle's
 * row holds the mark time. A cycle-0 row has no previous cycle, but its attempt
 * can still carry a mark older than its hand-in: a row written before the cycle
 * model (whose post-grading edit erased `timegraded`), or work first seen by the
 * plugin after it was already marked. For those the mark is read live from
 * `{assign_grades}`, with the same strict comparison that opens a cycle.
 *
 * Both queries take the whole page at once and over-fetch, as
 * {@see submission_browser::hidden_from_student()} does: matching course
 * modules (or assignments) and users separately admits pairs that are not on
 * the page, and discarding those in PHP is cheaper than a composite predicate.
 */
final class resubmission {
    /**
     * When each resubmitted row's attempt was marked before this hand-in,
     * keyed by ledger row id.
     *
     * Only submitted rows are considered: a draft is not handed in. A row
     * absent from the result is not a resubmission. A value of 0 means the row
     * is one but the earlier mark time is gone, which happens when retention
     * pruned the previous cycle's row.
     *
     * @param array $rows Ledger rows; each needs id, cmid, userid, iteminstance, attemptnumber,
     *                    cycle, timesubmitted and submissionstatus.
     * @return array Ledger row id => epoch of the earlier mark, or 0 when unknown.
     */
    public static function previous_marks(array $rows): array {
        $later = [];
        $first = [];
        foreach ($rows as $r) {
            if ((string) $r->submissionstatus !== submission_status::SUBMITTED) {
                continue;
            }
            if ((int) $r->cycle > 0) {
                $later[] = $r;
            } else {
                $first[] = $r;
            }
        }
        return self::from_previous_cycle($later) + self::from_live_grade($first);
    }

    /**
     * The mark time of the cycle before each row's own.
     *
     * The mark is `timemarked`, the time compared when the cycle was opened; a
     * cycle answered from the gradebook has none and falls back to
     * `timeclosed`, as the ledger does when it compares.
     *
     * @param array $rows Rows with cycle > 0.
     * @return array Ledger row id => epoch, or 0 when the previous cycle is gone.
     */
    private static function from_previous_cycle(array $rows): array {
        global $DB;
        if (empty($rows)) {
            return [];
        }
        [$csql, $cparams] = $DB->get_in_or_equal(self::ids($rows, 'cmid'), SQL_PARAMS_NAMED, 'rsc');
        [$usql, $uparams] = $DB->get_in_or_equal(self::ids($rows, 'userid'), SQL_PARAMS_NAMED, 'rsu');
        $previous = $DB->get_records_sql(
            "SELECT id, cmid, userid, attemptnumber, cycle, timemarked, timeclosed
               FROM {block_feedback_tracker_sub}
              WHERE cmid $csql
                AND userid $usql",
            $cparams + $uparams
        );
        $marks = [];
        foreach ($previous as $p) {
            $key = (int) $p->cmid . ':' . (int) $p->userid . ':' . (int) $p->attemptnumber . ':' . (int) $p->cycle;
            $marks[$key] = (int) ($p->timemarked ?? $p->timeclosed ?? 0);
        }
        $out = [];
        foreach ($rows as $r) {
            $key = (int) $r->cmid . ':' . (int) $r->userid . ':' . (int) $r->attemptnumber . ':' . ((int) $r->cycle - 1);
            $out[(int) $r->id] = $marks[$key] ?? 0;
        }
        return $out;
    }

    /**
     * The attempt's live mark, for rows whose hand-in is later than it.
     *
     * A mark is a grade row with a real value: -1 is mod_assign's "no grade"
     * placeholder, written when a grader merely opens the submission, and a
     * null value is a cleared grade.
     *
     * @param array $rows Rows with cycle 0.
     * @return array Ledger row id => epoch of the mark.
     */
    private static function from_live_grade(array $rows): array {
        global $DB;
        if (empty($rows)) {
            return [];
        }
        [$asql, $aparams] = $DB->get_in_or_equal(self::ids($rows, 'iteminstance'), SQL_PARAMS_NAMED, 'rsa');
        [$usql, $uparams] = $DB->get_in_or_equal(self::ids($rows, 'userid'), SQL_PARAMS_NAMED, 'rsu');
        $grades = $DB->get_records_sql(
            "SELECT g.id, g.assignment, g.userid, g.attemptnumber, g.timemodified
               FROM {assign_grades} g
              WHERE g.assignment $asql
                AND g.userid $usql
                AND g.grade IS NOT NULL
                AND g.grade >= 0",
            $aparams + $uparams
        );
        $marks = [];
        foreach ($grades as $g) {
            $marks[(int) $g->assignment . ':' . (int) $g->userid . ':' . (int) $g->attemptnumber] = (int) $g->timemodified;
        }
        $out = [];
        foreach ($rows as $r) {
            $mark = $marks[(int) $r->iteminstance . ':' . (int) $r->userid . ':' . (int) $r->attemptnumber] ?? 0;
            if ($mark > 0 && $mark < (int) $r->timesubmitted) {
                $out[(int) $r->id] = $mark;
            }
        }
        return $out;
    }

    /**
     * The distinct integer values of one field across the rows.
     *
     * @param array $rows
     * @param string $field
     * @return int[]
     */
    private static function ids(array $rows, string $field): array {
        return array_values(array_unique(array_map(static fn($r) => (int) $r->$field, $rows)));
    }
}
