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
 * Data generator class
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Block generator with helpers for seeding plugin-owned tables directly.
 *
 * Usage from tests:
 *   $g = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
 *   $g->seed_default_platform_calendar();
 *   $g->create_ledger_row(['courseid' => $c->id, 'effectivehours' => 12.0, 'timegraded' => time()]);
 */
class block_feedback_tracker_generator extends testing_block_generator {
    /**
     * Seed the platform calendar config + Mon-Fri 08:00-18:00 business hours.
     * Idempotent.
     *
     * @return void
     */
    public function seed_default_platform_calendar(): void {
        global $DB;
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('grading_during_pause_mode', 'clipped', 'block_feedback_tracker');
        set_config('sla_goal_hours', '24', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');
        set_config('weight_compliance', '0.40', 'block_feedback_tracker');
        set_config('weight_median', '0.25', 'block_feedback_tracker');
        set_config('weight_critical', '0.15', 'block_feedback_tracker');
        set_config('weight_pending', '0.10', 'block_feedback_tracker');
        set_config('weight_trend', '0.10', 'block_feedback_tracker');

        if (!$DB->record_exists('block_feedback_tracker_chours', [])) {
            $now = time();
            for ($dow = 0; $dow <= 4; $dow++) {
                $DB->insert_record('block_feedback_tracker_chours', (object) [
                    'dayofweek'    => $dow,
                    'starttime'    => 480,
                    'endtime'      => 1080,
                    'enabled'      => 1,
                    'timecreated'  => $now,
                    'timemodified' => $now,
                ]);
            }
        }
    }

    /**
     * Insert a {block_feedback_tracker_cday} row.
     *
     * @param int $daydate YYYYMMDD as int.
     * @param string $daytype schoolday|holiday|recess|closed|optional.
     * @param string|null $note
     * @return int Row id.
     */
    public function create_calendar_day(int $daydate, string $daytype, ?string $note = null): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate'      => $daydate,
            'daytype'      => $daytype,
            'note'         => $note,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Insert a {block_feedback_tracker_cpause} row.
     *
     * @param array $overrides Keys: scopelevel, scopeid, contextid, reason, timestart, timeend, note.
     * @return int Row id.
     */
    public function create_pause_window(array $overrides): int {
        global $DB;
        $now = time();
        $defaults = [
            'scopelevel'   => 'site',
            'scopeid'      => 0,
            'contextid'    => \context_system::instance()->id,
            'reason'       => 'other',
            'timestart'    => $now - 86400,
            'timeend'      => $now,
            'note'         => null,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];
        $rec = (object) array_merge($defaults, $overrides);
        return (int) $DB->insert_record('block_feedback_tracker_cpause', $rec);
    }

    /**
     * Insert a {block_feedback_tracker_sub} row with sensible defaults; only
     * the keys in $overrides are set explicitly.
     *
     * @param array $overrides
     * @return int Row id.
     */
    public function create_ledger_row(array $overrides = []): int {
        global $DB;
        static $cmid = 90000;
        $cmid++;
        $now = time();
        $defaults = [
            'courseid'         => 1,
            'groupid'          => 0,
            'cmid'             => $cmid,
            'iteminstance'     => $cmid,
            'userid'           => 1,
            'attemptnumber'    => 0,
            'cycle'            => 0,
            'submissionstatus' => 'submitted',
            'timesubmitted'    => $now - 7200,
            'timegraded'       => null,
            'timemarked'       => null,
            'timereleased'     => null,
            'timeclosed'       => null,
            'islatest'         => 1,
            'iscurrent'        => 1,
            'gradestate'       => null,
            'teamgroupid'      => 0,
            'timeallocated'    => null,
            'timeallocmarker'  => null,
            'allocmarkerid'    => 0,
            'allocsource'      => null,
            'timeopens'        => null,
            'timecloses'       => null,
            'timecutoff'       => null,
            'hasrule'          => 0,
            'waitinghours'     => 0.0,
            'effectivehours'   => 0.0,
            'effectiveasof'    => $now,
            'effectivecalver'  => 1,
            'slabucket'        => 'pending',
            'timecreated'      => $now,
            'timemodified'     => $now,
        ];
        $rec = (object) array_merge($defaults, $overrides);
        /* A fixture that sets timegraded describes a graded row, so mirror the
         * two companion stamps unless the caller pinned them itself. Production
         * writes all three together (marking workflow off is the common case,
         * where they are equal by definition), and a fixture that set only
         * timegraded would otherwise describe a state the ledger never
         * produces. */
        if ($rec->timegraded !== null) {
            if (!array_key_exists('timemarked', $overrides)) {
                $rec->timemarked = $rec->timegraded;
            }
            if (!array_key_exists('timeclosed', $overrides)) {
                $rec->timeclosed = $rec->timegraded;
            }
            if (!array_key_exists('gradestate', $overrides)) {
                $rec->gradestate = 'graded';
            }
        }
        return (int) $DB->insert_record('block_feedback_tracker_sub', $rec);
    }

    /**
     * Create a course the plugin will actually process.
     *
     * The plugin is strict opt-in: `course_access::is_processable()` returns
     * false unless a `feedback_tracker` block instance lives on the course's
     * own context. This bundles the three steps every DB test needs — create
     * the course, drop the block on it, and flush the per-request memo (which
     * may hold a stale `false` against a recycled courseid from an earlier
     * test in the same class).
     *
     * @param array $opts Passed to create_course(); `groupmode` and
     *                    `groupmodeforce` are honoured for group-visibility tests.
     * @return \stdClass The course record.
     */
    public function create_tracked_course(array $opts = []): \stdClass {
        $course = \phpunit_util::get_data_generator()->create_course((object) $opts);
        \phpunit_util::get_data_generator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        \block_feedback_tracker\local\sla\course_access::reset_memo();
        return $course;
    }

    /**
     * Enrol a new user in a course, optionally adding them to a group.
     *
     * @param int $courseid
     * @param string $roleshortname Archetype shortname, e.g. 'editingteacher'.
     * @param int|null $groupid When set, the user joins this group.
     * @return \stdClass The user record.
     */
    public function create_user_in_role(int $courseid, string $roleshortname, ?int $groupid = null): \stdClass {
        $course = get_course($courseid);
        $user = \phpunit_util::get_data_generator()->create_and_enrol($course, $roleshortname);
        if ($groupid !== null) {
            groups_add_member($groupid, $user->id);
        }
        return $user;
    }

    /**
     * Prohibit one capability for a role in a context.
     *
     * The `accesslib_clear_all_caches_for_unit_testing()` call is not optional:
     * without it the change is invisible to `has_capability()` for the rest of
     * the request, which is the classic cause of a capability test that passes
     * alone and fails when the suite runs in a different order.
     *
     * @param string $capability
     * @param \context $context
     * @param string $roleshortname
     * @return void
     */
    public function deny_capability(string $capability, \context $context, string $roleshortname): void {
        global $DB;
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $roleshortname], MUST_EXIST);
        assign_capability($capability, CAP_PROHIBIT, $roleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Insert a {block_feedback_tracker_group} rollup row.
     *
     * Seeding this table is a hard prerequisite for anything that walks it —
     * `calendar\observer::enqueue_all_groups()` iterates these rows, so a test
     * that skips this helper enqueues nothing and passes vacuously.
     *
     * @param array $overrides Any column of the rollup table; only courseid is required by the schema.
     * @return int Row id.
     */
    public function create_rollup_row(array $overrides = []): int {
        global $DB;
        $now = time();
        $defaults = [
            'courseid'             => 1,
            'groupid'              => 0,
            'pending'              => 0,
            'critical'             => 0,
            'overgoal'             => 0,
            'numgraded30d'         => 0,
            'compliance_pct'       => 100.0,
            'median_eff_h'         => 0.0,
            'responsiveness_score' => 100.0,
            'score_band'           => 'excellent',
            'timerecomputed'       => $now,
            'timemodified'         => $now,
        ];
        return (int) $DB->insert_record('block_feedback_tracker_group', (object) array_merge($defaults, $overrides));
    }

    /**
     * Seed audit-log rows through the production writer.
     *
     * Goes via `recompute_log::record()` rather than hand-built inserts so the
     * fixture keeps matching the schema (and the JSON shape of `details`)
     * instead of rotting when either changes.
     *
     * @param int $count How many rows to write.
     * @param array $overrides Keys: reason, affectedrows, triggeredby, details, timestarted, timefinished.
     * @return array The inserted row ids, oldest first.
     */
    public function seed_audit_log(int $count, array $overrides = []): array {
        $now = time();
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = \block_feedback_tracker\local\audit\recompute_log::record(
                (string) ($overrides['reason'] ?? 'manual'),
                (int) ($overrides['affectedrows'] ?? 1),
                array_key_exists('triggeredby', $overrides) ? $overrides['triggeredby'] : null,
                array_key_exists('details', $overrides) ? $overrides['details'] : null,
                (int) ($overrides['timestarted'] ?? $now - (($count - $i) * 60)),
                (int) ($overrides['timefinished'] ?? $now - (($count - $i) * 60) + 1)
            );
        }
        return $ids;
    }

