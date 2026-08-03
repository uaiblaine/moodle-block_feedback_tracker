# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.38] - Unreleased

### Fixed
- **The pending count now matches Moodle's own at the boundary.** A grade saved
  in the same clock second as the submission's last change read as *graded*
  here and as *needs grading* in core's counter, whose clause is
  `s.timemodified >= g.timemodified`. Core needs that direction because it
  auto-creates placeholder grade rows carrying the submission's own timestamp;
  the plugin rejects those by grade value instead, but the boundary is now
  identical anyway — a disagreement at the tie would have shown two different
  pending counts for one activity and had the reconciler chasing the gap for
  ever.
- **Group overrides re-resolved the wrong students.** The rule refresh selected
  ledger rows by the ledger's own `groupid`, which is the *reporting*
  attribution — the group a student was last added to, used to bucket the
  dashboard — and has no reason to match the group an override targets. It now
  selects by real membership of the overridden group, and enqueues every
  reporting tuple actually touched rather than one built from the override
  group.
- **`marker_updated` no longer breaks on Moodle 5.2 or later.** 5.2 dropped
  `assign_user_flags.allocatedmarker` in favour of the new
  `assign_allocated_marker` table, and it does fire the event, so reading the
  old column took the observer down with it. 5.02 is a supported branch, so
  this was a live defect, not a forward-compatibility note.

### Added
- **The response interval is split by who owns each part.** A submission that
  waited ten days to be allocated and was then marked in two hours used to
  report a ten-day turnaround against the marker. The ledger now records
  `queuehours` (hand-in to the first allocation — the coordination queue, which
  belongs to whoever runs the allocation) beside `allochours` / `allocdays` /
  `allocbucket` (the current marker's allocation to the grading — the only part
  that is theirs). The student-experience clock that feeds the responsiveness
  score is deliberately unchanged: the student really did wait the whole time,
  and that remains the institution's SLA.
- The marker turnaround is measured from the **current** marker's allocation,
  not the first, so someone who inherits a long-queued submission on day 8 and
  grades it on day 9 is measured at one day rather than nine. mod_assign fires
  no de-allocation event in any supported version, so the reassignment is only
  detectable from the next allocation.
- **A non-measurable interval reports null, never zero.** An allocation stamp
  that lands at or after the grading — which the reconciler produces whenever
  it discovers an allocation after the fact — would otherwise band as
  `excellent`, reading as a flawless turnaround. Those rows are marked
  `allocsource = 'late'` and excluded from the medians.
- Rollup columns `unallocated` (pending work nobody is responsible for yet),
  `median_queue_h`, `median_alloc_h` and `alloc_coverage_pct`, plus the split
  on `get_responsiveness` and on every listed submission row
  (`get_pending_submissions` / `get_graded_submissions`). The report shows the
  per-row split with an explanatory tooltip.
- **User-level overrides and extensions reach the ledger.**
  `user_override_created` / `_updated` / `_deleted` and `extension_granted` are
  now observed: all four move the dates one student is judged against, and none
  is visible in any other signal — the reconciler's rule-drift sweep compares
  against the activity's own dates and `assign_user_flags`, so an
  `assign_overrides` row was invisible to it.
- **An eighth reconciliation sweep discovers silent allocations.** Before
  Moodle 5.2 only the batch "Set allocated marker" operation fires an event;
  quick grading and the grading form write the allocation with no signal at
  all. Those are now found by a periodic diff and stamped
  `allocsource = 'reconciled'` — the moment of *discovery*, not of allocation,
  which is why it stays separable from `observed`: a median built from a mix
  without saying so would understate every turnaround.
- Behat coverage for the three outcomes only a browser can prove: the reported
  resubmission defect (the grading survives in the history *and* a new pending
  item appears), the awaiting-release chip, and a group submission appearing
  against every member. The scenarios pin core's grading form via
  `local_unifiedgrader/enable_assign`, so a sibling plugin that replaces the
  assign grading UI cannot move the field labels underneath them.
- `alloc_coverage_pct` is published **beside** `median_alloc_h`, never alone:
  before Moodle 5.2 only one of mod_assign's three allocation paths fires an
  event, so the sample is partial by construction and the median must not be
  read as if it covered everyone.

## [1.0.37] - Unreleased

### Fixed
- **A student who re-saves an already-graded submission no longer un-grades
  it.** The ledger compared the live `assign_submission.timemodified` against
  the grade time on a single mutable row, so any later save — even one that
  changed nothing — pushed the hand-in time past the grading time, reset
  `timegraded` to null and restarted the clock. The recorded response time was
  destroyed and the submission reappeared as awaiting feedback, while Moodle
  itself kept reporting it as graded. The ledger now keys on a **measurement
  cycle**: work resubmitted after it already carried a mark opens a new cycle
  instead of rewriting the closed one, so the completed turnaround survives in
  the history and the re-look becomes a correctly-pending item whose clock
  starts at the re-save. Removing the timestamp comparison outright was
  considered and rejected — it makes a reopened cycle inherit the old mark and
  be born graded with a fabricated zero-hour turnaround.
