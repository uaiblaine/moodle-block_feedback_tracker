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
 * Tests that the read services declared read-only leave the session as they found it.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\sla\process_memos;
use block_feedback_tracker\local\sla\rollup_service;
use core_external\external_api;

/**
 * A service declared 'readonlysession' runs without the session lock, so nothing it writes to
 * $SESSION survives the request.
 *
 * Core's contract for a read-only session is the one write_close() checks
 * (lib/classes/session/manager.php, the array_session_diff() of $SESSION taken at session start
 * and at close): no $SESSION key added, removed or changed. That is what is asserted here, per
 * service, around a call made the way the browser makes it, with the plugin's caches and memos
 * cold so every fill runs. $USER is not compared, as core does not compare it.
 *
 * One key is left out of the comparison, and only that one: `cachestore_session`, where core's
 * default session store keeps session-mode caches (this plugin has two, `responsiveness_payload`
 * and `dashboard_payload`). On a site with $CFG->enable_read_only_sessions on that key cannot
 * exist: core refuses to start a session that holds it (manager::start_session()), so such a site
 * maps its session-mode caches to a store outside the session, where a write survives a
 * read-only request.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \block_feedback_tracker\external\get_responsiveness
 * @covers \block_feedback_tracker\external\get_pending_submissions
 * @covers \block_feedback_tracker\external\get_graded_submissions
 * @covers \block_feedback_tracker\external\get_report_scopes
 * @covers \block_feedback_tracker\external\get_academic_days
 * @covers \block_feedback_tracker\external\get_pause_timeline
 * @covers \block_feedback_tracker\external\get_calendar
 * @covers \block_feedback_tracker\external\get_dashboard
 * @covers \block_feedback_tracker\external\get_school_comparison
 * @covers \block_feedback_tracker\external\get_grader_priority_list
 * @covers \block_feedback_tracker\external\get_insights
 * @covers \block_feedback_tracker\external\get_audit_log
 */
final class readonly_session_test extends \advanced_testcase {
    /** @var string The one $SESSION key core forbids on a read-only site, and the only one not compared. */
    private const SESSION_STORE_KEY = 'cachestore_session';

    /** @var string[] The plugin's cache definitions, purged before every call. */
    private const CACHES = [
        'calendar_effective_day',
        'pause_windows_by_course',
        'responsiveness_payload',
        'dashboard_payload',
        'site_comparison',
    ];

    /**
     * resetAfterTest() does not restore superglobals: drop the sesskey the calls plant in $_POST.
     *
     * @return void
     */
    protected function tearDown(): void {
        unset($_POST['sesskey']);
        parent::tearDown();
    }

    /**
     * The services that must hold no session lock, each with the arguments its view sends
     * (amd/src/lib/api.js) against the fixture below.
     *
     * @param int $courseid The fixture's course.
     * @param int $submissionid The fixture's graded ledger row.
     * @return array Service name => arguments.
     */
    private static function read_only_calls(int $courseid, int $submissionid): array {
        $month = (int) date('Ym');

        return [
            'block_feedback_tracker_get_responsiveness' => ['courseid' => $courseid],
            'block_feedback_tracker_get_pending_submissions' => ['courseid' => $courseid],
            'block_feedback_tracker_get_graded_submissions' => ['courseid' => $courseid],
            'block_feedback_tracker_get_report_scopes' => ['courseid' => $courseid],
            'block_feedback_tracker_get_academic_days' => ['courseid' => $courseid],
            'block_feedback_tracker_get_pause_timeline' => ['submissionid' => $submissionid],
            'block_feedback_tracker_get_calendar' => ['startymd' => $month * 100 + 1, 'endymd' => $month * 100 + 28],
            'block_feedback_tracker_get_dashboard' => ['band' => ''],
            'block_feedback_tracker_get_school_comparison' => ['days' => 30],
            'block_feedback_tracker_get_grader_priority_list' => ['limit' => 10, 'bucket' => ''],
            'block_feedback_tracker_get_insights' => [],
            'block_feedback_tracker_get_audit_log' => [],
        ];
    }

    /**
     * Read the plugin's db/services.php as core does.
     *
     * @return array The $functions array it declares.
     */
    private static function declared_functions(): array {
        $functions = null;
        include(\core_component::get_component_directory('block_feedback_tracker') . '/db/services.php');

        return $functions;
    }

    /**
     * $SESSION as core compares it, minus the session store's key: one entry per key, the value
     * serialised so a change inside a nested object is seen too.
     *
     * @return array Key => serialised value.
     */
    private static function session_snapshot(): array {
        global $SESSION;

        $snapshot = array_map('serialize', (array) $SESSION);
        unset($snapshot[self::SESSION_STORE_KEY]);

        return $snapshot;
    }

    /**
     * The $SESSION keys added, removed or changed between two snapshots.
     *
     * @param array $before Snapshot taken before.
     * @param array $after Snapshot taken after.
     * @return array The keys, sorted.
     */
    private static function changed_keys(array $before, array $after): array {
        $keys = array_keys(array_diff_assoc($before, $after) + array_diff_assoc($after, $before));
        sort($keys);

        return $keys;
    }

    /**
     * Every read is declared read-only, no write is, the provider exercises exactly the declared
     * set, and core's own reader of db/services.php sees the flag.
     *
     * Reading through external_function_info() is the point: it is the value
     * external_api::call_external_function() decides the session lock from.
     *
     * @return void
     */
    public function test_every_read_and_no_write_is_declared_read_only(): void {
        $functions = self::declared_functions();
        $declared = [];
        foreach ($functions as $name => $function) {
            $readonly = !empty($function['readonlysession']);
            $this->assertSame($function['type'] === 'read', $readonly, $name);
            $this->assertSame($readonly, (bool) external_api::external_function_info($name)->readonlysession, $name);
            if ($readonly) {
                $declared[] = $name;
            }
        }
        sort($declared);
        $exercised = array_keys(self::read_only_calls(1, 1));
        sort($exercised);

        $this->assertCount(12, $declared);
        $this->assertSame($exercised, $declared);
    }

