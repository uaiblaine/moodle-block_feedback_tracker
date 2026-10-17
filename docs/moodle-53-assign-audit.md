# Moodle 5.3 audit of the assign integration, and the 5.3 plan

Audit of 2026-10-17 on `MOODLE_503_DEV` (tree identical to `main` since #72, still
carrying 5.2 values: `supported = [502, 502]`, version `2026042003`). It compares
core **5.2.4** (`~/dev/moodle-502`, `2df605b1e09`) with **5.3** final
(`~/dev/moodle-503`, `42622298fe0`, build 20261005) over everything under
`public/mod/assign`, then reads the plugin's observers, ledger writer, reconciler,
tests and docs against the result. Core line numbers are 5.3 and will drift; the
quoted names let a claim be found again.

Sources outside the code: the 5.3 release notes (MDL-86007 marking workflows phase 2,
MDL-87709 phase 3, MDL-89346 per-marker feedback over WS, MDL-86513 override web
services), `docs.moodle.org/503` "Using multiple markers" and "Assignment settings".

This file supersedes the 5.3 parts of `moodle-52-multimarking.md`, which was written
against a 5.3-dev checkout of August 2026 and is wrong about 5.3 final in several
places (section 3).

## 1. What 5.3 changed in mod_assign

### Events

| Event | Status | Fired from | Payload |
|---|---|---|---|
| `marker_updated` | **no longer triggered** (`is_deprecated()` true) | only the deprecated `assign::update_allocated_markers()`, which no core path calls | unchanged |
| `marker_added` | new | `update_allocated_marker()` (private, behind `update_marker_allocations()`) | `objectid` = `assign_allocated_marker.id`, `relateduserid` = student, `other.markerid` |
| `marker_removed` | new | same | same; fired for the previous marker of a slot |
| `marker_enabled_updated` | new | same, when an optional slot is ticked or unticked | `objectid` = allocation row, `other.markernumber` (position), `other.enabled` |
| `submission_marked` | new | `update_mark()`, when a marker's mark or own workflow state changes | `objectid` = `assign_mark.id`, `relateduserid` = student, `other.mark`, `other.draft` (true unless the marker's state is `readyforreview`, "Marking completed") |
| `agreed_grade_calculated` | new | `update_agreed_grade()`, after `update_grade()` succeeds | `objectid` = `assign_grades.id`, `other.method`, `other.markerids` |

UPGRADING.md states it outright: "Event mod_assign\event\marker_updated is no longer
triggered. Observers listening to this event should instead listen to marker_added and
marker_removed." A deprecated event emits no `debugging()` when triggered
(`core\event\base::is_deprecated()` only changes the display name), so nothing warns.

Unchanged and still relevant: `submission_graded` (from `update_grade()`, so it also
fires for an agreed grade and for a **cleared** one, grade -1), `workflow_state_updated`
(four sites, the aggregate one in `calculate_and_save_overall_workflow_state()`), and the
six override events, which `override_manager` now fires for the UI and the three new web
services alike. Reordering group overrides (`move_group_override()`,
`reorder_group_overrides()`) still fires nothing, as on 5.2.

### Schema

- `{assign}.optionalmarkercount` (5.3); `markercount`, `multimarkmethod`,
  `multimarkrounding` were already 5.2.
- `{assign_allocated_marker}` gains `optional` (0/1) and `enabled` (nullable). Still no
  timestamp and no position column: a position is derived by `get_marker_allocations()`
  from `ORDER BY optional, id`, with optional rows starting after `markercount`.
- Placeholder rows exist: an optional slot can be enabled with no marker yet
  (`marker = 0`, `enabled = 1`); `get_marker_allocations($id, false)` filters them.
- Per-marker feedback: `assignfeedback_comments.mark`, `assignfeedback_file.mark`,
  `assignfeedback_editpdf_*.markid` reference `{assign_mark}`.
- `{assign_mark}` itself is unchanged from 5.2 (`timecreated`, `timemodified`, `marker`,
  `mark`, `workflowstate`).

### Behaviour

- **Agreed grade needs every expected marker at "Marking completed" with a mark**
  (`all_markers_marked()` over `expected_marker_count()` = required + enabled optional).
  Methods offered by the form are `manual`, `maximum`, `average`; `first` is still defined
  and still absent from the switch, but it is no longer reachable from the form.
