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
 * Tests for the block's course backup and restore.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\backup;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\submission_ledger;
use block_feedback_tracker\task\backfill_one_submission;
use block_feedback_tracker\task\rederive_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/backup/moodle2/backup_plan_builder.class.php');
require_once($CFG->dirroot . '/backup/moodle2/restore_plan_builder.class.php');
require_once($CFG->dirroot . '/blocks/feedback_tracker/backup/moodle2/backup_feedback_tracker_block_task.class.php');
require_once($CFG->dirroot . '/blocks/feedback_tracker/backup/moodle2/restore_feedback_tracker_block_task.class.php');

/**
 * Round trips through core's backup and restore: what each mode of
 * `backup_ledger` writes, that a restore follows the file rather than the
 * setting, how the ids are mapped, and the rebuild every restore queues.
 *
 * @covers \backup_feedback_tracker_block_task
 * @covers \backup_feedback_tracker_block_structure_step
 * @covers \restore_feedback_tracker_block_task
 * @covers \restore_feedback_tracker_block_structure_step
 * @covers \block_feedback_tracker\task\rederive_course
 */
final class backup_restore_test extends \advanced_testcase {
    /** An instant no rebuild can produce, to tell a restored stamp apart. */
    private const RELEASED = 1700000000;

    /**
     * Buffer the tasks' mtrace() output, which strict mode marks as risky.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        ob_start();
    }

    /**
     * Drop the buffered output.
     *
     * @return void
     */
    protected function tearDown(): void {
        ob_end_clean();
        parent::tearDown();
    }

    /**
     * The default mode writes no file of the block's own, and the restored
     * course gets its rows from the rebuild alone, without the stamps only
     * observation knows.
     */
    public function test_default_mode_rebuilds_the_restored_course(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cms] = $this->seed_course(1);

        $backupid = $this->backup_course($course);
        $this->assertNull($this->ledger_xml($backupid));

        $newcourseid = $this->restore_course($backupid, $course);
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub', ['courseid' => $newcourseid]));
        $this->assertSame([$newcourseid], $this->queued_rederives());

