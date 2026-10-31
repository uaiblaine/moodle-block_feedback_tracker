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
 * Tests for the requeue_rollups adhoc task.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\calendar\calendar;
use block_feedback_tracker\local\sla\dirty_queue;

/**
 * The task re-queues every rollup tuple of its scope, stamped with the
 * reason it was queued for, and walks again while the calendar version moves
 * under it: an edit made during the walk finds the task already queued and
 * queues nothing of its own.
 *
 * @covers \block_feedback_tracker\task\requeue_rollups
 */
final class requeue_rollups_test extends \advanced_testcase {
    /**
     * Buffer the task's mtrace() output, which Moodle's PHPUnit configuration
     * (beStrictAboutOutputDuringTests) reports as a risky test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        ob_start();
    }

    /**
     * Tear down the per-test output buffer.
     *
     * @return void
     */
    protected function tearDown(): void {
        ob_end_clean();
        parent::tearDown();
    }

    /**
     * Two courses with tuples: 8001 has groups 11 and 12, 8002 has group 21.
     *
     * @return void
     */
    private function seed_rollups(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        foreach ([[8001, 11], [8001, 12], [8002, 21]] as [$courseid, $groupid]) {
            $generator->create_rollup_row(['courseid' => $courseid, 'groupid' => $groupid]);
        }
    }

    /**
     * Build a task for a scope.
     *
     * @param int $courseid
     * @param string $reason
     * @return requeue_rollups
     */
    private function task(int $courseid, string $reason): requeue_rollups {
        $task = new requeue_rollups();
        $task->set_custom_data(['courseid' => $courseid, 'reason' => $reason]);
        return $task;
    }

    /**
     * A site walk queues every tuple with the reason it was queued for.
     *
     * @return void
     */
    public function test_site_walk_queues_every_tuple_with_its_reason(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_rollups();

        $this->task(0, dirty_queue::REASON_PAUSE)->execute();

        $this->assertSame(3, $DB->count_records('block_feedback_tracker_queue', ['reason' => dirty_queue::REASON_PAUSE]));
    }

    /**
     * A course walk queues that course's tuples only.
     *
     * @return void
     */
    public function test_course_walk_stays_in_its_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_rollups();

        $this->task(8001, dirty_queue::REASON_PAUSE)->execute();

        $this->assertSame(2, $DB->count_records('block_feedback_tracker_queue', ['courseid' => 8001]));
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_queue', ['courseid' => 8002]));
        $this->assertSame(2, requeue_rollups::count_tuples(8001));
        $this->assertSame(3, requeue_rollups::count_tuples(0));
    }

    /**
     * When the calendar moves during a walk, the task walks again, so the
     * tuples it queued before the edit are queued once more after it.
     *
     * @return void
     */
    public function test_walks_again_while_the_calendar_moves(): void {
        $this->resetAfterTest();
        $this->seed_rollups();
        $task = new class extends requeue_rollups {
            /** @var int Walks made. */
            public int $walks = 0;

            /**
             * Walk, and edit the calendar during the first walk only.
             *
             * @param int $courseid
             * @param string $reason
             * @return int
             */
            protected function walk(int $courseid, string $reason): int {
                $this->walks++;
                if ($this->walks === 1) {
                    calendar::bump_version();
                }
                return parent::walk($courseid, $reason);
            }
        };
        $task->set_custom_data(['courseid' => 0, 'reason' => dirty_queue::REASON_CALENDAR]);

        $task->execute();

        $this->assertSame(2, $task->walks);
    }

    /**
     * Control for the test above: a calendar that holds still is walked once.
     *
     * @return void
     */
    public function test_walks_once_when_the_calendar_holds_still(): void {
        $this->resetAfterTest();
        $this->seed_rollups();
        $task = new class extends requeue_rollups {
            /** @var int Walks made. */
            public int $walks = 0;

            /**
             * Count the walk.
             *
             * @param int $courseid
             * @param string $reason
             * @return int
             */
            protected function walk(int $courseid, string $reason): int {
                $this->walks++;
                return parent::walk($courseid, $reason);
            }
        };
        $task->set_custom_data(['courseid' => 0, 'reason' => dirty_queue::REASON_CALENDAR]);

        $task->execute();

        $this->assertSame(1, $task->walks);
    }

    /**
     * Queueing the same scope twice keeps one task; another scope or reason
     * gets its own.
     *
     * @return void
     */
    public function test_queue_collapses_one_scope(): void {
        global $DB;
        $this->resetAfterTest();
        $classname = ['classname' => '\\' . requeue_rollups::class];

        requeue_rollups::queue(0, dirty_queue::REASON_CALENDAR);
        requeue_rollups::queue(0, dirty_queue::REASON_CALENDAR);
        $this->assertSame(1, $DB->count_records('task_adhoc', $classname));

        requeue_rollups::queue(8001, dirty_queue::REASON_PAUSE);
        requeue_rollups::queue(0, dirty_queue::REASON_PAUSE);
        $this->assertSame(3, $DB->count_records('task_adhoc', $classname));
    }
}
