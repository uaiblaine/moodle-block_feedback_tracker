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
 * Tests for the get_graded_submissions external function.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use core_external\external_api;

/**
 * Covers the graded listing: it returns only submitted work that now carries a
 * timegraded, partitions the result bands into counts, and respects the result
 * band filter.
 *
 * @covers \block_feedback_tracker\external\get_graded_submissions
 */
final class get_graded_submissions_test extends \advanced_testcase {
    /**
     * The listing returns only graded rows; pending work never surfaces.
     *
     * @return void
     */
    public function test_lists_graded_only(): void {
        $this->resetAfterTest();

        [$course, $teacher] = $this->seed_course_with_teacher();
        $this->seed_row($course, 'good', true);
        $this->seed_row($course, 'excellent', true);
        $this->seed_row($course, 'good', false);

        $this->setUser($teacher);
        $result = external_api::clean_returnvalue(
            get_graded_submissions::execute_returns(),
            get_graded_submissions::execute((int) $course->id)
        );

        $this->assertSame(2, (int) $result['total']);
        foreach ($result['submissions'] as $row) {
            $this->assertGreaterThan(0, (int) $row['timegraded']);
        }
    }

    /**
     * Counts partition the graded set into the three result bands
     * (excellent / good / regular). Critical graded results fold into Regular —
     * the same three-band set the academic-days strip shows — so the critical
     * count is always zero.
     *
     * @return void
     */
    public function test_counts_by_result_band(): void {
        $this->resetAfterTest();

        [$course, $teacher] = $this->seed_course_with_teacher();
        $this->seed_row($course, 'excellent', true);
        $this->seed_row($course, 'good', true);
        $this->seed_row($course, 'good', true);
        $this->seed_row($course, 'critical', true);

        $this->setUser($teacher);
        $result = external_api::clean_returnvalue(
            get_graded_submissions::execute_returns(),
            get_graded_submissions::execute((int) $course->id)
        );

        $this->assertSame(1, (int) $result['counts']['excellent']);
        $this->assertSame(2, (int) $result['counts']['good']);
        // The lone critical row folds into Regular; the critical band stays empty.
        $this->assertSame(1, (int) $result['counts']['regular']);
        $this->assertSame(0, (int) $result['counts']['critical']);
    }

    /**
     * Critical graded results fold into Regular across the whole graded view:
     * each row reports a "regular" slabucket (never "critical"), the counts roll
     * critical into regular, and filtering by Regular returns the folded rows.
     *
     * @return void
     */
    public function test_critical_folds_into_regular(): void {
        $this->resetAfterTest();

        [$course, $teacher] = $this->seed_course_with_teacher();
        $this->seed_row($course, 'regular', true);
        $this->seed_row($course, 'critical', true);

        $this->setUser($teacher);

        $all = external_api::clean_returnvalue(
            get_graded_submissions::execute_returns(),
            get_graded_submissions::execute((int) $course->id)
        );
        $this->assertSame(2, (int) $all['counts']['regular']);
        $this->assertSame(0, (int) $all['counts']['critical']);
        foreach ($all['submissions'] as $row) {
            $this->assertSame('regular', $row['slabucket']);
        }

        // Filtering by Regular includes the folded critical row.
        $filtered = external_api::clean_returnvalue(
            get_graded_submissions::execute_returns(),
            get_graded_submissions::execute((int) $course->id, 0, 'regular')
        );
        $this->assertSame(2, (int) $filtered['total']);
    }

    /**
     * The bucket filter narrows the rows to one result band while the counts
     * still describe the whole graded set.
     *
     * @return void
     */
    public function test_bucket_filter_narrows_rows(): void {
        $this->resetAfterTest();

        [$course, $teacher] = $this->seed_course_with_teacher();
        $this->seed_row($course, 'excellent', true);
        $this->seed_row($course, 'good', true);

        $this->setUser($teacher);
        $result = external_api::clean_returnvalue(
            get_graded_submissions::execute_returns(),
            get_graded_submissions::execute((int) $course->id, 0, 'good')
        );

        $this->assertSame(1, (int) $result['total']);
        $this->assertSame('good', $result['submissions'][0]['slabucket']);
        $this->assertSame(1, (int) $result['counts']['excellent']);
        $this->assertSame(1, (int) $result['counts']['good']);
    }

