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
    /** Ceiling on rows re-derived from any one bulk-triggering event. */
    private const BULK_MAX_ROWS = 20000;

    /** Rows per adhoc backfill task dispatched by a bulk re-derivation. */
    private const BULK_CHUNK = 50;

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
     * Chunk a set of backfill descriptors into adhoc tasks, and say so when
     * the set was truncated.
     *
     * A bounded sweep that stays quiet reads as full coverage, which is the
     * one thing it must never do — the remainder has to be picked up
     * deliberately rather than assumed done.
     *
     * The ceiling is judged on `$fetched`, the number of rows the query
     * actually returned, not on the descriptor count. Callers collapse many
     * rows into one descriptor — every cycle of an attempt, every member of a
     * team — so a descriptor count can sit far below the ceiling while the
     * `LIMIT` has already truncated the source. Measuring the wrong one turns
     * this notice into the silence it exists to prevent.
     *
     * @param array $rows Row descriptors for backfill_one_submission.
     * @param int $fetched Rows the source query returned, before collapsing.
     * @param string $context Short description of the trigger, for the notice.
     * @return void
     */
    private static function dispatch_backfill(array $rows, int $fetched, string $context): void {
        $buffer = [];
        foreach ($rows as $row) {
            $buffer[] = $row;
            if (count($buffer) >= self::BULK_CHUNK) {
                self::queue_backfill($buffer);
                $buffer = [];
            }
        }
        if (!empty($buffer)) {
            self::queue_backfill($buffer);
        }
        if ($fetched >= self::BULK_MAX_ROWS) {
            debugging(sprintf(
                'block_feedback_tracker: %s hit the %d-row ceiling; '
                . 'the remaining submissions need a manual backfill.',
                $context,
                self::BULK_MAX_ROWS
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
            "SELECT s.id, s.userid, s.attemptnumber
               FROM {assign_submission} s
              WHERE s.assignment = :assignid AND s.userid > 0
           ORDER BY s.userid ASC",
            ['assignid' => $assignid],
            0,
            self::BULK_MAX_ROWS
        );
        $descriptors = [];
        foreach ($rows as $r) {
            $descriptors[(int) $r->userid . ':' . (int) $r->attemptnumber] = [
                'cmid' => $cmid,
                'userid' => (int) $r->userid,
                'attemptnumber' => (int) $r->attemptnumber,
                'courseid' => $courseid,
            ];
        }
        self::dispatch_backfill(
            array_values($descriptors),
            count($rows),
            sprintf('identities_revealed on cmid %d', $cmid)
        );
    }

    /**
     * An activity's settings were saved.
     *
     * Only `assign` matters, and only three of its settings do:
     * `markingworkflow`, `markingallocation` and `teamsubmission` change what
     * the rows already written *mean*. With marking workflow on, the response
     * lands when the mark is released rather than when it is entered, so
     * `timeclosed` is chosen from a different stamp; with team submission on,
     * the work belongs to a group rather than a person. None of this is a
     * value drifting out of sync, so no reconciler sweep can see it: the
     * divergence sweep keys on the mark, the rule sweep keys on dates, and
     * both would find the stored rows perfectly consistent with a definition
     * that no longer applies. Re-derivation from live state is the only repair.
     *
     * Rather than compare settings against a snapshot the ledger does not
     * keep, every affected row is simply re-derived — the upsert reads the
     * live {assign} row, so the re-derivation *is* the resync. The work is
     * dispatched as adhoc chunks: a settings save must not run the
     * academic-time engine for a whole cohort inside the teacher's request.
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
        /* The module name is the cheap rejection, and it carries most of the
         * traffic: this event also fires once per contained module when a
         * section is hidden or shown, and once per selected module from the
         * bulk-edit web service. */
        if (($other['modulename'] ?? null) !== 'assign') {
            return;
        }
        if (!course_access::is_processable($courseid)) {
            return;
        }

        global $DB;
        /* Nothing measured yet — the overwhelmingly common case for a settings
         * save — means nothing to re-derive. One indexed existence check keeps
         * a routine save off every path below. */
        if (!$DB->record_exists('block_feedback_tracker_sub', ['cmid' => $cmid])) {
            return;
        }

        /* Route on the LIVE teamsubmission flag, never on the stored
         * teamgroupid, because the stored value cannot tell the two shapes
         * apart: mod_assign's DEFAULT group IS groupid 0, so a team row for it
         * is stored with teamgroupid = 0, identical to an individual row.
         * Routing those per member sends each member back through the
         * whole-group fan-out — quadratic in group size, on the one path that
         * exists to be cheap.
         *
         * It also covers team submission being switched off, where the rows
         * still carry their old teamgroupid and a team descriptor built from it
         * would be a guaranteed no-op (upsert_for_team_attempt() returns
         * immediately once the activity is no longer a team one). That case is
         * defensive rather than live: core freezes the teamsubmission field
         * whenever the activity has any submission or grade
         * (mod/assign/mod_form.php), and ledger rows only exist once it does,
         * so the settings form cannot produce it — a restore or a direct write
         * still can. */
        $teamsubmission = (int) $DB->get_field_sql(
            "SELECT a.teamsubmission
               FROM {assign} a
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE cm.id = :cmid",
            ['modname' => 'assign', 'cmid' => $cmid],
            IGNORE_MISSING
        );

        /* Selecting the row id first is not cosmetic: get_records_sql() keys
         * the result by the first column and silently keeps only the last row
         * of any duplicate key, so a DISTINCT userid projection would drop
         * every attempt but one for any student with a resubmission. The
         * de-duplication is done here instead, on the real key. */
        $rows = $DB->get_records_sql(
            "SELECT l.id, l.userid, l.attemptnumber, l.teamgroupid
               FROM {block_feedback_tracker_sub} l
              WHERE l.cmid = :cmid
           ORDER BY l.id ASC",
            ['cmid' => $cmid],
            0,
            self::BULK_MAX_ROWS
        );

        $descriptors = [];
        foreach ($rows as $r) {
            if ($teamsubmission === 1) {
                /* Once per (group, attempt), not once per member: each
                 * descriptor already fans out to the whole group. */
                $teamgroupid = (int) $r->teamgroupid;
                $descriptors['t' . $teamgroupid . ':' . (int) $r->attemptnumber] = [
                    'cmid' => $cmid,
                    'userid' => 0,
                    'groupid' => $teamgroupid,
                    'attemptnumber' => (int) $r->attemptnumber,
                    'courseid' => $courseid,
                ];
                continue;
            }
            $descriptors['u' . (int) $r->userid . ':' . (int) $r->attemptnumber] = [
                'cmid' => $cmid,
                'userid' => (int) $r->userid,
                'attemptnumber' => (int) $r->attemptnumber,
                'courseid' => $courseid,
            ];
        }

        self::dispatch_backfill(
            array_values($descriptors),
            count($rows),
            sprintf('course_module_updated on cmid %d', $cmid)
        );
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
     * A grade changed in the gradebook.
     *
     * This is the only signal the plugin has that a teacher responded outside
     * the activity. It fires for EVERY gradebook item on the site, so the
     * order of the guards below is the design: the course gate first (memoised,
     * so a whole-gradebook regrade in an untracked course costs one query for
     * the entire request), then one indexed read to reject every item that is
     * not an assign.
     *
     * It also fires on the ordinary grading path — `gradebook_item_update()`
     * performs the gradebook write, and only then is `submission_graded`
     * triggered — so without the closed-cycle early exit every grade save
     * would re-derive the same row twice and run the academic-time engine
     * twice. A cycle that already has a response is exactly the case this
     * observer has nothing to add to, which is what makes that exit both cheap
     * and correct.
     *
     * `\core\event\grade_deleted` is deliberately NOT registered: under the
     * earliest-wins rule a deletion does not withdraw a response, so there is
     * nothing for it to do. (It is also unreliable — it only fires when the
     * grade object happens to have had its item loaded, which on a site with
     * completion disabled it has not.)
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
        /* The event's context is the COURSE context, so contextinstanceid is a
         * courseid here and must never be read as a cmid. The cm is reached
         * through the grade item instead — one indexed read that also rejects
         * every non-assign item, including an outcome item attached to an
         * assign (itemnumber >= 1000). */
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

        /* The early exit. A cycle that already carries a response cannot be
         * improved by this event — earliest wins — and this is the branch the
         * ordinary grading path takes, twice per save, on every site. */
        /* Skip when the activity owns this cycle — either it has already been
         * answered, or {assign_grades} carries a mark that postdates the
         * hand-in, which means mod_assign is mid-save and submission_graded is
         * about to fire.
         *
         * The second half is what makes the exit useful. Core writes
         * {assign_grades}, then pushes to the gradebook (firing this event),
         * and only then triggers submission_graded — so on a FIRST grading the
         * ledger row is still open when we arrive, and a check on timeclosed
         * alone would let every ordinary grade save re-derive the same row
         * twice and run the academic-time engine twice.
         *
         * Nothing is lost by deferring to the activity: under earliest-wins the
         * mark it is saving right now is at or before this gradebook write, so
         * it would win regardless. A gradebook response that is genuinely
         * earlier belongs to a cycle the activity has not marked at all, which
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
     * a participant, so they inflate the course's pending count, the grader
     * priority list and the medians until something removes them. The
     * reconciler's departed-participant sweep already does, but it walks
     * exactly one course per tick on a two-hourly task, so on a site with N
     * tracked courses the rows survive up to ~2N hours — a whole semester's
     * worth on a large site. This is the same deletion, immediately.
     *
     * Like the other cleanup handlers, it skips the processability gate: data
     * previously tracked has to be collectable even after the block is gone.
     *
     * A user may hold several enrolments in one course. Core has already
     * worked out whether this was the last one and ships the answer in the
     * payload (`$ue->lastenrol`, set in `unenrol_user()` immediately before the
     * event); re-deriving it with `get_enrolled_sql()` would materialise the
     * whole enrolled set to answer a one-row question, once per event, and a
     * bulk unenrolment fires one event per student.
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
            /* No payload to trust — a non-core producer of this event. One
             * indexed existence check, not an enrolled-set materialisation. */
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
     * keeping a deleted account inside the data the privacy provider declares
     * and exports, and inside the userlist of every one of those courses.
     * Ungated, like the other cleanup handlers.
     *
     * The user id comes from `objectid`: core only guarantees `relateduserid`
     * with a `debugging()` fallback to `objectid`, so reading `objectid`
     * directly is the one that always holds.
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
