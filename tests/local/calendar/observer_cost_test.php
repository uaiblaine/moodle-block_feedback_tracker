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
 * Request cost of the calendar observer and the settings callback.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

use block_feedback_tracker\task\requeue_rollups;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/feedback_tracker/lib.php');

/**
 * A calendar edit or a rule-changing settings save used to queue every rollup
 * tuple inside the admin's request, two statements per tuple. The walk now
 * runs in {@see requeue_rollups}; these tests hold the request to a cost that
 * does not grow with the tuples, so a loop over them cannot come back unseen.
 * `tests/perf/observer_cost_bench.php` measures the same handlers at scale.
 *
 * @covers \block_feedback_tracker\local\calendar\observer
 * @covers \block_feedback_tracker\task\requeue_rollups
 * @covers ::block_feedback_tracker_invalidate_rollups
 */
final class observer_cost_test extends \advanced_testcase {
    /**
     * Every handler that used to walk the rollup tuples.
     *
     * @return array
     */
    public static function handlers(): array {
        $system = fn() => \context_system::instance();
        return [
            'calendar day' => [fn() => observer::day_updated(
                \block_feedback_tracker\event\cal_day_updated::create(['context' => $system(), 'other' => []])
            ), 0],
            'business hours' => [fn() => observer::hours_updated(
                \block_feedback_tracker\event\cal_hours_updated::create(['context' => $system(), 'other' => []])
            ), 0],
            'site pause' => [fn() => observer::pause_updated(
                \block_feedback_tracker\event\cal_pause_updated::create([
                    'context' => $system(),
                    'other' => ['scopelevel' => 'site', 'scopeid' => 0],
                ])
            ), 0],
            'course pause' => [fn() => observer::pause_updated(
                \block_feedback_tracker\event\cal_pause_updated::create([
                    'context' => $system(),
                    'other' => ['scopelevel' => 'course', 'scopeid' => 8001],
                ])
            ), 8001],
            'settings save' => [fn() => block_feedback_tracker_invalidate_rollups(), 0],
        ];
    }

    /**
     * The request costs as many statements with 60 tuples as with 3, and the
     * background walk still queues all 60.
     *
     * @dataProvider handlers
     * @param callable $fire Runs the handler.
     * @param int $courseid The course the tuples belong to; 0 spreads them over courses.
     * @return void
     */
    public function test_request_cost_does_not_grow_with_the_tuples(callable $fire, int $courseid): void {
        global $DB;
        $this->resetAfterTest();

        // Warm up first: the first run in a test fills caches the later runs read.
        $this->statements($fire, 1, $courseid);
        $few = $this->statements($fire, 3, $courseid);
        $many = $this->statements($fire, 60, $courseid);

        $this->assertSame($few, $many, 'The request must not touch each tuple.');
        // Control: the walk the request queued reaches every tuple.
        ob_start();
        $this->runAdhocTasks(requeue_rollups::class);
        ob_end_clean();
        $this->assertSame(60, $DB->count_records('block_feedback_tracker_queue'));
    }

    /**
     * Statements one handler run costs over N fresh rollup tuples.
     *
     * @param callable $fire Runs the handler.
     * @param int $n Tuples to seed.
     * @param int $courseid The course of the tuples; 0 gives each its own.
     * @return int Reads plus writes.
     */
    private function statements(callable $fire, int $n, int $courseid): int {
        global $DB;
        $DB->delete_records('block_feedback_tracker_group');
        $DB->delete_records('block_feedback_tracker_queue');
        $DB->delete_records('block_feedback_tracker_log');
        $DB->delete_records('task_adhoc');
        $generator = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        for ($i = 1; $i <= $n; $i++) {
            $generator->create_rollup_row(['courseid' => $courseid ?: 9000 + $i, 'groupid' => $courseid ? $i : 0]);
        }

        $before = $DB->perf_get_reads() + $DB->perf_get_writes();
        $fire();
        return $DB->perf_get_reads() + $DB->perf_get_writes() - $before;
    }
}
