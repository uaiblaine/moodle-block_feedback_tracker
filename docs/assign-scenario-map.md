# mod_assign → block_feedback_tracker: Scenario Matrix and Remediation Design (rev. 2)

**Scope:** `/Users/uaiblaine/dev/moodle-block_feedback_tracker` audited against `mod_assign` in Moodle 4.5 (`/Users/uaiblaine/dev/moodle-405/mod/assign`), 5.1 (`/Users/uaiblaine/dev/moodle-501/public/mod/assign`) and 5.3-dev (`/Users/uaiblaine/dev/moodle/public/mod/assign`). Nothing was edited. Core line numbers are the **4.5** checkout unless stated.

**Revision note.** Three adversarial reviews were applied. The single decisive correction: rev. 1's replacement predicate **deleted** the `grade.timemodified >= submission.timemodified` comparison, which caused a freshly-opened resubmission cycle to be born *graded* with a fabricated 0-hour turnaround — strictly worse than the bug it replaced. The comparison is not the defect; applying it to a **single mutable row** is. Rev. 2 scopes it **per cycle**, where the reference (`timesubmitted`) is immutable once a cycle closes. Every other accepted correction is folded in; disagreements are recorded in **Disputed** notes and were re-verified against the checkouts before being rejected.

---

## 0. Ground truth (re-verified, quoted verbatim)

**0.1 — Core's per-user grading status is time-blind.** `assign::get_grading_status()`, `locallib.php:9432-9449` (5.1 `:9592`, 5.3 `:10196`; `mod/assign/classes/event/` and this method are identical across the three):

```php
public function get_grading_status($userid) {
    if ($this->get_instance()->markingworkflow) {
        $flags = $this->get_user_flags($userid, false);
        if (!empty($flags->workflowstate)) {
            return $flags->workflowstate;
        }
        return ASSIGN_MARKING_WORKFLOW_STATE_NOTMARKED;
    } else {
        $attemptnumber = optional_param('attemptnumber', -1, PARAM_INT);
        $grade = $this->get_user_grade($userid, false, $attemptnumber);

        if (!empty($grade) && $grade->grade !== null && $grade->grade >= 0) {
            return ASSIGN_GRADING_STATUS_GRADED;
        } else {
            return ASSIGN_GRADING_STATUS_NOT_GRADED;
        }
    }
}
```

No timestamp comparison, and **no `grader` test**. This is why Moodle 4.5 still shows "Graded" after the student re-saves. `assign::is_graded()` (`locallib.php:5020-5026`) is the same shape.

**0.2 — Core's needs-grading *counter* is a different, time-sensitive predicate.** `assign::count_submissions_need_grading()`, `locallib.php:2515-2548`, quoted in full this time (rev. 1 silently dropped `:2523-2525`):

```php
public function count_submissions_need_grading($currentgroup = null) {
    global $DB;

    if ($this->get_instance()->teamsubmission) {
        // This does not make sense for group assignment because the submission is shared.
        return 0;
    }

    if ($currentgroup === null) {
        $currentgroup = groups_get_activity_group($this->get_course_module(), true);
    }
    list($esql, $params) = get_enrolled_sql($this->get_context(), '', $currentgroup, true);

    $params['assignid'] = $this->get_instance()->id;
    $params['submitted'] = ASSIGN_SUBMISSION_STATUS_SUBMITTED;
    $sqlscalegrade = $this->get_instance()->grade < 0 ? ' OR g.grade = -1' : '';

    $sql = 'SELECT COUNT(s.userid)
               FROM {assign_submission} s
               LEFT JOIN {assign_grades} g ON
                    s.assignment = g.assignment AND
                    s.userid = g.userid AND
                    g.attemptnumber = s.attemptnumber
               JOIN(' . $esql . ') e ON e.id = s.userid
               WHERE
                    s.latest = 1 AND
                    s.assignment = :assignid AND
                    s.timemodified IS NOT NULL AND
                    s.status = :submitted AND
                    (s.timemodified >= g.timemodified OR g.timemodified IS NULL OR g.grade IS NULL '
                        . $sqlscalegrade . ')';

    return $DB->count_records_sql($sql, $params);
}
```

**Corrected version claim:** the predicate is *semantically* identical on 5.1/5.3 but **not textually**. 4.5 uses the literal `' OR g.grade = -1'` (`:2530`); 5.1 `:2621` and 5.3 `:2666` use `' OR g.grade = ' . ASSIGN_GRADE_NOT_SET`, the clause order differs (`assignment/status/latest/timemodified`), and the body moved into `count_submissions_need_grading_with_groups()` (5.1 `:2612`, 5.3 `:2657`). **`count_submissions_need_grading()` still returns 0 for teams on 5.1 and 5.3** (`moodle-501/public/mod/assign/locallib.php:2589-2594`, with an explicit comment); only `..._with_groups()` counts teams, via `COUNT(DISTINCT s1.groupid) ... WHERE s1.userid = 0` at `:2650-2658`.

**Core is internally split.** After a post-grading student edit: every per-user surface says *Graded*; the counter and the "Requires grading" filter (`gradingtable.php:310-322`) say *needs grading*. The only presentational reconciliation is one badge — and it is **workflow-gated**, `gradingtable.php:1283-1289`:

```php
// Add status of "grading" if markflow is not enabled.
if (!$instance->markingworkflow) {
    if ($row->grade !== null && $row->grade >= 0) {
        if ($row->timemarked < $row->timesubmitted) {
            $submissioninfo .= $this->output->container(get_string('gradedfollowupsubmit', 'assign'), 'gradingreminder');
```

(`$row->timemarked` is `g.timemodified as timemarked`, `gradingtable.php:149`.) There is **no DB column, no flag and no event** in mod_assign for "edited after grading".

**0.3 — The plugin's flipping comparison.** `classes/local/sla/submission_ledger.php:107-124`:

```php
$timesubmitted = (int) $submission->timemodified;
$timegraded = null;
...
if (
    $grade
    && (int) $grade->timemodified > 0
    && (int) $grade->timemodified >= $timesubmitted     // <-- line 119: the flip
    && $grade->grade !== null
    && (float) $grade->grade >= 0
) {
    $timegraded = (int) $grade->timemodified;
}
```

Line 152 writes `'timegraded' => $timegraded` (NULL) over the stored value; line 151 writes `'timesubmitted' => $timesubmitted` (the re-save time) over the original hand-in; `$DB->update_record(...)` at line 171 commits both. There is no history table.

**Corrected:** the dirty-queue reason at `:181-185` is **conditional** — `$timegraded !== null ? REASON_GRADE : REASON_SUBMISSION` — so in B2 it *does* degrade to `REASON_SUBMISSION`, but the statement is scenario-specific, not unconditional. And `{block_feedback_tracker_queue}.reason` is the dirty queue, **not** the audit log; the audit log is `{block_feedback_tracker_log}` via `classes/local/audit/recompute_log.php`, which this code path never writes at all. Rev. 1 conflated the two.

**0.4 — Core auto-creates grade rows that are not gradings.** `assign::get_user_grade($userid, true, ...)`, `locallib.php:3989-4011`:

```php
// If we are "auto-creating" a grade - and there is a submission
// the new grade should not have a more recent timemodified value
// than the submission.
if ($submission) {
    $grade->timemodified = $submission->timemodified;
} else {
    $grade->timemodified = $grade->timecreated;
}
$grade->grade = -1;
// Do not set the grader id here as it would be the admin users which is incorrect.
$grade->grader = -1;
```

**Corrected:** `grade = -1` alone is a sufficient discriminator, because `get_grading_status()` itself uses only `grade >= 0`. `grader > 0` is **not** a safe additional test and rev. 1's use of it is withdrawn:

- `{assign_grades}.grader` is `NOTNULL DEFAULT 0` (`mod/assign/db/install.xml:84`), so 0 is a legal stored value.
- Restore maps it: `restore_assign_stepslib.php:232` — `$data->grader = $this->get_mappingid('user', $data->grader);` — and `restore_structure_step.class.php:210-213` returns `$ifnotfound` (default `false` → 0) when the grader is not in the mapping. **Genuinely graded, restored submissions can carry `grader = 0`**, which `grader > 0` would re-open as pending forever — the exact failure class this work exists to remove.
- Converse: `revert_to_draft()` sets `$grade->grader = $USER->id;` with `grade` still `-1` (`locallib.php:8347-8349`), so `grader > 0` would misread a *revert* as a response.

`grader` is therefore used nowhere in the final predicate.

**0.5 — Where the events are, and are not.** `mod/assign/classes/event/` is **byte-identical across 4.5 / 5.1 / 5.3** (`diff -rq` empty in both directions) — **35 files each**, not 36. Every version difference is *where core triggers*, never the payload shape. The event-free mutations: `add_attempt()` (`locallib.php:9076-9135`, no `::trigger()` in the body), the draft path of `save_submission()`, `assign_grade_item_update()` / `assign_update_grades()` in `lib.php`, `reset_userdata()` (`locallib.php:1250-1267`) and the `mod_assign_set_user_flags` WS (`externallib.php:961`).

**0.6 — Observers are synchronous, run inside the caller's transaction, and their exceptions are swallowed.** `lib/classes/event/manager.php:60-77` — `dispatch()` appends to `self::$buffer` and calls `process_buffers()` **immediately**; only `self::$extbuffer` (external/legacy log stores) waits on `!$DB->is_transaction_started()` (`:110`). Plugin observers default to `internal = true` (`manager.php:274-275`), so they always run in-line. Exceptions are caught:

```php
try {
    call_user_func($observer->callable, $event);
} catch (\Exception $e) {
    // Observers are notified before installation and upgrade, this may throw errors.
    if (empty($CFG->upgraderunning)) {
        debugging("Exception encountered in event observer '$observer->callable': ".$e->getMessage(), ...);
    }
}
```
(`manager.php:152-161`.)

Consequence for FIX-13: a `dml_write_exception` out of the observer does **not** by itself abort the teacher's save. The real hazard is narrower and worse — `moodle_database::query_end()` (`lib/dml/moodle_database.php:472-504`) **throws without rolling back**, so on PostgreSQL a failed INSERT inside an open transaction leaves the connection's transaction aborted and every subsequent statement (including the recovery `SELECT`) fails. Rev. 1's blanket catch-and-retry was unsafe; see FIX-13.

**Disputed (rev. 1, M2a).** "An exception out of the observer aborts the caller's transaction, not just the observer" — false; `manager.php:152-161` catches `\Exception`. The correct statement is the PostgreSQL connection-poisoning one above, and the original document's I3 wording ("aborting the teacher's grade save") is corrected accordingly.

---

## 1. Verdict index

| ID | Scenario | Verdict | Fix |
|---|---|---|---|
| A1 | First submission, drafts OFF, online text | OK (by luck) | FIX-1 |
| A2 | First submission, drafts OFF, file | OK (by luck) | FIX-1 |
| A3 | First submission, drafts OFF, both plugins | OK | FIX-1 |
| A4 | Drafts ON, student saves draft | MISSED-no-event / wrong-row | FIX-1, FIX-6.1 |
| A5 | Drafts ON, student clicks Submit | OK | — |
| A6 | Teacher submits on student's behalf | OK | — |
| A7 | Offline assignment (no submission plugins) | MISSED-no-event (benign) | FIX-9 note |
| A8 | Teacher opens grading page pre-submission | OK | — |
| B1 | Student edits submitted, ungraded | OK-but-clock-reset | FIX-3 |
| B2 | **Student edits already-GRADED (the bug)** | **FALSE-POSITIVE-pending + data loss** | FIX-2, FIX-3, FIX-4, FIX-5 |
| B3 | Student edits a draft (drafts ON) | MISSED-no-event | FIX-6.1 |
| B4 | Drafts ON, edit after submit | OK (core blocks) | — |
| C1 | Teacher grades, numeric | OK | — |
| C2 | Teacher re-grades | OK-but-measurement-moves | FIX-2 |
| C3 | Quick grading | OK | — |
| C4 | Feedback only, no mark (numeric assign) | OK (matches core status) | — |
| C5 | Grade = 0 | OK | — |
| C6 | Scale grade chosen | OK | — |
| C7 | Scale grade "not set" | OK | — |
| C8 | Grade type "None" (`assign.grade = 0`) | FALSE-POSITIVE-pending, permanent | FIX-12 |
| C9 | Teacher clears the grade (assign UI) | OK (genuine un-grading) | — |
| C10 | Gradebook override / lock | MISSED-no-event → STALE-row | FIX-9 (R2) only |
| C11 | Gradebook grade deleted | MISSED-no-event → STALE-row | FIX-6.9, FIX-9 |
| C12 | Grade rescale | MISSED-no-event → STALE values | (unreconcilable; documented) |
| C13 | Grading a non-latest attempt | MISSED-no-event → FALSE-POSITIVE-pending | FIX-5, FIX-9 |
| C14 | Blind marking, graded before reveal | MISSED-no-event → FALSE-POSITIVE-pending (whole assign) | FIX-6.7, FIX-9 |
| C15 | Identities revealed | MISSED-no-event | FIX-6.7 |
| C16 | Marking anonymous (**4.5+**) | OK | — |
| C17 | 5.3 multi-marker, partial marks | MISSED-no-event (correct outcome) | — |
| C18 | 5.3 multi-marker, all marks in | OK | — |
| C19 | 5.3 multimark method `manual` | MISSED-no-event | FIX-9 |
| D1 | Workflow: grade saved, state `inmarking` | FALSE-NEGATIVE-graded | FIX-2, FIX-7 |
| D2 | Workflow: `readyforrelease` | FALSE-NEGATIVE-graded | FIX-2, FIX-7 |
| D3 | Workflow: `released` | MISSED-no-event (clock already stopped) | FIX-6.5 |
| D4 | Batch workflow state, no grade | OK (no mark) | — |
| D5 | Workflow regression `released` → `inmarking` | MISSED-no-event | FIX-6.5 |
| D6 | Marker allocation | MISSED-no-event (harmless) | FIX-6.6 (optional) |
| E1 | Revert to draft | OK | — |
| E2 | Teacher removes submission | OK | — |
| E3 | Student removes own submission | OK | — |
| E4/E5 | Lock / unlock submissions | MISSED-no-event (harmless) | FIX-6.6 (optional) |
| E6 | Allow another attempt (manual) | MISSED-no-event → STALE + FALSE-POSITIVE-pending | FIX-5, FIX-9 |
| E7 | Automatic reopen after grading | MISSED-no-event → STALE-row | FIX-5, FIX-9 |
| E8 | Untilpass reopen | MISSED-no-event → STALE-row | FIX-5, FIX-9 |
| E9 | Student copies previous attempt | MISSED-no-event | FIX-6.3 |
| E10 | Student submits the new attempt | OK (drafts OFF) / MISSED (drafts ON) | FIX-6.1, FIX-6.3 |
| E11 | Teacher grades the new attempt | OK | — |
| F1/F2 | Extension granted / revoked | MISSED-no-event + structurally blind | FIX-6.8, FIX-10 |
| F3/F4 | User override created / updated / deleted | MISSED-no-event → STALE-row | FIX-6.4 |
| F5 | Group override created / updated / deleted | OK-but-mis-targeted | FIX-6.4 note |
| F6 | Assign due date / cutoff changed | MISSED-no-event → STALE-row | FIX-6.10, FIX-9 (R6) |
| G1 | Team: one member submits | FALSE-POSITIVE-pending (phantom userid 0) + total blindness | FIX-8 |
| G2 | Team: all members must submit | same as G1, plus members with no submission row | FIX-8 |
| G3 | Team graded with "apply to all" | STALE-row (phantom never closes) | FIX-8 |
| G4 | Team graded, one member only | STALE-row | FIX-8 |
| G5 | Ungrouped member (default group) | FALSE-POSITIVE-pending | FIX-8 |
| G6 | Member changes group after submitting | OK | — |
| G7 | Team attempt reopened | MISSED-no-event | FIX-9 |
| H1 | Student unenrolled | STALE-row → FALSE-POSITIVE-pending | FIX-6.11, FIX-9 (R5) |
| H2 | Student suspended | STALE-row → FALSE-POSITIVE-pending | FIX-6.11, FIX-9 (R5) |
| H3 | User deleted | STALE-row | FIX-6.12 |
| H4/H5 | Group deleted / membership changed | OK | — |
| H6 | Course module deleted | OK | — |
| H7 | Course module hidden | MISSED-no-event → FALSE-POSITIVE-pending | FIX-6.10 |
| H8 | Course module moved to another course | MISSED-no-event → STALE-row | FIX-6.10 |
| H9 | Course hidden | MISSED-no-event → STALE-row | FIX-9 |
| H10 | Course reset | MISSED-no-event → STALE orphans (grades survive by default) | FIX-9 (R4) |
| H11 | Course deleted | OK | — |
| H12 | Course restored from backup | MISSED-no-event | FIX-9 (R1) |
| H13 | Block instance added / removed | MISSED-no-event → STALE-row | FIX-9 |
| I1 | `g.timemodified == s.timemodified` | Divergence from core's counter | FIX-2 (cycle-scoped; documented) |
| I2 | Submitter holds `mod/assign:grade` | OK-by-design, memo hazard | FIX-9 note |
| I3 | Concurrent writers | `dml_write_exception` out of the observer | FIX-13 |
| I4 | Plugin installed mid-course (backfill) | Re-derives every row with the broken rule | FIX-2, FIX-3, FIX-8 |

