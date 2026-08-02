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
 * Tests for the one-time effectivedays backfill.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

/**
 * A resumable upgrade backfill: armed by config, walked with a keyset cursor,
 * finished by flipping a done flag. None of that was covered, and a paging or
 * arming defect would silently leave NULL effectivedays on an upgraded site —
 * which is the condition that makes the day-mode bands fall back rather than
 * read real data.
 *
 * @covers \block_feedback_tracker\task\backfill_effectivedays
 */
final class backfill_effectivedays_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * Arm the backfill the way the upgrade step does.
     *
     * @param int|null $batchsize
     * @return void
     */
    private function arm(?int $batchsize = null): void {
        set_config('effectivedays_backfill_done', '0', 'block_feedback_tracker');
        set_config('effectivedays_backfill_lastid', '0', 'block_feedback_tracker');
        if ($batchsize !== null) {
            set_config('effectivedays_batch_size', (string) $batchsize, 'block_feedback_tracker');
        }
    }

    /**
     * Run one tick.
     *
     * @return void
     */
    private function tick(): void {
        ob_start();
        (new backfill_effectivedays())->execute();
        ob_end_clean();
    }

    /**
     * Seed rows that still need a day count.
     *
     * @param int $count
     * @return int[] Row ids.
     */
    private function seed_rows(int $count): array {
        $now = time();
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->generator()->create_ledger_row([
                'courseid' => 900 + $i,
                'userid' => $i + 1,
                'timesubmitted' => $now - (86400 * ($i + 2)),
                'timegraded' => $now - 3600,
            ]);
        }
        return $ids;
    }

    /**
     * Read the current done flag.
     *
     * @return string|false
     */
    private function done_flag() {
        return get_config('block_feedback_tracker', 'effectivedays_backfill_done');
    }

    /**
     * On a fresh install the flag is absent, and the task must not touch
     * anything — arming is the upgrade step's job, not the task's.
     *
     * @return void
     */
    public function test_unarmed_is_a_noop(): void {
        global $DB;
        $this->resetAfterTest();

        $ids = $this->seed_rows(2);
        unset_config('effectivedays_backfill_done', 'block_feedback_tracker');

        $this->tick();

        foreach ($ids as $id) {
            $this->assertNull($DB->get_field('block_feedback_tracker_sub', 'effectivedays', ['id' => $id]));
        }
        $this->assertFalse($this->done_flag(), 'An unarmed backfill must not arm itself.');
    }

    /**
     * Once finished the task short-circuits rather than re-walking the table.
     *
     * @return void
     */
    public function test_already_done_is_a_noop(): void {
        global $DB;
        $this->resetAfterTest();

        $ids = $this->seed_rows(2);
        set_config('effectivedays_backfill_done', '1', 'block_feedback_tracker');

        $this->tick();

        $this->assertNull($DB->get_field('block_feedback_tracker_sub', 'effectivedays', ['id' => $ids[0]]));
    }

    /**
     * An armed run fills the column and records progress.
     *
     * @return void
     */
    public function test_armed_run_fills_the_column(): void {
        global $DB;
        $this->resetAfterTest();

        $ids = $this->seed_rows(3);
        $this->arm();

        $this->tick();

        foreach ($ids as $id) {
            $this->assertNotNull(
                $DB->get_field('block_feedback_tracker_sub', 'effectivedays', ['id' => $id]),
                'Every row past the cursor should have been filled.'
            );
        }
    }

    /**
     * The cursor advances across ticks and each tick picks up where the last
     * one stopped — the property that makes the walk resumable rather than
     * restarting from the top.
     *
     * @return void
     */
    public function test_keyset_cursor_advances_across_ticks(): void {
        global $DB;
        $this->resetAfterTest();

        $ids = $this->seed_rows(5);
        $this->arm(2);

        $this->tick();
        $firstcursor = (int) get_config('block_feedback_tracker', 'effectivedays_backfill_lastid');
        $this->assertSame($ids[1], $firstcursor, 'The cursor should sit on the last row of the first batch.');
        $filledafterone = $DB->count_records_select('block_feedback_tracker_sub', 'effectivedays IS NOT NULL');
        $this->assertSame(2, $filledafterone);

        $this->tick();
        $secondcursor = (int) get_config('block_feedback_tracker', 'effectivedays_backfill_lastid');
        $this->assertGreaterThan($firstcursor, $secondcursor);
        $this->assertSame(4, $DB->count_records_select('block_feedback_tracker_sub', 'effectivedays IS NOT NULL'));
    }

    /**
     * When no rows remain past the cursor the run marks itself complete, and
     * the flag is what stops every later tick.
     *
     * @return void
     */
    public function test_exhaustion_flips_the_done_flag(): void {
        $this->resetAfterTest();

        $this->seed_rows(2);
        $this->arm(10);

        $this->tick();
        $this->assertSame('0', $this->done_flag(), 'Filling a batch is not yet completion.');

        $this->tick();
        $this->assertSame('1', $this->done_flag(), 'An empty page past the cursor means complete.');
    }

    /**
     * Rows with no submission time are excluded by design — the ledger leaves
     * their day count NULL deliberately, so they must not stall completion.
     *
     * @return void
     */
    public function test_rows_without_a_submission_time_never_block_completion(): void {
        global $DB;
        $this->resetAfterTest();

        $skipped = $this->generator()->create_ledger_row([
            'courseid' => 950,
            'userid' => 1,
            'timesubmitted' => 0,
            'timegraded' => null,
        ]);
        $this->arm(10);

        $this->tick();
        $this->tick();

        $this->assertSame('1', $this->done_flag());
        $this->assertNull(
            $DB->get_field('block_feedback_tracker_sub', 'effectivedays', ['id' => $skipped]),
            'A row with no submission time keeps its NULL day count.'
        );
    }

    /**
     * A row that already carries a day count is left alone — the walk fills
     * gaps, it does not recompute.
     *
     * @return void
     */
    public function test_existing_values_are_not_overwritten(): void {
        global $DB;
        $this->resetAfterTest();

        $now = time();
        $id = $this->generator()->create_ledger_row([
            'courseid' => 960,
            'userid' => 1,
            'timesubmitted' => $now - (86400 * 9),
            'timegraded' => $now,
            'effectivedays' => 99.0,
        ]);
        $this->arm();

        $this->tick();

        $this->assertSame(
            99.0,
            (float) $DB->get_field('block_feedback_tracker_sub', 'effectivedays', ['id' => $id])
        );
    }

    /**
     * A completed run writes an audit entry so the backfill is visible in the
     * recompute log rather than happening silently.
     *
     * @return void
     */
    public function test_progress_is_recorded_in_the_audit_log(): void {
        global $DB;
        $this->resetAfterTest();

        $this->seed_rows(2);
        $this->arm();

        $this->tick();

        $this->assertTrue(
            $DB->record_exists(
                'block_feedback_tracker_log',
                ['reason' => \block_feedback_tracker\local\audit\recompute_log::REASON_BACKFILL_DAYS]
            ),
            'The backfill should leave an audit trail.'
        );
    }
}
