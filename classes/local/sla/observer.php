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
 * SLA event observers.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Thin observer handlers that turn assign / group / course events into
 * ledger upserts plus dirty-queue entries. All heavy lifting (academic-time
 * engine, rollup, score) runs out-of-band: the observer's job is just to
 * record that something changed.
 *
 * Every handler is idempotent: replaying the same event leaves the ledger
 * in the same state (the unique key on cmid/userid/attemptnumber enforces
 * this).
 */
class observer {
    /** Ceiling on rows re-derived from one identities-revealed event. */
    private const REVEAL_MAX_ROWS = 20000;

    /** Rows per adhoc backfill task dispatched by identities_revealed. */
    private const REVEAL_CHUNK = 50;

    /**
     * Queue one adhoc backfill batch, logging rather than propagating a
     * failure: an observer must never abort the action that triggered it.
     *
     * @param array $rows Row descriptors for backfill_one_submission.
     * @return void
     */
    private static function queue_backfill(array $rows): void {
        try {
            $task = new \block_feedback_tracker\task\backfill_one_submission();
            $task->set_custom_data(['rows' => $rows]);
            \core\task\manager::queue_adhoc_task($task, true);
        } catch (\Throwable $e) {
            debugging(sprintf(
                'block_feedback_tracker: could not queue backfill for %d row(s): %s',
                count($rows),
                $e->getMessage()
            ));
        }
    }

    /**
     * Resolve the attempt number core currently considers latest for a user.
     *
     * The workflow and allocation events are user-scoped — {assign_user_flags}
     * has no attemptnumber column — so the target attempt has to be derived.
     * `latest` is core's own authority and is what the ledger mirrors.
     *
     * @param int $cmid
     * @param int $userid
     * @return int|null Null when the user has no submission row.
     */
    private static function latest_attempt_number(int $cmid, int $userid): ?int {
        global $DB;
        $row = $DB->get_record_sql(
            "SELECT s.attemptnumber
               FROM {assign_submission} s
               JOIN {assign} a ON a.id = s.assignment
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE cm.id = :cmid AND s.userid = :userid
           ORDER BY s.latest DESC, s.attemptnumber DESC",
            ['modname' => 'assign', 'cmid' => $cmid, 'userid' => $userid],
            IGNORE_MULTIPLE
        );
        return $row ? (int) $row->attemptnumber : null;
    }

    /**
     * Submission state change (created, status updated, assessable submitted).
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function submission_changed(\core\event\base $event): void {
        $cmid = (int) ($event->contextinstanceid ?? 0);
        if ($cmid <= 0) {
            return;
        }
        /* The assignsubmission_* events override objecttable, so their
         * objectid is the SUBPLUGIN row id (assignsubmission_onlinetext.id /
         * assignsubmission_file.id) and the real {assign_submission}.id lives
         * in other['submissionid']. Reading objectid against
         * {assign_submission} looks up one table's id in another: it either
         * finds nothing, or finds an unrelated row and writes a ledger entry
         * for the wrong student. The mod_assign events carry the submission id
         * in objectid and set no submissionid key, so the fallback serves
         * both families. */
        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        $submissionid = (int) (($other['submissionid'] ?? null) ?: ($event->objectid ?? 0));
        if ($submissionid <= 0) {
            return;
        }

