# Reconciliation throughput plan — block_feedback_tracker

> **Status — 2026-09-25.** This file moved from the repository root to `docs/`, and
> its body below is unchanged. Checked against `git log`: **stages 0, 1 and 2 have
> landed**, as the 2026-08-12 addendum below lists. Stage 0 is pull request #23
> (`7e80781`, `17036fe`, `06f0c05`, `adf2761`, `9763156`, `15b34f4`, `2299041`).
> Stage 1 is #24 (`7ad0a56`, `def243a`, `19f25e3`, `aad73cb`). Stage 2 is #25 and #26
> (`a902882`, `f3092fd`, `b8e56df`), with 2.4 closed at the worker instead
> (`581179e`). Since then, `fa92b2a` (2026-09-03) replaced the
> filtered `LIMIT` of every sweep with a window over the driving table, which is 1.3
> in its structural form (the repo's `CLAUDE.md`, *Reconciliation sweeps*).
> **Stage 3 has not started.** There is no claim-and-generation unit table, and the
> structural gate in `tests/external/services_coverage_test.php` still reads only the
> scheduled tasks of `db/tasks.php`, although each of the six adhoc classes is now
> named by a test file in `tests/task/` (five have one of their own;
> `stamp_allocations` is exercised from `reconcile_ledger_test`), so the gate that
> section asks for would pass today. One claim of that addendum no longer holds: the
> `dirty_queue` adopt branch and both collision recoveries of the ledger writer,
> including the blind-update last resort, now run under PHPUnit in
> `tests/local/sla/concurrent_insert_test.php`, added on branch `audit-followups`.

Scope: does `reconcile_ledger` apply `drain_queue`'s parallelism model, what the
global picture looks like across every background surface, and a staged plan
where each stage ships alone and is provable.

Verified against the repo at `d20091d` (clean tree), Moodle core 4.5.13 / 5.1.6 /
5.2.2.

> **Status addendum (2026-08-12, verified against `19109c9`).** Stages 0–2 have
> landed since this document was written; every Stage-0 claim was re-verified
> against HEAD by independent review and none survives. Landed: 0.1 kill switch
> (`7e80781`, with non-vacuous control test), 0.2 live-`teamsubmission` routing
> in `sweep_orphans`/`sweep_latest_drift` (+ default-group regression tests),
> 0.3 collision re-entry (`15b34f4` — re-enters `build_and_store` once instead
> of extracting `derive_record`; same single-place-derivation property), 0.4
> team-descriptor collapse (`06f0c05`), 0.5 `queue_repair()` return counted +
> refusals mtraced (`adf2761`), 0.6 `dirty_queue::enqueue()` insert-then-adopt,
> 0.7 nine-sweep docs/strings (`9763156`), 1.1 own time cap (`7ad0a56`), 1.2
> per-tick telemetry (`REASON_RECONCILE` incl. `emptyms`), 1.3 exhausted-vs-
> truncated cursor contract, 1.4 index (`aad73cb`), 2.1 courseid cursor + many
> courses/tick (`a902882`), 2.2 rotation (`f3092fd`), 2.3 allocation dispatch
> (`b8e56df`), and R1/R2 closed at the WORKER (`participation` re-check at
> execute time in `backfill_one_submission` — stronger than the SELECT-side
> predicate §4/2.4 proposed, see its comment). The coverage holes (§2 tail,
> §5.4 fixture) are closed in `tests/task/reconcile_ledger_test.php`.
> Known accepted residues: the triple-writer blind-update last resort inside
> `insert_cycle_row` (documented in code), and the `dirty_queue` adopt branch
> not being executable in single-connection PHPUnit (hazard pinned instead).
> **Stage 3 remains not started, and per §7 it should stay that way until the
> §6 measurement protocol has run against the Stage-1.2 telemetry.**

---

## 1. Answer to the direct question

`reconcile_ledger` applies the pattern **for the repair half and not for the
detection half**, and the two halves have opposite cost profiles.

Six of nine sweeps dispatch repairs as adhoc `backfill_one_submission` batches
(`reconcile_ledger.php:229, :271, :321, :363, :485, :585`), which is the
`drain_queue` shape. But:

- **The nine sweeps run sequentially in one `execute()`** under one shared
  deadline (`:124-134`). The deadline is tested only *between* sweeps (`:125`),
  never inside one.
- **Three sweeps act inline**, not two as the class docblock claims (`:56-60`):
  `sweep_orphans` deletes at `:414`, `sweep_departed_participants` deletes at
  `:542`, and `sweep_unstamped_allocations` stamps at `:657` — the last of which
  runs the academic-time engine inside the task's own budget, for up to
  `reconcile_batch_size` (default 500) rows.
- **Sweep order is fixed** (`:112-122`), with no rotation. `rules` and
  `allocation` are structurally last and starve first, deterministically.
- **Cursors are nine global `set_config` rows** (`:741-754`). A single global
  value per sweep cannot be sharded, and `set_config` has no compare-and-swap.
- Moodle serialises scheduled tasks by classname cluster-wide
  (`lib/classes/task/manager.php`, `get_lock($record->classname, 0)` on the
  `cron` factory). `reconcile_ledger` is **one worker, permanently**.

### The premise that has to be corrected first

Both `drain_queue.php:36-40` and `backfill_one_submission.php:33-36` claim a
cluster of N workers gives ~Nx throughput. On a default site that is false.

`task_adhoc_concurrency_limit` defaults to **3**
(`admin/settings/server.php:388-393`), and core fair-shares candidate selection
as `floor($limit / (count($uniquetasksinqueue) + 1)) ?: 1`
(`lib/classes/task/manager.php:731-756`), ordering by fewest-running-first.

Consequence: with `recompute_one`, `backfill_one_submission` and any third adhoc
class in the queue, each gets **one slot**. Adding adhoc classes to buy
parallelism *reduces* the share of the classes already there — a fat pending
reconcile queue competes head-on with the `recompute_one` drain that the
reconciler itself feeds.

**So the dominant wins here are sequential-cost wins, not concurrency wins.**
Concurrency is stage 3, and it is bounded by a number the site admin owns.

---

## 2. Global check — every background surface

19 surfaces: 10 scheduled tasks (`db/tasks.php`, all `blocking = 0`), 4 adhoc
classes, 5 CLIs.

| Model | Surfaces |
|---|---|
| DISPATCHER | `drain_queue` → `recompute_one`; `backfill_history` → `backfill_one_submission` |
| HYBRID | `reconcile_ledger` (6 dispatch, 3 inline) |
| INLINE_SEQUENTIAL | `backfill_effectivedays`, `recompute_pending`, `recompute_trend`, `recompute_site_stats`, `purge_calendar_cache`, `prune_ledger`, `prune_audit_log` |
| WORKER | `recompute_one`, `backfill_one_submission`, `bulk_remove_blocks`, `discard_course_data` |

Ranked by throughput damage on a 5,000-course site:

1. **`sweep_departed_participants` — one course per tick.** The cursor is a
   positional index into the `processable_course_ids()` array (`:507-513`), and
   `db/tasks.php` schedules `minute 15, hour */2` = 12 ticks/day. Full pass =
   N ticks = **2N hours**. N=5,000 → ~417 days. And the index is *unstable*:
   adding the block to a course with a lower id shifts every later position, so
   a course is silently skipped for a whole cycle. Worse, a course with more
   than `reconcile_batch_size` departed rows sheds only 500 per visit.
2. **`reconcile_ledger` steady state is O(table), not O(batch).** All three
   cursor writers use `count($rows) < $batch ? 0 : $lastid` (`:418, :670, :711`).
   The batch caps the *result set*, not the rows examined. A converged ledger —
   nothing to repair — is the **most expensive** case: the engine walks the whole
   PK range to prove fewer than 500 rows match, then throws the position away.
   Every tick. Forever. Plus `sweep_orphans`, which has no course filter at all.
3. **`backfill_history`** — per-course cap is 1/5 of the tick cap, so ~5
   courses/tick; and it issues one point `SELECT` per processable course for
   lazy cursor creation (`:103-105`) *before* its own all-complete early-out.
4. **Calendar-save fan-out** — `enqueue_all_groups` walks every rollup row
   synchronously in a web request; can outproduce `drain_queue`'s ceiling.
5. **`recompute_pending`** — per-row cost is linear in days-pending (the
   academic-time day loop), i.e. quadratic in backlog age.
6. **`recompute_trend` / `recompute_site_stats`** — no batch, no cap, no cursor.
   `site_stats_service.php:48-55` materialises a whole day of gradings with
   `get_records_select` where `trend_service.php:111-117` already streams the
   analogous set with `get_recordset_sql`.

The plugin already owns the right primitives and does not reuse them:
`block_feedback_tracker_bfcursor` (`db/install.xml:249-266`) is a proper
per-course cursor table with a unique key, an `active` latch and the MariaDB
reserved-word workaround (`lastsubid`, because `CURSOR` is reserved); and
`backfill_history.php:124-141` already implements round-robin rotation via
`bfdispatch_last_courseid`. Only `backfill_history` uses either.

---

## 3. What breaks if you only add parallelism

Four correctness problems that are dormant *because* the reconciler is slow, and
become routine the moment it is not.

**R1 — The lock guards detection, not the writer.** Any course-level lock covers
only the three inline actors. Every row-creating and row-repairing write travels
through `backfill_one_submission::execute()` (`:67-93`), which takes no lock and
runs on an arbitrary adhoc runner. `build_and_store` is an unlocked
read-derive-write-whole-row, so a writer whose snapshot predates a grading resets
`timegraded` to NULL.

**R2 — The four ledger-rooted dispatching sweeps carry no enrolment predicate.**
Commit `1cd08f9` aligned only `sweep_missing_rows` with
`sweep_departed_participants`. `gradestate`, `gradebook`, `latest` and `rules`
dispatch asynchronously, and `backfill_one_submission` re-gates only on
`course_access::is_processable()` — never on enrolment. A repair dispatched
before a delete lands after it and recreates the departed student's row. Today
`participant` reaches one course per 2h, so the pair effectively never meets.
Speed it up and the oscillation becomes routine on every course.

**R3 — `sweep_unstamped_allocations` runs inline, concurrently with the repairs
the same tick just dispatched.** A concurrent `build_and_store` whose `$existing`
snapshot predates the stamp writes NULL over `queuehours`/`allochours`/
`allocdays`/`allocbucket` while `timeallocated` survives (it is not in
`$record`) — producing a permanently unrecoverable row: allocated, no queue
measurement, and invisible to the sweep that would fix it.

**R4 — Team descriptors amplify quadratically.** `dispatch_and_advance`
(`:694-702`) emits one descriptor per team **member**, and
`upsert_for_cm_user_attempt` re-routes each member back through the whole-group
fan-out. An N-member team produces N full fan-outs, split across parallel adhoc
tasks that then all write every member's row. `observer.php:411-419` names this
exact hazard in prose and routes around it; the reconciler does not.

---

## 4. Staged plan

Each stage ships alone, is green in CI on its own, and de-risks the next.
**Stages 0-2 raise throughput with no concurrency at all.**

---

### Stage 0 — Standalone defects (no schema, no version-gated behaviour)

| # | Change | File |
|---|---|---|
| 0.1 | `reconcile_active` kill switch is dead. `(int) (get_config(...) ?: 1) !== 1` — a checkbox stores `'0'` for off, `'0'` is falsy, so `'0' ?: 1` yields 1 and the guard never fires. Read it so only a literal `'0'` means off (the pattern `retention.php:62` already uses correctly). | `reconcile_ledger.php:90` |
| 0.2 | `sweep_orphans` and `sweep_latest_drift` discriminate individual-vs-team on the **stored** `teamgroupid = 0`, but mod_assign's default team group *is* group 0. Route on the live `a.teamsubmission` flag, as the observer does. This is a running delete/resurrect loop today: `sweep_orphans` deletes the member row, `sweep_missing_team_rows` recreates it next tick, costing a dispatch plus a rollup recompute per round trip. | `reconcile_ledger.php:390-391, :347-348` |
| 0.3 | `insert_cycle_row`'s unique-key recovery re-reads only the colliding row's `id` and calls `update_record` with a `$record` built on the `$existing === null` branch — so the sticky-restore that reinstates a stored gradebook-sourced `closedsource`/`timeclosed` never ran. Extract `derive_record(array $ctx, ?stdClass $existing)` and re-enter it with the freshly-read row. | `submission_ledger.php:866-882` |
| 0.4 | Collapse team descriptors to one `userid => 0` row per `(cmid, teamgroupid, attemptnumber)` in `dispatch_and_advance`. Removes the N² amplification in R4. Pure throughput win, no concurrency involved. | `reconcile_ledger.php:694-702` |
| 0.5 | `queue_repair()` ignores `queue_adhoc_task()`'s return. On 5.2 a `false` return no longer implies "already queued" (a deprecated component returns false up front). Check it, and log a refusal. | `reconcile_ledger.php:721-733` |
| 0.6 | `dirty_queue::enqueue()` is an unguarded check-then-insert against a UNIQUE index and is the last statement of every write path. Under raised concurrency it becomes a routine `dml_write_exception` escaping *after* the ledger row is committed — failing the task and, on 4.5, tombstoning that exact 50-row payload (see 4.5 note below). Make it insert-then-catch-and-update. | `dirty_queue.php:60-82` |
| 0.7 | Docblock says two sweeps act inline; three do. Lang string `settings_reconcile_*` says "Seven sweeps run each tick"; there are nine. | `reconcile_ledger.php:56-60`, `lang/en` + `lang/pt_br` |

**Moodle 4.5 constraint that must survive into every later stage.** On 4.5,
`task_is_scheduled()` → `get_queued_adhoc_task_record()` has no
`attemptsavailable` filter, so a retry-exhausted adhoc row **blocks re-queueing
of identical custom_data** for up to `task_adhoc_failed_retention` (4 weeks).
5.1.5+/5.2 pass `$includefailed = false` and exclude them. Therefore: any adhoc
task this plugin queues on the 4.5 leg must be structurally unable to throw
(per-row catch, cursor advances regardless), or a single dead payload silently
freezes that work for a month.

**Validation, stage 0**

| Change | Proof |
|---|---|
| 0.1 | Non-vacuous: run with the setting **unset**, assert `{task_adhoc}` count **grew** (the control — proves the mechanism was on); then set `'0'`, re-run, assert it did **not** grow. Asserting "0 ledger rows" is vacuous — six sweeps write no ledger rows at all, they queue adhoc tasks. |
| 0.2 | New test: team activity using the **default group (0)**, one member row seeded. Run the reconciler twice; assert the member row survives both, and assert `{task_adhoc}` did not grow on the second run (convergence idiom, `reconcile_ledger_test.php:414-420`). Without the fix this test fails on run 1. |
| 0.3 | Seed a row closed by a gradebook grade (`closedsource = 'gradebook'`), force the collision path, assert `closedsource` and `timeclosed` survive. |
| 0.4 | 3-member team, one missing row. Assert exactly **one** descriptor with `userid = 0` is dispatched, not three. |
| 0.6 | Two `enqueue()` calls for the same tuple; assert one row and no exception. |
| all | `mdl phpunit m501 block_feedback_tracker` · `mdl ci moodle-block_feedback_tracker --only phpcs,phpdoc` · `mdl ci moodle-block_feedback_tracker --branch MOODLE_405_STABLE` |

Also close two coverage holes this stage exposes: `sweep_missing_team_rows` and
`sweep_rule_drift` have **no test anywhere in the repo**.

---

### Stage 1 — Make the cost visible, then make convergence free

| # | Change |
|---|---|
| 1.1 | Split `reconcile_time_cap_seconds` out of `drain_time_cap_seconds`. Today `reconcile_ledger.php:104`, `drain_queue.php:76` and `backfill_history.php:95` read one key (`settings.php:276`): nine table scans and a 200-row insert loop cannot share a knob. Behaviour change for tuned sites — `CHANGELOG.md` entry required. |
| 1.2 | Per-sweep telemetry via `recompute_log::record()` with a new `REASON_RECONCILE`: rows examined, rows repaired, elapsed ms, cursor position, and **`emptyms`** — the cost of proving nothing was wrong. Reconciliation emits nothing comparable to `drain_queue.php:116-129` today. This is the instrument the whole plan is measured with, so it ships **before** any structural change. |
| 1.3 | Separate *"the driving set is exhausted"* from *"I ran out of batch/time"*. Only the first may reset the cursor to 0. Today `count($rows) < $batch ? 0 : $lastid` conflates them, which is why a converged site rescans every driving table on every tick. This is the single largest steady-state win and it lands at any concurrency. |
| 1.4 | Add index `(courseid, id)` on `block_feedback_tracker_sub`. No index currently leads with `id` (`db/install.xml:47-55`), so every keyset sweep is a PK range scan plus an `IN`-list residual. **Caveat:** this serves the seven ledger-rooted sweeps but **not** `sweep_missing_rows` / `sweep_missing_team_rows`, which drive from `{assign_submission}` — a table with no course column on either leg. Do not claim otherwise. |

**Validation, stage 1**

- 1.3 needs a discriminating test: seed fewer rows than the batch, run twice,
  assert the cursor did **not** reset (today it does), plus a control proving the
  sweep actually executed (`emptyms > 0` in the audit row).
- 1.4: `xmllint --noout --schema ~/dev/moodle-501/public/lib/xmldb/xmldb.xsd db/install.xml`,
  a `db/upgrade.php` step ending in `upgrade_block_savepoint(true, <version>, 'feedback_tracker')`
  **with a matching `version.php` bump** and the same `VERSION` in `install.xml`.
- **After any schema change: `mdl phpunit-init m501` before `mdl phpunit`,**
  or the run aborts on a stale versions hash.
- `mdl ci moodle-block_feedback_tracker --db mariadb` — stage 1 touches SQL.

---

### Stage 2 — Kill the two coverage pathologies (still no concurrency)

| # | Change |
|---|---|
| 2.1 | Replace the positional course cursor in `sweep_departed_participants` (`:507-513`) with a **keyset on `courseid`**, and visit as many courses per tick as the budget allows instead of exactly one. ~15 lines; fixes both the 2N-hour pass and the silent-skip instability. |
| 2.2 | Rotate the sweep order per tick, using the round-robin `backfill_history.php:124-141` already implements. Ends the deterministic starvation of `rules` and `allocation`. |
| 2.3 | Stop `sweep_unstamped_allocations` acting inline — dispatch it like the other six, so the academic-time engine leaves the scheduled task's budget. Removes R3. |
| 2.4 | Give the four ledger-rooted dispatching sweeps (`gradestate`, `gradebook`, `latest`, `rules`) the same enrolment predicate `sweep_missing_rows` got in `1cd08f9`, **including the SITEID exemption** — `get_enrolled_join()` skips the enrolment join entirely on the front page, and an inlined predicate that demands a `{user_enrolments}` row would silently stop repairing every front-page activity. Removes R2. |

**Validation, stage 2**

- 2.1 needs the test that does not exist today:
  `test_no_course_is_skipped_when_a_block_is_added_mid_pass` — start a pass, add
  the block to a course with a *lower* id, finish the pass, assert every course
  was visited exactly once.
- 2.4 needs a control: seed a row for an unenrolled student **and** a row for an
  enrolled one; assert the first is deleted and stays deleted across two ticks,
  and that the second survives. The second row is the control — without it the
  test passes when the sweep does nothing at all.
- **Coverage gap this stage must close first:** every existing test in
  `tests/task/reconcile_ledger_test.php` uses exactly **one course**. Rewriting
  the course predicate has zero coverage — a sweep that binds the wrong courseid,
  or drops the term, passes the entire suite. Add a two-course fixture before
  touching the predicates.

---

### Stage 3 — Sharding (only after 0-2 have landed and 1.2 shows the numbers)

Course is the correct shard axis, and not by preference: `get_enrolled_sql()` and
`context_course::instance()` are course-scoped by construction (`:516-520`), so
`sweep_departed_participants` **cannot** be expressed as an id-range shard; and
every acting sweep must enqueue a `(courseid, groupid)` dirty tuple
(`:415-417, :543-545`), so the course dimension reappears at the end regardless.

Preconditions, each of which was a blocker in review:

- **Unit table with claim + generation.** Copy the `bfcursor` shape
  (`db/install.xml:249-266`), and add a generation counter so `advance()` is a
  compare-and-swap. Core gives adhoc tasks **no lease** — a claimed row is
  protected only by the per-row `adhoc_<id>` advisory lock, and crash recovery is
  the hourly `task_lock_cleanup_task`, which ignores anything started under an
  hour ago. A worker really can outlive its claim and write a stale position over
  a newer generation. Without CAS this reproduces the exact defect that
  disqualifies `set_config` cursors.
- **Lock the writer, not the detector.** `ledger_{cmid}_{userid}_{attemptnumber}`
  around `build_and_store` and `stamp_allocation_for_user`, or an optimistic
  `timemodified` predicate. A course-level lock is not sufficient — see R1.
- **Back-pressure must count running tasks.** Any governor keyed on
  `timestarted IS NULL` is blind to everything actually executing: core sets
  `timestarted` when it claims a row, and `get_candidate_adhoc_tasks` selects
  only unstarted rows. `dirty_queue::size()` is not a second signal either — it
  only grows *after* a shard has run.
- **Do not add many adhoc classes.** Core's fair share is
  `floor($limit / (classes + 1)) ?: 1` against a default limit of 3. Three worker
  classes at most; nine would dilute the pool to nothing.
- **`db/tasks.php` cadence changes need a `version.php` bump.**
  `reset_scheduled_tasks_for_component()` is called only from
  `upgrade_component_updated()`. A schedule change with no version bump never
  installs. And it **skips any task whose stored record the admin has
  customised** — so a site that ever edited this schedule keeps the old cadence
  and silently reverts the throughput model. Say so in `CHANGELOG.md`.
- **Read `retention::cutoff()` at worker execute time**, not at dispatch time, so
  the pruner and the reconciler cannot observe different cutoffs across a
  settings change.
- **Flush the memos in the worker.** `reconcile_ledger.php:107-110` calls
  `submission_ledger::reset_memos()` and `group_resolver::reset_memo()` per tick
  because "a long-lived cron process would otherwise carry one tick's decisions
  into the next". Core runs adhoc tasks back-to-back in one PHP process with no
  plugin-static reset between them, and `group_resolver::$memo` is keyed only by
  `courseid:userid` and never versioned — a stale `groupid` is the rollup's
  partition key.

Also extend the structural gate: `tests/external/services_coverage_test.php:174-192`
requires every classname in `db/tasks.php` to be named by some
`tests/task/*_test.php`. Adhoc classes are not in `db/tasks.php`, so
`backfill_one_submission`, `recompute_one`, `bulk_remove_blocks` and
`discard_course_data` are gated by **nothing** today. Glob `classes/task/*.php`
for `extends \core\task\adhoc_task`. Make the gate fail when the glob matches
zero files, or the gate is itself vacuous.

---

## 5. Test traps specific to this repo

Found while auditing the validation story; each of these makes a test pass while
proving nothing.

1. **Five tests in `reconcile_ledger_test.php` are "run, run again, assert
   nothing more happened".** Any design that marks a converged unit `done` and
   skips it turns those green results from *"converged"* into *"skipped"*. If
   stage 1.3 or stage 3 introduces unit state, those five tests need a control
   proving the second run actually executed.
2. **`set_config(<cap>, 0)` does not work anywhere in this plugin.** Every config
   read uses the `?:` idiom, under which a stored `'0'` is falsy and silently
   becomes the default. Time-cap and batch-size tests must use a different lever.
3. **`assertX_absent` without a control is forbidden here** (repo CLAUDE.md).
   Every "the sweep did not touch this row" assertion needs a sibling row the
   sweep *must* touch, asserted changed.
4. **`seed_scale_fixture()` (`tests/generator/lib.php:486-515`) has zero callers
   and is not usable as-is for reconciler scale tests**: its `cmid`/`iteminstance`
   come from a static counter with no `{course_modules}` and no
   `{assign_submission}` row, and its `userid` is a fabricated id with no
   enrolment — so every seeded row is simultaneously an orphan *and* a departed
   participant, and the sweeps annihilate the fixture. Fix the generator or build
   the fixture on `create_tracked_course()` + `create_graded_submission()`.
5. **`insert_records()` throws unless every row has byte-identical key sets in
   identical order.** Feeding it the result of `array_diff()` (which preserves
   keys), or omitting a conditionally-null column, aborts the whole call on both
   CI drivers.
6. **Behat covers none of this** — no scenario runs cron or a task. Do not let
   `mdl ci --behat` imply a wider net than exists.

---

## 6. Measurement protocol

Without this, "it got faster" is unfalsifiable. Stage 1.2 exists to make this
runnable.

**Before any structural change**, capture from the audit log over 24h:

- per sweep: ticks reached, rows examined, rows repaired, `emptyms`
- `sweep_departed_participants`: courses visited / total processable courses
- `{task_adhoc}` depth by classname, sampled every 5 min
- `dirty_queue::size()` sampled every 5 min
- `reconcile_ledger` task duration from `{task_scheduled}`

**Acceptance thresholds:**

| Metric | Target |
|---|---|
| Sweeps reached per tick | 9 of 9 (today: starves at 5-6 on a large site) |
| Full `participant` pass | ≤ 24h (today: 2N hours) |
| `emptyms` on a converged site | falls by ≥ 1 order of magnitude after 1.3 |
| Team fan-out queries per team repair | N → 1 after 0.4 |
| `{task_adhoc}` depth for `recompute_one` | must not rise after stage 3 — if it does, fair-share displacement is happening |

That last row is the one that decides whether stage 3 was worth shipping.

---

## 7. Ordering rationale

Stage 0 first because 0.2 and 0.3 are **live defects today**, unrelated to
parallelism, and 0.3 plus 0.4 are the two things that make raised concurrency
survivable. Stage 1 second because you cannot prove stage 2 or 3 helped without
1.2, and 1.3 is the biggest single win in the document. Stage 2 third because it
removes the two pathologies that dominate the numbers *and* removes R2 and R3,
which are the preconditions for stage 3 being safe. Stage 3 last, and optional —
after 0-2, re-read the stage-1.2 numbers before deciding whether the fair-share
ceiling leaves anything on the table worth the unit table.
