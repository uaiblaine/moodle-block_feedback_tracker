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
 * Idempotent upserts into {block_feedback_tracker_sub}, one row per
 * (cmid, userid, attemptnumber, cycle). Reads the live {assign_submission} /
 * {assign_grades} / {assign_overrides} / {assign_user_flags} / gradebook
 * state, invokes the academic-time engine to compute effective hours, and
 * enqueues the (courseid, groupid) tuple for rollup recompute.
 *
 * The per-user upsert runs a bounded number of queries beyond the engine
 * call, so it is suitable to run inline from event observers; the team,
 * re-resolve and delete paths scale with the members or rows they touch.
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
     * event for it. The stamp is the moment of discovery, later than the real
     * allocation, so the queue time is over-estimated and the marker
     * turnaround under-estimated; kept separable from observed stamps so a
     * median is never built from a mix without saying so.
     */
    public const ALLOC_SOURCE_RECONCILED = 'reconciled';

    /**
     * The allocation landed at or after the grading, so the marker's own
     * turnaround cannot be measured ({@see self::allocation_measures()}).
     */
    public const ALLOC_SOURCE_LATE = 'late';

    /**
     * Responsible marker under the manual method: the teacher who saved the
     * final grade (`{assign_grades}.grader` read when the cycle first got it).
     */
    public const ATTRIB_CONFIRMER = 'confirmer';

    /**
     * Responsible marker under the maximum method: the marker of the highest
     * completed mark among the active slots, a tie going to the lower position.
     */
    public const ATTRIB_MAXMARK = 'maxmark';

    /** Responsible marker by position: the marker in position 1 (average, first). */
    public const ATTRIB_POSITION = 'position';

    /**
     * The method's own rule named nobody (a manual grade saved with no grader,
     * a maximum with no completed mark), so position 1 was taken instead.
     */
    public const ATTRIB_FALLBACK = 'fallback';

    /**
     * Upsert one ledger row for (cmid, userid, attemptnumber), in its current
     * measurement cycle.
     *
     * Reads the current submission + grade + overrides + extension from the
     * assign tables, computes raw/effective hours, classifies the bucket, and
     * enqueues the (course, group) tuple. Returns the ledger row id, or null if
     * the inputs are not resolvable (cm missing, not an assign, no submission
     * row) or the submitter is excluded.
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

        /* userid 0 is not a user: it is mod_assign's team-submission container
         * row (one per group, userid 0, groupid G). Stored, it would be a
         * pending item the rollup counts (it does not join {user}) but every
         * list hides. should_skip_submitter() cannot catch it: has_capability()
         * treats userid 0 as a real, capability-less user. */
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
        $rule = rule_resolver::resolve_rule((int) $assign->id, $userid);

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
             timeclosed, closedsource, timedismissed, timeallocated, timeallocmarker,
             allocmarkerid, allocattrib',
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

        /* A dismissed cycle ({@see legacy_dismissal}) stays out of every
         * population, so it is never rewritten: a re-derivation would put it
         * back as current and pending. Only work saved after the dismissal
         * reopens the attempt, in a cycle of its own, as work saved after a
         * mark does. A mark made after the dismissal answers nothing that is
         * still listed, so it is not measured. */
        if ($existing !== null && $existing->timedismissed !== null) {
            if ($livesubmitted <= (int) $existing->timedismissed) {
                self::set_islatest($cmid, $userid, $attemptnumber, $latest);
                return (int) $existing->id;
            }
            $cycle = (int) $existing->cycle + 1;
            $existing = null;
            $newcycle = true;
        }

        if ($existing !== null) {
            $cycle = (int) $existing->cycle;
            $prevsubmitted = (int) $existing->timesubmitted;
            $prevmarked = $existing->timemarked !== null ? (int) $existing->timemarked : 0;
            /* A gradebook closure never touched {assign_grades}, so it leaves
             * timemarked null; use its timeclosed instead. Otherwise work
             * resubmitted after a gradebook grade would be absorbed into the
             * closed cycle and never measured, and no reconciler sweep would
             * notice. */
            if (
                $prevmarked === 0
                && (string) ($existing->closedsource ?? '') === gradebook_response::SOURCE_GRADEBOOK
            ) {
                $prevmarked = $existing->timeclosed !== null ? (int) $existing->timeclosed : 0;
            }

            if ($prevmarked > 0 && $prevmarked >= $prevsubmitted && $livesubmitted > $prevmarked) {
                /* The student changed the work after this cycle was marked (core's
                 * grading table shows "Graded - resubmitted" and re-flags it as
                 * needing grading): open a new cycle rather than rewrite the
                 * closed one. Keyed on timemarked, not timegraded, so a
                 * marking-workflow row marked but never released is detected
                 * too. */
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

        /* A response the student already received stays recorded when the grade
         * under it is cleared while the state is still released. Moodle 5.3
         * clears agreed grades in bulk from the activity settings ("Update
         * agreed grades": Clear, or Recalculate under the manual method) with
         * no workflow check, released students included; without this the cycle
         * would read as pending again and lose its response time. A state taken
         * back from released, or a resubmission, still reopens as before. */
        if (
            $existing !== null
            && !$state['hasmark']
            && $state['usesworkflow']
            && $state['isreleased']
            && $storedreleased !== null
            && $storedstudentclosed !== null
            && $existing->timemarked !== null
        ) {
            $state['hasmark'] = true;
            $state['markbelongs'] = true;
            $state['timemarked'] = (int) $existing->timemarked;
            $state['isclosed'] = true;
        }

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

        /* Which event stops the clock: the mark (default) or, with
         * release_stops_clock on, the release. See release_stops_the_clock(). */
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

        /* timeclosed records when the response reached the student, whatever
         * the clock setting, so switching the setting later needs no
         * re-derivation. Gated on isclosed rather than falling back to the
         * mark: on a marking-workflow activity an unreleased mark has not
         * reached anybody. */
        $timeclosed = null;
        $closedsource = null;
        if ($state['isclosed']) {
            $timeclosed = !empty($assign->markingworkflow)
                ? ($timereleased ?? $state['timemarked'])
                : $state['timemarked'];
            /* Sticky, like timegraded above: assign::update_grade() restamps
             * assign_grades.timemodified on every save, including a
             * feedback-only edit days later, so timemarked moves. Inside the
             * isclosed branch so un-grading in the activity still clears it. */
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
         * never learns what is typed into it. It may only close a cycle: a
         * grade deleted there does not withdraw a response that already reached
         * the student, and re-opening stays with the activity (clearing a mark
         * there means "not answered yet").
         *
         * Earliest wins, so a response is dated when it landed rather than
         * when the plugin noticed it, and the writer cannot disagree with a
         * reconciler sweep over the same facts. It closes timegraded as well as
         * timeclosed, because every pending predicate keys on timegraded.
         *
         * It must not close the marker's own turnaround: a grade typed into the
         * gradebook is often a coordinator's act, so allocation_measures() gets
         * the activity-derived instant ($assigngraded) only. */
        $gradebook = gradebook_response::for_assign_user((int) $assign->id, $userid);
        $assigngraded = $timegraded;

        /* Once the gradebook has answered a cycle, both clocks are restored from
         * the stored row rather than re-derived: {grade_grades} holds no
         * history, so after the grade is hidden or cleared a re-derivation
         * triggered by anything else (a student save, a settings change, a rule
         * sweep) would silently take the response back. Restoring timeclosed
         * alone would leave the row closed and pending at once.
         *
         * Limited to gradebook-sourced closures: an activity-sourced one stays
         * withdrawable, because clearing a mark in the activity means "not
         * answered yet". */
        if ($storedsource === gradebook_response::SOURCE_GRADEBOOK) {
            /* Same postdates-the-hand-in requirement as the live read below: the
             * hand-in can move forward under a stored closure without opening a
             * new cycle (a draft graded in the gradebook, then submitted), and
             * the older instant would close the cycle before the work existed. */
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

        /* The response has to postdate the work it answers, as
         * grading_state::resolve() requires on the activity side.
         * {grade_grades} carries one grade per user per item, with no attempt
         * or cycle, so an override made against the previous cycle would
         * otherwise close a resubmission's new cycle before it began: a
         * zero-hour interval, which bands as the best possible result. */
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

        $effectivehours = ($timesubmitted > 0 && $upperbound > $timesubmitted)
            ? academic_time::elapsed_effective_hours((int) $cm->course, $groupid, $timesubmitted, $upperbound)
            : 0.0;

        /* The responsible marker of a multi-marking cycle (owner decision D1),
         * decided once, when the cycle first carries its agreed grade, and
         * sticky like timegraded: a later mark edit, recalculation or removed
         * marker would be read through the markers allocated NOW
         * (get_marks() filters on the current allocation), not the ones that
         * produced the grade. Only the activity withdrawing the agreed grade
         * (markbelongs false, and the released-response rule above not keeping
         * it) takes it back, to the provisional marker in position 1. The
         * marker and the start of their clock are written together here,
         * never through stamp_allocation_for_user()'s reassignment branch,
         * which would null the turnaround. */
        $attribution = null;
        if ($existing !== null && marking_progress::uses_multiple_marking($assign)) {
            $storedattrib = $existing->allocattrib !== null ? (string) $existing->allocattrib : null;
            if ($storedattrib === null && $state['markbelongs']) {
                $resolved = self::resolve_responsible($assign, (int) $existing->id, $userid, $attemptnumber, $grade);
                if ($resolved !== null) {
                    $attribution = [
                        'allocmarkerid' => $resolved['marker'],
                        'timeallocmarker' => $resolved['start'],
                        'allocattrib' => $resolved['label'],
                    ];
                }
            } else if ($storedattrib !== null && !$state['markbelongs']) {
                $provisional = self::provisional_responsible($assign, (int) $existing->id, $userid, $attemptnumber, $grade);
                $attribution = [
                    'allocmarkerid' => $provisional['marker'],
                    'timeallocmarker' => $provisional['start'],
                    'allocattrib' => null,
                ];
            }
            if ($attribution !== null) {
                $existing = clone $existing;
                $existing->timeallocmarker = $attribution['timeallocmarker'];
                $existing->allocattrib = $attribution['allocattrib'];
            }
        }

        /* The response interval split by owner (see allocation_measures()); the
         * student-experience clock above is unchanged. $assigngraded, not
         * $timegraded: the marker interval closes only on a mark entered
         * inside the activity. */
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
        if ($attribution !== null) {
            $record->allocmarkerid = $attribution['allocmarkerid'];
            $record->timeallocmarker = $attribution['timeallocmarker'];
            $record->allocattrib = $attribution['allocattrib'];
        }

        $inserted = false;
        if ($existing !== null) {
            if (!$alloc['known']) {
                /* This snapshot saw no allocation, so leave the four measures
                 * as stored rather than writing nulls. stamp_allocation_for_user()
                 * writes timeallocated and queuehours together, and
                 * timeallocated is not part of this record: a snapshot taken
                 * before that stamp would keep the instant but erase its queue
                 * time, and the repair sweep (which selects on
                 * `timeallocated IS NULL`) could not see the row. A new row has
                 * nothing to preserve, so the insert path writes them as-is. */
                unset(
                    $record->queuehours,
                    $record->allochours,
                    $record->allocdays,
                    $record->allocbucket
                );
            }
            $record->id = $existing->id;
            $subid = (int) $existing->id;
            $DB->update_record('block_feedback_tracker_sub', $record);
        } else {
            $record->timecreated = $now;
            $subid = self::insert_cycle_row($record, $cmid, $userid, $attemptnumber, $cycle, $retrying);
            $inserted = $subid !== null;
            if ($subid === null) {
                /* A concurrent writer inserted this cycle after the read at the
                 * top of this method: re-derive against its row, bounded to one
                 * retry. See insert_cycle_row(). */
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

        self::set_islatest($cmid, $userid, $attemptnumber, $latest);
        if ($newcycle) {
            $DB->execute(
                'UPDATE {block_feedback_tracker_sub}
                    SET iscurrent = 0
                  WHERE cmid = :cmid AND userid = :userid
                    AND attemptnumber = :att AND cycle < :cycle',
                ['cmid' => $cmid, 'userid' => $userid, 'att' => $attemptnumber, 'cycle' => $cycle]
            );
        }

        /* A new cycle inherits the markers already allocated
         * ({@see slot_ledger::carry_onto_new_cycle()}). After the iscurrent
         * update above, so only the new cycle is synced. */
        if ($inserted && marking_progress::uses_multiple_marking($assign)) {
            slot_ledger::carry_onto_new_cycle($cmid, $userid, $timesubmitted, $now);
        }

        dirty_queue::enqueue(
            (int) $cm->course,
            $groupid,
            $timegraded !== null ? dirty_queue::REASON_GRADE : dirty_queue::REASON_SUBMISSION
        );

        return $subid;
    }

    /**
     * Mirror assign_submission.latest onto every cycle of one attempt.
     *
     * islatest is an attempt-wide fact, so it is maintained set-based across
     * every cycle of the tuple: the upsert only touches the highest one, and a
     * superseded attempt whose cycle-0 row still claimed islatest = 1 would
     * stay pending for ever.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $attemptnumber
     * @param int $latest assign_submission.latest of the authoritative row.
     * @return void
     */
    private static function set_islatest(int $cmid, int $userid, int $attemptnumber, int $latest): void {
        global $DB;
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
     * The marker is re-read from core rather than taken from the event payload:
     * 5.3 fires marker_added / marker_removed per slot change, so "the last
     * event wins" and a re-read would disagree on every multi-marker student and
     * rewrite the row on every poll.
     *
     * On a multi-marking activity it is the provisional responsible marker of
     * owner decision D1: the marker in position 1, in core's order
     * ({@see self::position_one_marker()}), so adding a second marker never
     * moves it nor restarts its clock. Elsewhere the lowest id among the rows
     * is kept, which on a single-marker activity is the one marker.
     *
     * @param int $assignid
     * @param int $userid
     * @param bool $multi Whether the activity uses multiple marking
     *                    ({@see marking_progress::uses_multiple_marking()}).
     * @return int Marker user id, or 0 when unallocated.
     */
    private static function allocated_marker_id(int $assignid, int $userid, bool $multi): int {
        global $DB;

        if (self::$hasallocationtable === null) {
            self::$hasallocationtable = $DB->get_manager()->table_exists('assign_allocated_marker');
        }
        if (self::$hasallocationtable && $multi) {
            return self::position_one_marker($assignid, $userid);
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
     * The marker in position 1 of a student's active slots.
     *
     * Core's order, as {@see \assign::get_marker_allocations()} with
     * `$includeplaceholders = false` and {@see marking_progress::for_rows()}:
     * rows by optional then id, placeholders (no marker) and disabled optional
     * slots skipped. Never the lowest user id, which means nothing to a teacher.
     *
     * @param int $assignid
     * @param int $userid The student.
     * @return int Marker user id, or 0 when no slot is active.
     */
    private static function position_one_marker(int $assignid, int $userid): int {
        global $DB;
        $rows = $DB->get_records_select(
            'assign_allocated_marker',
            'assignment = :assignid AND student = :userid AND marker > 0 AND (optional = 0 OR enabled = 1)',
            ['assignid' => $assignid, 'userid' => $userid],
            'optional ASC, id ASC',
            'id, marker',
            0,
            1
        );
        return $rows ? (int) reset($rows)->marker : 0;
    }

    /**
     * Who is responsible for a multi-marking cycle that has just got its agreed
     * grade, decided once by the activity's method (owner decision D1).
     *
     * - `manual`: the grade row's grader, the teacher who saved the final grade
     *   ({@see \assign::apply_grade_to_user()} sets it to `$USER`), read now
     *   because a later feedback-only save overwrites it. Their responsibility
     *   began when the last expected mark reached "Marking completed": the
     *   latest completion among the active slots, from the slot table, or from
     *   `{assign_mark}.timemodified` where the slot row has none. A grader of 0
     *   or less (a restore maps an unknown grader to 0; a calculated grade
     *   carries -1) falls back to position 1.
     * - `maximum`: the marker of the highest complete mark among the active
     *   slots (the marks {@see \assign::get_marks()} reads); the slots arrive in
     *   position order and only a strictly higher mark replaces the best, so a
     *   tie goes to the lower position. Core cannot say who won: the agreed
     *   grade carries grader -1.
     * - `average`, `first` and anything else: position 1.
     *
     * Every marker but the confirmer starts from their own slot allocation on
     * this cycle. A start the slot table does not know is null: unmeasurable.
     *
     * @param \stdClass $assign The {assign} row.
     * @param int $subid Ledger row id of the cycle.
     * @param int $userid The student.
     * @param int $attemptnumber
     * @param \stdClass|null $grade The attempt's {assign_grades} row.
     * @return array|null Keys marker, label (an ATTRIB_* constant) and start
     *                    (int|null); null when nobody can be named.
     */
    private static function resolve_responsible(
        \stdClass $assign,
        int $subid,
        int $userid,
        int $attemptnumber,
        ?\stdClass $grade
    ): ?array {
        [$slots, $slotrows, $marks] = self::cycle_slots($assign, $subid, $userid, $attemptnumber, $grade);

        $label = self::ATTRIB_POSITION;
        switch ((string) $assign->multimarkmethod) {
            case 'manual':
                $grader = $grade !== null ? (int) ($grade->grader ?? 0) : 0;
                if ($grader > 0) {
                    $start = null;
                    foreach ($slots as $slot) {
                        if ($slot['state'] !== marking_progress::MARK_COMPLETE) {
                            continue;
                        }
                        $row = $slotrows[$slot['marker']] ?? null;
                        $done = $row !== null && $row->timemarked !== null
                            ? (int) $row->timemarked
                            : (int) ($marks[$slot['marker']]->timemodified ?? 0);
                        if ($done > 0 && ($start === null || $done > $start)) {
                            $start = $done;
                        }
                    }
                    return ['marker' => $grader, 'label' => self::ATTRIB_CONFIRMER, 'start' => $start];
                }
                $label = self::ATTRIB_FALLBACK;
                break;
            case 'maximum':
                $best = null;
                $bestmark = null;
                foreach ($slots as $slot) {
                    if ($slot['state'] !== marking_progress::MARK_COMPLETE) {
                        continue;
                    }
                    $value = (float) $marks[$slot['marker']]->mark;
                    if ($bestmark === null || $value > $bestmark) {
                        $best = $slot['marker'];
                        $bestmark = $value;
                    }
                }
                if ($best !== null) {
                    return ['marker' => $best, 'label' => self::ATTRIB_MAXMARK, 'start' => self::slot_start($slotrows, $best)];
                }
                $label = self::ATTRIB_FALLBACK;
                break;
        }

        if (empty($slots)) {
            return null;
        }
        $first = (int) $slots[0]['marker'];
        return ['marker' => $first, 'label' => $label, 'start' => self::slot_start($slotrows, $first)];
    }

    /**
     * The provisional responsible marker of a multi-marking cycle: position 1,
     * from its slot allocation on this cycle.
     *
     * @param \stdClass $assign The {assign} row.
     * @param int $subid Ledger row id of the cycle.
     * @param int $userid The student.
     * @param int $attemptnumber
     * @param \stdClass|null $grade The attempt's {assign_grades} row.
     * @return array Keys marker (0 when no slot is active) and start (int|null).
     */
    private static function provisional_responsible(
        \stdClass $assign,
        int $subid,
        int $userid,
        int $attemptnumber,
        ?\stdClass $grade
    ): array {
        [$slots, $slotrows] = self::cycle_slots($assign, $subid, $userid, $attemptnumber, $grade);
        if (empty($slots)) {
            return ['marker' => 0, 'start' => null];
        }
        $first = (int) $slots[0]['marker'];
        return ['marker' => $first, 'start' => self::slot_start($slotrows, $first)];
    }

    /**
     * The active slots of a cycle with their stored slot rows and marks.
     *
     * The slots come from {@see marking_progress::for_rows()}, the one reading
     * of core's multi-marking state, in position order. Slot rows are this
     * cycle's live ones in {block_feedback_tracker_alloc}; marks are the grade
     * row's {assign_mark} rows. Both keyed by marker.
     *
     * @param \stdClass $assign The {assign} row.
     * @param int $subid Ledger row id of the cycle.
     * @param int $userid The student.
     * @param int $attemptnumber
     * @param \stdClass|null $grade The attempt's {assign_grades} row.
     * @return array [slot entries, slot rows by marker, marks by marker].
     */
    private static function cycle_slots(
        \stdClass $assign,
        int $subid,
        int $userid,
        int $attemptnumber,
        ?\stdClass $grade
    ): array {
        global $DB;

        $progress = marking_progress::for_rows([(object) [
            'id' => $subid,
            'iteminstance' => (int) $assign->id,
            'userid' => $userid,
            'attemptnumber' => $attemptnumber,
        ]]);
        $slots = $progress[$subid]['slots'] ?? [];

        $slotrows = [];
        $records = $DB->get_records_select(
            'block_feedback_tracker_alloc',
            'subid = :subid AND timeremoved IS NULL',
            ['subid' => $subid],
            'id ASC',
            'id, marker, timeallocated, timemarked'
        );
        foreach ($records as $rec) {
            $slotrows[(int) $rec->marker] = $rec;
        }

        $marks = [];
        if ($grade !== null && !empty($grade->id)) {
            $records = $DB->get_records('assign_mark', ['gradeid' => $grade->id], 'id ASC', 'id, marker, mark, timemodified');
            foreach ($records as $rec) {
                $marks[(int) $rec->marker] = $rec;
            }
        }
        return [$slots, $slotrows, $marks];
    }

    /**
     * A marker's allocation instant on this cycle, from the slot table.
     *
     * @param array $slotrows Live slot rows keyed by marker.
     * @param int $marker
     * @return int|null Null when the slot table does not know it.
     */
    private static function slot_start(array $slotrows, int $marker): ?int {
        $row = $slotrows[$marker] ?? null;
        return $row !== null && $row->timeallocated !== null ? (int) $row->timeallocated : null;
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
     * On a multi-marking cycle whose responsible marker is decided
     * (`allocattrib` set, owner decision D1), `timeallocmarker` is when
     * responsibility landed on that person, and a null there means the start is
     * unknown: the turnaround is then null, not measured from the first
     * allocation.
     *
     * A non-positive marker interval yields null, never 0.0: zero effective
     * hours bands as `excellent`, so a stamp at or after the grading (which the
     * reconciler produces when it discovers an allocation after the fact)
     * would read as a flawless turnaround for a named person. Such a row is
     * flagged `late` and left out of the medians.
     *
     * @param \stdClass|null $existing The current ledger row, or null when new.
     * @param int $courseid
     * @param int $groupid Reporting group, for the academic-time calendar.
     * @param int $timesubmitted This cycle's hand-in time.
     * @param int|null $timegraded The recorded response time, or null while open.
     * @return array Keys: queuehours, allochours, allocdays, allocbucket, late, known.
     */
    private static function allocation_measures(
        ?\stdClass $existing,
        int $courseid,
        int $groupid,
        int $timesubmitted,
        ?int $timegraded
    ): array {
        /* `known` says whether this snapshot saw an allocation at all, which is
         * not the same as the measures being null: the late branch below
         * returns nulls as a known answer. Only the two early returns mean
         * "nothing to say", and the caller then keeps the stored measures. */
        $none = [
            'queuehours' => null,
            'allochours' => null,
            'allocdays' => null,
            'allocbucket' => null,
            'late' => false,
            'known' => false,
        ];
        if ($existing === null) {
            return $none;
        }

        $allocated = $existing->timeallocated !== null ? (int) $existing->timeallocated : 0;
        if ($allocated <= 0) {
            return $none;
        }
        /* An attributed cycle (owner decision D1) whose responsible person has
         * no known start is unmeasurable: falling back to the first allocation
         * would charge them a turnaround that began before they were
         * responsible. */
        $attributed = ($existing->allocattrib ?? null) !== null;
        if ($existing->timeallocmarker !== null) {
            $current = (int) $existing->timeallocmarker;
        } else {
            $current = $attributed ? 0 : $allocated;
        }

        $out = $none;
        $out['known'] = true;
        if ($allocated > $timesubmitted) {
            $out['queuehours'] = academic_time::elapsed_effective_hours(
                $courseid,
                $groupid,
                $timesubmitted,
                $allocated
            );
        } else {
            // Allocated before or at hand-in: there was no queue at all.
            $out['queuehours'] = 0.0;
        }

        if ($current <= 0) {
            return $out;
        }
        $upper = $timegraded ?? time();
        if ($upper <= $current) {
            $out['late'] = $timegraded !== null;
            return $out;
        }
        $hours = academic_time::elapsed_effective_hours($courseid, $groupid, $current, $upper);
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
     * `assign::get_submission_group_members()`. Each participant is resolved
     * as {@see self::team_group_for_user()} resolves one, from memberships
     * loaded once for the whole course.
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

        $groupsof = self::team_groups_by_user($assign, $context, $esql, $params);
        $defaultgroup = empty($assign->preventsubmissionnotingroup) ? 0 : null;
        $out = [];
        foreach ($enrolled as $userid) {
            $userid = (int) $userid;
            $groups = $groupsof[$userid] ?? [];
            $team = count($groups) === 1 ? $groups[0] : $defaultgroup;
            if ($team === $groupid) {
                $out[] = $userid;
            }
        }
        return $out;
    }

    /**
     * The groups of the activity's grouping each enrolled participant belongs
     * to, in one query.
     *
     * The bulk form of the per-user lookup in {@see self::team_group_for_user()},
     * and it must return the same groups: those `groups_get_all_groups()`
     * returns for one user with the grouping and participation-only
     * arguments, including its rule that a viewer without
     * `moodle/course:viewhiddengroups` is not shown a group whose membership
     * is hidden from everyone.
     *
     * @param \stdClass $assign The {assign} row.
     * @param \context_course $context
     * @param string $esql The enrolled-users query from get_enrolled_sql().
     * @param array $eparams Its parameters.
     * @return array Group ids keyed by user id; a user in no such group is absent.
     */
    private static function team_groups_by_user(
        \stdClass $assign,
        \context_course $context,
        string $esql,
        array $eparams
    ): array {
        global $DB;

        $params = $eparams + ['bftcourseid' => (int) $context->instanceid];
        $groupingjoin = '';
        $groupingid = (int) ($assign->teamsubmissiongroupingid ?? 0);
        if ($groupingid > 0) {
            $groupingjoin = 'JOIN {groupings_groups} gg ON gg.groupid = g.id AND gg.groupingid = :bftgroupingid';
            $params['bftgroupingid'] = $groupingid;
        }
        $visibility = '';
        if (!has_capability('moodle/course:viewhiddengroups', $context)) {
            [$vsql, $vparams] = $DB->get_in_or_equal(
                [GROUPS_VISIBILITY_ALL, GROUPS_VISIBILITY_MEMBERS, GROUPS_VISIBILITY_OWN],
                SQL_PARAMS_NAMED,
                'bftvis'
            );
            $visibility = "AND g.visibility $vsql";
            $params += $vparams;
        }

        $rs = $DB->get_recordset_sql(
            "SELECT gm.id, gm.userid, gm.groupid
               FROM {groups_members} gm
               JOIN {groups} g ON g.id = gm.groupid
               $groupingjoin
               JOIN ($esql) e ON e.id = gm.userid
              WHERE g.courseid = :bftcourseid
                AND g.participation = 1
                $visibility",
            $params
        );
        $out = [];
        foreach ($rs as $r) {
            $out[(int) $r->userid][] = (int) $r->groupid;
        }
        $rs->close();
        return $out;
    }

    /**
     * Insert one cycle row, tolerating a concurrent writer.
     *
     * The check-then-insert in build_and_store() races against a second request
     * touching the same tuple, and the unique index turns that race into an
     * exception that would abort the teacher's grade save. Outside a
     * transaction it is safe to recover by re-reading; inside one it is not,
     * because a failed INSERT has already poisoned the connection on
     * PostgreSQL, so the exception is rethrown (an event observer's exception
     * becomes a debugging notice).
     *
     * Recovery does not write `$record` over the winner's row: it was derived
     * as if no row existed, so the sticky rules (earliest-wins `timegraded`,
     * the reinstated gradebook `closedsource`/`timeclosed`) never ran and it
     * would discard a response that already reached the student. Instead this
     * returns null and {@see self::build_and_store()} re-derives against the
     * winner's row. On that single retry ($retrying) a blind update is the
     * last resort: adopting the row beats throwing. Both recoveries are pinned
     * by concurrent_insert_test, which makes the read miss a row already there.
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
        /* timeclosed and closedsource go through COALESCE: a cycle the
         * gradebook already closed carries a timeclosed with no timereleased,
         * and a plain assignment would move that recorded response later,
         * breaking earliest-wins. The release itself is recorded either way. */
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
     * than taken from the event payload; see allocated_marker_id().
     *
     * A cycle whose responsible marker is already decided (`allocattrib` set,
     * owner decision D1) keeps its marker and clock: only build_and_store()
     * changes them, and only when the activity withdraws the agreed grade. A
     * missing first allocation is still stamped there, since the queue is not
     * the marker's.
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
            "SELECT cm.id, cm.course, cm.instance, a.markingworkflow, a.markingallocation,
                    a.markercount, a.optionalmarkercount
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
          LEFT JOIN {assign} a ON a.id = cm.instance
              WHERE cm.id = :cmid",
            ['modname' => 'assign', 'cmid' => $cmid]
        );
        if (!$cm) {
            return;
        }
        $marker = self::allocated_marker_id((int) $cm->instance, $userid, marking_progress::uses_multiple_marking($cm));

        $rows = $DB->get_records(
            'block_feedback_tracker_sub',
            ['cmid' => $cmid, 'userid' => $userid],
            '',
            'id, courseid, groupid, timesubmitted, timeallocated, allocmarkerid, allocattrib'
        );
        $tuples = [];
        foreach ($rows as $row) {
            $decided = $row->allocattrib !== null;
            $update = (object) [
                'id' => (int) $row->id,
                'timemodified' => time(),
            ];
            if (!$decided) {
                $update->allocmarkerid = $marker;
            }
            if ($marker > 0) {
                /* allocsource describes how this row's instant was obtained, so
                 * it changes only when an instant does; otherwise a sweep
                 * running with ALLOC_SOURCE_RECONCILED would relabel stamps that
                 * came from a real marker_updated event. */
                $stamped = false;
                if ($row->timeallocated === null) {
                    $update->timeallocated = $when;
                    /* The coordination queue closes now, so measure it now —
                     * the value is final and nothing later can recover it if
                     * the row is rebuilt from a different starting point. */
                    $update->queuehours = $when > (int) $row->timesubmitted
                        ? academic_time::elapsed_effective_hours(
                            (int) $row->courseid,
                            (int) $row->groupid,
                            (int) $row->timesubmitted,
                            $when
                        )
                        : 0.0;
                    $stamped = true;
                }
                if (!$decided && (int) $row->allocmarkerid !== $marker) {
                    // A new marker starts their own clock; the queue metric stays put.
                    $update->timeallocmarker = $when;
                    $update->allochours = null;
                    $update->allocdays = null;
                    $update->allocbucket = null;
                    $stamped = true;
                }
                /* Either branch recorded an instant (a reassignment's
                 * timeallocmarker included), so the label describes it. */
                if ($stamped) {
                    $update->allocsource = $source;
                }
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
     * Each member is resolved against all of their groups
     * ({@see rule_resolver::resolve_rule()}), so a member of two overridden
     * groups keeps whichever override core applies to them, and after a
     * deletion falls back to their remaining groups rather than to the
     * activity.
     *
     * @param int $assignid
     * @param int $groupid
     * @return void
     */
    public static function re_resolve_rules_for_assign_group(int $assignid, int $groupid): void {
        global $DB;

        if (!$DB->record_exists('assign', ['id' => $assignid])) {
            return;
        }

        /* Selected by membership of the overridden group, not by the ledger's
         * `groupid`: that column is the reporting attribution (the group a
         * student last joined, see group_resolver) and need not match the
         * group an override targets. Only that group's members can change:
         * everybody else's governing override does not involve it. An edit
         * that moves an override to another group names the new group only,
         * so the old group's members are left to the reconciler's rule-drift
         * sweep. */
        $rows = $DB->get_records_sql(
            "SELECT l.id, l.userid, l.courseid, l.groupid
               FROM {block_feedback_tracker_sub} l
               JOIN {groups_members} gm ON gm.userid = l.userid AND gm.groupid = :groupid
              WHERE l.iteminstance = :assignid",
            ['groupid' => $groupid, 'assignid' => $assignid]
        );

        $now = time();
        $tuples = [];
        $rules = [];
        foreach ($rows as $row) {
            $userid = (int) $row->userid;
            $rules[$userid] ??= rule_resolver::resolve_rule($assignid, $userid);
            $rule = $rules[$userid];
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

        // Enqueue the reporting tuples touched, not the override group.
        foreach ($tuples as [$courseid, $reportgroupid]) {
            dirty_queue::enqueue($courseid, $reportgroupid, dirty_queue::REASON_PAUSE);
        }
    }

    /**
     * Re-resolve the rule columns for one user's ledger rows on one assign.
     *
     * Used when a user-level override or an extension changes: both alter the
     * dates that submission is judged against, and neither is visible in any
     * other signal the plugin receives. The extension is read from
     * {assign_user_flags} by {@see rule_resolver::resolve_rule()}.
     *
     * @param int $assignid
     * @param int $userid
     * @return void
     */
    public static function re_resolve_rules_for_assign_user(int $assignid, int $userid): void {
        global $DB;

        if (!$DB->record_exists('assign', ['id' => $assignid])) {
            return;
        }
        $rows = $DB->get_records('block_feedback_tracker_sub', [
            'iteminstance' => $assignid,
            'userid' => $userid,
        ], '', 'id, courseid, groupid');
        if (empty($rows)) {
            return;
        }

        $rule = rule_resolver::resolve_rule($assignid, $userid);
        $now = time();
        $tuples = [];
        foreach ($rows as $row) {
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
     * Re-date one user's current rows in a course after their groups changed,
     * writing only the rows whose dates move.
     *
     * A student's groups reach the dates only through the governing group
     * override ({@see rule_resolver}). So a row is read only when its activity
     * still carries a group override, or when it stores dates other than the
     * activity's own: a row storing the activity's dates on an activity with
     * no group override resolves to them again. On a course without group
     * overrides that is one query over the user's rows, returning nothing. The
     * second arm covers a deleted group, whose overrides core removes in an
     * observer of its own ({@see assign_process_group_deleted_in_course()}),
     * possibly before this runs.
     *
     * The rows are selected with {@see rule_resolver::drift_sql()}, as the
     * reconciler's rule-drift sweep selects them, so a row written here is one
     * that sweep would repair, and afterwards it has nothing to repair. Only
     * the current cycle is written, as the writer and that sweep do.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $limit Most rows to write, 0 for no limit. When more rows
     *                   moved, nothing is written and null is returned, so the
     *                   caller can hand the work to a background task.
     * @return int|null Rows written, or null when there were more than $limit.
     */
    public static function re_resolve_rules_for_group_change(int $courseid, int $userid, int $limit = 0): ?int {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT l.id, l.courseid, l.groupid, " . rule_resolver::select_sql('a', 'rule') . "
               FROM {block_feedback_tracker_sub} l
               JOIN {assign} a ON a.id = l.iteminstance
               " . rule_resolver::joins_sql('a.id', 'l.userid') . "
              WHERE l.userid = :userid
                AND l.courseid = :courseid
                AND l.iscurrent = 1
                AND (EXISTS (SELECT 1
                               FROM {assign_overrides} gco
                              WHERE gco.assignid = a.id AND gco.groupid IS NOT NULL)
                     OR COALESCE(l.timeopens, 0) <> a.allowsubmissionsfromdate
                     OR COALESCE(l.timecloses, 0) <> a.duedate
                     OR COALESCE(l.timecutoff, 0) <> a.cutoffdate)
                AND " . rule_resolver::drift_sql('l', 'a') . "
           ORDER BY l.id ASC",
            ['userid' => $userid, 'courseid' => $courseid],
            0,
            $limit > 0 ? $limit + 1 : 0
        );
        if ($limit > 0 && count($rows) > $limit) {
            return null;
        }

        $now = time();
        $tuples = [];
        foreach ($rows as $row) {
            $rule = rule_resolver::rule_from_row($row, 'rule');
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
        foreach ($tuples as [$tuplecourseid, $groupid]) {
            dirty_queue::enqueue($tuplecourseid, $groupid, dirty_queue::REASON_PAUSE);
        }
        return count($rows);
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
     * The tuples are collected before the delete, while the rows still exist,
     * or the rollup would keep its stale totals. Shared by the user-scoped
     * delete methods below.
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

        slot_ledger::delete_for_ledger_where($where, $params);
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
     * For a deleted account. Rows where the deleted user is only the
     * allocated marker (`allocmarkerid`) are the students' and keep that id,
     * as core keeps `assign_user_flags.allocatedmarker` when it deletes an
     * account; their slot rows as a marker stay with those students' cycles
     * for the same reason. An erasure request clears both through
     * {@see \block_feedback_tracker\privacy\provider::delete_data_for_user()}.
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
     * Delete all ledger and slot rows for one course-module and re-enqueue the
     * tuples they belonged to.
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
        slot_ledger::delete_for_cm($cmid);
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
     * Delete all ledger, slot, rollup, trend, queue and backfill-cursor rows
     * for one course.
     *
     * @param int $courseid
     * @return void
     */
    public static function delete_for_course(int $courseid): void {
        global $DB;

        slot_ledger::delete_for_course($courseid);
        $DB->delete_records('block_feedback_tracker_sub', ['courseid' => $courseid]);
        $DB->delete_records('block_feedback_tracker_group', ['courseid' => $courseid]);
        $DB->delete_records('block_feedback_tracker_trend', ['courseid' => $courseid]);
        $DB->delete_records('block_feedback_tracker_queue', ['courseid' => $courseid]);
        backfill_cursor::delete($courseid);
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
        /* Default-ON checkbox: get_config() returns false for a key never
         * saved (a web upgrade leaves it so until upgradesettings.php is
         * saved), so only an explicit '0' turns the filter off. */
        $setting = get_config('block_feedback_tracker', 'exclude_grader_submissions');
        if ($setting !== false && $setting !== null && (string) $setting === '0') {
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
     * Drop the per-request memos (grader filter and allocation-table probe).
     * Called by tests and by long-running tasks such as reconcile_ledger.
     *
     * @return void
     */
    public static function reset_memos(): void {
        self::$skipsubmittermemo = [];
        self::$hasallocationtable = null;
    }
}