    /**
     * Switch the display ruler to days (or back to hours).
     *
     * The unit and its thresholds must move together — setting one without the
     * other leaves a day-mode test silently asserting against the hour ruler.
     *
     * @param string $unit 'business_days' or 'hours'.
     * @param string $daythresholds Comma-separated day thresholds for the bucket ladder.
     * @return void
     */
    public function set_display_unit(string $unit = 'business_days', string $daythresholds = '2,5,10'): void {
        set_config('display_time_unit', $unit, 'block_feedback_tracker');
        set_config('bucket_thresholds_days', $daythresholds, 'block_feedback_tracker');
    }

    /**
     * Create a graded assign submission and fire the submission_graded event.
     *
     * Wraps the whole mod_assign dance: the module, the {assign_submission}
     * row the ledger upsert requires, the {assign_grades} row, and the event —
     * which must go through `create_from_grade()` because `::create()` throws.
     *
     * The grade record is deliberately **re-read from the database** before the
     * event is built. `add_record_snapshot()` validates the snapshot's field
     * list against the live table, so a hand-built object is missing whatever
     * column core added last (`penalty`, as of Moodle 5.1) and raises an
     * unexpected debugging() notice.
     *
     * @param \stdClass $course Course to attach the assign to.
     * @param \stdClass $user The submitting user.
     * @param int $timesubmitted
     * @param int $timegraded
     * @param array $opts Keys: cm (reuse an existing course module), grade (float), attemptnumber (int).
     * @return array [cm, submission, grade] with the grade re-read from the database.
     */
    public function create_graded_submission(
        \stdClass $course,
        \stdClass $user,
        int $timesubmitted,
        int $timegraded,
        array $opts = []
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $cm = $opts['cm'] ?? null;
        if ($cm === null) {
            $instance = \phpunit_util::get_data_generator()->create_module('assign', ['course' => $course->id]);
            $cm = get_coursemodule_from_instance('assign', $instance->id);
        }
        $context = \context_module::instance($cm->id);
        $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
        $attempt = (int) ($opts['attemptnumber'] ?? 0);

        $submission = (object) [
            'assignment'    => $assign->id,
            'userid'        => $user->id,
            'attemptnumber' => $attempt,
            'timecreated'   => $timesubmitted,
            'timemodified'  => $timesubmitted,
            'status'        => 'submitted',
            'groupid'       => 0,
            'latest'        => 1,
        ];
        $submission->id = $DB->insert_record('assign_submission', $submission);

        $grade = (object) [
            'assignment'    => $assign->id,
            'userid'        => $user->id,
            'attemptnumber' => $attempt,
            'grader'        => 2,
            'grade'         => (float) ($opts['grade'] ?? 50.0),
            'timecreated'   => $timegraded,
            'timemodified'  => $timegraded,
        ];
        $grade->id = $DB->insert_record('assign_grades', $grade);
        /* Re-read so the record snapshot carries every column core defines,
         * including any added after this helper was written. */
        $grade = $DB->get_record('assign_grades', ['id' => $grade->id], '*', MUST_EXIST);

        $assigninst = new \assign($context, $cm, $course);
        \mod_assign\event\submission_graded::create_from_grade($assigninst, $grade)->trigger();

        return [$cm, $submission, $grade];
    }

