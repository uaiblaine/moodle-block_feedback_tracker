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
 * Privacy tests for the per-marker slot table.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\privacy;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\submission_ledger;
use block_feedback_tracker\local\sla\submission_status;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * The slot table holds two subjects: the student and the marker. Every
 * fixture uses the marker allocated in position 2, who is never the ledger
 * row's `allocmarkerid` (position 1 on a multi-marking activity): whatever the
 * provider finds for them, it found through the slot table.
 *
 * The class-level covers docblock is kept, not attributes: this branch family
 * still supports Moodle 4.5, whose moodle-cs leg cannot read attributes.
 *
 * @covers \block_feedback_tracker\privacy\provider
 */
final class provider_slots_test extends \core_privacy\tests\provider_testcase {
    /**
     * The marker who only appears on slot rows gets the course context, and
     * so does the student.
     *
     * @return void
     */
    public function test_both_subjects_get_the_course_context(): void {
        $env = $this->build_environment();
        $context = \context_course::instance($env->course->id);
        $this->assertSame(0, $this->ledger_rows_naming($env->slotmarker), 'Control: only the slot table names them.');

        foreach ([$env->slotmarker, $env->student] as $user) {
            $contextids = array_map('intval', provider::get_contexts_for_userid((int) $user->id)->get_contextids());
            $this->assertContains((int) $context->id, $contextids);
        }
    }

    /**
     * Both markers and the student are listed in the course context.
     *
     * @return void
     */
    public function test_both_subjects_are_listed_in_the_course_context(): void {
        $env = $this->build_environment();
        $userlist = new userlist(\context_course::instance($env->course->id), 'block_feedback_tracker');
        provider::get_users_in_context($userlist);

        $userids = array_map('intval', $userlist->get_userids());
        $this->assertContains((int) $env->slotmarker->id, $userids);
        $this->assertContains((int) $env->student->id, $userids);
    }

    /**
     * The marker's export lists their own slot without the student; the
     * student's export lists every slot of their work with its marker.
     *
     * @return void
     */
    public function test_each_subject_exports_their_own_view(): void {
        $env = $this->build_environment();
        $context = \context_course::instance($env->course->id);

        provider::export_user_data(new approved_contextlist($env->slotmarker, 'block_feedback_tracker', [$context->id]));
        $mine = writer::with_context($context)->get_data([
            get_string('pluginname', 'block_feedback_tracker'),
            get_string('privacy:path:markerslots', 'block_feedback_tracker'),
        ]);
        $this->assertCount(1, $mine->slots);
        $this->assertSame(2, $mine->slots[0]['position']);
        $this->assertSame((int) $env->cm->id, $mine->slots[0]['cmid']);
        $this->assertArrayNotHasKey('userid', $mine->slots[0], 'The student is the student\'s data.');
        $this->assertArrayNotHasKey('marker', $mine->slots[0]);
        $this->assertArrayHasKey('markhours', $mine->slots[0], 'The marker\'s own export carries their turnaround.');
        $this->assertArrayHasKey('markdays', $mine->slots[0]);

        writer::reset();
        provider::export_user_data(new approved_contextlist($env->student, 'block_feedback_tracker', [$context->id]));
        $theirs = writer::with_context($context)->get_data([
            get_string('pluginname', 'block_feedback_tracker'),
            get_string('privacy:path:slots', 'block_feedback_tracker'),
        ]);
        $this->assertCount(2, $theirs->slots);
        $markers = array_map(fn($s) => $s['marker'], $theirs->slots);
        sort($markers);
        $expected = [(int) $env->markers[0]->id, (int) $env->slotmarker->id];
        sort($expected);
        $this->assertSame($expected, $markers);
        foreach ($theirs->slots as $slot) {
            $this->assertArrayNotHasKey('markhours', $slot, 'Marker performance is the marker\'s data.');
            $this->assertArrayNotHasKey('markdays', $slot);
            $this->assertArrayHasKey('timemarked', $slot);
        }
    }