- **Agreed grades can be cleared**, by `clear_agreed_grade()` (grade -1, grader -1,
  through `update_grade()`, so `submission_graded` fires):
  - when an optional marker is enabled on a graded student (allowed until
    `readyforrelease`);
  - when a marker edits a mark and the grade can no longer be determined
    (`should_clear_stale_agreed_grade()`);
  - **in bulk from the assignment settings**, "Update agreed grades" = Clear, or
    Recalculate under the `manual` method (`recalculate_agreed_grades()` →
    `clear_all_agreed_grades()`). This path has **no workflow-state check**: it also
    clears students already `released`.
- `calculate_and_save_overall_workflow_state()` leaves states outside
  notmarked/inmarking/readyforreview alone, so a released student stays `released` when
  the grade under it is cleared.
- Deprecated: `get_allocated_markers()` / `update_allocated_markers()` (use
  `get_marker_allocations()` / `update_marker_allocations()`),
  `ASSIGN_MULTIMARKING_MAX_MARKERS`, the four override functions moved to
  `override_manager`.
- `mod_assign_get_submission_status` returns `feedback.markerfeedback`; three override web
  services exist. Neither touches the plugin.
- Grader filter preferences (`assign_filter`, `assign_workflowfilter`,
  `assign_markerfilter`) and the filter constants are unchanged, so
  `local\grading\grader_filters` needs nothing.

## 2. Findings

Severity is about what the plugin reports on a 5.3 site, not about code style.

### F1 — Blocker: the allocation observer is dead on 5.3

`db/events.php` subscribes `observer::marker_changed` to `marker_updated` only. On 5.3
no allocation path fires it, so `timeallocated` / `timeallocmarker` are written only by
the `allocation` sweep of `reconcile_ledger` with `ALLOC_SOURCE_RECONCILED` (accurate to
the sweep period). `allocsource = observed` disappears from 5.3 sites, `queuehours` and
`allochours` lose their precision, and the coverage figure in `rollup_service`
(`alloc_coverage_pct`) silently reports a different population.

It is invisible to the suite: `tests/local/sla/observer_coverage_test.php:220` writes the
allocation through the fixture and then **triggers `marker_updated` by hand**, so it stays
green on 5.3 while production never sends the event. It asserts the convenient fact (the
observer reacts to the event) rather than the promised one (an allocation made through
core is stamped as observed).

Fix: subscribe `marker_added`, `marker_removed` and `marker_enabled_updated` to the same
observer, which already re-reads core rather than trusting the payload. Keep the
`marker_updated` line. `core\event\manager` registers an observer by class name without
`class_exists()`, so the three lines are harmless on 4.5/5.1/5.2 and `db/events.php` can
stay identical on every branch.

### F2 — High: `MIN(marker)` makes the marker clock reset when a marker is added

`submission_ledger::allocated_marker_id()` picks `MIN(am.marker)` (a user id). Adding a
second marker whose user id is lower than the first's changes `allocmarkerid`, and
`stamp_allocation_for_user()` then treats it as a reassignment: `timeallocmarker` moves,
`allochours` / `allocdays` / `allocbucket` are nulled. On 5.3 every enabled optional
marker can do this. The same holds on 5.2 with two required markers. "Lowest user id" has
no meaning for the teacher. Core's own order (`optional ASC, id ASC`, position 1 first)
is the one the Submissions page shows.

### F3 — High (5.3 only): bulk "Update agreed grades" reopens released cycles

Clear, or Recalculate under `manual`, sets grade -1 on every latest-attempt grade,
including students whose grade was released and whose cycle the plugin closed. The writer
re-derives from the live row: `grading_state::resolve()` sees no real mark, `isclosed`
becomes false, and an activity-sourced `timeclosed` / `timegraded` is withdrawable by
design ("clearing a mark in the activity means not answered yet",
`submission_ledger::build_and_store()`). The class goes back to pending with its response
time lost, although every student read the feedback. Whether that is right is a product
decision (D2). Today no test pins either answer.

The per-student paths (optional marker enabled, stale clear after a mark edit) cannot
reach `released` (`marker_allocation_locked_states()`), so they only flip "marked,
awaiting release" back to pending, which is correct and also untested.

### F4 — Medium: multi-marker progress is invisible

Between the first mark and the agreed grade, the plugin shows a pending submission with
workflow `inmarking`. It cannot say "1 of 2 markers done" or, under `manual`, "all marks
in, waiting for the agreed grade". Under `manual`, that last wait belongs to the teacher
in charge, not the markers, and it is the longest wait in that workflow. The facts are in
`{assign_mark}` (5.2+) and, on 5.3, are announced by `submission_marked`.

