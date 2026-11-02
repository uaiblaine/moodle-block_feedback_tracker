# block_feedback_tracker: client-side performance audit

Template: `moodle-dev/docs/client-performance-audit/README.md`. Started 2026-11-01 on `main` (5.3, `7c7b862`,
version 2026100517), by the owner's request, after block_compass's and theme_dimensions' audits.

## Scope

- In scope: the Preact client in `amd/src` (3,110 lines in the views and entry points, 28 components, 178 htm
  templates), the vendored bundle's loading (`js/vendor/`, `local\output\vendor_bundle`), the plain AMD modules
  `pending_table.js` and `bulk_remove.js`, `styles.css`, and the server markup each mount point starts from.
- Out of scope: `spike_react.js` (a smoke-test page), core's code. The forward migration to core React (CLAUDE.md,
  "Forward migration") waits for 4.5 and 5.1 to leave support, so the Preact client is what survives until then.

## Data set and instruments

The owner's production shape (2026-11-01): a teacher sees 10 courses of 4 classes of 50 students; a manager sees
500 courses and 10,000 students; the site counts hundreds of thousands of enrolments and submissions.

Fixture on m503b, category `FTPERF` (`skeleton_ftperf.php`, `seed_ftperf.sql`, `ledger_ftperf.php` in
`moodle-dev/docs/client-performance-audit/block_feedback_tracker-2026-11-01/`):

- 500 courses `FTP000`-`FTP499`, each with the block, 4 separate groups of 50 and five assignments due 49, 35, 21,
  9 and 3 days ago; 10,000 students in 50 cohorts of 200, each cohort taking ten courses in the same class
  (100,000 enrolments); 470,000 submissions (450,000 submitted), 385,000 marked; the pending share and the delay
  follow the course (`cidx % 3`). The ledger and the rollups are built through the plugin's own
  `submission_ledger::upsert_for_cm_user_attempt()` and `rollup_service::recompute_group()`.
- Viewers (password `moodle`): `ftperf_teacher`, editing teacher of `FTP000`-`FTP009`; `ftperf_manager`, enrolled
  with the manager role in all 500.
- Protocol: `profile.php` at 4x CPU, 5 warm loads a line (medians), 1358 px and 390 px emulated; `baseline.sh` in
  the instrument folder runs every scenario and prints the host's load on each line. `mdl fast` state: on (cachejs and cachetemplates on since 2026-10-28), so a bundle swap needs a JS purge.

## Scenarios