- **Group assignments are tracked per member instead of not at all.**
  mod_assign stores a team's work in one `assign_submission` row with
  `userid = 0`; the observer mirrored it verbatim, producing a ledger row the
  rollup counted (it does not join `user`) but every list hid (they all do) —
  a pending item nobody could clear — while the actual group members got no row
  at all. The container row is now refused at the write path (and the existing
  ones removed on upgrade), and team submissions are fanned out to one ledger
  row per member: timing and status from the shared group row, the mark from
  each member's own grade row, tagged with the originating `teamgroupid`.
  A member who never personally saved has no submission row of their own when
  `requireallteammemberssubmit` is on, which is precisely why the per-user
  lookup dropped them; the fan-out reads the group row instead. Members of the
  default group (in none, or more than one, group of the activity's grouping)
  follow the same rule core applies, and are skipped entirely when
  `preventsubmissionnotingroup` bars them from submitting. Both the observers
  and the historical backfill route through it.
- **Superseded attempts stop counting as pending.** The ledger now mirrors
  `assign_submission.latest`, which is the gate core applies to every one of
  its own needs-grading reads, and every pending predicate is filtered on it
  plus `iscurrent` — the rollup's pending set, the stale-row recomputer, the
  report's pending and draft tabs, and the grade-now priority list. Granting
  another attempt fires no event at all, so an ungraded earlier attempt
  previously stayed pending indefinitely with a clock that grew without bound.
  The graded populations deliberately keep **every** cycle: a teacher who
  responded twice generated two genuine response events, so `numgraded30d`
  counts responses rather than distinct students.
- **The dashboard's "current" medians no longer count one attempt twice.**
  `cur_median_eff_h` / `cur_median_raw_h` / `cur_median_eff_days` /
  `cur_median_perc_days` merged the 30-day graded set with the pending set, so
  an attempt whose earlier cycle was graded inside the window while its current
  cycle awaits feedback appeared in both. They are now built from a dedicated
  current-state population, one live observation per attempt.
- **Elapsed-day fallbacks can no longer go negative.** A row written before the
  cycle model could hold `timegraded` earlier than `timesubmitted`, and the
  un-backfilled-day fallback turned that into a negative count that sorted
  straight to the top of the priority list. Clamped at zero, expressed as
  `CASE` rather than `GREATEST` (which core uses nowhere and which SQL Server
  lacks before 2022).
- **Activities with grade type "None" are no longer permanently pending.** They
  can never carry a numeric mark, so the grade-value test never passed. They
  now follow core's needs-grading counter, which clears them on a grade row
  later than the hand-in.
- **Submission events from the online-text and file subplugins no longer read
  the wrong row.** Those events override `objecttable`, so their `objectid` is
  the subplugin row id and the real `assign_submission.id` lives in
  `other['submissionid']`. The observer looked the former up in the latter's
  table: it either found nothing, or found an unrelated row and wrote a ledger
  entry against the wrong student.

### Added
- **Scheduled ledger reconciliation** (`reconcile_ledger`, every two hours,
  `reconcile_active` on by default). Six classes of mod_assign mutation emit
  no usable event and can only be repaired by diffing against the source
  tables: `add_attempt()` inserts a new attempt and flips the previous row's
  `latest` flag in total silence; blind marking makes `gradebook_item_update()`
  return false before doing anything, suppressing the grading event for the
  entire activity until identities are revealed; grading a non-latest attempt
  returns early, before the trigger; a gradebook-side override or lock silences
  it too; `reset_userdata()` bulk-deletes submissions with
  `delete_records_select()`, leaving orphans; and due dates, cut-offs,
  overrides and extensions change with no per-row signal. Seven keyset-paged
  sweeps, each behind its own cursor and bounded per tick, dispatch repairs as
  adhoc tasks — except the two cleanup sweeps (orphaned rows, departed
  participants), which act directly because the repair task re-gates on course
  processability and would skip exactly the courses whose rows most need
  removing. The divergence sweep mirrors the writer's predicate exactly and is
  cycle-scoped, so it converges instead of re-dispatching the same repair every
  tick; both properties are pinned by tests.
- **Event coverage for the mod_assign actions the observer used to miss.**
  `workflow_state_updated` (the marking-workflow release — the only signal
  that a grade became visible to the student, and one core keeps no timestamp
  for), `identities_revealed` (blind marking suppresses `submission_graded`
  outright, so until identities are revealed every grading on the activity is
  invisible and every submission reads as awaiting feedback), `marker_updated`,
  `submission_removed`, `submission_duplicated`, and the
  `assignsubmission_onlinetext` / `assignsubmission_file` `submission_updated`
  twins — without which a student editing an existing submission is invisible
  whenever `submissiondrafts` is on.
- **Allocation timestamps.** `timeallocated` (first allocation, sticky, closes
  the coordination-queue measurement), `timeallocmarker` (the current marker's
  own start, so someone inheriting a long-queued submission is not charged for
  a queue they did not cause), `allocmarkerid` and `allocsource`. mod_assign
  stores no allocation timestamp on any supported version, so the moment is
  only ever knowable when observed — and on 4.5 and 5.1 only the batch "Set
  allocated marker" operation fires an event at all, which is what
  `allocsource` records.
- **"Awaiting release" chip on marked-but-unreleased submissions.** Under
  marking workflow a saved grade is invisible to the student until the state
  reaches *Released*, and releasing needs a capability the marker frequently
  does not hold. The report now flags those rows with an explanatory tooltip so
  the marker can see that an outstanding step exists and that it may not be
  theirs. Reaches the payload as the new `awaitingrelease` field on
  `get_pending_submissions` / `get_graded_submissions` rows.
- New setting **`release_stops_clock`** (default off). When on, the response
  clock for a marking-workflow activity stops at release rather than at
  marking, which is when the feedback actually reaches the student. Default off
  so the upgrade moves no displayed number; turning it on changes historical
  figures on courses that use marking workflow and should be followed by
  `cli/recompute_all.php` and `cli/backfill_trends.php`.
- Ledger columns `cycle`, `timemarked`, `timereleased`, `timeclosed`,
  `islatest`, `iscurrent`, `gradestate` and `teamgroupid`. `timereleased` and
  `timeclosed` are maintained regardless of the setting above, so enabling it
  later needs no re-derivation of what the student actually saw — mod_assign
  persists no release timestamp of its own (`assign_user_flags` has no time
  columns on any supported version), so the moment is only ever knowable when
  observed.
- `docs/moodle-52-multimarking.md`: what Moodle 5.2 changed about marker
  allocation and multi-marking, and why it was a live defect rather than
  future work.
- `docs/assign-scenario-map.md` and `docs/assign-marking-allocation.md`: the
  full audit of mod_assign's submission and grading lifecycle against this
  plugin, covering the scenarios not yet addressed here (team fan-out, blind
  marking, gradebook-side changes, course reset, the marking-allocation clock)
  and the design for each.

## [1.0.36] - Unreleased

### Fixed
- **Sortable tables are reachable by keyboard.** The group drill-down's column
  headers attached their click listener to the `<th>` itself, so sorting could
  only be triggered with a mouse and the active column was never announced —
  a WCAG 2.1.1 failure. Headers now carry a real `<button>`, and `aria-sort`
  moves onto the `<th>` where it is valid. The pending report had the same
  `aria-sort` misplacement (on the button rather than the header) and is
  corrected to match.
- **Assistive technology is no longer read English on a pt_br site.** The score
  gauge and the sparkline hard-coded their `aria-label`; both now come from
  lang strings. The sparkline's label also said "30-day trend" while the card
  renders 14 days, so screen-reader users were told the wrong window.
- **The hero info dot is focusable.** It was a bare `<span>`, so its
  explanatory tooltip could not be reached without a mouse and its
  `aria-label` was ignored on a generic element. It is a `<button>` now.
- **Draft badges are visible.** `.badge-draft` had no rule at all, so under
  Bootstrap 5 a bare `.badge` set white text with no background and the label
  rendered white-on-white.
- **Sorting no longer inverts after a double init.** `pending_table.js` gained
  the idempotency guard every other entrypoint already had; without it a
  second init bound a second listener per header, so one click sorted
  ascending then immediately re-sorted descending.

## [1.0.31] - Unreleased

### Added
- **Business-days band thresholds** — when the display unit is business days,
  the excellent / good / up-next / priority bands classify by elapsed
  business days against a new comma-separated setting (default `2,5,10`,
  inclusive bounds: up to 2 days excellent, 3-5 good, 6-10 up-next, over 10
  priority) instead of the hour thresholds. The ruler is applied everywhere
  bands appear: report badges, distribution counts and band filters
  (pending and graded), the grade-now list, the block's pending tiles, the
  dashboard counts and the academic-days strip colours. The responsiveness
  score keeps using effective hours and is unaffected. New per-submission
  `effectivedays` column maintained alongside `effectivehours` (backfilled
  on upgrade) and day-ruler `critical_days`/`overgoal_days` rollup twins.
- The academic-days strip tooltip now shows each day's median in the
  configured unit (elapsed business days in days mode, effective hours
  otherwise) via a new per-day `eff_days` field on `get_academic_days`.

### Changed
- The report's "paused" row tag is now a compact info icon: hover keeps the
  explanation tooltip and clicking pins it, so touch users can read it too.
- Teacher-facing copy that names the working-time unit (the hero Effective
  tooltip, score note/tooltip, dashboard/report intro, the "business-time
  only" chip and the paused-row tip) now says "business days" / "dias úteis"
  when the business-days display unit is active, resolved server-side in the
  i18n bundle so the React layer is unchanged. Settings descriptions, the
  simulator and the SLA-goal tooltip stay in hours (they configure or report
  genuinely hour-based values).

## [1.0.30] - Unreleased

### Changed
- **Pending report loads like the dashboard** — the page ships its first byte
  immediately and every section loads asynchronously after first paint: the
  submissions table (skeleton while in flight) in parallel with the hero
  scopes and class filter, then drafts, then the academic-days strip. The
  page previously blocked on two submissions queries plus the full per-group
  responsiveness payload (trend series, peer stats, activity schedules)
  before sending any HTML.

### Added
- New `get_report_scopes` web service: per-group hero scopes + class-filter
  list read straight from the materialised rollup — the report no longer
  builds the full responsiveness payload at all.

## [1.0.29] - Unreleased

### Added
- **Wait-time display unit** — a site setting to show wait times in business
  **hours** (default) or **business days**. Business days are counted from
  the submission and grading *dates* (time of day is ignored): business days
  skip weekends, holidays and recesses per the platform calendar, while the
  perceived wait counts every calendar day — submitted Friday evening and
  graded Monday reads 1 business day / 3 calendar days. Applies everywhere:
  the in-course block, the dashboard hero and courses table, the grade-now
  priority cards, and the pending/graded report. The responsiveness score
  still uses effective hours and is unaffected. Toggling the unit is
  display-only (both representations are always computed); run
  `cli/recompute_all.php` once after upgrading to backfill the new day
  medians (or let the drain queue catch up).
- New per-submission `effective_days` / `perceived_days` fields on
  `get_pending_submissions`, `get_graded_submissions` and
  `get_grader_priority_list`; new `cur_median_eff_days` /
  `cur_median_perc_days` rollup columns surfaced via `get_responsiveness`
  and `get_dashboard`.
- **Show peer comparison** site setting to hide or show the peer-context panel
  (You / Department / Top 10%) on the in-course group cards.

### Fixed
- The grade-now priority card estimated the perceived wait from effective
  hours with a fudge factor, drifting far from the report's figure on long
  waits. It now shows the real elapsed calendar days.
- The no-JS responsiveness card's "show perceived time" and "show paused-today
  indicator" toggles could not be switched off — the stored `'0'` was read as
  on (PHP treats the string `'0'` as falsy). They now honour an explicit off.

## [1.0.27] - Unreleased

### Added
- **Graded view** on the pending-grading report — switch between work that's
  still waiting and work already graded, sharing one unified browser. New
  `get_graded_submissions` web service.
- **Academic-days strip** on the report — a 30-day view of on-goal, paused and
  other academic days. New `get_academic_days` web service.
- Drafts (not-yet-submitted work) are surfaced read-only on the report,
  clearly separated from work awaiting feedback.

### Changed
- The report reuses the dashboard hero (Score / Effective / SLA / Trend) for a
  consistent look, with an action column and a collapse toggle.
- `get_pending_submissions` gained pagination, sorting and a status filter.
- Brazilian Portuguese language pack brought to full key parity with English.
- Standardised all in-code comments to English (user-facing strings
  unaffected).

### Removed
- Dropped an unused legacy dashboard-greeting fallback.

## [1.0.25] - 2026-06-08

### Added
- Per-group **assignment open/close schedule** in the block: each course
  assignment shows its effective open/close dates per group and a four-state
  action chip (done / create rule / override rule / no rule), gated on the
  manage-overrides capability and deep-linking to the group overrides page.
- `get_responsiveness` now returns each group's activities.

### Changed
- The activity timeline bar shows before / running / closed phases; the stat
  row reflows gracefully on narrow blocks instead of overflowing.

## [1.0.24] - 2026-06-05

### Added
- `get_responsiveness` gained pagination (`limit` / `offset`) and sorting,
  with skeleton loading states and a "load more" control in the block.
- Settings to compose the group-card title and subtitle from custom group
  fields.

### Changed
- In-course block overhaul: collapsible group cards, accessibility and
  typography improvements (WCAG AA contrast, focus rings, larger touch
  targets), a footer with last-sync time and a cache note, and a smarter
  priority sort.
- The pending tiles (Waiting / Attention / Priority) now partition the whole
  backlog exactly; the score is unchanged.
- 14-day sparkline window; the score breakdown moved out of the block (the
  simulator covers it); comparison is score-only.

## [1.0.23] - 2026-06-04

### Added
- Optional **teacher access to the score simulator** (admin master switch,
  default off).

### Changed
- The score's **trend** term now compares this week against last week (rolling
  7-day windows); compliance, median, counts and the 30-day sparkline stay
  monthly. The formula itself is unchanged.

### Fixed
- The collapsed dashboard hero no longer overflows on intermediate widths, and
  the sort control is now translatable.

## [1.0.21] - 2026-06-03

### Changed
- Dashboard polish: the effective / perceived / trend mini-stats move into the
  hero copy column; a responsive courses table with mobile reflow; a scoped
  typography and accessibility token scale.
- Block effective / perceived KPIs now use backlog-aware "current" medians,
  matching the dashboard; the score stays graded-only.

## [1.0.20] - 2026-06-02

### Added
- Interactive **Academic Responsiveness Score simulator** (admin sandbox) —
  tune the five weights and a group scenario and watch the score, band and
  per-term breakdown update live. Nothing is saved.

## [1.0.19] - 2026-06-02

### Fixed
- Unified every surface on a single **"speed" model** for trend (faster =
  better, green ▲; slower = red ▼), correcting inverted arrows and an inverted
  sparkline.
- Hero effective / perceived times now include currently-pending work, so the
  backlog shows through (the score stays graded-only).
- The dashboard's global trend and SLA now populate (the service was omitting
  fields the view expected).

