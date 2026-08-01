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
 * Tests for the submission browser's banding and counts.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * The day-mode band predicates compare a nullable column, and SQL NULL
 * comparisons are never true — so a row whose effectivedays was never
 * backfilled can fall out of every band while still being counted in total.
 *
 * @covers \block_feedback_tracker\local\sla\submission_browser
 */
final class submission_browser_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * A tracked course, a teacher who can see everything, and a real assign
     * course module.
     *
     * The course module is not optional: browse()'s FROM inner-joins
     * {course_modules} and {assign}, so a ledger row carrying the generator's
     * synthetic cmid is dropped from every query and the whole fixture
     * silently returns nothing.
     *
     * @return array [course, teacher, cmid]
     */
    private function course_with_viewer(): array {
        $this->generator()->seed_default_platform_calendar();
        $course = $this->generator()->create_tracked_course();
        $teacher = $this->generator()->create_user_in_role((int) $course->id, 'editingteacher');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        group_access::reset_memo();
        return [$course, $teacher, (int) $cm->id];
    }

    /**
     * Real enrolled users. The ledger joins {user}, so synthetic ids are
     * dropped from every query — another way a fixture silently returns less
     * than it seeded.
     *
     * @param \stdClass $course
     * @param int $count
     * @return array User ids.
     */
    private function students(\stdClass $course, int $count): array {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = (int) $this->generator()->create_user_in_role((int) $course->id, 'student')->id;
        }
        return $ids;
    }

    /**
     * Pending rows whose effectivedays was never backfilled must still be
     * classified. The row badge treats a missing day count as zero, so the
     * distribution has to agree.
     *
     * @return void
     */
    public function test_pending_counts_sum_to_total_with_null_effectivedays(): void {
        $this->resetAfterTest();

        [$course, $teacher, $cmid] = $this->course_with_viewer();
        $this->generator()->set_display_unit('business_days', '2,5,10');
        $now = time();

        $users = $this->students($course, 3);

        // Three pending rows, none of them carrying a stored day count.
        for ($i = 0; $i < 3; $i++) {
            $this->generator()->create_ledger_row([
                'courseid' => (int) $course->id,
                'cmid' => $cmid,
                'groupid' => 0,
                'userid' => $users[$i],
                'timesubmitted' => $now - (3600 * ($i + 1)),
                'timegraded' => null,
            ]);
        }

        $result = submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => 'pending']);

        $this->assertSame(3, $result['total']);
        $this->assertSame(
            $result['total'],
            array_sum($result['counts']),
            'Every row must land in exactly one band: counts must sum to total.'
        );
    }

    /**
     * The same for graded rows, which use the four-band result ladder.
     *
     * @return void
     */
    public function test_graded_counts_sum_to_total_with_null_effectivedays(): void {
        $this->resetAfterTest();

        [$course, $teacher, $cmid] = $this->course_with_viewer();
        $this->generator()->set_display_unit('business_days', '2,5,10');
        $now = time();

        $users = $this->students($course, 4);
        for ($i = 0; $i < 4; $i++) {
            $this->generator()->create_ledger_row([
                'courseid' => (int) $course->id,
                'cmid' => $cmid,
                'groupid' => 0,
                'userid' => $users[$i],
                'timesubmitted' => $now - (86400 * ($i + 2)),
                'timegraded' => $now - 3600,
            ]);
        }

        $result = submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => 'graded']);

        $this->assertSame(4, $result['total']);
        $this->assertSame(
            $result['total'],
            array_sum($result['counts']),
            'Graded rows with no stored day count must not vanish from the distribution.'
        );
    }

    /**
     * A stored day count still drives the band, so the fallback must not
     * override real data.
     *
     * @return void
     */
    public function test_stored_day_count_drives_the_band(): void {
        $this->resetAfterTest();

        [$course, $teacher, $cmid] = $this->course_with_viewer();
        $this->generator()->set_display_unit('business_days', '2,5,10');
        $now = time();
        $users = $this->students($course, 2);

        // One clearly excellent (1 day) and one clearly critical (20 days).
        $this->generator()->create_ledger_row([
            'courseid' => (int) $course->id,
            'cmid' => $cmid,
            'userid' => $users[0],
            'timesubmitted' => $now - 86400,
            'timegraded' => $now,
            'effectivedays' => 1.0,
        ]);
        $this->generator()->create_ledger_row([
            'courseid' => (int) $course->id,
            'cmid' => $cmid,
            'userid' => $users[1],
            'timesubmitted' => $now - (86400 * 20),
            'timegraded' => $now,
            'effectivedays' => 20.0,
        ]);

        $result = submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => 'graded']);

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['counts']['excellent']);
        /* Graded results fold critical into regular, so the 20-day row lands
         * in regular and the critical key stays zero by design. */
        $this->assertSame(1, $result['counts']['regular']);
        $this->assertSame(0, $result['counts']['critical']);
        $this->assertSame($result['total'], array_sum($result['counts']));
    }

    /**
     * Mixing backfilled and un-backfilled rows must still partition cleanly.
     *
     * @return void
     */
    public function test_mixed_null_and_stored_rows_partition_cleanly(): void {
        $this->resetAfterTest();

        [$course, $teacher, $cmid] = $this->course_with_viewer();
        $this->generator()->set_display_unit('business_days', '2,5,10');
        $now = time();
        $users = $this->students($course, 3);

        $this->generator()->create_ledger_row([
            'courseid' => (int) $course->id,
            'cmid' => $cmid,
            'userid' => $users[0],
            'timesubmitted' => $now - 86400,
            'timegraded' => $now,
            'effectivedays' => 1.0,
        ]);
        $this->generator()->create_ledger_row([
            'courseid' => (int) $course->id,
            'cmid' => $cmid,
            'userid' => $users[1],
            'timesubmitted' => $now - (86400 * 3),
            'timegraded' => $now,
        ]);
        $this->generator()->create_ledger_row([
            'courseid' => (int) $course->id,
            'cmid' => $cmid,
            'userid' => $users[2],
            'timesubmitted' => $now - (86400 * 30),
            'timegraded' => $now,
        ]);

        $result = submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => 'graded']);

        $this->assertSame(3, $result['total']);
        $this->assertSame($result['total'], array_sum($result['counts']));
    }

    /**
     * The hours ruler is unaffected — effectivehours is not nullable in
     * practice and this pins that the fix did not disturb the default mode.
     *
     * @return void
     */
    public function test_hours_mode_counts_sum_to_total(): void {
        $this->resetAfterTest();

        [$course, $teacher, $cmid] = $this->course_with_viewer();
        $this->generator()->set_display_unit('hours', '2,5,10');
        $now = time();

        $users = $this->students($course, 3);
        foreach ([1.0, 30.0, 200.0] as $i => $hours) {
            $this->generator()->create_ledger_row([
                'courseid' => (int) $course->id,
                'cmid' => $cmid,
                'userid' => $users[$i],
                'timesubmitted' => $now - 7200,
                'timegraded' => null,
                'effectivehours' => $hours,
            ]);
        }

        $result = submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => 'pending']);

        $this->assertSame(3, $result['total']);
        $this->assertSame($result['total'], array_sum($result['counts']));
    }

    /**
     * A row missing its day count must never be counted in a band better than
     * the one its elapsed time implies. Counting it as zero days would flatter
     * a compliance figure, which is worse than the row going missing.
     *
     * @return void
     */
    public function test_missing_day_count_is_not_flattered_to_excellent(): void {
        $this->resetAfterTest();

        [$course, $teacher, $cmid] = $this->course_with_viewer();
        $this->generator()->set_display_unit('business_days', '2,5,10');
        $now = time();
        $users = $this->students($course, 1);

        // Graded 30 calendar days after submission, with no stored day count.
        $this->generator()->create_ledger_row([
            'courseid' => (int) $course->id,
            'cmid' => $cmid,
            'userid' => $users[0],
            'timesubmitted' => $now - (86400 * 30),
            'timegraded' => $now,
        ]);

        $result = submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => 'graded']);

        $this->assertSame(1, $result['total']);
        $this->assertSame($result['total'], array_sum($result['counts']));
        $this->assertSame(0, $result['counts']['excellent'], 'A 30-day wait must not be reported as excellent.');
        // Critical folds into regular on the graded ladder.
        $this->assertSame(1, $result['counts']['regular']);
    }

    /**
     * A pending row still awaiting the backfill must be banded by how long it
     * has actually been waiting, not treated as brand new. Filing it as
     * "aguardando" would hide the oldest work from the people triaging it.
     *
     * @return void
     */
    public function test_long_pending_row_without_day_count_is_not_banded_as_fresh(): void {
        $this->resetAfterTest();

        [$course, $teacher, $cmid] = $this->course_with_viewer();
        $this->generator()->set_display_unit('business_days', '2,5,10');
        $now = time();
        $users = $this->students($course, 1);

        // Submitted 40 days ago, never graded, no stored day count.
        $this->generator()->create_ledger_row([
            'courseid' => (int) $course->id,
            'cmid' => $cmid,
            'userid' => $users[0],
            'timesubmitted' => $now - (86400 * 40),
            'timegraded' => null,
        ]);

        $result = submission_browser::browse((int) $course->id, (int) $teacher->id, ['mode' => 'pending']);

        $this->assertSame(1, $result['total']);
        $this->assertSame($result['total'], array_sum($result['counts']));
        $this->assertSame(0, $result['counts']['aguardando'], 'A 40-day wait is not "just arrived".');
        $this->assertSame(1, $result['counts']['prioridade']);
    }
}
