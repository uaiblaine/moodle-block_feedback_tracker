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
 * Scheduled task: backfill the per-submission effectivedays column.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\audit\recompute_log;
use block_feedback_tracker\local\calendar\day_counter;
use block_feedback_tracker\local\sla\process_memos;

/**
 * One-time, resumable backfill of {block_feedback_tracker_sub}.effectivedays.
 *
 * The upgrade step that adds the column leaves it NULL on existing ledger
 * rows; a later step arms this task by setting
 * `effectivedays_backfill_done = 0`, so the column is filled in the
 * background rather than by one UPDATE per row inside the upgrade, which is
 * slow on large sites.
 *
 * Each tick:
 *  - keyset-pages past `effectivedays_backfill_lastid` for rows still missing
 *    the value (a primary-key range scan, so filled low ids are not re-read),
 *  - computes each row's elapsed business days under a soft time cap,
 *  - applies one `UPDATE ... WHERE id IN` per distinct value inside a single
 *    transaction, then advances the cursor.
 *
 * When a tick finds no row past its cursor it sets the done flag, and every
 * later tick is a single get_config() no-op. A fresh install never arms the
 * flag (the ledger writer fills the column from the first row), so the task
 * is inert there.
 *
 * Dashboard day counts do not depend on this backfill: the rollup computes
 * them from timestamps. This task only restores the per-row column, which the
 * ledger otherwise maintains wherever it writes effectivehours.
 */
class backfill_effectivedays extends \core\task\scheduled_task {
    /** Default rows fetched per tick. */
    public const DEFAULT_BATCH_SIZE = 5000;
    /** Default soft time cap (seconds). Shared key with the other batch tasks. */
    public const DEFAULT_TIME_CAP = 50;
    /** Rows per UPDATE ... WHERE id IN (...) chunk. */
    private const UPDATE_CHUNK = 1000;

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_backfill_effectivedays', 'block_feedback_tracker');
    }

    /**
     * Backfill one batch of rows whose effectivedays is still NULL.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;
        process_memos::reset();

        // Not armed (fresh installs) or already finished → cheap no-op.
        $done = get_config('block_feedback_tracker', 'effectivedays_backfill_done');
        if ($done === false || $done === '1') {
            return;
        }

        $started = time();
        $now = $started;
        $batchsize = (int) (get_config('block_feedback_tracker', 'effectivedays_batch_size') ?: self::DEFAULT_BATCH_SIZE);
        if ($batchsize < 1) {
            $batchsize = self::DEFAULT_BATCH_SIZE;
        }
        $timecap = (int) (get_config('block_feedback_tracker', 'drain_time_cap_seconds') ?: self::DEFAULT_TIME_CAP);
        $deadline = $started + $timecap;
        $lastid = (int) (get_config('block_feedback_tracker', 'effectivedays_backfill_lastid') ?: 0);

        // Keyset page: rows past the cursor still missing the column. Rows
        // with timesubmitted = 0 are intentionally excluded (the ledger
        // leaves their effectivedays NULL by design), so they never block
        // completion — the cursor walks straight past them.
        $rows = $DB->get_records_select(
            'block_feedback_tracker_sub',
            'id > :lastid AND effectivedays IS NULL AND timesubmitted > 0',
            ['lastid' => $lastid],
            'id ASC',
            'id, timesubmitted, timegraded',
            0,
            $batchsize
        );

        if (empty($rows)) {
            // Nothing left past the cursor → backfill complete. Subsequent
            // ticks short-circuit on the done flag above.
            set_config('effectivedays_backfill_done', '1', 'block_feedback_tracker');
            mtrace('backfill_effectivedays: no rows past cursor — marked COMPLETE.');
            return;
        }

        // Group ids by computed day-count so N row updates collapse into one
        // UPDATE per distinct value.
        $buckets = [];
        $lastappliedid = $lastid;
        $processed = 0;
        foreach ($rows as $r) {
            if (time() > $deadline) {
                break;
            }
            $upper = $r->timegraded !== null ? (int) $r->timegraded : $now;
            $days = day_counter::business_days((int) $r->timesubmitted, $upper);
            $buckets[$days][] = (int) $r->id;
            $lastappliedid = (int) $r->id;
            $processed++;
        }

        if ($processed === 0) {
            // The page fetch alone burned the time cap; retry next tick
            // without advancing the cursor.
            mtrace('backfill_effectivedays: time cap reached before any row processed — retrying next tick.');
            return;
        }

        $transaction = $DB->start_delegated_transaction();
        foreach ($buckets as $days => $ids) {
            foreach (array_chunk($ids, self::UPDATE_CHUNK) as $chunk) {
                [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'bf');
                $DB->set_field_select(
                    'block_feedback_tracker_sub',
                    'effectivedays',
                    $days,
                    "id $insql",
                    $inparams
                );
            }
        }
        $transaction->allow_commit();

        set_config('effectivedays_backfill_lastid', (string) $lastappliedid, 'block_feedback_tracker');

        recompute_log::record(
            recompute_log::REASON_BACKFILL_DAYS,
            $processed,
            null,
            [
                'lastid'  => $lastappliedid,
                'buckets' => count($buckets),
                'took_ms' => (time() - $started) * 1000,
            ],
            $started,
            time()
        );

        mtrace(sprintf(
            'backfill_effectivedays: filled %d row(s) in %d distinct value bucket(s); cursor->%d.',
            $processed,
            count($buckets),
            $lastappliedid
        ));
    }
}