## [1.0.18] - 2026-05-31

### Fixed
- The teacher dashboard rendered an empty shell because a web-service call
  resolved a jQuery promise with no native `.finally()`. Every WS call now
  returns a native Promise. (Supersedes 1.0.17, whose rebuilt bundle was never
  regenerated.)

## [1.0.16] - 2026-05-31

### Added
- `cli/recompute_all.php` to refresh stored rollups in place after a
  scoring-rule change.

### Changed
- Teacher dashboard access scope made correct and configurable: the "admins
  see all" setting now works; non-admins are scoped to active
  teacher-or-higher enrolments.
- The dashboard loads asynchronously (ships a shell, fetches on mount) instead
  of running a large query batch before the page renders.
- Groups with no submitted work now score as a neutral "no data" instead of a
  misleading 100, and are excluded from averages, insights and peer stats.

### Fixed
- Priority cards deep-link to the single-student grader; course titles link to
  the pending report.

## [1.0.14] – [1.0.15] - 2026-05-30

### Fixed
- Continuous-integration hardening: resolved PHP CodeSniffer violations and a
  PHPUnit ordering flake; temporarily disabled Behat pending a CI image fix.

## [1.0.13] - 2026-05-30

### Fixed
- Trend backfill now covers every group (a multi-group course previously
  collapsed to one group per course).
