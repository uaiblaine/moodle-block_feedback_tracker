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
 * Scheduled task: reconcile the ledger against the live assign tables.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\dirty_queue;
use block_feedback_tracker\local\sla\grading_state;
use block_feedback_tracker\local\sla\group_resolver;
use block_feedback_tracker\local\sla\retention;
use block_feedback_tracker\local\sla\submission_ledger;
use block_feedback_tracker\local\sla\submission_status;

/**
 * Repairs the ledger for the mutations mod_assign performs without emitting
 * any usable event. Events cannot cover these, so a periodic diff against the
 * source tables is the only mechanism available:
 *
 *  - `assign::add_attempt()` inserts a new attempt and flips the previous
 *    row's `latest` flag with zero event traffic, so the superseded attempt
 *    would otherwise stay pending for ever.
 *  - Blind marking makes `gradebook_item_update()` return false before doing
 *    anything, suppressing `submission_graded` for the whole activity until
 *    identities are revealed.
 *  - Grading a non-latest attempt returns early, before the trigger.
 *  - A gradebook-side override or lock silences the event too.
 *  - `assign::reset_userdata()` bulk-deletes submissions with
 *    `delete_records_select()`, leaving orphan ledger rows behind.
 *  - Due dates, cut-offs, overrides and extensions change with no per-row
 *    signal.
 *
 * Each sweep is keyset-paged behind its own cursor, bounded per tick and
 * gated on {@see course_access::is_processable()}. Six sweeps dispatch their
 * repairs as adhoc {@see backfill_one_submission} tasks, keeping the
 * academic-time engine out of this task's own time budget.
 *
 * THREE act directly, for two different reasons. The two cleanup sweeps
 * ({@see self::sweep_orphans()}, {@see self::sweep_departed_participants()})
 * must, because the repair task re-gates every row on processability and would
 * silently skip exactly the hidden or block-less courses whose rows most need
 * removing. {@see self::sweep_unstamped_allocations()} does so for no such
 * reason: it calls `stamp_allocation_for_user()`, which runs the academic-time
 * engine per row, inside this budget, on the sweep that is last in the
 * registry and therefore the first to be cut off by the shared deadline.
 */
class reconcile_ledger extends \core\task\scheduled_task {
    /** Default rows examined per sweep per tick. */
    public const DEFAULT_BATCH = 500;

    /** Default soft time cap for the whole tick, in seconds. */
    public const DEFAULT_TIME_CAP = 50;

    /** Rows per dispatched adhoc repair batch. */
    private const REPAIR_CHUNK = 50;