---

## 2. The matrix

### Family A — First submission

**A1 — First submission, `submissiondrafts = 0`, online text.**
Core: `{assign_submission}` (`attemptnumber = 0`, `latest = 1`) → `status = 'submitted'`, `timemodified = T1` (`save_submission` sets status at `locallib.php:7665-7669`, then `update_submission($submission, $userid, true, ...)` at `:7697`, which does `if ($updatetime) { $submission->timemodified = time(); }` at `:6250-6252`). No `{assign_grades}` row. Displays *Submitted for grading* / *Not graded*; counter +1.
Events, in order: `\assignsubmission_onlinetext\event\assessable_uploaded` → `..\submission_created` → (`statement_accepted` if ticked) → `\mod_assign\event\assessable_submitted` (`:7732`).
Plugin today: `submission_created` is registered (`db/events.php:42-45`) and hits `observer::submission_changed`, which reads `$event->objectid` (`observer.php:51`) — but that objectid is the **`{assignsubmission_onlinetext}.id`** (`submission/onlinetext/locallib.php:306`, after `unset($params['objectid']);` at `:276`; the real id is in `other['submissionid']`, `:279`). The `{assign_submission}` lookup at `observer.php:62-66` either misses or hits an unrelated row. The row is written correctly anyway because `assessable_submitted` follows with the real submission id (`classes/event/assessable_submitted.php:59`).
**Verdict: OK — by luck. Fix: FIX-1.**

**A2** — identical with `\assignsubmission_file\event\submission_created` (`submission/file/locallib.php:301`; update branch `:288`). **OK — by luck. FIX-1.**

**A3 — both plugins enabled.** Five events, four reaching `submission_changed`, three with a wrong-table objectid. Four upserts, four `dirty_queue::enqueue` calls. **OK final state, wasteful and dangerous path. FIX-1.**

**A4 — `submissiondrafts = 1`, student saves a draft.** `status = 'draft'`, `timemodified = T0`, no grade row, not in the counter. **`assessable_submitted` does NOT fire** — `locallib.php:7729-7733` guards it with `if (!$instance->submissiondrafts)`. The only event received carries the wrong objectid; worst case an unrelated submission id collides and the plugin upserts a ledger row for **a different student** against **this** cmid. **MISSED-no-event / wrong-row. FIX-1, FIX-6.1.**

**A5 — drafts ON, student clicks Submit.** `status = 'submitted'`, `timemodified` bumped (`process_submit_for_grading` → `update_submission(..., true, ...)`, `:6885`). Events: `statement_accepted` (4.5 `:6897`; 5.1/5.3 hoisted before the backend call, `:7022`) → `assessable_submitted` (`:6902`; 5.1 `:7067` inside the new `submit_submission()`; 5.3 `:7334`). Objectid **is** the submission id. **OK.**

**A6 — teacher submits on the student's behalf.** Same events; `relateduserid` set because `$submission->userid != $USER->id`. **OK.**

**A7 — offline assignment.** No student-created submission row. `get_user_grade($userid, true)` creates the grade row and `get_user_submission($userid, true)` creates a `status = 'new'`, `latest = 1` row. Only `submission_graded` fires; the ledger records `submissionstatus = 'new'`, excluded from every SLA surface by policy (`classes/local/sla/submission_status.php:59`). **MISSED-no-event for the "work arrived" side; benign — document the exclusion (FIX-9 note).**

**A8 — teacher opens the grading page before submission.** Auto-created grade row, `grade = -1`, `grader = -1`, `timemodified = $submission->timemodified` (§0.4). No event. Had the plugin run, `(float) $grade->grade >= 0` correctly rejects it. **OK — the `grade >= 0` clause at `submission_ledger.php:121` is load-bearing and survives the rewrite unchanged.**

---

### Family B — Student edits

**B1 — student edits an already-submitted, NOT-yet-graded submission (drafts OFF).**
Core: `status` stays `'submitted'`, `timemodified` T1 → T2. Re-editing is permitted — `submissions_open()` blocks it only when `$this->get_instance()->submissiondrafts && $submission->status == ASSIGN_SUBMISSION_STATUS_SUBMITTED` (`locallib.php:6334-6337`). Events: `assessable_uploaded` → `\assignsubmission_*\event\submission_updated` (**not registered**) → `assessable_submitted` (drafts OFF, fires again).
Plugin today: `timesubmitted` overwritten T1 → T2 (`submission_ledger.php:107`, `:151`); the SLA clock restarts, `bucket::for_effective(0.0)` → `excellent`, and the row drops to the bottom of `get_grader_priority_list`'s `ORDER BY sub.effectivehours DESC`.
**OK on pending/graded, wrong on the measurement. FIX-3.**

**B2 — student edits an already-GRADED submission — THE REPORTED BUG.**

- **Preconditions:** `submissiondrafts = 0`, no lock, `maxattempts = 1`, no marking workflow.
- **Trace:** (T1) student submits. (T2 > T1) teacher grades 8.0. (T3 > T2) student re-opens *Edit submission*, saves.
- **Core tables:** `{assign_submission}` — `status = 'submitted'` (re-set at `locallib.php:7668`), `attemptnumber = 0`, `latest = 1`, `timemodified = T3`. `{assign_grades}` — **untouched**: `grade = 8.0`, `grader = <teacher>`, `timemodified = T2`, `attemptnumber = 0`.
- **Core displays:** submission-status table (student *and* teacher) → **Graded** (§0.1). Grading table → **"Graded - resubmitted"** (`gradingtable.php:1283-1286`; the badge is suppressed entirely under marking workflow). Mark and marked-date columns still render (`:999-1040`, `:1065-1077`). "Needs grading" counter and *Requires grading* filter → **+1 again** (§0.2).
- **Events:** `assessable_uploaded` → `\assignsubmission_onlinetext\event\submission_updated` (**not registered**) → `assessable_submitted` (registered).
- **Plugin today:** `observer::submission_changed` → `upsert_for_cm_user_attempt(cmid, userid, 0)`. At `submission_ledger.php:119`, `$grade->timemodified (T2) >= $timesubmitted (T3)` is **false** → `$timegraded` keeps its line-108 `null`. Line 152 writes NULL over T2; line 151 writes T3 over T1; `$upperbound = $now` (`:127`) so `waitinghours ≈ 0`, `effectivehours ≈ 0`, `effectivedays ≈ 0`, `slabucket = 'excellent'` (`:164`); enqueued as `REASON_SUBMISSION` (`:181-185`, the `timegraded === null` arm).
- **Consequences on every read surface** (all key off `timegraded IS NULL AND submissionstatus = 'submitted'`): `rollup_service.php:137-138` pending +1; `submission_browser.php:261-268` the row enters the Pending tab and vanishes from the Graded tab; `get_grader_priority_list.php:125` it joins the cross-course urgent list; `get_dashboard` totals move; `responsiveness_card` / `GroupCard.js` Aguardando increments; `pending_recomputer.php:68-69` re-ages it hourly forever.
- **Historical destruction:** `rollup_service.php:188-189` (`timegraded IS NOT NULL AND timegraded >= :cutoff`) loses the data point → `numgraded30d`, `compliance_pct`, `median_eff_h`, `p90_eff_h`, `max_eff_h`, `median_raw_h`, `p90_raw_h`, `max_raw_h` recomputed without it; `rollup_service.php:346-348` → `trend_pct_30d` swings or nulls; `responsiveness_calculator.php:242-244` loses a momentum point; `get_academic_days.php:251-253` loses the day's point; `site_stats_service.php:50-51` and `trend_service.php:52-53` lose it for future days only.
- **Perverse headline:** `rollup_service.php:240-248` merges pending rows into `cur_median_eff_h` / `cur_median_raw_h` / `cur_median_eff_days` / `cur_median_perc_days`, so a real 24 h value is replaced by a ~0 h one — displayed turnaround gets **faster** exactly when the backlog gets worse. `responsiveness_calculator.php:118-124` forces compliance and median terms to 1.0 when `numgraded30d` hits 0, so the score can **rise**.
- **Sparkline / school comparison never heal:** `{block_feedback_tracker_trend}` and `{block_feedback_tracker_site}` are only ever written for *yesterday*, so a materialised day keeps counting a grading the live rollup has discarded. Two panels of one card disagree permanently.
- **Irrecoverable:** when the teacher re-saves at T4, `effectivehours = academic(T3 → T4)`. Nothing restores T1 or T2.
- **Desired:** the closed measurement (T1 → T2) is preserved verbatim; a **new pending** observation opens with its clock starting at T3; core's "Graded - resubmitted" state is representable.
- **Verdict: FALSE-POSITIVE-pending plus irreversible destruction of a completed measurement.**
- **Fix:** FIX-2 (cycle-scoped predicate), FIX-3 (sticky `timesubmitted`), FIX-4 (`cycle`/`timemarked`/`iscurrent`/`gradestate`), FIX-5 (read paths).

**B3 — student edits a draft (drafts ON).** Only `assessable_uploaded` → `submission_updated`, neither registered. **MISSED-no-event. FIX-6.1.**

**B4 — drafts ON, edit after submitting.** Core refuses (`locallib.php:6334-6337`). **OK.**

---

### Family C — Grading

