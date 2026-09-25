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

use block_feedback_tracker\local\audit\recompute_log;
use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\dirty_queue;
use block_feedback_tracker\local\sla\grading_state;
use block_feedback_tracker\local\sla\process_memos;
use block_feedback_tracker\local\sla\retention;
use block_feedback_tracker\local\sla\rule_resolver;
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
 * Each sweep walks its driving set in windows of `reconcile_batch_size` rows
 * behind its own keyset cursor, as many windows as its share of the tick's
 * time cap allows ({@see self::walk()}), and every sweep but the orphan one is
 * limited to the courses {@see course_access::processable_course_ids()}
 * returns. Seven sweeps dispatch their repairs — six as
 * {@see backfill_one_submission}, the allocation one as
 * {@see stamp_allocations} — which keeps the academic-time engine out of this
 * task's own time budget.
 *
 * The two cleanup sweeps ({@see self::sweep_orphans()},
 * {@see self::sweep_departed_participants()}) delete directly: the repair
 * task only upserts, and it re-gates every row on processability, which would
 * skip the hidden or block-less courses whose orphan rows most need removing.
 *
 * Sweeps run in a rotating order, each tick resuming after the last one that
 * ran, because the deadline is tested between them and a fixed order would
 * never reach the tail on a site that runs out of budget.
 */
class reconcile_ledger extends \core\task\scheduled_task {
    /** Default driving rows per window. */
    public const DEFAULT_BATCH = 500;

    /** Default soft time cap for the whole tick, in seconds. */
    public const DEFAULT_TIME_CAP = 50;

    /** Rows per dispatched adhoc repair batch. */
    private const REPAIR_CHUNK = 50;

    /** Config key prefix for the per-sweep keyset cursors. */
    private const CURSOR_PREFIX = 'reconcile_cursor_';

    /** Config key holding the key of the last sweep that ran, for rotation. */
    private const LAST_SWEEP_KEY = 'reconcile_last_sweep';

    /**
     * Courses the departed-participant sweep visits per tick. A constant rather
     * than a setting: the tick's real bound is the time cap, and this only stops
     * one sweep spending the whole of it before the deadline is next tested.
     */
    private const COURSES_PER_TICK = 25;

    /**
     * Ceiling on the window size, whatever the setting says. A window becomes
     * an `id IN (...)` list of that many placeholders in the probe, and
     * PostgreSQL refuses a statement carrying more than 65 535 of them.
     */
    private const MAX_BATCH = 10000;

    /** Token the window predicate is spliced into, in a probe template. */
    private const WINDOW_TOKEN = '__window__';

    /**
     * @var int Epoch second after which the sweep now running must stop
     *          starting windows: its share of what was left of the tick when
     *          its turn came. Zero outside execute(), so a window sweep driven
     *          directly walks exactly one window (and the departed-participant
     *          sweep visits no course).
     */
    private int $sweepdeadline = 0;

    /**
     * @var array<string, bool> Whether each sweep spent its driving set this
     *                          tick, keyed by sweep key. Reported in the audit
     *                          row: a sweep that never shows true is one whose
     *                          pass never completes, which no other signal says.
     */
    private array $exhausted = [];

    /** @var array<string, int> Driving rows each sweep examined this tick, keyed by sweep key. */
    private array $examined = [];

    /** @var array<string, int> Windows each sweep walked this tick, keyed by sweep key. */
    private array $windows = [];

    /** @var array Repair descriptors waiting to be queued for the sweep in flight. */
    private array $repairbuffer = [];

    /** @var array<string, bool> Dedup keys of the descriptors the sweep in flight emitted. */
    private array $repairseen = [];

    /** @var int Repair batches the sweep in flight tried to queue. */
    private int $repairbatches = 0;

    /** @var int Repair batches the sweep in flight actually queued. */
    private int $repairqueued = 0;

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
        process_memos::reset();
        /* Default-ON checkbox: an unset value (false) means enabled, and only
         * an explicit '0' turns it off. A `?: 1` read would never see the off
         * state, because the stored '0' is falsy. */
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
        $batch = min($batch, self::MAX_BATCH);
        /* A cap of its own rather than drain_time_cap_seconds: the drain only
         * queues a few hundred rows, while this runs nine diffs against the
         * assignment tables. The upgrade step that introduced the setting
         * seeded it from drain_time_cap_seconds. */
        $timecap = (int) (get_config('block_feedback_tracker', 'reconcile_time_cap_seconds')
            ?: self::DEFAULT_TIME_CAP);
        $deadline = time() + $timecap;

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
        /* Resume after the last sweep that ran, so the tail of the registry is
         * reached on a site whose ticks run out of budget. The marker is the
         * sweep key, never an index, so it stays valid when the registry is
         * reordered or extended. On a tick that reaches every sweep the last
         * one to run is the last in the order, so rotation is a no-op. */
        $order = array_keys($sweeps);
        $lastran = (string) (get_config('block_feedback_tracker', self::LAST_SWEEP_KEY) ?: '');
        $resumeat = array_search($lastran, $order, true);
        if ($resumeat !== false) {
            $start = ((int) $resumeat + 1) % count($order);
            if ($start > 0) {
                $order = array_merge(array_slice($order, $start), array_slice($order, 0, $start));
            }
        }

