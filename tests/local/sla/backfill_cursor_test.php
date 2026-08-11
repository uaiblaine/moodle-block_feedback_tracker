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
 * Tests for the per-course backfill_cursor helper.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Wraps {block_feedback_tracker_bfcursor}. Get-or-create / advance /
 * reset / enable / disable / delete primitives that the
 * backfill_history dispatcher and the per-course CLI tool consume.
 *
 * @covers \block_feedback_tracker\local\sla\backfill_cursor
 */
final class backfill_cursor_test extends \advanced_testcase {
    /**
     * get_or_create lazily inserts a row with cursor=0 + active=1 the
     * first time it's called for a courseid, and returns the existing
     * row on subsequent calls.
     */
    public function test_get_or_create_lazily_inserts_then_returns_existing(): void {
        $this->resetAfterTest();
        global $DB;
        $courseid = 4242;

        $this->assertFalse($DB->record_exists('block_feedback_tracker_bfcursor', ['courseid' => $courseid]));
        $row = backfill_cursor::get_or_create($courseid);
        $this->assertSame($courseid, (int) $row->courseid);
        $this->assertSame(0, (int) $row->lastsubid);
        $this->assertSame(1, (int) $row->active);
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_bfcursor', ['courseid' => $courseid]));

        // Idempotent.
        $row2 = backfill_cursor::get_or_create($courseid);
        $this->assertSame((int) $row->id, (int) $row2->id);
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_bfcursor', ['courseid' => $courseid]));
    }

    /**
     * advance() updates cursor + lastrunat. When complete=true it also
     * sets active=0 so the dispatcher skips this course next tick.
     */
    public function test_advance_updates_cursor_and_completion_flag(): void {
        $this->resetAfterTest();
        global $DB;
        $courseid = 5050;

        backfill_cursor::advance($courseid, 100, false);
        $row = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
        $this->assertSame(100, (int) $row->lastsubid);
        $this->assertSame(1, (int) $row->active);
        $this->assertNotNull($row->lastrunat);

        backfill_cursor::advance($courseid, 250, true);
        $row = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
        $this->assertSame(250, (int) $row->lastsubid);
        $this->assertSame(0, (int) $row->active, 'complete=true must flip active=0.');
    }

    /**
     * reset() flips cursor=0 + active=1 + lastrunat=null without
     * deleting the row.
     */
    public function test_reset_returns_row_to_cursor_zero_active_one(): void {
        $this->resetAfterTest();
        global $DB;
        $courseid = 6060;

        backfill_cursor::advance($courseid, 999, true);
        backfill_cursor::reset($courseid);
        $row = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
        $this->assertSame(0, (int) $row->lastsubid);
        $this->assertSame(1, (int) $row->active);
        $this->assertNull($row->lastrunat);
    }

    /**
     * disable / enable toggle active without changing cursor.
     */
    public function test_disable_enable_toggle_active_without_touching_cursor(): void {
        $this->resetAfterTest();
        global $DB;
        $courseid = 7070;

        backfill_cursor::advance($courseid, 12345, false);
        backfill_cursor::disable($courseid);
        $row = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
        $this->assertSame(0, (int) $row->active);
        $this->assertSame(12345, (int) $row->lastsubid, 'disable() must not touch cursor.');

        backfill_cursor::enable($courseid);
        $row = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
        $this->assertSame(1, (int) $row->active);
        $this->assertSame(12345, (int) $row->lastsubid, 'enable() must not touch cursor.');
    }

    /**
     * active_rows() returns only active=1 rows, ordered by courseid ASC.
     * all_rows() returns every row regardless of active.
     */
    public function test_active_rows_vs_all_rows(): void {
        $this->resetAfterTest();
        backfill_cursor::get_or_create(3);
        backfill_cursor::get_or_create(1);
        backfill_cursor::advance(2, 50, true);

        $active = backfill_cursor::active_rows();
        $activecourseids = array_map(static fn($r) => (int) $r->courseid, array_values($active));
        $this->assertSame([1, 3], $activecourseids, 'active_rows() excludes active=0; ordered courseid ASC.');

        $all = backfill_cursor::all_rows();
        $allcourseids = array_map(static fn($r) => (int) $r->courseid, array_values($all));
        $this->assertSame([1, 2, 3], $allcourseids);
    }