        $this->run_rebuild();
        $rows = $DB->get_records('block_feedback_tracker_sub', ['courseid' => $newcourseid]);
        $this->assertCount(2, $rows);
        $newcmid = $this->restored_cmid($newcourseid);
        foreach ($rows as $row) {
            $this->assertSame($newcmid, (int) $row->cmid);
            $this->assertNotEquals(self::RELEASED, (int) $row->timereleased);
        }
        $this->assertNotEquals((int) $cms[0]->id, $newcmid);
    }

    /**
     * A full-mode backup carries the rows, and the restore writes them with
     * every id mapped and the observed stamps kept, even when the site has
     * gone back to the default mode by then. The rebuild that follows keeps
     * them too.
     */
    public function test_full_mode_restores_the_rows_with_their_ids_mapped(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cms, $students, $marker] = $this->seed_course(1);
        set_config('backup_ledger', 'full', 'block_feedback_tracker');

        $backupid = $this->backup_course($course);
        $this->assertNotNull($this->ledger_xml($backupid));
        set_config('backup_ledger', 'rederive', 'block_feedback_tracker');

        $newcourseid = $this->restore_course($backupid, $course);
        $newcmid = $this->restored_cmid($newcourseid);
        $newassignid = (int) $DB->get_field('course_modules', 'instance', ['id' => $newcmid]);
        $rows = $DB->get_records('block_feedback_tracker_sub', ['courseid' => $newcourseid], 'userid ASC');
        $this->assertCount(2, $rows);
        $stamped = null;
        foreach ($rows as $row) {
            $this->assertSame($newcmid, (int) $row->cmid);
            $this->assertSame($newassignid, (int) $row->iteminstance);
            if ((int) $row->userid === (int) $students[0]->id) {
                $stamped = $row;
            }
        }
        $this->assertNotNull($stamped);
        $this->assertSame(self::RELEASED, (int) $stamped->timereleased);
        $this->assertSame((int) $marker->id, (int) $stamped->allocmarkerid);
        $newgroupid = (int) $DB->get_field('groups', 'id', ['courseid' => $newcourseid, 'name' => 'Group A'], MUST_EXIST);
        $this->assertSame($newgroupid, (int) $stamped->groupid);

        $slot = $DB->get_record('block_feedback_tracker_alloc', ['courseid' => $newcourseid], '*', MUST_EXIST);
        $this->assertSame((int) $stamped->id, (int) $slot->subid);
        $this->assertSame($newcmid, (int) $slot->cmid);
        $this->assertSame((int) $marker->id, (int) $slot->marker);
        $this->assertSame((int) $students[0]->id, (int) $slot->userid);

        // The source course keeps its own rows untouched.
        $this->assertSame(2, $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id]));
        $this->assertSame(1, $DB->count_records('block_feedback_tracker_alloc', ['courseid' => $course->id]));

        $this->assertSame([$newcourseid], $this->queued_rederives());
        $this->run_rebuild();
        $this->assertSame(2, $DB->count_records('block_feedback_tracker_sub', ['courseid' => $newcourseid]));
        $this->assertSame(
            self::RELEASED,
            (int) $DB->get_field('block_feedback_tracker_sub', 'timereleased', ['id' => $stamped->id])
        );
    }

    /**
     * The full mode puts nothing in a backup without user data, which is
     * what a course import is.
     */
    public function test_full_mode_without_user_data_writes_no_rows(): void {
        $this->resetAfterTest();
        [$course] = $this->seed_course(1);
        set_config('backup_ledger', 'full', 'block_feedback_tracker');

        $this->assertNotNull($this->ledger_xml($this->backup_course($course)));
        $this->assertNull($this->ledger_xml($this->backup_course($course, false)));
    }

    /**
     * Rows of an activity the restore leaves out are not written; the rows
     * of the restored activity are.
     */
    public function test_rows_of_an_activity_not_restored_are_left_out(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cms] = $this->seed_course(2);
        set_config('backup_ledger', 'full', 'block_feedback_tracker');
        $this->assertSame(4, $DB->count_records('block_feedback_tracker_sub', ['courseid' => $course->id]));

        $backupid = $this->backup_course($course);
        $newcourseid = $this->restore_course($backupid, $course, ['assign_' . $cms[1]->id . '_included' => false]);

        $newcmid = $this->restored_cmid($newcourseid);
        $rows = $DB->get_records('block_feedback_tracker_sub', ['courseid' => $newcourseid]);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($newcmid, (int) $row->cmid);
        }
    }

    /**
     * The rule dates move with the course start, as core moves the
     * activity's own dates; a hand-in instant does not.
     */
    public function test_rule_dates_move_with_the_course_start(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cms, $students] = $this->seed_course(1);
        set_config('backup_ledger', 'full', 'block_feedback_tracker');
        $due = (int) $course->startdate + 14 * DAYSECS;
        $DB->set_field('block_feedback_tracker_sub', 'timecloses', $due, ['courseid' => $course->id]);
        $submitted = (int) $DB->get_field(
            'block_feedback_tracker_sub',
            'timesubmitted',
            ['courseid' => $course->id, 'userid' => $students[0]->id]
        );

        $backupid = $this->backup_course($course);
        $shift = 7 * DAYSECS;
        $newcourseid = $this->restore_course($backupid, $course, ['course_startdate' => (int) $course->startdate + $shift]);

        $row = $DB->get_record(
            'block_feedback_tracker_sub',
            ['courseid' => $newcourseid, 'userid' => $students[0]->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame($due + $shift, (int) $row->timecloses);
        $this->assertSame($submitted, (int) $row->timesubmitted);
    }

    /**
     * The backup writes every column of both tables but the id, so a column
     * added to the schema cannot be left out of backups unnoticed.
     */
    public function test_backup_fields_are_the_schema_columns(): void {
        global $CFG;
        $xmldb = new \xmldb_file($CFG->dirroot . '/blocks/feedback_tracker/db/install.xml');
        $xmldb->loadXMLStructure();
        $structure = $xmldb->getStructure();
        $step = new \ReflectionClass(\backup_feedback_tracker_block_structure_step::class);
        $consts = [
            'block_feedback_tracker_sub' => 'SUB_FIELDS',
            'block_feedback_tracker_alloc' => 'ALLOC_FIELDS',
        ];
        foreach ($consts as $table => $const) {
            $columns = array_map(fn($field) => $field->getName(), $structure->getTable($table)->getFields());
            $this->assertSame(
                array_values(array_diff($columns, ['id'])),
                $step->getConstant($const),
                "{$const} must list every column of {$table} but id, in schema order."
            );
        }
    }

    /**
     * A tracked course with $activities assignments, two students handing in
     * each, one marker allocated to the first student's work on the first
     * activity, the stamps only observation records set on that row, and
     * the first student's rows reporting under a group named "Group A".
     *
     * @param int $activities How many assignments.
     * @return array The course, its course modules, the students and the marker.
     */
    private function seed_course(int $activities): array {
        global $DB;
        $this->setAdminUser();
        set_config('process_hidden_courses', 1, 'block_feedback_tracker');
        $generator = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $generator->seed_default_platform_calendar();
        $course = $generator->create_tracked_course(['startdate' => time() - 30 * DAYSECS]);
        $students = [
            $this->getDataGenerator()->create_and_enrol($course, 'student'),
            $this->getDataGenerator()->create_and_enrol($course, 'student'),
        ];
        $marker = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $students[0]->id]);

        $cms = [];
        $now = time();
        for ($i = 0; $i < $activities; $i++) {
            $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
            $cm = get_coursemodule_from_instance('assign', $assign->id);
            $cms[] = $cm;
            foreach ($students as $n => $student) {
                $DB->insert_record('assign_submission', (object) [
                    'assignment' => $assign->id,
                    'userid' => $student->id,
                    'attemptnumber' => 0,
                    'timecreated' => $now - ($n + 2) * HOURSECS,
                    'timemodified' => $now - ($n + 2) * HOURSECS,
                    'status' => 'submitted',
                    'groupid' => 0,
                    'latest' => 1,
                ]);
                submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0);
            }
        }

        $row = $DB->get_record(
            'block_feedback_tracker_sub',
            ['cmid' => $cms[0]->id, 'userid' => $students[0]->id],
            '*',
            MUST_EXIST
        );
        $DB->update_record('block_feedback_tracker_sub', (object) [
            'id' => $row->id,
            'timereleased' => self::RELEASED,
            'allocmarkerid' => $marker->id,
            'timeallocated' => self::RELEASED - DAYSECS,
            'timeallocmarker' => self::RELEASED - DAYSECS,
            'allocsource' => 'observed',
        ]);
        $DB->set_field('block_feedback_tracker_sub', 'groupid', $group->id, ['userid' => $students[0]->id]);
        $DB->insert_record('block_feedback_tracker_alloc', (object) [
            'subid' => $row->id,
            'courseid' => $course->id,
            'cmid' => $cms[0]->id,
            'userid' => $students[0]->id,
            'marker' => $marker->id,
            'position' => 1,
            'optional' => 0,
            'timeallocated' => self::RELEASED - DAYSECS,
            'allocsource' => 'observed',
            'timemarked' => self::RELEASED,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return [$course, $cms, $students, $marker];
    }

    /**
     * Back a course up as a site does, then unpack the file for the restore.
     *
     * An import-mode backup would leave the submissions out, since import
     * never carries activity user data, and the rebuild would have nothing
     * to read.
     *
     * @param \stdClass $course The course.
     * @param bool $users Whether the backup includes user data.
     * @return string The name of the unpacked backup's temp directory.
     */
    private function backup_course(\stdClass $course, bool $users = true): string {
        global $CFG, $USER;
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($users);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();
        $folder = 'block_feedback_tracker_' . uniqid();
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->backuptempdir . '/' . $folder);
        $file->delete();
        return $folder;
    }

    /**
     * Restore a backup into a new course.
     *
     * @param string $backupid The backup id.
     * @param \stdClass $course The source course, which names the copy.
     * @param array $settings Restore plan settings to set, by name.
     * @return int The new course id.
     */
    private function restore_course(string $backupid, \stdClass $course, array $settings = []): int {
        global $USER;
        $newcourseid = (int) \restore_dbops::create_new_course(
            $course->fullname . ' copy',
            $course->shortname . '_copy',
            $course->category
        );
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value(true);
        foreach ($settings as $name => $value) {
            $rc->get_plan()->get_setting($name)->set_value($value);
        }
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        course_access::reset_memo();
        return $newcourseid;
    }

    /**
     * The block's own file in a backup, or null when it wrote none.
     *
     * @param string $backupid The backup id.
     * @return string|null The XML.
     */
    private function ledger_xml(string $backupid): ?string {
        global $CFG;
        $files = glob($CFG->backuptempdir . '/' . $backupid . '/course/blocks/feedback_tracker_*/feedback_tracker.xml');
        return $files ? file_get_contents($files[0]) : null;
    }

    /**
     * The course module of the first assignment in a course.
     *
     * @param int $courseid The course.
     * @return int The course module id.
     */
    private function restored_cmid(int $courseid): int {
        $cms = get_coursemodules_in_course('assign', $courseid);
        return (int) reset($cms)->id;
    }

    /**
     * The courses the queued rebuild tasks are for.
     *
     * @return array Course ids.
     */
    private function queued_rederives(): array {
        $courseids = [];
        foreach (\core\task\manager::get_adhoc_tasks(rederive_course::class) as $task) {
            $courseids[] = (int) $task->get_custom_data()->courseid;
        }
        return $courseids;
    }

    /**
     * Run the queued rebuilds and the backfill workers they queue.
     *
     * @return void
     */
    private function run_rebuild(): void {
        foreach (\core\task\manager::get_adhoc_tasks(rederive_course::class) as $task) {
            $task->execute();
        }
        foreach (\core\task\manager::get_adhoc_tasks(backfill_one_submission::class) as $task) {
            $task->execute();
        }
    }
}