    /**
     * A graded row whose stored bucket is still the "pending" sentinel (e.g.
     * graded before its effective hours were resolved, or graded entirely
     * within a paused window) is reclassified from its frozen effective hours
     * so the result band is never "pending". Hours mode.
     *
     * @return void
     */
    public function test_graded_bucket_never_pending_hours(): void {
        $this->resetAfterTest();

        [$course, $teacher] = $this->seed_course_with_teacher();
        $this->seed_row($course, 'pending', true);

        $this->setUser($teacher);
        $result = external_api::clean_returnvalue(
            get_graded_submissions::execute_returns(),
            get_graded_submissions::execute((int) $course->id)
        );

        $this->assertSame(1, (int) $result['total']);
        // The seed_row helper stores effectivehours of 5.0, reclassified to excellent.
        $this->assertSame('excellent', $result['submissions'][0]['slabucket']);
    }

    /**
     * In business-days mode the displayed band is recomputed from the stored
     * elapsed-day count, which is NULL on legacy / unbackfilled rows and would
     * otherwise resolve to "pending". A graded row falls back to its frozen
     * submit→grade business-day count instead, so it shows a real band.
     *
     * @return void
     */
    public function test_graded_bucket_never_pending_business_days(): void {
        $this->resetAfterTest();

        set_config('display_time_unit', 'business_days', 'block_feedback_tracker');

        [$course, $teacher] = $this->seed_course_with_teacher();
        // The seed_row helper leaves effectivedays NULL and the stored bucket pending.
        $this->seed_row($course, 'pending', true);

        $this->setUser($teacher);
        $result = external_api::clean_returnvalue(
            get_graded_submissions::execute_returns(),
            get_graded_submissions::execute((int) $course->id)
        );

        $this->assertSame(1, (int) $result['total']);
        $this->assertNotSame('pending', $result['submissions'][0]['slabucket']);
        // Same-day submit→grade ⇒ zero business days ⇒ excellent.
        $this->assertSame('excellent', $result['submissions'][0]['slabucket']);
    }

    // Helpers.

    /**
     * Create a course with a feedback_tracker block instance and an editing
     * teacher who can view the responsiveness data.
     *
     * @return array{0: \stdClass, 1: \stdClass} [course, teacher]
     */
    private function seed_course_with_teacher(): array {
        $course = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        return [$course, $teacher];
    }

    /**
     * Insert one submitted ledger row, optionally graded.
     *
     * @param \stdClass $course
     * @param string $slabucket Result band recorded at grading time.
     * @param bool $graded When true, set timegraded so the row counts as graded.
     * @return void
     */
    private function seed_row(\stdClass $course, string $slabucket, bool $graded): void {
        global $DB;

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $now = time();
        $DB->insert_record('block_feedback_tracker_sub', (object) [
            'courseid'         => (int) $course->id,
            'groupid'          => 0,
            'cmid'             => (int) $cm->id,
            'iteminstance'     => (int) $assign->id,
            'userid'           => (int) $student->id,
            'attemptnumber'    => 0,
            'submissionstatus' => 'submitted',
            'timesubmitted'    => $now - 7200,
            'timegraded'       => $graded ? $now - 3600 : null,
            'hasrule'          => 0,
            'waitinghours'     => 6.0,
            'effectivehours'   => 5.0,
            'effectiveasof'    => $now,
            'effectivecalver'  => 1,
            'slabucket'        => $slabucket,
            'timecreated'      => $now - 7200,
            'timemodified'     => $now,
        ]);
    }

    /**
     * A student cannot read the graded table for their course.
     *
     * @return void
     */
    public function test_student_is_refused(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        get_graded_submissions::execute((int) $course->id);
    }
}
