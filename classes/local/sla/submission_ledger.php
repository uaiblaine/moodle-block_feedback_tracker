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
 * Submission ledger writer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\calendar\calendar;
use block_feedback_tracker\local\calendar\day_counter;

/**
 * Idempotent upserts into {block_feedback_tracker_sub} keyed by
 * (cmid, userid, attemptnumber). Reads the live {assign_submission} /
 * {assign_grades} / {assign_overrides} state, invokes the academic-time
 * engine to compute effective hours, and enqueues the (courseid, groupid)
 * tuple for rollup recompute.
 *
 * All write paths are O(1) in DB queries beyond the engine call, suitable
 * to run inline from event observers.
 */
class submission_ledger {
    /**
     * Whether this core version keeps allocations in their own table (5.2+)
     * rather than in an {assign_user_flags} column. Memoised per request.
     *
     * @var bool|null
     */
    private static ?bool $hasallocationtable = null;

    /** Allocation instant captured from a marker_updated event: exact. */
    public const ALLOC_SOURCE_OBSERVED = 'observed';

    /**
     * Allocation first seen by a reconciliation sweep, because core fired no
     * event for it. The stamp is the moment of discovery, so the recorded
     * turnaround is an over-estimate bounded by the sweep period — kept
     * separable from observed stamps so a median is never built from a mix
     * without saying so.
     */
    public const ALLOC_SOURCE_RECONCILED = 'reconciled';

    /**
     * The allocation landed at or after the grading, so the marker's own
     * turnaround cannot be measured. Recorded rather than silently stored as
     * zero: a zero interval bands as `excellent`, which would read as a
     * flawless turnaround — the worst possible failure direction for a metric
     * attached to a person.
     */
    public const ALLOC_SOURCE_LATE = 'late';