    /**
     * Seed a scale fixture: N courses × M groups × K submissions per group.
     * Returns aggregate counts so tests can size their assertions.
     *
     * @param int $courses
     * @param int $groupspercourse
     * @param int $subspergroup
     * @return array{courses:int, groups:int, submissions:int}
     */
    public function seed_scale_fixture(int $courses, int $groupspercourse, int $subspergroup): array {
        $totalcourses = 0;
        $totalgroups = 0;
        $totalsubs = 0;
        $now = time();
        $datagen = \phpunit_util::get_data_generator();
        for ($c = 0; $c < $courses; $c++) {
            $course = $datagen->create_course();
            $totalcourses++;
            for ($g = 0; $g < $groupspercourse; $g++) {
                $group = $datagen->create_group(['courseid' => $course->id]);
                $totalgroups++;
                for ($s = 0; $s < $subspergroup; $s++) {
                    $age = ($s + 1) * 3600; // 1h, 2h, ... back
                    $this->create_ledger_row([
                        'courseid'        => (int) $course->id,
                        'groupid'         => (int) $group->id,
                        'userid'          => $s + 1,
                        'timesubmitted'   => $now - $age,
                        'timegraded'      => $now - max(0, $age - 1800),
                        'waitinghours'    => $age / 3600.0,
                        'effectivehours' => min($age / 3600.0, 24.0),
                        'slabucket'       => 'excellent',
                    ]);
                    $totalsubs++;
                }
            }
        }
        return ['courses' => $totalcourses, 'groups' => $totalgroups, 'submissions' => $totalsubs];
    }
}