- Clamp the 30-day trend percentage so a near-zero prior window can't overflow
  the stored column.

## [1.0.12] - 2026-05-29

### Changed
- Only **submitted** work counts toward pending counts, response time, score,
  compliance and trend. Draft / new / reopened attempts are awaiting the
  student, so they no longer inflate the metrics.

### Added
- Drafts are surfaced read-only and de-emphasised on the report and drilldown,
  never reaching the block or dashboard counts.

## [1.0.11] - 2026-05-27

### Fixed
- `PausedCallout` event segment now includes the event date and time
  alongside the label. Previously it rendered `1 event (⚽ Brasil vs
  França)`; now `1 event (21/05 14:00-16:00 · ⚽ Brasil vs França)`.
  The data was already in the `paused_events_30d` sidecar — only the
  line builder needed updating.
- Block view `PausedNote` now reads from the `paused_events_30d` sidecar
  directly when present, so a named optional event shows on the block
  even when the rollup pause indicators are stale (the drain queue
  may not have caught up since the calendar edit). Format:
  `DD/MM HH:MM-HH:MM: ⚽ label`.

### Added
- Teacher dashboard subline gains a "Recent event" chip (plum tone)
  for the most recent named optional event in the last 30 days,
  site-scope. `pages/teacher_dashboard.php` calls
  `paused_aggregator::for_window(0, ...)` at boot and emits the
  events list as `initial.events`. New i18n keys
  `dashboard_event_chip_label` + `dashboard_event_chip_tooltip`
  (en + pt_br).