**C1 — teacher grades, numeric, no workflow.** `{assign_grades}` updated at `locallib.php:3011`; gradebook updated; `{assign_submission}` untouched. `\mod_assign\event\submission_graded` fires at `:3030`, gated by `if ($this->gradebook_item_update(null, $grade))` at `:3029`. No `->other` payload.
Plugin re-reads the grade row by id — safe because the write at `:3011` precedes the trigger **on the same connection**, not because dispatch is deferred (§0.6 corrects rev. 1's rationale). Upserts, then queues a deduplicated `recompute_one` adhoc task (`observer.php:125-130`). **OK.**

**C2 — teacher re-grades.** `timemodified` T2 → T4, event fires again; the plugin moves `timegraded` to T4, so the recorded response becomes T1 → T4. An SLA measures the **first** response. **OK on pending/graded, wrong measurement. FIX-2 (first-response-wins).**

**C3 — quick grading.** `process_save_quick_grades` → `update_grade()` → same single event; also `workflow_state_updated` when the workflow column changed (`:7344`). **OK.**

**C4 — grading form saved with feedback only, no mark (numeric assign).** The auto-created row keeps `grade = -1` but gains `grader = $USER->id` and `timemodified = now`; `update_grade()` runs (guard at `:8719-8725` satisfied by `$feedbackmodified`), so **`submission_graded` fires with `grade = -1`**. Core says *Not graded* (`-1` fails `>= 0`) but the **counter** drops the student (fresh `g.timemodified`, non-null `g.grade`, `-1` branch is scale-only) — a genuine core inconsistency. The plugin stays pending, matching the user-facing status. **OK.**

**C5 — grade = 0.** `0.0 >= 0` passes. **OK.**
**C6 — scale item chosen.** Stored as the 1-based index. **OK.**
**C7 — scale "not set".** Stored as `-1`; core's counter re-admits it (`$sqlscalegrade`, `:2530`) and the status says *Not graded*. **OK.**

**C8 — grade type "None" (`assign.grade = 0`).**
Core: no numeric mark possible. The teacher can save feedback; the grade row stays `grade = -1`. Moodle 5.x suppresses the needs-grading badge entirely via the global `is_gradable()` (`classes/courseformat/overview.php:88`), which returns true only for `GRADE_TYPE_VALUE` / `GRADE_TYPE_SCALE` items (`lib/gradelib.php:320-335`). **`is_gradable()` exists on 4.5 too** (`moodle-405/lib/gradelib.php:320`) and mod_assign already uses it internally (`locallib.php:8810`).
Plugin today: `(float) $grade->grade >= 0` can **never** be satisfied. Every submission on a feedback-only assignment is pending **forever**, re-aged hourly, poisoning `pending`, `critical`, the priority list, the score and every median. Zero `is_gradable()` gates anywhere in the plugin.
**FALSE-POSITIVE-pending, permanent, unbounded. FIX-12.**
*(Rev. 1 cited `block_grade_me`'s `AND a.grade <> 0`; that repo is not present under `~/dev` and the citation is withdrawn in favour of core's own `is_gradable()`.)*

**C9 — teacher clears the grade in the assign UI.** `unformat_float('')` → `null`, `grade_floatval(null)` → `null`; the row is kept with `grade = NULL`, `timemodified = now`; `update_grade()` runs because `$originalgrade !== null && $originalgrade != -1` (`:8719-8725`). Event fires. Core: *Not graded*, counter re-admits (`g.grade IS NULL`). Plugin: `$grade->grade !== null` fails → pending. **OK — and unlike B2 this is a *genuine* un-grading, so losing the measurement is correct.**

**C10 — gradebook edit / override / lock.**
Core: `{grade_grades}.overridden` (or `locked`) is set; `{assign_grades}` is never written back. `assign::grading_disabled()` (`:7781`) becomes true, `gradebook_item_update()` returns false at `:6152-6154`, and **all future `submission_graded` events for that user are suppressed**.
**Corrected:** the proposed `\core\event\user_graded` observer **cannot see this transition**. `user_graded` is triggered from `grade_item` only when the final grade **value** changed — `grade_item.php:1927`: `if ($result && grade_floats_different($grade->finalgrade, $oldgrade->finalgrade)) { \core\event\user_graded::create_from_grade($grade)->trigger(); }` (insert path `:1903-1904`). The `overridden` flip counts toward `$gradechanged` (`:1913`) but not toward the event. `grade_grade::set_locked()` and `set_overridden()` trigger nothing.
**MISSED-no-event → STALE-row. Only FIX-9 (R2) covers it.** FIX-6.9 is retained for C11 and for value changes, not for this.

**C11 — gradebook grade deleted.** `\core\event\grade_deleted`; `{assign_grades}` untouched, so the plugin — and core's own assign UI — still say graded. **MISSED-no-event → STALE-row. FIX-6.9.**

**C12 — grade rescale.** `assign_rescale_activity_grades()` runs raw SQL (`lib.php:1303`: `UPDATE {assign_grades} set grade = ... where assignment = :a and grade >= 0`) with no `timemodified` change and no event. **MISSED-no-event → STALE values; states unaffected. Not reconcilable without a value checksum — documented as a known limit, no fix.**

**C13 — grading a non-latest attempt.** `{assign_grades}` for attempt N is written at `:3011`, then `update_grade()` returns before the trigger:

```php
// Only push to gradebook if the update is for the most recent attempt.
if ($submission && $submission->attemptnumber != $grade->attemptnumber) {
    return true;
}
```
(comment `:3024`, condition `:3025`, `return` `:3026`; 5.1 `:3164`, 5.3 `:3209`.) Core judges the user on `s.latest = 1`. The plugin never learns, and **no plugin query filters `latest`**, so attempt N is pending forever, climbing the priority list. **MISSED-no-event → FALSE-POSITIVE-pending, unbounded. FIX-5 (`islatest`), FIX-9 (R3).**

**C14 — blind marking, graded before reveal.** The grade row is written, then `gradebook_item_update()` bails at `:6118-6122`:

```php
// Do not push grade to gradebook if blind marking is active as
// the gradebook would reveal the students.
if ($this->is_blind_marking() && !$this->is_marking_anonymous()) {
    return false;
}
```
so `:3029` never opens and **`submission_graded` never fires**. Core shows *Graded* and drops it from the counter. Every submission on a blind-marked assignment reads **100 % pending** until reveal. **MISSED-no-event → FALSE-POSITIVE-pending, whole-activity scale. FIX-6.7 + FIX-9.**

**C15 — identities revealed.** `\mod_assign\event\identities_revealed` (`:7428`; `objectid = assign.id`, `classes/event/identities_revealed.php:55`). Not registered. **MISSED-no-event. FIX-6.7 — re-upsert every ledger row of that assign.**

**C16 — `markinganonymous = 1`.** `is_marking_anonymous()` makes the blind-marking guard fall through, so `submission_graded` fires normally. **Corrected: this is a 4.5 feature, not 5.1+** — `mod/assign/db/install.xml:37` and `locallib.php:9851-9853` on the 4.5 checkout (5.1 `:10012`). The escape hatch for C14 already exists on every supported branch. **OK.**

**C17 — 5.3 multi-marker, partial marks.** `assign::update_mark()` (`moodle/public/mod/assign/locallib.php:3275`) writes `{assign_mark}` and returns early at `:3327-3330` while `count($marks) < markercount`; no event, `{assign_grades}.grade` stays `-1`. Plugin correctly stays pending. **MISSED-no-event, right outcome. No fix.**

**C18 — 5.3, all markers in (`average` / `maximum`).** The switch at `:3332-3337` dispatches to `calculate_and_update_grade_from_maximum_mark()` (`:3375`) or `..._average_mark()` (`:3351`), both routing through `update_grade()` → `submission_graded`. **OK.**

**C19 — 5.3, `multimarkmethod = 'manual'`.** The switch falls through with `return true` (`:3341`); no grade write, no event, ever, from the marking path. **MISSED-no-event. FIX-9.**

---

### Family D — Marking workflow

**D1 — `markingworkflow = 1`, grade saved with state `inmarking`.**
Core: `{assign_grades}.grade = 8.0`, `timemodified = T2`; `{assign_user_flags}.workflowstate = 'inmarking'`. `gradebook_item_update()` blanks the grade at `:6124-6131` so the gradebook stays empty, but returns true → **`submission_graded` fires**.
**Corrected mechanism:** `$grade` is passed by handle, so `$grade->grade = -1; $grade->feedbacktext = '';` (`:6128-6130`) mutates the **same object** `update_grade()` then hands to `submission_graded::create_from_grade()` at `:3030`. The conclusion is unchanged (event snapshot carries `-1`, DB row carries 8.0) but rev. 1's "local copy" wording was wrong. The plugin re-reads the DB row by id anyway, so it sees 8.0.
Events: `workflow_state_updated` (`:8687` from `apply_grade_to_user`) → `submission_graded`.
Plugin today: never reads `{assign_user_flags}` (grep for `workflowstate` / `markingworkflow` / `assign_user_flags` across `classes/`, `db/`, `cli/` returns **zero hits**). `timegraded = T2`; the row leaves the pending set and is credited to `numgraded30d` / `compliance_pct` / `median_eff_h` days before the student can see anything.
**FALSE-NEGATIVE-graded. FIX-2 + FIX-7.**

**D2 — advanced to `readyforreview` / `inreview` / `readyforrelease`.** Same. **FALSE-NEGATIVE-graded. FIX-2, FIX-7.**

**D3 — workflow set to `released`.** `{assign_user_flags}.workflowstate = 'released'`; the real grade is pushed. `{assign_grades}` is not rewritten and **there is no release timestamp anywhere in mod_assign** — `{assign_user_flags}` has columns `id, userid, assignment, locked, mailed, extensionduedate, workflowstate, allocatedmarker` (`db/install.xml:129-139`, identical on all three). Events: `workflow_state_updated` with `other['newstate'] = 'released'` (4.5/5.1: `:7344`, `:8501`, `:8687`; **5.3 has four sites: `:7815`, `:9112`, `:9367`, `:9418`**). Not registered → nothing happens. **This event's `timecreated` IS the SLA stop time and is unrecoverable afterwards. MISSED-no-event. FIX-6.5.**

**D4 — batch "Set marking workflow state", no grade.** `process_set_batch_marking_workflow_state` creates the grade row and calls `update_grade()` (`:8489-8499`) **without setting `grader`**, so `submission_graded` fires with `grade = -1` and the `grade >= 0` clause rejects it. **OK.**

**D5 — regression `released` → `inmarking`.** Feedback is withdrawn from the student; only `workflow_state_updated` fires. **MISSED-no-event. FIX-6.5 (must re-open the cycle).**

**D6 — marker allocation.** `\mod_assign\event\marker_updated`; on 4.5/5.1 it fires **only** from the batch operation (`:8559`) — quick grading (`:7331-7345`) and the grading form (`:8678-8688`) write `allocatedmarker` but guard the trigger on `$workflowstatemodified`. Fixed on 5.3 via `update_allocated_markers()` (declared `:7872`, trigger `:7900`). **MISSED-no-event, harmless. FIX-6.6 (optional).**

---

### Family E — Teacher submission-state actions

**E1 — revert to draft.** `status = 'draft'`, `timemodified` **not** bumped (`update_submission(..., false, ...)`, `:8337`); then `$grade = $this->get_user_grade($userid, true); $grade->grader = $USER->id; $this->update_grade($grade);` (`:8347-8349`), so `submission_graded` also fires with `grade` possibly `-1`. `submission_status_updated` fires at `:8356`. Ledger `submissionstatus` → `'draft'`, which removes the row from every SLA read by policy. **OK — and this self-protects the FIX-12 grade-type-None branch, since a reverted submission is never in the measured population.**

**E2 — teacher removes the submission.** `remove_submission()` mutates status at `:8293`, `update_submission(..., false, ...)` at `:8294`, then fires `submission_removed` (`:8310`) and `submission_status_updated` (`:8311`). The second is registered. **OK.** (Registering the first is belt-and-braces — FIX-6.2.)

**E3 — student removes their own submission.** Same path. **OK.**

**E4/E5 — lock / unlock.** `submission_locked` (`:8437`) / `submission_unlocked` (`:8621`); `objectid = assign.id`, `relateduserid` = student. No SLA fact changes. **MISSED-no-event, harmless. FIX-6.6 (optional).**

**E6 — "Allow another attempt" without grading.** `add_attempt()` (`:9076-9135`) creates attempt N+1 via `get_user_submission($userid, true, $old + 1)`, sets `ASSIGN_SUBMISSION_STATUS_REOPENED` (`:9119`), calls each plugin's `add_attempt()`, then `update_submission($newsubmission, $userid, false, ...)` (`:9129`) — `$updatetime = false`, so `timemodified == timecreated == now`. `latest` flips inside a delegated transaction (`:3866-3888`). Attempt N keeps `status = 'submitted'`, `latest = 0`, no grade. **No `::trigger()` anywhere in the method**; the only escapee is `submission_unlocked` when flags were locked (`:9130-9132`).
Plugin: attempt N's ledger row stays `'submitted'` + `timegraded = NULL` → permanently pending, re-aged hourly, pinned to the top of the priority list. Attempt N+1 gets no row until the student saves. **MISSED-no-event → STALE + unbounded FALSE-POSITIVE-pending. FIX-5 + FIX-9 (R1, R3).**

**E7 — automatic reopen after grading.** `update_grade()` → `reopen_submission_if_required()` (`:8799-8835`: `ASSIGN_ATTEMPT_REOPEN_METHOD_AUTOMATIC` → `$shouldreopen = true`) → `add_attempt()`. `submission_graded` fires **before** the reopen, so attempt N closes correctly and N+1 is never learned. **MISSED-no-event → STALE-row. FIX-5, FIX-9.**

**E8 — `untilpass` reopen.** Same, gated on `is_gradable(...)` (`:8810`) and `$gradegrade->is_passed() === false` (`:8819`). **MISSED-no-event → STALE-row. FIX-5, FIX-9.**

**E9 — student clicks "Add a new attempt based on previous submission".** `copy_previous_attempt()` sets status and bumps `timemodified` (`:7519-7524`), fires `submission_duplicated` (`:7551`; `objectid` = the **new** submission id, `classes/event/submission_duplicated.php:55`; no `->other`, no `relateduserid`), then `assessable_submitted` **only when `!submissiondrafts`** (`:7577`). Not registered. **MISSED-no-event. FIX-6.3.**

**E10 — student submits the new attempt.** Drafts OFF: correct. Drafts ON: only subplugin events until *Submit assignment*. **OK / MISSED. FIX-6.1, FIX-6.3.**

**E11 — teacher grades the new attempt.** It is the latest, so `:3025` does not early-return; `submission_graded` fires with `attemptnumber = N+1`. **OK.**

---

### Family F — Deadlines and overrides

**F1/F2 — extension granted / revoked.** `{assign_user_flags}.extensionduedate` written; `\mod_assign\event\extension_granted` fires (`:7029`; `objectid = assign.id` at `classes/event/extension_granted.php:56`, `relateduserid` `:57`, **no `->other`** — the new date is not in the event). There is no `extension_revoked`; a revoke sets the field to 0 and fires the same event.
Core folds it in two places, and they are **not** the same rule:
- **Cutoff** (`submissions_open()`, `:6288-6308`): `$finaldate = cutoffdate` if set; the extension raises `$finaldate` only `if ($finaldate)`. With no cutoff, submissions never close (`:6310-6314`) and the extension is irrelevant to closure.
- **Due date / lateness** (`gradingtable.php:1115-1116`): `if ($row->extensionduedate) { $due = $row->extensionduedate; }` — unconditional replacement. `save_user_extension()` validates `extensionduedate > duedate` and `> allowsubmissionsfromdate` (`locallib.php:7012-7019`), so the replacement is always a forward move.

Plugin today: not registered **and** `rule_resolver::resolve_rule()` (`classes/local/sla/rule_resolver.php:47-80`) consults only `{assign}` and `{assign_overrides}` — `{assign_user_flags}` is never read anywhere in the plugin. **MISSED-no-event + structurally blind. FIX-6.8 + FIX-10.**

**F3/F4 — user override created / updated / deleted.** `\mod_assign\event\user_override_created|_updated` (`overrideedit.php:231`, `:197`; 5.3 `classes/override_manager.php:737`, `:761`) and `_deleted` (`locallib.php:962`; 5.3 `override_manager.php:713`). None registered; `re_resolve_rules_for_assign_group()` handles `(assignid, groupid)` only. **MISSED-no-event → STALE-row. FIX-6.4.**

**F5 — group override created / updated / deleted.** Registered (`db/events.php:57-69`), but `re_resolve_rules_for_assign_group()` selects rows by the **plugin's own** `groupid` attribution (`group_resolver`'s "most recently joined group"), not by `{groups_members}`. Also `rule_resolver.php:54-57` uses `$DB->get_record()` for the user override with no limit — two override rows for one user yields a debugging notice and `false`. **OK-but-mis-targeted. FIX-6.4 note.**

**F6 — assign due date / cutoff changed on the settings form.** Only `\core\event\course_module_updated`; `{assign}.timemodified` bumps. Not registered. **MISSED-no-event → STALE-row. FIX-6.10 + R6.**

---

### Family G — Team (group) submissions

**G1 — `teamsubmission = 1`, one member submits.**
Core: `get_group_submission()` queries `array('assignment'=>..., 'groupid'=>$groupid, 'userid'=>0)` (`:3214`) and inserts `$submission->userid = 0; $submission->groupid = $groupid;` (`:3242-3244`). `update_team_submission()` writes the acting user's own mirror row (`:6179-6182`), and `save_submission()` loops the whole team **only when `requireallteammemberssubmit = 0`** (`:7700-7710`). Result: 1 group row + 1..N member rows.
Events: `assessable_submitted` with `objectid` = the **group** row; `relateduserid` is **never set** for a team (`classes/event/assessable_submitted.php:63-65` requires `!empty($submission->userid)`, and it is 0).
Plugin today: `observer.php:62-69` reads `userid = 0` off the group row and calls `upsert_for_cm_user_attempt($cmid, 0, $attempt)`. `should_skip_submitter()` (`submission_ledger.php:338-353`) cannot save it: `has_capability('mod/assign:grade', $ctx, 0)` returns false because `lib/accesslib.php:480-485` short-circuits `write`/`RISK_XSS` capabilities for `$userid == 0`, and `mod/assign:grade` is declared `'captype' => 'write'` with `RISK_XSS` (`mod/assign/db/access.php:49-59`). A **phantom ledger row with `userid = 0`** is written; `group_resolver::resolve_group_for_user($courseid, 0)` files it under `groupid = 0`. Real team members get **no pending row at all**. `backfill_history.php:228-239` has no `s.userid > 0` filter, so deleting the phantom recreates it.
**FALSE-POSITIVE-pending (a phantom that can never close) plus total blindness to real team work. FIX-8.**

**G2 — `requireallteammemberssubmit = 1`.** Each member submits their own row; the group status is recomputed from all members (`:6205-6219`). **Critically, non-submitting members have no `{assign_submission}` row at all**, so any per-member path that starts from `get_record('assign_submission', ['userid' => $member, ...])` returns null — this is why FIX-8 needs its own record builder. **FIX-8.**

**G3 — teacher grades with "apply to all group members".** `save_grade()` calls `apply_grade_to_user()` per member (`:8862-8877`), producing one `{assign_grades}` row and one `submission_graded` **per member**. There is never a `userid = 0` grade row, so the phantom can never close. **STALE-row, permanent. FIX-8.**

**G4 — teacher grades one member only.** One event; the plugin produces a sane-looking graded row whose `timesubmitted` came from the mirror row, not the group hand-in. **STALE-row. FIX-8.**

**G5 — ungrouped member, `preventsubmissionnotingroup = 0`.** `get_submission_group()` returns false when the user is in zero or 2+ groups (`:3407-3424`), so `$groupid` stays 0 and the row is stored `userid = 0, groupid = 0` — the *default group*, indistinguishable from G1's phantom. **FALSE-POSITIVE-pending. FIX-8.**

**G6 — member changes group after submitting.** `group_member_added/removed` are registered → `reattribute_user()`. **OK.**

**G7 — team attempt reopened.** `add_attempt()` team branch (`:9101-9113`), no event. **MISSED-no-event. FIX-9.**

---

### Family H — Users, structure, lifecycle

**H1 — student unenrolled.** Core's counter joins `get_enrolled_sql($ctx, '', $currentgroup, true)` (`:2526`, `:2538`), so the row disappears from core instantly. The plugin has no enrolment predicate anywhere. **STALE-row → FALSE-POSITIVE-pending. FIX-6.11 + FIX-9 (R5).**