    /**
     * delete() removes the row. Called from the course_deleted cleanup
     * chain so a deleted course doesn't leave orphaned cursor rows.
     */
    public function test_delete_removes_row(): void {
        $this->resetAfterTest();
        global $DB;
        $courseid = 9090;

        backfill_cursor::get_or_create($courseid);
        $this->assertTrue($DB->record_exists('block_feedback_tracker_bfcursor', ['courseid' => $courseid]));

        backfill_cursor::delete($courseid);
        $this->assertFalse($DB->record_exists('block_feedback_tracker_bfcursor', ['courseid' => $courseid]));
    }

    /**
     * Bulk creation adds the missing rows and leaves the existing ones alone.
     *
     * The second half is the part that matters. The dispatcher calls this once
     * a tick for every tracked course, so a version that wrote over rows it
     * found would restart the backfill of every completed course on the next
     * tick, for ever — and it would look like the backfill simply never
     * finishing rather than like a bug.
     *
     * @return void
     */
    public function test_ensure_for_courses_creates_only_what_is_missing(): void {
        global $DB;
        $this->resetAfterTest();

        // One course that has already finished its backfill.
        $done = (int) $this->getDataGenerator()->create_course()->id;
        backfill_cursor::get_or_create($done);
        backfill_cursor::advance($done, 4242, true);
        $before = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $done]);
        $this->assertSame(0, (int) $before->active, 'Sanity: this course is complete.');

        $fresh = (int) $this->getDataGenerator()->create_course()->id;
        $second = (int) $this->getDataGenerator()->create_course()->id;

        backfill_cursor::ensure_for_courses([$done, $fresh, $second]);

        $this->assertSame(3, $DB->count_records('block_feedback_tracker_bfcursor'));
        foreach ([$fresh, $second] as $courseid) {
            $row = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
            $this->assertNotEmpty($row, 'A course with no cursor gets one.');
            $this->assertSame(1, (int) $row->active, 'And it starts active.');
            $this->assertSame(0, (int) $row->lastsubid);
        }

        $after = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $done]);
        $this->assertSame((int) $before->id, (int) $after->id, 'The finished course keeps its row.');
        $this->assertSame(0, (int) $after->active, 'And stays finished.');
        $this->assertSame(4242, (int) $after->lastsubid, 'And keeps its position.');

        // Running it again changes nothing at all.
        backfill_cursor::ensure_for_courses([$done, $fresh, $second]);
        $this->assertSame(3, $DB->count_records('block_feedback_tracker_bfcursor'));
    }

    /**
     * A repeated course id in the input produces one row, not a unique-key
     * violation that takes its whole insert batch down with it.
     *
     * @return void
     */
    public function test_ensure_for_courses_tolerates_a_repeated_id(): void {
        global $DB;
        $this->resetAfterTest();

        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $other = (int) $this->getDataGenerator()->create_course()->id;

        backfill_cursor::ensure_for_courses([$courseid, $courseid, $other]);

        $this->assertSame(1, $DB->count_records('block_feedback_tracker_bfcursor', ['courseid' => $courseid]));
        $this->assertSame(
            1,
            $DB->count_records('block_feedback_tracker_bfcursor', ['courseid' => $other]),
            'Control: the rest of the batch survived, which is what a failed insert would have taken out.'
        );
    }

    /**
     * An empty course list is a no-op, not an empty insert.
     *
     * @return void
     */
    public function test_ensure_for_courses_with_nothing_to_do(): void {
        global $DB;
        $this->resetAfterTest();

        backfill_cursor::ensure_for_courses([]);

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_bfcursor'));
    }
}