    /** Config key prefix for the per-sweep keyset cursors. */
    private const CURSOR_PREFIX = 'reconcile_cursor_';

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_reconcile_ledger', 'block_feedback_tracker');
    }

    /**
     * Run one bounded pass over each sweep.
     *
     * @return void
     */
    public function execute(): void {
        /* Default-ON checkbox: an unset value (false) means enabled, and only
         * an explicit '0' turns it off. The `?: 1` read this replaced could
         * never see the off state — admin_setting_configcheckbox stores '0',
         * which is falsy, so `'0' ?: 1` yielded 1 and the documented escape
         * hatch never fired. Same read as bootstrap::config_bundle(). */
        $activecfg = get_config('block_feedback_tracker', 'reconcile_active');
        $active = ($activecfg === false || $activecfg === null) ? true : ((string) $activecfg !== '0');
        if (!$active) {
            mtrace('reconcile_ledger: disabled by setting.');
            return;
        }
        $processable = course_access::processable_course_ids();
        if (empty($processable)) {
            mtrace('reconcile_ledger: no processable courses.');
            return;
        }

        $batch = (int) (get_config('block_feedback_tracker', 'reconcile_batch_size') ?: self::DEFAULT_BATCH);
        if ($batch < 1) {
            $batch = self::DEFAULT_BATCH;
        }
        /* Its own cap since 2026081100. Sharing drain_time_cap_seconds meant one
         * number sized a task that inserts a couple of hundred queue rows AND a
         * task that runs nine diffs against the assignment tables. The upgrade
         * step seeds this from the old key, so a site that had tuned the drain
         * cap keeps the behaviour it had rather than silently reverting to the
         * default. */
        $timecap = (int) (get_config('block_feedback_tracker', 'reconcile_time_cap_seconds')
            ?: self::DEFAULT_TIME_CAP);
        $deadline = time() + $timecap;

        // Flush the memos the ledger consults; a long-lived cron process would
        // otherwise carry one tick's decisions into the next.
        submission_ledger::reset_memos();
        group_resolver::reset_memo();

        $sweeps = [
            'missing' => 'sweep_missing_rows',
            'team' => 'sweep_missing_team_rows',
            'gradestate' => 'sweep_grade_divergence',
            'gradebook' => 'sweep_gradebook_closures',
            'latest' => 'sweep_latest_drift',
            'orphan' => 'sweep_orphans',
            'participant' => 'sweep_departed_participants',
            'rules' => 'sweep_rule_drift',
            'allocation' => 'sweep_unstamped_allocations',
        ];
        $total = 0;
        foreach ($sweeps as $key => $method) {
            if (time() > $deadline) {
                mtrace('reconcile_ledger: time cap reached; remaining sweeps run next tick.');
                break;
            }
            $repaired = $this->$method($processable, $batch, $key);
            $total += $repaired;
            if ($repaired > 0) {
                mtrace(sprintf('reconcile_ledger: %s repaired %d row(s).', $key, $repaired));
            }
        }
        mtrace(sprintf('reconcile_ledger: %d row(s) repaired this tick.', $total));
    }

    /**
     * The oldest submission the row-creating sweeps may still resurrect.
     *
     * Without this the pruner and the reconciler fight: the pruner deletes a
     * closed row past its retention window, the reconciler sees a submission
     * with no ledger row and recreates it, and the pair burn a batch of work
     * against each other on every tick, for ever. Both read the same cutoff.
     *
     * Returns 0 when retention is off, which admits everything — the historical
     * behaviour.
     *
     * @return int Epoch seconds, or 0 for no floor.
     */
    private function retention_floor(): int {
        return retention::cutoff() ?? 0;
    }

    /**
     * Submissions with no ledger row at all.
     *
     * The fingerprint of `add_attempt()` (a brand-new reopened row nobody was
     * told about), of a restored course, and of any event lost in flight.
     *
     * Restricted to users who are still active participants, matching
     * {@see self::sweep_departed_participants()}'s
     * `get_enrolled_sql($context, '', 0, true)` predicate — deleted account,
     * suspended enrolment, suspended method, or an enrolment outside its
     * start/end window all disqualify. Without that restriction the two sweeps
     * fight: this one runs first on every tick and rebuilds exactly the rows
     * the departed-participant sweep deleted on the previous one, so an
     * unenrolled student's rows reappear for ever, each round trip costing a
     * backfill dispatch and a rollup recompute. The predicate is spelled out
     * inline rather than reusing `get_enrolled_sql()` because that helper is
     * course-scoped while this sweep is deliberately cross-course.
     *
     * The site course is exempt, because core exempts it: `get_enrolled_join()`
     * skips the whole enrolment join when the course context is SITEID —
     * "all users are enrolled on the frontpage" — and nobody holds a
     * {user_enrolments} row there. Without the exemption an activity on the
     * front page would stop being repaired entirely, which is a quieter and
     * worse failure than the oscillation this predicate exists to end.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Row ceiling for this sweep.
     * @param string $key Cursor key.
     * @return int Rows dispatched for repair.
     */
    private function sweep_missing_rows(array $processable, int $batch, string $key): int {
        global $DB;
        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $now = time();
        $rows = $DB->get_records_sql(
            "SELECT s.id AS subid, cm.id AS cmid, cm.course AS courseid,
                    s.userid, s.groupid, s.attemptnumber
               FROM {assign_submission} s
               JOIN {user} u ON u.id = s.userid AND u.deleted = 0
               JOIN {assign} a ON a.id = s.assignment
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
          LEFT JOIN {block_feedback_tracker_sub} l
                 ON l.cmid = cm.id
                AND l.userid = s.userid
                AND l.attemptnumber = s.attemptnumber
              WHERE s.userid > 0
                AND a.teamsubmission = 0
                AND s.id > :cursor
                AND cm.course $csql
                AND l.id IS NULL
                AND s.timemodified >= :retention
                AND (cm.course = :siteid OR EXISTS (
                    SELECT 1
                      FROM {user_enrolments} ue
                      JOIN {enrol} en ON en.id = ue.enrolid AND en.courseid = cm.course
                     WHERE ue.userid = s.userid
                       AND ue.status = 0
                       AND en.status = 0
                       AND (ue.timestart = 0 OR ue.timestart <= :nowstart)
                       AND (ue.timeend = 0 OR ue.timeend > :nowend)
                ))
           ORDER BY s.id ASC",
            $cparams + [
                'modname' => 'assign',
                'cursor' => $this->cursor($key),
                'retention' => $this->retention_floor(),
                'nowstart' => $now,
                'nowend' => $now,
                'siteid' => SITEID,
            ],
            0,
            $batch
        );
        return $this->dispatch_and_advance($rows, $key, $batch, 'subid');
    }

    /**
     * Team group rows whose members were never fanned out.
     *
     * Matched on `teamgroupid` rather than `userid`, because the ledger stores
     * one row per member while the source stores one row per group.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Row ceiling for this sweep.
     * @param string $key Cursor key.
     * @return int Rows dispatched for repair.
     */
    private function sweep_missing_team_rows(array $processable, int $batch, string $key): int {
        global $DB;
        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $rows = $DB->get_records_sql(
            "SELECT s.id AS subid, cm.id AS cmid, cm.course AS courseid,
                    s.userid, s.groupid, s.attemptnumber
               FROM {assign_submission} s
               JOIN {assign} a ON a.id = s.assignment AND a.teamsubmission = 1
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
          LEFT JOIN {block_feedback_tracker_sub} l
                 ON l.cmid = cm.id
                AND l.teamgroupid = s.groupid
                AND l.attemptnumber = s.attemptnumber
              WHERE s.userid = 0
                AND s.id > :cursor
                AND cm.course $csql
                AND l.id IS NULL
                AND s.timemodified >= :retention
           ORDER BY s.id ASC",
            $cparams + [
                'modname' => 'assign',
                'cursor' => $this->cursor($key),
                'retention' => $this->retention_floor(),
            ],
            0,
            $batch
        );
        return $this->dispatch_and_advance($rows, $key, $batch, 'subid');
    }

    /**
     * Ledger rows whose graded state no longer matches the assign tables.
     *
     * Cycle-scoped (`iscurrent = 1`) so a legitimately pending later cycle is
     * not re-flagged for ever, and the mark tests mirror
     * {@see grading_state::resolve()} exactly — a divergence sweep that
     * disagrees with the writer would dispatch the same repair on every tick.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Row ceiling for this sweep.
     * @param string $key Cursor key.
     * @return int Rows dispatched for repair.
     */
    private function sweep_grade_divergence(array $processable, int $batch, string $key): int {
        global $DB;
        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $rows = $DB->get_records_sql(
            "SELECT l.id AS subid, l.cmid, l.courseid, l.userid, l.groupid,
                    l.teamgroupid, l.attemptnumber, a.teamsubmission AS isteam
               FROM {block_feedback_tracker_sub} l
               JOIN {course_modules} cm ON cm.id = l.cmid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {assign} a ON a.id = cm.instance
          LEFT JOIN {assign_grades} g
                 ON g.assignment = a.id
                AND g.userid = l.userid
                AND g.attemptnumber = l.attemptnumber
              WHERE l.id > :cursor
                AND l.iscurrent = 1
                AND l.courseid $csql
                AND (
                     (l.timemarked IS NULL
                      AND g.id IS NOT NULL
                      AND g.grade IS NOT NULL
                      AND g.grade >= 0
                      AND g.timemodified > l.timesubmitted)
                  OR (l.timemarked IS NOT NULL
                      AND (g.id IS NULL
                           OR g.grade IS NULL
                           OR g.grade < 0
                           OR g.timemodified <= l.timesubmitted))
                )
           ORDER BY l.id ASC",
            $cparams + ['modname' => 'assign', 'cursor' => $this->cursor($key)],
            0,
            $batch
        );
        return $this->dispatch_and_advance($rows, $key, $batch, 'subid');
    }

    /**
     * Ledger rows whose latest flag or status drifted from the source.
     *
     * The direct fingerprint of `add_attempt()`, which fires nothing at all.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Row ceiling for this sweep.
     * @param string $key Cursor key.
     * @return int Rows dispatched for repair.
     */
    private function sweep_latest_drift(array $processable, int $batch, string $key): int {
        global $DB;
        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $rows = $DB->get_records_sql(
            "SELECT l.id AS subid, l.cmid, l.courseid, l.userid, l.groupid,
                    l.teamgroupid, l.attemptnumber, a.teamsubmission AS isteam
               FROM {block_feedback_tracker_sub} l
               JOIN {course_modules} cm ON cm.id = l.cmid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {assign} a ON a.id = cm.instance
               JOIN {assign_submission} s
                 ON s.assignment = a.id
                AND s.attemptnumber = l.attemptnumber
                AND ((a.teamsubmission = 0 AND s.userid = l.userid)
                     OR (a.teamsubmission = 1 AND s.userid = 0 AND s.groupid = l.teamgroupid))
              WHERE l.id > :cursor
                AND l.iscurrent = 1
                AND l.courseid $csql
                AND (l.islatest <> s.latest
                     OR l.submissionstatus <> COALESCE(s.status, :statusnew))
           ORDER BY l.id ASC",
            $cparams + [
                'modname' => 'assign',
                'cursor' => $this->cursor($key),
                'statusnew' => submission_status::NEW,
            ],
            0,
            $batch
        );
        return $this->dispatch_and_advance($rows, $key, $batch, 'subid');
    }

    /**
     * Ledger rows whose source submission is gone.
     *
     * Course reset deletes {assign_submission} with a bare
     * `delete_records_select()`, and by default leaves the grades behind — so
     * the probe keys on the submission, not the grade. Acts directly: a repair
     * task would re-gate on processability and skip exactly the courses whose
     * rows most need removing.
     *
     * Team-aware, or the fan-out and this sweep would delete and recreate each
     * other's rows for ever — and the discriminator has to be the LIVE
     * `assign.teamsubmission` flag, never the stored `teamgroupid`. mod_assign's
     * default team group IS group 0, so a member row for it is stored with
     * `teamgroupid = 0`, byte-identical to an individual row; the observer
     * routes on the live flag for exactly this reason (see
     * {@see \block_feedback_tracker\local\sla\observer}). Probing such a row as
     * individual looks for `s.userid = l.userid` while the source row carries
     * `userid = 0`, finds nothing, and deletes a perfectly good member row that
     * {@see self::sweep_missing_team_rows()} then recreates on the next tick —
     * one backfill dispatch plus one rollup recompute per round trip, for ever.
     *
     * An activity whose {assign} row is gone leaves `a.teamsubmission` NULL, so
     * neither branch matches and the row is deleted. That is the intended
     * reading: no activity means no submission to measure.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Row ceiling for this sweep.
     * @param string $key Cursor key.
     * @return int Rows deleted.
     */
    private function sweep_orphans(array $processable, int $batch, string $key): int {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT l.id, l.courseid, l.groupid
               FROM {block_feedback_tracker_sub} l
          LEFT JOIN {course_modules} cm ON cm.id = l.cmid
          LEFT JOIN {assign} a ON a.id = l.iteminstance
          LEFT JOIN {assign_submission} s
                 ON s.assignment = l.iteminstance
                AND s.attemptnumber = l.attemptnumber
                AND ((a.teamsubmission = 0 AND s.userid = l.userid)
                     OR (a.teamsubmission = 1 AND s.userid = 0 AND s.groupid = l.teamgroupid))
              WHERE l.id > :cursor
                AND (cm.id IS NULL OR s.id IS NULL)
           ORDER BY l.id ASC",
            ['cursor' => $this->cursor($key)],
            0,
            $batch
        );
        if (empty($rows)) {
            $this->set_cursor($key, 0);
            return 0;
        }
        $ids = [];
        $tuples = [];
        $lastid = 0;
        foreach ($rows as $r) {
            $ids[] = (int) $r->id;
            $lastid = (int) $r->id;
            $tuples[(int) $r->courseid . ':' . (int) $r->groupid] = [
                (int) $r->courseid, (int) $r->groupid,
            ];
        }
        [$isql, $iparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'd');
        $DB->delete_records_select('block_feedback_tracker_sub', "id $isql", $iparams);
        foreach ($tuples as [$courseid, $groupid]) {
            dirty_queue::enqueue($courseid, $groupid, dirty_queue::REASON_SUBMISSION);
        }
        $this->set_cursor($key, count($rows) < $batch ? 0 : $lastid);
        return count($rows);
    }

    /**
     * Open cycles the gradebook has already answered.
     *
     * `user_graded` covers the low-latency case, but it cannot cover this one:
     * flipping a grade to overridden or locked fires no event at all, and a
     * re-grade to the same value fires none either, because core gates the
     * event on the final grade *value* having changed. Both leave a response
     * sitting in {grade_grades} that no signal will ever announce.
     *
     * Visibility is part of the predicate, not an afterthought: a hidden grade
     * has not reached the student, and core overloads `hidden` so that 1 means
     * hidden while anything larger is a hidden-until instant that stops hiding
     * once it passes.
     *
     * Acts by dispatching the ordinary re-derivation rather than writing the
     * stamp here, so the writer stays the single place the earliest-wins rule
     * lives.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Row ceiling for this sweep.
     * @param string $key Cursor key.
     * @return int Rows dispatched for repair.
     */
    private function sweep_gradebook_closures(array $processable, int $batch, string $key): int {
        global $DB;
        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $now = time();
        $rows = $DB->get_records_sql(
            "SELECT l.id, l.cmid, l.courseid, l.userid, l.attemptnumber, l.groupid,
                    l.teamgroupid, a.teamsubmission AS isteam
               FROM {block_feedback_tracker_sub} l
               JOIN {assign} a ON a.id = l.iteminstance
               JOIN {grade_items} gi
                 ON gi.iteminstance = l.iteminstance
                AND gi.itemtype = :itemtype
                AND gi.itemmodule = :itemmodule
                AND gi.itemnumber = 0
               JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = l.userid
              WHERE l.timeclosed IS NULL
                AND l.iscurrent = 1
                /* islatest as well as iscurrent: {grade_grades} holds one grade
                 * per user per item with no attempt dimension, so without this
                 * a single gradebook response would close every unmarked
                 * attempt of that user and be counted once per attempt in the
                 * graded window. The observer avoids it by resolving through
                 * latest_attempt_number(); the sweep has to say so. */
                AND l.islatest = 1
                AND l.timesubmitted > 0
                AND gg.overridden > l.timesubmitted
                AND l.id > :cursor
                AND l.courseid $csql
                AND gg.finalgrade IS NOT NULL
                AND (gg.hidden = 0 OR (gg.hidden > 1 AND gg.hidden <= :nowgrade))
                AND (gi.hidden = 0 OR (gi.hidden > 1 AND gi.hidden <= :nowitem))
           ORDER BY l.id ASC",
            $cparams + [
                'itemtype' => 'mod',
                'itemmodule' => 'assign',
                'cursor' => $this->cursor($key),
                'nowgrade' => $now,
                'nowitem' => $now,
            ],
            0,
            $batch
        );
        return $this->dispatch_and_advance($rows, $key, $batch, 'id');
    }

    /**
     * Ledger rows for users who are no longer active participants.
     *
     * Unenrolment, suspension and user deletion all leave the rows behind, and
     * an unenrolled student's work is nobody's outstanding task. Reuses core's
     * own `get_enrolled_sql()` definition so the plugin and mod_assign agree
     * on who counts. Acts directly, like the orphan sweep.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Row ceiling for this sweep.
     * @param string $key Cursor key.
     * @return int Rows deleted.
     */
    private function sweep_departed_participants(array $processable, int $batch, string $key): int {
        global $DB;

        /* Per course, because get_enrolled_sql() is course-scoped. The cursor
         * here is a course index rather than a row id: one course per tick
         * keeps the query bounded without a cross-course keyset. */
        sort($processable);
        $idx = $this->cursor($key);
        if ($idx >= count($processable)) {
            $idx = 0;
        }
        $courseid = (int) $processable[$idx];
        $this->set_cursor($key, $idx + 1 >= count($processable) ? 0 : $idx + 1);

        try {
            $context = \context_course::instance($courseid);
        } catch (\Throwable $e) {
            return 0;
        }
        [$esql, $eparams] = get_enrolled_sql($context, '', 0, true);
        $rows = $DB->get_records_sql(
            "SELECT l.id, l.groupid
               FROM {block_feedback_tracker_sub} l
          LEFT JOIN ($esql) e ON e.id = l.userid
              WHERE l.courseid = :courseid
                AND e.id IS NULL
           ORDER BY l.id ASC",
            $eparams + ['courseid' => $courseid],
            0,
            $batch
        );
        if (empty($rows)) {
            return 0;
        }
        $ids = [];
        $groupids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r->id;
            $groupids[(int) $r->groupid] = true;
        }
        [$isql, $iparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'd');
        $DB->delete_records_select('block_feedback_tracker_sub', "id $isql", $iparams);
        foreach (array_keys($groupids) as $groupid) {
            dirty_queue::enqueue($courseid, $groupid, dirty_queue::REASON_SUBMISSION);
        }
        return count($rows);
    }

    /**
     * Ledger rows whose stored due-date rule no longer matches the activity.
     *
     * Covers a changed due date or cut-off, and any override or extension
     * whose event was lost — including a revoked extension, whose only
     * remaining evidence is that the stored close time stopped matching the
     * assignment's own due date.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Row ceiling for this sweep.
     * @param string $key Cursor key.
     * @return int Rows dispatched for repair.
     */
    private function sweep_rule_drift(array $processable, int $batch, string $key): int {
        global $DB;
        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $rows = $DB->get_records_sql(
            "SELECT l.id AS subid, l.cmid, l.courseid, l.userid, l.groupid,
                    l.teamgroupid, l.attemptnumber, a.teamsubmission AS isteam
               FROM {block_feedback_tracker_sub} l
               JOIN {assign} a ON a.id = l.iteminstance
          LEFT JOIN {assign_user_flags} uf
                 ON uf.assignment = l.iteminstance
                AND uf.userid = l.userid
              WHERE l.id > :cursor
                AND l.courseid $csql
                AND ((COALESCE(uf.extensionduedate, 0) > 0
                      AND COALESCE(l.timecloses, 0) <> uf.extensionduedate)
                  OR (COALESCE(uf.extensionduedate, 0) = 0
                      AND l.hasrule = 1
                      AND COALESCE(l.timecloses, 0) <> COALESCE(a.duedate, 0)))
           ORDER BY l.id ASC",
            $cparams + ['cursor' => $this->cursor($key)],
            0,
            $batch
        );
        return $this->dispatch_and_advance($rows, $key, $batch, 'subid');
    }

    /**
     * Allocations core made without telling anyone.
     *
     * On Moodle 4.5 and 5.1 only the batch "Set allocated marker" operation
     * fires `marker_updated`; quick grading and the grading form write
     * `assign_user_flags.allocatedmarker` in silence. On 5.2 and later every path fires,
     * but a de-allocation still does not. Without this sweep the marker
     * turnaround is measurable only for batch-allocated work, which on most
     * sites is a small and non-random slice.
     *
     * The stamp it writes is the moment of discovery, not of allocation, so it
     * is recorded as `reconciled` and is accurate only to the sweep period.
     * That distinction is the whole point of `allocsource`: a median built
     * from discovery times would silently understate every turnaround, so the
     * two populations stay separable.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Row ceiling for this sweep.
     * @param string $key Cursor key.
     * @return int Rows stamped.
     */
    private function sweep_unstamped_allocations(array $processable, int $batch, string $key): int {
        global $DB;

        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $params = $cparams + ['cursor' => $this->cursor($key)];

        /* Moodle 5.2 moved allocation out of assign_user_flags into its own
         * table, so the join differs by core version. Both shapes select the
         * same thing: a ledger row whose activity has a marker allocated that
         * the ledger has never stamped. */
        if ($DB->get_manager()->table_exists('assign_allocated_marker')) {
            $sql = "SELECT l.id AS subid, l.cmid, l.courseid, l.userid, l.groupid,
                           l.teamgroupid, l.attemptnumber, MIN(am.marker) AS markerid
                      FROM {block_feedback_tracker_sub} l
                      JOIN {assign_allocated_marker} am
                        ON am.assignment = l.iteminstance
                       AND am.student = l.userid
                       AND am.marker > 0
                     WHERE l.id > :cursor
                       AND l.timeallocated IS NULL
                       AND l.courseid $csql
                  GROUP BY l.id, l.cmid, l.courseid, l.userid, l.groupid,
                           l.teamgroupid, l.attemptnumber
                  ORDER BY l.id ASC";
        } else {
            $sql = "SELECT l.id AS subid, l.cmid, l.courseid, l.userid, l.groupid,
                           l.teamgroupid, l.attemptnumber, uf.allocatedmarker AS markerid
                      FROM {block_feedback_tracker_sub} l
                      JOIN {assign_user_flags} uf
                        ON uf.assignment = l.iteminstance
                       AND uf.userid = l.userid
                       AND uf.allocatedmarker > 0
                     WHERE l.id > :cursor
                       AND l.timeallocated IS NULL
                       AND l.courseid $csql
                  ORDER BY l.id ASC";
        }
        $rows = $DB->get_records_sql($sql, $params, 0, $batch);
        if (empty($rows)) {
            $this->set_cursor($key, 0);
            return 0;
        }

        $now = time();
        $lastid = 0;
        $tuples = [];
        foreach ($rows as $r) {
            $lastid = (int) $r->subid;
            submission_ledger::stamp_allocation_for_user(
                (int) $r->cmid,
                (int) $r->userid,
                $now,
                submission_ledger::ALLOC_SOURCE_RECONCILED
            );
            $tuples[(int) $r->courseid . ':' . (int) $r->groupid] = [
                (int) $r->courseid, (int) $r->groupid,
            ];
        }
        foreach ($tuples as [$courseid, $groupid]) {
            dirty_queue::enqueue($courseid, $groupid, dirty_queue::REASON_SUBMISSION);
        }
        $this->set_cursor($key, count($rows) < $batch ? 0 : $lastid);
        return count($rows);
    }

    /**
     * Queue adhoc repairs for a sweep's rows and move its cursor on.
     *
     * The cursor resets to 0 when the sweep returned fewer rows than the
     * batch, so the next tick starts a fresh pass rather than stalling at the
     * end of the table.
     *
     * A team activity's ledger rows are per MEMBER while the repair is
     * per GROUP: `upsert_for_cm_user_attempt()` re-routes any member of a team
     * activity back through the whole-group fan-out. Emitting one descriptor
     * per member therefore ran the entire fan-out once per member — quadratic
     * in group size, and split across parallel adhoc tasks that then raced to
     * write the same rows. Team rows collapse to one `userid = 0` container
     * descriptor per (cmid, team group, attempt) instead, which is the shape
     * {@see backfill_one_submission} already routes to
     * `upsert_for_team_attempt()`.
     *
     * @param array $rows Sweep result, keyed by the cursor column.
     * @param string $key Cursor key.
     * @param int $batch Batch size used.
     * @param string $cursorfield Field carrying the keyset value.
     * @return int Rows examined (not descriptors emitted — the cursor and the
     *             end-of-pass test both key on rows read from the driving set).
     */
    private function dispatch_and_advance(array $rows, string $key, int $batch, string $cursorfield): int {
        if (empty($rows)) {
            $this->set_cursor($key, 0);
            return 0;
        }
        $buffer = [];
        $seen = [];
        $lastid = 0;
        $batches = 0;
        $queued = 0;
        foreach ($rows as $r) {
            $lastid = (int) $r->{$cursorfield};
            $cmid = (int) $r->cmid;
            $attempt = (int) $r->attemptnumber;
            $courseid = (int) $r->courseid;
            /* Route on the live teamsubmission flag where the sweep selected
             * it, and on the source row's own userid where it did not — never
             * on the stored teamgroupid, which cannot tell a default-group team
             * row (teamgroupid = 0) from an individual one. */
            $isteam = isset($r->isteam)
                ? ((int) $r->isteam === 1)
                : ((int) ($r->userid ?? 0) === 0);
            if ($isteam) {
                $teamgroupid = (int) ($r->teamgroupid ?? $r->groupid ?? 0);
                $dedupkey = 't:' . $cmid . ':' . $teamgroupid . ':' . $attempt;
                $descriptor = [
                    'cmid' => $cmid,
                    'userid' => 0,
                    'groupid' => $teamgroupid,
                    'attemptnumber' => $attempt,
                    'courseid' => $courseid,
                ];
            } else {
                $userid = (int) $r->userid;
                $dedupkey = 'u:' . $cmid . ':' . $userid . ':' . $attempt;
                $descriptor = [
                    'cmid' => $cmid,
                    'userid' => $userid,
                    'groupid' => (int) ($r->groupid ?? 0),
                    'attemptnumber' => $attempt,
                    'courseid' => $courseid,
                ];
            }
            if (isset($seen[$dedupkey])) {
                continue;
            }
            $seen[$dedupkey] = true;
            $buffer[] = $descriptor;
            if (count($buffer) >= self::REPAIR_CHUNK) {
                $queued += $this->queue_repair($buffer) ? 1 : 0;
                $batches++;
                $buffer = [];
            }
        }
        if (!empty($buffer)) {
            $queued += $this->queue_repair($buffer) ? 1 : 0;
            $batches++;
        }
        if ($batches > $queued) {
            mtrace(sprintf(
                'reconcile_ledger: %s had %d of %d repair batch(es) refused '
                . '(already pending, or blocked by a retry-exhausted row).',
                $key,
                $batches - $queued,
                $batches
            ));
        }
        $this->set_cursor($key, count($rows) < $batch ? 0 : $lastid);
        return count($rows);
    }

    /**
     * Queue one adhoc repair batch, logging rather than propagating failures.
     *
     * The return value of `queue_adhoc_task()` is not discardable. A `false`
     * used to mean only "an identical payload is already pending", which is the
     * dedup working as intended; since Moodle 5.2 it is also returned up front
     * for a refused component, before the dedup check runs. And on 4.5 a
     * retry-exhausted `{task_adhoc}` row still matches the dedup probe, so an
     * identical payload stays blocked for as long as
     * `task_adhoc_failed_retention` keeps the dead row — up to four weeks.
     * Either way the caller has NOT queued anything, so it counts the outcome
     * instead of assuming the repair is on its way.
     *
     * @param array $rows Row descriptors for backfill_one_submission.
     * @return bool True when a new adhoc task was created.
     */
    private function queue_repair(array $rows): bool {
        try {
            $task = new backfill_one_submission();
            $task->set_custom_data(['rows' => $rows]);
            return \core\task\manager::queue_adhoc_task($task, true) !== false;
        } catch (\Throwable $e) {
            debugging(sprintf(
                'reconcile_ledger: could not queue a repair batch of %d row(s): %s',
                count($rows),
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Read one sweep's keyset cursor.
     *
     * @param string $key Sweep key.
     * @return int
     */
    private function cursor(string $key): int {
        return (int) (get_config('block_feedback_tracker', self::CURSOR_PREFIX . $key) ?: 0);
    }

    /**
     * Persist one sweep's keyset cursor.
     *
     * @param string $key Sweep key.
     * @param int $value New cursor value.
     * @return void
     */
    private function set_cursor(string $key, int $value): void {
        set_config(self::CURSOR_PREFIX . $key, (string) $value, 'block_feedback_tracker');
    }
}