**H2 — student suspended.** `get_enrolled_sql(..., $onlyactive = true)` drops them; the plugin does not. **STALE-row → FALSE-POSITIVE-pending. FIX-6.11, FIX-9 (R5).**

**H3 — user deleted.** `\core\event\user_deleted`; rows persist with an orphan `userid` that the privacy provider still exports and every listing JOIN silently drops. **STALE-row. FIX-6.12.**

**H4/H5 — group deleted / membership changed.** Registered. **OK.**

**H6 — course module deleted.** Registered; `delete_for_cm()` deliberately bypasses the `course_access` gate. **OK.**

**H7 — course module hidden.** Only `course_module_updated`. `course_access::is_processable()` checks course visibility and the block instance, **never cm visibility** (`course_access.php:83-86`). A hidden assign keeps accruing pending hours. **MISSED-no-event → FALSE-POSITIVE-pending. FIX-6.10.**

**H8 — course module moved to another course.** `courseid` is only ever written by the upsert (`submission_ledger.php:144`), so rows keep pointing at the old course and its rollups. **MISSED-no-event → STALE-row. FIX-6.10.**

**H9 — course hidden.** `process_hidden_courses` gates *future* writes; existing rows and rollups remain. **MISSED-no-event → STALE-row. FIX-9.**

**H10 — course reset.** **Corrected:** `assign::reset_userdata()` deletes `assign_submission` and `assign_user_flags` unconditionally (`:1253-1254`), but `assign_grades` **only when `reset_gradebook_grades` is ticked** (`:1262-1263`; 5.3 adds `assign_allocated_marker`). A default reset therefore leaves `{assign_grades}` intact — R4 must key orphan detection on `{assign_submission}`, not on grades. No events at all. **MISSED-no-event → STALE orphans. FIX-9 (R4).**

**H11 — course deleted.** Registered; `delete_for_course()` purges sub / group / trend / queue / bfcursor. **OK.**

**H12 — course restored or duplicated.** New `{assign_submission}` / `{assign_grades}` rows with historical timestamps, no events; `{assign_grades}.grader` may be 0 (§0.4). **MISSED-no-event. FIX-9 (R1).**

**H13 — block instance added / removed.** Flips `course_access::is_processable()` with no ledger consequence. **MISSED-no-event → STALE-row. FIX-9.**

---

### Family I — Cross-cutting

**I1 — `g.timemodified == s.timemodified`.** The plugin's `>=` at `submission_ledger.php:119` is the mirror image of core's `s.timemodified >= g.timemodified`: on a tie the plugin says *graded* while core's counter says *needs grading*. Core deliberately manufactures this tie for auto-created rows (§0.4); the plugin is saved only by `grade >= 0`. **After FIX-2 the tie is resolved the same way, but scoped to a cycle whose `timesubmitted` is immutable, so it can no longer destroy anything.** Documented divergence, deliberate.

**I2 — the submitter holds `mod/assign:grade`.** `should_skip_submitter()` (`:338-353`) skips them, gated by `exclude_grader_submissions` (default ON). Hazard: `self::$skipsubmittermemo` (`:325`) and `group_resolver::$memo` are process-lifetime statics, so a long backfill/drain run caches capability and group answers for the whole run. A reset helper already exists (`submission_ledger.php:355-360`). **FIX-9 must call it per batch.**

**I3 — concurrent writers.** `dirty_queue::enqueue()` (`dirty_queue.php:60-82`) and the ledger upsert (`submission_ledger.php:137-175`) both do `get_record()` → `insert_record()` against UNIQUE indexes (`db/install.xml:36`, `:143`) with no transaction and no catch. **Corrected severity:** the resulting `dml_write_exception` is caught by the event manager (§0.6), so the request is not aborted by the throw itself; the real damage is on PostgreSQL when the observer runs inside an open transaction, where the failed INSERT poisons the connection and every later statement fails. **Latent fatal on PG-in-transaction, noisy elsewhere. FIX-13.**

**I4 — plugin installed mid-course / full backfill.** `backfill_history.php:228-239` walks every `{assign_submission}` row with no status, `latest`, grade or `userid > 0` filter, re-applying the broken rule to each. **Systematic. FIX-2, FIX-3, FIX-8.**

---

## 3. Proposed design

### 3.0 FIX-2 / FIX-3 — the replacement predicate

**Design principle.** Core carries two orthogonal facts: *a grade exists (value, marked-at)* in `{assign_grades}`, and *the latest work postdates that grade* as a derived predicate. The plugin collapses both into one nullable column, so asserting the second necessarily erases the first.

**The comparison is not the bug.** `grade.timemodified >= submission.timemodified` is exactly right — it is what makes core's counter re-flag resubmitted work. The bug is applying it to a **single mutable row** whose `timesubmitted` is overwritten on every student save. Rev. 1's cure (delete the comparison) produced a worse defect: with the comparison gone, a freshly-opened cycle inherits the *old* mark and is born graded with `timegraded < timesubmitted`, i.e. a fabricated 0-hour perfect turnaround that inflates `numgraded30d`, `compliance_pct` and every median while removing the item from the backlog. All three reviewers caught this; it is fixed here.

**The cure is to scope the comparison to a measurement cycle.**

- One ledger row per *(cmid, userid, attemptnumber, cycle)*.
- `cycle = 0` — the original hand-in of this attempt.
- `cycle = N > 0` — work resubmitted after cycle N−1 carried a mark. Its own clock, bucket, `timemarked`, `timegraded`.
- A cycle's `timesubmitted` is **immutable once the cycle carries a mark**, so the comparison `timemarked >= timesubmitted` can never flip retroactively.
- A **new** cycle opens iff the previous cycle carried a mark that belonged to it *and* the live submission time has moved past that mark. Detection keys on **`timemarked`, not `timegraded`**, so marking-workflow assignments (where `timegraded` stays NULL until release) are covered.
- A **genuine** un-grading (grade cleared, workflow regressed) re-opens the **same** cycle — no new student work happened.

Trace on B2 (T1 submit, T2 grade, T3 re-save, T4 re-grade):

| Event | Row touched | timesubmitted | timemarked | timegraded | pending? |
|---|---|---|---|---|---|
| T1 submit | cycle 0 (insert) | T1 | — | — | yes |
| T2 grade | cycle 0 | T1 | T2 | T2 | no |
| T3 re-save | **cycle 1 (insert)**; cycle 0 untouched | T3 | — | — | **yes** |
| T4 re-grade | cycle 1 | T3 | T4 | T4 | no |

Cycle 0's completed 24 h measurement survives verbatim in every graded population; cycle 1 is a correctly-pending item whose clock starts at T3; the re-look is measured T3 → T4. Exactly what core reports on both of its surfaces.

#### 3.1 New file: `classes/local/sla/grading_state.php`

```php
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
 * Grading-state resolver for one mod_assign measurement cycle.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Single decision point for "does this cycle carry a real mark, and has that
 * mark reached the student?".
 *
 * The mark-exists test mirrors assign::get_grading_status()
 * (mod/assign/locallib.php:9432-9449 on 4.5, :9592 on 5.1, :10196 on 5.3 —
 * identical): grade is not null and grade is at least zero. It deliberately
 * does NOT test grader: that column is NOTNULL DEFAULT 0
 * (mod/assign/db/install.xml:84) and restore maps it through get_mappingid()
 * (restore_assign_stepslib.php:232), which yields 0 for an unmapped grader, so
 * a restored genuine grading would be misread as ungraded.
 *
 * The mark-belongs-to-this-cycle test mirrors the counter's
 * "s.timemodified >= g.timemodified" clause (locallib.php:2544), applied to
 * the cycle's own frozen hand-in time rather than to the live, mutable
 * submission row. That is the whole fix: the comparison is preserved, its
 * reference is made immutable.
 */
final class grading_state {
    /** Marking-workflow state in which the grade is visible to the student. */
    public const WORKFLOW_RELEASED = 'released';

    /** Marking-workflow default when no flag row exists. */
    public const WORKFLOW_NOTMARKED = 'notmarked';

    /** Core grading status: a real mark exists. Mirrors ASSIGN_GRADING_STATUS_GRADED. */
    public const STATUS_GRADED = 'graded';

    /** Core grading status: no real mark. Mirrors ASSIGN_GRADING_STATUS_NOT_GRADED. */
    public const STATUS_NOT_GRADED = 'notgraded';

    /**
     * Resolve the grading state of one measurement cycle.
     *
     * Returns an array with keys: hasmark (bool, a real mark exists at all),
     * markbelongs (bool, that mark postdates this cycle's hand-in),
     * timemarked (?int, set only when markbelongs), isreleased (bool),
     * isgraded (bool), gradestate (string), usesworkflow (bool).
     *
     * @param \stdClass $assign An {assign} row; needs markingworkflow.
     * @param \stdClass|null $grade An {assign_grades} row for this attempt, or null.
     * @param \stdClass|null $flags An {assign_user_flags} row for this user, or null.
     * @param int $timesubmitted This cycle's frozen hand-in time.
     * @param bool $isgradable Result of core is_gradable() for this activity.
     * @return array Resolved state; see the description for the shape.
     */
    public static function resolve(
        \stdClass $assign,
        ?\stdClass $grade,
        ?\stdClass $flags,
        int $timesubmitted,
        bool $isgradable
    ): array {
        $gradetime = $grade !== null ? (int) $grade->timemodified : 0;

        if ($isgradable) {
            /* A real mark exists. Mirrors get_grading_status()'s non-workflow
             * branch exactly. The grade >= 0 test alone rejects every
             * placeholder mod_assign auto-creates when a teacher opens the
             * grading page or the PDF annotator (grade = -1,
             * locallib.php:4002). */
            $hasmark = $grade !== null
                && $gradetime > 0
                && $grade->grade !== null
                && (float) $grade->grade >= 0.0;
            $markbelongs = $hasmark && $gradetime >= $timesubmitted;
        } else {
            /* Grade type "None", or no value/scale grade item at all: core
             * is_gradable() is false (lib/gradelib.php:320-335) and no numeric
             * mark is ever possible. Mirror the COUNTER instead of the status
             * here, because the counter is the only core surface that can ever
             * clear such a submission: a grade row whose timemodified is
             * strictly later than the hand-in is what drops the student out of
             * count_submissions_need_grading() (locallib.php:2544). Strict
             * greater-than excludes the auto-created placeholder, whose
             * timemodified is copied from the submission (locallib.php:3993). */
            $hasmark = $grade !== null && $gradetime > 0;
            $markbelongs = $hasmark && $gradetime > $timesubmitted;
        }

        /* Marking workflow: the student sees nothing until the state is
         * 'released', so that — not the moment the marker typed the grade —
         * is when the response actually lands. Mirrors the workflow branch of
         * get_grading_status(). */
        $workflow = null;
        if (!empty($assign->markingworkflow)) {
            $workflow = ($flags !== null && !empty($flags->workflowstate))
                ? (string) $flags->workflowstate
                : self::WORKFLOW_NOTMARKED;
        }
        $isreleased = $workflow === null || $workflow === self::WORKFLOW_RELEASED;

        if ($workflow !== null) {
            $gradestate = $workflow;
        } else {
            $gradestate = $hasmark ? self::STATUS_GRADED : self::STATUS_NOT_GRADED;
        }

        return [
            'hasmark' => $hasmark,
            'markbelongs' => $markbelongs,
            'timemarked' => $markbelongs ? $gradetime : null,
            'isreleased' => $isreleased,
            'isgraded' => $markbelongs && $isreleased,
            'gradestate' => $gradestate,
            'usesworkflow' => $workflow !== null,
        ];
    }
}
```

#### 3.2 Replacement inside `submission_ledger::upsert_for_cm_user_attempt()`

Signature gains one optional parameter (every existing 3-argument call site keeps working):

```php
    public static function upsert_for_cm_user_attempt(
        int $cmid,
        int $userid,
        int $attemptnumber,
        ?int $releasedat = null
    ): ?int {
```

**Guard inserted immediately after the `$cm` resolution (before `should_skip_submitter`, i.e. between current lines 74 and 80):**

```php
        /* userid 0 is not a user: it is the container row mod_assign writes for
         * team submissions (locallib.php:3242-3244). has_capability()
         * short-circuits to false for userid 0 on every write capability
         * (lib/accesslib.php:480-485), so should_skip_submitter() cannot catch
         * it. Team rows are fanned out per member by upsert_for_team_attempt(). */
        if ($userid <= 0) {
            return null;
        }
```

**Replace lines 107-175 in full** (rev. 1 said "replace 107-141", which deleted `$now`, `$upperbound`, `$waitinghours`, `$audit` and `$effectivehours` while leaving the `$record` literal reading all five — the patch did not compile). The ordering matters: the elapsed-time block must now run **after** `$timegraded` is known.

```php
        $isgradable = self::is_activity_gradable((int) $cm->course, (int) $assign->id);

        $flags = null;
        if (!empty($assign->markingworkflow)) {
            $flags = $DB->get_record('assign_user_flags', [
                'assignment' => $assign->id,
                'userid' => $userid,
            ]) ?: null;
        }

        $status = isset($submission->status)
            ? (string) $submission->status
            : submission_status::NEW;
        $livesubmitted = (int) $submission->timemodified;
        $grade = $grade ?: null;

        // Highest (i.e. current) cycle for this attempt, if any.
        $rows = $DB->get_records(
            'block_feedback_tracker_sub',
            ['cmid' => $cmid, 'userid' => $userid, 'attemptnumber' => $attemptnumber],
            'cycle DESC',
            'id, cycle, submissionstatus, timesubmitted, timegraded, timemarked',
            0,
            1
        );
        $existing = $rows ? reset($rows) : null;

        $cycle = 0;
        $timesubmitted = $livesubmitted;
        $storedgraded = null;
        $newcycle = false;

        if ($existing !== null) {
            $cycle = (int) $existing->cycle;
            $prevsubmitted = (int) $existing->timesubmitted;
            $prevmarked = $existing->timemarked !== null ? (int) $existing->timemarked : 0;

            if ($prevmarked > 0 && $prevmarked >= $prevsubmitted && $livesubmitted > $prevmarked) {
                /* The student moved the work after this cycle was marked. Core
                 * keeps reporting "Graded" (get_grading_status,
                 * locallib.php:9443) while its counter re-flags the row
                 * (locallib.php:2544) and the grading table labels it
                 * "Graded - resubmitted" (gradingtable.php:1283-1286). Open a
                 * NEW measurement cycle instead of rewriting the closed one:
                 * the completed turnaround stays in the history and the
                 * re-look gets its own clock. Keyed on timemarked rather than
                 * timegraded so marking-workflow rows (marked, not yet
                 * released) are detected too. */
                $cycle++;
                $existing = null;
                $newcycle = true;
                $timesubmitted = $livesubmitted;
            } else {
                $storedgraded = $existing->timegraded !== null ? (int) $existing->timegraded : null;
                if (
                    $prevsubmitted > 0
                    && (string) $existing->submissionstatus === submission_status::SUBMITTED
                    && $status === submission_status::SUBMITTED
                ) {
                    /* The clock starts at hand-in. Later edits inside the same
                     * open cycle must not move it. A draft that becomes
                     * submitted does move it, because the work only arrives on
                     * submit. */
                    $timesubmitted = min($prevsubmitted, $livesubmitted);
                }
            }
        }

        $state = grading_state::resolve($assign, $grade, $flags, $timesubmitted, $isgradable);

        $timegraded = null;
        if ($state['isgraded']) {
            /* First response wins: a re-grade refreshes timemarked but never
             * moves the recorded response time of a cycle that already closed.
             * Under marking workflow the release moment is supplied by the
             * workflow_state_updated observer — mod_assign persists no release
             * timestamp ({assign_user_flags} has no timemodified column,
             * db/install.xml:129-139) — and the mark time is the documented
             * lower-bound fallback for a row rebuilt after the fact. Once the
             * state IS 'released', later grade saves reach the student
             * immediately (gradebook_item_update() only blanks the grade while
             * the state is not released, locallib.php:6124-6131), so the mark
             * time is then the correct stop time, not a fallback. */
            if ($storedgraded !== null) {
                $timegraded = $storedgraded;
            } else if ($state['usesworkflow'] && $releasedat !== null) {
                $timegraded = $releasedat;
            } else {
                $timegraded = $state['timemarked'];
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
            'islatest'         => (int) ($submission->latest ?? 1),
            'iscurrent'        => 1,
            'gradestate'       => $state['gradestate'],
            'teamgroupid'      => 0,
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
            'timemodified'     => $now,
        ];

        if ($existing !== null) {
            $record->id = $existing->id;
            $subid = (int) $existing->id;
            $DB->update_record('block_feedback_tracker_sub', $record);
        } else {
            $record->timecreated = $now;
            $subid = self::insert_cycle_row($record, $cmid, $userid, $attemptnumber, $cycle);
        }

        /* islatest and iscurrent are attempt-wide facts, so they must be
         * maintained set-based across EVERY cycle of the tuple — the
         * single-row upsert above only ever touches the highest one, and a
         * superseded attempt whose cycle-0 row still claimed islatest = 1
         * would be re-selected by the reconciler for ever. */
        $DB->execute(
            'UPDATE {block_feedback_tracker_sub}
                SET islatest = :islatest
              WHERE cmid = :cmid AND userid = :userid AND attemptnumber = :att',
            [
                'islatest' => (int) ($submission->latest ?? 1),
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
```