### F5 — Medium: no per-marker figures

`allocmarkerid` holds one id. With N markers, per-marker turnaround (allocated →
"Marking completed") is not measured. 5.3 gives what was missing: `marker_added` dates
each slot exactly, and `submission_marked` with `draft = false` dates each completion
(`assign_mark.timemodified` is the reconcilable copy). This is phases 4 and 5 of
`assign-marking-allocation.md`, which ask for an explicit product decision before
shipping (D3).

### F6 — Low: per-marker feedback and the new web services

Per-marker feedback goes through `update_mark()`, not `update_grade()`, so it fires
`submission_marked`, never `submission_graded`. The plugin is unaffected, because a
response is the agreed or manual grade plus release. The override web services fire the
same six override events through `override_manager`, so `override_changed` /
`user_rule_changed` already cover them. A test that drives `override_manager` directly
would pin it on 5.3.

### F7 — Docs and comments that are wrong for 5.3

| Where | Claim | 5.3 final |
|---|---|---|
| `moodle-52-multimarking.md` §1, §7; `assign-scenario-map.md:136`, `:1463`; CHANGELOG | "No new event classes", "`db/events.php` needs no new subscriptions for 5.3" | Five new events; `marker_updated` retired |
| `moodle-52-multimarking.md` §4; `reconcile_ledger.php:1047` | "De-allocation still fires nothing" | True on 5.2; on 5.3 `marker_removed` fires |
| `submission_ledger.php:679-682`; `moodle-52-multimarking.md` §4 | "5.2+ fires one event per marker in insertion order" | 5.2 only; 5.3 fires per slot change |
| `observer.php:463-470`; `db/events.php:89-96`; `rollup_service.php:250-254` | `marker_updated` "fires from every allocation path" from 5.2 | 5.2 only |
| `moodle-52-multimarking.md` §3 | "`first` behaves exactly like `manual`" | Still unwired, and no longer offered by the form |
| `moodle-52-multimarking.md` §3 item 1 | partial marking "emits nothing" | Emits `submission_marked` on 5.3; still no `submission_graded` |
| `db/install.xml:32` (`allocsource` comment) | "no reconciliation sweep covers this yet" | The `allocation` sweep exists; also stale on 5.2 |
| `CLAUDE.md:13` | `MOODLE_503_DEV` "awaits a 5.3 compatibility review" | This file is that review |
| `CLAUDE.md` "Data reset and privacy" | keeps the marker "as core keeps `assign_user_flags.allocatedmarker`" | That column is gone since 5.2 |
| `assign-marking-allocation.md` header, `assign-scenario-map.md:3` | `supported = [405, 502]`, "5.3-dev" | Stale on every branch |

Still true on 5.3 and worth keeping: `{assign_allocated_marker}` has no timestamp; the
workflow observer must mirror the live `{assign_user_flags}.workflowstate` and treat
`other.newstate` as a hint; the agreed grade covers the latest attempt only (core says so
in `calculate_and_save_agreed_grade()`).

Carried over from `assign-marking-allocation.md` and not re-verified in this audit: the
grading form never updates allocations for team submissions, and `set_user_flags` accepts
`allocatedmarker` and discards it. Re-check them in P1.

## 3. Owner decisions (2026-10-17)

### D1 — The responsible marker follows "Calculate grade"

Owner's rule: under `manual` the responsible marker is whoever confirms the final grade;
under `maximum`, the marker who gave the highest mark; under `average`, the marker in
position 1. The rule below is that, made precise enough to implement. It applies only to
an activity using multiple marking (`markingworkflow`, `markingallocation` and more than
one marker slot, as `assign::is_using_multiple_marking()`). A single-marker activity keeps
today's behaviour.

- **While the cycle is pending**, nobody has earned the attribution yet. `allocmarkerid`
  holds the **provisional** marker: the marker in position 1, in core's order
  (`ORDER BY optional, id`, placeholders and disabled optional slots skipped, as
  `get_marker_allocations($studentid, false)`). Adding a second marker never moves it,
  which closes F2.
- **When the cycle first gets its agreed grade** (the first time `markbelongs` is true),
  the responsible marker is resolved once and stored:
  - `manual`: `assign_grades.grader` at that moment, the user who saved the final grade
    (`apply_grade_to_user()` sets it to `$USER`). The value is read at closure because a
    later feedback-only save overwrites it. A grader of 0 or less (a restore maps an
    unknown grader to 0) falls back to position 1, with a source value that says so.
  - `maximum`: the marker of the highest completed mark among the active slots
    (`assign_mark` with `workflowstate = readyforreview` and `mark >= 0`, markers as
    `get_marks()` selects them). A tie goes to the lowest position. Core cannot give this:
    the agreed grade carries `grader = -1`, and `agreed_grade_calculated.other.markerids`
    lists every marker, not the winner. It is therefore derived from `{assign_mark}` at
    that moment and stored.
  - `average`, and `first` (defined, but no longer offered by the form): position 1.
