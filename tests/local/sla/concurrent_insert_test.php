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
 * Tests for the writers that insert against a unique index.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;

/**
 * The ledger writer and the dirty queue each read before they insert, against
 * a unique index, and recover when another writer inserts in between. One
 * connection cannot interleave with itself, so these tests replace $DB with a
 * copy of it that answers chosen reads with nothing, as a read taken before
 * the other writer committed would, while every other call, and the insert
 * that then collides, runs on the real connection. The collision is real: the
 * row it hits was written first, through the real $DB.
 *
 * Each test calls preventResetByRollback(): on PostgreSQL the test runner
 * otherwise wraps the test in a transaction, and both writers rethrow a
 * collision inside one, since it has aborted the transaction.
 *
 * @covers \block_feedback_tracker\local\sla\submission_ledger
 * @covers \block_feedback_tracker\local\sla\dirty_queue
 */
final class concurrent_insert_test extends \advanced_testcase {
    /**
     * Flush the memos the ledger consults.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        process_memos::reset();
    }

    /**
     * An upsert whose read missed a row inserted meanwhile adopts that row
     * and re-derives against it, so what the other writer recorded survives:
     * here a gradebook response, which a derivation that believed no row
     * existed would have erased.
     *
     * @return void
     */
    public function test_the_ledger_writer_adopts_a_row_inserted_after_its_read(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();
        $submitted = time() - 3 * 86400;
        $this->insert_submission((int) $assign->id, (int) $student->id, $submitted);
        $winner = submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $answered = $submitted + 86400;
        $DB->update_record('block_feedback_tracker_sub', (object) [
            'id' => $winner,
            'timegraded' => $answered,
            'timeclosed' => $answered,
            'closedsource' => gradebook_response::SOURCE_GRADEBOOK,
        ]);

        $misses = 1;
        $id = $this->with_stale_reads(
            'get_records',
            static function (...$args) use (&$misses): bool {
                if ($misses > 0 && $args[0] === 'block_feedback_tracker_sub' && ($args[2] ?? '') === 'cycle DESC') {
                    $misses--;
                    return true;
                }
                return false;
            },
            fn() => submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0)
        );