| Id | Page | Viewer | Interaction | Widths |
|---|---|---|---|---|
| K0 | `course/view.php?id=FTP000`, vendor bundle blocked (control: no client) | teacher | load | 1358 |
| K1 | the same, first visit of the session (`sessionStorage` cleared) | teacher | load | 1358, 390 (K1p) |
| K2 | the same, a revisit within 15 min (the block's `sessionStorage` cache) | teacher | load | 1358 |
| D1 | `pages/teacher_dashboard.php` | teacher (10 courses) | load | 1358 |
| D2 | `pages/teacher_dashboard.php` | manager (500 courses) | load; a sort press (D2s) | 1358, 390 (D2p) |
| P1 | `pages/pending_report.php?courseid=FTP000` | teacher | load; typing at 150 ms a key (P1t); next page (P1n) | 1358 |
| P2 | the same | manager | load | 1358 |
| P3 | the same | teacher, manager | the To grade / Graded toggle: a press, 10 cycles, a quick back-and-forth | 1358 |
| G1 | `pages/group_drilldown.php` (Class 01 of FTP000) | teacher | a sort press | 1358 |

## Targets

Core Web Vitals "good": TBT ≤ 200 ms, INP ≤ 200 ms, CLS ≤ 0.1, LCP ≤ 2.5 s, at 4x CPU.

## Starting signals (counts on `7c7b862`, `amd/src` without the spike)

`addEventListener` 5 (all in the two plain modules), `IntersectionObserver` 6 (one observer, BlockView),
`setTimeout` 1 (the report's search debounce), `sessionStorage` 6 (the block's cache), `useMemo` 9, `memo(` 0,
layout reads 0, `@keyframes` 1 (`bft-shimmer`, transform only, reduced-motion gated).

## Baseline (phase 0, 2026-11-01)

`phase0.log` in the instrument folder; medians of 5 warm loads at 4x CPU, host load 3.2-5.8. CLS here includes the
dev stack's performance-info footer (see C4 below for the production-like figure). Each page's WS calls are listed
as start+duration (ms) in the log.

| Id | TBT | INP | Forced layout | CLS | LCP | DOM | Plugin WS calls (browser ms) |
|---|---|---|---|---|---|---|---|
| K0 control | 0 | - | 78 | 0.006 | 320 | 2,137 | - |
| K1 block, first visit | 0 | - | 115 | 0.006 | 292 | 2,537 | `get_responsiveness` 30; first card at 748 ms |
| K2 block, revisit | 26 | - | 184 | 0.006 | 288 | 2,537 | none (sessionStorage); first card at 673 ms |
| K1p block, 390 px | 97 | - | 276 | 0 | 344 | 2,533 | `get_responsiveness` 30 |
| D1 dashboard, 10 courses | 0 | - | 0 | 0.274 | 392 | 1,727 | dashboard 135, priority 357, insights 244 (parallel) |
| D2 dashboard, 500 courses | **605** | - | 0 | 0.364 | 432 | **12,028** | dashboard 237, priority 896, insights 989 |
| D2s sort press, 500 | 1,077 | **408** | **653** | 0.277 | 440 | 12,028 | as D2 |
| D2p 500, 390 px | **780** | - | 11 | 0.01 | 336 | 12,029 | as D2 |
| P1 report | 0 | - | 14 | 0.215 | 252 | 2,277 | scopes 136, page 357, days 244, then drafts 33 |
| P1t typing (150 ms a key) | 27 | 32 | 107 | 0.21 | 248 | 1,905 | one page fetch per settled term |
| P1n next page | 18 | 24 | 74 | 0.21 | 236 | 2,277 | one page fetch |
| P2 report, manager | 0 | - | 43 | 0.205 | 260 | 2,278 | scopes 31, page 144, days 261, drafts 135 |
| G1 drill-down sort | 0 | 32 | 15 | 0 | 256 | 1,843 | - |

Server side (`ws_reads.php`, CLI, `ws_reads.log`): the course-scoped services read 7-20 times and take 1-38 ms. The
cross-course ones grow with the viewer's courses:

| Service | Teacher, 10 courses | Manager, 500 courses |
|---|---|---|
| `get_dashboard` | 21 reads, 40 ms, 7.9 KB (cached: 3 reads) | 511 reads, 213 ms, **392 KB** (cached: 3 reads, 101 ms) |
| `get_grader_priority_list` | 17 reads, 14 ms, every call | **507 reads, 470-503 ms, every call** (no cache) |
| `get_insights` | 94 reads, 12 ms (cached: 3) | **4,504 reads, 592 ms** (cached: 3) |

Lifecycle (`memory.log`, `--cycle --repeat=10`, after a forced GC): the report's filter on and off, nodes 12,194 →
12,194, listeners 1,036 → 1,036, heap 24.6 → 24.8 MB; the dashboard's two sort presses, 10,550 → 10,550, 1,029 →
1,029, 24.4 → 24.6 MB. The block's Refresh was not cycled: the block drawer is closed when the probe loads the page.

The To grade / Graded toggle (P3, added at the owner's request; `mode-toggle.log`): a press answers in INP 48 ms
(teacher) and 56 ms (manager) with one `get_graded_submissions` of 51-55 ms (graded rows of FTP000: about 850, 25
a page); 10 toggle cycles leave nodes 12,862 → 12,862, listeners 1,508 → 1,508, heap 24.7 → 24.9 MB.

## Findings (phases 1 and 2)

Two `fleet-reader`s read the four axes (A+C, B+D) before the baseline; each candidate was then measured.
`phase2.sh` switches a mechanism in the page through CSS injected at document start, with a readback that the rule
applied, in interleaved rounds (`phase2.log`, `phase2b.log`).

### Confirmed, ranked by cost

1. **A1 / C — the dashboard renders and lays out every course row** (`DashboardView.js:196`,
   `CoursesTable.js:92-157`). At the manager's 500 courses: 12,028 DOM nodes, load TBT 481-605 ms (780 at 390 px),
   a sort press INP 320-408 ms with 567-653 ms of forced layout. The sort's LoAF: Preact's handler 92 ms, then
   core's `theme_boost` drawers `isSmall()` (a `document` click listener reading `getBoundingClientRect`) pays
   249 ms of forced layout for the 500 moved rows; a scroll on the same page pays ~217 ms in theme_boost_union's
   back-to-top `fadeIn`. Neither listener is the plugin's; both are cheap on a page of normal size. With the rows
   past the 50th `display: none` (Preact still renders all 500): load TBT **94-95 ms**, sort INP **112-120 ms**,
   forced layout **122-124 ms**. The cost is the layout of the rows, not the JavaScript. At 10 courses (the
   teacher) it is 0.
2. **S2 — the cross-course scope costs one read per course, on every call**
   (`dashboard_scope::sql_visibility()`, `classes/local/sla/dashboard_scope.php:142-172`). `scope_reads.php`:
   `visible_course_ids()` 5 reads, then `sql_visibility()` **500 reads, 57 ms** for the manager
   (`group_access::visible_group_ids()` per course), and the result is an `OR` of 500 `(courseid = :cN)` clauses over
   the ledger. `get_grader_priority_list` pays it on every load (507 reads, 470-503 ms server, 690-990 ms in the
   browser), `get_insights` and `get_dashboard` on a cache miss. The dashboard's priority cards arrive last on every
   500-course load.
3. **B3 — the report's fetches have no sequence guard** (`PendingReportView.js:425-441`, `fetchDrafts` 463-473,
   `fetchAcademic` 495-509). Reproduced in the browser with the first response held 2.5 s: "Waiting" pressed, then
   "Attention" 150 ms later; at 1.35 s the table showed Attention's 14 rows, at 3.85 s Waiting's late answer had
   replaced them (25 rows, "Page 1 of 2") under the Attention filter. Wrong data on screen, not a cost.
   `BlockView` (`callIdRef`) and `SchoolComparison` (a ticket) already guard theirs. The To grade / Graded
   toggle has the same race: Graded pressed, then To grade again before the graded answer (held 1.5 s); the late
   answer painted the graded list under the To grade columns, each row offering "Grade", with the counts at 0/0/0.
   And in the ordinary case, for as long as the graded request takes (`handleModeChange()`,
   `PendingReportView.js:551-561`), the table shows the pending rows under the Graded columns (a "Review" action, a
   "Result" band, Graded "—") and the distribution bar reads 0/0/0, because the mode changes before the rows do.
4. **S1 — the parallel calls of a page queue on the session lock.** Every service declares `readonlysession`, which
   does nothing until the site sets `$CFG->enable_read_only_sessions` (off by default; no check or README line in
   the plugin). In every load the three mount calls finish in steps of ~105 ms (P1: 130, 240, 360 ms; the order
   changes between runs) while each one's own work is 2-12 ms (`ws_reads.log`). The gain with the flag on was not
   measured: with it set, `profile.php` timed out on m503b (cause not investigated; the flag was unset again).
5. **B6 — drafts are fetched on a class change in graded mode** (`PendingReportView.js:474-481`), where the drafts
   table is not shown: one wasted `get_pending_submissions` (7 reads, 3 ms) per class change. Small; it rides with
   B3.

### Dropped, each with its number

- **The block (A1-A3, B1, B2, C1-C3).** At the owner's scale (4 classes a course) the block loads with TBT 0, the
  control's CLS (0.006, the theme's own shift), one `get_responsiveness` of 30 ms; the sessionStorage revisit skips
  it. Re-rendering every card per batch and the cache written per batch need tens of groups to cost anything; at 4
  there are two batches. Stale sessionStorage keys (D1 of the B+D read) hold ~5 KB per course visited, per tab.