    /**
     * Upsert one ledger row for (cmid, userid, attemptnumber), in its current
     * measurement cycle.
     *
     * Reads the current submission + grade + overrides from the assign
     * tables, computes raw/effective hours, classifies the bucket, and
     * enqueues the (course, group) tuple. Returns the ledger row id or null if
     * the inputs are not resolvable (cm missing, not an assign, etc.).
     *
     * A *cycle* is one measurement of teacher response. Work resubmitted after
     * it already carried a mark opens a new cycle rather than rewriting the
     * closed one, so a completed response time is never destroyed and the
     * re-look gets its own clock.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $attemptnumber
     * @param int|null $releasedat Observed marking-workflow release instant,
     *                             supplied by the workflow_state_updated
     *                             observer; mod_assign persists none.
     * @return int|null
     */
    public static function upsert_for_cm_user_attempt(
        int $cmid,
        int $userid,
        int $attemptnumber,
        ?int $releasedat = null
    ): ?int {
        global $DB;

        /* userid 0 is not a user: it is the container row mod_assign writes
         * for a team submission (one row per group, userid 0, groupid G).
         * Mirroring it verbatim produced a ledger row the rollup counted (it
         * does not join {user}) but every list hid (they all do), i.e. a
         * pending item nobody could clear. has_capability() cannot catch it
         * either — it evaluates userid 0 as a real, capability-less user. */
        if ($userid <= 0) {
            return null;
        }

        $cm = self::resolve_assign_cm($cmid);
        if (!$cm) {
            return null;
        }

        $assign = $DB->get_record('assign', ['id' => $cm->instance]);
        if (!$assign) {
            return null;
        }

        /* A team assignment stores the work once per group, so the per-user
         * row (when one exists at all) carries no timing of its own. Route to
         * the fan-out, which reads the group row for timing and each member's
         * own grade row. */
        if (!empty($assign->teamsubmission)) {
            $group = self::team_group_for_user($assign, (int) $cm->course, $userid);
            if ($group === null) {
                return null;
            }
            $ids = self::upsert_for_team_attempt($cmid, $group, $attemptnumber, $releasedat);
            return $ids[$userid] ?? null;
        }

        // Filter out submissions from users who actually hold grading
        // capability in this course (role-switched teachers, dual-role
        // staff, content-QA admins, etc.). Their submissions are almost
        // always internal testing rather than real student work.
        if (self::should_skip_submitter((int) $cm->course, $userid)) {
            return null;
        }

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $userid,
            'attemptnumber' => $attemptnumber,
        ]);
        if (!$submission) {
            return null;
        }

        $grade = $DB->get_record('assign_grades', [
            'assignment' => $assign->id,
            'userid' => $userid,
            'attemptnumber' => $attemptnumber,
        ]);

        return self::build_and_store(
            $cm,
            $assign,
            $userid,
            $attemptnumber,
            isset($submission->status) ? (string) $submission->status : submission_status::NEW,
            (int) $submission->timemodified,
            (int) ($submission->latest ?? 1),
            0,
            $grade ?: null,
            $releasedat
        );
    }

    /**
     * Upsert one ledger row per member of a team submission.
     *
     * mod_assign stores a team's work in a single {assign_submission} row with
     * userid 0 and groupid G, and grades it per member. Timing and status
     * therefore come from the group row while the mark comes from each
     * member's own {assign_grades} row — which is why the per-user entry point
     * cannot serve teams: with `requireallteammemberssubmit` on, a member who
     * did not personally save has no submission row at all, so a per-user
     * lookup finds nothing and the member silently drops out of the SLA.
     *
     * @param int $cmid
     * @param int $groupid The team's group id; 0 is mod_assign's default group
     *                     (everyone not in exactly one group of the grouping).
     * @param int $attemptnumber
     * @param int|null $releasedat Observed marking-workflow release instant.
     * @return array Ledger row ids keyed by userid.
     */
    public static function upsert_for_team_attempt(
        int $cmid,
        int $groupid,
        int $attemptnumber,
        ?int $releasedat = null
    ): array {
        global $DB;

        $cm = self::resolve_assign_cm($cmid);
        if (!$cm) {
            return [];
        }
        $assign = $DB->get_record('assign', ['id' => $cm->instance]);
        if (!$assign || empty($assign->teamsubmission)) {
            return [];
        }

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'groupid' => $groupid,
            'userid' => 0,
            'attemptnumber' => $attemptnumber,
        ]);
        if (!$submission) {
            return [];
        }

        $status = isset($submission->status) ? (string) $submission->status : submission_status::NEW;
        $livesubmitted = (int) $submission->timemodified;
        $latest = (int) ($submission->latest ?? 1);

        $out = [];
        foreach (self::team_member_ids($assign, (int) $cm->course, $groupid) as $memberid) {
            if (self::should_skip_submitter((int) $cm->course, $memberid)) {
                continue;
            }
            $grade = $DB->get_record('assign_grades', [
                'assignment' => $assign->id,
                'userid' => $memberid,
                'attemptnumber' => $attemptnumber,
            ]);
            $subid = self::build_and_store(
                $cm,
                $assign,
                $memberid,
                $attemptnumber,
                $status,
                $livesubmitted,
                $latest,
                $groupid,
                $grade ?: null,
                $releasedat
            );
            if ($subid !== null) {
                $out[$memberid] = $subid;
            }
        }
        return $out;
    }

    /**
     * Build and store one ledger row for a resolved (cm, user, attempt).
     *
     * The single implementation of the cycle model, shared by the individual
     * and team entry points so there is exactly one predicate in the codebase.
     * Callers supply the authoritative submission facts because they differ:
     * an individual reads them from that user's own row, a team member from
     * the shared group row.
     *
     * @param \stdClass $cm Resolved course-module (id, course, instance).
     * @param \stdClass $assign The {assign} row.
     * @param int $userid
     * @param int $attemptnumber
     * @param string $status Submission status governing this row.
     * @param int $livesubmitted Current hand-in time from the authoritative row.
     * @param int $latest assign_submission.latest of the authoritative row.
     * @param int $teamgroupid Group the row was fanned out from; 0 for individuals.
     * @param \stdClass|null $grade The member's {assign_grades} row, or null.
     * @param int|null $releasedat Observed marking-workflow release instant.
     * @param bool $retrying True on the single re-entry after a concurrent
     *                       writer won the insert race; bounds the recursion.
     * @return int|null Ledger row id.
     */
    private static function build_and_store(
        \stdClass $cm,
        \stdClass $assign,
        int $userid,
        int $attemptnumber,
        string $status,
        int $livesubmitted,
        int $latest,
        int $teamgroupid,
        ?\stdClass $grade,
        ?int $releasedat,
        bool $retrying = false
    ): ?int {
        global $DB;

        $cmid = (int) $cm->id;
        $groupid = group_resolver::resolve_group_for_user((int) $cm->course, $userid);
        $rule = rule_resolver::resolve_rule($assign, $userid, $groupid);

        $flags = null;
        if (!empty($assign->markingworkflow)) {
            $flags = $DB->get_record('assign_user_flags', [
                'assignment' => $assign->id,
                'userid' => $userid,
            ]) ?: null;
        }

        $isgradable = self::is_activity_gradable($assign);

        /* Resolve the current measurement cycle. Only the highest cycle of a
         * tuple is ever written; lower ones are closed history. */
        $rows = $DB->get_records(
            'block_feedback_tracker_sub',
            ['cmid' => $cmid, 'userid' => $userid, 'attemptnumber' => $attemptnumber],
            'cycle DESC',
            'id, cycle, submissionstatus, timesubmitted, timegraded, timemarked, timereleased,
             timeclosed, closedsource, timeallocated, timeallocmarker',
            0,
            1
        );
        $existing = $rows ? reset($rows) : null;

        $cycle = 0;
        $timesubmitted = $livesubmitted;
        $storedclosed = null;
        $storedreleased = null;
        $storedstudentclosed = null;
        $storedsource = null;
        $newcycle = false;

        if ($existing !== null) {
            $cycle = (int) $existing->cycle;
            $prevsubmitted = (int) $existing->timesubmitted;
            $prevmarked = $existing->timemarked !== null ? (int) $existing->timemarked : 0;
            /* A gradebook closure never touched {assign_grades}, so it leaves
             * timemarked null. Without this the test below could never pass for
             * such a cycle, and a student who resubmitted after being graded in
             * the gradebook would have that work silently absorbed into the
             * closed cycle — never measured, never pending, and invisible to
             * every sweep, because each of them keys on something this row
             * still satisfies. */
            if (
                $prevmarked === 0
                && (string) ($existing->closedsource ?? '') === gradebook_response::SOURCE_GRADEBOOK
            ) {
                $prevmarked = $existing->timeclosed !== null ? (int) $existing->timeclosed : 0;
            }

            if ($prevmarked > 0 && $prevmarked >= $prevsubmitted && $livesubmitted > $prevmarked) {
                /* The student moved the work after this cycle was marked. Core
                 * keeps reporting "Graded" on every per-user surface while its
                 * needs-grading counter re-flags the row and the grading table
                 * labels it "Graded - resubmitted". Open a NEW cycle instead of
                 * rewriting the closed one: the completed turnaround stays in
                 * the history and the re-look gets its own clock. Keyed on
                 * timemarked, not timegraded, so a marking-workflow row that
                 * was marked but never released is detected too. */
                $cycle++;
                $existing = null;
                $newcycle = true;
                $timesubmitted = $livesubmitted;
            } else {
                $storedclosed = $existing->timegraded !== null ? (int) $existing->timegraded : null;
                $storedreleased = $existing->timereleased !== null ? (int) $existing->timereleased : null;
                $storedstudentclosed = $existing->timeclosed !== null ? (int) $existing->timeclosed : null;
                $storedsource = $existing->closedsource !== null ? (string) $existing->closedsource : null;
                if (
                    $prevsubmitted > 0
                    && (string) $existing->submissionstatus === submission_status::SUBMITTED
                    && $status === submission_status::SUBMITTED
                ) {
                    /* The clock starts at hand-in; later edits inside the same
                     * open cycle must not move it. A draft that becomes
                     * submitted does move it, because the work only arrives on
                     * submit. */
                    $timesubmitted = min($prevsubmitted, $livesubmitted);
                }
            }
        }

        $state = grading_state::resolve($assign, $grade ?: null, $flags, $timesubmitted, $isgradable);

        /* The release instant exists nowhere in core — {assign_user_flags} has
         * no time columns on any supported version — so it is only ever known
         * from the observed workflow_state_updated event, or from what a
         * previous observation already stored. */
        $timereleased = $storedreleased;
        if ($state['isreleased'] && $state['usesworkflow']) {
            if ($timereleased === null && $releasedat !== null) {
                /* The grading form fires workflow_state_updated before it saves
                 * the grade, so a naive assignment could close the row seconds
                 * before the feedback existed. */
                $timereleased = max($releasedat, (int) ($state['timemarked'] ?? 0)) ?: null;
            }
            if ($timereleased === null) {
                // Rebuilt after the fact: the mark time is the documented lower bound.
                $timereleased = $state['timemarked'];
            }
        }

        /* Which event stops the clock. Off (the default) keeps the historical
         * behaviour — the mark stops it — so no displayed number moves on
         * upgrade. On, the clock stops when the feedback actually reaches the
         * student, which on a marking-workflow activity is the release. Either
         * way timereleased and gradestate are recorded, so the UI can flag a
         * marked-but-unreleased submission to the teacher who entered the mark
         * (frequently not the one who may release it). */
        $requirerelease = self::release_stops_the_clock();
        $isclosed = $requirerelease ? $state['isclosed'] : $state['markbelongs'];

        $timegraded = null;
        if ($isclosed) {
            /* First response wins: a re-grade refreshes timemarked but never
             * moves the recorded response time of a cycle that already closed. */
            if ($storedclosed !== null) {
                $timegraded = $storedclosed;
            } else if ($requirerelease && $state['usesworkflow']) {
                $timegraded = $timereleased ?? $state['timemarked'];
            } else {
                $timegraded = $state['timemarked'];
            }
        }

        /* timeclosed records when the response reached the STUDENT, whatever
         * the score clock is set to, so switching the setting later needs no
         * re-derivation. It is deliberately NOT a coalesce: on a
         * marking-workflow activity a mark that was never released has not
         * reached anybody. */
        $timeclosed = null;
        $closedsource = null;
        if ($state['isclosed']) {
            $timeclosed = !empty($assign->markingworkflow)
                ? ($timereleased ?? $state['timemarked'])
                : $state['timemarked'];
            /* Sticky, exactly as timegraded is a few lines above. Without this
             * the two clocks drift apart on the same row: assign::update_grade()
             * restamps assign_grades.timemodified on every save, including a
             * feedback-only edit days later, so timemarked moves and timeclosed
             * followed it while timegraded correctly stayed put. It stays
             * INSIDE the isclosed branch so an un-grading in the activity still
             * clears it — withdrawal there is deliberate. */
            if (
                $storedstudentclosed !== null
                && $storedstudentclosed > $timesubmitted
                && ($timeclosed === null || $storedstudentclosed < $timeclosed)
            ) {
                $timeclosed = $storedstudentclosed;
            }
            $closedsource = $timeclosed !== null
                ? ($storedsource ?? gradebook_response::SOURCE_ASSIGN)
                : null;
        }

        /* The gradebook is the other surface the student reads, and mod_assign
         * never learns what is typed into it. It may only ever CLOSE a cycle:
         * a grade deleted there does not withdraw a response that had already
         * reached the student, and the activity keeps sole authority over
         * re-opening (clearing a mark there means "not answered yet", which is
         * the cycle model's own rule).
         *
         * Earliest wins, so a response is dated when it landed rather than
         * when the plugin noticed it — and, being monotone, the writer can
         * never disagree with a reconciler sweep over the same facts.
         *
         * It closes the OPERATIONAL clock as well as the disclosure one. Every
         * pending predicate in the plugin keys on timegraded — the rollup, the
         * priority list, the browser, the re-ager — so leaving timegraded to
         * the activity alone would have left a gradebook-graded submission
         * sitting in the pending count for ever, which is the whole failure
         * this model exists to end.
         *
         * What it must NOT touch is the marker's own turnaround. queuehours
         * and allochours measure the allocated marker's interval, and a grade
         * typed into the gradebook is frequently a coordinator's act; closing
         * that interval on it would measure the wrong person on a figure that
         * carries their name. So allocation_measures() is given the
         * activity-derived instant, and only that one. */
        $gradebook = gradebook_response::for_assign_user((int) $assign->id, $userid);
        $assigngraded = $timegraded;

        /* Once the gradebook has answered a cycle, that answer is restored from
         * the stored row rather than re-derived, and BOTH clocks are restored.
         * {grade_grades} holds no history: hiding or clearing the grade makes
         * the live read go quiet, so a re-derivation driven by anything else —
         * a student save, a settings change, a rule sweep — would silently take
         * the response back. Restoring only timeclosed, as an earlier draft
         * did, left the row closed and pending at the same time.
         *
         * The restore is deliberately limited to gradebook-sourced closures.
         * An activity-sourced one must still be withdrawable, because clearing
         * a mark in the activity is the marker saying "not answered yet" —
         * long-standing behaviour with its own test. */
        if ($storedsource === gradebook_response::SOURCE_GRADEBOOK) {
            /* The restore carries the same postdates-the-hand-in requirement as
             * the live read below. A cycle's hand-in can move forward under a
             * stored closure without a new cycle opening — a draft graded in
             * the gradebook and only then submitted — and reinstating the older
             * instant would close the cycle before the work existed, which is
             * the zero-hour "excellent" the live gate exists to prevent,
             * reached through the other door. */
            if (
                $storedstudentclosed !== null
                && $storedstudentclosed > $timesubmitted
                && ($timeclosed === null || $storedstudentclosed < $timeclosed)
            ) {
                $timeclosed = $storedstudentclosed;
                $closedsource = gradebook_response::SOURCE_GRADEBOOK;
            }
            if (
                $storedclosed !== null
                && $storedclosed > $timesubmitted
                && ($timegraded === null || $storedclosed < $timegraded)
            ) {
                $timegraded = $storedclosed;
            }
        }

        /* The response has to postdate the work it answers. The activity side
         * has always required this (grading_state::resolve's
         * `$gradetime > $timesubmitted`); the gradebook needs it just as much,
         * because {grade_grades} carries ONE grade per user per item with no
         * attempt or cycle dimension. A resubmission opens a new cycle whose
         * hand-in postdates an override made against the previous one, and
         * without the gate that stale instant would close the new cycle before
         * it began — a zero-hour interval, which bands as the best possible
         * result. */
        $respondedat = $gradebook['respondedat'] !== null ? (int) $gradebook['respondedat'] : null;
        if ($respondedat !== null && $respondedat > $timesubmitted) {
            if ($timeclosed === null || $respondedat < $timeclosed) {
                $timeclosed = $respondedat;
                $closedsource = gradebook_response::SOURCE_GRADEBOOK;
            }
            if ($timegraded === null || $respondedat < $timegraded) {
                $timegraded = $respondedat;
            }
        }

        $now = time();
        $upperbound = $timegraded ?? $now;
        $waitinghours = $timesubmitted > 0
            ? round(max(0.0, ($upperbound - $timesubmitted) / 3600.0), 2)
            : 0.0;

        $audit = ($timesubmitted > 0 && $upperbound > $timesubmitted)
            ? academic_time::elapsed_with_audit((int) $cm->course, $groupid, $timesubmitted, $upperbound)
            : ['hours' => 0.0, 'pauses' => []];
        $effectivehours = $audit['hours'];

        /* The response interval, split by who owns each part. A submission
         * that sat ten days waiting to be allocated and was then marked in two
         * hours must not read as a ten-day marker turnaround: that is the
         * coordinator's queue, not the marker's work. The student-experience
         * clock above is unchanged — it is still the institution's SLA. */
        /* $assigngraded, not $timegraded: the marker interval closes on a mark
         * entered inside the activity and on nothing else. */
        $alloc = self::allocation_measures(
            $existing,
            (int) $cm->course,
            $groupid,
            $timesubmitted,
            $assigngraded
        );

        $record = (object) [
            'courseid'         => (int) $cm->course,
            'groupid'          => $groupid,
            'cmid'             => $cmid,
            'iteminstance'     => (int) $assign->id,
            'userid'           => $userid,
            'attemptnumber'    => $attemptnumber,
            'cycle'            => $cycle,
            'submissionstatus' => $status,
            'timesubmitted'    => $timesubmitted,
            'timegraded'       => $timegraded,
            'timemarked'       => $state['timemarked'],
            'timereleased'     => $timereleased,
            'timeclosed'       => $timeclosed,
            'closedsource'     => $closedsource,
            'islatest'         => $latest,
            'iscurrent'        => 1,
            'gradestate'       => $state['gradestate'],
            'teamgroupid'      => $teamgroupid,
            'timeopens'        => $rule['timeopens'],
            'timecloses'       => $rule['timecloses'],
            'timecutoff'       => $rule['timecutoff'],
            'hasrule'          => $rule['hasrule'],
            'waitinghours'     => $waitinghours,
            'effectivehours'   => $effectivehours,
            'effectivedays'    => $timesubmitted > 0
                ? day_counter::business_days($timesubmitted, $upperbound)
                : null,
            'effectiveasof'    => $now,
            'effectivecalver'  => calendar::current_version(),
            'slabucket'        => bucket::for_effective($effectivehours),
            'queuehours'       => $alloc['queuehours'],
            'allochours'       => $alloc['allochours'],
            'allocdays'        => $alloc['allocdays'],
            'allocbucket'      => $alloc['allocbucket'],
            'timemodified'     => $now,
        ];
        if ($alloc['late']) {
            $record->allocsource = self::ALLOC_SOURCE_LATE;
        }

        if ($existing !== null) {
            $record->id = $existing->id;
            $subid = (int) $existing->id;
            $DB->update_record('block_feedback_tracker_sub', $record);
        } else {
            $record->timecreated = $now;
            $subid = self::insert_cycle_row($record, $cmid, $userid, $attemptnumber, $cycle, $retrying);
            if ($subid === null) {
                /* A concurrent writer inserted this exact cycle between the read
                 * at the top of this method and the insert above, so $record was
                 * derived as if no row existed — the whole sticky-restore block
                 * (earliest-wins timegraded, the gradebook closedsource/timeclosed
                 * reinstatement) never ran. Writing it would silently discard the
                 * other writer's recorded response.
                 *
                 * Re-enter instead of merging here, so the earliest-wins rule
                 * stays in exactly one place: the second pass reads their row as
                 * $existing and takes the ordinary update path. Bounded to one
                 * retry — if the row vanishes again, insert_cycle_row's own
                 * last-resort recovery adopts whatever is there. */
                return self::build_and_store(
                    $cm,
                    $assign,
                    $userid,
                    $attemptnumber,
                    $status,
                    $livesubmitted,
                    $latest,
                    $teamgroupid,
                    $grade,
                    $releasedat,
                    true
                );
            }
        }

        // V2.0.0+: the per-submission pause ledger was removed. The
        // pause timeline is recomputed on demand by get_pause_timeline.
        // $audit['pauses'] is intentionally unused here.

        /* islatest is an attempt-wide fact, so it is maintained set-based
         * across every cycle of the tuple — the upsert above only touches the
         * highest one, and a superseded attempt whose cycle-0 row still
         * claimed islatest = 1 would stay pending for ever. */
        $DB->execute(
            'UPDATE {block_feedback_tracker_sub}
                SET islatest = :islatest
              WHERE cmid = :cmid AND userid = :userid AND attemptnumber = :att',
            [
                'islatest' => $latest,
                'cmid' => $cmid,
                'userid' => $userid,
                'att' => $attemptnumber,
            ]
        );
        if ($newcycle) {
            $DB->execute(
                'UPDATE {block_feedback_tracker_sub}
                    SET iscurrent = 0
                  WHERE cmid = :cmid AND userid = :userid
                    AND attemptnumber = :att AND cycle < :cycle',
                ['cmid' => $cmid, 'userid' => $userid, 'att' => $attemptnumber, 'cycle' => $cycle]
            );
        }

        dirty_queue::enqueue(
            (int) $cm->course,
            $groupid,
            $timegraded !== null ? dirty_queue::REASON_GRADE : dirty_queue::REASON_SUBMISSION
        );

        return $subid;
    }

    /**
     * The marker currently allocated to a student, across core versions.
     *
     * Moodle 4.5 and 5.1 store a single marker in
     * `{assign_user_flags}.allocatedmarker`. Moodle 5.2 dropped that column
     * and moved allocation into `{assign_allocated_marker}`, which holds one
     * row per marker — so reading the old column there raises a DML error, and
     * `marker_updated` does fire on 5.2, which would take the observer with it.
     *
     * With several markers the lowest id is chosen deterministically rather
     * than trusting the event payload: 5.2+ fires one event per marker in
     * insertion order, so "the last event wins" and a re-read would disagree
     * on every multi-marker student and rewrite the row on every poll.
     *
     * @param int $assignid
     * @param int $userid
     * @return int Marker user id, or 0 when unallocated.
     */
    private static function allocated_marker_id(int $assignid, int $userid): int {
        global $DB;

        if (self::$hasallocationtable === null) {
            self::$hasallocationtable = $DB->get_manager()->table_exists('assign_allocated_marker');
        }
        if (self::$hasallocationtable) {
            $marker = $DB->get_field_sql(
                "SELECT MIN(am.marker)
                   FROM {assign_allocated_marker} am
                  WHERE am.assignment = :assignid AND am.student = :userid AND am.marker > 0",
                ['assignid' => $assignid, 'userid' => $userid]
            );
            return (int) ($marker ?: 0);
        }
        return (int) $DB->get_field(
            'assign_user_flags',
            'allocatedmarker',
            ['assignment' => $assignid, 'userid' => $userid],
            IGNORE_MISSING
        );
    }

    /**
     * Split the response interval into the coordination queue and the marker's
     * own turnaround.
     *
     * `queuehours` runs from hand-in to the FIRST allocation and belongs to
     * whoever runs the allocation. `allochours` runs from the CURRENT marker's
     * allocation to the grading and is the only part that is theirs — which is
     * why the current stamp is used rather than the first: someone who
     * inherits a submission on day 8 and grades it on day 9 turned it round in
     * a day, not nine.
     *
     * A non-positive interval yields NULL, never 0.0. Zero effective hours
     * bands as `excellent`, so a stamp that landed at or after the grading —
     * which the reconciler produces whenever it discovers an allocation after
     * the fact — would read as a flawless turnaround. That failure direction
     * is unacceptable for a figure attached to a person, so the row is marked
     * unmeasurable instead and excluded from the medians.
     *
     * @param \stdClass|null $existing The current ledger row, or null when new.
     * @param int $courseid
     * @param int $groupid Reporting group, for the academic-time calendar.
     * @param int $timesubmitted This cycle's hand-in time.
     * @param int|null $timegraded The recorded response time, or null while open.
     * @return array Keys: queuehours, allochours, allocdays, allocbucket, late.
     */
    private static function allocation_measures(
        ?\stdClass $existing,
        int $courseid,
        int $groupid,
        int $timesubmitted,
        ?int $timegraded
    ): array {
        $none = [
            'queuehours' => null,
            'allochours' => null,
            'allocdays' => null,
            'allocbucket' => null,
            'late' => false,
        ];
        if ($existing === null) {
            return $none;
        }

        $allocated = $existing->timeallocated !== null ? (int) $existing->timeallocated : 0;
        $current = $existing->timeallocmarker !== null ? (int) $existing->timeallocmarker : $allocated;
        if ($allocated <= 0) {
            return $none;
        }

        $out = $none;
        if ($allocated > $timesubmitted) {
            $out['queuehours'] = academic_time::elapsed_with_audit(
                $courseid,
                $groupid,
                $timesubmitted,
                $allocated
            )['hours'];
        } else {
            // Allocated before or at hand-in: there was no queue at all.
            $out['queuehours'] = 0.0;
        }

        $upper = $timegraded ?? time();
        if ($current <= 0 || $upper <= $current) {
            $out['late'] = $timegraded !== null;
            return $out;
        }
        $hours = academic_time::elapsed_with_audit($courseid, $groupid, $current, $upper)['hours'];
        $out['allochours'] = $hours;
        $out['allocdays'] = day_counter::business_days($current, $upper);
        $out['allocbucket'] = bucket::for_effective($hours);
        return $out;
    }

    /**
     * Resolve a course-module row, rejecting anything that is not an assign.
     *
     * @param int $cmid
     * @return \stdClass|null Object with id, course and instance.
     */
    private static function resolve_assign_cm(int $cmid): ?\stdClass {
        global $DB;
        $cm = $DB->get_record_sql(
            "SELECT cm.id, cm.course, cm.instance
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE cm.id = :cmid",
            ['modname' => 'assign', 'cmid' => $cmid]
        );
        return $cm ?: null;
    }

    /**
     * The submission group a user hands work in under a team assignment.
     *
     * Mirrors `assign::get_submission_group()`: the single group the user
     * belongs to within the activity's grouping, or the default group (0) when
     * they belong to none or to more than one. Returns null when the default
     * group is not allowed to submit, in which case the user has no team.
     *
     * @param \stdClass $assign The {assign} row.
     * @param int $courseid
     * @param int $userid
     * @return int|null Group id, 0 for the default group, or null when none applies.
     */
    private static function team_group_for_user(\stdClass $assign, int $courseid, int $userid): ?int {
        $groups = groups_get_all_groups(
            $courseid,
            $userid,
            (int) ($assign->teamsubmissiongroupingid ?? 0),
            'g.id',
            false,
            true
        );
        if (count($groups) === 1) {
            $group = reset($groups);
            return (int) $group->id;
        }
        // Not in exactly one group: mod_assign's default group, unless barred.
        return empty($assign->preventsubmissionnotingroup) ? 0 : null;
    }

    /**
     * Enrolled, active members whose work the given team submission covers.
     *
     * For a real group that is its membership; for the default group (0) it is
     * every participant who is not in exactly one group of the activity's
     * grouping, which is the same rule core applies in
     * `assign::get_submission_group_members()`.
     *
     * @param \stdClass $assign The {assign} row.
     * @param int $courseid
     * @param int $groupid
     * @return array Member user ids.
     */
    private static function team_member_ids(\stdClass $assign, int $courseid, int $groupid): array {
        global $DB;

        try {
            $context = \context_course::instance($courseid);
        } catch (\Throwable $e) {
            return [];
        }
        [$esql, $params] = get_enrolled_sql($context, 'mod/assign:submit', 0, true);
        $enrolled = $DB->get_fieldset_sql("SELECT e.id FROM ($esql) e", $params);
        if (empty($enrolled)) {
            return [];
        }

        $out = [];
        foreach ($enrolled as $userid) {
            $userid = (int) $userid;
            if (self::team_group_for_user($assign, $courseid, $userid) === $groupid) {
                $out[] = $userid;
            }
        }
        return $out;
    }

    /**
     * Insert one cycle row, tolerating a concurrent writer.
     *
     * The check-then-insert above races against a second request touching the
     * same tuple, and the unique index turns that race into an exception that
     * would abort the teacher's grade save. Outside a transaction it is safe to
     * recover by re-reading; inside one it is not, because a failed INSERT has
     * already poisoned the connection on PostgreSQL, so the exception is
     * rethrown and the event manager downgrades it to a debugging notice.
     *
     * Recovery is NOT to write `$record` over the winner's row. `$record` was
     * derived on the branch where no row existed, so none of the sticky rules
     * ran — the earliest-wins `timegraded` restore, and the reinstatement of a
     * gradebook-sourced `closedsource`/`timeclosed` that {grade_grades} can no
     * longer report. Overwriting with it discards a response that had already
     * reached the student. Instead this returns null and lets
     * {@see self::build_and_store()} re-derive against the winner's row, so the
     * earliest-wins rule keeps living in exactly one place.
     *
     * On the bounded re-entry ($retrying) the blind update is the last resort:
     * a row that collides twice and then cannot be read is a state no further
     * retry improves, and adopting it beats throwing.
     *
     * NOT COVERED BY A TEST, deliberately. Reaching this catch needs a writer
     * to insert between the read at the top of build_and_store() and the insert
     * below, and both key on the same (cmid, userid, attemptnumber) tuple — so
     * a read that misses guarantees an insert that cannot collide, and one
     * process can never reach it. Driving it needs either a second connection
     * interleaved inside one statement pair, or a seam here for a test subclass
     * to override; the suite has neither, and no test in this repo uses
     * reflection. The concurrency harness belongs with the work that raises
     * concurrency (locking the writer), not with this fix. Until then the
     * guarantee rests on the re-entry landing on the ordinary update path,
     * which gradebook_response_test does cover.
     *
     * @param \stdClass $record Fully built ledger record.
     * @param int $cmid
     * @param int $userid
     * @param int $attemptnumber
     * @param int $cycle
     * @param bool $retrying True when the caller has already re-derived once.
     * @return int|null Ledger row id, or null when the caller must re-derive.
     */
    private static function insert_cycle_row(
        \stdClass $record,
        int $cmid,
        int $userid,
        int $attemptnumber,
        int $cycle,
        bool $retrying = false
    ): ?int {
        global $DB;
        try {
            return (int) $DB->insert_record('block_feedback_tracker_sub', $record);
        } catch (\dml_write_exception $e) {
            if ($DB->is_transaction_started()) {
                throw $e;
            }
            if (!$retrying) {
                return null;
            }
            $row = $DB->get_record('block_feedback_tracker_sub', [
                'cmid' => $cmid,
                'userid' => $userid,
                'attemptnumber' => $attemptnumber,
                'cycle' => $cycle,
            ], 'id', MUST_EXIST);
            $record->id = (int) $row->id;
            unset($record->timecreated);
            $DB->update_record('block_feedback_tracker_sub', $record);
            return (int) $row->id;
        }
    }

    /**
     * Whether the activity can carry a numeric mark at all.
     *
     * `assign.grade` is the grade type: greater than zero is a point scale,
     * less than zero is a Moodle scale id, and exactly zero is grade type
     * "None". A "None" activity can never receive a mark, so the ordinary
     * grade-value test would leave every submission pending for ever.
     *
     * @param \stdClass $assign An {assign} row.
     * @return bool
     */
    private static function is_activity_gradable(\stdClass $assign): bool {
        return (float) ($assign->grade ?? 0) != 0.0;
    }

    /**
     * Whether a marking-workflow grade only stops the SLA clock once released.
     *
     * Default off, so an upgrade moves no displayed number: a site that does
     * not use marking workflow is unaffected either way, and a site that does
     * keeps its historical figures until an admin opts in and recomputes. The
     * stored `timereleased` / `timeclosed` / `gradestate` columns are
     * maintained regardless, so turning it on needs no re-derivation of what
     * the student actually saw.
     *
     * @return bool
     */
    private static function release_stops_the_clock(): bool {
        return (int) (get_config('block_feedback_tracker', 'release_stops_clock') ?: 0) === 1;
    }

    /**
     * Stamp a marking-workflow release across every ledger row of one student
     * on one course-module.
     *
     * The upsert only ever touches the current cycle of the latest attempt,
     * but a release covers whatever that student had marked and unreleased —
     * earlier cycles, earlier attempts. mod_assign records no release
     * timestamp of its own, so an unobserved release is lost for good; this
     * writes it wherever it applies while the event is in hand.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $when Observed release instant.
     * @return void
     */
    public static function stamp_release_for_user(int $cmid, int $userid, int $when): void {
        global $DB;

        $params = ['when' => $when, 'cmid' => $cmid, 'userid' => $userid];
        /* timeclosed and closedsource go through COALESCE rather than being
         * assigned: a cycle the gradebook already closed carries a timeclosed
         * with no timereleased, so a plain assignment here would move a
         * recorded response LATER — the one thing the earliest-wins rule
         * exists to forbid. The release itself is still recorded either way. */
        $DB->execute(
            'UPDATE {block_feedback_tracker_sub}
                SET timereleased = :when,
                    timeclosed = COALESCE(timeclosed, :when2),
                    closedsource = COALESCE(closedsource, :src),
                    gradestate = :released
              WHERE cmid = :cmid AND userid = :userid
                AND timemarked IS NOT NULL AND timereleased IS NULL',
            $params + [
                'when2' => $when,
                'src' => gradebook_response::SOURCE_ASSIGN,
                'released' => grading_state::WORKFLOW_RELEASED,
            ]
        );
        /* Only rows the release policy left open are closed here; under the
         * default policy the mark already stopped their clock. */
        $DB->execute(
            'UPDATE {block_feedback_tracker_sub}
                SET timegraded = :when
              WHERE cmid = :cmid AND userid = :userid
                AND timemarked IS NOT NULL AND timegraded IS NULL',
            $params
        );

        $rows = $DB->get_records(
            'block_feedback_tracker_sub',
            ['cmid' => $cmid, 'userid' => $userid],
            '',
            'id, courseid, groupid'
        );
        $tuples = [];
        foreach ($rows as $row) {
            $tuples[(int) $row->courseid . ':' . (int) $row->groupid] = [
                (int) $row->courseid, (int) $row->groupid,
            ];
        }
        foreach ($tuples as [$courseid, $groupid]) {
            dirty_queue::enqueue($courseid, $groupid, dirty_queue::REASON_GRADE);
        }
    }

    /**
     * Record when marking responsibility for a student landed.
     *
     * `timeallocated` is the first allocation and is sticky — it closes the
     * coordination-queue measurement and must not move on reassignment.
     * `timeallocmarker` tracks the current marker, so someone who inherits a
     * long-queued submission is measured from their own start rather than from
     * a queue they did not cause. The marker id is re-read from core rather
     * than taken from the event payload, because Moodle 5.2 and later fire one event
     * per marker and the last one to arrive is not necessarily the one the
     * table settles on.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $when Allocation instant, or the moment of discovery when
     *                  reconciled.
     * @param string $source One of the ALLOC_SOURCE_* constants; reconciled
     *                       stamps are accurate only to the sweep period and
     *                       are kept separable for that reason.
     * @return void
     */
    public static function stamp_allocation_for_user(
        int $cmid,
        int $userid,
        int $when,
        string $source = self::ALLOC_SOURCE_OBSERVED
    ): void {
        global $DB;

        $cm = $DB->get_record_sql(
            "SELECT cm.id, cm.course, cm.instance
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE cm.id = :cmid",
            ['modname' => 'assign', 'cmid' => $cmid]
        );
        if (!$cm) {
            return;
        }
        $marker = self::allocated_marker_id((int) $cm->instance, $userid);

        $rows = $DB->get_records(
            'block_feedback_tracker_sub',
            ['cmid' => $cmid, 'userid' => $userid],
            '',
            'id, courseid, groupid, timesubmitted, timeallocated, allocmarkerid'
        );
        $tuples = [];
        foreach ($rows as $row) {
            $update = (object) [
                'id' => (int) $row->id,
                'allocmarkerid' => $marker,
                'timemodified' => time(),
            ];
            if ($marker > 0) {
                if ($row->timeallocated === null) {
                    $update->timeallocated = $when;
                    /* The coordination queue closes now, so measure it now —
                     * the value is final and nothing later can recover it if
                     * the row is rebuilt from a different starting point. */
                    $update->queuehours = $when > (int) $row->timesubmitted
                        ? academic_time::elapsed_with_audit(
                            (int) $row->courseid,
                            (int) $row->groupid,
                            (int) $row->timesubmitted,
                            $when
                        )['hours']
                        : 0.0;
                }
                if ((int) $row->allocmarkerid !== $marker) {
                    // A new marker starts their own clock; the queue metric stays put.
                    $update->timeallocmarker = $when;
                    $update->allochours = null;
                    $update->allocdays = null;
                    $update->allocbucket = null;
                }
                $update->allocsource = $source;
            }
            $DB->update_record('block_feedback_tracker_sub', $update);
            $tuples[(int) $row->courseid . ':' . (int) $row->groupid] = [
                (int) $row->courseid, (int) $row->groupid,
            ];
        }
        foreach ($tuples as [$courseid, $groupid]) {
            dirty_queue::enqueue($courseid, $groupid, dirty_queue::REASON_SUBMISSION);
        }
    }

    /**
     * Re-resolve the rule columns for every ledger row tied to (assignid,
     * groupid). Used when a group override is created / updated / deleted.
     *
     * @param int $assignid
     * @param int $groupid
     * @return void
     */
    public static function re_resolve_rules_for_assign_group(int $assignid, int $groupid): void {
        global $DB;

        $assign = $DB->get_record('assign', ['id' => $assignid]);
        if (!$assign) {
            return;
        }

        /* Selected by real membership of the overridden group, not by the
         * ledger's own `groupid`. That column is the REPORTING attribution —
         * the group a student was last added to, used to bucket the dashboard
         * — and has no reason to match the group an override targets. Keying
         * on it re-resolved the wrong students whenever the two differ, which
         * is the normal case on any course where reporting groups and
         * override groups are not the same set. */
        $rows = $DB->get_records_sql(
            "SELECT l.id, l.userid, l.courseid, l.groupid
               FROM {block_feedback_tracker_sub} l
               JOIN {groups_members} gm ON gm.userid = l.userid AND gm.groupid = :groupid
              WHERE l.iteminstance = :assignid",
            ['groupid' => $groupid, 'assignid' => $assignid]
        );

        $now = time();
        $tuples = [];
        foreach ($rows as $row) {
            $rule = rule_resolver::resolve_rule($assign, (int) $row->userid, $groupid);
            $DB->update_record('block_feedback_tracker_sub', (object) [
                'id'           => $row->id,
                'timeopens'    => $rule['timeopens'],
                'timecloses'   => $rule['timecloses'],
                'timecutoff'   => $rule['timecutoff'],
                'hasrule'      => $rule['hasrule'],
                'timemodified' => $now,
            ]);
            $tuples[(int) $row->courseid . ':' . (int) $row->groupid] = [
                (int) $row->courseid, (int) $row->groupid,
            ];
        }

        /* Enqueue every reporting tuple actually touched. The old code
         * enqueued one tuple built from the OVERRIDE group, which on a course
         * whose reporting groups differ pointed at a rollup nothing had
         * changed while leaving the real ones stale. */
        foreach ($tuples as [$courseid, $reportgroupid]) {
            dirty_queue::enqueue($courseid, $reportgroupid, dirty_queue::REASON_PAUSE);
        }
    }

    /**
     * Re-resolve the rule columns for one user's ledger rows on one assign.
     *
     * Used when a user-level override or an extension changes: both alter the
     * dates that submission is judged against, and neither is visible in any
     * other signal the plugin receives.
     *
     * @param int $assignid
     * @param int $userid
     * @return void
     */
    public static function re_resolve_rules_for_assign_user(int $assignid, int $userid): void {
        global $DB;

        $assign = $DB->get_record('assign', ['id' => $assignid]);
        if (!$assign) {
            return;
        }
        $rows = $DB->get_records('block_feedback_tracker_sub', [
            'iteminstance' => $assignid,
            'userid' => $userid,
        ], '', 'id, courseid, groupid');

        $now = time();
        $tuples = [];
        foreach ($rows as $row) {
            $rule = rule_resolver::resolve_rule($assign, $userid, (int) $row->groupid);
            $DB->update_record('block_feedback_tracker_sub', (object) [
                'id'           => $row->id,
                'timeopens'    => $rule['timeopens'],
                'timecloses'   => $rule['timecloses'],
                'timecutoff'   => $rule['timecutoff'],
                'hasrule'      => $rule['hasrule'],
                'timemodified' => $now,
            ]);
            $tuples[(int) $row->courseid . ':' . (int) $row->groupid] = [
                (int) $row->courseid, (int) $row->groupid,
            ];
        }
        foreach ($tuples as [$courseid, $groupid]) {
            dirty_queue::enqueue($courseid, $groupid, dirty_queue::REASON_PAUSE);
        }
    }

    /**
     * Re-attribute every ledger row for a user in a course to whatever group
     * they belong to now. Used when the user joins / leaves a group.
     *
     * Enqueues both the old (now-stale) and the new group.
     *
     * @param int $courseid
     * @param int $userid
     * @return void
     */
    public static function reattribute_user(int $courseid, int $userid): void {
        global $DB;

        group_resolver::reset_memo();
        $newgroupid = group_resolver::resolve_group_for_user($courseid, $userid);

        $rows = $DB->get_records('block_feedback_tracker_sub', [
            'courseid' => $courseid,
            'userid' => $userid,
        ], '', 'id, groupid');

        $now = time();
        $oldgroupids = [];
        foreach ($rows as $row) {
            $oldid = (int) $row->groupid;
            $oldgroupids[$oldid] = true;
            if ($oldid !== $newgroupid) {
                $DB->update_record('block_feedback_tracker_sub', (object) [
                    'id'           => $row->id,
                    'groupid'      => $newgroupid,
                    'timemodified' => $now,
                ]);
            }
        }

        foreach (array_keys($oldgroupids) as $oldgroupid) {
            dirty_queue::enqueue($courseid, $oldgroupid, dirty_queue::REASON_SUBMISSION);
        }
        if (!empty($rows) && !isset($oldgroupids[$newgroupid])) {
            dirty_queue::enqueue($courseid, $newgroupid, dirty_queue::REASON_SUBMISSION);
        }
    }

    /**
     * Delete every ledger row matching a where clause, then re-enqueue each
     * distinct (course, group) tuple the deletion touched.
     *
     * The read-then-delete order matters: the tuples have to be collected
     * while the rows still exist, or the rollup keeps whatever totals it had
     * when the rows disappeared. Shared by the three user-scoped entry points
     * below so the predicate, the early return and the enqueue never drift
     * apart — they were three separate copies before.
     *
     * @param string $where SQL predicate over {block_feedback_tracker_sub}.
     * @param array $params Named parameters for the predicate.
     * @param string $reason A dirty_queue REASON_* constant.
     * @return int Rows deleted.
     */
    private static function delete_rows_and_requeue(string $where, array $params, string $reason): int {
        global $DB;

        $rows = $DB->get_records_select(
            'block_feedback_tracker_sub',
            $where,
            $params,
            '',
            'id, courseid, groupid'
        );
        if (empty($rows)) {
            return 0;
        }

        $tuples = [];
        foreach ($rows as $r) {
            $tuples[(int) $r->courseid . ':' . (int) $r->groupid] = [
                (int) $r->courseid, (int) $r->groupid,
            ];
        }

        $DB->delete_records_select('block_feedback_tracker_sub', $where, $params);

        foreach ($tuples as [$courseid, $groupid]) {
            dirty_queue::enqueue($courseid, $groupid, $reason);
        }
        return count($rows);
    }

    /**
     * Delete one user's ledger rows in one course.
     *
     * Every attempt and every cycle goes, closed ones included. The rows
     * describe a response owed to somebody who is no longer a participant, so
     * keeping them would leave the course's pending counts, priority list and
     * medians answering for work nobody can act on.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $reason A dirty_queue REASON_* constant.
     * @return int Rows deleted.
     */
    public static function delete_for_course_user(
        int $courseid,
        int $userid,
        string $reason = dirty_queue::REASON_SUBMISSION
    ): int {
        if ($courseid <= 0 || $userid <= 0) {
            return 0;
        }
        return self::delete_rows_and_requeue(
            'courseid = :courseid AND userid = :userid',
            ['courseid' => $courseid, 'userid' => $userid],
            $reason
        );
    }

    /**
     * Delete one user's ledger rows across every course.
     *
     * For a deleted account. Note what this deliberately does NOT do: rows
     * where the deleted user is the allocated marker (`allocmarkerid`) keep
     * that id. Scrubbing it belongs to the privacy provider, which declares
     * the column and today neither lists a marker among a context's users nor
     * clears the field — closing that gap here would hide it in the wrong
     * place and would silently move the allocation-coverage figure of courses
     * the account merely marked in.
     *
     * @param int $userid
     * @param string $reason A dirty_queue REASON_* constant.
     * @return int Rows deleted.
     */
    public static function delete_for_user(
        int $userid,
        string $reason = dirty_queue::REASON_SUBMISSION
    ): int {
        if ($userid <= 0) {
            return 0;
        }
        return self::delete_rows_and_requeue(
            'userid = :userid',
            ['userid' => $userid],
            $reason
        );
    }

    /**
     * Delete all ledger rows for one course-module (and cascade pause rows).
     *
     * @param int $cmid
     * @return void
     */
    public static function delete_for_cm(int $cmid): void {
        global $DB;
        $rows = $DB->get_records(
            'block_feedback_tracker_sub',
            ['cmid' => $cmid],
            '',
            'id, courseid, groupid'
        );
        if (empty($rows)) {
            return;
        }
        $DB->delete_records('block_feedback_tracker_sub', ['cmid' => $cmid]);

        $tuples = [];
        foreach ($rows as $row) {
            $tuples[(int) $row->courseid . ':' . (int) $row->groupid] = [
                (int) $row->courseid, (int) $row->groupid,
            ];
        }
        foreach ($tuples as [$courseid, $groupid]) {
            dirty_queue::enqueue($courseid, $groupid, dirty_queue::REASON_SUBMISSION);
        }
    }

    /**
     * Delete all ledger + queue + rollup + trend rows for one course.
     *
     * @param int $courseid
     * @return void
     */
    public static function delete_for_course(int $courseid): void {
        global $DB;

        $DB->delete_records('block_feedback_tracker_sub', ['courseid' => $courseid]);
        $DB->delete_records('block_feedback_tracker_group', ['courseid' => $courseid]);
        $DB->delete_records('block_feedback_tracker_trend', ['courseid' => $courseid]);
        $DB->delete_records('block_feedback_tracker_queue', ['courseid' => $courseid]);
        $DB->delete_records('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
    }

    /**
     * Per-request memoised cache for the grader-filter capability check.
     * Keyed by "courseid:userid".
     *
     * @var array<string, bool>
     */
    private static array $skipsubmittermemo = [];

    /**
     * Returns true if the submission for ($courseid, $userid) should be
     * skipped because the submitter actually holds the grading capability
     * — most commonly a role-switched teacher / administrator running an
     * internal test rather than a real student. Gated by the
     * `exclude_grader_submissions` site setting (default ON).
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private static function should_skip_submitter(int $courseid, int $userid): bool {
        if ((int) (get_config('block_feedback_tracker', 'exclude_grader_submissions') ?? 1) !== 1) {
            return false;
        }
        $key = $courseid . ':' . $userid;
        if (!isset(self::$skipsubmittermemo[$key])) {
            try {
                $context = \context_course::instance($courseid);
                self::$skipsubmittermemo[$key] = has_capability('mod/assign:grade', $context, $userid);
            } catch (\Throwable $e) {
                // Context resolution failure — fail open (record the row).
                self::$skipsubmittermemo[$key] = false;
            }
        }
        return self::$skipsubmittermemo[$key];
    }

    /**
     * Drop the per-request grader-filter memo. Test helper; not called by
     * production code.
     *
     * @return void
     */
    public static function reset_memos(): void {
        self::$skipsubmittermemo = [];
        self::$hasallocationtable = null;
    }
}