    /**
     * Erasing a marker deletes the rows where they are the marker and keeps
     * the student's ledger row and the other marker's slot.
     *
     * @return void
     */
    public function test_erasing_a_marker_deletes_their_slot_rows(): void {
        global $DB;
        $env = $this->build_environment();
        $context = \context_course::instance($env->course->id);

        provider::delete_data_for_user(new approved_contextlist($env->slotmarker, 'block_feedback_tracker', [$context->id]));

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_alloc', ['marker' => $env->slotmarker->id]));
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_alloc', ['marker' => $env->markers[0]->id]));
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_sub', ['userid' => $env->student->id]));
    }

    /**
     * Erasing a marker through a userlist does the same.
     *
     * @return void
     */
    public function test_erasing_a_marker_by_userlist_deletes_their_slot_rows(): void {
        global $DB;
        $env = $this->build_environment();
        $context = \context_course::instance($env->course->id);

        provider::delete_data_for_users(new approved_userlist($context, 'block_feedback_tracker', [(int) $env->slotmarker->id]));

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_alloc', ['marker' => $env->slotmarker->id]));
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_alloc', ['marker' => $env->markers[0]->id]));
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_sub', ['userid' => $env->student->id]));
    }

    /**
     * Erasing the student deletes their slot rows with their ledger rows.
     *
     * @return void
     */
    public function test_erasing_the_student_deletes_their_slot_rows(): void {
        global $DB;
        $env = $this->build_environment();
        $context = \context_course::instance($env->course->id);

        provider::delete_data_for_user(new approved_contextlist($env->student, 'block_feedback_tracker', [$context->id]));

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_alloc', ['userid' => $env->student->id]));
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub', ['userid' => $env->student->id]));
    }

    /**
     * Deleting the whole context deletes every slot row of the course.
     *
     * @return void
     */
    public function test_deleting_the_context_deletes_every_slot_row(): void {
        global $DB;
        $env = $this->build_environment();
        $this->assertSame(2, $DB->count_records('block_feedback_tracker_alloc', ['courseid' => $env->course->id]));

        provider::delete_data_for_all_users_in_context(\context_course::instance($env->course->id));

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_alloc', ['courseid' => $env->course->id]));
    }

    /**
     * Ledger rows that name a user as student or allocated marker.
     *
     * @param \stdClass $user
     * @return int
     */
    private function ledger_rows_naming(\stdClass $user): int {
        global $DB;
        return $DB->count_records_select(
            'block_feedback_tracker_sub',
            'userid = :u OR allocmarkerid = :m',
            ['u' => $user->id, 'm' => $user->id]
        );
    }

    /**
     * A processable course with a two-marker assign and one handed-in student
     * whose markers are allocated through core.
     *
     * @return \stdClass course, cm, student, markers and slotmarker (the one in position 2).
     */
    private function build_environment(): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablebusinesshours', 0, 'block_feedback_tracker');
        submission_ledger::reset_memos();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $inst = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'markingworkflow' => 1,
            'markingallocation' => 1,
            'markercount' => 2,
            'multimarkmethod' => 'average',
            'grade' => 100,
        ]);
        $cm = get_coursemodule_from_instance('assign', $inst->id);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $t1 = time() - 3 * DAYSECS;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $inst->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'timecreated' => $t1, 'timemodified' => $t1,
            'status' => submission_status::SUBMITTED, 'groupid' => 0, 'latest' => 1,
        ]);
        submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
        // Created in this order, so the second has the higher id.
        $markers = [
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher'),
            $this->getDataGenerator()->create_and_enrol($course, 'editingteacher'),
        ];
        $assign->update_allocated_markers((int) $student->id, [(int) $markers[0]->id, (int) $markers[1]->id]);
        return (object) [
            'course' => $course,
            'cm' => $cm,
            'student' => $student,
            'markers' => $markers,
            'slotmarker' => $markers[1],
        ];
    }
}