        $this->assertSame(0, $misses, 'Precondition: the writer read past the existing row.');
        $this->assertSame($winner, $id, 'The existing row is adopted.');
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));
        $row = $DB->get_record('block_feedback_tracker_sub', ['id' => $winner], '*', MUST_EXIST);
        $this->assertSame($answered, (int) $row->timegraded, 'The response the other writer recorded survives.');
        $this->assertSame(gradebook_response::SOURCE_GRADEBOOK, $row->closedsource);
    }

    /**
     * When the retry's read misses as well, the retry adopts the row with a
     * blind update rather than failing the caller.
     *
     * @return void
     */
    public function test_the_ledger_writer_adopts_the_row_when_its_retry_misses_too(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->seed_calendar();
        [$cm, $student, $assign] = $this->build_environment();
        $submitted = time() - 3 * 86400;
        $this->insert_submission((int) $assign->id, (int) $student->id, $submitted);
        $winner = submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        $DB->update_record('block_feedback_tracker_sub', (object) [
            'id' => $winner,
            'submissionstatus' => submission_status::NEW,
            'timesubmitted' => 0,
        ]);

        $misses = 2;
        $id = $this->with_stale_reads(
            'get_records',
            static function (...$args) use (&$misses): bool {
                if ($misses > 0 && $args[0] === 'block_feedback_tracker_sub' && ($args[2] ?? '') === 'cycle DESC') {
                    $misses--;
                    return true;
                }
                return false;
            },
            fn() => submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0)
        );

        $this->assertSame(0, $misses, 'Precondition: both reads missed the existing row.');
        $this->assertSame($winner, $id);
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_sub', ['cmid' => $cm->id]));
        $row = $DB->get_record('block_feedback_tracker_sub', ['id' => $winner], '*', MUST_EXIST);
        $this->assertSame(submission_status::SUBMITTED, $row->submissionstatus, 'The retry wrote its derivation.');
        $this->assertSame($submitted, (int) $row->timesubmitted);
    }

    /**
     * An enqueue whose read missed a tuple queued meanwhile refreshes that row
     * instead of failing on the unique index.
     *
     * @return void
     */
    public function test_the_dirty_queue_adopts_a_tuple_queued_after_its_read(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        dirty_queue::enqueue(42, 7, dirty_queue::REASON_SUBMISSION);
        $DB->set_field('block_feedback_tracker_queue', 'timeenqueued', 1000, ['courseid' => 42, 'groupid' => 7]);

        $misses = 1;
        $this->with_stale_reads(
            'get_record',
            static function (...$args) use (&$misses): bool {
                if ($misses > 0 && $args[0] === 'block_feedback_tracker_queue') {
                    $misses--;
                    return true;
                }
                return false;
            },
            fn() => dirty_queue::enqueue(42, 7, dirty_queue::REASON_GRADE)
        );

        $this->assertSame(0, $misses, 'Precondition: the enqueue read past the existing row.');
        $rows = $DB->get_records('block_feedback_tracker_queue', ['courseid' => 42, 'groupid' => 7]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame(dirty_queue::REASON_GRADE, $row->reason, 'The existing row is refreshed.');
        $this->assertGreaterThan(1000, (int) $row->timeenqueued);
    }

    /**
     * Run a callable with $DB replaced by a copy that answers the matching
     * calls of one read method with nothing.
     *
     * The copy is a mock of the real driver class carrying the real
     * instance's state, the open connection included, so every call it does
     * not intercept runs as it would on $DB. Its dispose() is stubbed out too:
     * the driver's closes the connection, which the copy's destructor would
     * otherwise do to the real $DB.
     *
     * @param string $method 'get_records' or 'get_record'.
     * @param callable $miss Takes the call's arguments; true answers it with nothing.
     * @param callable $fn The code under test.
     * @return mixed What $fn returns.
     */
    private function with_stale_reads(string $method, callable $miss, callable $fn) {
        global $DB;
        $real = $DB;
        $copy = $this->getMockBuilder(get_class($real))
            ->disableOriginalConstructor()
            ->onlyMethods([$method, 'dispose'])
            ->getMock();
        for ($class = new \ReflectionClass($real); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if (!$property->isStatic() && $property->isInitialized($real)) {
                    $property->setValue($copy, $property->getValue($real));
                }
            }
        }
        $copy->method($method)->willReturnCallback(
            static function (...$args) use ($real, $method, $miss) {
                if ($miss(...$args)) {
                    return $method === 'get_record' ? false : [];
                }
                return $real->$method(...$args);
            }
        );

        $DB = $copy;
        try {
            return $fn();
        } finally {
            $DB = $real;
        }
    }

    /**
     * Build a tracked course with an enrolled student and an assign.
     *
     * @return array The cm, the student and the assign.
     */
    private function build_environment(): array {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        return [$cm, $student, $assign];
    }

    /**
     * Insert one submitted attempt.
     *
     * @param int $assignid
     * @param int $userid
     * @param int $tsubmit
     * @return void
     */
    private function insert_submission(int $assignid, int $userid, int $tsubmit): void {
        global $DB;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assignid, 'userid' => $userid, 'attemptnumber' => 0,
            'timecreated' => $tsubmit, 'timemodified' => $tsubmit,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
    }

    /**
     * Seed the calendar settings the academic-time engine needs, with the
     * working day this suite owns.
     *
     * @return void
     */
    private function seed_calendar(): void {
        global $DB;
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');
        $DB->delete_records('block_feedback_tracker_chours');
        $now = time();
        for ($dow = 0; $dow <= 4; $dow++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek' => $dow, 'starttime' => 480, 'endtime' => 1080,
                'enabled' => 1, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        academic_time::reset_memos();
    }
}
