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
 * Request cost of the observers that re-derive a whole activity.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\task\rederive_activity;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Saving an assignment's settings and revealing its identities used to read
 * the activity's rows and queue their backfill tasks inside the teacher's
 * request. The walk now runs in {@see rederive_activity}; these tests hold the
 * request to a cost that does not grow with the rows, so a loop over them
 * cannot come back unseen. `tests/perf/observer_cost_bench.php` measures the
 * same handlers at scale.
 *
 * @covers \block_feedback_tracker\local\sla\observer
 * @covers \block_feedback_tracker\task\rederive_activity
 */
final class observer_cost_test extends \advanced_testcase {
    /** @var \stdClass Tracked course of the test. */
    private \stdClass $course;

    /** @var \stdClass Assign course module of the test. */
    private \stdClass $cm;

    /**
     * Saving the settings costs as many statements with 60 ledger rows as
     * with 3, and the walk it queued still reaches all 60.
     *
     * @return void
     */
    public function test_settings_save_cost_does_not_grow_with_the_rows(): void {
        $this->resetAfterTest();
        $this->setup_activity();
        $fire = fn() => observer::course_module_updated(\core\event\course_module_updated::create([
            'courseid' => (int) $this->course->id,
            'context' => \context_module::instance((int) $this->cm->id),
            'objectid' => (int) $this->cm->id,
            'other' => ['modulename' => 'assign', 'instanceid' => (int) $this->cm->instance, 'name' => 'Cost'],
        ]));

        $this->assert_flat_cost($fire, fn(int $n) => $this->seed_ledger($n));
    }

    /**
     * Revealing identities costs as many statements with 60 submissions as
     * with 3, and the walk it queued still reaches all 60.
     *
     * @return void
     */
    public function test_identities_reveal_cost_does_not_grow_with_the_rows(): void {
        $this->resetAfterTest();
        $this->setup_activity();
        $context = \context_module::instance((int) $this->cm->id);
        $event = \mod_assign\event\identities_revealed::create_from_assign(new \assign($context, $this->cm, $this->course));
        $fire = fn() => observer::identities_revealed($event);

        $this->assert_flat_cost($fire, fn(int $n) => $this->seed_submissions($n));
    }

    /**
     * Measure the handler over 3 and over 60 rows, then run the walk.
     *
     * @param callable $fire Runs the handler.
     * @param callable $seed Takes N, inserts N rows behind the handler.
     * @return void
     */
    private function assert_flat_cost(callable $fire, callable $seed): void {
        // Warm up first: the first run in a test fills caches the later runs read.
        $this->statements($fire, $seed, 1);
        $few = $this->statements($fire, $seed, 3);
        $many = $this->statements($fire, $seed, 60);

        $this->assertSame($few, $many, 'The request must not touch each row.');
        // Control: the walk the request queued reaches every row.
        ob_start();
        $this->runAdhocTasks(rederive_activity::class);
        ob_end_clean();
        $descriptors = 0;
        foreach (\core\task\manager::get_adhoc_tasks('\\block_feedback_tracker\\task\\backfill_one_submission') as $task) {
            $descriptors += count((array) $task->get_custom_data()->rows);
        }
        $this->assertSame(60, $descriptors);
    }

    /**
     * Statements one handler run costs over N fresh rows.
     *
     * @param callable $fire Runs the handler.
     * @param callable $seed Takes N, inserts N rows behind the handler.
     * @param int $n Rows to seed.
     * @return int Reads plus writes.
     */
    private function statements(callable $fire, callable $seed, int $n): int {
        global $DB;
        $DB->delete_records('block_feedback_tracker_sub');
        $DB->delete_records('assign_submission');
        $DB->delete_records('task_adhoc');
        $seed($n);
        course_access::reset_memo();

        $before = $DB->perf_get_reads() + $DB->perf_get_writes();
        $fire();
        return $DB->perf_get_reads() + $DB->perf_get_writes() - $before;
    }

    /**
     * Create the tracked course and its assign activity.
     *
     * @return void
     */
    private function setup_activity(): void {
        $this->course = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')->create_tracked_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('assign', (int) $assign->id);
    }

    /**
     * Insert N ledger rows on the activity, one per synthetic student.
     *
     * @param int $n
     * @return void
     */
    private function seed_ledger(int $n): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        for ($i = 1; $i <= $n; $i++) {
            $generator->create_ledger_row([
                'courseid' => (int) $this->course->id,
                'cmid' => (int) $this->cm->id,
                'iteminstance' => (int) $this->cm->instance,
                'userid' => 9000 + $i,
            ]);
        }
    }

    /**
     * Insert N submitted assign_submission rows, one per synthetic student.
     *
     * @param int $n
     * @return void
     */
    private function seed_submissions(int $n): void {
        global $DB;
        for ($i = 1; $i <= $n; $i++) {
            $DB->insert_record('assign_submission', (object) [
                'assignment' => (int) $this->cm->instance,
                'userid' => 9000 + $i,
                'attemptnumber' => 0,
                'timecreated' => time(),
                'timemodified' => time(),
                'status' => 'submitted',
                'groupid' => 0,
                'latest' => 1,
            ]);
        }
    }
}
