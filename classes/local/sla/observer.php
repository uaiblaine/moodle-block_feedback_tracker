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

use block_feedback_tracker\task\reattribute_users;
use block_feedback_tracker\task\rederive_activity;

/**
 * Thin observer handlers that turn assign / group / course events into
 * ledger upserts plus dirty-queue entries. All heavy lifting (academic-time
 * engine, rollup, score) runs out-of-band: the observer's job is just to
 * record that something changed.
 *
 * Every handler is idempotent: replaying the same event leaves the ledger
 * in the same state (the unique key on cmid/userid/attemptnumber/cycle
 * enforces this). Write handlers gate on {@see course_access::is_processable()};
 * the cleanup handlers (deletions, unenrolment) deliberately do not, so data
 * tracked earlier is still removed after the block is gone.
 */
class observer {
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
         * objectid is the subplugin row id (e.g. assignsubmission_file.id) and
         * the {assign_submission}.id is in other['submissionid']; reading
         * objectid would find nothing or another student's submission. The
         * mod_assign events observed here carry the submission id in objectid
         * (and in other['submissionid'] when they set it), so the fallback
         * serves both families. */
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
     * relateduserid and the new state in other['newstate']. It is the only
     * signal that a grade became visible to the student: mod_assign stores no
     * release timestamp ({assign_user_flags} records the state, not when it
     * changed), so an unobserved release is unrecoverable.
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
            /* Earlier cycles and attempts may carry a mark never released; this
             * transition releases them all, and the upsert above only touches
             * the current cycle of the latest attempt. */
            submission_ledger::stamp_release_for_user($cmid, $userid, $when);
        }
    }

    /**
     * Identities revealed on a blind-marked assignment.
     *
     * Until then core never fires `submission_graded`: `gradebook_item_update()`
     * returns false while blind marking is on without the markinganonymous
     * setting, and the event is triggered only when it succeeds. Every
     * grading so far was invisible to the plugin, so every submission of the
     * activity is re-derived, by {@see rederive_activity} in the background.
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

        rederive_activity::queue($cmid, rederive_activity::SOURCE_SUBMISSIONS);
    }

    /**
     * An activity's settings were saved.
     *
     * Only `assign` matters. `markingworkflow`, `markingallocation` and
     * `teamsubmission` change what the rows already written mean (with
     * marking workflow the response lands on release, not entry; with team
     * submission the work belongs to a group). The stored rows then stay
     * consistent with a definition that no longer applies, so no reconciler
     * sweep can detect it.
     *
     * The ledger keeps no settings snapshot to compare against, so every row
     * of the activity is re-derived from the live {assign} row, by
     * {@see rederive_activity} in the background: the request costs the same
     * however many rows the activity has.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function course_module_updated(\core\event\base $event): void {
        $cmid = (int) ($event->objectid ?? 0);
        $courseid = (int) ($event->courseid ?? 0);
        if ($cmid <= 0 || $courseid <= 0) {
            return;
        }

        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        /* The cheap rejection for most of the traffic: this event also fires
         * per module when a section is shown or hidden, and per module on
         * bulk edits. */
        if (($other['modulename'] ?? null) !== 'assign') {
            return;
        }
        if (!course_access::is_processable($courseid)) {
            return;
        }

        global $DB;
        /* Nothing measured yet, the common case for a settings save, means
         * nothing to re-derive. */
        if (!$DB->record_exists('block_feedback_tracker_sub', ['cmid' => $cmid])) {
            return;
        }

        rederive_activity::queue($cmid, rederive_activity::SOURCE_LEDGER);
    }

    /**
     * Allocated marker changed for one student.
     *
     * Records when marking responsibility landed, which mod_assign never
     * stores. The event differs by Moodle version:
     * - 4.5 and 5.1: only the batch "Set allocated marker" operation fires
     *   `marker_updated` (the grading form and quick grading write the flag
     *   with no event), so coverage is partial and the stored `allocsource`
     *   says so.
     * - 5.2: every allocation path goes through
     *   {@see \assign::update_allocated_markers()}, which fires `marker_updated`
     *   once per marker. A de-allocation fires nothing.
     * - 5.3: `marker_added` and `marker_removed` per slot change replace it
     *   (`marker_updated` survives only for the deprecated method). Those
     *   classes do not exist before 5.3, so their subscriptions are inert here.
     *
     * Every event is only a cue: the marker is re-read from core, so the
     * payload's marker id and the order of several events for one student do
     * not matter.
     *
     * On a multi-marking activity the per-marker slot rows follow too
     * ({@see slot_ledger::sync_for_user()}), each marker's clock starting at
     * the event's instant. Only additions are recorded here: the event fires
     * between one marker's insert and the next, so the reconcile sweep closes
     * the slots of removed markers.
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

        $when = (int) ($event->timecreated ?: time());
        submission_ledger::stamp_allocation_for_user($cmid, $userid, $when);
        slot_ledger::sync_for_user($cmid, $userid, $when, slot_ledger::SOURCE_OBSERVED, false);
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
     * Course deleted. Drops all ledger / rollup / trend / queue / backfill-cursor rows.
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
     * A grade changed in the gradebook.
     *
     * The only signal that a teacher responded outside the activity. It fires
     * for every gradebook item on the site, so the guard order matters: the
     * memoised course gate first (a whole-gradebook regrade in an untracked
     * course pays for it once), then one indexed read rejecting every item
     * that is not an assign.
     *
     * It also fires on the ordinary grading path, before `submission_graded`;
     * the early exit below keeps every grade save from re-deriving the row
     * twice.
     *
     * `\core\event\grade_deleted` is deliberately not observed: under the
     * earliest-wins rule a deletion does not withdraw a response. It is also
     * unreliable: {@see \grade_grade::delete()} fires it only when the grade
     * object already had its item loaded.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function gradebook_changed(\core\event\base $event): void {
        $courseid = (int) ($event->courseid ?? 0);
        $userid = (int) ($event->relateduserid ?? 0);
        if ($courseid <= 0 || $userid <= 0) {
            return;
        }
        if (!course_access::is_processable($courseid)) {
            return;
        }

        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        $itemid = (int) ($other['itemid'] ?? 0);
        if ($itemid <= 0) {
            return;
        }

        global $DB;
        /* The event's context is the course context, so contextinstanceid is a
         * courseid here, never a cmid. The cm is reached through the grade
         * item, which also rejects every non-assign item, including an
         * assign's outcome items (itemnumber 1000 and up). */
        $cmid = (int) $DB->get_field_sql(
            "SELECT cm.id
               FROM {grade_items} gi
               JOIN {course_modules} cm ON cm.instance = gi.iteminstance AND cm.course = gi.courseid
               JOIN {modules} m ON m.id = cm.module AND m.name = gi.itemmodule
              WHERE gi.id = :itemid
                AND gi.itemtype = :itemtype
                AND gi.itemmodule = :itemmodule
                AND gi.itemnumber = 0",
            [
                'itemid' => $itemid,
                'itemtype' => 'mod',
                'itemmodule' => 'assign',
            ],
            IGNORE_MISSING
        );
        if ($cmid <= 0) {
            return;
        }

        $attempt = self::latest_attempt_number($cmid, $userid);
        if ($attempt === null) {
            // No submission: a gradebook grade with nothing to measure against.
            return;
        }

        /* The early exit: skip when the activity owns this cycle, because it
         * is already answered (earliest wins) or {assign_grades} carries a
         * mark that postdates the hand-in. Core writes {assign_grades}, pushes
         * to the gradebook (firing this event) and only then triggers
         * submission_graded, so on a first grading the ledger row is still
         * open here; testing timeclosed alone would re-derive every ordinary
         * grade save twice.
         *
         * Deferring loses nothing: the mark being saved is at or before this
         * gradebook write, so it wins anyway. A genuinely earlier gradebook
         * response belongs to a cycle the activity has not marked, which
         * fails this test and proceeds. */
        $row = $DB->get_record_select(
            'block_feedback_tracker_sub',
            'cmid = :cmid AND userid = :userid AND attemptnumber = :attempt AND iscurrent = 1',
            ['cmid' => $cmid, 'userid' => $userid, 'attempt' => $attempt],
            'id, iteminstance, timesubmitted, timeclosed',
            IGNORE_MULTIPLE
        );
        if ($row && $row->timeclosed !== null) {
            return;
        }
        if ($row) {
            $activitymark = (int) $DB->get_field_sql(
                "SELECT MAX(g.timemodified)
                   FROM {assign_grades} g
                  WHERE g.assignment = :assignid
                    AND g.userid = :userid
                    AND g.attemptnumber = :attempt",
                [
                    'assignid' => (int) $row->iteminstance,
                    'userid' => $userid,
                    'attempt' => $attempt,
                ]
            );
            if ($activitymark > (int) $row->timesubmitted) {
                return;
            }
        }

        submission_ledger::upsert_for_cm_user_attempt($cmid, $userid, $attempt);
    }

    /**
     * A user's enrolment in a course was removed.
     *
     * Their ledger rows describe a response owed to somebody who is no longer
     * a participant, inflating the course's pending count, the grader
     * priority list and the medians. The reconciler's departed-participant
     * sweep removes them too, but only as its rotation through the tracked
     * courses reaches this one; this is the same deletion, immediately.
     * Ungated, like the other cleanup handlers.
     *
     * A user may hold several enrolments in one course. Core ships whether
     * this was the last one in the payload (`lastenrol`, set by
     * `unenrol_user()`), which avoids materialising the enrolled set with
     * `get_enrolled_sql()` once per event of a bulk unenrolment.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function enrolment_changed(\core\event\base $event): void {
        $courseid = (int) ($event->courseid ?? 0);
        $userid = (int) ($event->relateduserid ?? 0);
        if ($courseid <= 0 || $userid <= 0) {
            return;
        }

        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        $ue = $other['userenrolment'] ?? [];
        if (is_object($ue)) {
            $ue = (array) $ue;
        }

        if (array_key_exists('lastenrol', $ue)) {
            if (empty($ue['lastenrol'])) {
                // Another enrolment still stands; the user remains a participant.
                return;
            }
        } else if (self::still_enrolled($courseid, $userid)) {
            /* No payload to trust (a non-core producer of this event). */
            return;
        }

        submission_ledger::delete_for_course_user($courseid, $userid);
    }

    /**
     * Whether any enrolment at all survives for this user in this course.
     *
     * Deliberately weaker than `get_enrolled_sql()`'s active test: this only
     * decides whether to skip a deletion, and a suspended-but-present
     * enrolment is left for the reconciler's own sweep to judge rather than
     * acted on from an event that says nothing about status.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private static function still_enrolled(int $courseid, int $userid): bool {
        global $DB;
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {user_enrolments} ue
               JOIN {enrol} en ON en.id = ue.enrolid
              WHERE ue.userid = :userid AND en.courseid = :courseid",
            ['userid' => $userid, 'courseid' => $courseid]
        );
    }

    /**
     * A user account was deleted.
     *
     * Their rows would otherwise stay in every course they ever submitted in,
     * inside the data the privacy provider declares and exports. Ungated, like
     * the other cleanup handlers.
     *
     * The user id comes from `objectid`, which core always sets;
     * `relateduserid` only falls back to it with a `debugging()` notice.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function user_deleted(\core\event\base $event): void {
        $userid = (int) ($event->objectid ?? 0);
        if ($userid <= 0) {
            return;
        }
        submission_ledger::delete_for_user($userid);
    }

    /**
     * Group member added or removed.
     *
     * Moves the user's ledger rows to the group they report under now
     * ({@see submission_ledger::reattribute_user()}): one user's rows in one
     * course. The dates the rows store are not re-resolved here; an open
     * cycle takes them at its next write, and a closed one keeps the dates it
     * closed under.
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
     * Group deleted. Queues one {@see reattribute_users} task, which moves the
     * rows that reported under the group to the group each user reports under
     * now.
     *
     * Core deletes the memberships without a group_member_removed event per
     * member, so this is the only signal. The rows are read in the task, not
     * here: a group can hold any number of users, and the deletion runs in
     * whoever's request removed it, a course reset included.
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
        reattribute_users::queue_group($courseid, $groupid);
    }
}
