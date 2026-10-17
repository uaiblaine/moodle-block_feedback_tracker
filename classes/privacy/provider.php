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
 * Privacy provider for Feedback Flow.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\privacy;

use block_feedback_tracker\local\sla\slot_ledger;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Declares the plugin's personal data and exports / deletes it.
 *
 *  - {block_feedback_tracker_sub}, the per-submission ledger, holds the
 *    student (userid); it is exported and deleted per course context. It also
 *    names the teacher currently allocated to mark each submission
 *    (allocmarkerid): that teacher's allocations are exported as theirs, and
 *    erasing them clears the id while the student's row stays.
 *  - {block_feedback_tracker_alloc}, the per-marker slot table of multi-marking
 *    activities, holds two subjects: the student (userid) and the marker
 *    (marker). Each is exported their own view of a row; a student's erasure
 *    deletes their slot rows with their ledger rows, and a marker's erasure
 *    deletes the rows where they are the marker while the student's ledger
 *    row stays.
 *  - The calendar tables (cday / chours / cpause) record who last edited a
 *    row (`usermodified`) and the audit log who triggered a recompute
 *    (`triggeredby`). They live at system context; erasure clears the
 *    attribution and keeps the rows.
 *  - The rollup / trend / site / queue / bfcursor tables hold aggregates or
 *    operational state with no user link and are not declared.
 *  - Pause windows are derived from the calendar on demand
 *    (get_pause_timeline), not stored, so they are not declared either.
 *  - The two collapse-state preferences are declared through
 *    user_preference_provider; core_user's provider deletes a user's
 *    preferences, so there is no plugin-side delete path for them.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Site-configuration tables that record which administrator last touched
     * a row, and the column holding that id.
     *
     * These live at system context, which is why get_contexts_for_userid()
     * offers it — and therefore why the export and delete paths must handle
     * it too.
     *
     * @var array Table name => attribution column.
     */
    private const SYSTEM_ATTRIBUTION = [
        'block_feedback_tracker_cday' => 'usermodified',
        'block_feedback_tracker_chours' => 'usermodified',
        'block_feedback_tracker_cpause' => 'usermodified',
        'block_feedback_tracker_log' => 'triggeredby',
    ];

    /**
     * Describe what the plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'block_feedback_tracker_sub',
            [
                'courseid'         => 'privacy:metadata:sub:courseid',
                'groupid'          => 'privacy:metadata:sub:groupid',
                'cmid'             => 'privacy:metadata:sub:cmid',
                'userid'           => 'privacy:metadata:sub:userid',
                'attemptnumber'    => 'privacy:metadata:sub:attemptnumber',
                'cycle'            => 'privacy:metadata:sub:cycle',
                'submissionstatus' => 'privacy:metadata:sub:submissionstatus',
                'timesubmitted'    => 'privacy:metadata:sub:timesubmitted',
                'timegraded'       => 'privacy:metadata:sub:timegraded',
                'timemarked'       => 'privacy:metadata:sub:timemarked',
                'timereleased'     => 'privacy:metadata:sub:timereleased',
                'timeclosed'       => 'privacy:metadata:sub:timeclosed',
                'closedsource'     => 'privacy:metadata:sub:closedsource',
                'timedismissed'    => 'privacy:metadata:sub:timedismissed',
                'gradestate'       => 'privacy:metadata:sub:gradestate',
                'timeallocated'    => 'privacy:metadata:sub:timeallocated',
                'allocmarkerid'    => 'privacy:metadata:sub:allocmarkerid',
                'timeallocmarker'  => 'privacy:metadata:sub:timeallocmarker',
                'queuehours'       => 'privacy:metadata:sub:queuehours',
                'allochours'       => 'privacy:metadata:sub:allochours',
                'waitinghours'     => 'privacy:metadata:sub:waitinghours',
                'effectivehours'   => 'privacy:metadata:sub:effectivehours',
                'effectivedays'    => 'privacy:metadata:sub:effectivedays',
                'slabucket'        => 'privacy:metadata:sub:slabucket',
            ],
            'privacy:metadata:sub'
        );

        $collection->add_database_table(
            'block_feedback_tracker_alloc',
            [
                'courseid' => 'privacy:metadata:alloc:courseid',
                'cmid' => 'privacy:metadata:alloc:cmid',
                'userid' => 'privacy:metadata:alloc:userid',
                'marker' => 'privacy:metadata:alloc:marker',
                'position' => 'privacy:metadata:alloc:position',
                'optional' => 'privacy:metadata:alloc:optional',
                'timeallocated' => 'privacy:metadata:alloc:timeallocated',
                'allocsource' => 'privacy:metadata:alloc:allocsource',
                'timemarked' => 'privacy:metadata:alloc:timemarked',
                'markhours' => 'privacy:metadata:alloc:markhours',
                'markdays' => 'privacy:metadata:alloc:markdays',
                'timeremoved' => 'privacy:metadata:alloc:timeremoved',
            ],
            'privacy:metadata:alloc'
        );

        $collection->add_database_table(
            'block_feedback_tracker_cday',
            ['usermodified' => 'privacy:metadata:cday:usermodified'],
            'privacy:metadata:cday'
        );
        $collection->add_database_table(
            'block_feedback_tracker_chours',
            ['usermodified' => 'privacy:metadata:chours:usermodified'],
            'privacy:metadata:chours'
        );
        $collection->add_database_table(
            'block_feedback_tracker_cpause',
            ['usermodified' => 'privacy:metadata:cpause:usermodified'],
            'privacy:metadata:cpause'
        );
        $collection->add_database_table(
            'block_feedback_tracker_log',
            ['triggeredby' => 'privacy:metadata:log:triggeredby'],
            'privacy:metadata:log'
        );

        // Collapse state of the dashboard's hero and insights panels.
        $collection->add_user_preference(
            'block_feedback_tracker_dashboard_collapsed',
            'privacy:metadata:preference:dashboard_collapsed'
        );
        // Collapse state of the pending report's hero and academic-days strip.
        $collection->add_user_preference(
            'block_feedback_tracker_report_collapsed',
            'privacy:metadata:preference:report_collapsed'
        );

        return $collection;
    }

    /**
     * Export every plugin user-preference held against $userid into the
     * privacy writer's preferences bucket. The collapse state is
     * surfaced as a localised human-readable label rather than the raw
     * '0' / '1' string the API stores.
     *
     * @param int $userid
     * @return void
     */
    public static function export_user_preferences(int $userid): void {
        $dashboard = get_user_preferences('block_feedback_tracker_dashboard_collapsed', null, $userid);
        if ($dashboard !== null) {
            $description = (string) $dashboard === '1'
                ? get_string('privacy:preference:dashboard_collapsed_collapsed', 'block_feedback_tracker')
                : get_string('privacy:preference:dashboard_collapsed_expanded', 'block_feedback_tracker');
            writer::export_user_preference(
                'block_feedback_tracker',
                'block_feedback_tracker_dashboard_collapsed',
                (string) $dashboard,
                $description
            );
        }

        $report = get_user_preferences('block_feedback_tracker_report_collapsed', null, $userid);
        if ($report !== null) {
            $description = (string) $report === '1'
                ? get_string('privacy:preference:report_collapsed_collapsed', 'block_feedback_tracker')
                : get_string('privacy:preference:report_collapsed_expanded', 'block_feedback_tracker');
            writer::export_user_preference(
                'block_feedback_tracker',
                'block_feedback_tracker_report_collapsed',
                (string) $report,
                $description
            );
        }
    }

    /**
     * Course contexts where the user has ledger rows, is the allocated marker
     * of one, or appears on a slot row as the student or the marker; system
     * context when they appear as `usermodified` on any calendar/pause table or
     * `triggeredby` on the audit log.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {block_feedback_tracker_sub} s
                  JOIN {context} ctx ON ctx.contextlevel = :coursectxlevel AND ctx.instanceid = s.courseid
                 WHERE s.userid = :userid";
        $contextlist->add_from_sql(
            $sql,
            ['userid' => $userid, 'coursectxlevel' => CONTEXT_COURSE]
        );

        // A separate query rather than an OR, so each column is matched on its own.
        $sql = "SELECT ctx.id
                  FROM {block_feedback_tracker_sub} s
                  JOIN {context} ctx ON ctx.contextlevel = :coursectxlevel AND ctx.instanceid = s.courseid
                 WHERE s.allocmarkerid = :markerid";
        $contextlist->add_from_sql(
            $sql,
            ['markerid' => $userid, 'coursectxlevel' => CONTEXT_COURSE]
        );

        // The slot table, once per column for the same reason.
        foreach (['userid', 'marker'] as $column) {
            $sql = "SELECT ctx.id
                      FROM {block_feedback_tracker_alloc} a
                      JOIN {context} ctx ON ctx.contextlevel = :coursectxlevel AND ctx.instanceid = a.courseid
                     WHERE a.$column = :userid";
            $contextlist->add_from_sql(
                $sql,
                ['userid' => $userid, 'coursectxlevel' => CONTEXT_COURSE]
            );
        }

        // Select ctx.id from {context} rather than a bare :placeholder:
        // PostgreSQL types a selected placeholder as text, and the join that
        // contextlist::add_from_sql() wraps around this query compares it with
        // a bigint.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.id = :sysctxid
                   AND (
                          EXISTS (SELECT 1 FROM {block_feedback_tracker_cday} WHERE usermodified = :u1)
                       OR EXISTS (SELECT 1 FROM {block_feedback_tracker_chours} WHERE usermodified = :u2)
                       OR EXISTS (SELECT 1 FROM {block_feedback_tracker_cpause} WHERE usermodified = :u3)
                       OR EXISTS (SELECT 1 FROM {block_feedback_tracker_log} WHERE triggeredby = :u4)
                       )";
        $contextlist->add_from_sql($sql, [
            'sysctxid' => \context_system::instance()->id,
            'u1' => $userid, 'u2' => $userid, 'u3' => $userid, 'u4' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Users with ledger rows in a course context, allocated to mark one, or on
     * a slot row as the student or the marker; or who modified any calendar /
     * audit row at system context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context instanceof \context_course) {
            $userlist->add_from_sql(
                'userid',
                'SELECT userid FROM {block_feedback_tracker_sub} WHERE courseid = :courseid',
                ['courseid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'allocmarkerid',
                'SELECT allocmarkerid FROM {block_feedback_tracker_sub} WHERE courseid = :courseid AND allocmarkerid > 0',
                ['courseid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'userid',
                'SELECT userid FROM {block_feedback_tracker_alloc} WHERE courseid = :courseid',
                ['courseid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'marker',
                'SELECT marker FROM {block_feedback_tracker_alloc} WHERE courseid = :courseid',
                ['courseid' => $context->instanceid]
            );
            return;
        }

        if ($context instanceof \context_system) {
            $userlist->add_from_sql(
                'usermodified',
                'SELECT usermodified FROM {block_feedback_tracker_cday} WHERE usermodified IS NOT NULL',
                []
            );
            $userlist->add_from_sql(
                'usermodified',
                'SELECT usermodified FROM {block_feedback_tracker_chours} WHERE usermodified IS NOT NULL',
                []
            );
            $userlist->add_from_sql(
                'usermodified',
                'SELECT usermodified FROM {block_feedback_tracker_cpause} WHERE usermodified IS NOT NULL',
                []
            );
            $userlist->add_from_sql(
                'triggeredby',
                'SELECT triggeredby FROM {block_feedback_tracker_log} WHERE triggeredby IS NOT NULL',
                []
            );
        }
    }

    /**
     * Export the user's ledger rows and the submissions they are allocated to
     * mark, per course context, and the site-configuration rows they are
     * attributed on at system context.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                self::export_system_attribution($context, (int) $userid);
                continue;
            }
            if (!($context instanceof \context_course)) {
                continue;
            }

            self::export_marker_allocations($context, (int) $userid);
            self::export_marker_slots($context, (int) $userid);
            self::export_student_slots($context, (int) $userid);

            $rows = $DB->get_records('block_feedback_tracker_sub', [
                'courseid' => $context->instanceid,
                'userid'   => $userid,
            ]);
            if (empty($rows)) {
                continue;
            }

            // Pause windows are not exported: they are derived from the
            // calendar on demand (get_pause_timeline), not stored.
            $export = ['submissions' => []];
            foreach ($rows as $r) {
                $export['submissions'][] = [
                    'cmid'           => (int) $r->cmid,
                    'groupid'        => (int) $r->groupid,
                    'attemptnumber'  => (int) $r->attemptnumber,
                    'cycle'          => (int) $r->cycle,
                    'submissionstatus' => (string) $r->submissionstatus,
                    'timesubmitted'  => transform::datetime((int) $r->timesubmitted),
                    'timegraded'     => $r->timegraded !== null ? transform::datetime((int) $r->timegraded) : null,
                    'timemarked'     => $r->timemarked !== null ? transform::datetime((int) $r->timemarked) : null,
                    'timereleased'   => $r->timereleased !== null ? transform::datetime((int) $r->timereleased) : null,
                    'timeclosed'     => $r->timeclosed !== null ? transform::datetime((int) $r->timeclosed) : null,
                    'closedsource'   => $r->closedsource !== null ? (string) $r->closedsource : null,
                    'timedismissed'  => $r->timedismissed !== null ? transform::datetime((int) $r->timedismissed) : null,
                    'gradestate'     => $r->gradestate !== null ? (string) $r->gradestate : null,
                    'timeallocated'  => $r->timeallocated !== null ? transform::datetime((int) $r->timeallocated) : null,
                    'allocmarkerid'  => (int) $r->allocmarkerid,
                    'timeallocmarker' => $r->timeallocmarker !== null ? transform::datetime((int) $r->timeallocmarker) : null,
                    'queuehours'     => $r->queuehours !== null ? (float) $r->queuehours : null,
                    'allochours'     => $r->allochours !== null ? (float) $r->allochours : null,
                    'waitinghours'   => $r->waitinghours !== null ? (float) $r->waitinghours : null,
                    'effectivehours' => $r->effectivehours !== null ? (float) $r->effectivehours : null,
                    'effectivedays'  => $r->effectivedays !== null ? (float) $r->effectivedays : null,
                    'slabucket'      => (string) $r->slabucket,
                ];
            }

            $subcontext = [
                get_string('pluginname', 'block_feedback_tracker'),
                get_string('privacy:path:submissions', 'block_feedback_tracker'),
            ];
            writer::with_context($context)->export_data($subcontext, (object) $export);
        }
    }

    /**
     * Delete every user's ledger rows in a course context, or clear every
     * attribution at system context.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context instanceof \context_system) {
            self::clear_system_attribution(null);
            return;
        }
        if (!($context instanceof \context_course)) {
            return;
        }
        self::delete_course_data((int) $context->instanceid, null);
    }

    /**
     * Delete the user's ledger rows in the approved course contexts, and clear
     * their attribution at system context.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                self::clear_system_attribution([$userid]);
                continue;
            }
            if ($context instanceof \context_course) {
                self::delete_course_data((int) $context->instanceid, $userid);
            }
        }
    }

    /**
     * Delete data for the listed users in one context.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        if ($context instanceof \context_system) {
            self::clear_system_attribution(array_map('intval', $userids));
            return;
        }
        if (!($context instanceof \context_course)) {
            return;
        }
        foreach ($userids as $userid) {
            self::delete_course_data((int) $context->instanceid, (int) $userid);
        }
    }

    /**
     * Export the submissions one user is currently allocated to mark in a
     * course.
     *
     * The allocation is the marker's data: which activity and attempt, since
     * when, and how long they took once allocated. The student's identity is
     * the student's data and is left out.
     *
     * @param \context_course $context
     * @param int $userid The marker.
     * @return void
     */
    private static function export_marker_allocations(\context_course $context, int $userid): void {
        global $DB;

        $rows = $DB->get_records(
            'block_feedback_tracker_sub',
            ['courseid' => $context->instanceid, 'allocmarkerid' => $userid],
            'cmid ASC, id ASC',
            'id, cmid, attemptnumber, cycle, timeallocmarker, allochours'
        );
        if (empty($rows)) {
            return;
        }

        $allocations = [];
        foreach ($rows as $r) {
            $allocations[] = [
                'cmid' => (int) $r->cmid,
                'attemptnumber' => (int) $r->attemptnumber,
                'cycle' => (int) $r->cycle,
                'timeallocmarker' => $r->timeallocmarker !== null ? transform::datetime((int) $r->timeallocmarker) : null,
                'allochours' => $r->allochours !== null ? (float) $r->allochours : null,
            ];
        }

        $subcontext = [
            get_string('pluginname', 'block_feedback_tracker'),
            get_string('privacy:path:allocations', 'block_feedback_tracker'),
        ];
        writer::with_context($context)->export_data($subcontext, (object) ['allocations' => $allocations]);
    }

    /**
     * Export the slot rows where one user is the marker, in a course.
     *
     * The marker's own record: which activity, attempt and cycle, the slot,
     * since when they held it and how long they took. The student is the
     * student's data and is left out, as in export_marker_allocations().
     *
     * @param \context_course $context
     * @param int $userid The marker.
     * @return void
     */
    private static function export_marker_slots(\context_course $context, int $userid): void {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT a.id, a.cmid, l.attemptnumber, l.cycle, a.position, a.optional, a.timeallocated,
                    a.allocsource, a.timemarked, a.markhours, a.markdays, a.timeremoved
               FROM {block_feedback_tracker_alloc} a
               JOIN {block_feedback_tracker_sub} l ON l.id = a.subid
              WHERE a.courseid = :courseid AND a.marker = :marker
           ORDER BY a.cmid ASC, a.id ASC",
            ['courseid' => $context->instanceid, 'marker' => $userid]
        );
        if (empty($rows)) {
            return;
        }
        $slots = [];
        foreach ($rows as $r) {
            $slots[] = self::slot_export($r);
        }
        $subcontext = [
            get_string('pluginname', 'block_feedback_tracker'),
            get_string('privacy:path:markerslots', 'block_feedback_tracker'),
        ];
        writer::with_context($context)->export_data($subcontext, (object) ['slots' => $slots]);
    }

    /**
     * Export the slot rows of one student's submissions, in a course.
     *
     * Which marker held each slot of the student's work and when they
     * completed it, as the student's ledger export names the allocated marker.
     *
     * @param \context_course $context
     * @param int $userid The student.
     * @return void
     */
    private static function export_student_slots(\context_course $context, int $userid): void {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT a.id, a.cmid, l.attemptnumber, l.cycle, a.marker, a.position, a.optional, a.timeallocated,
                    a.allocsource, a.timemarked, a.timeremoved
               FROM {block_feedback_tracker_alloc} a
               JOIN {block_feedback_tracker_sub} l ON l.id = a.subid
              WHERE a.courseid = :courseid AND a.userid = :userid
           ORDER BY a.cmid ASC, a.id ASC",
            ['courseid' => $context->instanceid, 'userid' => $userid]
        );
        if (empty($rows)) {
            return;
        }
        $slots = [];
        foreach ($rows as $r) {
            $slots[] = ['marker' => (int) $r->marker] + self::slot_export($r, false);
        }
        $subcontext = [
            get_string('pluginname', 'block_feedback_tracker'),
            get_string('privacy:path:slots', 'block_feedback_tracker'),
        ];
        writer::with_context($context)->export_data($subcontext, (object) ['slots' => $slots]);
    }

    /**
     * The fields of a slot row shown to a subject.
     *
     * @param \stdClass $r Slot row joined with its ledger row's attempt and cycle.
     * @param bool $withmeasures True for the marker's own export, which adds the marker's turnaround.
     * @return array
     */
    private static function slot_export(\stdClass $r, bool $withmeasures = true): array {
        $row = [
            'cmid' => (int) $r->cmid,
            'attemptnumber' => (int) $r->attemptnumber,
            'cycle' => (int) $r->cycle,
            'position' => (int) $r->position,
            'optional' => transform::yesno((int) $r->optional === 1),
            'timeallocated' => $r->timeallocated !== null ? transform::datetime((int) $r->timeallocated) : null,
            'allocsource' => (string) $r->allocsource,
            'timemarked' => $r->timemarked !== null ? transform::datetime((int) $r->timemarked) : null,
            'timeremoved' => $r->timeremoved !== null ? transform::datetime((int) $r->timeremoved) : null,
        ];
        if ($withmeasures) {
            $row['markhours'] = $r->markhours !== null ? (float) $r->markhours : null;
            $row['markdays'] = $r->markdays !== null ? (int) $r->markdays : null;
        }
        return $row;
    }

    /**
     * Export the site-configuration rows one user is attributed on.
     *
     * These rows are site configuration rather than the user's own content,
     * so what is exported is the attribution: which rows they last touched,
     * and when.
     *
     * @param \context $context The system context.
     * @param int $userid
     * @return void
     */
    private static function export_system_attribution(\context $context, int $userid): void {
        global $DB;

        $entries = [];
        foreach (self::SYSTEM_ATTRIBUTION as $table => $column) {
            $rows = $DB->get_records($table, [$column => $userid]);
            foreach ($rows as $r) {
                $entry = ['table' => $table, 'id' => (int) $r->id];
                if (isset($r->timemodified)) {
                    $entry['timemodified'] = transform::datetime((int) $r->timemodified);
                } else if (isset($r->timestarted)) {
                    $entry['timestarted'] = transform::datetime((int) $r->timestarted);
                }
                $entries[] = $entry;
            }
        }

        if (empty($entries)) {
            return;
        }

        $subcontext = [
            get_string('pluginname', 'block_feedback_tracker'),
            get_string('privacy:path:siteconfig', 'block_feedback_tracker'),
        ];
        writer::with_context($context)->export_data($subcontext, (object) ['records' => $entries]);
    }

    /**
     * Drop the user link from the site-configuration tables.
     *
     * The rows themselves are site configuration and must survive — deleting
     * them would silently rewrite the academic calendar for everyone. Only the
     * attribution is removed, which is what the metadata declares and all that
     * an erasure request covers here.
     *
     * @param int[]|null $userids Users to clear, or null for every user.
     * @return void
     */
    private static function clear_system_attribution(?array $userids): void {
        global $DB;

        foreach (self::SYSTEM_ATTRIBUTION as $table => $column) {
            if ($userids === null) {
                $DB->set_field_select($table, $column, null, "$column IS NOT NULL");
                continue;
            }
            if (empty($userids)) {
                continue;
            }
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
            $DB->set_field_select($table, $column, null, "$column $insql", $inparams);
        }
    }

    /**
     * Drop ledger rows for (courseid, optional userid) and re-enqueue
     * the affected (course, group) tuples so the rollup re-runs without the
     * deleted contributions.
     *
     * For one user, the submissions they are only allocated to mark belong to
     * their students and stay: the marker's id is cleared from them instead,
     * and the slot rows where they are the marker are deleted. No rollup reads
     * either, so nothing is re-enqueued for them. The user's own slot rows as
     * a student go with their ledger rows.
     *
     * @param int $courseid
     * @param int|null $userid Restrict to one user, or null to drop the whole course.
     * @return void
     */
    private static function delete_course_data(int $courseid, ?int $userid): void {
        global $DB;
        $params = ['courseid' => $courseid];
        $where = 'courseid = :courseid';
        if ($userid !== null) {
            $DB->set_field_select(
                'block_feedback_tracker_sub',
                'allocmarkerid',
                0,
                'courseid = :courseid AND allocmarkerid = :markerid',
                ['courseid' => $courseid, 'markerid' => $userid]
            );
            slot_ledger::delete_for_marker_in_course($courseid, $userid);
            $params['userid'] = $userid;
            $where .= ' AND userid = :userid';
        } else {
            slot_ledger::delete_for_course($courseid);
        }

        $rows = $DB->get_records_select(
            'block_feedback_tracker_sub',
            $where,
            $params,
            '',
            'id, groupid'
        );
        if (empty($rows)) {
            return;
        }

        $tuples = [];
        foreach ($rows as $r) {
            $tuples[(int) $r->groupid] = true;
        }

        slot_ledger::delete_for_ledger_where($where, $params);
        $DB->delete_records_select('block_feedback_tracker_sub', $where, $params);

        foreach (array_keys($tuples) as $groupid) {
            \block_feedback_tracker\local\sla\dirty_queue::enqueue(
                $courseid,
                (int) $groupid,
                \block_feedback_tracker\local\sla\dirty_queue::REASON_BULK
            );
        }
    }
}
