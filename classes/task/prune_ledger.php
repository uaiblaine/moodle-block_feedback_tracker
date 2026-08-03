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
 * Scheduled task: discard measured history past its retention window.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\retention;
use block_feedback_tracker\local\sla\submission_status;

/**
 * Bounds the ledger's growth by dropping closed measurements older than the
 * configured window, plus the daily aggregate rows that outlived every surface
 * that reads them.
 *
 * Two rules make this safe to run unattended:
 *
 *  - **Only closed rows are ever deleted.** A row still awaiting feedback is
 *    outstanding work, and its age is precisely the signal this plugin exists
 *    to surface — deleting the oldest pending items would hide exactly what a
 *    coordinator needs to see. They leave the ledger only when their
 *    submission, course or enrolment does, which the reconciler already
 *    handles.
 *  - **The reconciler agrees on the boundary.** Its first sweep recreates a
 *    ledger row for any submission that lacks one, so without a shared cutoff
 *    it would resurrect everything deleted here on the next tick, for ever.
 *    Both read {@see retention::cutoff()}.
 *
 * The rollup is not re-enqueued afterwards: every statistical window is 30
 * days and the retention floor is a month, so no pruned row could have been
 * contributing to a displayed figure. The report's all-time Graded tab does
 * shrink to the window — that is the trade the setting makes, and it is
 * stated in the setting's own description.
 */
class prune_ledger extends \core\task\scheduled_task {
    /** Default rows deleted per table per run. */
    public const DEFAULT_BATCH = 5000;

    /** Default soft time cap for the whole tick, in seconds. */
    public const DEFAULT_TIME_CAP = 50;

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_prune_ledger', 'block_feedback_tracker');
    }

    /**
     * Delete one bounded batch of expired history.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $cutoff = retention::cutoff();
        if ($cutoff === null) {
            mtrace('prune_ledger: retention is off; nothing is ever deleted.');
            return;
        }

        $batch = (int) (get_config('block_feedback_tracker', 'retention_batch_size') ?: self::DEFAULT_BATCH);
        if ($batch < 1) {
            $batch = self::DEFAULT_BATCH;
        }
        $timecap = (int) (get_config('block_feedback_tracker', 'drain_time_cap_seconds') ?: self::DEFAULT_TIME_CAP);
        $deadline = time() + $timecap;

        mtrace(sprintf(
            'prune_ledger: discarding closed history recorded before %s.',
            userdate($cutoff, get_string('strftimedatetimeshort', 'langconfig'))
        ));

        $subs = $this->prune_closed_submissions($cutoff, $batch);
        mtrace(sprintf('prune_ledger: %d closed submission row(s) deleted.', $subs));

        if (time() > $deadline) {
            mtrace('prune_ledger: time cap reached; the aggregate tables run next tick.');
            return;
        }

        $trend = $this->prune_daily_table('block_feedback_tracker_trend', $cutoff, $batch);
        $site = $this->prune_daily_table('block_feedback_tracker_site', $cutoff, $batch);
        mtrace(sprintf(
            'prune_ledger: %d trend row(s) and %d site row(s) deleted.',
            $trend,
            $site
        ));
    }

    /**
     * Delete closed ledger rows recorded before the cutoff.
     *
     * Keyed on `timegraded`, which is what every read predicate in the plugin
     * treats as "this measurement is finished" and is always populated on a
     * closed row. Pending rows are excluded by that same test, so no age
     * threshold can reach them.
     *
     * @param int $cutoff Epoch seconds.
     * @param int $batch Row ceiling for this run.
     * @return int Rows deleted.
     */
    private function prune_closed_submissions(int $cutoff, int $batch): int {
        global $DB;

        $ids = $DB->get_fieldset_sql(
            "SELECT id
               FROM {block_feedback_tracker_sub}
              WHERE timegraded IS NOT NULL
                AND timegraded < :cutoff
           ORDER BY timegraded ASC",
            ['cutoff' => $cutoff],
            0,
            $batch
        );
        if (empty($ids)) {
            return 0;
        }
        [$isql, $iparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'p');
        $DB->delete_records_select('block_feedback_tracker_sub', "id $isql", $iparams);
        return count($ids);
    }

    /**
     * Delete daily aggregate rows older than the cutoff.
     *
     * These carry no per-user data and no audit value: the sparkline reads a
     * fortnight and `cli/backfill_trends.php` rebuilds at most a couple of
     * months, so anything past the retention floor is unreachable by any
     * surface.
     *
     * @param string $table Either the trend or the site-stats table.
     * @param int $cutoff Epoch seconds.
     * @param int $batch Row ceiling for this run.
     * @return int Rows deleted.
     */
    private function prune_daily_table(string $table, int $cutoff, int $batch): int {
        global $DB;

        /* `day` is a YYYYMMDD integer, not an epoch, so the cutoff is
         * converted rather than compared directly — a raw comparison would
         * silently match nothing and read as "there was nothing to delete". */
        $cutoffday = (int) userdate($cutoff, '%Y%m%d');
        $ids = $DB->get_fieldset_sql(
            "SELECT id FROM {" . $table . "} WHERE day < :cutoffday ORDER BY day ASC",
            ['cutoffday' => $cutoffday],
            0,
            $batch
        );
        if (empty($ids)) {
            return 0;
        }
        [$isql, $iparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'p');
        $DB->delete_records_select($table, "id $isql", $iparams);
        return count($ids);
    }
}