## [1.0.10] - 2026-05-27

### Fixed
- `paused_aggregator::ymd_in_pause_span()` triggered PHP 8.x's
  "Deprecated: Implicit conversion from float ... to int loses precision"
  warning when computing the day-string from a YYYYMMDD integer. The
  expression `($ymd / 100) % 100` produced a float intermediate (PHP's
  `/` always returns float) and `% 100` on a float casts implicitly.
  Replaced with `intdiv()` so every intermediate stays an int. Same
  pattern in `pages/calendar_editor.php` minute→HH:MM conversion also
  switched to `intdiv()` for consistency (no functional change — the
  outer `(int)` cast was suppressing the deprecation there).

## [1.0.9] - 2026-05-27

### Phase 4C — Optional days and named sub-day events

#### Added
- `block_feedback_tracker_cday` schema gains nullable `starttime` and
  `endtime` columns (minutes since midnight). Both null = legacy full-day
  rule. When set on a `daytype = 'optional'` row, they describe a sub-day
  event window inside an otherwise active schoolday.
- `calendar::daytype_label()` static helper — single source of truth for
  the localised label of every daytype slug. Reused by
  `calendar_day_form.php` (dropdown options) and
  `pages/calendar_editor.php` (day-list column).
- `day_rule_resolver::for_date()` returns a new `optional_window` key on
  the rule shape when a sub-day event is present
  (`{startmin, endmin, note}`). Full-day optional rows keep their
  `is_active=false` behaviour.
- `academic_time::effective_hours_between()` subtracts the optional
  event window from the day's active intervals and records a synthetic
  pause with `reason='optional'` and the event label as `note`.
- `paused_aggregator::for_window()` grew an `events` sidecar list
  (`[{date, starttime, endtime, label}]`). Sub-day events surface
  there without inflating the `recess` day-bucket; full-day optional
  rows fold into the `recess` bucket as before.
- Payload + WS additive: `paused_events_30d` array on
  `responsiveness_payload::group_payload()` and
  `get_responsiveness::execute_returns()`.
- `save_calendar_day` WS gained nullable `starttime` / `endtime` params
  with range + ordering validation. JS wrapper
  `api.js::saveCalendarDay()` accepts the new options.
- Calendar-day form (`calendar_day_form.php`) reveals HH:MM start/end
  inputs when `daytype = 'optional'`. Both-set-or-both-empty is enforced;
  values round-trip through `get_data()` as minutes-since-midnight.
- PausedNote (block view) and PausedCallout (report page) recognise the
  new `optional` pause reason: PausedNote renders
  `"Paused 16:00-18:00: ⚽ Brasil vs França"`; PausedCallout's body
  line adds `"… · 1 event (⚽ Brasil vs França)"`. Event labels are
  pre-sanitised server-side via `format_string()` so emoji + unicode
  pass through cleanly while HTML is escaped.
- Calendar editor day list: localised label via the new helper +
  `"Optional · 16:00-18:00"` suffix for sub-day rows.
- New tests:
  - `paused_aggregator_test::test_full_day_optional_buckets_as_recess`
  - `paused_aggregator_test::test_sub_day_optional_event_appears_in_events_sidecar`
  - `day_rule_resolver_test::test_sub_day_optional_event_surfaces_window`
  - Extended `get_responsiveness_test` to assert `paused_events_30d`
    shape.

#### Changed
- `pause_reason_for_day()` in `academic_time` now distinguishes
  full-day `optional` from `closed`, returning a dedicated `'optional'`
  reason so PausedNote can render the event-style note rather than
  collapsing into a generic closed chip.
- `next_pause_indicator()` / `last_pause_indicator()` SQL filters
  include `DAYTYPE_OPTIONAL`; sub-day rows resolve their timestamps to
  `ymd + starttime*60` so the indicator points at the actual window.
- Upgrade savepoint at `2026060109` runs `$dbman->add_field()` for both
  new columns and bumps `calver` via `calendar::bump_version()` so
  cached payloads roll over and downstream views pick up the events
  sidecar on first read.

#### Notes
- Schema additions are nullable; existing cday rows continue to work as
  full-day rules with both columns null.
- Sub-day events do NOT bump the `recess` bucket count — they're
  hour-scale, not day-scale. The dedicated `events` sidecar list
  surfaces them for the UI to render alongside the bucket counts.

## [1.0.8] - 2026-05-26

### Phase 4B — Combined hero+insights collapse + user-preference persistence

