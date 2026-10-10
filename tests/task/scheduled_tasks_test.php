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
 * Tests for the retention tasks and the service-wrapper tasks.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\audit\recompute_log;

/**
 * Two retention tasks that own real deletion logic, plus the three thin
 * wrappers over services that already have their own tests.
 *
 * The wrappers get a smoke test rather than a suite: duplicating
 * pending_recomputer_test, site_stats_service_test and trend_service_test here
 * would add ceremony, not safety. What is worth pinning is that each task
 * actually runs and is safe to run twice, since cron will.
 *
 * @covers \block_feedback_tracker\task\prune_audit_log
 * @covers \block_feedback_tracker\task\purge_calendar_cache
 * @covers \block_feedback_tracker\task\recompute_pending
 * @covers \block_feedback_tracker\task\recompute_site_stats
 * @covers \block_feedback_tracker\task\recompute_trend
 */
final class scheduled_tasks_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * Run a task with its console output swallowed.
     *
     * @param \core\task\scheduled_task $task
     * @return void
     */
    private function run_task(\core\task\scheduled_task $task): void {
        ob_start();
        $task->execute();
        ob_end_clean();
    }

    /**
     * Audit rows older than the retention window go; recent ones stay.
     *
     * @return void
     */
    public function test_prune_audit_log_applies_the_retention_window(): void {
        global $DB;
        $this->resetAfterTest();

        $now = time();
        $ancient = recompute_log::record('cron', 1, null, null, $now - (400 * 86400), $now - (400 * 86400));
        $recent = recompute_log::record('cron', 1, null, null, $now - 60, $now - 60);

        $this->run_task(new prune_audit_log());

        $this->assertFalse($DB->record_exists('block_feedback_tracker_log', ['id' => $ancient]));
        $this->assertTrue($DB->record_exists('block_feedback_tracker_log', ['id' => $recent]));
    }

    /**
     * Pruning an empty table is safe.
     *
     * @return void
     */
    public function test_prune_audit_log_on_empty_table(): void {
        global $DB;
        $this->resetAfterTest();

        $this->run_task(new prune_audit_log());

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_log'));
    }

    /**
     * Closed pause windows and calendar days older than the retention window
     * are dropped; anything inside it survives.
     *
     * @return void
     */
    public function test_purge_calendar_cache_drops_only_stale_rows(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('purge_inactive_after_days', 30, 'block_feedback_tracker');

        $now = time();
        $stale = $this->generator()->create_pause_window([
            'timestart' => $now - (200 * 86400),
            'timeend' => $now - (100 * 86400),
        ]);
        $fresh = $this->generator()->create_pause_window([
            'timestart' => $now - 7200,
            'timeend' => $now - 3600,
        ]);
        $openended = $this->generator()->create_pause_window([
            'timestart' => $now - (200 * 86400),
            'timeend' => null,
        ]);

        $staleday = $this->generator()->create_calendar_day(20200101, 'holiday', null);
        $futureday = $this->generator()->create_calendar_day(20990101, 'holiday', null);

        $this->run_task(new purge_calendar_cache());

        $this->assertFalse($DB->record_exists('block_feedback_tracker_cpause', ['id' => $stale]));
        $this->assertTrue($DB->record_exists('block_feedback_tracker_cpause', ['id' => $fresh]));
        $this->assertTrue(
            $DB->record_exists('block_feedback_tracker_cpause', ['id' => $openended]),
            'An open-ended window has no end date and must never be purged by age.'
        );
        $this->assertFalse($DB->record_exists('block_feedback_tracker_cday', ['id' => $staleday]));
        $this->assertTrue($DB->record_exists('block_feedback_tracker_cday', ['id' => $futureday]));
    }

    /**
     * A non-positive retention setting falls back to the default rather than
     * purging everything.
     *
     * @return void
     */
    public function test_purge_calendar_cache_ignores_a_zero_retention_setting(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('purge_inactive_after_days', 0, 'block_feedback_tracker');

        $now = time();
        $fresh = $this->generator()->create_pause_window([
            'timestart' => $now - 7200,
            'timeend' => $now - 3600,
        ]);

        $this->run_task(new purge_calendar_cache());

        $this->assertTrue(
            $DB->record_exists('block_feedback_tracker_cpause', ['id' => $fresh]),
            'A zero setting must fall back to the default window, not purge everything.'
        );
    }

    /**
     * The three service wrappers run without error and are idempotent, which
     * is all a wrapper owes: the arithmetic is covered by the service tests.
     *
     * @return void
     */
    public function test_service_wrapper_tasks_run_and_are_repeatable(): void {
        $this->resetAfterTest();
        $this->generator()->seed_default_platform_calendar();
        $this->generator()->create_rollup_row(['courseid' => 970, 'groupid' => 0]);

        foreach ([new recompute_pending(), new recompute_site_stats(), new recompute_trend()] as $task) {
            $this->run_task($task);
            $this->run_task($task);
        }

        $this->assertTrue(true, 'Each wrapper task completed twice without error.');
    }

    /**
     * Every scheduled task declared in db/tasks.php resolves to a real class
     * with a localised name, so a rename cannot leave cron pointing at
     * nothing.
     *
     * @return void
     */
    public function test_declared_scheduled_tasks_are_resolvable(): void {
        $tasks = [];
        require(__DIR__ . '/../../db/tasks.php');

        $this->assertNotEmpty($tasks);
        foreach ($tasks as $def) {
            $classname = (string) $def['classname'];
            $this->assertTrue(class_exists($classname), "Scheduled task class {$classname} does not exist");
            $instance = new $classname();
            $this->assertNotSame('', trim($instance->get_name()), "{$classname} has no display name");
        }
    }
}