**No `timecreated` clamp.** Rev. 1 synthesised a hand-in time from `{assign_submission}.timecreated` when the very first ledger write already found `timemarked < livesubmitted`. That manufactures 0-hour perfect scores at install-time scale whenever `timecreated > timemarked` (offline / `status = 'new'` rows, where `timecreated` is when the *teacher* opened the grading page) and inflates the wait whenever drafts are on (`timecreated` = first draft save, possibly weeks early). It is withdrawn. On such a first write the row is simply created as `cycle = 0`, `timesubmitted = livesubmitted`, `timemarked = NULL`, `timegraded = NULL`, `gradestate = 'graded'` — pending, clock from the edit, `gradestate` recording core's truth. The historical turnaround is genuinely unrecoverable from Moodle (`{assign_submission}` keeps one `timemodified`) and is therefore not recorded rather than fabricated.

**Two small helpers** (`is_activity_gradable()` memoises core's `is_gradable()` per `(courseid, assignid)` for the request; `insert_cycle_row()` carries the FIX-13 concurrency handling) are given in §3.7 and §3.9.

**How the three requirements are met.**

| Requirement | Mechanism |
|---|---|
| (a) keep a grade once given, even if the student later touches the submission | The closed cycle is never re-read or rewritten by student activity — the upsert only ever touches the highest cycle, and a post-mark edit creates a new one. `timegraded` on the closed cycle is untouchable. |
| (b) still re-open as pending when genuinely new work appears | Two independent routes: a **new attempt** is a new ledger row (`attemptnumber + 1`, `status = 'reopened'` → excluded until submitted, then `submitted` with no grade for that attempt → pending; the old attempt drops out on `islatest = 0`); a **resubmission of the same attempt** is a new cycle whose `timemarked` is NULL because the live mark predates its hand-in → pending. |
| (c) NULL / -1 grades and marking-workflow states | `grade !== null && grade >= 0` (core's own test, no `grader`) rejects cleared grades and every auto-created placeholder; the non-gradable branch mirrors core's counter for grade-type-None; `isgraded = markbelongs && isreleased` handles workflow; a workflow regression or a cleared grade sets `timegraded` back to NULL on the **same** cycle, which is a correct un-grading. |

---

### 3.3 FIX-1 / FIX-6 — event registrations to add

`observer::submission_changed` must first stop trusting `objectid`. Replace `observer.php:51`:

```php
        /* The assignsubmission_* events carry the SUBPLUGIN row id in objectid
         * (submission/onlinetext/locallib.php:306 and :291,
         * submission/file/locallib.php:301 and :288 — all after an explicit
         * unset($params['objectid']) at onlinetext :276) and the real
         * {assign_submission}.id in other['submissionid'] (:279). The
         * mod_assign events carry the submission id in objectid and set no
         * submissionid key, so the fallback is safe for both families. */
        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        $submissionid = (int) (($other['submissionid'] ?? null) ?: ($event->objectid ?? 0));
```

Registrations to add to `db/events.php` (**a `db/events.php` change requires a `version.php` bump** — the observer map is rebuilt on upgrade).

| # | Event name | Observer method | What the method must do |
|---|---|---|---|
| **6.1** | `\assignsubmission_onlinetext\event\submission_updated`<br>`\assignsubmission_file\event\submission_updated` | `observer::submission_changed` | Same as `submission_created`, now that FIX-1 reads `other['submissionid']`. Closes the drafts-ON blind spot (A4, B3, E10) and gives a second signal for B2. |
| **6.2** | `\mod_assign\event\submission_removed` | `observer::submission_changed` | Belt-and-braces alongside `submission_status_updated`; `objectid` is the submission id. Idempotent. |
| **6.3** | `\mod_assign\event\submission_duplicated` | `observer::submission_changed` | `objectid` is the **new** submission row (`classes/event/submission_duplicated.php:55`). No `relateduserid` and no `->other` — the userid must come from the row lookup, which FIX-1 already does. |
| **6.4** | `\mod_assign\event\user_override_created`<br>`…_updated`<br>`…_deleted` | `observer::user_override_changed` (new) | Read `other['assignid']` + `$event->relateduserid`; gate on `course_access::is_processable()`; call a new `submission_ledger::re_resolve_rules_for_assign_user($assignid, $userid)`; enqueue with `REASON_PAUSE`. **Also fix** `re_resolve_rules_for_assign_group()` to select affected users from `{groups_members}` rather than the ledger's own `groupid` attribution, and change `rule_resolver.php:54` from `get_record()` to `get_records(..., 0, 1)` so a duplicate override row cannot raise a debugging notice. |
| **6.5** | `\mod_assign\event\workflow_state_updated` | `observer::workflow_state_changed` (new) | `objectid = assign.id`; the student is `$event->relateduserid`; `other['newstate']` is the new state. Resolve the cm from `contextinstanceid`; resolve the target attempt from **`{assign_submission}.latest = 1`** for that assign+user (not `MAX(attemptnumber)` — `latest` is core's authority and is what `islatest` mirrors); then `upsert_for_cm_user_attempt($cmid, $userid, $attempt, $releasedat)` with `$releasedat = (int) $event->timecreated` **only** when `newstate === 'released'`. On a release, additionally run one set-based statement that closes every earlier cycle of that (cmid, userid) whose mark was never released:<br>`UPDATE {block_feedback_tracker_sub} SET timegraded = :rel WHERE cmid = :cmid AND userid = :userid AND timegraded IS NULL AND timemarked IS NOT NULL AND timemarked >= timesubmitted` — then enqueue the tuple so the elapsed columns are recomputed. A regression away from `released` re-opens the cycle automatically (`isgraded` becomes false). 5.3 fires this event from four sites (`:7815`, `:9112`, `:9367`, `:9418`); the observer is idempotent and `$releasedat` only bites on the first transition into `released`. |
| **6.6** | `\mod_assign\event\submission_locked`<br>`…_unlocked`<br>`\mod_assign\event\marker_updated` | `observer::assign_user_touched` (new) | Optional. All three carry `objectid = assign.id` + `relateduserid`. Cheap re-read of that user's latest attempt; no SLA semantics of their own. Register only if the roadmap adds marker-level attribution or lock-aware pausing. |
| **6.7** | `\mod_assign\event\identities_revealed` | `observer::identities_revealed` (new) | `objectid = assign.id`. **Mandatory** — the moment every grading suppressed by blind marking (`gradebook_item_update()` returning false at `locallib.php:6118-6122`) becomes knowable. Select `DISTINCT userid, attemptnumber` from `{assign_submission}` ∪ `{assign_grades}` for the assign (`userid > 0`) and dispatch chunked adhoc `backfill_one_submission` tasks — **each row must carry `courseid`**, which the task requires (`classes/task/backfill_one_submission.php:55-77`). Never loop inline: a large cohort would block the teacher's request. |
| **6.8** | `\mod_assign\event\extension_granted` | `observer::extension_changed` (new) | `objectid = assign.id`, `relateduserid` = student, **no payload** — the date must be read from `{assign_user_flags}.extensionduedate`. Requires FIX-10. Fires for both grant and revoke (there is no `extension_revoked`). |
| **6.9** | `\core\event\user_graded`<br>`\core\event\grade_deleted` | `observer::gradebook_changed` (new) | `other['itemid']` (set in `create_from_grade()`, `lib/classes/event/user_graded.php:65-68`) → `{grade_items}` → require `itemmodule = 'assign'` → `iteminstance` → cm via `get_coursemodule_from_instance()`. **The event's context is `context_course`, not the cm** (`user_graded.php:62`), so `contextinstanceid` must not be treated as a cmid. Then re-upsert `(cmid, $event->relateduserid, latest attempt)`. **Scope note:** `user_graded` fires only when the final grade *value* changed (`grade_item.php:1927`), so this observer catches C11 and value edits but **cannot** catch the override/lock transition of C10 — that one is reconciler-only. |
| **6.10** | `\core\event\course_module_updated` | `observer::course_module_updated` (new) | Bail unless `other['modulename'] === 'assign'`. Then (i) if the cm's `course` differs from the ledger rows' `courseid`, rewrite `courseid`, re-run `group_resolver` per row and enqueue both old and new tuples; (ii) if the cm is now hidden, take it out of scope (simplest: delete its rows, mirroring `delete_for_cm()`, and let the reconciler re-create them if it is unhidden); (iii) re-resolve rule columns for every row of that cm (due-date / cutoff changes). |
| **6.11** | `\core\event\user_enrolment_deleted`<br>`\core\event\user_enrolment_updated` | `observer::enrolment_changed` (new) | `courseid` + `relateduserid`. Core's counter joins `get_enrolled_sql(..., onlyactive = true)`, so an unenrolled or suspended user contributes nothing. Delete that user's ledger rows for the course and enqueue the affected tuples. `user_enrolment_updated` must re-check active status — a re-activated enrolment should re-run the backfill for that user. |
| **6.12** | `\core\event\user_deleted` | `observer::user_deleted` (new) | Delete all ledger rows for `objectid` and enqueue every affected tuple. Must bypass `course_access::is_processable()` (a cleanup path, like `course_deleted`) — and therefore must **not** be routed through `backfill_one_submission`, which re-gates every row on `is_processable()` (`:70-72`). |

Not worth registering: `\assignfeedback_file\event\feedback_downloaded` (5.1+ only, and download ≠ response), `statement_accepted`, every `*_viewed` event, `all_submissions_downloaded`, `course_module_viewed`.

**Trap:** do **not** register `\mod_assign\event\submission_created` or `\mod_assign\event\submission_updated`. Both are `abstract class … extends base` (`mod/assign/classes/event/submission_created.php:47`, `submission_updated.php:47`) and are never instantiated by core.

### Status of phase 6 as built (2026-08-03)

**6.11 — shipped**, as `observer::enrolment_changed`, with two deviations from the row above. Only `user_enrolment_deleted` is registered; `user_enrolment_updated` is not, so suspension (H2) still reaches the ledger only through R5's one-course-per-tick sweep, and the two enrolment states therefore have very different latencies. And "delete that user's ledger rows" is conditional: a user may hold several enrolments in one course, so the deletion runs only when this was the last one. Core has already decided that and ships the answer as `lastenrol` in `other['userenrolment']` (set in `unenrol_user()` immediately before the event); re-deriving it with `get_enrolled_sql()` would materialise the whole enrolled set to settle a one-row question, once per event, and a bulk unenrolment fires one event per student.

**6.12 — shipped**, as `observer::user_deleted`, reading `objectid` (core only guarantees `relateduserid` with a `debugging()` fallback). Backed by a new `idx_user` index: no existing index led with `userid`, so the deletion was a full table scan. It deliberately leaves `allocmarkerid` pointing at the deleted account — see the open item below.

**6.10 — shipped in part**, as `observer::course_module_updated`. Duties (i) and (iii) are covered by re-deriving every measured row of the cm from live state: the upsert reads `$cm->course` and the live `{assign}` row, so a moved module rewrites its own `courseid` and a settings change re-resolves its own rule columns — there is no stored snapshot of `markingworkflow` to resync against, so the re-derivation *is* the resync. Duty (ii), taking a hidden cm out of scope, is **not** implemented and the row above's "simplest: delete its rows" is rejected: `delete_for_cm()` destroys every closed cycle, while `sweep_missing_rows` can only rebuild from live `{assign_submission}`, i.e. one cycle-0 row per attempt. Unhiding would silently discard completed response-time history. Doing this properly needs a scope flag, not a deletion.

### The gradebook decision (2026-08-04) — supersedes the 6.9 verdict below

The measurement model was extended rather than the observer set patched. Four options were worked out against the code; the one adopted is **earliest response wins, from either surface, never withdrawn**.

What that settles, and why the alternatives were refused:

- **Doing nothing was not neutral.** The pre-existing model already broke the plugin's own "reached the student" principle in both directions: it wrote `timeclosed` for a mark whose gradebook item was hidden (the student sees no feedback block and gets no mail), and left `timeclosed` NULL for an unreleased workflow mark that a gradebook override had already put on the student's screen. Those are exactly the two failures the "deliberately NOT a coalesce" sentence exists to prevent.
- **Withdrawal was refused.** Making a response reversible would let hiding a column today re-edit last month's median, with no automatic trend recompute. A published figure that a later administrative act can change is not a fact. So a gradebook deletion does not re-open — while the activity keeps sole authority over re-opening, because clearing a mark *there* is the marker saying "not answered yet", which is the cycle model's own rule.
- **Excluding the unmeasurable was refused.** Excluded rows would leave the headline denominator, so the median improves and the score rises by dropping the hard cases — gameable in the one direction that matters, on a figure attached to a named person. That is the same refusal already encoded in `ALLOC_SOURCE_LATE`.

Boundaries that are load-bearing, each pinned by a test that reds under mutation:

1. The instant comes from `{grade_grades}.overridden`, never `timemodified`. Core sets `overridden` for a human grading outside the activity and suppresses it for a mass rescale, while regrades, calculated items and 5.2's penalty manager move `timemodified` with nobody grading.
2. A hidden grade is not a response — grade hidden, item hidden, or hidden-until in the future.
3. The operational clock (`timegraded`, which every pending predicate keys on) accepts the gradebook; the allocated marker's turnaround (`allochours`) does not, and receives an activity-derived instant only.
4. `stamp_release_for_user()` writes `timeclosed` through `COALESCE`, so a release cannot move a response the gradebook already recorded.

Coverage: `\core\event\user_graded` (early-exit on an already-answered cycle) plus a reconciler sweep for what fires no event — the flip to overridden, and a re-grade to the same value. `grade_deleted` is not registered; under earliest-wins it has nothing to do.

**Left unrepaired, on purpose:** an activity mark whose gradebook grade is hidden still closes the response time. That is pre-existing behaviour, and it is now *disclosed* on the row rather than changed. Changing it is a separate decision, because it would move already-published numbers.

---

**6.9 — the original prescription was not implementable as written.** Two findings, both verified against 4.5 and 5.2:

1. It cannot repair C11. Every ledger stamp derives from `{assign_grades}` and `{assign_user_flags}`; a gradebook-side deletion touches neither, so the prescribed re-upsert reproduces the stored row unchanged. The plugin would keep saying *graded* — as core's own assign UI does. Closing C11 needs a new grading source in the measurement model, which is a phase of its own, not an observer.
2. `user_graded` fires on the ordinary grading path *before* `submission_graded` — `gradebook_item_update()` performs the gradebook write, and only if it returns true is `submission_graded` triggered (`mod/assign/locallib.php:3228-3229` on 5.2) — so registering it rebuilds the same ledger row twice on every grade save, running the academic-time engine twice. The replay is not free: `build_and_store()` rewrites `effectiveasof`, `timemodified` and every elapsed column, and enqueues.

Two further constraints for whoever picks this up: `grade_deleted` only fires when site-wide completion is enabled — `grade_grade::delete()` tests `!empty($this->grade_item)` *before* calling `load_grade_item()`, and the property is populated only as a side effect of `notify_changed()`, which returns early when `completion_info::is_enabled_for_site()` is false. And `user_graded` fires only when the final grade *value* changed, so feedback-only edits are invisible to it.

**Open item, out of scope here:** `allocmarkerid` is declared personal data and exported by the privacy provider, but nothing clears it when the marker's account is deleted, and `get_users_in_context()` selects only `userid`, so a marker is never listed among a context's users in the first place. That is a gap in the provider, not in the observers, and closing it inside `user_deleted` would both hide it in the wrong place and silently move the allocation-coverage figure of every course the account merely marked in.

---

### 3.4 FIX-4 — schema changes

Six columns on `{block_feedback_tracker_sub}` plus an index rework.

| Column | Type | Why |
|---|---|---|
| `cycle` | `int(10) NOTNULL UNSIGNED DEFAULT 0` | Measurement index within an attempt. 0 = original hand-in; N > 0 = work resubmitted after cycle N−1 carried a mark. Makes "Graded - resubmitted" representable without destroying history. |
| `timemarked` | `int(10) NULL UNSIGNED` | `{assign_grades}.timemodified`, stored **only when the mark postdates this cycle's hand-in**. Distinct from `timegraded` under marking workflow (mark saved vs released). It is the reference against which the next resubmission is detected. |
| `islatest` | `int(1) NOTNULL UNSIGNED DEFAULT 1` | Mirror of `{assign_submission}.latest`. Core gates every needs-grading read on `s.latest = 1` (`locallib.php:2540`); without it, superseded attempts are pending forever (C13, E6-E8). |
| `iscurrent` | `int(1) NOTNULL UNSIGNED DEFAULT 1` | 1 on the highest cycle of a tuple, 0 on superseded cycles. Without it two cycles of one attempt can both be open under marking workflow, double-counting the backlog, and the `cur_median_*` merge would combine a closed cycle with its own live successor. |
| `gradestate` | `char(20) NULL` | `get_grading_status()` verbatim: `graded` / `notgraded` / a workflow state. Lets the UI show core's truth, lets the reconciler diff cheaply, and is the audit trail for why a row is pending. |
| `teamgroupid` | `int(10) NOTNULL UNSIGNED DEFAULT 0` | The `{assign_submission}.groupid` a fanned-out team row was derived from (0 for individual). Required by FIX-8 so a team row can be traced back to its group submission — and by R4, so team rows are not deleted as orphans. |

#### `db/install.xml`

Inside `<FIELDS>` of `block_feedback_tracker_sub`, after `attemptnumber`:

```xml
        <FIELD NAME="cycle" TYPE="int" LENGTH="10" NOTNULL="true" UNSIGNED="true" DEFAULT="0" COMMENT="Measurement index within one attempt. 0 is the original hand-in; N greater than 0 is work resubmitted after cycle N-1 carried a mark (core's Graded - resubmitted state). Closed cycles are never rewritten by student activity." SEQUENCE="false"/>
```

after `timegraded`:

```xml
        <FIELD NAME="timemarked" TYPE="int" LENGTH="10" NOTNULL="false" UNSIGNED="true" COMMENT="assign_grades.timemodified, stored only when the mark postdates this cycle's hand-in. Equals timegraded when marking workflow is off; earlier than timegraded when the grade waited for release. Reference for detecting the next resubmission." SEQUENCE="false"/>
        <FIELD NAME="islatest" TYPE="int" LENGTH="1" NOTNULL="true" UNSIGNED="true" DEFAULT="1" COMMENT="Mirror of assign_submission.latest. Superseded attempts (0) are excluded from pending reads, as in core's needs-grading SQL." SEQUENCE="false"/>
        <FIELD NAME="iscurrent" TYPE="int" LENGTH="1" NOTNULL="true" UNSIGNED="true" DEFAULT="1" COMMENT="1 on the highest cycle of an attempt, 0 on superseded cycles. Pending reads and the current-state medians use it so one attempt contributes exactly one live observation." SEQUENCE="false"/>
        <FIELD NAME="gradestate" TYPE="char" LENGTH="20" NOTNULL="false" COMMENT="assign get_grading_status() verbatim: graded, notgraded, or a marking-workflow state." SEQUENCE="false"/>
        <FIELD NAME="teamgroupid" TYPE="int" LENGTH="10" NOTNULL="true" UNSIGNED="true" DEFAULT="0" COMMENT="assign_submission.groupid the row was derived from; 0 for individual assignments. Non-zero rows were fanned out from a team submission." SEQUENCE="false"/>
```

Index rework inside `<INDEXES>` — drop `uq_cm_user_attempt` (`:36`), `idx_course_group_graded` (`:37`) and `idx_status_graded` (`:39`); add:

```xml
        <INDEX NAME="uq_cm_user_att_cycle" UNIQUE="true" FIELDS="cmid, userid, attemptnumber, cycle"/>
        <INDEX NAME="idx_cg_cur_graded" UNIQUE="false" FIELDS="courseid, groupid, iscurrent, timegraded"/>
        <INDEX NAME="idx_status_cur_graded" UNIQUE="false" FIELDS="submissionstatus, islatest, iscurrent, timegraded"/>
        <INDEX NAME="idx_item_user" UNIQUE="false" FIELDS="iteminstance, userid"/>
```

`idx_course_group_submitted` (`:38`), `idx_pending_bucket` (`:40`) and `idx_calver` (`:41`) are unchanged; `idx_pending_bucket` still serves the bucket partition, whose extra `islatest`/`iscurrent` predicates are covered by `idx_status_cur_graded`. `idx_item_user` is new and exists for the reconciler's `{assign_user_flags}` and `{assign}` joins.

**XMLDB `VERSION` attribute:** the root element currently carries `VERSION="20260523"` — a **date**, not a plugin version code. Bump it to the release date of this change (`20260802`), not to `2026080201`; rev. 1's instruction to "match the version.php bump" would have silently broken the file's own convention.

#### `version.php` and `CHANGELOG.md`

```php
$plugin->release      = '1.1.0';
$plugin->version      = 2026080201;
```

`$plugin->supported = [405, 502]` (`version.php:31`) is **unchanged**, so `.github/workflows/ci.yml` and the README compatibility table need no edit. A `CHANGELOG.md` entry lands in the **same commit** as the version bump (fleet standard).

#### Upgrade step (`db/upgrade.php`, appended before `return true;`)

```php
    /* V1.1.0 — a post-grading student edit no longer un-grades the row.
     * timegraded becomes a sticky record of the first response; a resubmission
     * opens a new measurement cycle instead. Six new columns support that,
     * plus core's latest-attempt gate and marking-workflow awareness. Existing
     * rows are seeded conservatively (cycle 0, timemarked = timegraded,
     * islatest = 1, iscurrent = 1) and re-derived by the new reconcile_ledger
     * task. */
    if ($oldversion < 2026080201) {
        $table = new xmldb_table('block_feedback_tracker_sub');

        $fields = [
            new xmldb_field('cycle', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'attemptnumber'),
            new xmldb_field('timemarked', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null, 'timegraded'),
            new xmldb_field('islatest', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '1', 'timemarked'),
            new xmldb_field('iscurrent', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '1', 'islatest'),
            new xmldb_field('gradestate', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'iscurrent'),
            new xmldb_field('teamgroupid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'gradestate'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Seed timemarked from the existing timegraded so the resubmission
        // detector has a reference on day one.
        $DB->execute(
            'UPDATE {block_feedback_tracker_sub}
                SET timemarked = timegraded
              WHERE timegraded IS NOT NULL AND timemarked IS NULL'
        );

        $dropindexes = [
            new xmldb_index('uq_cm_user_attempt', XMLDB_INDEX_UNIQUE, ['cmid', 'userid', 'attemptnumber']),
            new xmldb_index('idx_course_group_graded', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'groupid', 'timegraded']),
            new xmldb_index('idx_status_graded', XMLDB_INDEX_NOTUNIQUE, ['submissionstatus', 'timegraded']),
        ];
        foreach ($dropindexes as $index) {
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
        }

        $addindexes = [
            new xmldb_index('uq_cm_user_att_cycle', XMLDB_INDEX_UNIQUE, ['cmid', 'userid', 'attemptnumber', 'cycle']),
            new xmldb_index('idx_cg_cur_graded', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'groupid', 'iscurrent', 'timegraded']),
            new xmldb_index('idx_status_cur_graded', XMLDB_INDEX_NOTUNIQUE, ['submissionstatus', 'islatest', 'iscurrent', 'timegraded']),
            new xmldb_index('idx_item_user', XMLDB_INDEX_NOTUNIQUE, ['iteminstance', 'userid']),
        ];
        foreach ($addindexes as $index) {
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        // Drop the team phantoms the old code wrote for userid 0; the fan-out
        // recreates real per-member rows.
        $DB->delete_records_select('block_feedback_tracker_sub', 'userid = 0');

        // Arm the reconciler so it re-derives islatest / iscurrent /
        // gradestate / teamgroupid and repairs mis-classified rows.
        set_config('reconcile_full_sweep_pending', '1', 'block_feedback_tracker');
        set_config('reconcile_lastid', '0', 'block_feedback_tracker');

        upgrade_block_savepoint(true, 2026080201, 'feedback_tracker');
    }
```

#### Companion changes the schema forces (all in the same commit)

1. **Privacy provider.** `classes/privacy/provider.php:90-107` enumerates `{block_feedback_tracker_sub}` field by field (12 entries). Add `cycle`, `timemarked`, `islatest`, `iscurrent`, `gradestate`, `teamgroupid` with `privacy:metadata:sub:<field>` keys, and surface them in `export_user_data()`.
2. **Lang packs.** Six new `privacy:metadata:sub:*` keys **plus** `task_reconcile_ledger`, inserted in their alphabetic slots in **both** `lang/en/block_feedback_tracker.php` and `lang/pt_br/block_feedback_tracker.php`. `task_reconcile_ledger` goes between `task_purge_calendar_cache` (`:527`) and `task_recompute_one` (`:528`). Missing task strings fail the `validate` gate; out-of-order keys fail `moodle.Files.LangFilesOrdering`.
3. **Test generator.** `tests/generator/lib.php:125-154` inserts an explicit field list with no new columns. Add `cycle => 0`, `timemarked => null`, `islatest => 1`, `iscurrent => 1`, `gradestate => null`, `teamgroupid => 0` to `$defaults`, so every consumer test keeps seeing its seeded rows once `islatest`/`iscurrent` filters land.
4. **`classes/task/reconcile_ledger.php` must exist before `db/tasks.php` names it** — `tests/task/scheduled_tasks_test.php:193-203` asserts `class_exists()` and a non-empty `get_name()` for every declared task.

---

### 3.5 FIX-5 — read-path changes

```
pending := submissionstatus = 'submitted' AND islatest = 1 AND iscurrent = 1 AND timegraded IS NULL
graded  := submissionstatus = 'submitted' AND timegraded IS NOT NULL [+ optional window]
current := iscurrent = 1                         -- for "state right now" headlines
```

**Add `AND islatest = 1 AND iscurrent = 1` to every pending predicate:**

| File | Line |
|---|---|
| `classes/local/sla/rollup_service.php` | `:137-138` (pending fetch), and the partition loop at `:156-183` inherits it |
| `classes/local/sla/pending_recomputer.php` | `:68-69` |
| `classes/local/sla/submission_browser.php` | `:261-268` (MODE_PENDING and MODE_DRAFT) |
| `classes/external/get_grader_priority_list.php` | `:125` |

**Graded-side statistical populations keep every cycle** — `rollup_service.php:188-189` (`numgraded30d`, compliance, medians, p90, max) and `:346-348` (`trend_pct_30d`), `responsiveness_calculator.php:242-244`, `site_stats_service.php:50-51`, `trend_service.php:52-53` and `:114-115`, `get_academic_days.php:251-253`. A superseded cycle that *was* graded is a genuine, distinct response event; dropping it would re-create exactly the data loss this whole change exists to prevent. `numgraded30d` therefore counts **responses**, not distinct students — document that in the card tooltip.

**One exception, and it is mandatory:** `rollup_service.php:240-248` merges the graded and pending arrays into `cur_median_eff_h` / `cur_median_raw_h` / `cur_median_eff_days` / `cur_median_perc_days`. These are "state right now" headlines, so a closed cycle 0 must not be merged alongside its own live cycle 1 for the same attempt. Build the `cur_*` arms from a dedicated `iscurrent = 1` fetch (graded ∪ pending), so one attempt contributes exactly one current observation.

**`cycle` needs no filter anywhere else.** Surface `cycle > 0` in the browser and the report row as a "Resubmitted" label, mirroring `gradingtable.php:1286` — and note that core suppresses that badge entirely when `markingworkflow` is on (`:1283`), so the label should follow the same rule if strict parity is wanted.

Two pre-existing inconsistencies worth folding into the same pass, since they live in the same queries:
- The Graded tab (`submission_browser.php:257-268`) is **all-time** while every other graded consumer is windowed at 30 days (`rollup_service.php:113-118`). Window it or label it.
- The block tile computes pending days **live** (`rollup_service.php:161`, `day_counter::between(timesubmitted, $now)`) while the report bar reads the **stored** `effectivedays` with a **calendar-day** COALESCE fallback (`submission_browser.php:227-234`). Two rulers, one number. The fallback also goes negative for any legacy row where `timegraded < timesubmitted`; the reconciler's first full sweep repairs those, but the expression should be clamped with `max(0, …)` regardless.

---

### 3.6 FIX-7 — marking workflow

`{assign_user_flags}` is read in `upsert_for_cm_user_attempt()` **only when `$assign->markingworkflow` is set**, so no extra query on the overwhelming majority of assignments. `grading_state::resolve()` owns the interpretation; `observer::workflow_state_changed` (FIX-6.5) owns the release timestamp, which mod_assign never persists.

### 3.7 FIX-10 — extensions

`rule_resolver::resolve_rule()` gains a final precedence step. Core applies the extension **twice, with two different rules**, and the plugin must mirror both:

```php
        $flags = $DB->get_record('assign_user_flags', [
            'assignment' => $assign->id,
            'userid' => $userid,
        ], 'extensionduedate');
        $extension = $flags ? (int) $flags->extensionduedate : 0;
        if ($extension > 0) {
            /* Due date: core replaces it outright for this user — the grading
             * table computes lateness against the extension
             * (gradingtable.php:1115-1116), and save_user_extension()
             * guarantees the extension is later than the due date
             * (locallib.php:7012-7019), so the replacement only ever moves
             * the deadline forward. */
            $timecloses = $extension;

            /* Cutoff: core raises it only when a cutoff exists. With no
             * cutoff, submissions_open() never closes at all
             * (locallib.php:6288-6314), so inventing one here would be wrong. */
            if ($timecutoff !== null && $extension > $timecutoff) {
                $timecutoff = $extension;
            }
        }
```

`merge_override()` (the batch path used by `activity_schedule`) needs the same treatment via a pre-loaded flags map.

**Disputed.** Rev. 1 (M9) asserted "a null cutoff means the due date IS the final date"; `locallib.php:6310-6314` shows the opposite — with no cutoff, `$dateopen` only checks `allowsubmissionsfromdate`. Rev. 3 (C14) called raising `$timecutoff` only when non-null "the opposite of core"; core does exactly that (`if ($finaldate) { … }`, `:6301`). Both cutoff halves of rev. 1's original code were already right; only the *justification* for the `$timecloses` line needed a real citation, supplied above.

### 3.8 FIX-12 — grade type "None" / non-gradable activities

Two supported policies, chosen by a new site setting `measure_ungradable_activities` (default: **off**):

- **Off (default, mirrors core 5.x):** skip the activity entirely. Guard at the top of the upsert:
  ```php
  if (!self::is_activity_gradable((int) $cm->course, (int) $assign->id)) {
      return null;
  }
  ```
  using core's global `is_gradable(courseid, 'mod', 'assign', $assign->id)` — present on **all three** branches (`moodle-405/lib/gradelib.php:320`, same on 5.1/5.3), true only for `GRADE_TYPE_VALUE` / `GRADE_TYPE_SCALE`, and already used by mod_assign itself (`locallib.php:8810`) and by 5.x's overview badge (`classes/courseformat/overview.php:88`). Memoise per `(courseid, assignid)` for the request — `is_gradable()` does a `grade_item::fetch_all()`.
- **On:** measure them, with `grading_state::resolve()`'s non-gradable branch (a grade row whose `timemodified` is strictly later than the cycle's hand-in), which mirrors core's counter — the only core surface that ever clears such a submission.

**`grader > 0` is not used in either branch.** It is broken by restore (§0.4) and, in the non-gradable branch, would have promoted `revert_to_draft()` (`locallib.php:8347-8349`) into a "response". The `timemodified >` test avoids both: the auto-created placeholder copies the submission's `timemodified` (`:3993`) so it fails strictly, and a reverted submission leaves the measured population anyway because its `submissionstatus` becomes `'draft'`.

### 3.9 FIX-8 — team submissions

**1. Guard.** `if ($userid <= 0) { return null; }` at the top of the upsert (§3.2). Add `AND s.userid > 0` to `backfill_history.php`'s dispatch query (`:228-239`) so phantoms are never re-created. The upgrade step deletes the existing ones.

**2. Fan-out with its own record builder.** `upsert_for_cm_user_attempt()` cannot serve team members: it resolves `$submission` itself by `(assignment, member userid, attemptnumber)` (`:89-96`) and returns null when there is none — and with `requireallteammemberssubmit = 1`, non-submitting members have **no** `{assign_submission}` row at all (`locallib.php:6179-6182` mirrors only the acting user; the whole-team loop at `:7700-7710` runs only when the flag is off).

```php
    /**
     * Upsert one ledger row per member of a team submission.
     *
     * mod_assign stores the team's work in a single {assign_submission} row
     * with userid = 0 and groupid = G (locallib.php:3242-3244) and grades it
     * per member (save_grade -> apply_grade_to_user per member,
     * locallib.php:8862-8877). Timing and status therefore come from the group
     * row while the mark comes from each member's own {assign_grades} row.
     *
     * @param int $cmid
     * @param int $groupid The team's group id; 0 is mod_assign's default group.
     * @param int $attemptnumber
     * @return array Ledger row ids keyed by userid.
     */
    public static function upsert_for_team_attempt(int $cmid, int $groupid, int $attemptnumber): array
```

Behaviour:
1. Load the group row: `{assign_submission}` where `assignment = a.id AND groupid = :groupid AND userid = 0 AND attemptnumber = :att`. Its `status`, `timemodified` and `latest` are the authoritative facts for every member.
2. Enumerate members. For `$groupid > 0`: `{groups_members}` for that group, intersected with `get_enrolled_sql($ctx, '', [], true)`. For `$groupid == 0` (mod_assign's *default group*): every enrolled user for whom `groups_get_all_groups($courseid, $userid, $teamsubmissiongroupingid)` yields a count other than exactly 1 — the rule in `assign::get_submission_group()` (`locallib.php:3407-3424`). Skip the default group entirely when `$assign->preventsubmissionnotingroup` is set.
3. For each member, run the shared record builder with the **group** row's `status` / `timemodified` / `latest` and the **member's** `{assign_grades}` row, writing `teamgroupid = $groupid`. Cycle detection, stickiness and `grading_state::resolve()` are unchanged — factor the body of §3.2 into a private `build_and_store(...)` that both entry points call, so there is exactly one predicate implementation.
4. **Both** observers dispatch to it: `observer::submission_changed` when the loaded row has `userid == 0` or the assign has `teamsubmission`, and `observer::submission_graded` likewise (`$grade->userid` is always a real user, but the *timing* must still come from the group row — routing grades through the per-user path is what leaves `requireallteammemberssubmit = 1` teams permanently pending).
5. For large cohorts, dispatch step 3 as chunked adhoc `backfill_one_submission` tasks (with `courseid` in every row) rather than looping inline.

### 3.10 FIX-13 — concurrency

`dirty_queue::enqueue()` (`dirty_queue.php:60-82`) and the ledger insert both check-then-insert against UNIQUE indexes with no transaction and no catch.

```php
    /**
     * Insert one cycle row, tolerating a concurrent writer.
     *
     * @param \stdClass $record Fully built ledger record.
     * @param int $cmid
     * @param int $userid
     * @param int $attemptnumber
     * @param int $cycle
     * @return int Ledger row id.
     */
    private static function insert_cycle_row(
        \stdClass $record,
        int $cmid,
        int $userid,
        int $attemptnumber,
        int $cycle
    ): int {
        global $DB;
        try {
            return (int) $DB->insert_record('block_feedback_tracker_sub', $record);
        } catch (\dml_write_exception $e) {
            if ($DB->is_transaction_started()) {
                /* Inside a caller's transaction the failed INSERT has already
                 * poisoned the connection on PostgreSQL — query_end() throws
                 * without rolling back (lib/dml/moodle_database.php:472-504) —
                 * so the recovery SELECT would fail too and swallowing the
                 * error would corrupt the caller's transaction silently. Let
                 * it propagate: the event manager logs it via debugging()
                 * (lib/classes/event/manager.php:152-161) and the reconciler
                 * repairs the row on its next sweep. */
                throw $e;
            }
            /* A concurrent request inserted the same tuple between the read and
             * the write. Re-read and update: the observer must never abort the
             * user's grade or submission save. */
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
```

Apply the same shape to `dirty_queue::enqueue()`.

**Disputed (rev. 1, M2b, partially).** Rev. 1 was right that a blanket catch-and-retry is unsafe on PostgreSQL-in-transaction, and that is fixed above. It was wrong that the observer exception "aborts the caller's transaction" — `manager.php:152-161` catches `\Exception`; only the poisoned connection does that damage, which is why the in-transaction path rethrows instead of pretending to recover.

---

### 3.11 FIX-9 — the reconciler

Six classes of mutation emit nothing usable: `add_attempt()`, gradebook-side changes suppressed by `grading_disabled()` (including the C10 override/lock, which fires no event at all), blind-marking suppression, `reset_userdata()`, `assign_rescale_activity_grades()`, and the 5.3 `multimarkmethod = 'manual'` path. Events cannot cover them.

New file `classes/task/reconcile_ledger.php` (created **before** it is named in `db/tasks.php`), registered as:

```php
    [
        'classname' => 'block_feedback_tracker\task\reconcile_ledger',
        'blocking'  => 0,
        'minute'    => '15',
        'hour'      => '*/2',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
```

It runs seven keyset-paged, time-capped queries, gates every candidate on `course_access::is_processable()`, calls `submission_ledger::reset_skip_memo()` and `group_resolver::reset_memo()` between batches (I2), and repairs by dispatching adhoc `backfill_one_submission` tasks **with `courseid` in every row** — except the two cleanup paths (R4 deletion, R5 pruning) which must act directly, because that task re-gates on `is_processable()` (`:70-72`) and would silently skip hidden or block-less courses. All SQL uses `$DB` placeholders, avoids `NULLS FIRST`, and compares nullable columns through `COALESCE`.

**R1 — individual submissions with no ledger row** (covers `add_attempt`, `copy_previous_attempt` under drafts-ON, restores, the subplugin objectid bug, pre-install history):

```sql
SELECT s.id AS subid, cm.id AS cmid, cm.course AS courseid, s.userid, s.attemptnumber
  FROM {assign_submission} s
  JOIN {assign} a ON a.id = s.assignment
  JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
  LEFT JOIN {block_feedback_tracker_sub} l
         ON l.cmid = cm.id
        AND l.userid = s.userid
        AND l.attemptnumber = s.attemptnumber
 WHERE s.userid > 0
   AND a.teamsubmission = 0
   AND s.id > :lastid
   AND l.id IS NULL
 ORDER BY s.id ASC
```

The `{modules}` join is mandatory everywhere — `cm.instance = a.id` alone matches every module type sharing that instance id. (The existing `backfill_history.php:228-239` already gets this right.)

**R1b — team group rows with no fan-out** (dispatches `upsert_for_team_attempt`, never the per-user path):

```sql
SELECT s.id AS subid, cm.id AS cmid, cm.course AS courseid, s.groupid, s.attemptnumber
  FROM {assign_submission} s
  JOIN {assign} a ON a.id = s.assignment AND a.teamsubmission = 1
  JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
  LEFT JOIN {block_feedback_tracker_sub} l
         ON l.cmid = cm.id
        AND l.teamgroupid = s.groupid
        AND l.attemptnumber = s.attemptnumber
 WHERE s.userid = 0
   AND s.id > :lastid
   AND l.id IS NULL
 ORDER BY s.id ASC
```

**R2 — graded-state divergence** (blind marking, non-latest grading, gradebook override / deletion, workflow release, 5.3 manual multimark, cleared grades, re-grades, and any lost B2 event). **Cycle-scoped, `$hasmark`-mirroring, and time-scoped** — rev. 1's version had no cycle predicate (so it re-flagged every legitimately-pending cycle N > 0 for ever) and compared `timemarked` unconditionally (so every auto-created placeholder generated a repair task on every sweep, a whole-cohort storm every two hours):

```sql
SELECT l.id, l.cmid, l.courseid, l.userid, l.attemptnumber
  FROM {block_feedback_tracker_sub} l
  JOIN {course_modules} cm ON cm.id = l.cmid
  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
  JOIN {assign} a ON a.id = cm.instance
  LEFT JOIN {assign_grades} g
         ON g.assignment = a.id
        AND g.userid = l.userid
        AND g.attemptnumber = l.attemptnumber
  LEFT JOIN {assign_user_flags} uf
         ON uf.assignment = a.id
        AND uf.userid = l.userid
 WHERE l.id > :lastid
   AND l.iscurrent = 1
   AND (
        (l.timegraded IS NULL
         AND g.id IS NOT NULL
         AND g.grade IS NOT NULL
         AND g.grade >= 0
         AND g.timemodified >= l.timesubmitted
         AND (a.markingworkflow = 0 OR uf.workflowstate = :released))
     OR (l.timegraded IS NOT NULL
         AND (g.id IS NULL
              OR g.grade IS NULL
              OR g.grade < 0
              OR g.timemodified < l.timesubmitted
              OR (a.markingworkflow = 1
                  AND (uf.workflowstate IS NULL OR uf.workflowstate <> :released))))
     OR (g.id IS NOT NULL
         AND g.grade IS NOT NULL
         AND g.grade >= 0
         AND g.timemodified >= l.timesubmitted
         AND COALESCE(g.timemodified, 0) <> COALESCE(l.timemarked, 0))
   )
 ORDER BY l.id ASC
```

Bind `:released = 'released'`, `:modname = 'assign'`. Branch 3 now fires only for rows that actually carry a qualifying mark, so it converges. On a non-gradable assignment (`is_gradable()` false), run the mirrored variant that drops the `g.grade` tests and uses `g.timemodified > l.timesubmitted`, matching `grading_state::resolve()` — or skip those activities entirely under the default policy (FIX-12).

**R3 — `islatest` / status drift** (the `add_attempt` fingerprint). Cycle-scoped, because the repair only ever rewrites the current cycle and the set-based `islatest` update covers the rest:

```sql
SELECT l.id, l.cmid, l.courseid, l.userid, l.attemptnumber
  FROM {block_feedback_tracker_sub} l
  JOIN {course_modules} cm ON cm.id = l.cmid
  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
  JOIN {assign} a ON a.id = cm.instance
  JOIN {assign_submission} s
    ON s.assignment = a.id
   AND s.userid = l.userid
   AND s.attemptnumber = l.attemptnumber
 WHERE l.id > :lastid
   AND l.iscurrent = 1
   AND l.teamgroupid = 0
   AND (l.islatest <> s.latest
        OR l.submissionstatus <> COALESCE(s.status, :statusnew))
 ORDER BY l.id ASC
```

**R4 — orphaned ledger rows** (course reset, privacy deletion, raw removal). **Team-aware**, or the fan-out and the sweep delete and recreate each other's rows for ever; and keyed on `{assign_submission}`, not `{assign_grades}`, because a default course reset leaves grades intact (`locallib.php:1262-1263`):

```sql
SELECT l.id, l.courseid, l.groupid
  FROM {block_feedback_tracker_sub} l
  LEFT JOIN {course_modules} cm ON cm.id = l.cmid
  LEFT JOIN {assign_submission} s
         ON s.assignment = l.iteminstance
        AND s.attemptnumber = l.attemptnumber
        AND ((l.teamgroupid = 0 AND s.userid = l.userid)
             OR (l.teamgroupid > 0 AND s.userid = 0 AND s.groupid = l.teamgroupid))
 WHERE l.id > :lastid
   AND (cm.id IS NULL OR s.id IS NULL)
 ORDER BY l.id ASC
```

Delete these directly and enqueue their `(courseid, groupid)` tuples.

**R5 — rows for users who are no longer active participants** (H1, H2, H3). Run per course, reusing core's own definition so the plugin and core agree exactly:

```php
$context = \context_course::instance($courseid);
[$esql, $eparams] = get_enrolled_sql($context, '', 0, true);
$sql = "SELECT l.id, l.groupid
          FROM {block_feedback_tracker_sub} l
     LEFT JOIN ($esql) e ON e.id = l.userid
         WHERE l.courseid = :courseid
           AND l.id > :lastid
           AND e.id IS NULL
      ORDER BY l.id ASC";
$eparams['courseid'] = $courseid;
$eparams['lastid'] = $lastid;
```

**R6 — rule drift** (F6, and any override or extension change whose event was lost). Keyset-paged like the rest, and covering **revoked** extensions as well as granted ones:

```sql
SELECT l.id, l.cmid, l.courseid, l.userid, l.attemptnumber
  FROM {block_feedback_tracker_sub} l
  JOIN {assign} a ON a.id = l.iteminstance
  LEFT JOIN {assign_user_flags} uf
         ON uf.assignment = l.iteminstance
        AND uf.userid = l.userid
 WHERE l.id > :lastid
   AND (a.timemodified > l.timemodified
        OR (COALESCE(uf.extensionduedate, 0) > 0
            AND COALESCE(l.timecloses, 0) <> COALESCE(uf.extensionduedate, 0))
        OR (COALESCE(uf.extensionduedate, 0) = 0
            AND COALESCE(l.timecloses, 0) <> COALESCE(a.duedate, 0)
            AND l.hasrule = 1))
 ORDER BY l.id ASC
```

The third branch is the revoke probe: once `extensionduedate` returns to 0 the stored `timecloses` no longer matches the assign's own due date, which is the only evidence left.

**Cursor + budget.** Store one cursor per query in plugin config (the `backfill_effectivedays` pattern); process a bounded batch per tick under a soft time cap; when `reconcile_full_sweep_pending` is set (armed by the upgrade), sweep every query from id 0 once and clear the flag.

**Cannot be reconciled — documented limits.**
1. The original hand-in time of a submission edited after grading *before* this fix shipped is gone from Moodle entirely (`{assign_submission}` keeps one `timemodified`). Such rows get a pending cycle with the clock starting at the edit, and no fabricated history.
2. The release timestamp of a marking-workflow grade released before this fix shipped is likewise gone; the mark time is the documented lower bound.
3. `assign_rescale_activity_grades()` (`lib.php:1303`) changes values with no timestamp change, so R2's `timemarked` diff cannot see it. A value-level checksum would be needed; not worth it. States are unaffected.

---

## 4. Version compatibility (4.5 / 5.1 / 5.3-dev)

**Verified equivalences.**
- `mod/assign/classes/event/` is **byte-identical** across all three checkouts (`diff -rq` empty both ways; **35** files each). Every registration in FIX-6 is valid on 4.5, 5.1 and 5.3 with identical payload shapes. Version differences are only in *where core triggers*.
- `get_grading_status()` is identical (4.5 `:9432`, 5.1 `:9592`, 5.3 `:10196`), so `grading_state::resolve()` behaves identically on all three.
- `add_attempt()`, `save_submission()`, `update_grade()`, `gradebook_item_update()`, `reset_userdata()` and the `mod_assign_set_user_flags` WS have the same event gaps everywhere.
- `{assign_submission}` and `{assign_user_flags}` schemas are identical on all three; `{assign_user_flags}` has **no** timestamp column (`db/install.xml:129-139`), which is why FIX-6.5 must capture the release moment from the event.
- The global `is_gradable()` exists on all three (`lib/gradelib.php:320`), so FIX-12 needs no version guard.

**Version-specific cautions.**
1. **The needs-grading predicate is semantically identical but textually different** — 4.5 `' OR g.grade = -1'` (`:2530`) vs 5.1 `:2621` / 5.3 `:2666` `' OR g.grade = ' . ASSIGN_GRADE_NOT_SET`, with re-ordered clauses and the body relocated to `count_submissions_need_grading_with_groups()` (5.1 `:2612`, 5.3 `:2657`). The reconciler reimplements the predicate in its own SQL, so it works everywhere; **never call `count_submissions_need_grading_with_groups()`** — it does not exist on 4.5.
2. **`count_submissions_need_grading()` returns 0 for teams on all three** (4.5 `:2518-2521`, 5.1 `:2589-2594`, 5.3 equivalent). Only the `_with_groups()` variant counts teams. Do not cite 4.5-vs-5.1 as a behavioural difference here.
3. **`{assign_grades}.penalty` is 5.1+/5.3 only** (`moodle-501/public/mod/assign/db/install.xml:88`, `moodle/public/mod/assign/db/install.xml:91`). Never name it in a `SELECT` column list; the plugin's field-list-free `get_record('assign_grades', …)` is already safe.
4. **5.3 multi-marking tables do not exist on 4.5/5.1** (`{assign_mark}`, `{assign_allocated_marker}`, `assign.markercount` / `multimarkmethod` / `multimarkrounding`). Do **not** read them. `{assign_grades}` remains the contract on every branch: partial marks leave `grade = -1` (correctly pending, `locallib.php:3327-3330`), a completed multi-mark routes through `update_grade()` and fires `submission_graded` (`:3332-3337` → `:3351` / `:3375`), and only `multimarkmethod = 'manual'` is silent (`:3341`), which the reconciler covers. If a future version does read them, guard with `$dbman->table_exists('assign_mark')` and `property_exists($assign, 'markercount')` — never a Moodle-version comparison.
5. **`ASSIGN_ATTEMPT_REOPEN_METHOD_NONE` was removed in 5.3** (4.5 `:54`, 5.1 `:55`; absent on 5.3). The plugin does not reference it and must not start.
6. **`markinganonymous` is a 4.5 feature**, not 5.1+ (`moodle-405/mod/assign/db/install.xml:37`, `locallib.php:9851-9853`; 5.1 `:10012`). Any note claiming the blind-marking escape hatch is version-gated is wrong.
7. **`marker_updated` trigger sites differ** — one on 4.5/5.1 (batch only, `:8559`), three on 5.3 via `update_allocated_markers()` (declared `:7872`, trigger `:7900`). Only matters if FIX-6.6 is adopted; the observer is idempotent either way.
8. **`workflow_state_updated` has three trigger sites on 4.5/5.1 (`:7344`, `:8501`, `:8687`) and four on 5.3 (`:7815`, `:9112`, `:9367`, `:9418`).** FIX-6.5 handles duplicates naturally.
9. **Moodle tolerates observers on event class names that do not exist** — they simply never fire — so `db/events.php` never needs version guards. (`\assignfeedback_file\event\feedback_downloaded` is 5.1+ only; it is not registered anyway.)
10. **5.1+ split layout:** never hardcode `public/` inside plugin code; the plugin's CLIs already resolve `config.php` via a relative `__DIR__` path that lands on both layouts.
11. **Clock DI:** 5.1/5.3 replaced `time()` with `\core\di::get(\core\clock::class)->time()` inside `update_grade()`, `get_user_grade()` and the grading table. Values are still unix ints — no impact, but PHPUnit tests that freeze the clock must use the DI container on 5.1+ and plain `time()` on 4.5.
12. **`$plugin->supported = [405, 502]`** (`version.php:31`) is unchanged by this work, so `ci.yml` and the README compatibility table are untouched. The 5.3 assertions (C17-C19) can only be exercised after bumping `max` to `503`, adding the matching reusable-workflow job, updating the README in the same commit, and re-running `mdl mounts`.

**Testing obligations (all runnable locally — do not push to find out).**
- `mdl ci moodle-block_feedback_tracker --only phpcs,phpdoc`, then the full `mdl ci moodle-block_feedback_tracker`; the 4.5 leg explicitly with `--branch MOODLE_405_STABLE`, and `--db mariadb` because the reconciler adds SQL.
- `xmllint --noout --schema ~/dev/moodle-501/public/lib/xmldb/xmldb.xsd db/install.xml`.
- After installing the new observers, re-run `mdl phpunit-init` and `mdl behat-init` on both stacks; grep `tests/behat/` for any label touched by the new "Resubmitted" surfacing.

**New PHPUnit coverage that does not exist today** (`tests/local/sla/submission_ledger_test.php` and `observer_test.php` never re-save after grading, never touch teams, workflow, attempts or blind marking):

- **B2 end-to-end**: grade → re-save → assert cycle 0 keeps `timegraded = T2` and its `effectivehours`, cycle 1 exists with `timegraded IS NULL`, `iscurrent = 1`, clock from T3, and `rollup_service` reports pending +1 *and* the historical median unchanged. Then re-grade → assert cycle 1 closes at T4 and cycle 0 is still untouched.
- **I1** equal timestamps; **C8/FIX-12** grade type None under both policy settings; **C9** cleared grade re-opens the *same* cycle; **C13** non-latest attempt; **D1/D3/D5** workflow not-released → released → regressed, including the set-based release closure of an earlier marked-unreleased cycle; **E6** `add_attempt` then reconcile; **G1/G2/G3** team fan-out including `requireallteammemberssubmit = 1` (members with no submission row); **H12** a restored grade row with `grader = 0` must still read as graded; **R1-R6** each repair exactly once and are **idempotent — assert a second sweep dispatches zero tasks** (the convergence property rev. 1's SQL lacked).
- `submission_graded` events must be built via `\mod_assign\event\submission_graded::create_from_grade($assigninst, $grade)` — `::create()` throws.

**Existing tests that will need updating in the same commit.**

| Test | Line | Why |
|---|---|---|
| `tests/local/sla/submission_ledger_test.php::test_upsert_is_idempotent` | `:59-77` | `assertSame($a, $b)` / `assertSame(1, $count)` survive only because the fixture has no grade row. Extend it with a grade + a later `timemodified` and assert **two** rows with distinct ids — that is the new contract. |
| `tests/local/sla/submission_ledger_test.php` | `:170`, `:182` | `get_record(... ['cmid' => …])` becomes ambiguous once a cm carries two cycles; scope by `cycle` or use `get_records`. Multiple-records debugging is an unexpected-debugging failure under Moodle PHPUnit. |
| `tests/local/sla/submission_ledger_test.php::test_upsert_with_null_or_negative_grade_stays_pending` | `:242-291` | Helpers `insert_assign_grade` (`:399-406`) and `insert_assign_grade_raw` (`:420-427`) pass `'grader' => 2`; with `grader` removed from the predicate they are unaffected, but the final assertion at `:290` re-reads by the third upsert's return id and must be re-anchored on `(cmid, userid, attemptnumber, cycle)`. |
| `tests/local/sla/observer_test.php` | `:134-140` | Same `get_record` ambiguity. |
| `tests/privacy/provider_test.php` | `:82`, `:93`, `:110`, `:116` | Exact `count_records` assertions (1, 0, 2, 0) change for any fixture that grades then re-saves; the metadata test also gains six keys. |
| `tests/generator/lib.php::create_ledger_row` | `:125-154` | Add the six new defaults (see §3.4). |
| `tests/task/scheduled_tasks_test.php` | `:193-203` | Fails immediately if `db/tasks.php` names `reconcile_ledger` before the class exists. |
| `rollup_service_test`, `responsiveness_calculator_test`, `get_graded_submissions_test`, `get_pending_submissions_test`, `submission_browser_test`, `site_stats_service_test`, `trend_service_test`, `get_academic_days_test` | — | Generator-seeded rows default `islatest = 1` / `iscurrent = 1`, so the new pending filters keep them; add at least one fixture per suite with `islatest = 0` and one with `iscurrent = 0` to prove the filters bite. |

---

## 5. Prioritised implementation order

Each phase is independently shippable and independently testable. Phases 1-3 are the reported bug; everything after is coverage.

**Phase 0 — schema and scaffolding (no behaviour change).**
`db/install.xml` + `db/upgrade.php` (six columns, index rework, phantom purge, reconciler arming), `version.php` + `CHANGELOG.md`, privacy provider + both lang packs, `tests/generator/lib.php` defaults, and an empty-but-real `classes/task/reconcile_ledger.php`. Ship and verify the upgrade runs clean on m405 and m501 before touching any predicate.

**Phase 1 — the predicate (fixes B2, B1, C2, D1/D2, I1, I4).**
`classes/local/sla/grading_state.php`; the §3.2 replacement in `upsert_for_cm_user_attempt()` including the `userid <= 0` guard, the set-based `islatest`/`iscurrent` maintenance and `insert_cycle_row()` (FIX-13); FIX-12's `is_activity_gradable()` helper and setting. New PHPUnit for B2, I1, C9, C8, D1.

**Phase 2 — read paths (makes Phase 1 visible and correct).**
`islatest = 1 AND iscurrent = 1` on the four pending predicates; the dedicated `iscurrent = 1` fetch for `cur_median_*`; the "Resubmitted" label on `cycle > 0`; the `max(0, …)` clamp on `days_expr()`'s fallback. Fixes C13, E6-E8 as a side effect of `islatest`.

**Phase 3 — event coverage, mandatory tier.**
FIX-1 (`other['submissionid']`), then FIX-6.1, 6.2, 6.3, 6.5 (workflow + release closure), 6.7 (identities revealed). These are the registrations without which Phase 1 has blind spots on the most common configurations: drafts ON, marking workflow, blind marking, new attempts.

**Phase 4 — teams (FIX-8).**
Factor the record builder out of `upsert_for_cm_user_attempt()`, add `upsert_for_team_attempt()`, route both observers, add `s.userid > 0` to `backfill_history.php`. Team assignments are currently 100 % wrong; this is high value but touches the most code, so it lands after the predicate is proven.

**Phase 5 — the reconciler (FIX-9).**
R1, R1b, R2, R3 first (they cover `add_attempt`, blind marking, non-latest grading, C10, 5.3 manual multimark); then R4, R5 (cleanup, direct-acting); then R6. Assert idempotence — a second sweep must dispatch zero tasks — before enabling the schedule. This is also the only repair path for rows the earlier phases could not reach.

**Phase 6 — event coverage, secondary tier.**
FIX-6.4 (user overrides + the group-override targeting fix + the `get_records(..., 0, 1)` fix), 6.8 + FIX-10 (extensions), 6.9 (gradebook value changes and deletions — explicitly *not* C10), 6.10 (cm hidden / moved / dates), 6.11 (enrolment), 6.12 (user deleted).

**Phase 7 — optional and cleanup.**
FIX-6.6 (lock / unlock / marker allocation), the Graded-tab windowing decision, the live-vs-stored day-ruler reconciliation, and the documented non-reconcilable limits written into `CLAUDE.md` and the README.

---

## Disputed (rejected reviewer claims, with evidence)

1. **"FIX-10 raises the cutoff only when it already exists — the opposite of core"** (rev. 3, C14) and **"a null cutoff means the due date IS the final date"** (rev. 1, M9). Both wrong. `locallib.php:6301` is `if ($finaldate) { … }` — core also raises the cutoff only when one exists — and `:6310-6314` shows that with no cutoff, submissions never close at all. Only the *justification* for replacing `timecloses` needed a citation; supplied (`gradingtable.php:1115-1116`, `locallib.php:7012-7019`).

2. **"An exception out of the observer aborts the caller's transaction"** (rev. 1, M2a). Wrong: `lib/classes/event/manager.php:152-161` catches `\Exception` and downgrades it to `debugging()`. The genuine hazard is narrower — PostgreSQL connection poisoning after a failed INSERT inside an open transaction (`lib/dml/moodle_database.php:472-504` throws without rolling back) — and FIX-13 now handles exactly that, rethrowing instead of pretending to recover.

3. **"`$releasedat` plumbed through one caller ⇒ the D1 false-negative silently returns after a clear-and-regrade"** (rev. 3, C12). Only half right. Once `{assign_user_flags}.workflowstate` is `released`, `gradebook_item_update()` stops blanking the grade (`locallib.php:6124-6131`) and every later save reaches the student immediately — so the mark time **is** the correct stop time, not a degraded fallback. `$releasedat` matters only for the transition *into* `released`, which FIX-6.5 owns, and a stored `timereleased` column would add nothing. What rev. 3 did correctly expose — that a *marked-but-unreleased* cycle could be stranded with `timegraded` NULL — is fixed by the set-based release closure in FIX-6.5.

4. **"Graded-side reads must be cycle-filtered or they double-count"** (rev. 3, C8; rev. 2, C7). Accepted only for the "state right now" headlines (`cur_median_*`, where a closed cycle 0 must not be merged with its own live cycle 1 — that arm now uses `iscurrent = 1`) and for the pending population. Rejected for the 30-day statistical population: a teacher who responded twice generated two response events, and excluding the earlier one would re-create precisely the data destruction this design exists to prevent. `numgraded30d` counts responses, not students; that is documented rather than filtered away.

5. **"`grader > 0` is a reliable 'a human touched this' test"** (rev. 1's original premise, carried into rev. 1 of this document). Withdrawn entirely on rev. 1's own counter-evidence (`mod/assign/db/install.xml:84`, `restore_assign_stepslib.php:232`, `restore_structure_step.class.php:210-213`, `locallib.php:8347-8349`) — and because it was redundant anyway: `grade >= 0` alone already rejects every auto-created placeholder, which is what core itself relies on.