- **Sticky, like `timegraded`.** A later mark edit, a recalculation or a removed marker
  does not re-attribute a cycle that already responded. Re-deriving would read the marks
  of the markers allocated *now* (`get_marks()` filters on the current allocation), not the
  ones that produced the grade.
- **The clock moves with the attribution, without the reassignment reset.**
  `timeallocmarker` becomes the instant responsibility landed on that person:
  - for `maximum` and `average`, that marker's own slot allocation instant, read from the
    per-slot table (P3);
  - for `manual`, the instant the last expected mark reached "Marking completed", since
    the decision only fell to the confirmer then. The confirmer is often not an allocated
    marker at all.

  The current `stamp_allocation_for_user()` treats any change of `allocmarkerid` as a
  reassignment and nulls `allochours`. The closure path must set the pair itself.
- **Row level versus marker level.** The row's `allochours` remains "responsible person:
  responsibility → response". Each marker's own turnaround (allocation → own "Marking
  completed") lives in the per-slot table (D3), so the slower colleague under `maximum` is
  not hidden.

### D2 — A released response stays recorded

Recommendation accepted. When the stored cycle was closed by a release (`timereleased`
set) and the live workflow state is still `released`, a grade that becomes -1 under it
(settings "Clear", or Recalculate under `manual`) does not reopen the cycle. This is the
stickiness the gradebook closure already has. A new submission still opens a new cycle as
usual.

### D3 — Per-marker attribution, with core's completion rule for "pending for me"

Accepted, with the owner's point: once the marks the submission needs are complete,
nobody else may see it as theirs to mark. Today every teacher sees every pending
submission of their courses, whatever the allocation.

- A submission is **pending for marker M** when:
  - M holds an **active slot** for the student: a required slot, or an optional slot with
    `enabled = 1`;
  - M's own mark on the latest attempt is not complete (`readyforreview` with a mark,
    `mark_is_marked()`);
  - the completed marks are still fewer than the expected ones (required plus enabled
    optional, `expected_marker_count()` / `all_markers_marked()`).

  Anyone else, a teacher with no slot or a marker whose own mark is done, does not see it
  as pending for them.
- With the owner's example (2 required markers, 3 teachers marking in the course): the two
  allocated markers complete their marks, and the third teacher holds no active slot, so it
  is never pending for them. If the third teacher held an **enabled optional** slot, core
  would wait for their mark too (`expected_marker_count()` counts it), and it would stay
  pending for them, mirroring core. Disabling the slot ends that wait in both places.
- The **course-level** pending figures (block, dashboard, rollup, score) do not change: a
  submission stays pending for the course until its agreed grade, and under marking
  workflow until release. "Pending for me" is a filter over the marker views (Grade Now,
  pending report, drill-down), not a second definition of pending.
- Two waits belong to no marker, and the views say so instead of hiding them:
  - "awaiting allocation": a required slot with no marker, which is the coordinator's
    queue;
  - "awaiting agreed grade": `manual` with every expected mark complete, which waits for
    the confirmer.

  Both stay visible at course level. A marker's own view does not list them.
- Per-marker figures about **other** markers (medians, coverage) stay gated on
  `mod/assign:manageallocations`, as `assign-marking-allocation.md` phase 4 proposed. A
  marker always sees their own queue.

### D4 — Show multi-marker progress

Recommendation accepted: "k of n markers" and "awaiting agreed grade" appear as a
secondary line in the pending report and the drill-down. The headline counts stay as
they are.

## 4. Implementation plan for Moodle 5.3

Each phase is one pull request against `MOODLE_503_DEV`, with a version bump in the 5.3
range (`20261005XX`) and `CHANGELOG.md`. Each one runs the local `mdl ci --matrix`
(`--behat` on P0 and the last PR) with `[skip ci]`, per fleet rule 4. A mutation sweep
covers every new guard.

### P0 — Make the branch a 5.3 branch (no behaviour change)

- `version.php`: `requires = 2026100500`, `supported = [503, 503]`,
  `version = 2026100500`. A 5.2 site on `2026042003` upgrades naturally (the number only
  grows). No `cfg.php` step is needed, unlike the 1.2.0 switch.