        global $DB;
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], IGNORE_MISSING);
        if (!course_access::is_processable($courseid)) {
            return;
        }

        $sub = $DB->get_record(
            'assign_submission',
            ['id' => $submissionid],
            'userid, attemptnumber, groupid'
        );
        if (!$sub) {
            return;
        }
        if ((int) $sub->userid === 0) {
            /* The container row of a team submission: one row per group, no
             * user of its own. The work belongs to every member, so it is
             * fanned out rather than mirrored. */
            submission_ledger::upsert_for_team_attempt(
                $cmid,
                (int) $sub->groupid,
                (int) $sub->attemptnumber
            );
            return;
        }
        submission_ledger::upsert_for_cm_user_attempt(
            $cmid,
            (int) $sub->userid,
            (int) $sub->attemptnumber
        );
    }

    /**
     * Submission graded. Upserts the ledger and queues an adhoc rollup task.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function submission_graded(\core\event\base $event): void {
        $cmid = (int) ($event->contextinstanceid ?? 0);
        if ($cmid <= 0) {
            return;
        }
        $gradeid = (int) ($event->objectid ?? 0);
        if ($gradeid <= 0) {
            return;
        }

        global $DB;
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], IGNORE_MISSING);
        if (!course_access::is_processable($courseid)) {
            return;
        }

        $grade = $DB->get_record(
            'assign_grades',
            ['id' => $gradeid],
            'userid, attemptnumber'
        );
        if (!$grade) {
            return;
        }
        $subid = submission_ledger::upsert_for_cm_user_attempt(
            $cmid,
            (int) $grade->userid,
            (int) $grade->attemptnumber
        );
        if ($subid === null) {
            return;
        }

        $row = $DB->get_record(
            'block_feedback_tracker_sub',
            ['id' => $subid],
            'courseid, groupid'
        );
        if (!$row) {
            return;
        }

        $task = new \block_feedback_tracker\task\recompute_one();
        $task->set_custom_data([
            'courseid' => (int) $row->courseid,
            'groupid' => (int) $row->groupid,
        ]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Marking-workflow state changed for one student.
     *
     * The event carries the assign instance in objectid, the student in
     * relateduserid and the new state in other['newstate']. It is the ONLY
     * signal that a grade became visible to the student: mod_assign stores no
     * release timestamp anywhere ({assign_user_flags} has no time columns on
     * any supported version), so an unobserved release is unrecoverable.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function workflow_state_changed(\core\event\base $event): void {
        $cmid = (int) ($event->contextinstanceid ?? 0);
        $userid = (int) ($event->relateduserid ?? 0);
        if ($cmid <= 0 || $userid <= 0) {
            return;
        }

        global $DB;
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], IGNORE_MISSING);
        if (!course_access::is_processable($courseid)) {
            return;
        }

        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        $released = (string) ($other['newstate'] ?? '') === grading_state::WORKFLOW_RELEASED;
        $when = (int) ($event->timecreated ?: time());

        $attempt = self::latest_attempt_number($cmid, $userid);
        if ($attempt === null) {
            return;
        }
        submission_ledger::upsert_for_cm_user_attempt(
            $cmid,
            $userid,
            $attempt,
            $released ? $when : null
        );

        if ($released) {
            /* Earlier cycles and earlier attempts of the same student may carry
             * a mark that was never released; this transition releases the lot.
             * Stamped set-based because the upsert above only ever touches the
             * current cycle of the latest attempt. */
            submission_ledger::stamp_release_for_user($cmid, $userid, $when);
        }
    }

    /**
     * Identities revealed on a blind-marked assignment.
     *
     * Until this happens core suppresses `submission_graded` entirely —
     * `gradebook_item_update()` returns false before doing anything when blind
     * marking is on without marking-anonymous — so every grading on the
     * activity is invisible to the plugin and every submission reads as
     * awaiting feedback. This is the moment the whole activity becomes
     * knowable, so it is re-derived in the background.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function identities_revealed(\core\event\base $event): void {
        $cmid = (int) ($event->contextinstanceid ?? 0);
        $assignid = (int) ($event->objectid ?? 0);
        if ($cmid <= 0 || $assignid <= 0) {
            return;
        }

        global $DB;
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], IGNORE_MISSING);
        if (!course_access::is_processable($courseid)) {
            return;
        }

        /* Dispatched as adhoc chunks rather than looped inline: a large cohort
         * would otherwise run the whole academic-time engine inside the
         * teacher's request. */
        $rows = $DB->get_records_sql(
            "SELECT DISTINCT s.userid, s.attemptnumber
               FROM {assign_submission} s
              WHERE s.assignment = :assignid AND s.userid > 0
           ORDER BY s.userid ASC",
            ['assignid' => $assignid],
            0,
            self::REVEAL_MAX_ROWS
        );
        $buffer = [];
        foreach ($rows as $r) {
            $buffer[] = [
                'cmid' => $cmid,
                'userid' => (int) $r->userid,
                'attemptnumber' => (int) $r->attemptnumber,
                'courseid' => $courseid,
            ];
            if (count($buffer) >= self::REVEAL_CHUNK) {
                self::queue_backfill($buffer);
                $buffer = [];
            }
        }
        if (!empty($buffer)) {
            self::queue_backfill($buffer);
        }
        if (count($rows) >= self::REVEAL_MAX_ROWS) {
            /* Never let a bounded sweep read as full coverage: say so, so the
             * remainder is picked up deliberately rather than assumed done. */
            debugging(sprintf(
                'block_feedback_tracker: identities_revealed on cmid %d hit the %d-row ceiling; '
                . 'the remaining submissions need a manual backfill.',
                $cmid,
                self::REVEAL_MAX_ROWS
            ));
        }
    }

    /**
     * Allocated marker changed for one student.
     *
     * Records when marking responsibility landed, which mod_assign never
     * stores. Only the batch "Set allocated marker" operation fires this on
     * Moodle 4.5 and 5.1 — the grading form and quick grading write the flag
     * with no event at all — so coverage is partial by construction and the
     * stored `allocsource` says so.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function marker_changed(\core\event\base $event): void {
        $cmid = (int) ($event->contextinstanceid ?? 0);
        $userid = (int) ($event->relateduserid ?? 0);
        if ($cmid <= 0 || $userid <= 0) {
            return;
        }

        global $DB;
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], IGNORE_MISSING);
        if (!course_access::is_processable($courseid)) {
            return;
        }

        submission_ledger::stamp_allocation_for_user(
            $cmid,
            $userid,
            (int) ($event->timecreated ?: time())
        );
    }

    /**
     * Group override created / updated / deleted on an assign.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function override_changed(\core\event\base $event): void {
        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        $assignid = (int) ($other['assignid'] ?? 0);
        $groupid = (int) ($other['groupid'] ?? 0);
        if ($assignid <= 0 || $groupid <= 0) {
            return;
        }
        global $DB;
        $courseid = (int) $DB->get_field('assign', 'course', ['id' => $assignid], IGNORE_MISSING);
        if (!course_access::is_processable($courseid)) {
            return;
        }
        submission_ledger::re_resolve_rules_for_assign_group($assignid, $groupid);
    }

    /**
     * User-level override created / updated / deleted, or an extension
     * granted or revoked on an assign.
     *
     * Both change the dates one student's submission is judged against, and
     * neither reaches the plugin any other way. `extension_granted` carries no
     * payload beyond the student, so the date is read from
     * {assign_user_flags}; the same handler serves both because the work is
     * identical — re-resolve that user's rules from live state.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function user_rule_changed(\core\event\base $event): void {
        $userid = (int) ($event->relateduserid ?? 0);
        if ($userid <= 0) {
            return;
        }

        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        /* The override events carry the assign in other['assignid']; the
         * extension event has no ->other at all and puts the assign instance
         * in objectid. */
        $assignid = (int) ($other['assignid'] ?? ($event->objectid ?? 0));
        if ($assignid <= 0) {
            return;
        }

        global $DB;
        $courseid = (int) $DB->get_field('assign', 'course', ['id' => $assignid], IGNORE_MISSING);
        if (!course_access::is_processable($courseid)) {
            return;
        }
        submission_ledger::re_resolve_rules_for_assign_user($assignid, $userid);
    }

    /**
     * Course-module deleted. Only handles assign cms; others are ignored.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function course_module_deleted(\core\event\base $event): void {
        $cmid = (int) ($event->objectid ?? 0);
        if ($cmid <= 0) {
            return;
        }
        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        $modulename = $other['modulename'] ?? null;
        if ($modulename !== null && $modulename !== 'assign') {
            return;
        }
        submission_ledger::delete_for_cm($cmid);
    }

    /**
     * Course deleted. Drops all ledger / rollup / trend / queue rows.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function course_deleted(\core\event\base $event): void {
        $courseid = (int) ($event->objectid ?? 0);
        if ($courseid <= 0) {
            return;
        }
        submission_ledger::delete_for_course($courseid);
    }

    /**
     * Group member added / removed. Re-attribute the user's ledger rows.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function group_membership_changed(\core\event\base $event): void {
        $courseid = (int) ($event->courseid ?? 0);
        $userid = (int) ($event->relateduserid ?? 0);
        if ($courseid <= 0 || $userid <= 0) {
            return;
        }
        if (!course_access::is_processable($courseid)) {
            return;
        }
        submission_ledger::reattribute_user($courseid, $userid);
    }

    /**
     * Group deleted. Reattribute affected users' ledger rows to their new
     * latest-joined groups (which excludes the now-deleted group).
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function group_deleted(\core\event\base $event): void {
        $groupid = (int) ($event->objectid ?? 0);
        $courseid = (int) ($event->courseid ?? 0);
        if ($groupid <= 0 || $courseid <= 0) {
            return;
        }
        if (!course_access::is_processable($courseid)) {
            return;
        }
        global $DB;
        $affected = $DB->get_records(
            'block_feedback_tracker_sub',
            ['courseid' => $courseid, 'groupid' => $groupid],
            '',
            'id, userid',
            0,
            10000
        );
        if (empty($affected)) {
            return;
        }
        $userids = array_unique(array_map(static fn($r) => (int) $r->userid, $affected));
        group_resolver::reset_memo();
        foreach ($userids as $userid) {
            submission_ledger::reattribute_user($courseid, $userid);
        }
    }
}
