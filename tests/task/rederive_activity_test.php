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
 * Tests for the rederive_activity adhoc task.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;


/**
 * The task walks one activity in windows of ids and queues a backfill
 * descriptor per (student, attempt), or per (group, attempt) on a team
 * activity, however many windows the rows span: the observers it replaced
 * stopped at 20,000 rows. Windows of two rows here stand for windows of a
 * thousand.
 *
 * @covers \block_feedback_tracker\task\rederive_activity
 */
final class rederive_activity_test extends \advanced_testcase {
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
     * A tracked course with one assign activity.
     *
     * @param array $assignopts Options for the assign module.
     * @return array [course, cm]
     */
    private function activity(array $assignopts = []): array {
        $course = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')->create_tracked_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id] + $assignopts);
        return [$course, get_coursemodule_from_instance('assign', (int) $assign->id)];
    }

    /**
     * Insert ledger rows on the activity.
     *
     * @param \stdClass $course
     * @param \stdClass $cm
     * @param array $rows Overrides per row (userid, attemptnumber, teamgroupid, cycle).
     * @return void
     */
    private function ledger(\stdClass $course, \stdClass $cm, array $rows): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        foreach ($rows as $row) {
            $generator->create_ledger_row($row + [
                'courseid' => (int) $course->id,
                'cmid' => (int) $cm->id,
                'iteminstance' => (int) $cm->instance,
            ]);
        }
    }

    /**
     * A task that walks in windows of two rows.
     *
     * @param int $cmid
     * @param string $source One of rederive_activity::SOURCE_*.
     * @return rederive_activity
     */
    private function small_window_task(int $cmid, string $source): rederive_activity {
        $task = new class extends rederive_activity {
            /** Rows per window. */
            protected const PAGE = 2;
        };
        $task->set_custom_data(['cmid' => $cmid, 'source' => $source]);
        return $task;
    }

    /**
     * Every backfill descriptor queued, flattened across tasks.
     *
     * @return array
     */
    private function descriptors(): array {
        $out = [];
        foreach (\core\task\manager::get_adhoc_tasks('\\' . backfill_one_submission::class) as $task) {
            foreach ((array) ($task->get_custom_data()->rows ?? []) as $row) {
                $out[] = (array) $row;
            }
        }
        return $out;
    }

    /**
     * Five students over three windows: every one is queued, once per
     * attempt, though the ledger holds two cycles of one attempt.
     *
     * @return void
     */
    public function test_ledger_walk_passes_every_window(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->activity();
        $rows = [];
        for ($u = 1; $u <= 5; $u++) {
            $rows[] = ['userid' => 9000 + $u, 'attemptnumber' => 0];
        }
        $rows[] = ['userid' => 9001, 'attemptnumber' => 0, 'cycle' => 1, 'iscurrent' => 0];
        $this->ledger($course, $cm, $rows);

        $this->small_window_task((int) $cm->id, rederive_activity::SOURCE_LEDGER)->execute();

        $users = array_map(fn($d) => $d['userid'], $this->descriptors());
        sort($users);
        $this->assertSame([9001, 9002, 9003, 9004, 9005], $users);
        foreach ($this->descriptors() as $d) {
            $this->assertSame((int) $cm->id, $d['cmid']);
            $this->assertSame((int) $course->id, $d['courseid']);
        }
    }

    /**
     * On a team activity the members' rows collapse to one descriptor per
     * group and attempt, also when they span windows.
     *
     * @return void
     */
    public function test_team_rows_collapse_across_windows(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->activity(['teamsubmission' => 1]);
        $rows = [];
        for ($u = 1; $u <= 4; $u++) {
            $rows[] = ['userid' => 9000 + $u, 'teamgroupid' => 77];
        }
        $rows[] = ['userid' => 9001, 'teamgroupid' => 77, 'attemptnumber' => 1];
        $this->ledger($course, $cm, $rows);

        $this->small_window_task((int) $cm->id, rederive_activity::SOURCE_LEDGER)->execute();

        $keys = array_map(fn($d) => $d['groupid'] . ':' . $d['attemptnumber'] . ':' . $d['userid'], $this->descriptors());
        sort($keys);
        $this->assertSame(['77:0:0', '77:1:0'], $keys);
    }

    /**
     * The submission walk takes every student submission and skips the team
     * container rows, which belong to no student.
     *
     * @return void
     */
    public function test_submission_walk_takes_student_submissions(): void {
        global $DB;
        $this->resetAfterTest();
        [, $cm] = $this->activity();
        foreach ([[9001, 0], [9002, 0], [9002, 1], [0, 0], [9003, 0]] as [$userid, $attempt]) {
            $DB->insert_record('assign_submission', (object) [
                'assignment' => (int) $cm->instance,
                'userid' => $userid,
                'attemptnumber' => $attempt,
                'timecreated' => time(),
                'timemodified' => time(),
                'status' => 'submitted',
                'groupid' => 0,
                'latest' => 1,
            ]);
        }

        $this->small_window_task((int) $cm->id, rederive_activity::SOURCE_SUBMISSIONS)->execute();

        $keys = array_map(fn($d) => $d['userid'] . ':' . $d['attemptnumber'], $this->descriptors());
        sort($keys);
        $this->assertSame(['9001:0', '9002:0', '9002:1', '9003:0'], $keys);
    }

    /**
     * A course the block has left since the event is not walked; the same
     * rows are walked while the block is there.
     *
     * @return void
     */
    public function test_course_without_the_block_is_not_walked(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm] = $this->activity();
        $this->ledger($course, $cm, [['userid' => 9001]]);

        $this->small_window_task((int) $cm->id, rederive_activity::SOURCE_LEDGER)->execute();
        $this->assertCount(1, $this->descriptors(), 'Control: the tracked course is walked.');

        $DB->delete_records('task_adhoc');
        $DB->delete_records('block_instances', ['blockname' => 'feedback_tracker']);
        \block_feedback_tracker\local\sla\course_access::reset_memo();
        $this->small_window_task((int) $cm->id, rederive_activity::SOURCE_LEDGER)->execute();
        $this->assertCount(0, $this->descriptors());
    }

    /**
     * A course that gained the block after an earlier task of the same cron
     * process memoised it as untracked is walked: the task decides on the
     * block as it stands, not on the memo.
     *
     * @return void
     */
    public function test_a_stale_memo_does_not_skip_the_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', (int) $assign->id);
        $this->ledger($course, $cm, [['userid' => 9001]]);
        \block_feedback_tracker\local\sla\course_access::reset_memo();
        $this->assertFalse(
            \block_feedback_tracker\local\sla\course_access::is_processable((int) $course->id),
            'Precondition: the memo holds the course as untracked.'
        );

        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        $this->small_window_task((int) $cm->id, rederive_activity::SOURCE_LEDGER)->execute();

        $this->assertCount(1, $this->descriptors());
    }

    /**
     * Queueing one activity twice keeps one walk; another activity or source
     * gets its own.
     *
     * @return void
     */
    public function test_queue_collapses_one_activity(): void {
        global $DB;
        $this->resetAfterTest();
        $classname = ['classname' => '\\' . rederive_activity::class];

        rederive_activity::queue(501, rederive_activity::SOURCE_LEDGER);
        rederive_activity::queue(501, rederive_activity::SOURCE_LEDGER);
        $this->assertSame(1, $DB->count_records('task_adhoc', $classname));

        rederive_activity::queue(502, rederive_activity::SOURCE_LEDGER);
        rederive_activity::queue(501, rederive_activity::SOURCE_SUBMISSIONS);
        $this->assertSame(3, $DB->count_records('task_adhoc', $classname));
    }
}