    /**
     * The comparison sees a write, so an empty result below is not an empty comparison, and the
     * session store's key is the only one it leaves out.
     *
     * @return void
     */
    public function test_the_snapshot_sees_every_write_but_the_session_store(): void {
        global $SESSION;

        $this->resetAfterTest();
        $before = self::session_snapshot();
        $SESSION->block_feedback_tracker_probe = (object) ['seen' => 1];
        $added = self::session_snapshot();
        $SESSION->block_feedback_tracker_probe->seen = 2;
        $changed = self::session_snapshot();
        $SESSION->{self::SESSION_STORE_KEY} = ['probe' => 1];

        $this->assertSame(['block_feedback_tracker_probe'], self::changed_keys($before, $added));
        $this->assertSame(['block_feedback_tracker_probe'], self::changed_keys($added, $changed));
        $this->assertSame([], self::changed_keys($changed, self::session_snapshot()));
    }

    /**
     * Every read-only service leaves $SESSION as it found it, on a fixture that gives it work.
     *
     * One tracked course with a pending and a graded submission, the rollup recomputed from
     * them, the platform calendar with a holiday in the month asked for, and two audit rows;
     * the caller is the site admin with the cross-course view on, who passes every capability
     * check, so no call stops at a refusal.
     * Before each call the plugin's caches are purged and its per-request memos reset, so
     * every fill runs inside the call. The read-only branch of
     * external_api::call_external_function() is taken, as on a site with
     * $CFG->enable_read_only_sessions on.
     *
     * One require_login() runs before each snapshot. Core's after_require_login callbacks run
     * inside every service of every component, and under PHPUnit tool_mfa's writes a marker
     * ($SESSION->mfa_login_hook_test, admin/tool/mfa/lib.php); paying it first keeps that core
     * write out of the comparison without exempting any key from it.
     *
     * @return void
     */
    public function test_read_only_services_leave_the_session_untouched(): void {
        global $CFG;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugin = $generator->get_plugin_generator('block_feedback_tracker');
        $plugin->seed_default_platform_calendar();
        $plugin->create_calendar_day((int) date('Ym') * 100 + 15, 'holiday', 'Probe');
        set_config('enable_admin_view_all', 1, 'block_feedback_tracker');
        $course = $plugin->create_tracked_course();
        $courseid = (int) $course->id;
        $now = time();
        $rows = [];
        foreach (['pending' => null, 'graded' => $now - HOURSECS] as $key => $timegraded) {
            $student = $generator->create_and_enrol($course, 'student');
            $assign = $generator->create_module('assign', ['course' => $courseid]);
            $rows[$key] = $plugin->create_ledger_row([
                'courseid' => $courseid,
                'cmid' => (int) get_coursemodule_from_instance('assign', $assign->id)->id,
                'iteminstance' => (int) $assign->id,
                'userid' => (int) $student->id,
                'timesubmitted' => $now - 2 * DAYSECS,
                'timegraded' => $timegraded,
                'effectivehours' => 5.0,
                'waitinghours' => 6.0,
                'slabucket' => 'good',
            ]);
        }
        rollup_service::recompute_group($courseid, 0, $now);
        $plugin->seed_audit_log(2);
        $this->setAdminUser();
        $CFG->enable_read_only_sessions = true;

        foreach (self::read_only_calls($courseid, $rows['graded']) as $name => $args) {
            foreach (self::CACHES as $definition) {
                \cache::make('block_feedback_tracker', $definition)->purge();
            }
            process_memos::reset();
            require_login();
            $_POST['sesskey'] = sesskey();

            $before = self::session_snapshot();
            $result = external_api::call_external_function($name, $args, true);
            $after = self::session_snapshot();

            $this->assertFalse($result['error'], "$name: " . json_encode($result['exception'] ?? null));
            $this->assert_found($name, $result['data'], $courseid, $rows['graded']);
            $this->assertSame([], self::changed_keys($before, $after), "$name changed \$SESSION");
        }
    }

    /**
     * The control of each call: what it returned shows it found the fixture, so its path ran
     * and an unchanged session is not the session of a call that stopped early.
     *
     * @param string $name The service.
     * @param array $data What it returned.
     * @param int $courseid The fixture's course.
     * @param int $submissionid The fixture's graded ledger row.
     * @return void
     */
    private function assert_found(string $name, array $data, int $courseid, int $submissionid): void {
        $short = substr($name, strlen('block_feedback_tracker_'));
        if (in_array($short, ['get_responsiveness', 'get_report_scopes', 'get_academic_days'], true)) {
            $this->assertSame($courseid, (int) $data['courseid'], $name);
        } else if (in_array($short, ['get_pending_submissions', 'get_graded_submissions'], true)) {
            $this->assertSame(1, (int) $data['total'], $name);
        } else if ($short === 'get_pause_timeline') {
            $this->assertSame($submissionid, (int) $data['submissionid'], $name);
        } else if ($short === 'get_calendar') {
            $this->assertNotEmpty($data['days'], $name);
        } else if ($short === 'get_dashboard') {
            $this->assertContains($courseid, array_map('intval', array_column($data['courses'], 'courseid')), $name);
        } else if ($short === 'get_audit_log') {
            $this->assertSame(2, (int) $data['total'], $name);
        } else {
            // School comparison, grader priority list and insights: site-wide or ranked views whose
            // content depends on rollups this fixture does not age; success is what the call proves.
            $this->assertTrue($data['success'], $name);
        }
    }
}
