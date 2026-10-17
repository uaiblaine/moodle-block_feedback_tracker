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
 * Tests for the grader filter gate.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\grading;

use block_feedback_tracker\external\get_grader_priority_list;
use block_feedback_tracker\local\output\bootstrap;
use block_feedback_tracker\local\sla\submission_browser;
use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Pins when a grade link passes through pages/open_grader.php, and that the
 * page clears a teacher's grader state only when it hides the linked student.
 */
#[CoversClass(grader_filters::class)]
#[CoversClass(bootstrap::class)]
#[CoversClass(submission_browser::class)]
#[CoversClass(get_grader_priority_list::class)]
final class grader_filters_test extends \advanced_testcase {
    /**
     * resetAfterTest() does not restore superglobals: drop the parameters the tests plant.
     *
     * @return void
     */
    protected function tearDown(): void {
        unset($_GET['userid'], $_GET['group'], $_POST['sesskey']);
        parent::tearDown();
    }

    /**
     * An assignment with online text, so mod_assign applies its status filters
     * (it ignores them when no submission plugin is enabled), and one student
     * who has submitted.
     *
     * @param array $courseopts Passed to create_course().
     * @param string $teacherrole Role of the viewing teacher.
     * @return array With course, assign, cm, teacher and student.
     */
    private function fixture(array $courseopts = [], string $teacherrole = 'editingteacher'): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course($courseopts);
        $teacher = $generator->create_and_enrol($course, $teacherrole);
        $student = $generator->create_and_enrol($course, 'student', ['firstname' => 'Ana', 'lastname' => 'Souza']);
        $instance = $generator->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $generator->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $student->id,
            'cmid' => $cm->id,
            'onlinetext' => 'Answer',
        ]);
        // The generator saves a draft whatever status it is given; hand it in.
        $DB->set_field(
            'assign_submission',
            'status',
            ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            ['assignment' => $instance->id, 'userid' => $student->id]
        );
        $this->setUser($teacher);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        return [$course, $assign, $cm, $teacher, $student];
    }

    /**
     * Select a group in the grader as the group menu does, through core.
     *
     * @param \stdClass $cm
     * @param int $groupid
     * @return void
     */
    private function select_group(\stdClass $cm, int $groupid): void {
        $_GET['group'] = $groupid;
        groups_get_activity_group(get_fast_modinfo($cm->course)->get_cm((int) $cm->id), true);
        unset($_GET['group']);
    }

    /**
     * A viewer with nothing saved gets direct links; each kind of saved state
     * turns the gate on, and the "No filter" spelling and a sort-only table
     * preference do not.
     *
     * @return void
     */
    public function test_viewer_has_saved_state(): void {
        global $SESSION;
        $this->resetAfterTest();
        [, $assign, $cm] = $this->fixture();
        $tablepref = 'flextable_mod_assign_grading-' . $assign->get_context()->id;

        $this->assertFalse(grader_filters::viewer_has_saved_state());
        $this->assertFalse(bootstrap::config_bundle()['grader_gate']);

        set_user_preference('assign_filter', ASSIGN_FILTER_NONE);
        set_user_preference($tablepref, json_encode(['sortby' => ['lastname' => SORT_ASC], 'i_first' => '', 'i_last' => '']));
        $this->assertFalse(grader_filters::viewer_has_saved_state());

        set_user_preference('assign_filter', ASSIGN_FILTER_NOT_SUBMITTED);
        $this->assertTrue(grader_filters::viewer_has_saved_state());
        $this->assertTrue(bootstrap::config_bundle()['grader_gate']);
        unset_user_preference('assign_filter');

        set_user_preference('assign_workflowfilter', ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);
        $this->assertTrue(grader_filters::viewer_has_saved_state());
        unset_user_preference('assign_workflowfilter');

        set_user_preference($tablepref, json_encode(['sortby' => [], 'i_first' => 'Z', 'i_last' => '']));
        $this->assertTrue(grader_filters::viewer_has_saved_state());
        unset_user_preference($tablepref);
        $this->assertFalse(grader_filters::viewer_has_saved_state());

        $SESSION->activegroup = [(int) $cm->course => [SEPARATEGROUPS => [0 => 0], VISIBLEGROUPS => [], 'aag' => [0 => 0]]];
        $this->assertFalse(grader_filters::viewer_has_saved_state());
        $SESSION->activegroup[(int) $cm->course]['aag'][0] = 7;
        $this->assertTrue(grader_filters::viewer_has_saved_state());
    }

    /**
     * Only a group-mode activity whose viewer lacks accessallgroups needs the
     * gate; a forced course mode overrides the activity's.
     *
     * @return void
     */
    public function test_row_needs_gate(): void {
        $this->resetAfterTest();
        [$course, $assign, , $teacher] = $this->fixture([], 'teacher');
        $courseid = (int) $course->id;
        $context = $assign->get_context();

        $this->assertFalse(grader_filters::row_needs_gate($courseid, NOGROUPS, NOGROUPS, 0, $context));
        $this->assertTrue(grader_filters::row_needs_gate($courseid, SEPARATEGROUPS, NOGROUPS, 0, $context));
        $this->assertTrue(grader_filters::row_needs_gate($courseid, VISIBLEGROUPS, NOGROUPS, 0, $context));
        $this->assertFalse(grader_filters::row_needs_gate($courseid, SEPARATEGROUPS, NOGROUPS, 1, $context));
        $this->assertTrue(grader_filters::row_needs_gate($courseid, NOGROUPS, SEPARATEGROUPS, 1, $context));

        // The control: the same rows for a teacher who may access all groups.
        $editor = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($editor);
        $this->assertFalse(grader_filters::row_needs_gate($courseid, SEPARATEGROUPS, NOGROUPS, 0, $context));
        $this->setUser($teacher);
        $this->assertTrue(grader_filters::row_needs_gate($courseid, SEPARATEGROUPS, NOGROUPS, 0, $context));
    }

    /**
     * With nothing saved the link is the plain grader URL and nothing changes.
     *
     * @return void
     */
    public function test_prepare_without_saved_state_changes_nothing(): void {
        $this->resetAfterTest();
        [, $assign, $cm, , $student] = $this->fixture();

        $result = grader_filters::prepare($assign, (int) $student->id, true);

        $this->assertFalse($result['cleared']);
        $direct = new \moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grader', 'userid' => $student->id]);
        $this->assertSame($direct->out(false), $result['url']->out(false));
    }

    /**
     * A status filter that hides the student is cleared, with every other
     * grader filter; one that keeps the student listed is left alone.
     *
     * @return void
     */
    public function test_prepare_clears_a_hiding_filter_only(): void {
        $this->resetAfterTest();
        [, $assign, , , $student] = $this->fixture();
        $_GET['userid'] = (int) $student->id;

        set_user_preference('assign_filter', ASSIGN_FILTER_REQUIRE_GRADING);
        $kept = grader_filters::prepare($assign, (int) $student->id, true);
        $this->assertFalse($kept['cleared']);
        $this->assertSame(ASSIGN_FILTER_REQUIRE_GRADING, get_user_preferences('assign_filter'));

        set_user_preference('assign_filter', ASSIGN_FILTER_NOT_SUBMITTED);
        set_user_preference('assign_workflowfilter', ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);
        $cleared = grader_filters::prepare($assign, (int) $student->id, true);
        $this->assertTrue($cleared['cleared']);
        $this->assertNull(get_user_preferences('assign_filter'));
        $this->assertNull(get_user_preferences('assign_workflowfilter'));
        $this->assertNull($cleared['url']->get_param('group'));
        $this->assertTrue(grader_filters::student_listed($assign, (int) $student->id));
    }

    /**
     * The one-student check gives the whole-class table's answer: the
     * request parameter changes its cost, never its verdict.
     *
     * @return void
     */
    public function test_student_listed_matches_the_whole_class_table(): void {
        $this->resetAfterTest();
        [, $assign, , , $student] = $this->fixture();
        $userid = (int) $student->id;

        foreach ([ASSIGN_FILTER_NOT_SUBMITTED => false, ASSIGN_FILTER_SUBMITTED => true] as $filter => $listed) {
            set_user_preference('assign_filter', $filter);
            unset($_GET['userid']);
            $this->assertSame($listed, grader_filters::student_listed($assign, $userid), "$filter, whole class");
            $_GET['userid'] = $userid;
            $this->assertSame($listed, grader_filters::student_listed($assign, $userid), "$filter, one student");
        }
    }

    /**
     * A name-initial filter on this assignment's table is emptied and the
     * table's sort order kept.
     *
     * @return void
     */
    public function test_prepare_clears_name_initials_and_keeps_the_sort(): void {
        $this->resetAfterTest();
        [, $assign, , , $student] = $this->fixture();
        $_GET['userid'] = (int) $student->id;
        $tablepref = 'flextable_mod_assign_grading-' . $assign->get_context()->id;
        set_user_preference($tablepref, json_encode([
            'collapse' => [],
            'sortby' => ['lastname' => SORT_DESC],
            'i_first' => 'Z',
            'i_last' => '',
            'textsort' => [],
        ]));
        $this->assertFalse(grader_filters::student_listed($assign, (int) $student->id));

        $result = grader_filters::prepare($assign, (int) $student->id, true);

        $this->assertTrue($result['cleared']);
        $prefs = json_decode(get_user_preferences($tablepref), true);
        $this->assertSame('', $prefs['i_first']);
        $this->assertSame(['lastname' => SORT_DESC], $prefs['sortby']);
    }

    /**
     * A teacher without accessallgroups whose active group leaves the student
     * out is sent to a group the two share; saved filters stay untouched.
     *
     * @return void
     */
    public function test_prepare_switches_to_a_shared_group(): void {
        $this->resetAfterTest();
        [$course, $assign, $cm, $teacher, $student] = $this->fixture(
            ['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1],
            'teacher'
        );
        $generator = $this->getDataGenerator();
        $groupa = $generator->create_group(['courseid' => $course->id, 'name' => 'A']);
        $groupb = $generator->create_group(['courseid' => $course->id, 'name' => 'B']);
        groups_add_member($groupa, $teacher);
        groups_add_member($groupb, $teacher);
        groups_add_member($groupb, $student);
        $this->select_group($cm, (int) $groupa->id);

        $result = grader_filters::prepare($assign, (int) $student->id, true);

        $this->assertTrue($result['cleared']);
        $this->assertSame((string) $groupb->id, (string) $result['url']->get_param('group'));

        // The control: with the student's group selected, nothing changes.
        $this->select_group($cm, (int) $groupb->id);
        $this->assertFalse(grader_filters::prepare($assign, (int) $student->id, true)['cleared']);
    }

    /**
     * A teacher who may see every group is sent to all participants.
     *
     * @return void
     */
    public function test_prepare_switches_to_all_participants(): void {
        $this->resetAfterTest();
        [$course, $assign, $cm, , $student] = $this->fixture(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $generator = $this->getDataGenerator();
        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);
        groups_add_member($groupb, $student);
        $this->select_group($cm, (int) $groupa->id);

        $result = grader_filters::prepare($assign, (int) $student->id, true);

        $this->assertTrue($result['cleared']);
        $this->assertSame('0', (string) $result['url']->get_param('group'));
    }

    /**
     * A user who is not a participant never clears anything, even under a
     * filter that would hide a participant.
     *
     * @return void
     */
    public function test_prepare_ignores_a_non_participant(): void {
        $this->resetAfterTest();
        [, $assign] = $this->fixture();
        $stranger = $this->getDataGenerator()->create_user();
        $_GET['userid'] = (int) $stranger->id;
        set_user_preference('assign_filter', ASSIGN_FILTER_NOT_SUBMITTED);

        $result = grader_filters::prepare($assign, (int) $stranger->id, true);

        $this->assertFalse($result['cleared']);
        $this->assertSame(ASSIGN_FILTER_NOT_SUBMITTED, get_user_preferences('assign_filter'));
    }

    /**
     * A user who does not exist never clears anything and raises nothing.
     *
     * @return void
     */
    public function test_prepare_ignores_a_user_that_does_not_exist(): void {
        global $DB;
        $this->resetAfterTest();
        [, $assign] = $this->fixture();
        $missing = (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}') + 1000;
        $_GET['userid'] = $missing;
        set_user_preference('assign_filter', ASSIGN_FILTER_NOT_SUBMITTED);

        $result = grader_filters::prepare($assign, $missing, true);

        $this->assertFalse($result['cleared']);
        $this->assertSame(ASSIGN_FILTER_NOT_SUBMITTED, get_user_preferences('assign_filter'));
    }

    /**
     * Without a valid sesskey a filter that hides the student stays; the
     * same call with one clears it.
     *
     * @return void
     */
    public function test_prepare_without_sesskey_changes_nothing(): void {
        $this->resetAfterTest();
        [, $assign, $cm, , $student] = $this->fixture();
        $_GET['userid'] = (int) $student->id;
        set_user_preference('assign_filter', ASSIGN_FILTER_NOT_SUBMITTED);

        $result = grader_filters::prepare($assign, (int) $student->id, false);

        $this->assertFalse($result['cleared']);
        $this->assertSame(ASSIGN_FILTER_NOT_SUBMITTED, get_user_preferences('assign_filter'));
        $direct = new \moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grader', 'userid' => $student->id]);
        $this->assertSame($direct->out(false), $result['url']->out(false));

        // The control: the same call with a valid sesskey clears the filter.
        $this->assertTrue(grader_filters::prepare($assign, (int) $student->id, true)['cleared']);
    }

    /**
     * A user who cannot view the assignment's grades is refused before
     * anything is read or changed.
     *
     * @return void
     */
    public function test_prepare_requires_the_graders_capability(): void {
        $this->resetAfterTest();
        [, $assign, , , $student] = $this->fixture();
        $this->setUser($student);
        set_user_preference('assign_filter', ASSIGN_FILTER_NOT_SUBMITTED);

        try {
            grader_filters::prepare($assign, (int) $student->id, true);
            $this->fail('A student passed the grader capability check.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(ASSIGN_FILTER_NOT_SUBMITTED, get_user_preferences('assign_filter'));
        }
    }

    /**
     * The rows of both web services carry the gate: 1 for a teacher without
     * accessallgroups in a group-mode course, 0 for one with it.
     *
     * @return void
     */
    public function test_web_service_rows_carry_the_gate(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugin = $generator->get_plugin_generator('block_feedback_tracker');
        $plugin->seed_default_platform_calendar();
        $course = $plugin->create_tracked_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $courseid = (int) $course->id;
        $group = $generator->create_group(['courseid' => $courseid]);
        $teacher = $plugin->create_user_in_role($courseid, 'teacher', (int) $group->id);
        $editor = $plugin->create_user_in_role($courseid, 'editingteacher', (int) $group->id);
        $student = $plugin->create_user_in_role($courseid, 'student', (int) $group->id);
        $instance = $generator->create_module('assign', ['course' => $courseid]);
        $plugin->create_ledger_row([
            'courseid' => $courseid,
            'cmid' => (int) get_coursemodule_from_instance('assign', $instance->id)->id,
            'iteminstance' => (int) $instance->id,
            'userid' => (int) $student->id,
            'groupid' => (int) $group->id,
            'timesubmitted' => time() - 2 * DAYSECS,
            'effectivehours' => 5.0,
            'waitinghours' => 6.0,
            'slabucket' => 'good',
        ]);

        foreach ([1 => $teacher, 0 => $editor] as $expected => $viewer) {
            $this->setUser($viewer);
            $_POST['sesskey'] = sesskey();
            $pending = external_api::call_external_function(
                'block_feedback_tracker_get_pending_submissions',
                ['courseid' => $courseid]
            );
            $this->assertFalse($pending['error'], json_encode($pending['exception'] ?? null));
            $this->assertCount(1, $pending['data']['submissions']);
            $this->assertSame($expected, (int) $pending['data']['submissions'][0]['gradergate']);

            $priority = external_api::call_external_function(
                'block_feedback_tracker_get_grader_priority_list',
                ['limit' => 10, 'bucket' => '']
            );
            $this->assertFalse($priority['error'], json_encode($priority['exception'] ?? null));
            $this->assertCount(1, $priority['data']['submissions']);
            $this->assertSame($expected, (int) $priority['data']['submissions'][0]['gradergate']);
        }
    }
}