#### Added
- `block_feedback_tracker_user_preferences()` in `lib.php` declares the
  `block_feedback_tracker_dashboard_collapsed` preference (PARAM_BOOL,
  default `'0'`, permission callback `\core_user::is_current_user`).
  Wires the plugin into Moodle's core
  [user-preferences API](https://moodledev.io/docs/5.2/apis/core/preference)
  so `core_user_update_user_preferences` accepts writes to this key.
- `pages/teacher_dashboard.php` preloads the preference via
  `get_user_preferences()` and emits it as `initial.dashboard_collapsed`
  in the bootstrap JSON — first paint matches the user's saved choice
  with no WS round-trip.
- Privacy provider now implements
  `\core_privacy\local\request\user_preference_provider`. The new
  preference is declared via `$collection->add_user_preference()` in
  `get_metadata()` and surfaces in subject-access exports through
  `export_user_preferences()` with localised "collapsed" / "expanded"
  descriptions. Deletion is handled by Moodle's core privacy
  machinery — no plugin-side delete path needed for preferences
  declared this way.
- New privacy lang strings:
  `privacy:metadata:preference:dashboard_collapsed`,
  `privacy:preference:dashboard_collapsed_collapsed`,
  `privacy:preference:dashboard_collapsed_expanded` (en + pt_br).
- Privacy provider tests gain two coverage methods:
  `test_export_user_preferences_writes_dashboard_collapsed` (verifies
  the value lands in the writer's preferences bucket) and
  `test_export_user_preferences_is_noop_when_unset`.

#### Changed
- The Responsiveness hero and Insights section now collapse together
  under a single toggle. Previously the hero had its own
  collapsed/expanded state and the Insights row was always visible.
- `ResponsivenessModule.js` is now a controlled presentational
  component: parent owns the state, module receives `collapsed` +
  `onToggle` props. Removed the `bft.dashboard.hero.collapsed`
  `localStorage` round-trip.
- `DashboardView` lifts the collapsed state, gates the Insights
  section on `!collapsed`, and persists toggles through
  `core_user/repository::setUserPreference()` with optimistic local
  update + revert-on-failure.

#### Notes
- Existing `localStorage` keys from v1.0.5–1.0.7 are now orphaned but
  harmless — no cleanup needed. Users get the server-side default
  (`expanded`) on first visit after upgrade.
- Permission callback restricts writes to the user's own preference
  row — admins can't toggle the dashboard hero on someone else's
  behalf through the WS.

## [1.0.7] - 2026-05-25

### Phase 4A — Trend-term refinements (Lever 1 + Lever 3)

#### Added
- `responsiveness_calculator::effective_weights($weights, $available)` —
  drops unavailable terms and renormalises the kept weights to sum 1.0.
  Used by both `compute()` and the breakdown panel so the displayed
  weights match what actually contributed to the score.
- `responsiveness_calculator::momentum_pct($courseid, $groupid, $now)` —
  week-over-week % change in median effective hours for one group.
  Returns null when either 7-day window has fewer than 5 grades.
- `insight_momentum_eyebrow` / `insight_momentum_body` lang strings
  (en + pt_br) for the dashboard momentum copy.
- `breakdown_excluded_prefix` lang string (en + pt_br) for the new
  breakdown footnote.
- `.bft-breakdown-footnote` CSS rule.

#### Changed
- `responsiveness_calculator::compute()` — when the rollup has no prior
  30-day window (`trend_pct_30d === null`), the trend term is now
  dropped instead of substituted with 0.5. The other four weights
  renormalise to sum 1.0, so a brand-new course can legitimately
  reach 100. Previously a fresh course's theoretical maximum was 95.
- `components.trend` is now `null` (not `0.5`) when no trend data is
  available — the breakdown panel skips the row and prints a footnote
  explaining the exclusion.
- `responsiveness_card::build_breakdown()` mirrors the JS-side math:
  renormalised weights, excluded-rows footnote.
- `get_insights::pick_most_improved()` — first checks for sharp
  week-over-week momentum (`momentum_pct < -40%` with ≥5 grades each
  week); falls back to the existing 30-day heuristic. The returned
  row carries an optional `momentum` boolean so the JS InsightCard
  can swap eyebrow text + body.
- `DashboardView` consumes the `momentum` flag and renders either the
  "This week's momentum" or "Most improved" copy.

#### Notes
- No schema change, no calver bump — payload shape is additive
  (`momentum` is `VALUE_OPTIONAL`), score component shape is
  unchanged (trend was already declared `NULL_ALLOWED`).
- Momentum recognition is dashboard-only — the score formula never
  sees the week-over-week signal, so the headline number stays
  transparent and explainable.
- Weekly-weighted trend in the score formula (Lever 2) was
  considered for this release; deferred to v1.1.0 so the 95-cap fix
  can soak first before changing what `trend_pct_30d` itself means.

## [1.0.6] - 2026-05-24

### Phase 3F — Web-service completion

#### Added
- New WS `get_audit_log` (`classes/external/get_audit_log.php`) —
  paginated read of `block_feedback_tracker_log` with optional
  `courseid` and `actor` filters. Reuses the existing `viewaudit`
  capability. Returns `{success, total, page, perpage, entries[],
  lastsynced}`.
- Five typed JS write wrappers in `amd/src/lib/api.js`:
  `savePauseWindow`, `deletePauseWindow`, `saveCalendarDay`,
  `bulkImportCalendar`, `saveBusinessHours`. Each mirrors the server's
  `execute_parameters()` signature so callers can pass payload-shaped
  options objects.
- Read wrapper `getAuditLog()` added to `amd/src/lib/api.js`.
- Drift-check phpunit test `services_coverage_test.php` — iterates
  every entry in `db/services.php` and asserts the class exists,
  extends `external_api`, defines `execute_parameters()` / `execute()`
  / `execute_returns()`, and references a declared capability.

#### Notes
- Calendar editor migration to React deferred — the existing
  `pages/calendar_editor.php` is pure server-rendered moodleform with
  no JS to migrate. The future React port (documented in
  `docs/future-features.md`) will adopt the new typed wrappers.

## [1.0.5] - 2026-05-24

### Phase 3E — Dashboard redesign

#### Added
- New WS `get_insights` (`classes/external/get_insights.php`)
  returning `{bright_spot, most_improved, gentle_watch}` for the
  dashboard insight cards. Heuristics: bright spot = top-scoring
  group, most improved = largest negative `trend_pct_30d`, gentle
  watch = group with most critical pending. Null insights are omitted
  from the response. Cached 900s per `(calver, userid)`. Capability:
  `viewdashboard`.
- 8 new Preact components: `WaveMark`, `Sparkle`, `InsightCard`,
  `PriorityCard`, `ResponsivenessHero`, `ResponsivenessHeroSlim`,
  `ResponsivenessModule` (toggle wrapper, persisted via
  `localStorage`), `CoursesTable`.
- `docs/future-features.md` documenting the "On-goal academic days"
  deferral, audit-log React-view deferral, mobile reflow, woff2
  self-hosting, and Moodle 5.2 React migration.

#### Changed
- `DashboardView.js` rewritten — brand-tag → time-aware greeting (with
  `WaveMark`) → subline → collapsible `ResponsivenessModule` →
  Insights row → Priority cards → Courses table.
- `get_dashboard::execute_returns()` extended with per-course
  `score_band`, `median_eff_h`, `perceived_median_hours`, and
  `trend_series` (30-day, from `block_feedback_tracker_trend`).
  `CACHE_KEY_VERSION` bumped to 3.
- `pages/teacher_dashboard.php` pre-loads insights server-side + emits
  `greeting_firstname` so the view can build the greeting from local
  clock.
- `bootstrap::dashboard_i18n()` extended with ~30 new keys (greetings,
  insights eyebrows/bodies, priority labels, business-time chip).

## [1.0.4] - 2026-05-24

### Phase 3D — Pending report page redesign

#### Added
- 4 new Preact components: `PausedCallout` (transparency strip),
  `StatusDistributionBar` (segmented click-to-filter), `HeroMetricCard`
  (children-driven hero card chrome), `SegmentedFilter` (pill row
  with tone-coloured active state).
- Scope-level hero metrics in `pages/pending_report.php` —
  `build_pending_report_scope()` helper picks the active group's
  payload or aggregates across the course when `groupid=0`.

#### Changed
- `PendingReportView.js` rewritten — breadcrumb → title + overall
  band chip → 4-card hero (Score / Effective / SLA / Trend) →
  PausedCallout → StatusDistributionBar → toolbar with segmented
  status filter → table with dual `Effective` (bold colored) +
  `Perceived` columns + per-row `paused` tag where
  `waitinghours − effectivehours > 0.5h`.

## [1.0.3] - 2026-05-24

### Phase 3C — Perceived time + Paused aggregates + Peer data

#### Added
- New `classes/local/calendar/paused_aggregator.php` —
  `for_window(courseid, start, end)` returns `{total_days, weekend,
  holiday, recess}` for the design's transparency callout. Honours
  `excludeweekends` / `excludeholidays` / `excluderecesses` flags;
  `schoolday` override cancels weekend classification; manual pauses
  bucket as recess.
- New `classes/local/score/peer_stats.php` — `for_exclusion(groupid)`
  returns `{department_score, department_hours, top10_score,
  top10_hours}` (p50 / p90 / p10 of group rollups). Returns nulls when
  sample size < 3 so the JS `PeerContext` hides gracefully.
- Tests: `tests/local/calendar/paused_aggregator_test.php` (8 cases
  covering weekends, holiday-overrides-weekend, recess+closed,
  schoolday cancellation, manual pause, course-scope isolation, empty
  window, weekend-exclusion disabled).

#### Changed
- `responsiveness_payload::group_payload()` gains
  `perceived_median_hours` (alias of existing `median_raw_h`),
  `paused_days_30d`, `paused_breakdown_30d`,
  `peer_department_score`, `peer_department_hours`,
  `peer_top10_score`, `peer_top10_hours`.
- `get_responsiveness::execute_returns()` mirrors the new fields
  (strict additive — existing callers ignore unknown keys).
- Upgrade savepoint bumps `calver` via `calendar::bump_version()` so
  MUC keys roll over and cached payloads pick up new keys on first
  read.

## [1.0.2] - 2026-05-24

### Phase 3B — Block view recomposition

#### Added
- 8 new Preact components in `amd/src/components/`: `ScoreRing`,
  `KpiTile`, `TrendRow`, `StatTile`, `PeerContext`, `PausedNote`,
  `TimelineBar`, `OverallBanner`.
- `OverallBanner` mounted at the top of `BlockView.js` — eyebrow +
  ScoreRing + score + band pill + `PausedNote`. Overall score is a
  pending-weighted average across present group scores.
- Activities list inside each `GroupCard` — per-assignment open/close
  `TimelineBar` with urgency colouring and "NO RULE" badge for
  rule-less assignments.

#### Changed
- `GroupCard.js` recomposed — header strip (course + class name +
  status chip) → hero row (`ScoreRing` + score + caption) →
  3-column KPI row (Effective / Perceived / SLA) → `TrendRow`
  (arrow + verbal label + sparkline) → `StatTile` row (Pending /
  At risk / Priority — clickable, deep-link to filtered report) →
  `PeerContext` (optional, hidden when sample too small) →
  `BreakdownPanel` (kept, collapsed) → activities list.
- Mustache SSR fallback templates kept structurally identical;
  visual refresh comes via the CSS tokens introduced in 1.0.1.

## [1.0.1] - 2026-05-24

### Phase 3A — Design tokens & threshold setting

#### Added
- Admin setting `score_thresholds_band` (CSV `"90,70,40"` default,
  mirroring the existing `bucket_thresholds_eff` pattern) routing
  through new helper `responsiveness_calculator::parse_thresholds_band()`.
  PHP `band_for()` reads from the setting; JS
  `bandForScore(score, thresholds)` accepts an explicit thresholds
  arg propagated via `bootstrap::config_bundle()` →
  `initial.config.score_thresholds`.
- `:root` CSS token block — surface / border / text / per-band fg+bg
  pairs / font-family stacks. Per-band tokens are
  `--bft-band-{slug}-fg` / `-bg`.
- `.bft-mono` utility class for numeric cells; `--bft-font-display`
  (Manrope first, system-ui fallback) + `--bft-font-mono`
  (JetBrains Mono first, ui-monospace fallback).

#### Changed
- Band palette across PHP (`score_gauge.php::BAND_COLOURS`), JS
  (`bands.js::BAND_COLOURS`), CSS (badge rules), and gauge stroke now
  uses the calm "Academic Responsiveness" palette: excellent
  `#047857`, good `#0e7490` (teal, replacing amber), regular
  `#b45309`, critical `#be4b25` (softer burnt sienna, replacing
  pure red), pending `#475569`.
- Default font-family promoted to Manrope across `.block_feedback_tracker`
  and `.bft-dashboard`.

#### Notes
- Self-hosting Manrope + JetBrains Mono woff2 files deferred — design
  degrades cleanly to system geometric sans for users without the
  fonts installed.

## [1.0.0] - 2026-05-23

### Added — data layer
- Per-submission SLA ledger (`block_feedback_tracker_sub`) tracking raw
  wall-clock hours and business / academic effective hours for every
  `\mod_assign` submission, keyed by `(cmid, userid, attemptnumber)` and
  tied to `groupid`.
- Materialised rollup (`block_feedback_tracker_group`) with pending /
  critical / over-goal counts, raw and effective medians / p90 / max,
  compliance %, 30-day trend, `nextpause` / `lastpause` indicators, and
  the five score-formula component values (`comp_*`) per (course, group).
- Per-course backfill cursors (`block_feedback_tracker_bfcursor`) with
  round-robin starvation prevention across courses.
- Daily trend table for the 30-day sparkline and a site-wide stats table
  for the school-comparison overlay.
- Calendar configuration tables: `_cday` (per-day exceptions), `_chours`
  (weekly business hours), `_cpause` (manual pause windows).
- Dirty queue + audit log tables.

### Added — engine + score
- Academic-time engine with weekly business hours, exception days
  (schoolday / holiday / recess / closed / optional), site / course /
  group manual pause windows, weekend bitmask, and timezone awareness.
- Academic Responsiveness Score (0-100) — five-term weighted formula
  (compliance / median / critical / pending / trend) with admin-tunable
  weights (auto-normalised to sum 1.0), a four-band mapping (excellent /
  good / regular / critical), and per-term component values persisted on
  the rollup so the card UI can show *why* the score is what it is.
- Two-tier caching: per-request memos + MUC application caches
  (`calendar_effective_day`, `pause_windows_by_course`, plus session
  caches for the WS payloads). `calver` site setting bumps on every
  calendar-affecting write so old cache keys naturally fall out.
- Pause timeline computed on demand — no stored pause table needed.

### Added — events + tasks
- Event observers for the assign + group + course lifecycle that upsert
  the ledger and enqueue rollup recompute.
- Three custom plugin events (`cal_day_updated`, `cal_hours_updated`,
  `cal_pause_updated`) so calendar edits drive cache invalidation +
  rollup re-enqueue.
- Scheduled tasks: drain queue (5 min), recompute pending (hourly),
  recompute trend (daily), recompute site stats (daily), purge calendar
  cache (daily), backfill history (inactive by default), prune audit
  log (daily).
- Drain task uses a dispatcher pattern — fans out `recompute_one` adhoc
  tasks for cluster-wide parallelism.
- Backfill dispatcher fans out `backfill_one_submission` adhoc tasks
  with configurable sub-chunk size.
- Concurrency-safe rollup recompute via Lock API guard.

### Added — course processing gate
- `course_access` helper — `is_processable($courseid)` requires a
  `feedback_tracker` block instance on the course context.
- Admin setting `process_hidden_courses` (default OFF).
- SQL pre-filter for backfill using `processable_course_ids()`.

### Added — web services
- 12 external functions: get_responsiveness, get_pending_submissions,
  get_pause_timeline, get_calendar, save_calendar_day,
  bulk_import_calendar, save_business_hours, save_pause_window,
  delete_pause_window, get_dashboard, get_school_comparison,
  get_grader_priority_list.
- CSV importer with per-line error reporting.

### Added — UI
- React UI built on vendored Preact 10.29.2 + htm 3.1.1.
- Block content: SVG ring gauge, counts row, metrics row,
  paused-today / next-pause strip, 30-day sparkline, refresh button,
  drilldown link, collapsible score-breakdown panel.
- Teacher dashboard with hero KPI tiles, sortable courses table,
  optional site benchmarks section, and Grade Now priority panel.
- Pending grading report with sortable/paginated table, server-side
  filters, client-side search, and pause timeline modal.
- Calendar editor page with days / hours / pauses sections and bulk
  CSV import.
- Group drilldown page with sortable pending-submissions table.
- Recompute audit log viewer, full-reset confirmation page, tools
  landing page.

### Added — admin + access
- Site settings: 5 weights, SLA goal, bucket thresholds, calendar
  behaviour switches, weekend mask, timezone selector,
  grading-during-pause mode, performance knobs, view toggles, Tools
  links.
- 9 capabilities (addinstance / myaddinstance plus viewresponsiveness,
  viewdashboard, viewschoolcomparison, managecalendar,
  managepausewindows, resetdata, viewaudit).
- Group-mode-aware visibility on the block.
- `backfill_active` admin UI toggle.

### Added — privacy
- Full GDPR provider implementing metadata + contextlist + export +
  delete for the ledger table.

### Added — testing
- PHPUnit test classes covering academic-time engine, interval math,
  day-rule resolver, pause lookup, CSV importer, bucket classifier,
  statistics helpers, observer, submission ledger, rollup service,
  pending recomputer, score calculator, privacy provider, drain queue
  task, backfill history task, backfill cursor, course access gate,
  and web service functions.
- Behat features for install check, calendar editor, responsiveness
  block, pending report, and teacher dashboard.
- Plugin generator with helpers for seeding fixtures.

### Added — i18n
- Full EN + PT-BR language packs with complete key parity.
- "Feedback tracker" → "Feedback Flow" branding across user-facing
  strings.

### Supported Moodle range
- Moodle 4.5 through 5.2 (`requires = 2024100700`, `supported = [405, 502]`).

### Notes
- `db/upgrade.php` is a stub — fresh installations only, no upgrade
  path from hypothetical prior versions.
- `db/install.xml` is the single source of truth for the schema.
