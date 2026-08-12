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
 * Pending submission re-computer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\calendar\calendar;
use block_feedback_tracker\local\calendar\day_counter;

/**
 * Pending submissions accumulate effective hours over time even without
 * being graded. Without periodic recomputation, an "excellent" pending
 * submission would still read excellent at hour 25 — the score would lie.
 *
 * Run hourly by the `recompute_pending` scheduled task. For each pending
 * ledger row whose `effectivecalver` is behind the current calver, or whose
 * `effectiveasof` is older than one hour, re-runs the academic-time engine
 * against `now` and updates effectivehours / slabucket / pause records in
 * place; then enqueues each touched (course, group) tuple for rollup
 * recompute.
 *
 * The recompute is per-row and skips the expensive cm/assign/grade reads of
 * `submission_ledger::upsert_for_cm_user_attempt()` since the source-of-truth
 * fields (timesubmitted etc.) don't change between hours.
 */
class pending_recomputer {
    /**
     * Recompute the effective hours of stale pending ledger rows.
     *
     * @param int $batchsize Maximum rows to process this run.
     * @param int $timecap Soft time cap in seconds.
     * @param int|null $now Override "now" for tests; defaults to time().
     * @return array{count:int, tuples:int}
     */
    public static function recompute_stale(int $batchsize, int $timecap, ?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $calver = calendar::current_version();
        $deadline = $now + $timecap;
        $stalecutoff = $now - 3600;

        $rows = $DB->get_records_sql(
            "SELECT id, courseid, groupid, timesubmitted
               FROM {block_feedback_tracker_sub}
              WHERE timegraded IS NULL
                AND submissionstatus = :substatus
                AND islatest = 1
                AND iscurrent = 1
                AND (effectivecalver < :calver OR effectiveasof IS NULL OR effectiveasof < :asof)
              ORDER BY COALESCE(effectiveasof, 0) ASC, id ASC",
            ['substatus' => submission_status::SUBMITTED, 'calver' => $calver, 'asof' => $stalecutoff],
            0,
            $batchsize
        );

        $count = 0;
        $touched = [];

        foreach ($rows as $r) {
            if (time() > $deadline) {
                break;
            }

            $timesubmitted = (int) $r->timesubmitted;
            if ($timesubmitted <= 0) {
                $count++;
                continue;
            }

            $effective = academic_time::elapsed_effective_hours(
                (int) $r->courseid,
                (int) $r->groupid,
                $timesubmitted,
                $now
            );
            $waiting = round(max(0.0, ($now - $timesubmitted) / 3600.0), 2);

            $DB->update_record('block_feedback_tracker_sub', (object) [
                'id'              => (int) $r->id,
                'waitinghours'    => $waiting,
                'effectivehours'  => $effective,
                'effectivedays'   => day_counter::business_days($timesubmitted, $now),
                'effectiveasof'   => $now,
                'effectivecalver' => $calver,
                'slabucket'       => bucket::for_effective($effective),
                'timemodified'    => $now,
            ]);

            $touched[(int) $r->courseid . ':' . (int) $r->groupid] = [
                (int) $r->courseid, (int) $r->groupid,
            ];
            $count++;
        }

        foreach ($touched as [$courseid, $groupid]) {
            dirty_queue::enqueue($courseid, $groupid, dirty_queue::REASON_SUBMISSION);
        }

        return ['count' => $count, 'tuples' => count($touched)];
    }
}