- `.github/workflows/ci.yml` one job on `MOODLE_503_STABLE`; README requirements line;
  `ci_matrix_test` follows. `CLAUDE.md` plugin context: drop "awaits a 5.3 review".
- `plugins.conf`: mount `moodle-block_feedback_tracker-503` on `m503` / `m503b`; then
  `mdl mounts`, `mdl up`, `mdl upgrade`, `mdl phpunit-init`, `mdl behat-init`.
- Run the whole suite on 5.3. Expect green, and note that green proves nothing about F1
  (see the false green). Record the baseline counts in the PR body.
- Decide whether the branch is renamed `MOODLE_503_STABLE` at this point (fleet branch
  model) or after P1.

### P1 — Allocation events (F1, blocker)

- `db/events.php`: add `marker_added`, `marker_removed`, `marker_enabled_updated` →
  `observer::marker_changed`; keep `marker_updated`. Comments say which core fires which.
- `observer::marker_changed()` docblock: the per-version story (4.5/5.1 batch only, 5.2
  every path through `marker_updated`, 5.3 per-slot events).
- `marker_removed` leaving no marker writes `allocmarkerid = 0` on the current cycle
  (`stamp_allocation_for_user()` already does when the re-read returns 0). Pin it with a
  test, because the same transition used to be silent.
- Tests drive **core's API**, not hand-fired events:
  `$assign->update_marker_allocations($studentid, [1 => [$markerid, null]])` for a
  required slot, `[$pos => [$markerid, 1]]` for an optional one, `[$pos => [0, 0]]` to
  remove. Assert `allocsource = observed`. Rewrite
  `observer_coverage_test::test_marker_*` that way; it is the mutation target (delete the
  `db/events.php` line, the test must redden).
- Re-verify the two carried-over claims (team submissions on the grading form,
  `set_user_flags`) and record the result in `docs/`.

### P2 — One reading of core's multi-marking state, and the progress line (D4)

- `local\sla\marking_progress`: one helper that every later phase reads. For a student's
  latest attempt it returns:
  - the active slots, with position, marker, `optional`, `enabled`;
  - each slot's mark state (none, in marking, complete);
  - `expected`, `completed`, `method`;
  - the wait: `markers` (some active slot not complete), `allocation` (a required slot
    with no marker), `agreed` (`manual`, every expected mark complete, no agreed grade
    yet), or `none`.

  It mirrors `get_marker_allocations()`, `mark_is_marked()`, `expected_marker_count()`
  and `all_markers_marked()`, cites each with `{@see}`, and reads with set-based SQL for a
  page of rows (never one `\assign` per row).
- The progress line in the pending report and the drill-down, through allowlisted fields
  on `get_pending_submissions` (bump its cache key); lang strings en + pt_br.
- `submission_marked` and `agreed_grade_calculated` subscribed to queue `recompute_one`
  for the row's tuple, so the line refreshes when a marker finishes. Both are gated on
  `is_processable()`.
- Tests on 5.3 core through `update_mark()` / `update_marker_allocations()`: 0 of 2,
  1 of 2, 2 of 2 under `average` (agreed grade, wait `none`), 2 of 2 under `manual`
  (wait `agreed`), an optional slot enabled (expected goes 2 → 3), disabled again (3 → 2,
  its mark kept and no longer counted), a required slot left empty (wait `allocation`).

### P3 — Per-slot table and "pending for me" (D3)

- Table `block_feedback_tracker_alloc`, one row per ledger cycle and slot: ledger row id,
  position, marker, `optional`, `enabled`, `timeallocated`, `allocsource`, `timemarked`
  (the first "Marking completed"), `markhours` / `markdays`, `timeremoved`.
  - The events write it: `marker_added`, `marker_removed`, `marker_enabled_updated`, and
    `submission_marked` with `draft` false. Every write re-reads core, as the observer
    does today.
  - A new reconcile sweep through `walk()` rebuilds it from `{assign_allocated_marker}`
    and `{assign_mark}` (`timemodified` as the reconciled completion instant), with
    `allocsource = reconciled`.
- "Pending for me" in Grade Now, the pending report and the drill-down, by the D3
  predicate, read from `marking_progress` (P2), never from the table alone. The table is
  history; core is the truth about who holds a slot now. The course-level figures stay as
  they are.
- Per-marker medians and coverage for other markers behind
  `mod/assign:manageallocations`.
