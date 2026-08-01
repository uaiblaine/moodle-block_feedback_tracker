# Code audit — 2026-07-31

Full-tree audit of `block_feedback_tracker` at commit `0c6da47` (release 1.0.35,
version 2026061700). Eight review dimensions were swept independently — security,
web services, privacy/GDPR, coding standards, JavaScript/Preact/AMD,
database/upgrade/cross-DB, Mustache/UI/accessibility, and test coverage — and every
candidate finding was then re-checked by a separate adversarial verifier that opened
the cited file and tried to refute the claim. Findings that survived: 35 unique
(5 were reported by two dimensions each and are merged here), plus 2 more (items 4.9
and 4.10) found afterwards by running the test suite, which the static sweep did not
do — **37 total**.

Every CI gate that can be run locally passes: `phplint`, `phpcs`, `phpdoc`, `validate`,
`savepoints`, `mustache` and `grunt` are all clean, `db/install.xml` validates against
the XMLDB schema, and the PHPUnit suite is green (258 tests, 904 assertions, on both
PHP 8.3/PG16 via CI parity and PHP 8.4 on the m501 stack).

**Nothing in this plan is a CI failure** — every item below is something the gates
cannot see. That is the point: the suite passing is not evidence that item 2.1 is not a
cross-context write IDOR, because no test exercises that gate at all (item 4.1).

---

## 1. The Preact update

Current: Preact **10.29.2** + htm **3.1.1**, concatenated into
`js/vendor/bft-vendor-10.29.2-3.1.1.min.js`.

Latest: Preact **10.29.7** (2026-07-08), htm **3.1.1** — htm is already current, so
only Preact moves.

### What the five patch releases actually contain

