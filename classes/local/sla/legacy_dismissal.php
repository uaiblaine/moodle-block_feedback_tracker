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
 * Takes the pending rows the cycle model inherited without a response out of every population.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\audit\recompute_log;

/**
 * Before the cycle model (upgrade step 2026080202), a student who saved an
 * already-graded submission again erased the row's response: the hand-in time
 * moved to the save and `timegraded` went back to null. Such a row is still a
 * pending cycle 0 today, with its clock running from an edit that may be
 * months old, while the attempt carries a mark older than that edit. The
 * original hand-in time is gone from Moodle, so the response cannot be
 * rebuilt; this takes the row out of the backlog without inventing one.
 *
 * A dismissed row keeps its data, gets `timedismissed` and leaves the current
 * set (`iscurrent = 0`). Every pending read requires `iscurrent = 1` and every
 * graded read requires `timegraded`, which a dismissed row never gets, so it
 * falls out of all of them with no change to any reader. The writer keeps it
 * that way ({@see submission_ledger::upsert_for_cm_user_attempt()}), and work
 * the student saves after the dismissal opens a new cycle.
 *
 * Selection is deliberately narrower than the Resubmitted tag
 * ({@see resubmission}): cycle 0, no recorded mark, a live mark strictly older
 * than the hand-in, and a hand-in before the cutoff, which defaults to the
 * moment this site reached the cycle-model savepoint. A cycle-0 row handed in
 * after that is work the plugin first saw after it was marked; it is pending by
 * the same rule as any resubmission and is left alone.
 */
final class legacy_dismissal {
    /** The savepoint of the upgrade step that introduced measurement cycles. */
    public const CYCLE_MODEL_VERSION = '2026080202';

    /** Ids per UPDATE statement, well under every driver's placeholder limit. */
    private const CHUNK = 500;

    /**
     * When this site reached the cycle-model savepoint.
     *
     * Read from core's {upgrade_log}, which records every savepoint with the
     * plugin's version at that moment. A site installed at or after that
     * version never ran the step and has no row: nothing on it predates the
     * cycle model.
     *
     * @return int|null Epoch seconds, or null when the step never ran here.
     */
    public static function cycle_model_time(): ?int {
        global $DB;
        $when = $DB->get_field_sql(
            'SELECT MIN(timemodified)
               FROM {upgrade_log}
              WHERE plugin = :plugin
                AND version = :version
                AND info = :info',
            [
                'plugin' => 'block_feedback_tracker',
                'version' => self::CYCLE_MODEL_VERSION,
                'info' => 'Upgrade savepoint reached',
            ]
        );
        return $when ? (int) $when : null;
    }

    /**
     * The rows a dismissal with this cutoff would take.
     *
     * The activity is resolved through {course_modules} and {modules}, as the
     * writer resolves it, so a row whose module is gone is not selected.
     *
     * @param int $before Only rows handed in strictly before this instant.
     * @param int $courseid One course, or 0 for every course.
     * @return array Rows keyed by ledger id: id, courseid, groupid, cmid, userid,
     *               attemptnumber, timesubmitted and timemark (the live mark).
     */
    public static function candidates(int $before, int $courseid = 0): array {
        global $DB;
        $params = [
            'modname' => 'assign',
            'substatus' => submission_status::SUBMITTED,
            'before' => $before,
        ];
        $coursesql = '';
        if ($courseid > 0) {
            $coursesql = 'AND l.courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        return $DB->get_records_sql(
            "SELECT l.id, l.courseid, l.groupid, l.cmid, l.userid, l.attemptnumber,
                    l.timesubmitted, g.timemodified AS timemark
               FROM {block_feedback_tracker_sub} l
               JOIN {course_modules} cm ON cm.id = l.cmid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {assign} a ON a.id = cm.instance
               JOIN {assign_grades} g
                 ON g.assignment = a.id
                AND g.userid = l.userid
                AND g.attemptnumber = l.attemptnumber
              WHERE l.cycle = 0
                AND l.iscurrent = 1
                AND l.islatest = 1
                AND l.timegraded IS NULL
                AND l.timemarked IS NULL
                AND l.timedismissed IS NULL
                AND l.submissionstatus = :substatus
                AND l.timesubmitted < :before
                AND g.grade >= 0
                AND g.timemodified < l.timesubmitted
                $coursesql
           ORDER BY l.id ASC",
            $params
        );
    }

    /**
     * Dismiss the rows a cutoff selects, re-queue their rollups and record it.
     *
     * Re-selects rather than taking ids from the caller, so a row that changed
     * between a dry run and this call is judged on its current state. The
     * UPDATE repeats the guard on the ledger's own state (`timedismissed`,
     * `iscurrent`, `timegraded`, `timemarked`), so a row a concurrent writer
     * marked, answered or reopened in the meantime is left alone. Each chunk
     * re-queues its own rollups as soon as it is written, so a failure later
     * in the run cannot leave dismissed rows behind stale figures.
     *
     * @param int $before Only rows handed in strictly before this instant.
     * @param int $courseid One course, or 0 for every course.
     * @param int|null $userid Who ran it, for the audit row; null when nobody is
     *                         logged in, as on the CLI.
     * @return int Rows dismissed.
     */
    public static function dismiss(int $before, int $courseid = 0, ?int $userid = null): int {
        global $DB;
        $started = time();
        $rows = self::candidates($before, $courseid);
        $dismissed = 0;
        $alltuples = [];
        foreach (array_chunk(array_keys($rows), self::CHUNK) as $ids) {
            [$isql, $iparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'ld');
            $now = time();
            $DB->execute(
                "UPDATE {block_feedback_tracker_sub}
                    SET timedismissed = :dismissed, iscurrent = 0, timemodified = :modified
                  WHERE id $isql
                    AND timedismissed IS NULL
                    AND iscurrent = 1
                    AND timegraded IS NULL
                    AND timemarked IS NULL",
                $iparams + ['dismissed' => $now, 'modified' => $now]
            );
            // Counted after the write, so a row the guard skipped is not reported.
            $dismissed += $DB->count_records_select(
                'block_feedback_tracker_sub',
                "id $isql AND timedismissed = :dismissed",
                $iparams + ['dismissed' => $now]
            );
            $tuples = [];
            foreach ($ids as $id) {
                $r = $rows[$id];
                $tuples[(int) $r->courseid . ':' . (int) $r->groupid] = [(int) $r->courseid, (int) $r->groupid];
            }
            foreach ($tuples as $key => [$tcourseid, $tgroupid]) {
                dirty_queue::enqueue($tcourseid, $tgroupid, dirty_queue::REASON_BULK);
                $alltuples[$key] = true;
            }
        }
        recompute_log::record(
            recompute_log::REASON_LEGACY_DISMISSAL,
            $dismissed,
            $userid,
            ['before' => $before, 'courseid' => $courseid, 'tuples' => count($alltuples)],
            $started,
            time()
        );
        return $dismissed;
    }
}