- Privacy provider: the table's marker is a data subject (metadata, export, erasure; the
  student's row stays). `component_is_compliant()` asserted.
- Tests: the owner's example (two of three teachers mark, the third never sees it); an
  enabled optional slot keeps it pending for its marker; a completed marker drops it from
  their own view while the other is still marking; a marker removed mid-way loses it at
  once (`marker_removed`).
- `security-diff-read` before pushing (a capability gate, a privacy surface, a new sweep).
  Update `assign-marking-allocation.md` phases 4-5 first; it is the design's home.

### P4 — The responsible marker (D1)

- Resolution at first closure, in the writer, by method, as D1 states. It is stored with a
  source label (`confirmer`, `maxmark`, `position`, `fallback`) so a median can be split
  by how the attribution was made.
- `timeallocmarker` set from the P3 slot row (`maximum`, `average`) or from the last
  completion instant (`manual`), without passing through the reassignment branch of
  `stamp_allocation_for_user()`.
- While pending, `allocated_marker_id()` returns position 1 (closes F2).
- Tests, each on 5.3 core:
  - `manual`: the confirmer is not an allocated marker; a later feedback-only save by
    someone else does not move the attribution.
  - `maximum`: the higher mark wins; a tie goes to position 1; a marker removed after the
    agreed grade keeps the attribution.
  - `average`: position 1.
  - Mutation targets: the method switch and the stickiness guard.

### P5 — A released response stays recorded (D2)

- Tests first: optional marker enabled on a graded, unreleased student (pending again,
  correct); settings "Clear" on a released student (stays closed, D2); Recalculate under
  `maximum` (closure instant unchanged); partial marking stays pending (owed since August).
- The guard sits next to the gradebook stickiness in `build_and_store()`: stored
  `timereleased` plus live state `released` keeps the activity-sourced closure. The
  comment carries the reason (the student already read the response).
- Independent of P2-P4; can ship right after P1.

### P6 — Docs and comments (F7)

- Rename `moodle-52-multimarking.md` to the 5.2 story only; point it at this file for 5.3.
- Correct every row of the F7 table that applies to 5.3 on this branch, including the
  `install.xml` comment (a comment-only XMLDB edit needs no upgrade step).
- Fold sections 1-3 of this file into `assign-scenario-map.md` (new C-rows for
  `marker_added` / `marker_removed` / `submission_marked` / the bulk clear) and close
  C17-C19.

**Order:**

1. P0.
2. P1.
3. P5 and P6, which can run in parallel.
4. P2.
5. P3, which reads P2.
6. P4, which needs P3's per-slot instants.

## 5. Future work: what can go back to 5.2, 5.1 and 4.5

Recorded here; nothing below is scheduled. 5.2 already has `markercount`,
`multimarkmethod` (`manual` / `maximum` / `average`), `{assign_allocated_marker}` and
`{assign_mark}`; it lacks optional slots and every per-slot event.

| # | Item | 5.2 | 5.1 / 4.5 | Note |
|---|---|---|---|---|
| B1 | `db/events.php` identical on every branch, with the 5.3 events | yes | yes | Inert where the class does not exist; one file to cherry-pick |
| B2 | Allocation tests drive core's API instead of firing the event by hand | `update_allocated_markers()` | the batch "Set allocated marker" path | The same false-green shape exists on every branch |
| B3 | `marking_progress` and the progress line (P2) | yes, required slots only (`expected = markercount`) | n/a | Refreshes on the next event or sweep; there is no `submission_marked` |
| B4 | "Pending for me" (D3, P3) | yes, from `{assign_allocated_marker}` + `{assign_mark}` | yes, with one marker: allocated marker = the only one it is pending for | 4.5/5.1 read `assign_user_flags.allocatedmarker` |
| B5 | Responsible marker by method (D1, P4) | yes, all three methods; per-slot instants are reconciled only | n/a | Position 1 while pending fixes F2 on 5.2 too |
| B6 | Per-slot table (P3) | yes, reconciled precision (`assign_mark.timecreated/timemodified`; allocation discovered by the sweep) | n/a | Absorbs item 3 of `moodle-52-multimarking.md` §6 |
| B7 | Stale comments: `install.xml:32`, the `CLAUDE.md` privacy note, the `supported` headers of the two design docs | yes | yes | Wrong on every branch today |
| B8 | Partial-marking and "all marks in, no agreed grade" tests | yes | n/a | Owed since August |

F3 / D2 (bulk clear) and the optional-slot paths are 5.3-only. They have no backport.