        $started = time();
        $total = 0;
        $stats = [];
        $emptyms = 0;
        $skipped = [];
        $timecapped = false;
        /* Seeded from the stored marker: a tick already past its deadline runs
         * no sweep, and must leave the rotation where it was rather than reset
         * it to registry order. */
        $ranlast = $lastran;
        foreach ($order as $position => $key) {
            $method = $sweeps[$key];
            if (time() > $deadline) {
                $timecapped = true;
                // Sliced from the rotated order: what was skipped is what this
                // tick would have run next, not what the registry lists next.
                $skipped = array_slice($order, (int) $position);
                mtrace('reconcile_ledger: time cap reached; remaining sweeps run next tick.');
                break;
            }
            /* Each sweep gets an equal share of whatever is left of the tick
             * when its turn comes, tested between windows inside the sweep. A
             * cheap sweep hands its unused share on to the next; a hungry one
             * cannot spend the whole tick before the others have had a window. */
            $this->sweepdeadline = self::share_deadline(time(), $deadline, count($order) - (int) $position);
            $sweepstarted = microtime(true);
            $repaired = $this->$method($processable, $batch, $key);
            $sweepms = (int) round((microtime(true) - $sweepstarted) * 1000);
            /* `exhausted` is null only for a sweep that never reached
             * advance_cursor(), so "did not answer" stays distinguishable from
             * "did not finish". The departed-participant sweep visits courses,
             * not windows, so its `examined` and `windows` stay null. */
            $stats[$key] = [
                'rows' => $repaired,
                'examined' => $this->examined[$key] ?? null,
                'windows' => $this->windows[$key] ?? null,
                'ms' => $sweepms,
                'cursor' => $this->cursor($key),
                'exhausted' => $this->exhausted[$key] ?? null,
            ];
            if ($repaired === 0) {
                /* The cost of proving nothing was wrong: on a converged ledger
                 * this is the whole tick, the task's steady-state cost. */
                $emptyms += $sweepms;
            }
            $total += $repaired;
            $ranlast = $key;
            if ($repaired > 0) {
                mtrace(sprintf('reconcile_ledger: %s repaired %d row(s).', $key, $repaired));
            }
        }
        set_config(self::LAST_SWEEP_KEY, $ranlast, 'block_feedback_tracker');
        mtrace(sprintf('reconcile_ledger: %d row(s) repaired this tick.', $total));