| Version | Change | Relevant here? |
|---|---|---|
| 10.29.3 | Error recovery for partially rendered subtrees; `useId` stability under async Suspense; hydrate recovery with null excess DOM children; **memory-leak hotspot guards (#5116)**; **fewer redundant allocations (#5115)** | Memory/allocation fixes: yes. Suspense/hydrate: no. |
| 10.29.4 | Fix hydration-Suspense crash; reverts the 10.29.3 `useId` fix | No |
| 10.29.5 | Compat Suspense hydration children recovery | No |
| 10.29.6 | Reverts #5055 (incompatible with `useSignalEffect`) | No |
| 10.29.7 | Make RTS an optional peer dependency | No (build-time only) |

**Assessment: low urgency, low risk.** There is no CVE, no security fix, and no
breaking change in the range — it is patch-level within the same minor. The plugin
renders client-side into empty mount points and uses neither hydration, Suspense,
`lazy()`, nor signals (`grep` over `amd/src` confirms), so most of the fixed bugs are
unreachable from this code. The genuine benefits are the memory-leak guards and
reduced allocations, which matter mildly on long-lived dashboard pages that re-render
on every filter and sort.

The better argument for going to 10.29.7 specifically is that **it is the settled
state after two reverts** (10.29.4 undid a 10.29.3 fix, 10.29.6 undid #5055). Landing
on .3 or .5 would adopt a change that upstream later withdrew.

One caveat worth knowing: the shim exports `useId`, but no component uses it. If a
component ever adopts `useId`, note that its async-Suspense stability fix was reverted
and remains unfixed upstream in the 10.x line.

### What the bump costs — and why item 4.4 should land first

The version numbers are baked into the **filename**, which is written out
independently in five PHP files:

- `block_feedback_tracker.php:36` (`private const VENDOR_BUNDLE`)
- `pages/teacher_dashboard.php:138`
- `pages/score_simulator.php:75`
- `pages/pending_report.php:143`
- `pages/spike_react.php:45`

Plus `thirdpartylibs.xml` (lines 4, 16, 28 for location; 7, 18 for version) and
`js/vendor/README.md` (filename + four SHA-384 values). That is **10+ coordinated
edits across 8 files**, and missing any one page breaks only that page: the old
filename 404s, `window.bftPreact` is never set, the shim's `need()` throws at module
evaluation, and that page's entire React surface goes blank. No gate catches it —
phpcs and grunt never execute the pages.

**Recommended order:** land the path centralisation (item 4.4) first, then the version
bump becomes one constant plus two docs.

### Steps

1. Centralise the bundle path (item 4.4).
2. Re-download per `js/vendor/README.md`, substituting `10.29.2` → `10.29.7` for the
   `preact.min.js` and `hooks.umd.js` URLs; keep htm at 3.1.1.
3. Verify each component's SHA-384 before concatenating; re-record all four hashes in
   the README (and fix the false claim about where they live — item 4.9).
4. Rebuild the bundle with the same prologue/epilogue: the `var define;` IIFE that
   forces the UMDs onto their global-script branch, the `bft*` aliasing, and the
   deletion of `window.preact`/`preactHooks`/`htm`.
5. Rename to `bft-vendor-10.29.7-3.1.1.min.js`, update the centralised constant and
   `thirdpartylibs.xml`, bump `version.php`, add the `CHANGELOG.md` entry.
6. Smoke-test **every** mounting surface — the block, teacher dashboard, pending
   report, score simulator, and `pages/spike_react.php` (which mounts every shared
   component and is the canonical check). A blank panel means a missed reference.

---

## 2. Priority 0 — security and compliance

### 2.1 Write IDOR: any editing teacher can hijack site-scope pause windows

`classes/external/save_pause_window.php:125-128`

The capability is checked against the context derived from the **caller-supplied**
`scopelevel`/`scopeid` (lines 108-110), but when `id > 0` the function then loads an
arbitrary row by id and overwrites it, never checking the caller against the
**existing** row's stored `contextid`:

```php
$context = self::context_for($scopelevel, $scopeid);   // caller-chosen
require_capability('block/feedback_tracker:managepausewindows', $context);
// ...
$existing = $DB->get_record('block_feedback_tracker_cpause', ['id' => $id], '*', MUST_EXIST);
$record->id = (int) $existing->id;
$DB->update_record('block_feedback_tracker_cpause', $record);   // no ownership check
```

`db/access.php:109-116` grants `managepausewindows` to the **editingteacher**
archetype. So any editing teacher can call the AJAX web service with a site-scope row
id (ids are small sequential integers), `scopelevel='course'` and their own courseid:
the check passes at their own course context, and the row's `scopelevel`, `scopeid`,
`contextid`, `timestart`, `timeend` and `note` are all rewritten. A site-wide SLA pause
created by a manager is silently re-scoped, corrupting effective-hours computation for
every course on the site.

The sibling `delete_pause_window.php:66-69` gets this right — it loads the row first
and derives the context from `$row->contextid`.

**Fix:** when `id > 0`, load the existing row first and
`require_capability(..., \context::instance_by_id((int) $existing->contextid))` in
addition to the check on the new scope's context. Add a PHPUnit test asserting an
editing teacher updating a site-scope row gets `required_capability_exception`.

### 2.2 GDPR erasure failure: declared system-context data is never deleted

`classes/privacy/provider.php:303, 319, 333`

`get_metadata` declares `_cday`/`_chours`/`_cpause` (`usermodified`) and `_log`
(`triggeredby`) as personal data; `get_contexts_for_userid` returns the **system
context** for users appearing in those columns; `get_users_in_context` actively
enumerates them at system context. But all three delete methods hard-filter to
`context_course` and return early otherwise, and `delete_course_data` touches only
`_sub`.

Consequence: a data-protection officer approves a deletion request that includes the
system context — a context the plugin itself put in the list — and **nothing is
deleted**. The userids persist indefinitely.

**Fix:** handle `context_system` in all three delete methods. These are config-audit
columns and are nullable, so anonymise rather than drop rows: `set_field_select` each
of the four columns to `NULL` for the target user(s) (`get_in_or_equal` for the
userlist variant). Cover each branch with a test.

### 2.3 GDPR access failure: `export_user_data` skips the system context

`classes/privacy/provider.php:257-260`

Same root cause, read side: the export loop does `continue` for anything that is not
`context_course`. A subject-access request from an admin or manager who edited the
academic calendar, business hours or pause windows — or who triggered recomputes —
returns an export containing none of it, while the plugin's own metadata declares that
data as held. That is provably incomplete relative to the plugin's own declaration.

**Fix:** add a `context_system` branch exporting the four tables under
`writer::with_context($systemcontext)` with a sensible subcontext path, timestamps via
`transform::datetime()`. Add lang strings for the new paths in both packs.

### 2.4 Cross-group leak: `get_pause_timeline` skips the group gate

`classes/external/get_pause_timeline.php:75-78`

The function derives the course context from the submission row and requires
`viewresponsiveness`, but never calls `group_access::can_see_group()` — the plugin's
declared single decision point, enforced by every other read surface
(`submission_browser`, `responsiveness_payload:111`, `get_academic_days:85-99`,
`get_report_scopes`, `dashboard_scope::sql_visibility`). `$sub->groupid` is fetched but
never checked.

Ledger ids are sequential, so a teacher restricted by SEPARATEGROUPS can enumerate
`submissionid` and read `timesubmitted`, `timegraded`, `waitinghours`,
`effectivehours`, `slabucket` and pause notes for groups hidden from them. The payload
carries no name or userid and the cross-course boundary holds, which is why this is
rated below the two items above — but it is exactly the leak `group_access`' own
docblock warns about.

**Fix:** after `require_capability`, add the `can_see_group` check. Cover with a test
using two separate groups.

### 2.5 Raw exception messages through a triple-stash sink

`pages/calendar_editor.php:147, 175` → `templates/calendar_editor.mustache:66`

Both catch blocks assign `$notice = $e->getMessage()` and the template renders
`{{{text}}}` unescaped. The triple-stash exists for the bulk-import error list, whose
dynamic fragments **are** `s()`-escaped (lines 99-104) — exception messages take the
same raw path with no escaping. `save_calendar_day.php:120` throws
`invalid_parameter_exception('Invalid daytype: ' . $daytype)` carrying a submitted
form value, and with developer debugging on, `moodle_exception` appends debuginfo.

Exploitability today is low (reachable inputs are PARAM-constrained moodleform values,
and it is self-XSS for a `managecalendar` admin), but the capability declares only
`RISK_CONFIG`, not `RISK_XSS`, and the sink violates escape-by-default.

**Fix:** `$notice = s($e->getMessage())` in both catch blocks, or split the context
into an escaped text field plus a separate raw field used only by the bulk path.

---

## 3. Priority 1 — correctness bugs users can see

### 3.1 Drilldown badge shows the raw band slug instead of a localised label

`pages/group_drilldown.php:82` → `templates/drilldown.mustache:84`

`'status' => (string) $s['slabucket']` passes the internal slug straight through as
visible badge text. pt_br users see lowercase English (`critical`), and the documented
relabel mechanism — edit the `band_*` string only — silently does not reach this
surface. The ` d` / ` h` unit suffixes concatenated at lines 74-80 are likewise
untranslated.

**Fix:** map the slug to its localised label via a literal switch (the `band_label`
pattern), keeping the raw slug only for the CSS-class key.

### 3.2 `get_audit_log` applies the course filter after SQL pagination

`classes/external/get_audit_log.php:105-118, 143`

`$total` counts with only the actor filter, rows are fetched with `LIMIT`, and *then*
the courseid filter drops non-matching rows post-JSON-decode. With `courseid > 0` a
page can return zero entries while `total` says 500, matching entries land on
effectively random page numbers, and a client paging to `total` renders mostly empty
pages.

The post-decode filter is a **deliberate, documented tradeoff** — the comment at
lines 101-104 explains the log table carries no `courseid` and that SQL-side filtering
"would require schema and isn't worth it for a <90-day audit window that's rarely
queried with a course filter." That reasoning is sound as far as it goes. What the
comment does not address is the consequence for `total` and the page window, which
appears unintended rather than accepted.

**Fix (pick one):** filter in SQL so `COUNT` and `LIMIT` see the same predicate (add a
`courseid` column); or keep the post-decode filter but fetch unpaged and slice in PHP
when `courseid > 0`, correcting `total` to the filtered count; or drop the parameter
until the schema supports it. Given the author's stated cost/benefit, the middle option
is probably the right size — it is bounded by the same 90-day window the comment relies
on.

### 3.3 Business-days mode silently drops rows with NULL `effectivedays`

`classes/local/sla/submission_browser.php:284-343, 373-418`;
`classes/external/get_grader_priority_list.php:130-148`

Every day-mode predicate compares the nullable `sub.effectivedays` against thresholds.
SQL NULL comparisons are never true, so rows still awaiting the
`backfill_effectivedays` walk fall out of every band while remaining in `total`. The
day-mode "critical" filter therefore hides exactly the oldest, most overdue rows.
Meanwhile the per-row badge treats NULL as pending (`pending_band_days:511-520`,
`bucket::for_effective_days(null)`), so during the backfill window the distribution
bar, the row badges and the totals visibly disagree and the counts do not sum.

**Fix:** `COALESCE(sub.effectivedays, 0)` in all day-mode predicates, matching the
display convention — or fall back to the hour ruler while the backfill-done flag is
unset.

### 3.4 `api.js` `getCalendar` sends parameters the web service does not accept

`amd/src/lib/api.js:192-193` vs `classes/external/get_calendar.php:51-56`

The wrapper posts `{scope, scopeid}`; the service declares two **required** params
`startymd`/`endymd` and no scope at all. Every invocation would throw
`invalid_parameter_exception`. No view imports it today, so nothing is user-visibly
broken — but the first caller gets an opaque toast, and the JSDoc documents a contract
the server never had.

**Fix:** rewrite to `{startymd, endymd}`, correct the JSDoc, rebuild `amd/build` in the
same commit.

### 3.5 Draft badge is invisible on Moodle 5.x

`templates/drilldown.mustache:112`; `amd/src/views/PendingReportView.js:900`

`<span class="badge badge-draft">` has no matching rule — `styles.css:730-748` defines
only the five band variants. Under Bootstrap 5 a bare `.badge` sets `color:#fff` with
no background, so "Draft" renders as white text on a white cell. The Preact twin
`bft-badge-draft` has no rule either.

**Fix:** add `.badge.badge-draft` and `.bft-badge-draft` using a neutral token pair.

### 3.6 Queue row deleted even when the recompute was skipped

`classes/task/recompute_one.php:64-67`

`rollup_service::recompute_group()` returns silently without recomputing when another
worker holds the per-tuple lock (`rollup_service.php:79-82`), yet the queue row is
deleted unconditionally afterwards. An `enqueue()` arriving mid-recompute is wiped by
the same delete. Either way the materialized rollup row stays stale until the next
event touches that tuple — and `pending_recomputer`'s hourly pass only re-enqueues
tuples that still have stale pending rows, so a tuple that raced the delete can serve
outdated scores indefinitely.

**Fix:** have `recompute_group()` report whether it ran and skip the delete when
lock-skipped; replace the delete with a `delete_records_select` bounded by
`timeenqueued <= :started` so mid-recompute enqueues survive.

### 3.7 Check-then-insert races against unique indexes

`classes/local/sla/dirty_queue.php:63`;
`classes/local/sla/submission_ledger.php:137-175`

Both do `get_record()` then `insert_record()` against a unique index
(`uq_course_group`, `uq_cm_user_attempt`) with no duplicate-key tolerance, while the
plugin deliberately parallelises backfill across cron workers via adhoc tasks.
`backfill_one_submission` has no try/catch around its upsert loop, so the losing worker
throws `dml_write_exception` and fails the whole batch. On the observer path core
swallows it into a `debugging()` call — which also fails PHPUnit `--fail-on-warning` if
a test ever provokes it.

**Fix:** catch `dml_write_exception` on insert and fall back to re-fetch-and-update. In
`enqueue()` a duplicate key is success.

### 3.8 `pending_table.js` lacks the idempotency guard

`amd/src/pending_table.js:97-99`

All six other entrypoints guard `init()` with a `window.bft*InitDone` flag; this one
does not, and each call adds another click listener to every `th[data-sort]`. On a
double init one click fires two handlers — the first sorts ascending and toggles the
class, the second reads the toggled class and immediately re-sorts descending — so
sorting appears permanently inverted. Latent today (one call site), but it is the one
entrypoint where a second init corrupts behaviour rather than being a no-op.

**Fix:** add the standard guard; rebuild the minified counterpart in the same commit.

---

## 4. Priority 2 — coverage and maintainability

### 4.1 Seven of seventeen web services have no test, and no gate has a failure-path test

`tests/external/`

Untested: `delete_pause_window`, `get_audit_log`, `get_insights`, `get_pause_timeline`,
`get_school_comparison`, `save_business_hours`, `save_pause_window`.
`services_coverage_test.php` asserts only structural facts (class exists, extends the
base, methods present, capability declared) and never invokes `execute()`.

Apply the mutation check — remove a capability gate and see if exactly one test goes
red — and **all seven fail it: removing any of those gates turns zero tests red.**
That is precisely why 2.1 shipped. All three untested write functions carry capability
gates and fire events. `get_insights` additionally uses a bespoke gate
(`dashboard_scope` empty-scope check instead of `require_capability`), which is the
kind of custom logic that most needs a regression test.

The pattern already exists in the repo — `get_dashboard_test.php:100`,
`get_report_scopes_test.php:223` and `save_calendar_day_test.php:65` all assert
`required_capability_exception`. It simply was not applied to these seven.

**Fix:** one test file per function, success path through
`clean_returnvalue(execute_returns(), ...)` plus one unauthorised-user test each.
Prioritise the three write functions; assert the row is written/deleted and the `cal_*`
event fires via `redirectEvents`.

### 4.2 Six of ten tasks are never executed by a test

The real gaps, in order:

- **`backfill_effectivedays`** — 182 lines of one-time resumable upgrade-backfill
  (config arming, keyset paging past a stored cursor, grouped `UPDATE ... WHERE id IN`
  batches, done-flag flip) with **zero** coverage. A paging or arming bug leaves NULL
  `effectivedays` on upgraded large sites — which is also the trigger condition for
  finding 3.3.
- **`prune_audit_log`** → `recompute_log::prune_older_than()`; there is no
  `tests/local/audit/` directory at all, so the retention-cutoff SQL is untested on
  both drivers.
- **`purge_calendar_cache`** — 77 lines, unexercised.

`recompute_pending`, `recompute_site_stats` and `recompute_trend` are thin wrappers
over services that *are* tested; document them as covered rather than duplicating.

### 4.3 The privacy userlist half is untested

`tests/privacy/provider_test.php:30`

The file imports `approved_userlist` but never calls `delete_data_for_users` or
`get_users_in_context` — the entire `core_userlist_provider` surface. Export is
asserted only via `has_any_data()`, never by content, and there is no system-context
coverage anywhere. That absence is exactly why 2.2 and 2.3 shipped unnoticed.

### 4.4 The vendor bundle path is duplicated across five PHP files

Detailed in section 1. **This is the enabling item for the Preact update** — do it
first.

**Fix:** one public const plus a static helper in
`classes/local/output/bootstrap.php` (e.g.
`bootstrap::require_vendor_bundle(\moodle_page $page)`), called by the block class and
all four pages.

### 4.5 Dead module `responsiveness.js` ships and holds the only stray `Ajax.call`

`amd/src/responsiveness.js:29, 55-58`

Referenced nowhere — no `js_call_amd`, no template require — and its target markup no
longer exists (`grep` for `bft-refresh` across PHP and Mustache returns nothing; the
block's server card is noscript-only and mounts `block_app` instead). It also holds the
only direct `Ajax.call` outside `lib/api.js`, so re-wiring it would bypass api.js's
`Promise.resolve()` adoption and the connectivity-error tagging every other surface
relies on. Its document-level `.bft-refresh` click listener would additionally
intercept the re-roll button that `spike_react.js:123` renders, if both ever loaded
together.

**Fix:** delete the source, its minified output and map in one commit; drop the
orphaned `refreshtext` context key from `responsiveness_card.php`.

### 4.6 Privacy metadata under-declares what the plugin stores and exports

`classes/privacy/provider.php:73-87`

Nine of `block_feedback_tracker_sub`'s 22 fields are declared. Notably `attemptnumber`
**is exported** at line 280 while never being declared — the allowlist and the export
contradict each other. `submissionstatus` and `effectivedays` are stored personal data
that is neither declared nor exported. Core's compliance test only validates lang
strings for declared fields, so this ships silently, but the registry shown to users
and DPOs under-reports what is held.

**Fix:** declare `attemptnumber`, `submissionstatus`, `effectivedays` (ideally the
timestamps too) with lang strings in both packs in alphabetic slots, and add the two
missing fields to the export. Purely derived activity-config mirrors (`timeopens`,
`timecloses`, `timecutoff`, `effectivecalver`, `hasrule`, `iteminstance`) can stay
undeclared if documented as such.

### 4.7 CHANGELOG stops four releases behind

`CHANGELOG.md:8` — newest heading is `[1.0.31] - Unreleased`, while `version.php` ships
`1.0.35` / `2026061700` and `db/upgrade.php` carries steps through `2026060133`. Two of
the missing releases had DB changes. The four entries that do exist are still stamped
"Unreleased" despite having shipped, so the release tags feeding the moodle.org
workflow carry no notes.

**Fix:** backfill 1.0.32-1.0.35 from the git log and upgrade savepoints, replace the
stale "Unreleased" stamps with real dates, and restore same-commit discipline.

### 4.8 `install.xml` VERSION attribute is stale

`db/install.xml:2` still reads `20260523`, predating the five later upgrade steps whose
columns *are* correctly present in the file. Fresh installs and upgraded sites do
converge, so nothing is broken — but the XMLDB editor and schema-comparison tooling key
off this attribute. Bump it opportunistically in the next schema-touching commit.

### 4.9 A hand-built `assign_grades` record is missing core's `penalty` field

`tests/local/sla/observer_test.php:114-128`

Running the suite (not just the static gates) surfaces one notice:

```
Unexpected debugging() call detected.
Debugging: Fields list in snapshot record does not match fields list in
'assign_grades'. Record is missing fields: penalty
```

The test builds `$grade` by hand with seven fields, inserts it, and then passes the
**in-memory object** — never re-read from the database — to
`submission_graded::create_from_grade()`. That calls `add_record_snapshot()`, which
validates the snapshot's field list against the live table schema. Moodle 5.1's
`assign_grades` carries a `penalty` column the object does not have.

It currently lands as a PHPUnit notice rather than a failure, so the suite is green and
CI passes. But `advanced_testcase` treats unexpected `debugging()` calls as a
reportable condition and CI runs with `--fail-on-warning`; this is the exact class of
signal that flips to a hard failure when core tightens it. It is also a forward-compat
warning in its own right — a new core column silently invalidated a hand-built
snapshot, and the next added column will do the same.

**Fix:** re-read the row after insert so the snapshot carries every column:
`$grade = $DB->get_record('assign_grades', ['id' => $grade->id]);` before the
`create_from_grade()` call. (Using mod_assign's own generator would also work and is
more future-proof, but the re-read is the one-line change.)

### 4.10 PHPUnit doc-comment metadata — know about it, do *not* act yet

The suite reports **41 PHPUnit deprecations**, all identical in kind: *"Metadata found
in doc-comment … deprecated and will no longer be supported in PHPUnit 12. Update your
test code to use attributes instead."* — one per test class using `@covers`.

**This is not a plugin defect and should not be "fixed" now.** Moodle 5.1 core itself
uses **zero** PHP attributes across its test suite and 449 files with `@covers`
doc-comments (counted under `public/lib` and `public/mod`). The fleet standard
likewise mandates `@covers` on the class docblock. Migrating this plugin unilaterally
would diverge from both core and the fleet convention while buying nothing — Moodle
ships PHPUnit 11 today.

Record it as an upstream-driven migration to follow when core moves, not as work to
schedule. The one thing worth doing now is item 5's `@coversNothing` correction on
`services_coverage_test`, which is about a wrong target rather than the syntax.

### 4.11 `js/vendor/README.md` points at the wrong place for the bundle hash

`js/vendor/README.md:48-49` states the bundle's SHA-384 "is recorded in
thirdpartylibs.xml" — that file contains no hash element of any kind. The hash itself is
correct (recomputed against the committed bundle and it matches), so integrity
verification works; the documented cross-check location is simply false, and anyone
following the re-assembly instructions during the Preact bump will look there and find
nothing.

**Fix:** say the hash lives in the README, or add an XML comment carrying it next to the
three `<location>` entries.

---

## 5. Priority 3 — accessibility and polish

**Accessibility (each one a real barrier, not a lint warning):**

- `amd/src/pending_table.js:72` — the server-rendered sortable table attaches click
  listeners directly to `<th data-sort>` with no button, tabindex, role or `aria-sort`.
  Keyboard users cannot sort at all and sort state is never announced: a WCAG 2.1.1
  failure on the group drilldown page. The plugin's own `CoursesTable.js SortHeader`
  already implements the correct pattern — mirror it.
- `templates/score_gauge.mustache:33` and `amd/src/components/ScoreGauge.js:56` —
  `aria-label="Responsiveness score …"` hard-coded in English in both copies; the Preact
  one has no i18n prop at all. pt_br screen-reader users hear English on the headline
  metric of every surface.
- `amd/src/views/PendingReportView.js:249` — `aria-sort` sits on the inner `<button>`
  rather than the `<th>`. It is only valid on a `columnheader`, so screen readers ignore
  it entirely. `CoursesTable.js:59` does it correctly.
- `amd/src/components/HeroMetricCard.js:43` — the info dot is a non-focusable `<span>`
  with `title` and `aria-label`. Unreachable by keyboard, and `aria-label` on a generic
  span is ignored by most screen readers, so the explanatory text is effectively
  unavailable to assistive tech. Follow the accessible popover pattern already in
  `PendingReportView.js:271-286`.
- `templates/sparkline.mustache:39` — `aria-label="30-day trend"` hard-coded, and it
  contradicts the card's own 14-day window, so assistive-tech users are told the wrong
  period.

**Correctness polish:**

- `classes/external/get_grader_priority_list.php:195` and
  `classes/local/sla/submission_browser.php:176` build `studentname` by concatenating
  `firstname . ' ' . lastname`, bypassing `fullname()` — so `$CFG->fullnamedisplay`,
  `alternatefullnameformat`, middle names and forced ordering are ignored on every
  student row three web services return. `get_audit_log:125` uses `fullname()`
  correctly, so the plugin is internally inconsistent. The search SQL
  (`submission_browser:245`) will also miss users matched by their display name.
- `classes/external/get_insights.php:67` — the only one of the 17 functions that never
  calls `validate_parameters()`. Nothing exploitable follows (it takes no parameters),
  but a future parameter addition would silently skip validation.
- `classes/external/get_calendar.php:44` — `MAX_SPAN_DAYS = 366` is dead code; only
  `endymd >= startymd` is enforced, so `19700101..99991231` is accepted. Enforce it or
  delete the constant.
- `settings.php:64, 189, 286, 316` — four loops build string ids by concatenation
  (`get_string('settings_' . $key, …)`), against the no-dynamic-string-ids rule. All 38
  derived keys currently resolve in both packs, so nothing is broken; the risk is that
  adding an array entry without both strings produces a runtime notice nothing catches.
  Cheapest mitigation: a lockstep test iterating the same arrays and asserting
  `string_exists` for both suffixes.
- `lib.php:196` — no `db/uninstall.php`. Core's `uninstall_plugin()` cleans config,
  tasks, events and tables, but never `{user_preferences}`, so the two declared
  preferences are orphaned permanently. Everything else the plugin seeds *is* cleaned by
  core, so preferences are the only residue.
- `cli/reset.php:62` — running it with no arguments immediately wipes the sub, group,
  trend, site and queue tables; the only options are `--help` and `--backfill`. The
  sibling `cli/recompute_all.php` offers `--dryrun`. A capability-gated UI path with a
  confirmation form does exist (`pages/reset.php`, `RISK_DATALOSS`), so this is
  defence-in-depth rather than a hole — but a `--confirm` gate costs little.

**Test hygiene:**

- `tests/behat/behat_block_feedback_tracker.php:32` — the file correctly omits the
  `MOODLE_INTERNAL` guard (a guard here would silently abort the entire site-wide Behat
  run with exit code 0), but carries only a bare `phpcs:disable` line with no prose
  explaining why. Add the canonical explanatory note so a future edit does not
  reintroduce it.
- `tests/external/services_coverage_test.php:40` — `@covers ::__construct` targets a
  global function that does not exist. Inert today (CI runs without coverage) but it
  becomes an invalid-target error the moment coverage is enabled. `@coversNothing` fits
  what the test actually does.

---

## 6. Suggested sequencing

| Step | Contents | Why here |
|---|---|---|
| 1 | 2.1 save_pause_window IDOR + its test | Only item where a user can damage other people's data |
| 2 | 2.2 + 2.3 privacy system-context delete/export, with 4.3 tests | Compliance; the two ship together as one provider change |
| 3 | 2.4 group gate, 2.5 escaping | Remaining security items, small and independent |
| 4 | 4.1 web-service tests | Closes the gate-coverage hole that let step 1 ship |
| 5 | 3.1-3.5 user-visible bugs | Ordered by visibility: slug badge, audit pager, day-mode counts, dead wrapper, badge CSS |
| 6 | 3.6 + 3.7 concurrency, with 4.2 backfill tests | Both touch the queue/ledger write path |
| 7 | 4.4 bundle path centralisation | Enabling step for the next one |
| 8 | **Preact 10.29.7** + 4.9 README fix | Section 1 |
| 9 | 4.5-4.9 cleanup, 3.8 guard | Low-risk housekeeping; 4.9 is a one-line test fix |
| 10 | Section 5 accessibility pass | Coherent as one commit; all five items are the same class of fix |

Steps 1-3 are worth doing regardless of what else is scheduled. Step 4 is what prevents
the next occurrence of step 1.

Run `mdl ci moodle-block_feedback_tracker` before pushing any of these, and add
`--branch MOODLE_405_STABLE` for anything version-conditional plus `--db mariadb` for
anything touching SQL (3.3 and 4.2 both qualify).

---

## 7. Test plan — closing the coverage gap

### 7.1 Where coverage actually stands

Mapping every production class to a declared `@covers` target (not to a same-named
file, which produces false positives — `local/calendar/observer.php` looks covered by
`tests/local/sla/observer_test.php` but that file declares `@covers` only for
`local\sla\observer` and `submission_ledger`):

**78 production classes, 39 covered, 39 uncovered — exactly half.**

| Area | Uncovered | Classes |
|---|---|---|
| `classes/task/` | 7 of 10 | backfill_effectivedays, prune_audit_log, purge_calendar_cache, recompute_one, recompute_pending, recompute_site_stats, recompute_trend |
| `classes/external/` | 7 of 17 | delete_pause_window, get_audit_log, get_insights, get_pause_timeline, get_school_comparison, save_business_hours, save_pause_window |
| `classes/local/sla/` | 6 of 15 | dirty_queue, group_access, group_resolver, rule_resolver, submission_browser, submission_status |
| `classes/form/` | 5 of 5 | bulk_import_form, business_hours_form, calendar_day_form, pause_window_form, reset_form |
| `classes/local/calendar/` | 4 of 12 | business_hours_lookup, calendar, effective_day_cache, observer |
| `classes/event/` | 3 of 5 | cal_day_updated, cal_hours_updated, cal_pause_updated |
| `classes/output/` | 3 of 4 | renderer, score_gauge, sparkline |
| `classes/local/` (misc) | 3 | audit/recompute_log, output/bootstrap, payload/responsiveness_payload, score/peer_stats |

Behat: **6 scenarios total** across 5 features. JavaScript: **zero tests** — no runner
configured, no spec files.

### 7.2 The ordering principle

Write the tests that would have caught a **confirmed shipped defect** first. This is not
an abstract preference: every P0 item in sections 2 and 3 sits in an uncovered class,
and the correlation is causal rather than coincidental. `group_access` is the plugin's
single decision point for group visibility and has no test at all — which is precisely
how `get_pause_timeline` shipped without calling it. `save_pause_window` has no test —
which is how a cross-context write IDOR shipped. Coverage last, defects first is the
pattern that produced this list.

So the plan below is tiered by what each test *protects*, not by area convenience.

### 7.3 Tier 0 — regression tests for the five confirmed defects

Each of these should be written so it **fails against the current code** and passes
after the corresponding fix. Write the test first; a regression test that never went red
proves nothing.

| Test file | Pins | Concrete shape |
|---|---|---|
| `tests/external/save_pause_window_test.php` | 2.1 IDOR | Seed a site-scope pause row (`generator::create_pause_window()` defaults to exactly this). Enrol a user as `editingteacher` in course A. Call the WS with that row's `id`, `scopelevel='course'`, `scopeid=<course A>`. Assert `required_capability_exception`. Add the happy path: a manager at system context updates it successfully and `cal_pause_updated` fires. |
| `tests/external/get_pause_timeline_test.php` | 2.4 group leak | Course with `SEPARATEGROUPS`, groups G1/G2, a teacher in G1 only, ledger rows in both (`create_ledger_row(['groupid' => ...])`). Assert the teacher reads G1's submission and is refused G2's. Without the fix the second call returns data. |
| `tests/external/get_audit_log_test.php` | 3.2 pager | Seed more than one page of log rows spanning two courses, request `courseid=<A>` with `perpage` smaller than the unfiltered count. Assert `total` equals the count of *matching* rows and that paging through `total` yields every match exactly once with no empty page. |
| `tests/local/sla/submission_browser_test.php` | 3.3 NULL day bands | Seed ledger rows leaving `effectivedays` NULL — the generator's `create_ledger_row()` already omits that column, so this is the default and the bug reproduces without effort. In business-days mode assert the band counts **sum to `total`**. Today they do not. |
| `tests/task/recompute_one_test.php` | 3.6 queue delete | Enqueue a tuple, make `rollup_service::recompute_group()` take its lock-skip early return (`rollup_service.php:79-82`), run the task, assert the queue row **still exists**. Second case: enqueue again mid-recompute and assert the new enqueue survives. **The obvious way to write this does not work — see 7.3.1.** |

These five are the highest-value test files in the entire plan.

#### 7.3.1 The `recompute_one` lock test needs a production seam, not a held lock

The natural shape — "acquire the tuple's lock in the test, then run the task and assert
it skipped" — **silently does not skip on either CI database**, and would pass green for
the wrong reason. That is worse than having no test, because it certifies a behaviour it
never exercised.

Verified in the 5.1 checkout: `postgres_lock_factory` keeps `$locksbytoken` **static**
(`lib/classes/lock/postgres_lock_factory.php:55`), keyed by `spl_object_id($this->db)`.
On a second acquire of the same token it treats the situation as a hash collision,
increments the counter and **returns a `lock` object** (lines 152-158). `lock_config::get_lock_factory()`
hands back a fresh factory instance per call, so a test holding "the lock" does not block
the task. `mysql_lock_factory` keys `$openlocks` per instance, and a same-session
`GET_LOCK` re-acquire returns 1 — same outcome. Only `db_record_lock_factory` genuinely
denies the second acquire.

The workable shape, which costs about half a day more:

1. Refactor `rollup_service::recompute_group()` to return `bool` (false when lock-skipped)
   and have `recompute_one::execute()` skip the delete on false. **This is the 3.6 fix**,
   so the test and the fix are genuinely coupled and must be scheduled and estimated as
   one unit rather than as "test, then fix".
2. In the test, pin `$CFG->lock_factory = '\core\lock\db_record_lock_factory';`
   (`resetAfterTest()` restores it), take the lock through its own factory instance on the
   tuple's resource key, run the task, assert the queue row survives, release.
3. For the mid-recompute enqueue case, replace the bare delete with
   `delete_records_select` bounded by `timeenqueued <= :started` and assert a row enqueued
   during the recompute is still there afterwards.

### 7.4 Tier 1 — the untested gates and engines

**`tests/local/sla/group_access_test.php`** — the single most important missing file.
Four public methods, four distinct branches, all mechanically testable:

- `NOGROUPS` → `visible_group_ids()` returns `null` (unrestricted).
- `accessallgroups` capability → returns `null` even under SEPARATEGROUPS. Note it
  resolves through `dashboard_scope::can_access_all_groups()`, so the
  `enable_admin_view_all` setting participates — test both settings states.
- `VISIBLEGROUPS` → returns every named group id.
- `SEPARATEGROUPS` → returns only the user's own group ids; a user in no group gets
  `[]` (callers must short-circuit, so pin that it is an empty array and not `null` —
  conflating the two is the whole failure mode this class exists to prevent).
- `can_see_group($c, $u, 0)` → **false** for any restricted user. The "ungrouped is
  admin-only" rule is documented in the class docblock and is exactly the kind of rule
  that a later refactor silently inverts.
- The per-request memo: call, change group mode, call again, assert the memo held; then
  `reset_memo()` and assert it re-reads. Tests that skip this will mysteriously fail
  when reordered.

**`tests/task/backfill_effectivedays_test.php`** — 182 lines of one-time resumable
upgrade-backfill with zero coverage, and the trigger condition for defect 3.3. Cases:
not-armed no-op; a multi-tick run over rows with NULL `effectivedays` asserting the
keyset cursor advances and grouped updates apply; exhaustion flipping the done flag.

**`tests/local/audit/recompute_log_test.php`** — the directory does not exist yet.
`prune_audit_log` delegates here, so the retention-cutoff SQL is untested on both
drivers: assert rows older than the cutoff are deleted and newer ones survive.

**`tests/local/sla/dirty_queue_test.php`** — pin duplicate-enqueue tolerance against the
`uq_course_group` unique index (defect 3.7).

**Remaining external functions** — `delete_pause_window`, `get_insights`,
`get_school_comparison`, `save_business_hours`. Each gets a success path through
`clean_returnvalue(execute_returns(), ...)` plus **one failure-path test per capability
gate**. `get_insights` needs particular attention: it uses a bespoke `dashboard_scope`
empty-scope check instead of `require_capability`, and bespoke gates are exactly what
regression tests are for.

### 7.5 Tier 2 — the rest, by area

- **Events** (`cal_day_updated`, `cal_hours_updated`, `cal_pause_updated`): mirror
  `tests/event/report_viewed_test.php`. The rule worth pinning with a test is that these
  deliberately omit `objecttable` so bulk-import and delete paths can fire them without
  an `objectid` — Moodle throws `coding_exception` unless `objectid` and `objecttable`
  are both present or both absent. Assert `::create()` works from both a single-row and
  a bulk caller, and that each event has its `event_<name>` lang string.
- **Output** (`score_gauge`, `sparkline`, `renderer`): the highest-value assertion is a
  lockstep test pinning `score_gauge::BAND_COLOURS` against `amd/src/lib/bands.js`, so
  drift between the PHP and JS copies breaks a test instead of a page. Verified: the two
  are **currently in lockstep** — six identical values (`excellent #047857`,
  `good #0e7490`, `regular #b45309`, `critical #be4b25`, `pending #475569`,
  `nodata #94a3b8`). Since the JS file is not readable from PHPUnit, the practical form
  is to assert the PHP constant against a literal expected map in the test and add a
  comment on both sides naming the other; the test then fails whenever one side is edited
  alone. `bands.js` also exports `DEFAULT_SCORE_THRESHOLDS` (90/70/40) and
  `bandForScore()` — check whether the PHP band ladder derives from admin settings rather
  than constants, and if so pin the correspondence at default settings. Follow
  `tests/output/responsiveness_card_test.php` for the `export_for_template()` shape.
- **Calendar** (`observer`, `calendar`, `business_hours_lookup`, `effective_day_cache`):
  the calendar observer drives calver bumps and rollup recompute on `cal_*` events and
  needs its own file. `effective_day_cache` should pin the MUC key convention
  (`{calver}_{id}`, no `:`) and that a calver bump makes old keys fall out.
- **`local/output/bootstrap`**: pin the default-ON checkbox trap — only an explicit
  stored `'0'` means off, and `get_config(...) ?: 1` can never turn the toggle off.
  One test, permanently prevents a recurring class of bug.
- **`responsiveness_payload` and `peer_stats`**: the payload is the shared block+WS data
  loader and applies `group_access` internally, so it is worth covering once
  `group_access_test` exists to lean on.

### 7.6 Land shared fixtures first

`tests/generator/lib.php` already provides `seed_default_platform_calendar()`,
`create_calendar_day()`, `create_pause_window()`, `create_ledger_row()` and
`seed_scale_fixture()`. Seven helpers are missing; each pays for itself across four or
more consumers, and landing them as commit 0 is what keeps the per-area effort estimates
from being paid repeatedly:

1. **`create_tracked_course(array $opts = []): array`** — course + `feedback_tracker`
   block on the course context + `course_access::reset_memo()`. The plugin is strict
   opt-in, so *every* DB test needs this three-step dance; today it is copy-pasted.
   Accept a `groupmode` option so `group_access` and `get_pause_timeline` tests can
   request SEPARATEGROUPS directly.
2. **`create_graded_submission(...)`** — wraps the `mod_assign` event dance
   (`require_once` of `locallib.php`, `new \assign(...)`,
   `submission_graded::create_from_grade()`) and **re-reads the grade row from the
   database** so the snapshot carries every column. That re-read is the fix for item 4.9;
   putting it in the generator means no future test can reintroduce the `penalty` notice.
3. **`create_rollup_row(array $overrides = []): int`** — seeds
   `{block_feedback_tracker_group}`. This is a **hard prerequisite**, not a convenience:
   the calendar observer's `enqueue_all_groups()` iterates that table, so with no rows
   seeded an observer test enqueues nothing and **passes vacuously**. Nothing in the repo
   seeds this table today. Also consumed by `recompute_one`, `recompute_trend`,
   `recompute_site_stats`, `peer_stats`, `get_insights` and `get_school_comparison`.
4. **`create_user_in_role(int $courseid, string $roleshortname, ?int $groupid = null)`**
   — enrol plus optional `groups_add_member` in one call. Consumed by roughly sixteen
   failure-path tests.
5. **`deny_capability(string $cap, \context $ctx, string $roleshortname): void`** —
   `assign_capability(..., CAP_PROHIBIT, ..., true)` **plus
   `accesslib_clear_all_caches_for_unit_testing()`**. Forgetting that second call is the
   classic cause of a capability test that passes alone and fails in suite order.
6. **`seed_audit_log(int $count, array $overrides = []): array`** — wraps
   `recompute_log::record(...)` rather than hand-building rows, so the fixture drifts with
   the schema instead of rotting. Consumed by `get_audit_log`, `prune_audit_log` and
   `recompute_log`.
7. **`set_display_unit(string $unit = 'business_days', string $daythresholds = '2,5,10')`**
   — flips `display_time_unit` and `bucket_thresholds_days` **together**. Setting one
   without the other is how a day-mode test silently ends up asserting the hour ruler.

New directories the plan introduces: `tests/local/audit/`, `tests/local/payload/`,
`tests/form/` and `tests/lockstep/`.

**Before writing anything, note that five files the plan touches already exist** and must
be *extended*, never created — a fresh `Write` would clobber real coverage:
`tests/external/get_pending_submissions_test.php` (323 lines),
`tests/external/get_graded_submissions_test.php` (261),
`tests/local/score/responsiveness_calculator_test.php` (353),
`tests/behat/calendar_editor.feature` (36) and
`tests/behat/behat_block_feedback_tracker.php` (98).

One gap belongs to no area and must be scheduled explicitly: **`classes/privacy/provider.php`
counts as "covered"**, but `tests/privacy/provider_test.php` exercises only
`context_course` and has zero userlist coverage — which is exactly audit items 2.2 and
2.3. Roughly 0.75 day, and not optional.

### 7.7 What is not worth a dedicated test

A plan that treats all 39 classes as equally deserving is not a useful plan.

- **`recompute_pending`, `recompute_site_stats`, `recompute_trend`** — thin `execute()`
  wrappers over `pending_recomputer`, `site_stats_service` and `trend_service`, all three
  already tested. A light smoke test each is defensible; a full suite duplicates the
  service tests. Document them as transitively covered.
- **`submission_status`** — a constants holder. Its values are asserted implicitly by
  every SLA read test. A dedicated file adds ceremony, not safety.
- **`classes/form/*` `definition()`** — markup construction is genuinely better covered
  by Behat than by unit tests. What *is* worth unit-testing is `validation()`, which is
  pure logic. Checked: **only 3 of the 5 forms have a `validation()` method at all** —
  `business_hours_form`, `calendar_day_form` and `pause_window_form`. `bulk_import_form`
  and `reset_form` have none, so they get a Behat smoke scenario and no unit test file.
  The three that do are enumerated in 7.7.1 below.
- **`output/renderer`** — mostly one-line `render_from_template()` delegations; the
  renderable `export_for_template()` methods carry the logic and are where the assertions
  belong. Keep a single "the renderer resolves each template without throwing" case
  inside `score_gauge_test` instead of a dedicated file.
- **The three `cal_*` events** — near-identical `event\base` subclasses. One shared
  `tests/event/cal_events_test.php` (~5 cases) rather than three files. The rule worth
  pinning is that all three deliberately omit `objecttable`, plus the `event_<name>` lang
  string in both packs.
- **`score_gauge` / `sparkline` `export_for_template()`** — arithmetic (circumference,
  arc, polyline points). Four and five cases suffice. The real value is the lockstep test.

Realistically this reduces "39 uncovered classes" to roughly **26 files worth writing**,
and a first-pass case count of ~460 down to **~300**.

#### 7.7.1 The three form `validation()` methods, branch by branch

These are pure logic with no `$DB`, so they are cheap, fast and completely
deterministic — the best value-per-line in the whole plan. The branches below were read
off the current code, so the case lists are exhaustive rather than indicative.

**`business_hours_form::validation()`** — `tests/form/business_hours_form_test.php`

- Both `start_$i` and `end_$i` empty → slot skipped, **no error** (the "unused slot" path).
- Exactly one of the two empty → `caleditor_err_badslot` on `slotgroup_$i`.
- `end <= start` → `caleditor_err_badslot`. Test `end == start` explicitly; it is the
  boundary and the operator is `<=`.
- Two overlapping slots → `caleditor_err_overlap`, reported on `slotgroup_0` regardless
  of which pair overlapped. Note the slots are sorted before comparison, so submit them
  **out of order** to prove the sort works.
- Two slots that merely touch (`slots[i][0] == slots[i-1][1]`, e.g. 09:00-12:00 and
  12:00-18:00) → **no error**. The comparison is `<`, not `<=`, so back-to-back slots are
  legal. This is the case a careless refactor breaks.

**`calendar_day_form::validation()`** — `tests/form/calendar_day_form_test.php`

- `daydate` below `19700101` or above `99991231` → `caleditor_err_baddate`.
- A well-formed but non-existent date (`20260230`) → `caleditor_err_baddate` via
  `checkdate()`. This is a separate branch from the range check and needs its own case.
- `daytype` is optional, both times empty → **no error** (full-day optional).
- Exactly one time supplied → `caleditor_err_window_partial` on `endtime`.
- `starttime` failing `TIME_REGEX` → `caleditor_err_badtime` on `starttime`; same for
  `endtime` on its own key. Two cases, because the error lands on different fields.
- `start >= end` → `caleditor_err_window_order`. Test equality explicitly.
- `daytype` **not** optional with times filled in → **no error**; the window is ignored
  entirely. Easy branch to lose in a refactor, and losing it produces spurious errors.

**`pause_window_form::validation()`** — `tests/form/pause_window_form_test.php`

- `scopelevel` of `course` or `group` with `scopeid <= 0` → `caleditor_err_scopeid`.
- `scopelevel` of `site` with `scopeid = 0` → **no error** (site scope legitimately has
  no id). The guard is `in_array($scope, ['course','group'], true)`.
- `timeend != 0` and `timeend <= timestart` → `caleditor_err_endbeforestart`; test
  equality.
- `timeend == 0` → **no error** whatever `timestart` is. Zero means open-ended, and
  treating it as "before start" is the obvious wrong simplification.

### 7.8 Behat additions

Six scenarios is thin, but the fix is not more Behat — it is more PHPUnit plus a smoke
scenario per surface that currently has none: `pages/audit_log.php`,
`pages/group_drilldown.php`, `pages/score_simulator.php`, `pages/reset.php` and
`manage.php`. One scenario each, proving the page loads for an authorised user and is
refused for an unauthorised one.

Keep them thin per the repo rule — no tree-expand, infinite-scroll or drag-drop — and
respect the documented locator traps: confirm dialogs match by **title**, the
`"checkbox"` selector needs a real `<label>`, controls in collapsed panels must be
opened first, and field labels match by substring so lookups need a `"fieldset"` scope.

### 7.9 JavaScript — an honest assessment

There are zero JS tests, and the reason is structural: **Moodle ships Grunt and ESLint
for plugin AMD but no unit-test runner**, so there is no blessed path and no CI hook to
attach to. Standing up Jest against `amd/src` means bringing a dev-dependency and a
config the fleet CI does not know how to run, and `moodle-plugin-ci` will not execute it.

Given that, the recommendation is deliberately narrow rather than "add Jest and test
everything":

The one place where the cost is clearly worth it is **`amd/src/lib/trend.js`**
(`classifySpeed` / `speedLabel`). The trend sign convention — negative percentage means
faster, shown as an up arrow — exists in **three lockstep copies**
(`lib/trend.js`, `lib/format.js::formatTrend`, and
`classes/output/responsiveness_card.php`), and the repo's own notes record that these
were "easy to leave inverted."

**The cheapest real protection needs no JS runner at all: a PHP test that reads the JS
file from disk.** The repo already does exactly this kind of on-disk read —
`tests/external/services_coverage_test.php` does `require(__DIR__ . '/../../db/services.php')`
— so it is in-convention, and it is strictly stronger than duplicating a literal map in
PHP, because a duplicated map drifts silently while a disk read cannot. Put it in
`tests/lockstep/js_php_lockstep_test.php`:

- `file_get_contents(__DIR__ . '/../../amd/src/lib/bands.js')`, regex the six hex values,
  assert equality with `score_gauge::BAND_COLOURS`.
- Then the threshold ladder, which is **not** a PHP constant — worth knowing before
  writing the test: `responsiveness_calculator::parse_thresholds_band()` reads the
  `score_thresholds_band` setting with a `?: '90,70,40'` fallback, and
  `bootstrap::config_bundle()` ships it to JS as `score_thresholds`. Pin the
  correspondence three ways: (a) `unset_config('score_thresholds_band', ...)` then assert
  `parse_thresholds_band()` equals the `DEFAULT_SCORE_THRESHOLDS` parsed out of
  `bands.js`; (b) assert `db/install.php` seeds the same `'90,70,40'` string; (c) assert
  `config_bundle()['score_thresholds']` carries exactly the `excellent|good|regular` keys
  that `bandForScore()` reads.
- Add the trend sign convention to the same file as the next drift detector.

Only if JS logic keeps growing is a real runner worth it, and then Vitest scoped to
`amd/src/lib/*` pure functions — accepting that it runs as its own CI job, not through
`moodle-plugin-ci`, and that component-rendering tests are not worth the setup. Treat
that as opportunistic and deferrable, not part of the core programme.

### 7.10 Effort and sequencing

Tier 0 PRs carry the red test as commit 1 and the fix as commit 2, so review sees the
proof and the cure together.

| PR | Title | Days |
|---|---|---|
| 1 | `test: shared fixture helpers in the block generator` | 0.5 |
| 2 | `fix: pause-window write IDOR and the missing group gate on get_pause_timeline` | 2.5 |
| 3 | `fix: business-days bands no longer drop rows with NULL effectivedays` | 2.5 |
| 4 | `fix: audit log counts what it pages` | 1.5 |
| 5 | `fix: queue rows survive a lock-skipped recompute` | 2.0 |
| 6 | `fix: privacy provider handles the system context it declares` | 1.0 |
| 7 | `test: failure paths for the four remaining capability-gated web services` | 2.0 |
| 8 | `test: sla engines — group_resolver, rule_resolver, submission_browser depth` | 2.0 |
| 9 | `test: calendar engine, business hours lookup, effective-day cache and observer` | 2.5 |
| 10 | `test: payload, peer stats, bootstrap default-ON reads and output renderables` | 3.0 |
| 11 | `test: form validation() branches for the three forms that have one` | 1.0 |
| 12 | `test: behat smoke for the five unguarded pages` | 2.5 |
| 13 | `test: task smoke coverage plus the structural services/tasks gate` | 1.0 |
| 14 | `test: JS runner for amd/src/lib pure functions` — deferrable | 1.5 |

**Total 20-26 dev-days**, of which PRs 1-6 are the risk-carrying core (~10 days) and the
rest is hardening.

A note on that number, because two earlier figures in this document disagreed with it.
Summing the seven area plans independently gives **37.5 dev-days**, which is inflated:
each area separately priced the fixture dance that commit 1 amortises once, and each
counted *cases* rather than files when roughly 40% of the proposed cases are
data-provider rows rather than test methods. Pulling the other way, the areas
**under**-priced the four production refactors (2.1, 3.2, 3.3, 3.6) that the Tier 0 tests
force. An earlier draft of this section said 10-14 days; that was too low because it
assumed the tests could be written without those refactors. 20-26 is the reconciled
figure.

The two least reliable lines are PR 5 — for the lock-factory reason in 7.3.1, where the
test cannot be written without the refactor — and PR 12, since headless Behat flake makes
any estimate there soft.

Note that PRs 2-6 are *also* the fixes' safety net: do not write them after the fixes
land, or they lose their ability to prove the bug existed.

### 7.11 Keeping coverage from regressing

**The bar, stated as a rule rather than a percentage.** A class needs its own test file
with a `@covers` pointing at it if it does any of: (a) checks a capability, (b) emits or
observes an event, (c) builds SQL with a user-influenced `WHERE`, or (d) reads a setting
that has a default. Everything else may be transitively covered — but each exemption
should be *written down in code*, not merely absent. A percentage coverage gate is not
worth wiring here; `moodle-plugin-ci` offers nothing useful to hang one on.

**The cheapest durable guard is one the plugin already half-built.**
`tests/external/services_coverage_test.php` already loads `db/services.php` and
`db/access.php` by `require(__DIR__ . '/../../…')` and asserts structural facts. Three
more assertions turn it from a structural check into a build-time coverage gate:

1. Every declared web-service class has `tests/external/<function>_test.php` and that file
   contains `@covers \block_feedback_tracker\external\<class>`. Registering a service
   without a test then fails the build.
2. Every capability named in `db/services.php` appears somewhere in `tests/` next to
   `required_capability_exception`. **This is the assertion that would have prevented the
   2.1 IDOR from shipping**, and it is about fifteen lines.
3. The same trick for `db/tasks.php` against `tests/task/`, with an explicit
   `TRANSITIVELY_COVERED` allowlist (`recompute_pending`, `recompute_site_stats`,
   `recompute_trend`) so every exemption is a reviewable diff rather than silence.

That converts coverage from a periodic audit finding into a build-time gate, which is the
only version of this that survives contact with a deadline. While editing that file, fix
its `@covers ::__construct` annotation (section 5) — `@coversNothing` is what it means.

Two supporting habits: keep `tests/lockstep/` as the drift detector for every PHP constant
with a JS twin, and keep `mdl ci <repo> --db mariadb` mandatory for any PR touching
`submission_browser`, `get_grader_priority_list` or `recompute_log` — every bug found in
those three so far has been SQL-semantics-shaped.
