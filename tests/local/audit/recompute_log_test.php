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
 * Tests for the recompute audit log.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\audit;

/**
 * The retention cutoff runs on both supported database families and had no
 * coverage at all, so an off-by-one in the comparison would delete a day too
 * much (or too little) unnoticed.
 *
 * @covers \block_feedback_tracker\local\audit\recompute_log
 */
final class recompute_log_test extends \advanced_testcase {
    /**
     * A row is written with the fields the caller supplied, and details are
     * stored as JSON.
     *
     * @return void
     */
    public function test_record_writes_the_row(): void {
        global $DB;
        $this->resetAfterTest();

        $started = time() - 120;
        $id = recompute_log::record('manual', 7, null, ['source' => 'test', 'n' => 3], $started, $started + 5);

        $row = $DB->get_record('block_feedback_tracker_log', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('manual', $row->reason);
        $this->assertSame(7, (int) $row->affectedrows);
        $this->assertNull($row->triggeredby);
        $this->assertSame($started, (int) $row->timestarted);
        $this->assertSame($started + 5, (int) $row->timefinished);
        $this->assertSame(['source' => 'test', 'n' => 3], json_decode($row->details, true));
    }

    /**
     * Omitting the timestamps stamps them, and a null details payload stays
     * null rather than becoming the string "null".
     *
     * @return void
     */
    public function test_record_defaults(): void {
        global $DB;
        $this->resetAfterTest();

        $before = time();
        $id = recompute_log::record('cron', 0);
        $row = $DB->get_record('block_feedback_tracker_log', ['id' => $id], '*', MUST_EXIST);

        $this->assertNull($row->details);
        $this->assertGreaterThanOrEqual($before, (int) $row->timestarted);
        $this->assertGreaterThanOrEqual($before, (int) $row->timefinished);
    }

    /**
     * The actor is recorded when supplied.
     *
     * @return void
     */
    public function test_record_stores_the_actor(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $id = recompute_log::record('manual', 1, (int) $user->id);

        $row = $DB->get_record('block_feedback_tracker_log', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame((int) $user->id, (int) $row->triggeredby);
    }

    /**
     * Pruning removes rows started before the cutoff and keeps the rest.
     *
     * @return void
     */
    public function test_prune_removes_only_rows_older_than_the_cutoff(): void {
        global $DB;
        $this->resetAfterTest();

        $now = time();
        $old = recompute_log::record('cron', 1, null, null, $now - 1000, $now - 1000);
        $older = recompute_log::record('cron', 1, null, null, $now - 2000, $now - 2000);
        $recent = recompute_log::record('cron', 1, null, null, $now - 10, $now - 10);

        $removed = recompute_log::prune_older_than($now - 500);

        $this->assertSame(2, $removed);
        $this->assertFalse($DB->record_exists('block_feedback_tracker_log', ['id' => $old]));
        $this->assertFalse($DB->record_exists('block_feedback_tracker_log', ['id' => $older]));
        $this->assertTrue($DB->record_exists('block_feedback_tracker_log', ['id' => $recent]));
    }

    /**
     * The comparison is strictly less-than, so a row sitting exactly on the
     * cutoff survives. Getting this backwards silently shortens retention by
     * a whole tick.
     *
     * @return void
     */
    public function test_prune_keeps_a_row_exactly_on_the_cutoff(): void {
        global $DB;
        $this->resetAfterTest();

        $cutoff = time() - 100;
        $onboundary = recompute_log::record('cron', 1, null, null, $cutoff, $cutoff);
        $justbefore = recompute_log::record('cron', 1, null, null, $cutoff - 1, $cutoff - 1);

        $removed = recompute_log::prune_older_than($cutoff);

        $this->assertSame(1, $removed);
        $this->assertTrue($DB->record_exists('block_feedback_tracker_log', ['id' => $onboundary]));
        $this->assertFalse($DB->record_exists('block_feedback_tracker_log', ['id' => $justbefore]));
    }

    /**
     * Pruning an empty window is a no-op that reports zero.
     *
     * @return void
     */
    public function test_prune_with_nothing_to_remove(): void {
        global $DB;
        $this->resetAfterTest();

        $now = time();
        recompute_log::record('cron', 1, null, null, $now, $now);

        $this->assertSame(0, recompute_log::prune_older_than($now - 5000));
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_log'));
    }
}