        /* Recorded on every tick, including those that repaired nothing: an
         * empty tick's timings are the task's steady-state cost, which nothing
         * else records. At the default two-hourly schedule that is twelve rows
         * a day, pruned after 90 days. */
        recompute_log::record(
            recompute_log::REASON_RECONCILE,
            $total,
            null,
            [
                'sweeps' => $stats,
                'emptyms' => $emptyms,
                'skipped' => array_values($skipped),
                'timecapped' => $timecapped,
                // The order this tick actually used, so rotation is visible.
                'order' => $order,
                'courses' => count($processable),
                'batch' => $batch,
                'timecap' => $timecap,
            ],
            $started,
            time()
        );
    }

    /**
     * The oldest submission the row-creating sweeps may still recreate.
     *
     * Shared with prune_ledger through {@see retention::cutoff()}, which says
     * why the two must agree. Returns 0 when retention is off, which admits
     * everything.
     *
     * @return int Epoch seconds, or 0 for no floor.
     */
    private function retention_floor(): int {
        return retention::cutoff() ?? 0;
    }

    /**
     * The instant the sweep whose turn it is must stop starting windows.
     *
     * An equal split of what is left of the tick among the sweeps still to
     * run — never less than a second, so a sweep always walks at least one
     * window, and never past the tick's own deadline.
     *
     * @param int $now Epoch second.
     * @param int $deadline The tick's deadline.
     * @param int $left Sweeps still to run this tick, this one included.
     * @return int Epoch second.
     */
    private static function share_deadline(int $now, int $deadline, int $left): int {
        return min($deadline, $now + max(1, intdiv(max(0, $deadline - $now), max(1, $left))));
    }

    /**
     * Walk one sweep's driving set in windows of `$batch` rows.
     *
     * `$window` fetches the next window: up to `$batch` driving rows after a
     * cursor, keyed by their keyset id (the first column selected) and ordered
     * by it. `$act` probes one window for the rows that need acting on, acts
     * on them, and returns how many it found. The walk keeps going until the
     * driving set is spent or the sweep's share of the tick is, and moves the
     * cursor on from the window (the last driving row examined), never from
     * what the probe returned.
     *
     * The batch therefore bounds the rows examined, not the rows returned. A
     * LIMIT over the probe's own predicate, which is false for almost every
     * row of a converged ledger, makes the engine walk the whole driving set
     * to prove there are fewer than `$batch` matches; bounding the window
     * keeps a tick's cost proportional to the rows it examined.
     *
     * Exhaustion is a claim about the window alone: a window shorter than
     * `$batch` (or empty) means the driving set ran out. Stopping because the
     * time share ran out is not exhaustion; the cursor stays where the last
     * window ended and the next tick resumes there
     * ({@see self::advance_cursor()}).
     *
     * @param string $key Sweep key.
     * @param int $batch Window size.
     * @param callable $window Takes the cursor (int), returns the next window (array).
     * @param callable $act Takes one window (array), returns the rows acted on (int).
     * @return int Rows acted on across every window walked.
     */
    private function walk(string $key, int $batch, callable $window, callable $act): int {
        $cursor = $this->cursor($key);
        $acted = 0;
        $examined = 0;
        $windows = 0;
        $exhausted = false;
        do {
            $rows = $window($cursor);
            if (empty($rows)) {
                $exhausted = true;
                break;
            }
            $windows++;
            $examined += count($rows);
            $cursor = (int) array_key_last($rows);
            $acted += $act($rows);
            if (count($rows) < $batch) {
                $exhausted = true;
                break;
            }
        } while (time() <= $this->sweepdeadline);
        $this->examined[$key] = $examined;
        $this->windows[$key] = $windows;
        $this->advance_cursor($key, $cursor, $exhausted);
        return $acted;
    }

    /**
     * A window fetcher over the ledger.
     *
     * The predicates go here, on the driving set, rather than in the probe:
     * the window is what fixes a sweep's scope, so a probe over it needs no
     * course filter of its own and carries only the expensive half.
     *
     * @param string $where Extra predicates on the ledger row (aliased `l`), each starting with AND.
     * @param array $params Their parameters.
     * @param int $batch Window size.
     * @return callable Takes the cursor (int), returns up to `$batch` ledger ids keyed by id.
     */
    private function ledger_window(string $where, array $params, int $batch): callable {
        global $DB;
        return fn(int $cursor): array => $DB->get_records_sql(
            "SELECT l.id
               FROM {block_feedback_tracker_sub} l
              WHERE l.id > :cursor $where
           ORDER BY l.id ASC",
            $params + ['cursor' => $cursor],
            0,
            $batch
        );
    }

    /**
     * An act callback that probes one window and queues a repair for every row
     * the probe returns.
     *
     * @param string $template Probe SQL with {@see self::WINDOW_TOKEN} where the `id IN (...)` predicate goes.
     * @param array $params Probe parameters (never prefixed `w`, which the window uses).
     * @return callable Takes one window (array), returns the rows queued for repair (int).
     */
    private function repair_probe(string $template, array $params): callable {
        global $DB;
        return function (array $window) use ($DB, $template, $params): int {
            [$wsql, $wparams] = $DB->get_in_or_equal(array_keys($window), SQL_PARAMS_NAMED, 'w');
            $rows = $DB->get_records_sql(str_replace(self::WINDOW_TOKEN, $wsql, $template), $wparams + $params);
            $this->buffer_repairs($rows);
            return count($rows);
        };
    }

    /**
     * Submissions with no ledger row at all.
     *
     * The fingerprint of `add_attempt()` (a brand-new reopened row nobody was
     * told about), of a restored course, and of any event lost in flight.
     *
     * Restricted to users who are still active participants, and must agree
     * with {@see self::sweep_departed_participants()}'s
     * `get_enrolled_sql($context, '', 0, true)`: a deleted account, a
     * suspended enrolment or method, or an enrolment outside its start/end
     * window all disqualify. Otherwise the two sweeps fight, this one
     * rebuilding on every pass the rows the other deleted, each round trip
     * costing a backfill dispatch and a rollup recompute. The predicate is
     * inlined because `get_enrolled_sql()` is course-scoped while this sweep is
     * cross-course.
     *
     * The site course is exempt, as in core: `get_enrolled_join()` skips the
     * enrolment join when the course is SITEID, and nobody holds a
     * {user_enrolments} row there. Without the exemption no front-page
     * activity would ever be repaired.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Window size.
     * @param string $key Cursor key.
     * @return int Rows dispatched for repair.
     */
    private function sweep_missing_rows(array $processable, int $batch, string $key): int {
        global $DB;
        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $now = time();
        $acted = $this->walk(
            $key,
            $batch,
            /* The driving set: a keyset range over {assign_submission} with
             * point lookups on the activity, carrying every predicate the sweep
             * applies to the source row itself. */
            fn(int $cursor): array => $DB->get_records_sql(
                "SELECT s.id
                   FROM {assign_submission} s
                   JOIN {assign} a ON a.id = s.assignment AND a.teamsubmission = 0
                   JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
                   JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  WHERE s.userid > 0
                    AND s.id > :cursor
                    AND cm.course $csql
                    AND s.timemodified >= :retention
               ORDER BY s.id ASC",
                $cparams + [
                    'modname' => 'assign',
                    'cursor' => $cursor,
                    'retention' => $this->retention_floor(),
                ],
                0,
                $batch
            ),
            $this->repair_probe(
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
                  WHERE s.id " . self::WINDOW_TOKEN . "
                    AND l.id IS NULL
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
                [
                    'modname' => 'assign',
                    'nowstart' => $now,
                    'nowend' => $now,
                    'siteid' => SITEID,
                ]
            )
        );
        $this->flush_repairs($key);
        return $acted;
    }

    /**
     * Team group rows whose members were never fanned out.
     *
     * Matched on `teamgroupid` rather than `userid`, because the ledger stores
     * one row per member while the source stores one row per group.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Window size.
     * @param string $key Cursor key.
     * @return int Rows dispatched for repair.
     */
    private function sweep_missing_team_rows(array $processable, int $batch, string $key): int {
        global $DB;
        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $acted = $this->walk(
            $key,
            $batch,
            fn(int $cursor): array => $DB->get_records_sql(
                "SELECT s.id
                   FROM {assign_submission} s
                   JOIN {assign} a ON a.id = s.assignment AND a.teamsubmission = 1
                   JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
                   JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  WHERE s.userid = 0
                    AND s.id > :cursor
                    AND cm.course $csql
                    AND s.timemodified >= :retention
               ORDER BY s.id ASC",
                $cparams + [
                    'modname' => 'assign',
                    'cursor' => $cursor,
                    'retention' => $this->retention_floor(),
                ],
                0,
                $batch
            ),
            $this->repair_probe(
                "SELECT s.id AS subid, cm.id AS cmid, cm.course AS courseid,
                        s.userid, s.groupid, s.attemptnumber
                   FROM {assign_submission} s
                   JOIN {assign} a ON a.id = s.assignment
                   JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
                   JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              LEFT JOIN {block_feedback_tracker_sub} l
                     ON l.cmid = cm.id
                    AND l.teamgroupid = s.groupid
                    AND l.attemptnumber = s.attemptnumber
                  WHERE s.id " . self::WINDOW_TOKEN . "
                    AND l.id IS NULL
               ORDER BY s.id ASC",
                ['modname' => 'assign']
            )
        );
        $this->flush_repairs($key);
        return $acted;
    }

    /**
     * Ledger rows whose graded state no longer matches the assign tables.
     *
     * Cycle-scoped (`iscurrent = 1`) so a legitimately pending later cycle is
     * not re-flagged for ever, and the mark tests mirror
     * {@see grading_state::resolve()} exactly — a divergence sweep that
     * disagrees with the writer would dispatch the same repair on every tick.
     * That includes its grade type "None" branch (`a.grade = 0`), where the
     * grade value is never read: core stores -1 on such a grading, so a value
     * test would select every marked row of the activity on every pass.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Window size.
     * @param string $key Cursor key.
     * @return int Rows dispatched for repair.
     */
    private function sweep_grade_divergence(array $processable, int $batch, string $key): int {
        global $DB;
        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $acted = $this->walk(
            $key,
            $batch,
            $this->ledger_window("AND l.iscurrent = 1 AND l.courseid $csql", $cparams, $batch),
            $this->repair_probe(
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
                  WHERE l.id " . self::WINDOW_TOKEN . "
                    AND (
                         (l.timemarked IS NULL
                          AND g.id IS NOT NULL
                          AND g.timemodified > l.timesubmitted
                          AND (a.grade = 0 OR (g.grade IS NOT NULL AND g.grade >= 0)))
                      OR (l.timemarked IS NOT NULL
                          AND (g.id IS NULL
                               OR g.timemodified <= l.timesubmitted
                               OR (a.grade <> 0 AND (g.grade IS NULL OR g.grade < 0))))
                    )
               ORDER BY l.id ASC",
                ['modname' => 'assign']
            )
        );
        $this->flush_repairs($key);
        return $acted;
    }

    /**
     * Ledger rows whose latest flag or status drifted from the source.
     *
     * The direct fingerprint of `add_attempt()`, which fires nothing at all.
     *
     * The source row is reached through two equality-only joins, one per
     * submission mode, never one join with an OR between the modes: each arm
     * of such an OR mixes columns from three tables, so neither PostgreSQL nor
     * MariaDB can use more than `assignment` of the unique key (assignment,
     * userid, groupid, attemptnumber), and every ledger row reads every
     * submission of its activity. Split, each arm is a point lookup. The arms
     * are mutually exclusive on the live `teamsubmission` flag, so the
     * COALESCE below reads whichever matched; the individual arm leaves
     * `groupid` unconstrained, as the writer's own lookup in
     * {@see submission_ledger} does, so selector and writer agree row for row.
     *
     * The activity is resolved through the course module, as the writer
     * resolves it. Reaching {assign} directly via `l.iteminstance` would
     * select rows the writer cannot repair (the module row gone while the
     * activity row survives), dispatching the same no-op on every pass until
     * the orphan sweep removes them.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Window size.
     * @param string $key Cursor key.
     * @return int Rows dispatched for repair.
     */
    private function sweep_latest_drift(array $processable, int $batch, string $key): int {
        global $DB;
        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $acted = $this->walk(
            $key,
            $batch,
            $this->ledger_window("AND l.iscurrent = 1 AND l.courseid $csql", $cparams, $batch),
            $this->repair_probe(
                "SELECT l.id AS subid, l.cmid, l.courseid, l.userid, l.groupid,
                        l.teamgroupid, l.attemptnumber, a.teamsubmission AS isteam
                   FROM {block_feedback_tracker_sub} l
                   JOIN {course_modules} cm ON cm.id = l.cmid
                   JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                   JOIN {assign} a ON a.id = cm.instance
              LEFT JOIN {assign_submission} si
                     ON a.teamsubmission = 0
                    AND si.assignment = a.id
                    AND si.userid = l.userid
                    AND si.attemptnumber = l.attemptnumber
              LEFT JOIN {assign_submission} st
                     ON a.teamsubmission = 1
                    AND st.assignment = a.id
                    AND st.userid = 0
                    AND st.groupid = l.teamgroupid
                    AND st.attemptnumber = l.attemptnumber
                  WHERE l.id " . self::WINDOW_TOKEN . "
                    AND COALESCE(si.id, st.id) IS NOT NULL
                    AND (l.islatest <> COALESCE(si.latest, st.latest)
                         OR l.submissionstatus <> COALESCE(si.status, st.status, :statusnew))
               ORDER BY l.id ASC",
                ['modname' => 'assign', 'statusnew' => submission_status::NEW]
            )
        );
        $this->flush_repairs($key);
        return $acted;
    }

    /**
     * Ledger rows whose source submission is gone.
     *
     * Course reset deletes {assign_submission} with a bare
     * `delete_records_select()` and keeps {assign_grades} unless gradebook
     * grades are reset too, so the probe keys on the submission, not the
     * grade. Acts directly, and the driving set carries no course filter at
     * all: the whole ledger is walked, a window at a time, because a repair
     * task would re-gate on processability and skip the courses whose rows
     * most need removing.
     *
     * Team-aware, discriminating on the live `assign.teamsubmission` flag,
     * never on the stored `teamgroupid`: mod_assign's default team group is
     * group 0, so a member row for it is stored with `teamgroupid = 0`, exactly
     * like an individual row (the observer routes on the live flag for the
     * same reason). Probed as an individual row it would find no source with
     * its userid and be deleted, and {@see self::sweep_missing_team_rows()}
     * would recreate it on the next pass.
     *
     * The two modes are two equality-only joins rather than one OR, for the
     * reason {@see self::sweep_latest_drift()} gives. An activity whose
     * {assign} row is gone leaves `a.teamsubmission` NULL, so neither arm
     * matches and the row is deleted: no activity means no submission to
     * measure.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Window size.
     * @param string $key Cursor key.
     * @return int Rows deleted.
     */
    private function sweep_orphans(array $processable, int $batch, string $key): int {
        global $DB;
        return $this->walk(
            $key,
            $batch,
            $this->ledger_window('', [], $batch),
            function (array $window) use ($DB): int {
                [$wsql, $wparams] = $DB->get_in_or_equal(array_keys($window), SQL_PARAMS_NAMED, 'w');
                $rows = $DB->get_records_sql(
                    "SELECT l.id, l.courseid, l.groupid
                       FROM {block_feedback_tracker_sub} l
                  LEFT JOIN {course_modules} cm ON cm.id = l.cmid
                  LEFT JOIN {assign} a ON a.id = l.iteminstance
                  LEFT JOIN {assign_submission} si
                         ON a.teamsubmission = 0
                        AND si.assignment = l.iteminstance
                        AND si.userid = l.userid
                        AND si.attemptnumber = l.attemptnumber
                  LEFT JOIN {assign_submission} st
                         ON a.teamsubmission = 1
                        AND st.assignment = l.iteminstance
                        AND st.userid = 0
                        AND st.groupid = l.teamgroupid
                        AND st.attemptnumber = l.attemptnumber
                      WHERE l.id $wsql
                        AND (cm.id IS NULL OR (si.id IS NULL AND st.id IS NULL))
                   ORDER BY l.id ASC",
                    $wparams
                );
                if (empty($rows)) {
                    return 0;
                }
                $ids = [];
                $tuples = [];
                foreach ($rows as $r) {
                    $ids[] = (int) $r->id;
                    $tuples[(int) $r->courseid . ':' . (int) $r->groupid] = [
                        (int) $r->courseid, (int) $r->groupid,
                    ];
                }
                [$isql, $iparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'd');
                $DB->delete_records_select('block_feedback_tracker_sub', "id $isql", $iparams);
                foreach ($tuples as [$courseid, $groupid]) {
                    dirty_queue::enqueue($courseid, $groupid, dirty_queue::REASON_SUBMISSION);
                }
                return count($rows);
            }
        );
    }

    /**
     * Open cycles the gradebook has already answered.
     *
     * `user_graded` covers the low-latency case, but not this one: flipping a
     * grade to overridden or locked fires no event, and neither does a
     * re-grade to the same value, because core triggers the event only when
     * the final grade value changes. Both leave a response in {grade_grades}
     * that no signal announces.
     *
     * A hidden grade has not reached the student, so visibility is part of the
     * predicate. Core's `hidden` is 1 for hidden, and a larger value is a
     * hidden-until instant that stops hiding once it passes.
     *
     * Acts by dispatching the ordinary re-derivation rather than writing the
     * stamp here, so the writer stays the single place the earliest-wins rule
     * lives.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Window size.
     * @param string $key Cursor key.
     * @return int Rows dispatched for repair.
     */
    private function sweep_gradebook_closures(array $processable, int $batch, string $key): int {
        global $DB;
        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $now = time();
        $acted = $this->walk(
            $key,
            $batch,
            /* islatest as well as iscurrent: {grade_grades} holds one grade per
             * user per item with no attempt dimension, so without this a single
             * gradebook response would close every unmarked attempt of that
             * user and be counted once per attempt in the graded window. The
             * observer does the same through latest_attempt_number(). */
            $this->ledger_window(
                "AND l.timeclosed IS NULL
                 AND l.iscurrent = 1
                 AND l.islatest = 1
                 AND l.timesubmitted > 0
                 AND l.courseid $csql",
                $cparams,
                $batch
            ),
            $this->repair_probe(
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
                  WHERE l.id " . self::WINDOW_TOKEN . "
                    AND gg.overridden > l.timesubmitted
                    AND gg.finalgrade IS NOT NULL
                    AND (gg.hidden = 0 OR (gg.hidden > 1 AND gg.hidden <= :nowgrade))
                    AND (gi.hidden = 0 OR (gi.hidden > 1 AND gi.hidden <= :nowitem))
               ORDER BY l.id ASC",
                [
                    'itemtype' => 'mod',
                    'itemmodule' => 'assign',
                    'nowgrade' => $now,
                    'nowitem' => $now,
                ]
            )
        );
        $this->flush_repairs($key);
        return $acted;
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
     * @param int $batch Row ceiling per course.
     * @param string $key Cursor key.
     * @return int Rows deleted.
     */
    private function sweep_departed_participants(array $processable, int $batch, string $key): int {
        /* Per course, because get_enrolled_sql() is course-scoped. The cursor is
         * a course id, not an index into the list: the list is rebuilt from
         * {block_instances} on every call, and an index would shift, skipping
         * a course for a whole cycle, whenever a course with a lower id gains
         * or loses the block. */
        sort($processable);
        $after = $this->cursor($key);
        $remaining = array_values(array_filter(
            $processable,
            static fn($cid) => (int) $cid > $after
        ));

        $drained = 0;
        $visited = 0;
        $lastvisited = $after;
        foreach ($remaining as $cid) {
            /* No break when a course fills its batch: one course with a large
             * backlog would then hold the cursor and starve every course after
             * it, whose departed students would keep counting in their pending
             * totals. A course that fills its batch sheds the rest on the next
             * pass. */
            if ($visited >= self::COURSES_PER_TICK || time() > $this->sweepdeadline) {
                break;
            }
            $visited++;
            $lastvisited = (int) $cid;
            $drained += $this->drain_departed_for_course((int) $cid, $batch);
        }

        /* Exhausted only when the course list itself ran out, never when a
         * budget stopped the loop; otherwise the cursor would wrap to 0
         * mid-pass and pin the sweep to the low-id courses. */
        $this->advance_cursor($key, $lastvisited, $visited === count($remaining));
        return $drained;
    }

    /**
     * Delete one course's rows for users who are no longer active participants.
     *
     * On SITEID `get_enrolled_sql()` degenerates to a scan of {user}, because
     * `get_enrolled_join()` skips every enrolment join on the front page. That
     * is why front-page rows are left alone unless the account itself is gone,
     * and it is not cheap on a large site.
     *
     * @param int $courseid
     * @param int $batch Row ceiling for this course.
     * @return int Rows deleted.
     */
    private function drain_departed_for_course(int $courseid, int $batch): int {
        global $DB;

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
     * Ledger rows whose stored rule no longer matches the activity.
     *
     * Covers a changed open date, due date or cut-off, an override or an
     * extension whose event was lost, a group override edit that moved it to
     * another group, a reordering of group overrides (core fires no event for
     * it), and the group changes the observer does not re-date: a membership
     * event that was lost, and a deleted group's former member whose rows
     * report under another group ({@see \block_feedback_tracker\local\sla\observer::group_deleted()}).
     *
     * The expected dates come from the same SQL the writer stores them with
     * ({@see rule_resolver::drift_sql()}), so a row is selected exactly when a
     * repair would change it; the repair writes only the current cycle, hence
     * `iscurrent = 1`.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Window size.
     * @param string $key Cursor key.
     * @return int Rows dispatched for repair.
     */
    private function sweep_rule_drift(array $processable, int $batch, string $key): int {
        global $DB;
        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');
        $acted = $this->walk(
            $key,
            $batch,
            $this->ledger_window("AND l.iscurrent = 1 AND l.courseid $csql", $cparams, $batch),
            $this->repair_probe(
                "SELECT l.id AS subid, l.cmid, l.courseid, l.userid, l.groupid,
                        l.teamgroupid, l.attemptnumber, a.teamsubmission AS isteam
                   FROM {block_feedback_tracker_sub} l
                   JOIN {course_modules} cm ON cm.id = l.cmid
                   JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                   JOIN {assign} a ON a.id = cm.instance
                   " . rule_resolver::joins_sql('a.id', 'l.userid') . "
                  WHERE l.id " . self::WINDOW_TOKEN . "
                    AND " . rule_resolver::drift_sql('l', 'a') . "
               ORDER BY l.id ASC",
                ['modname' => 'assign']
            )
        );
        $this->flush_repairs($key);
        return $acted;
    }

    /**
     * Allocations core made without telling anyone.
     *
     * On Moodle 4.5 and 5.1 only the batch "Set allocated marker" operation
     * fires `marker_updated`; quick grading and the grading form write
     * `assign_user_flags.allocatedmarker` in silence. On 5.2 every path fires,
     * but a de-allocation still does not. Without this sweep the marker
     * turnaround is measurable only for batch-allocated work, usually a small
     * and non-random slice.
     *
     * The stamp it writes is the moment of discovery, not of allocation, so it
     * is recorded as `reconciled` and is accurate only to the sweep period.
     * `allocsource` keeps the two populations separable, because a median
     * built from discovery times would understate every turnaround.
     *
     * @param array $processable Course ids in scope.
     * @param int $batch Window size.
     * @param string $key Cursor key.
     * @return int Rows stamped.
     */
    private function sweep_unstamped_allocations(array $processable, int $batch, string $key): int {
        global $DB;

        [$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c');

        /* Moodle 5.2 moved allocation out of assign_user_flags into its own
         * table, so the join differs by core version. Both shapes select the
         * same thing: a ledger row whose activity has a marker allocated that
         * the ledger has never stamped. */
        if ($DB->get_manager()->table_exists('assign_allocated_marker')) {
            $template = "SELECT l.id AS subid, l.cmid, l.courseid, l.userid, l.groupid,
                                l.teamgroupid, l.attemptnumber, MIN(am.marker) AS markerid
                           FROM {block_feedback_tracker_sub} l
                           JOIN {assign_allocated_marker} am
                             ON am.assignment = l.iteminstance
                            AND am.student = l.userid
                            AND am.marker > 0
                          WHERE l.id " . self::WINDOW_TOKEN . "
                       GROUP BY l.id, l.cmid, l.courseid, l.userid, l.groupid,
                                l.teamgroupid, l.attemptnumber
                       ORDER BY l.id ASC";
        } else {
            $template = "SELECT l.id AS subid, l.cmid, l.courseid, l.userid, l.groupid,
                                l.teamgroupid, l.attemptnumber, uf.allocatedmarker AS markerid
                           FROM {block_feedback_tracker_sub} l
                           JOIN {assign_user_flags} uf
                             ON uf.assignment = l.iteminstance
                            AND uf.userid = l.userid
                            AND uf.allocatedmarker > 0
                          WHERE l.id " . self::WINDOW_TOKEN . "
                       ORDER BY l.id ASC";
        }

        $now = time();
        $seen = [];
        $buffer = [];
        $acted = $this->walk(
            $key,
            $batch,
            $this->ledger_window("AND l.timeallocated IS NULL AND l.courseid $csql", $cparams, $batch),
            function (array $window) use ($DB, $template, $now, &$seen, &$buffer): int {
                [$wsql, $wparams] = $DB->get_in_or_equal(array_keys($window), SQL_PARAMS_NAMED, 'w');
                $rows = $DB->get_records_sql(str_replace(self::WINDOW_TOKEN, $wsql, $template), $wparams);
                foreach ($rows as $r) {
                    /* One descriptor per (cmid, userid), not per ledger row:
                     * stamp_allocation_for_user() already walks every row of the
                     * pair (every attempt, every cycle), so one descriptor per
                     * row would make k passes over the same k rows. */
                    $dedupkey = (int) $r->cmid . ':' . (int) $r->userid;
                    if (isset($seen[$dedupkey])) {
                        continue;
                    }
                    $seen[$dedupkey] = true;
                    $buffer[] = [
                        'cmid' => (int) $r->cmid,
                        'userid' => (int) $r->userid,
                        'courseid' => (int) $r->courseid,
                        // The moment of discovery; see stamp_allocations.
                        'when' => $now,
                    ];
                    if (count($buffer) >= self::REPAIR_CHUNK) {
                        $this->queue_stamps($buffer);
                        $buffer = [];
                    }
                }
                return count($rows);
            }
        );
        if (!empty($buffer)) {
            $this->queue_stamps($buffer);
        }
        /* No dirty_queue::enqueue() here: stamp_allocation_for_user() enqueues
         * the tuples it actually touched when the worker runs, and enqueuing
         * here would mark tuples dirty before, or without, that write. */
        return $acted;
    }

    /**
     * Queue one adhoc batch of allocation stamps.
     *
     * Dispatched without the dedup check that {@see self::queue_repair()} uses:
     * core compares custom data as a string and every batch here embeds its
     * own discovery instant, so no two payloads can match. The sweep's cursor
     * is what bounds re-dispatch.
     *
     * @param array $rows Row descriptors for stamp_allocations.
     * @return void
     */
    private function queue_stamps(array $rows): void {
        try {
            $task = new stamp_allocations();
            $task->set_custom_data(['rows' => $rows]);
            \core\task\manager::queue_adhoc_task($task);
        } catch (\Throwable $e) {
            debugging(sprintf(
                'reconcile_ledger: could not queue a stamp batch of %d row(s): %s',
                count($rows),
                $e->getMessage()
            ));
        }
    }

    /**
     * Turn one window's probe result into repair descriptors, queued in
     * batches of {@see self::REPAIR_CHUNK} as the buffer fills; the sweep
     * flushes the remainder with {@see self::flush_repairs()} once its walk is
     * over. Neither touches the cursor: that is the walk's business, and it is
     * derived from the window, never from what a probe returned.
     *
     * A team activity's ledger rows are per member while the repair is per
     * group: `upsert_for_cm_user_attempt()` re-routes any member of a team
     * activity through the whole-group fan-out, so one descriptor per member
     * would run the fan-out once per member, quadratic in group size and split
     * across parallel tasks writing the same rows. Team rows therefore collapse
     * to one `userid = 0` container descriptor per (cmid, team group,
     * attempt), the shape {@see backfill_one_submission} routes to
     * `upsert_for_team_attempt()`.
     *
     * @param array $rows Probe result for one window.
     * @return void
     */
    private function buffer_repairs(array $rows): void {
        foreach ($rows as $r) {
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
            if (isset($this->repairseen[$dedupkey])) {
                continue;
            }
            $this->repairseen[$dedupkey] = true;
            $this->repairbuffer[] = $descriptor;
            if (count($this->repairbuffer) >= self::REPAIR_CHUNK) {
                $this->queue_buffered_repairs();
            }
        }
    }

    /**
     * Queue the buffered descriptors as one adhoc batch.
     *
     * @return void
     */
    private function queue_buffered_repairs(): void {
        if (empty($this->repairbuffer)) {
            return;
        }
        $this->repairqueued += $this->queue_repair($this->repairbuffer) ? 1 : 0;
        $this->repairbatches++;
        $this->repairbuffer = [];
    }

    /**
     * Queue whatever the sweep in flight left in the buffer, report the batches
     * that were refused, and reset the buffer for the next sweep.
     *
     * @param string $key Sweep key, for the trace line.
     * @return void
     */
    private function flush_repairs(string $key): void {
        $this->queue_buffered_repairs();
        if ($this->repairbatches > $this->repairqueued) {
            mtrace(sprintf(
                'reconcile_ledger: %s had %d of %d repair batch(es) refused '
                . '(already pending, or blocked by a retry-exhausted row).',
                $key,
                $this->repairbatches - $this->repairqueued,
                $this->repairbatches
            ));
        }
        $this->repairbuffer = [];
        $this->repairseen = [];
        $this->repairbatches = 0;
        $this->repairqueued = 0;
    }

    /**
     * Queue one adhoc repair batch, logging rather than propagating failures.
     *
     * The return value of `queue_adhoc_task()` matters: `false` means nothing
     * was queued. That is the dedup working when an identical payload is
     * already pending, but Moodle 5.0 and later also return it up front for a
     * task whose component is deprecated. And before 5.1.5 and 5.2.1 (so on
     * 4.5, 5.0, 5.1.0 to 5.1.4 and 5.2.0) the dedup probe does not skip a
     * retry-exhausted {task_adhoc} row, so an identical payload stays blocked
     * while `task_adhoc_failed_retention` keeps the dead row (four weeks by
     * default). The caller counts the outcome instead of assuming the repair
     * is on its way.
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
     * Move a sweep's cursor on, or start its pass over.
     *
     * `$exhausted` means the driving set had no more rows to give: the pass is
     * complete, the cursor resets to 0 and the next tick starts a fresh pass.
     * {@see self::walk()} derives it from the size of the last window it
     * fetched (shorter than the batch, or empty) and from nothing else; a walk
     * stopped by its time share passes false and keeps its cursor.
     *
     * Never derive it from a probe result. A full window with nothing to
     * repair would read as "pass complete", wrap the cursor to 0, and the
     * sweep would rescan the head of the table for ever without reaching its
     * tail, indistinguishable from a converged ledger in every log.
     *
     * @param string $key Sweep key.
     * @param int $lastid Highest keyset value examined this pass.
     * @param bool $exhausted True when the driving set is spent.
     * @return void
     */
    private function advance_cursor(string $key, int $lastid, bool $exhausted): void {
        $this->exhausted[$key] = $exhausted;
        $this->set_cursor($key, $exhausted ? 0 : $lastid);
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
