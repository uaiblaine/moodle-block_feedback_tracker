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
 * Tests for the calendar event observer.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

use block_feedback_tracker\local\audit\recompute_log;
use block_feedback_tracker\local\sla\dirty_queue;

/**
 * A calendar edit changes how long every pending submission has effectively
 * been waiting, so the observer has to bump the calendar version and re-enqueue
 * the affected rollups. Its scoping is the interesting part: a site pause
 * touches everything, a course pause only that course, a group pause a single
 * tuple. Every edit also leaves one audit row and purges the calver-keyed
 * caches the bump made unreachable.
 *
 * @covers \block_feedback_tracker\local\calendar\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * Seed rollup rows so there is something for the observer to re-enqueue.
     *
     * enqueue_all_groups() walks the rollup table, so without rows the
     * observer enqueues nothing and every assertion here would pass vacuously.
     *
     * @return array [courseid => [groupid, ...]]
     */
    private function seed_rollups(): array {
        $map = [8001 => [11, 12], 8002 => [21]];
        foreach ($map as $courseid => $groupids) {
            foreach ($groupids as $groupid) {
                $this->generator()->create_rollup_row(['courseid' => $courseid, 'groupid' => $groupid]);
            }
        }
        return $map;
    }

    /**
     * Queue rows currently held.
     *
     * @return int
     */
    private function queued(): int {
        global $DB;
        return $DB->count_records('block_feedback_tracker_queue');
    }

    /**
     * Build a calendar event carrying the given payload.
     *
     * @param string $class Event class name.
     * @param array $other
     * @return \core\event\base
     */
    private function event(string $class, array $other = []): \core\event\base {
        return $class::create([
            'context' => \context_system::instance(),
            'other' => $other,
        ]);
    }

    /**
     * A day change invalidates the whole calendar, so every tuple is queued
     * and the version moves.
     *
     * @return void
     */
    public function test_day_update_bumps_version_and_enqueues_everything(): void {
        $this->resetAfterTest();
        $this->seed_rollups();

        $before = calendar::current_version();
        observer::day_updated($this->event(\block_feedback_tracker\event\cal_day_updated::class));

        $this->assertGreaterThan($before, calendar::current_version());
        $this->assertSame(3, $this->queued(), 'Every rollup tuple should be queued.');
    }

    /**
     * Business-hours changes have the same blast radius as a day change.
     *
     * @return void
     */
    public function test_hours_update_enqueues_everything(): void {
        $this->resetAfterTest();
        $this->seed_rollups();

        $before = calendar::current_version();
        observer::hours_updated($this->event(\block_feedback_tracker\event\cal_hours_updated::class));

        $this->assertGreaterThan($before, calendar::current_version());
        $this->assertSame(3, $this->queued());
    }

    /**
     * A site-scope pause affects every course.
     *
     * @return void
     */
    public function test_site_pause_enqueues_everything(): void {
        $this->resetAfterTest();
        $this->seed_rollups();

        observer::pause_updated($this->event(
            \block_feedback_tracker\event\cal_pause_updated::class,
            ['scopelevel' => 'site', 'scopeid' => 0]
        ));

        $this->assertSame(3, $this->queued());
    }

    /**
     * A course-scope pause queues only that course's tuples — the narrowing
     * that keeps one course's calendar edit from recomputing the whole site.
     *
     * @return void
     */
    public function test_course_pause_enqueues_only_that_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_rollups();

        observer::pause_updated($this->event(
            \block_feedback_tracker\event\cal_pause_updated::class,
            ['scopelevel' => 'course', 'scopeid' => 8001]
        ));

        $this->assertSame(2, $this->queued());
        $this->assertSame(2, $DB->count_records('block_feedback_tracker_queue', ['courseid' => 8001]));
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_queue', ['courseid' => 8002]));
    }

    /**
     * A group-scope pause narrows to the single tuple.
     *
     * @return void
     */
    public function test_group_pause_enqueues_one_tuple(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->generator()->create_rollup_row([
            'courseid' => (int) $course->id,
            'groupid' => (int) $group->id,
        ]);
        $this->generator()->create_rollup_row(['courseid' => (int) $course->id, 'groupid' => 0]);

        observer::pause_updated($this->event(
            \block_feedback_tracker\event\cal_pause_updated::class,
            ['scopelevel' => 'group', 'scopeid' => (int) $group->id]
        ));

        $this->assertSame(1, $this->queued());
        $this->assertTrue($DB->record_exists('block_feedback_tracker_queue', [
            'courseid' => (int) $course->id,
            'groupid' => (int) $group->id,
        ]));
    }

    /**
     * An event with no scope information falls back to the site scope rather
     * than silently enqueueing nothing.
     *
     * @return void
     */
    public function test_pause_without_scope_defaults_to_site(): void {
        $this->resetAfterTest();
        $this->seed_rollups();

        observer::pause_updated($this->event(\block_feedback_tracker\event\cal_pause_updated::class));

        $this->assertSame(3, $this->queued());
    }

    /**
     * Re-enqueueing an already-queued tuple keeps one row per tuple — the
     * queue is a set, not a log.
     *
     * @return void
     */
    public function test_repeated_events_do_not_duplicate_queue_rows(): void {
        $this->resetAfterTest();
        $this->seed_rollups();

        observer::day_updated($this->event(\block_feedback_tracker\event\cal_day_updated::class));
        observer::day_updated($this->event(\block_feedback_tracker\event\cal_day_updated::class));

        $this->assertSame(3, $this->queued(), 'The queue holds one row per tuple regardless of event count.');
    }

    /**
     * The queue row's reason records what dirtied the tuple: a calendar edit
     * or a pause.
     *
     * @return void
     */
    public function test_queue_rows_carry_the_originating_reason(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_rollups();

        observer::day_updated($this->event(\block_feedback_tracker\event\cal_day_updated::class));
        $this->assertSame(3, $DB->count_records('block_feedback_tracker_queue', [
            'reason' => dirty_queue::REASON_CALENDAR,
        ]));

        $DB->delete_records('block_feedback_tracker_queue');
        observer::pause_updated($this->event(
            \block_feedback_tracker\event\cal_pause_updated::class,
            ['scopelevel' => 'site', 'scopeid' => 0]
        ));
        $this->assertSame(3, $DB->count_records('block_feedback_tracker_queue', [
            'reason' => dirty_queue::REASON_PAUSE,
        ]));
    }

    /**
     * The single audit row the observer writes, decoded.
     *
     * @return \stdClass The row, with details decoded to an array.
     */
    private function only_audit_row(): \stdClass {
        global $DB;
        $rows = $DB->get_records('block_feedback_tracker_log');
        $this->assertCount(1, $rows, 'One calendar edit writes exactly one audit row.');
        $row = reset($rows);
        $row->details = json_decode((string) $row->details, true);
        return $row;
    }

    /**
     * A calendar-day save is logged with the number of tuples it re-queued and
     * the user who made it.
     *
     * @return void
     */
    public function test_day_save_leaves_an_audit_row(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_rollups();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_log'));

        observer::day_updated($this->event(
            \block_feedback_tracker\event\cal_day_updated::class,
            ['daydate' => 20260601, 'daytype' => 'holiday', 'rowid' => 5]
        ));

        $row = $this->only_audit_row();
        $this->assertSame(recompute_log::REASON_CALENDAR_SAVE, $row->reason);
        $this->assertSame(3, (int) $row->affectedrows);
        $this->assertSame($this->queued(), (int) $row->affectedrows);
        $this->assertSame((int) $user->id, (int) $row->triggeredby);
        $this->assertSame(20260601, $row->details['daydate']);
        $this->assertSame(calendar::current_version(), $row->details['calver']);
    }

    /**
     * A CSV import fires one event for the whole import, and it is logged as a
     * bulk import rather than as a single-day save.
     *
     * @return void
     */
    public function test_bulk_import_is_logged_as_bulk_import(): void {
        $this->resetAfterTest();
        $this->seed_rollups();

        observer::day_updated($this->event(
            \block_feedback_tracker\event\cal_day_updated::class,
            ['daydate' => 0, 'daytype' => 'bulk_import', 'saved' => 4]
        ));

        $row = $this->only_audit_row();
        $this->assertSame(recompute_log::REASON_BULK_IMPORT, $row->reason);
        $this->assertSame(3, (int) $row->affectedrows);
        $this->assertSame(4, $row->details['saved']);
    }

    /**
     * A business-hours save is logged under its own reason.
     *
     * @return void
     */
    public function test_hours_save_leaves_an_audit_row(): void {
        $this->resetAfterTest();
        $this->seed_rollups();

        observer::hours_updated($this->event(
            \block_feedback_tracker\event\cal_hours_updated::class,
            ['dayofweek' => 2, 'slots' => 1]
        ));

        $row = $this->only_audit_row();
        $this->assertSame(recompute_log::REASON_BUSINESS_HOURS_SAVE, $row->reason);
        $this->assertSame(3, (int) $row->affectedrows);
        $this->assertNull($row->triggeredby, 'An edit with no logged-in user is attributed to nobody.');
    }

    /**
     * A pause edit is logged with the count of its own scope: a course pause
     * re-queues that course's tuples only, and a pause on a group that no
     * longer exists re-queues nothing.
     *
     * @return void
     */
    public function test_pause_save_logs_the_tuples_of_its_scope(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_rollups();

        observer::pause_updated($this->event(
            \block_feedback_tracker\event\cal_pause_updated::class,
            ['scopelevel' => 'course', 'scopeid' => 8001, 'rowid' => 9]
        ));
        $row = $this->only_audit_row();
        $this->assertSame(recompute_log::REASON_PAUSE_SAVE, $row->reason);
        $this->assertSame(2, (int) $row->affectedrows);
        $this->assertSame('course', $row->details['scopelevel']);

        $DB->delete_records('block_feedback_tracker_log');
        observer::pause_updated($this->event(
            \block_feedback_tracker\event\cal_pause_updated::class,
            ['scopelevel' => 'group', 'scopeid' => 999999, 'rowid' => 10]
        ));
        $this->assertSame(0, (int) $this->only_audit_row()->affectedrows);
    }

    /**
     * Every calendar edit purges the two calver-keyed caches, which have no
     * ttl: the bump alone only makes their entries unreachable.
     *
     * @return void
     */
    public function test_calendar_edits_purge_the_calver_keyed_caches(): void {
        $this->resetAfterTest();
        $this->seed_rollups();
        $daycache = \cache::make('block_feedback_tracker', 'calendar_effective_day');
        $pausecache = \cache::make('block_feedback_tracker', 'pause_windows_by_course');

        $events = [
            'day' => fn() => observer::day_updated($this->event(\block_feedback_tracker\event\cal_day_updated::class)),
            'hours' => fn() => observer::hours_updated($this->event(\block_feedback_tracker\event\cal_hours_updated::class)),
            'pause' => fn() => observer::pause_updated($this->event(
                \block_feedback_tracker\event\cal_pause_updated::class,
                ['scopelevel' => 'course', 'scopeid' => 8001]
            )),
        ];
        foreach ($events as $label => $fire) {
            $daykey = calendar::current_version() . '_20260601';
            $pausekey = calendar::current_version() . '_8001';
            $daycache->set($daykey, ['type' => 'implicit']);
            $pausecache->set($pausekey, [(object) ['id' => 1]]);
            // Precondition: the entries are there to be purged.
            $this->assertSame(['type' => 'implicit'], $daycache->get($daykey), $label);
            $this->assertCount(1, $pausecache->get($pausekey), $label);

            $fire();

            $this->assertFalse($daycache->get($daykey), $label . ': day cache');
            $this->assertFalse($pausecache->get($pausekey), $label . ': pause cache');
        }
    }

    /**
     * With no rollup rows the observer is a no-op rather than an error — a
     * fresh site has nothing to recompute.
     *
     * @return void
     */
    public function test_no_rollups_is_a_noop(): void {
        $this->resetAfterTest();

        observer::day_updated($this->event(\block_feedback_tracker\event\cal_day_updated::class));

        $this->assertSame(0, $this->queued());
    }
}