- **C4 — the pages' CLS.** 0.205-0.364 in phase 0, but most of it is the dev stack's performance-info footer
  (`perfdebug`) pushed down by the mount. With `.performanceinfo` hidden, as in production: D1 0.052, P1 0.068-0.082,
  D2 0.012-0.092 (median ~0.055), all under 0.1. A `min-height: 100vh` reserve on the root added nothing consistent
  (D1 0.009-0.048, P1 0.068-0.077, D2 0.039-0.052).
- **A5, A6, B4 — the report's typing and paging.** INP 32 ms typing at 150 ms a key, 24 ms for the next page; one
  request per settled term.
- **A9, B8 — the drill-down sort.** INP 32 ms (11 rows of a class).
- **D — memory.** Flat over 10 cycles of the report's filter and the dashboard's sort (above). No listener outside
  the two plain modules, which attach once.
- **A7, B9 — the simulator's sliders.** Not measured (no slider driver in the probe); a render of ~40 nodes per
  input, no request.

## Fixes proposed (phase 3), for the owner's decision

1. **Dashboard rows bounded (A1).** Render the first 50 courses of the sorted list and the rest on demand (a "Show
   more" button or pages, owner's choice), keeping the totals over all of them. Expected at 500: load TBT ~95 ms,
   sort INP ~115 ms. Gate: a source test that the table renders a slice, and a probe threshold for D2/D2s. The
   392 KB `get_dashboard` payload stays unless the slicing moves to the server.
2. **Report request guard (B3 + B6).** A ticket per fetch family (page, drafts, academic days) so only the latest
   answer applies, no drafts fetch in graded mode, and a mode change that clears the rows (the skeleton until the
   new mode's answer) so no row is ever drawn under the other mode's columns. Gate: a source test (no JS runner in the plugin) that each
   fetch compares its ticket before setting state, with one mutation gate per guard.
3. **Read-only sessions (S1).** A `core\check` through `block_feedback_tracker_performance_checks()` and a README
   line, block_compass PR 6's shape. Investigate first why the probe times out with the flag on m503b.
4. **Scope at the course ceiling (S2).** Collapse the unrestricted courses into one `IN` list, resolve group
   visibility for all courses in one pass, and cache or share the scope between the three dashboard services.
   Server-side and SQL: tier 3 (matrix, both DB families).

## Fix 1: the dashboard shows 50 courses at a time (branch `perf/dashboard-show-more`, version 2026100518)

The courses table gets the first `DASHBOARD_ROWS_STEP` (50) rows of the sorted list; "Showing N of M courses" and
**Show more** sit under it, a press adds 50 and moves focus to the first new row, the last press removes the button.
A sort reorders every course; the headline figures cover every course. Two strings, `dashboard_showing` and
`dashboard_showmore`.

A/B (`fix1-ab.sh`, `fix1-ab.log`): interleaved rounds swapping only `amd/build/views/DashboardView.min.js` with a JS
and string purge between halves, performance-info footer hidden, medians of 5 at 4x CPU, host load 3.2-4.2. The
readback (`rows`, `more`) proves which build each line ran. The stylesheet was still the previous one in both
halves (the theme sheet was purged afterwards); its new rules lay out one line.

| Scenario (500 courses unless said) | Before (round 1 / 2) | After (round 1 / 2) |
|---|---|---|
| D2 load, TBT | 358 / 409 ms | **0 / 0 ms** |
| D2s sort press, INP | 336 / 328 ms | **64 / 64 ms** |
| D2s forced layout after the press | 568 / 580 ms | 89 / 90 ms |
| D2p 390 px load, TBT | 738 / 716 ms | **4 / 0 ms** |
| DOM nodes | 12,029 | 2,582 |
| CLS | 0.040-0.092 | 0.040-0.055 |
| D1 control, 10 courses: TBT, rows | 0 ms, 10 | 0 ms, 10 |

In the browser at 500 courses: nine presses showed 100 ... 500 rows with focus on rows 51, 101 ... 451, the button
gone at 500; a sort by name kept the 500 rows.

Gates: `tests/lockstep/dashboard_rows_rule_test.php` reads `DashboardView.js` (the table gets the visible slice;
the slice starts at the step, grows by it, and the step is at most 100); mutation gates D1-D3 in
`mutations/gates.conf`. Sweep `--changed main`: 4/4 red on pgsql and on MariaDB (D1-D3 and S1 of the stylesheet), each
on the test its `#   ->` line names. Tier 2 gate, `mdl ci moodle-block_feedback_tracker --branch MOODLE_503_STABLE --php 8.4 --behat` on the final
tree: all steps passed, PHPUnit 923 tests (14,497 assertions), Behat 24/24 scenarios.
