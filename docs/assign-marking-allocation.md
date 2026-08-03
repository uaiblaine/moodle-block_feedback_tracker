# Marking workflow + marking allocation support — design for `block_feedback_tracker`

Target repo: `/Users/uaiblaine/dev/moodle-block_feedback_tracker`
Current state: `version.php` → `$plugin->version = 2026080200`, `$plugin->release = '1.0.36'`, `$plugin->supported = [405, 502]`.
Core references: `/Users/uaiblaine/dev/moodle-405/mod/assign`, `/Users/uaiblaine/dev/moodle-501/public/mod/assign`, `/Users/uaiblaine/dev/moodle/public/mod/assign`.

**Revision note.** This is the second revision. Three adversarial reviews found a fatal self-contradiction in the first draft's closing rule and a dozen implementation defects; every accepted finding is folded in below, and the places where a reviewer was wrong carry a **Disputed** note. The largest changes: the closing rule is now conditional on the instance setting (§2.2), the predicate migration is all-or-nothing rather than "the `IS NULL` ones" (§2.6), `sla_clock` moves to phase 3 with an explicit recompute cost (§5), the current-marker clock is separated from the first-allocation clock (§2.3), and §9 enumerates the eight files the first draft never mentioned.

---

## 0. Corrections to the brief, and to the first draft

### 0.1 `markingallocation` implies `markingworkflow` — the 2×2 matrix is a 1×3

`add_instance()` and `update_instance()` both force `markingallocation = 0` when `markingworkflow` is empty:

```php
// 405 locallib.php:793-795 (add_instance) and :1585-1587 (update_instance).
// 501 locallib.php:793-795 / :1589-1591.  503 locallib.php:832-834 / :1614-1616.
$update->markingworkflow = $formdata->markingworkflow;
$update->markingallocation = $formdata->markingallocation;
if (empty($update->markingworkflow)) { // If marking workflow is disabled, make sure allocation is disabled.
    $update->markingallocation = 0;
}
```

The form-level `hideIf('markingallocation', 'markingworkflow', 'eq', 0)` (405 `mod_form.php:239`, 501/503 `mod_form.php:244`) is cosmetic; the server zeroing is the rule. **The quadrant (workflow OFF, allocation ON) cannot be created.** It can persist as stale data: switching workflow off zeroes the setting but leaves `assign_user_flags.allocatedmarker` / `assign_allocated_marker` rows untouched. Gate on the *instance setting*, never on row existence.

### 0.2 `submission_graded` fires at *marking* time on workflow assignments, not at release

`gradebook_item_update()` (405 `locallib.php:6114`, 501 `:6220`, 503 `:6487`) has exactly two early returns:

```php
// 405 locallib.php:6120-6122 — the blind-marking return.
if ($this->is_blind_marking() && !$this->is_marking_anonymous()) {
    return false;
}
// 405 locallib.php:6125-6131 — marking workflow MUTATES and falls through.
if ($this->get_instance()->markingworkflow && !empty($grade) &&
        $this->get_grading_status($grade->userid) != ASSIGN_MARKING_WORKFLOW_STATE_RELEASED) {
    $grade->grade = -1;
    $grade->feedbacktext = '';
    $grade->feebackfiles = [];
}
// …
// 405 locallib.php:6153-6155 — the grading_disabled return.
if ($this->grading_disabled($gradebookgrade['userid'])) {
    return false;
}
// 405 locallib.php:6160
return assign_grade_item_update($assign, $gradebookgrade) == GRADE_UPDATE_OK;
```

`convert_grade_for_gradebook()` (405 `:6065`, 501 `:6171`, 503 `:6438`) maps `-1` to `rawgrade NULL` and `grade_update()` returns `GRADE_UPDATE_OK`, so:

```php
// 405 locallib.php:3029-3031 (trigger at :3030); 501 trigger at :3170; 503 trigger at :3215.
if ($this->gradebook_item_update(null, $grade)) {
    \mod_assign\event\submission_graded::create_from_grade($this, $grade)->trigger();
}
```

**fires.** Today `observer::submission_graded()` therefore stamps `timegraded` the moment the teacher first saves a mark in state `inmarking`, days before the student can see anything. That is the `FALSE-GRADED` verdict throughout §1.

Note also that `$DB->update_record('assign_grades', $grade)` happens at **405 `locallib.php:3011`**, *before* the `-1` mutation, so `assign_grades` keeps the real grade and the plugin's existing `grade >= 0` gate (`submission_ledger.php:117-124`) does fire.

### 0.3 Corrections to the brief's "already established" list

**Two additions, both genuine `MISSED-no-event` paths the brief omitted:**

**(a) `update_grade()` returns early for a non-latest attempt.**

```php
// 405 locallib.php:3024-3027
// Only push to gradebook if the update is for the most recent attempt.
if ($submission && $submission->attemptnumber != $grade->attemptnumber) {
    return true;
}
```

Grading a superseded attempt never reaches `gradebook_item_update()` and fires **no** `submission_graded`. This interacts directly with rule R5.

**(b) `grading_disabled()` suppresses the event for markers without release rights.** `gradebook_item_update()` calls it with the default `$checkworkflow = true`:

```php
// 405 locallib.php:7781-7788
public function grading_disabled($userid, $checkworkflow = true, $gradinginfo = null) {
    if ($checkworkflow && $this->get_instance()->markingworkflow) {
        $grade = $this->get_user_grade($userid, false);
        $validstates = $this->get_marking_workflow_states_for_current_user();
        if (!empty($grade) && !empty($grade->workflowstate) && !array_key_exists($grade->workflowstate, $validstates)) {
            return true;
        }
    }
```

A marker **without** `mod/assign:releasegrade` saving against a row already in `readyforrelease`/`released` gets no `submission_graded` at all. This is a third suppressor, orthogonal to blind marking and to a locked/overridden gradebook grade, and it is why §1 B3–B5 are qualified rather than unconditional.

Everything else in the brief's list is confirmed as written.

### 0.4 Corrections to the first draft of this design

| # | First draft said | Truth | Where fixed |
|---|---|---|---|
| D1 | `timeclosed = timereleased ?? timegraded` | Closes workflow rows at marking time — i.e. it *preserves* the bug it exists to fix, and contradicts §1 B3 and the phase-1 test | §2.2 |
| D2 | Upgrade seeds `timeclosed = timegraded` unconditionally | Cements FALSE-GRADED permanently for all historical data | §3.6 |
| D3 | Phase 1 renames "the `timegraded IS NULL` predicates" | Leaves 20+ graded-window predicates on `timegraded`; a row becomes pending **and** graded at once | §2.6 |
| D4 | `sla_clock` is a read-time switch | Every interval is a materialised column; flipping it recomputes nothing | §5.1 |
| D5 | R2 "`timegraded` is sticky", unconditional | Reintroduces a 0-hour `excellent` row after revert-to-draft + resubmit | R2 in §2.5 |
| D6 | R4 compared `timeallocated >= timegraded` in prose, `>= timeclosed` in code | One endpoint: `timegraded` | R4 in §2.5 |
| D7 | 5.3 fires `marker_updated` "on every save" | Only the grading form is unconditional (503 `:9424-9425`); quick grading is gated on `$markingallocationchanged` (503 `:7822-7823`), batch on workflow state (503 `:9166-9174`) | §1 C5, §7 |
| D8 | §7: "batch/quick: graded then workflow" | Quick grading fires workflow **first** (405 `:7344`) then `update_grade()` (405 `:7347`). Only **batch** is graded-then-workflow (405 `:8499` / `:8501`). §1 B5 had it backwards too | §1 B4/B5, §7 |
| D9 | B2 "teacher opens grading page (flags row auto-created)" | `get_user_flags($userid, true)` is never called from a view path; all eight 405 call sites are actions | §1 B2 |
| D10 | Index names "are short on purpose" | The XMLDB `NAME` attribute never reaches the physical name — `sql_generator::getNameForObject()` (`lib/ddl/sql_generator.php:1080-1130`) builds it from the **table** tokens (4 chars each) + **field** names (3 chars each) | §3.3 |
| D11 | Restore is a 5.3-only un-eventful write path | 405 `restore_assign_stepslib.php:205-206` restores `allocatedmarker` too, on every branch | §6.6 |
| D12 | Observers read `get_config(...) ?? 1` | `get_config()` returns `false`, never `null` → `(int) false === 0` → **both observers disable themselves on every fresh install** | §4.2 |
| D13 | `latest_row_at()` had no `submissionstatus` filter | `timesubmitted` is `NOTNULL DEFAULT 0`, so a `new`/`reopened` row always satisfies `timesubmitted <= :when` and outranks the real attempt | §4.4 |
| D14 | `allocatedmarkerid` defined three inconsistent ways | One rule: lowest non-zero allocated marker id, re-read from core, on both the event and reconciler paths | §2.3, §4.4 |
| D15 | No calver bump in the upgrade step | The payload MUC caches are keyed on `calendar::current_version()` (`responsiveness_payload.php:84`, `get_dashboard.php:99-101`) — without it, stale payloads are served | §3.6 |
| D16 | Lang list grouped `settings_sla_clock` with `_desc` | Strict alphabetical: `…_clock`, `…_clock_alloc`, `…_clock_desc`, `…_clock_grade`, `…_clock_release` | §5.3 |
| D17 | Marker reassignment left `timeallocated` frozen while `allocatedmarkerid` moved | Charges marker B for marker A's idle time | §2.3 (`timeallocmarker`) |
| D18 | Eight touched files never mentioned; a backfill task was assumed but never defined | — | §9 |
| D19 | Team submissions absent from the model | `assign_submission` group rows carry `userid = 0` and already enter the ledger | §1 T1, §2.7 |

### 0.5 Schema limits (relevant to §3)

`xmldb_table::NAME_MAX_LENGTH = 63 - PREFIX_MAX_LENGTH(10) = 53` (`lib/xmldb/xmldb_table.php:41,50`); `xmldb_field::NAME_MAX_LENGTH = 63` (`xmldb_field.php:81`). The **30-char limit is on physical key/index names** (`lib/ddl/sql_generator.php:127-128`, `$names_max_length = 30`), truncated and counter-disambiguated by the generator. The existing `block_feedback_tracker_bfcursor` (31 chars) is legal for exactly that reason. This design adds **one** table, `block_feedback_tracker_alloc` (28 chars), in the last phase.

---

## 1. Lifecycle map

Legend for **Verdict**:
`OK` · `FALSE-PENDING` (student has feedback, ledger says pending) · `FALSE-GRADED` (student has nothing, ledger says graded) · `MISSED-no-event` (core writes state, no event) · `CLOCK-POLLUTED` (a timestamp moves for a reason unrelated to turnaround).

### 1.A — `markingworkflow = 0` (allocation forced 0). The baseline that must not change.

| # | Trigger | Core tables written | Events fired | Student sees | Plugin records today | Plugin SHOULD record | Verdict |
|---|---|---|---|---|---|---|---|
| A1 | Student submits | `assign_submission` (`status='submitted'`, `timemodified`) | `\mod_assign\event\assessable_submitted`; `\assignsubmission_*\event\submission_created` | "Submitted for grading" | `timesubmitted`; row pending | same | **OK** |
| A2 | Teacher saves grade (form / quick / WS) | `assign_grades`; gradebook `grade_grades` | `submission_graded` (`objectid`=`assign_grades.id`, `relateduserid`=student, `contextinstanceid`=cmid) | Grade + feedback **immediately** | `timegraded` (gated `submission_ledger.php:117-124`) | `timegraded`; `timereleased = timegraded`, `releasesource='implicit'`; `timeclosed = timereleased` | **OK** |
| A3 | Blind marking on, not marking-anonymous | `assign_grades` only | **none** (405 `:6120-6122`) | Nothing until identities revealed | stays pending forever | same, plus a `blind` exclusion flag so it leaves the medians | **MISSED-no-event** (accepted; core cannot tell us) |
| A4 | Gradebook grade locked or overridden | `grade_grades` | none from assign | nothing new | stays pending forever | mark `blocked`, exclude from pending medians | **FALSE-PENDING** (pre-existing, out of scope) |
| A5 | `revert_to_draft` | `assign_submission.status='draft'` (405 `:8336`); `update_grade()` bumps `assign_grades.timemodified` (405 `:8349`) | `submission_status_updated` (405 `:8356`) | back to draft | leaves the SLA scope on status | ignore `timegraded` when `submissionstatus !== 'submitted'`; do not close | **CLOCK-POLLUTED** |
| A6 | `add_attempt` (reopen) | new `assign_submission`, `attemptnumber+1`, `status = ASSIGN_SUBMISSION_STATUS_REOPENED` (405 `:9119`); flags only unlocked (405 `:9130-9133`) | `submission_status_updated` | may resubmit | new ledger row, **status `reopened` → not pending** until resubmit | same | **OK** |
| A7 | Grade a superseded attempt | `assign_grades` for that attempt | **none** (405 `:3025-3027`) | nothing | nothing | nothing; document the blind spot | **MISSED-no-event** |

### 1.B — `markingworkflow = 1`, `markingallocation = 0`

| # | Trigger | Core tables written | Events fired | Student sees | Plugin records today | Plugin SHOULD record | Verdict |
|---|---|---|---|---|---|---|---|
| B1 | Student submits | `assign_submission` | `assessable_submitted` | "Submitted" | `timesubmitted`; pending | + `markingworkflow=1` snapshot, `workflowstate='notmarked'` | **OK** |
| B2 | First grading **action** on a student (grade save 405 `:8679`, extension `:7023`, lock `:8426`, unlock `:8610`, batch `:8470`/`:8546`, quick `:7309`, `notify_grade_modified` `:2944`) creates the flags row | `assign_user_flags` INSERT (`workflowstate=''` 405 `:3936`, `allocatedmarker=0` `:3937`) | **none** | nothing | nothing | treat `''` ≡ `NULL` ≡ `notmarked` — never a state change | **OK** (must not be read as a transition) |
| B3 | Grading form save → `inmarking` | `assign_user_flags.workflowstate`; `assign_grades`; gradebook gets `rawgrade NULL` | `workflow_state_updated` (405 `:8687`) **then** `submission_graded` (`update_grade()` at 405 `:8724`) | **NOTHING** — grade hidden, feedback forced empty | **`timegraded` set → row closes, SLA declared met** | `timegraded` (marker clock); `timereleased` NULL → `timeclosed` NULL → **row stays pending** | **FALSE-GRADED** ← the core bug |
| B4 | Quick grading, state change | `assign_user_flags`, `assign_grades` | `workflow_state_updated` (405 `:7344`) **then** `update_grade()` (405 `:7347`) | nothing | same as B3 | same as B3 | **FALSE-GRADED** |
| B5 | Batch "Set marking workflow state" | `assign_user_flags` ×N; `update_grade()` per user | `submission_graded` (via `update_grade()` 405 `:8499`) **then** `workflow_state_updated` (405 `:8501`) — **grade first on this path only** | nothing unless target is `released` | **`timegraded` re-stamped on every transition** | R2: only the first valid grade write sets `timegraded` | **CLOCK-POLLUTED** + **FALSE-GRADED** |
| B6 | Transition → `released` | `assign_user_flags.workflowstate='released'`; gradebook gets the real grade | `workflow_state_updated` (`other['newstate']='released'`) + `submission_graded` | **grade + feedback visible now** | nothing new (row "graded" since B3) | `timereleased = max(event time, timegraded)`, `releasesource='event'`; `timeclosed = timereleased`; close | **FALSE-GRADED** resolved here |
| B7 | Release via `mod_assign_set_user_flags` WS | `assign_user_flags.workflowstate` only | **none** (405 `externallib.php:961-1054`, cap `mod/assign:grade` at `:970`, no `->trigger()` in the method) | grade visible on the assign page; gradebook stale | nothing | reconciler poll | **MISSED-no-event** |
| B8 | Un-release (`released` → `inmarking`) | `assign_user_flags`; gradebook grade wiped to `rawgrade NULL` | `workflow_state_updated` | grade **disappears** | nothing | `workflowstate='inmarking'`; **keep** `timereleased` (R1); set a `rereopened` display flag | **OK** with the sticky rule |
| B9 | `add_attempt` after release | new submission row (`reopened`); `workflowstate` **untouched** (405 `:9076-9134`) | `submission_status_updated` | may resubmit | new row, not pending until resubmit | new row must **not** inherit `timereleased`; `workflowstate` is user-scoped, so mirror it but never the timestamps | **OK** — the real hazard is the reconciler (R5 + §4.5) |
| B10 | Marker without `releasegrade` saves against `readyforrelease`/`released` | `assign_grades` | **none** (`grading_disabled()` true, 405 `:7785-7787`) | nothing | nothing | nothing recoverable; reconciler still sees the state | **MISSED-no-event** |
| B11 | Course reset | `assign_user_flags` rows deleted (405 `:899`, `:1254`) | none | — | — | no flags row → `notmarked`, never a release | **MISSED-no-event** (benign) |

### 1.C — `markingworkflow = 1`, `markingallocation = 1`

| # | Trigger | Core tables written | Events fired | Student sees | Plugin records today | Plugin SHOULD record | Verdict |
|---|---|---|---|---|---|---|---|
| C1 | Student submits, no marker yet | `assign_submission` | `assessable_submitted` | "Submitted" | pending; SLA clock running | `timesubmitted`; `timeallocated` NULL → **coordinator queue time**, not marker time | **OK** (attribution wrong downstream — see C9) |
| C2 | **Batch** allocation | 4.5/5.1 `assign_user_flags.allocatedmarker`; 5.3 DELETE-all + INSERT `assign_allocated_marker` | `marker_updated` (`objectid`=assign.id, `objecttable='assign'`, `relateduserid`=student, `other['markerid']`). 405 `:8559` / 501 `:8719` / 503 `:7900`. **Guarded server-side**: skipped when the state is already `readyforreview`/`inreview`/`readyforrelease`/`released` (405 `:8546-8553`, 503 `:9166-9172`) | nothing | **nothing** | `timeallocated` (R1 first-wins), `timeallocmarker`, `allocmarkerid`, `allocsource='event'` | **MISSED-no-event** today → fixed by observer |
| C3 | **Grading-form** allocation, 4.5/5.1 | `assign_user_flags.allocatedmarker` (405 `:8682`) | **none** — the trigger at 405 `:8683-8689` is guarded on `isset($formdata->workflowstate) && $formdata->workflowstate !== $oldworkflowstate`, so `marker_updated` is unreachable. 501 identical (`:8842` / `:8843-8848`) | nothing | nothing | reconciler poll only | **MISSED-no-event** (permanent on 405/501) |
| C4 | **Quick-grading** allocation, 4.5/5.1 | `assign_user_flags.allocatedmarker` (405 `:7339`) | none — `&& $workflowstatemodified` guard (405 `:7341-7346`) | nothing | nothing | reconciler poll only | **MISSED-no-event** (permanent on 405/501) |
| C5 | Allocation on **5.3** | `assign_allocated_marker` delete-all + re-insert (503 `:7872-7902`) | `marker_updated` once per surviving marker (503 `:7900`). Reached **unconditionally from the grading form** (503 `:9424-9425`, but only when `!teamsubmission`); from quick grading **only** when `$markingallocationchanged` (503 `:7822-7823`, computed at `:7783-7785`); from batch only past the workflow-state guard (503 `:9174`) | nothing | nothing | dedupe: stamp `timeallocated` only while NULL | **OK** with dedupe |
| C6 | Allocation removed / cleared, any version | 4.5/5.1 `allocatedmarker=0`; 5.3 row DELETE (`array_filter()` at 503 `:7884` drops marker 0, so no event for the removal) | **none in any version** — no `marker_removed` class exists | nothing | nothing | reconciler clears `allocmarkerid`; **do not** clear `timeallocated` | **MISSED-no-event** |
| C7 | Allocation via `mod_assign_set_user_flags` | 4.5/5.1 writes `allocatedmarker` with only `mod/assign:grade`. **5.3: parameter still declared (`externallib.php:957`, `:1003-1004`) but the column was dropped → `update_record()` silently discards it** | none | nothing | nothing | reconciler on 405/501; on 503 nothing to reconcile — document the silent no-op | **MISSED-no-event** |
| C8 | Allocated marker saves a mark | as B3 + (5.3) `assign_mark` row with real `timecreated`/`timemodified` (503 `db/install.xml:180-181`) | as B3 (+ 5.3 aggregation event, 503 `:9367`) | nothing | `timegraded` set → row closes | `timegraded`; marker turnaround = `timeallocmarker → timegraded` | **FALSE-GRADED** |
| C9 | Coordinator sits on it 10 days, marker grades in 2 h | — | — | nothing for 10 days | `waitinghours=242.00`, `effectivehours≈82.00`, bucket `regular`, group `compliance_pct=0`, `responsiveness_score≈27.78` band `critical` | `queuehours≈80.00` (coordinator), `allochours=2.00` bucket `excellent` (marker), student wait unchanged at 82 h | **OK** only after §2 — today 100% of the number is coordinator-caused and 0% is separable |
| C10 | Allocation changed **after** grading/release | 4.5/5.1 overwrite; 5.3 delete+insert. Batch is guarded server-side (405 `:8546-8553`); the form's `disabledIf` calls (405 `:8026-8029`) and the grading table's render guard (`gradingtable.php:726-729`) are client-side only, so a crafted POST still reaches 405 `:7339`/`:8682` | `marker_updated` on batch/5.3 paths only | nothing | nothing | R4: if the allocation instant is `>= timegraded`, `allocsource='late'` and every `alloc*` measure is **NULL** | **CLOCK-POLLUTED** if unguarded |
| C11 | 5.3 site upgraded from 4.5/5.1 | `assign_allocated_marker` gets one row per pre-existing flags row, most with `marker = 0` — the `INSERT … SELECT` at 503 `db/upgrade.php:200-204` has **no `WHERE` clause**; `drop_field` at `:205` | none | nothing | nothing | **every 5.3 allocation query must carry `AND am.marker > 0`** | **CLOCK-POLLUTED** if omitted (phantom allocations on essentially every graded student) |
| C12 | 5.3 batch with `workflowcontext === 'mark'` | `assign_mark`; `assign_user_flags.workflowstate` set to the **aggregate** by `calculate_and_save_overall_workflow_state()` (503 `:9096`, `$flags->workflowstate = $overall` at `:9360`) — the per-marker `$state` is deliberately **not** written (503 `:9060-9062`, `:9073-9075`) | **two** events: the aggregate one at 503 `:9367`, then a second at 503 `:9112` carrying the **per-marker** `$state` | nothing | nothing | the event's `newstate` is **not** authoritative for the mirrored column — see R6 | **CLOCK-POLLUTED** if the payload is trusted |

### 1.T — Team (group) submissions, any workflow setting

| # | Trigger | Core | Events | Plugin records today | SHOULD | Verdict |
|---|---|---|---|---|---|---|
| T1 | Group submits | `assign_submission` row with **`userid = 0`**, `groupid` set (handled explicitly at 405 `:6134-6144`) | `assessable_submitted` with the group submission id | `observer::submission_changed()` feeds `assign_submission.userid` straight into `upsert_for_cm_user_attempt()` (`observer.php:62-74`), so a **`userid = 0` ledger row already exists today** | a `userid = 0` row can never acquire an `assign_grades` row, an `assign_user_flags` row or an allocation — it must not be counted as pending, unallocated, or in `alloccoverage` | **FALSE-PENDING** (pre-existing; becomes a *loud* wrong number once `unallocated` ships) |

`grep` confirms the string `teamsubmission` appears nowhere in the plugin. Phase 1 adds `AND userid > 0` to the new counts and a lifecycle decision (per-member vs per-group) is required **before** phase 2 ships `unallocated`.

---

## 2. The four-timestamp model

### 2.1 Definitions

| Timestamp | Exact source | Delivering event | When the event never fires | When the feature is off |
|---|---|---|---|---|
| **`timesubmitted`** | `{assign_submission}.timemodified` for `(assignment, userid, attemptnumber)`, read live at `submission_ledger.php:107` | `assessable_submitted`, `submission_status_updated`, `\assignsubmission_*\event\submission_created` | `backfill_history` walks `{assign_submission}` — always recoverable | unchanged |
| **`timeallocated`** / **`timeallocmarker`** | **No core source.** 4.5/5.1 `{assign_user_flags}.allocatedmarker`; 5.3 `{assign_allocated_marker}` — **neither has a timestamp column** (405/501 `db/install.xml` flags block; 503 `db/install.xml:135-152` and `:193-206`) | `marker_updated` → `other['markerid']`, `relateduserid`, `contextinstanceid` | `reconcile_workflow` polls and stamps `time()`, `allocsource='reconciled'` — **resolution = cron period**. Log-store backfill for historic batch allocations (§6) | `NULL`, `allocsource='none'`; every `alloc*`/`queuehours` measure `NULL` |
| **`timegraded`** | `{assign_grades}.timemodified`, gated exactly as today (`submission_ledger.php:117-124`) | `submission_graded` | Blind marking (405 `:6120`), `grading_disabled` (405 `:6153`, incl. the workflow-state check `:7785`), non-latest attempt (405 `:3025`), locked/overridden gradebook grade — all leave it NULL | unchanged. **Conditionally sticky** — see R2 |
| **`timereleased`** | `{assign_user_flags}.workflowstate == 'released'` — the same column on **all three versions** | `workflow_state_updated`, `other['newstate'] === 'released'`, `relateduserid` = student. Class is byte-identical across 4.5/5.1/5.3 | `mod_assign_set_user_flags` fires nothing → reconciler, `releasesource='reconciled'`. Log-store backfill (§6) | `markingworkflow = 0` → `timereleased = timegraded`, `releasesource='implicit'` — **byte-identical to today's numbers** |

### 2.2 The closing timestamp — `timeclosed`

**This is the rule the first draft got wrong.** It is conditional on the snapshotted instance setting, not a bare coalesce:

```
timeclosed = (markingworkflow === 1) ? timereleased : timegraded
```

With, at stamping time, `timereleased = max($observedinstant, $timegraded ?? 0)` — the form path fires `workflow_state_updated` (405 `:8687`) *before* `update_grade()` (405 `:8724`), so a naive assignment could close the row seconds before the feedback existed.

Consequences, stated plainly:

* **`markingworkflow = 0`:** `timeclosed ≡ timegraded` for every row. Every displayed number is bit-identical to before the upgrade. This is the majority of installed assignments and the regression gate in the phase-1 tests.
* **`markingworkflow = 1`, graded but not released:** `timeclosed` is NULL. The row **stays pending**. This is the fix. It is also a visible, possibly large, backlog appearing on upgrade — see §3.6 and the README note.
* **`markingworkflow = 1`, released with no grade** (blind marking + workflow, B10): `timeclosed = timereleased` with `timegraded` NULL. Because every window predicate keys on `timeclosed` (§2.6), the row lands in the graded window rather than vanishing.

Stored as a real column, not computed in SQL, so the predicate stays indexable on MariaDB (a `COALESCE()` / `CASE` predicate is not).

### 2.3 The intervals

`waitinghours`, `effectivehours`, `effectivedays` and `slabucket` **change meaning** from "submission → grading" to "submission → close". Their `install.xml` `COMMENT`s, the README, `docs/` and the WS field descriptions change in the same commit (§9).

| Interval | Formula | Owner | Stored as |
|---|---|---|---|
| **Student wait** | `timesubmitted → (timeclosed ?? now)` | the institution | `waitinghours`, `effectivehours`, `effectivedays`, `slabucket` — existing columns, redefined endpoint |
| **Allocation lead time** | `timesubmitted → (timeallocated ?? now)` | coordinator / allocation manager | `queuehours` |
| **Marker turnaround** | `timeallocmarker → (timegraded ?? timeclosed ?? now)` | the **currently allocated** marker | `allochours`, `allocdays`, `allocbucket` |
| **Release lag** | `timegraded → (timereleased ?? now)` | reviewer / releaser | derived in SQL as `(timereleased - timegraded)` — **not stored** |

Two allocation timestamps, not one — this is D17:

* **`timeallocated`** is the **first ever** allocation for this ledger row. R1-sticky. It closes the coordinator queue metric and must not move.
* **`timeallocmarker`** is the instant the **current** marker was allocated. It moves whenever `allocmarkerid` changes to a different non-zero marker. Without it, marker A is allocated on day 0, removed on day 8 (no event exists in any version), B is allocated on day 8 and grades on day 9 — and the row reports `allocmarkerid = B` with a 9-day turnaround. Phase 4's per-marker table supersedes this pair; phase 2 needs both or every reassignment in the interim produces an unrecoverable wrong number.

`allocmarkerid` has exactly **one** definition: **the lowest non-zero currently allocated marker id, re-read from core**. The observer does not trust the event payload for this — 5.3 fires one event per marker in insertion order (503 `:7885-7901`), so "the last event wins" and "`MIN(am.marker)`" disagree on every multi-marker student and the reconciler would rewrite the row every 15 minutes forever.

Raw wall-clock variants of the alloc/queue/release-lag intervals are not stored: they are exact arithmetic on two stored integers, unlike effective hours which needs `academic_time`.

### 2.4 Which clock drives the score

**Phase 1 changes the endpoint unconditionally.** There is no setting in phase 1 — see §5.1 for why a read-time switch over materialised columns is not implementable, and where the escape hatch actually lands.

From phase 3, `sla_clock` selects the interval that `recompute_measures()` writes into `effectivehours`, with a full-ledger recompute as its explicit cost:

| `sla_clock` | Interval feeding `effectivehours` → `compliance_pct`, `median_eff_h`, `critical`, `responsiveness_score` |
|---|---|
| `submission_release` **(default)** | `timesubmitted → timeclosed` |
| `submission_grade` | `timesubmitted → timegraded` — literal pre-1.0.37 behaviour, for sites freezing historical numbers |
| `allocation_grade` | `timeallocmarker → timegraded`, rows with `timeallocmarker IS NULL` excluded from the score sample; `waitinghours`/`effectivedays`/`slabucket` keep the student-wait endpoint so `submission_browser` and `get_grader_priority_list` stay coherent |

On a site with `markingworkflow = 0` everywhere, `timeclosed ≡ timegraded`, so `submission_release` and `submission_grade` are numerically identical.

### 2.5 Invariant rules (six, all load-bearing)

**R1 — First transition wins.** `timeallocated` and `timereleased` are stamped only when currently `NULL`. Re-allocation, un-release/re-release and 5.3's delete-and-reinsert must not move them. (`timeallocmarker` is the deliberate exception; it tracks the current marker.)

**R2 — `timegraded` is sticky *while the attempt is still the same submission*.** Once set from a valid `{assign_grades}` row it survives later `timemodified` bumps — `process_set_batch_marking_workflow_state()` calls `update_grade()` on every transition (405 `:8499`) and `revert_to_draft()` calls it with no grading at all (405 `:8349`). **But it is cleared whenever `timesubmitted` moves past it, or `submissionstatus` leaves `'submitted'`.** `revert_to_draft()` does not create a new attempt, so an unconditional sticky rule survives the resubmit and yields `timegraded < timesubmitted` → `waitinghours = max(0.0, negative) = 0.0` → `academic_time` short-circuits (`classes/local/calendar/academic_time.php:98-100`) → `bucket::for_effective(0.0)` returns `excellent` (`bucket.php:51-58`, first default threshold 24) → counted as SLA-met. That is R4's failure mode on the main clock.

```php
// Sticky, but only for the same submission instant and while still submitted.
if ($existing !== null && $existing->timegraded !== null
        && (int) $existing->timegraded >= $timesubmitted
        && $submissionstatus === submission_status::SUBMITTED) {
    $timegraded = max($timegraded ?? 0, (int) $existing->timegraded) ?: null;
}
```

**R3 — Empty string is `notmarked`.** `get_user_flags($userid, true)` seeds `workflowstate = ''` (405 `:3936`) and `get_grading_status()` maps empty → `notmarked` (405 `:9432-9449`). Every predicate uses `(workflowstate IS NULL OR workflowstate = '' OR workflowstate = 'notmarked')`. A bare `= 'notmarked'` matches almost nothing.

**R4 — A non-positive interval is `NULL`, never zero. One endpoint: `timegraded`.** Guard, applied identically in `apply_allocation()` and `recompute_measures()`:

```php
if ($timeallocmarker !== null && $timegraded !== null && $timeallocmarker >= $timegraded) {
    $allocsource = 'late';   // Detected after the fact; the interval is unmeasurable.
    $allochours = null;
    $allocdays = null;
    $allocbucket = null;
}
```

The same non-positive-interval rule applies to the **student clock**: `recompute_measures()` writes `NULL` for `effectivehours`/`effectivedays` and `slabucket = 'pending'` rather than `0.0`/`excellent` when `timeclosed <= timesubmitted`. Rows with `allocsource IN ('late','reconciled')` are excluded from the headline alloc medians, and `alloccoverage` is published beside them.

**R5 — `assign_user_flags` is user-scoped, not attempt-scoped.** No `attemptnumber` column in any version, and `add_attempt()` never resets `workflowstate` (405 `:9076-9134`). A workflow/allocation observation resolves to the **highest `attemptnumber`** ledger row for `(cmid, userid)` with `submissionstatus = 'submitted'` and `timesubmitted <= $when`; `timereleased`/`timeallocated` are **never** copied to a new attempt row. The reconciler does not re-derive at all — it passes the `subid` it already selected (§4.5), because `$when = time()` would otherwise resolve to a brand-new attempt nobody has looked at.

**R6 — The event's `newstate` decides *nothing* on its own.** `workflowstate` is mirrored from the **live** `{assign_user_flags}.workflowstate`, and a `released` stamp is confirmed against it before `timeclosed` is written. Required by C12: on 5.3 the second batch event (503 `:9112`) carries a per-marker `$state` that is not what the table holds, while the aggregate event (503 `:9367`) carries the value that is. Trusting the payload makes the mirrored column flap on every cron run and permanently re-dirty the rollup. The confirmation is one indexed read on an event that fires rarely.

### 2.6 The predicate migration is all-or-nothing

**This cannot be split across phases.** If the `IS NULL` predicates move to `timeclosed` while the graded-window predicates stay on `timegraded`, a marking-workflow row graded Monday and released Friday sits in `pending` **and** in `numgraded30d` at the same time, contributing a still-growing `effectivehours` to `compliance_pct` and `median_eff_h`. The three pending bands still sum to `pending` (`rollup_service.php:172-182`), so the design's own guard test passes and nothing goes red.

Complete list, verified by `rg -n "timegraded" classes/ cli/ db/ lib.php pages/ amd/src/ templates/`:

| File | Lines | Predicate |
|---|---|---|
| `classes/local/sla/rollup_service.php` | `:137` | pending set (`IS NULL`) |
| | `:188-189` | graded-in-window set (`IS NOT NULL … >= :cutoff`) |
| | `:197` | column list |
| | `:211` | `day_counter::between(timesubmitted, timegraded)` → the four day twins |
| | `:346-347` | prior-window set → `trend_pct_30d` → `comp_trend`, **a score term** |
| `classes/local/sla/pending_recomputer.php` | `:68` | stale-pending set (`IS NULL`) |
| `classes/local/score/responsiveness_calculator.php` | `:242-243` | momentum window, **a score term** |
| `classes/local/sla/trend_service.php` | `:52`, `:114` | day bucketing for the sparkline |
| `classes/local/sla/site_stats_service.php` | `:50` | site benchmark overlay |
| `classes/local/sla/submission_browser.php` | `:126`, `:147-150`, `:181`, `:229`, `:267-268`, `:510`, `:516` | column list, upper bound, payload, `COALESCE(effectivedays, (timegraded - timesubmitted)/86400.0)`, mode filter, sort keys |
| `classes/external/get_grader_priority_list.php` | `:125`, `:198` | pending filter + elapsed-days upper bound |
| `classes/external/get_academic_days.php` | `:251-252`, `:273`, `:279`, `:284` | window + day bucketing |
| `classes/external/get_pause_timeline.php` | `:98`, `:125`, `:143` | upper bound + payload + returns |
| `classes/external/get_pending_submissions.php` | `:212` | `'timegraded' => new external_value(PARAM_INT, '0 while pending')` — **the description is now false**; a pending row can carry a non-zero `timegraded`. Add `timereleased`/`timeclosed` and re-word, in phase 1 |
| `classes/task/backfill_effectivedays.php` | `:109`, `:131` | column list + upper bound |
| `classes/privacy/provider.php` | `:100`, `:307` | metadata + export |
| `amd/src/views/PendingReportView.js` | `:797` | column render; any `timegraded === 0` ⇒ pending logic must move to `timeclosed` |
| `db/install.xml` | `:37`, `:39` | the two composite indexes (kept, see §3.3) |

`{block_feedback_tracker_trend}` and `{block_feedback_tracker_site}` are written **once, for yesterday only** (`trend_service::recompute_yesterday()` → `recompute_day()` at `:107-125`, driven by `task/recompute_trend.php`). Neither `cli/recompute_all.php` nor `block_feedback_tracker_invalidate_rollups()` rewrites historical rows. So the phase-1 release note **must** require:

```sh
php cli/backfill_trends.php --days=60     # re-bucket the sparkline on the new endpoint
php cli/recompute_all.php                 # rebuild {..._group}
# plus one forced run of block_feedback_tracker\task\recompute_site_stats
```

Otherwise the headline (recomputed) and the sparkline + site overlay (frozen on the grade clock) disagree, with the discontinuity landing mid-sparkline at the upgrade date.

### 2.7 Rows that must be excluded from the new counts

`userid = 0` (team submissions, T1) is excluded from `unallocated`, `allocpending`, `allocnumgraded30d` and `alloccoverage` by an explicit `AND userid > 0`. It is **not** retro-excluded from `pending` in phase 1 — that is a separate, pre-existing defect and changing it would move today's numbers for a reason unrelated to this work. It is flagged in `CHANGELOG.md` as known and scheduled.

---

## 3. Schema changes

### 3.1 Naming convention

Existing `{block_feedback_tracker_sub}` columns are **single lowercase words** (`waitinghours`, `effectivehours`, `effectivedays`, `slabucket`, `effectiveasof`, `effectivecalver`); underscored names are the `_group` table's style. New `_sub` columns follow the `_sub` convention: `queuehours`, `allochours`, `allocdays`, `allocbucket`, `allocmarkerid`, `timeallocmarker`. New `_group` columns keep the underscored style.

### 3.2 `{block_feedback_tracker_sub}` — new fields (`db/install.xml`)

Both blocks land in **phase 1** so the schema is stable and `latest_row_at()` can select its full column list from the first release (D-note: the first draft shipped a phase-1 helper selecting phase-2 columns — it would have fatalled on the first workflow event). Only the phase-2 *behaviour* is deferred. The file's existing style carries no `PREVIOUS`/`NEXT` attributes; match it.

```xml
<!-- Release clock. -->
<FIELD NAME="timereleased" TYPE="int" LENGTH="10" NOTNULL="false" UNSIGNED="true" COMMENT="First instant assign_user_flags.workflowstate became 'released'. NULL when marking workflow is off (see releasesource='implicit') or when the release has not been observed. Sticky: a later un-release does not clear it." SEQUENCE="false"/>
<FIELD NAME="releasesource" TYPE="char" LENGTH="12" NOTNULL="false" COMMENT="How timereleased was obtained: event|reconciled|backfill|implicit|assumed. 'implicit' means marking workflow is off. 'assumed' means the upgrade found the flags row already released but core stores no release timestamp, so timegraded was used as a lower bound." SEQUENCE="false"/>
<FIELD NAME="timeclosed" TYPE="int" LENGTH="10" NOTNULL="false" UNSIGNED="true" COMMENT="The single SLA endpoint: timereleased when markingworkflow=1, timegraded when it is 0. A row is pending iff timeclosed IS NULL. Materialised (not a SQL CASE) so the predicate stays indexable on MariaDB." SEQUENCE="false"/>
<FIELD NAME="workflowstate" TYPE="char" LENGTH="20" NOTNULL="false" COMMENT="Last observed assign_user_flags.workflowstate, normalised: NULL and '' both stored as 'notmarked'. Mirrored from the live table, never from an event payload (5.3 batch fires a per-marker state that differs from the stored aggregate). User-scoped in core, so this mirrors the whole user, not this attempt." SEQUENCE="false"/>
<FIELD NAME="markingworkflow" TYPE="int" LENGTH="1" NOTNULL="true" UNSIGNED="true" DEFAULT="0" COMMENT="Snapshot of assign.markingworkflow, refreshed on every upsert and on course_module_updated. Decides which endpoint closes the row." SEQUENCE="false"/>

<!-- Allocation clock (behaviour lands in phase 2). -->
<FIELD NAME="timeallocated" TYPE="int" LENGTH="10" NOTNULL="false" UNSIGNED="true" COMMENT="First instant ANY marker was allocated; closes the coordinator queue metric and never moves. Core stores no timestamp for this on any supported branch." SEQUENCE="false"/>
<FIELD NAME="timeallocmarker" TYPE="int" LENGTH="10" NOTNULL="false" UNSIGNED="true" COMMENT="Instant the CURRENT marker was allocated; moves on reassignment so a new marker is not charged for the previous marker's idle time. Opens the marker-turnaround interval." SEQUENCE="false"/>
<FIELD NAME="allocsource" TYPE="char" LENGTH="12" NOTNULL="false" COMMENT="How the allocation instants were obtained: event|reconciled|backfill|late|none. 'late' means allocation was detected at or after grading, so the marker interval is unmeasurable and every alloc measure is NULL." SEQUENCE="false"/>
<FIELD NAME="allocmarkerid" TYPE="int" LENGTH="10" NOTNULL="false" UNSIGNED="true" COMMENT="Lowest non-zero currently allocated marker, re-read from core (never taken from an event payload: 5.3 fires one event per marker per save). Personal data of a SECOND user — see the privacy provider." SEQUENCE="false"/>
<FIELD NAME="markingallocation" TYPE="int" LENGTH="1" NOTNULL="true" UNSIGNED="true" DEFAULT="0" COMMENT="Snapshot of assign.markingallocation. Core forces this to 0 whenever markingworkflow is 0, so 1 here implies markingworkflow=1." SEQUENCE="false"/>
<FIELD NAME="queuehours" TYPE="number" LENGTH="10" DECIMALS="2" NOTNULL="false" COMMENT="Academic hours from submission to first allocation — the coordinator's queue time. NULL when allocation is off or unobserved." SEQUENCE="false"/>
<FIELD NAME="allochours" TYPE="number" LENGTH="10" DECIMALS="2" NOTNULL="false" COMMENT="Academic hours from timeallocmarker to grading — the current marker's turnaround. NULL, never 0.0, when the interval is non-positive (rule R4)." SEQUENCE="false"/>
<FIELD NAME="allocdays" TYPE="int" LENGTH="10" NOTNULL="false" UNSIGNED="true" COMMENT="Elapsed business days from allocation to grading; day-ruler twin of allochours." SEQUENCE="false"/>
<FIELD NAME="allocbucket" TYPE="char" LENGTH="10" NOTNULL="false" COMMENT="Bucket of allochours against the alloc_bucket_thresholds_eff ruler (default 8,24,72) — deliberately a different ruler from the submission clock." SEQUENCE="false"/>
```

### 3.3 `{block_feedback_tracker_sub}` — index changes

```xml
<INDEX NAME="idx_status_closed" UNIQUE="false" FIELDS="submissionstatus, timeclosed"/>
<INDEX NAME="idx_cg_closed" UNIQUE="false" FIELDS="courseid, groupid, timeclosed"/>
<INDEX NAME="idx_status_alloc" UNIQUE="false" FIELDS="submissionstatus, timeallocmarker"/>
<INDEX NAME="idx_marker" UNIQUE="false" FIELDS="allocmarkerid"/>
```

`idx_status_graded` and `idx_course_group_graded` are **kept**: `timegraded` still drives the marker-turnaround window and the `awaiting_release` count.

**On index naming (D10).** The XMLDB `NAME` attribute never reaches the database. `sql_generator::getNameForObject()` (`lib/ddl/sql_generator.php:1080-1130`) builds the physical name from the **table** name (first 4 chars of each underscore token) plus the **field** names (first 3 chars each), truncates to 30, and disambiguates with a counter. So for `block_feedback_tracker_sub` → `blocfeedtracsub`:

* `idx_status_closed` and the existing `idx_status_graded` both abbreviate to `…blocfeedtracsub_subtim_ix`;
* `idx_status_alloc` is a third collision on the same base;
* `idx_cg_closed` collides with **both** `idx_course_group_graded` and `idx_course_group_submitted` on `…blocfeedtracsub_cougrotim_ix`.

All four still work — the generator appends a counter — but the XMLDB name is only a logical handle for `$dbman->index_exists()` inside this plugin, and it must be **unique within the table**. Nothing else follows from its length.

### 3.4 `{block_feedback_tracker_group}` — new rollup fields

```xml
<FIELD NAME="unallocated" TYPE="int" LENGTH="10" NOTNULL="false" UNSIGNED="true" COMMENT="Pending submissions (userid > 0) with no marker allocated — the coordinator backlog. Orthogonal to the critical/overgoal/within-goal partition; render as a separate badge, never a fourth tile." SEQUENCE="false"/>
<FIELD NAME="awaiting_release" TYPE="int" LENGTH="10" NOTNULL="false" UNSIGNED="true" COMMENT="Rows on markingworkflow=1 assignments with timegraded set and timereleased NULL — marked but not visible to the student. Defined independently of the pending set so a row seeded 'assumed' at upgrade does not inflate it." SEQUENCE="false"/>
<FIELD NAME="alloc_pending" TYPE="int" LENGTH="10" NOTNULL="false" UNSIGNED="true" COMMENT="Pending submissions with a marker allocated — the marker backlog." SEQUENCE="false"/>
<FIELD NAME="alloc_numgraded30d" TYPE="int" LENGTH="10" NOTNULL="false" UNSIGNED="true" COMMENT="Closed-in-window rows with a usable timeallocmarker; always <= numgraded30d, and much smaller for the first 30 days after upgrade." SEQUENCE="false"/>
<FIELD NAME="alloc_compliance_pct" TYPE="number" LENGTH="5" DECIMALS="2" NOTNULL="false" COMMENT="Share of alloc_numgraded30d whose allochours is within alloc_goal_hours." SEQUENCE="false"/>
<FIELD NAME="alloc_median_eff_h" TYPE="number" LENGTH="10" DECIMALS="2" NOTNULL="false" SEQUENCE="false"/>
<FIELD NAME="alloc_p90_eff_h" TYPE="number" LENGTH="10" DECIMALS="2" NOTNULL="false" SEQUENCE="false"/>
<FIELD NAME="alloc_cur_median_eff_h" TYPE="number" LENGTH="10" DECIMALS="2" NOTNULL="false" COMMENT="Marker-clock display headline: median over closed-in-window plus allocated-and-pending." SEQUENCE="false"/>
<FIELD NAME="queue_median_eff_h" TYPE="number" LENGTH="10" DECIMALS="2" NOTNULL="false" COMMENT="Coordinator headline: median academic hours from submission to first allocation." SEQUENCE="false"/>
<FIELD NAME="median_release_lag_h" TYPE="number" LENGTH="10" DECIMALS="2" NOTNULL="false" COMMENT="Median wall-clock hours from grading to release; NULL when marking workflow is off across the group." SEQUENCE="false"/>
<FIELD NAME="alloc_coverage_pct" TYPE="number" LENGTH="5" DECIMALS="2" NOTNULL="false" COMMENT="alloc_numgraded30d / numgraded30d as a percentage. Must be shown beside every alloc median: on 4.5/5.1 only batch allocations produce an exact timestamp, so the alloc sample is a biased subset." SEQUENCE="false"/>
```

Phase 1 adds only `awaiting_release` and `median_release_lag_h` (positioned after `compliance_pct_days`); phase 3 appends the rest, in the order listed here. §3.4 and §3.6 must agree on that order.

`{block_feedback_tracker_trend}` gains `alloc_medianh_eff` and `alloc_numgraded` (phase 3, same shape as `medianh_eff`/`numgraded`).

### 3.5 New table (phase 4 only) — `{block_feedback_tracker_alloc}`

28 chars, inside the 53-char limit. Needed because 5.3 allows 0..N markers per student (`ASSIGN_MULTIMARKING_MAX_MARKERS = 10`, 503 `locallib.php:97`), which a single `allocmarkerid` column cannot represent.

```xml
<TABLE NAME="block_feedback_tracker_alloc" COMMENT="Plugin-owned marker allocation history; one row per (subid, markerid) allocation episode. Core keeps no history at all — assign_user_flags overwrites in place and 5.3's update_allocated_markers() deletes-all-then-reinserts — so this table is the only durable record and is forward-only.">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" UNSIGNED="true" SEQUENCE="true"/>
    <FIELD NAME="subid" TYPE="int" LENGTH="10" NOTNULL="true" UNSIGNED="true" COMMENT="block_feedback_tracker_sub.id" SEQUENCE="false"/>
    <FIELD NAME="courseid" TYPE="int" LENGTH="10" NOTNULL="true" UNSIGNED="true" SEQUENCE="false"/>
    <FIELD NAME="markerid" TYPE="int" LENGTH="10" NOTNULL="true" UNSIGNED="true" SEQUENCE="false"/>
    <FIELD NAME="markerslot" TYPE="int" LENGTH="2" NOTNULL="true" UNSIGNED="true" DEFAULT="1" COMMENT="1-based position among the allocated markers at observation time. Positional only — 5.3 reassigns row ids on every save, so slot identity is not stable across re-saves." SEQUENCE="false"/>
    <FIELD NAME="timeallocated" TYPE="int" LENGTH="10" NOTNULL="true" UNSIGNED="true" SEQUENCE="false"/>
    <FIELD NAME="timedeallocated" TYPE="int" LENGTH="10" NOTNULL="false" UNSIGNED="true" COMMENT="Detected removal. Core fires no event for de-allocation in any version, so this is always reconciler-stamped." SEQUENCE="false"/>
    <FIELD NAME="allocsource" TYPE="char" LENGTH="12" NOTNULL="true" DEFAULT="event" COMMENT="event|reconciled|backfill" SEQUENCE="false"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" UNSIGNED="true" DEFAULT="0" SEQUENCE="false"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
  </KEYS>
  <INDEXES>
    <INDEX NAME="uq_sub_marker_at" UNIQUE="true" FIELDS="subid, markerid, timeallocated"/>
    <INDEX NAME="idx_marker_course" UNIQUE="false" FIELDS="markerid, courseid"/>
    <INDEX NAME="idx_subid" UNIQUE="false" FIELDS="subid"/>
  </INDEXES>
</TABLE>
```

Also bump the file's `VERSION` attribute (`<XMLDB PATH="blocks/feedback_tracker/db" VERSION="20260803" …>`) and extend the root `COMMENT`.

### 3.6 `db/upgrade.php` — phase 1 step (savepoint `2026080300`)

`$plugin->version` → `2026080300`, `$plugin->release` → `'1.0.37'`, same commit.

```php
    /*
     * V1.0.37 — release clock. On marking-workflow assignments core fires
     * submission_graded at MARKING time, not at release: gradebook_item_update()
     * only mutates the grade to -1 and falls through (405 locallib.php:6125-6131),
     * so assign_grade_item_update() still returns GRADE_UPDATE_OK and the trigger
     * at 405 locallib.php:3029-3031 runs. The ledger therefore closed rows days
     * before the student could see anything. timeclosed becomes the single SLA
     * endpoint: timereleased when marking workflow is on, timegraded when it is off.
     *
     * Sites with markingworkflow=0 everywhere see IDENTICAL numbers after this
     * upgrade. Sites using marking workflow will see a pending backlog appear,
     * because those submissions genuinely were pending. Rows whose flags row is
     * ALREADY released are closed at timegraded with releasesource='assumed' —
     * core stores no release timestamp on any branch, so timegraded is the only
     * defensible lower bound. The log-store backfill later replaces 'assumed'
     * with the true instant, which moves those medians once. This is documented
     * in CHANGELOG.md and README.md.
     */
    if ($oldversion < 2026080300) {
        $table = new xmldb_table('block_feedback_tracker_sub');

        $fields = [
            new xmldb_field('timereleased', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null, 'timegraded'),
            new xmldb_field('releasesource', XMLDB_TYPE_CHAR, '12', null, null, null, null, 'timereleased'),
            new xmldb_field('timeclosed', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null, 'releasesource'),
            new xmldb_field('workflowstate', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'timeclosed'),
            new xmldb_field('markingworkflow', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'workflowstate'),
            new xmldb_field('timeallocated', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null, 'markingworkflow'),
            new xmldb_field('timeallocmarker', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null, 'timeallocated'),
            new xmldb_field('allocsource', XMLDB_TYPE_CHAR, '12', null, null, null, null, 'timeallocmarker'),
            new xmldb_field('allocmarkerid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null, 'allocsource'),
            new xmldb_field('markingallocation', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'allocmarkerid'),
            new xmldb_field('queuehours', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'markingallocation'),
            new xmldb_field('allochours', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'queuehours'),
            new xmldb_field('allocdays', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null, 'allochours'),
            new xmldb_field('allocbucket', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'allocdays'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Snapshot both instance settings first: every statement below reads them.
        $DB->execute(
            "UPDATE {block_feedback_tracker_sub}
                SET markingworkflow = 1
              WHERE iteminstance IN (SELECT id FROM {assign} WHERE markingworkflow = 1)"
        );
        $DB->execute(
            "UPDATE {block_feedback_tracker_sub}
                SET markingallocation = 1
              WHERE iteminstance IN (SELECT id FROM {assign} WHERE markingallocation = 1)"
        );

        // Marking workflow OFF: release IS grading. Numbers unchanged, bit for bit.
        $DB->execute(
            "UPDATE {block_feedback_tracker_sub}
                SET timereleased = timegraded, releasesource = :src, timeclosed = timegraded
              WHERE markingworkflow = 0
                AND timegraded IS NOT NULL",
            ['src' => 'implicit']
        );

        // Marking workflow ON and the flags row is ALREADY released: close at
        // timegraded as a lower bound. assign_user_flags carries no timestamp on
        // any branch (405/501 db/install.xml; 503 db/install.xml:135-152).
        $DB->execute(
            "UPDATE {block_feedback_tracker_sub}
                SET timereleased = timegraded, releasesource = :src, timeclosed = timegraded
              WHERE markingworkflow = 1
                AND timegraded IS NOT NULL
                AND EXISTS (
                    SELECT 1 FROM {assign_user_flags} uf
                     WHERE uf.assignment = {block_feedback_tracker_sub}.iteminstance
                       AND uf.userid = {block_feedback_tracker_sub}.userid
                       AND uf.workflowstate = :released
                )",
            ['src' => 'assumed', 'released' => 'released']
        );

        // Everything else on a workflow assignment stays OPEN. This is the fix.
        // timeclosed remains NULL; those rows are genuinely pending.

        // Mirror the live workflow state so the reconciler does not rewrite
        // every row on its first pass.
        $DB->execute(
            "UPDATE {block_feedback_tracker_sub}
                SET workflowstate = :notmarked
              WHERE markingworkflow = 1 AND workflowstate IS NULL"
        , ['notmarked' => 'notmarked']);

        $indexes = [
            new xmldb_index('idx_status_closed', XMLDB_INDEX_NOTUNIQUE, ['submissionstatus', 'timeclosed']),
            new xmldb_index('idx_cg_closed', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'groupid', 'timeclosed']),
            new xmldb_index('idx_status_alloc', XMLDB_INDEX_NOTUNIQUE, ['submissionstatus', 'timeallocmarker']),
            new xmldb_index('idx_marker', XMLDB_INDEX_NOTUNIQUE, ['allocmarkerid']),
        ];
        foreach ($indexes as $index) {
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        $table = new xmldb_table('block_feedback_tracker_group');
        $groupfields = [
            new xmldb_field('awaiting_release', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null, 'compliance_pct_days'),
            new xmldb_field('median_release_lag_h', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'awaiting_release'),
        ];
        foreach ($groupfields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        if (get_config('block_feedback_tracker', 'track_marking_workflow') === false) {
            set_config('track_marking_workflow', '1', 'block_feedback_tracker');
        }
        if (get_config('block_feedback_tracker', 'workflow_reconcile_window_days') === false) {
            set_config('workflow_reconcile_window_days', '30', 'block_feedback_tracker');
        }

        // Arm the workflow backfill; backfill_workflow fills timereleased from
        // the log store off the upgrade critical path and replaces 'assumed'.
        set_config('workflow_backfill_done', '0', 'block_feedback_tracker');
        set_config('workflow_backfill_lastid', '0', 'block_feedback_tracker');

        $tuples = $DB->get_recordset_sql(
            'SELECT DISTINCT courseid, groupid FROM {block_feedback_tracker_sub}'
        );
        foreach ($tuples as $t) {
            \block_feedback_tracker\local\sla\dirty_queue::enqueue(
                (int) $t->courseid,
                (int) $t->groupid,
                \block_feedback_tracker\local\sla\dirty_queue::REASON_SUBMISSION
            );
        }
        $tuples->close();

        // The payload MUC caches are keyed on the calendar version
        // (responsiveness_payload.php:84, get_dashboard.php:99-101); without
        // this bump every session serves pre-upgrade payloads. It also marks
        // every pending row stale so recompute_pending re-buckets on the new
        // endpoint (pending_recomputer.php:68 effectivecalver check).
        \block_feedback_tracker\local\calendar\calendar::bump_version();

        upgrade_block_savepoint(true, 2026080300, 'feedback_tracker');
    }
```

Cross-DB notes: `UPDATE … WHERE x IN (SELECT …)` and `EXISTS (…)` against a *different* table are portable on PostgreSQL and MariaDB (MariaDB's restriction is only on updating and selecting from the same table). No `NULLS FIRST`, no interpolated values, all placeholders.

Phase 2 (`2026080400`) is behaviour-only — no schema. Phase 3 (`2026080500`) adds the remaining `_group` and `_trend` columns.

---

## 4. Observer + event registration

### 4.1 `db/events.php` additions

```php
    // Marking workflow state transitions. Fires from quick grading
    // (405:7344), the batch workflow tool (405:8501), the grading form and
    // the save_grade/save_grades web services (405:8687), and on 5.3 also
    // from the per-marker aggregation (503:9367). It does NOT fire from
    // mod_assign_set_user_flags — the reconcile_workflow task covers that.
    [
        'eventname' => '\mod_assign\event\workflow_state_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::workflow_state_updated',
    ],

    // Marker allocation. On 4.5/5.1 this fires ONLY from the batch
    // allocation tool (405:8559 / 501:8719); quick grading and the grading
    // form write assign_user_flags.allocatedmarker with no event at all.
    // On 5.3 it comes from update_allocated_markers() (503:7900), once per
    // marker, reached unconditionally only from the grading form (503:9425).
    [
        'eventname' => '\mod_assign\event\marker_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::marker_updated',
    ],

    // Instance settings changed. markingworkflow decides which endpoint
    // closes a row, so a stale snapshot is a wrong-partition bug with no
    // self-healing path.
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::course_module_updated',
    ],
```

### 4.2 New observer handlers — `classes/local/sla/observer.php`

**The config read is the plugin's own correct default-ON pattern** (`classes/output/responsiveness_card.php:76-82`), not `?? 1`. `get_config()` returns `false` — never `null` — when a setting is unset, so `(int) (get_config(…) ?? 1) !== 1` evaluates to `0 !== 1`, i.e. **true**, and both observers would return early on every fresh install. (The same defect already exists at `submission_ledger.php:339` for `exclude_grader_submissions`; it is out of scope here but tracked.)

```php
    /**
     * Is a default-ON plugin flag switched on?
     *
     * get_config() returns false (not null) for an unset setting, so only an
     * explicitly stored '0' means off. See the fleet rule on default-ON
     * checkboxes and the existing read at responsiveness_card.php:76-82.
     *
     * @param string $name Setting name without the plugin prefix.
     * @return bool
     */
    private static function flag_on(string $name): bool {
        $value = get_config('block_feedback_tracker', $name);
        if ($value === false || $value === null) {
            return true;
        }
        return (string) $value !== '0';
    }

    /**
     * Marking workflow state changed for one student on one assign.
     *
     * The state lives in {assign_user_flags}, keyed on (assignment, userid)
     * with no attemptnumber, so the change is applied to the latest SUBMITTED
     * ledger attempt whose timesubmitted precedes the event.
     *
     * The event payload is deliberately not trusted for the stored state: on
     * 5.3 a batch save with workflowcontext='mark' writes the AGGREGATE state
     * (503 locallib.php:9360) but then fires an event carrying the per-marker
     * state (503 locallib.php:9112). Rule R6.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function workflow_state_updated(\core\event\base $event): void {
        if (!self::flag_on('track_marking_workflow')) {
            return;
        }
        $cmid = (int) ($event->contextinstanceid ?? 0);
        $userid = (int) ($event->relateduserid ?? 0);
        if ($cmid <= 0 || $userid <= 0) {
            return;
        }

        global $DB;
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], IGNORE_MISSING);
        if (!course_access::is_processable($courseid)) {
            return;
        }

        submission_ledger::apply_workflow_state(
            $cmid,
            $userid,
            (int) $event->timecreated,
            workflow_state::SOURCE_EVENT
        );
    }

    /**
     * A marker was allocated to a student.
     *
     * On 5.3 the grading-form path fires one event per surviving marker on
     * every save, because update_allocated_markers() deletes every row for
     * the student and re-inserts (503 locallib.php:7872-7902). The handler is
     * therefore idempotent and re-reads the whole allocation set from core
     * rather than trusting other['markerid'], so the stored marker id is
     * deterministic and matches what the reconciler computes.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function marker_updated(\core\event\base $event): void {
        if (!self::flag_on('track_marking_allocation')) {
            return;
        }
        $cmid = (int) ($event->contextinstanceid ?? 0);
        $userid = (int) ($event->relateduserid ?? 0);
        if ($cmid <= 0 || $userid <= 0) {
            return;
        }

        global $DB;
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], IGNORE_MISSING);
        if (!course_access::is_processable($courseid)) {
            return;
        }

        submission_ledger::apply_allocation(
            $cmid,
            $userid,
            (int) $event->timecreated,
            workflow_state::SOURCE_EVENT
        );
    }

    /**
     * Assignment settings changed. Refresh the markingworkflow /
     * markingallocation snapshots and re-derive timeclosed for every ledger
     * row on this course module: turning marking workflow ON must re-open
     * rows that were closed implicitly, and turning it OFF must close rows
     * that were waiting for a release that will now never be observed.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function course_module_updated(\core\event\base $event): void {
        $cmid = (int) ($event->objectid ?? 0);
        $other = (array) ($event->other ?? []);
        if ($cmid <= 0 || ($other['modulename'] ?? '') !== 'assign') {
            return;
        }
        if (!course_access::is_processable((int) $event->courseid)) {
            return;
        }
        submission_ledger::resync_instance_flags($cmid);
    }
```

**Disputed (D-note).** One reviewer called `if (is_object($other)) { $other = (array) $other; }` dead code because `\core\event\base::$other` is always an array. That is true for events created through `create()`, but `$event->other` on an event *rehydrated from the log store* (`\core\event\base::restore()`) can arrive as a `stdClass` when the payload was PHP-`serialize()`d rather than JSON-encoded (§6.3). The cast stays in the **backfill** path, where events are restored; it is dropped from the live observers, where the event object is always freshly built.

### 4.3 New helper — `classes/local/sla/workflow_state.php`

```php
/**
 * Marking-workflow state vocabulary and normalisation.
 *
 * Core seeds {assign_user_flags}.workflowstate as the empty string when the
 * row is created (405 locallib.php:3936) and get_grading_status() maps empty
 * to 'notmarked' (405 locallib.php:9432-9449). Every comparison in this
 * plugin therefore goes through normalise(). The six states are identical on
 * 4.5, 5.1 and 5.3 (405 locallib.php:70-75; 503 locallib.php:64-69);
 * 5.3's ASSIGN_MULTIMARKING_* family (503 locallib.php:87-98) is orthogonal.
 */
class workflow_state {
    /** Never touched by a grader. */
    public const NOTMARKED = 'notmarked';
    /** Grading started. */
    public const INMARKING = 'inmarking';
    /** Marker finished. */
    public const READYFORREVIEW = 'readyforreview';
    /** Under review. */
    public const INREVIEW = 'inreview';
    /** Reviewed, awaiting release. */
    public const READYFORRELEASE = 'readyforrelease';
    /** Visible to the student. */
    public const RELEASED = 'released';

    /** Timestamp came from a core event. */
    public const SOURCE_EVENT = 'event';
    /** Timestamp stamped by the reconciler at detection time. */
    public const SOURCE_RECONCILED = 'reconciled';
    /** Timestamp recovered from the standard log store. */
    public const SOURCE_BACKFILL = 'backfill';
    /** Marking workflow is off; release is simultaneous with grading. */
    public const SOURCE_IMPLICIT = 'implicit';
    /** Upgrade found the flags row already released; timegraded used as a lower bound. */
    public const SOURCE_ASSUMED = 'assumed';
    /** Allocation detected at or after grading; interval unmeasurable. */
    public const SOURCE_LATE = 'late';

    /**
     * Map a raw core value to the plugin vocabulary.
     *
     * @param string|null $raw Raw workflowstate as stored by mod_assign.
     * @return string One of the six state constants.
     */
    public static function normalise(?string $raw): string {
        $value = trim((string) $raw);
        if ($value === '') {
            return self::NOTMARKED;
        }
        return match ($value) {
            self::INMARKING, self::READYFORREVIEW, self::INREVIEW,
            self::READYFORRELEASE, self::RELEASED => $value,
            default => self::NOTMARKED,
        };
    }
}
```

### 4.4 Ledger mutators — `classes/local/sla/submission_ledger.php`

```php
    /**
     * Resolve the ledger row a user-scoped flags change applies to.
     *
     * {assign_user_flags} has no attemptnumber column in 4.5, 5.1 or 5.3, and
     * add_attempt() never resets workflowstate (405 locallib.php:9076-9134),
     * so a state change is attributed to the newest SUBMITTED attempt that had
     * already been submitted when the change happened. The submissionstatus
     * filter is mandatory: timesubmitted is NOTNULL DEFAULT 0, so a 'new' or
     * 'reopened' row always satisfies the timestamp predicate and would
     * outrank the real attempt on attemptnumber DESC.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $when Unix timestamp of the change.
     * @return \stdClass|null
     */
    private static function latest_row_at(int $cmid, int $userid, int $when): ?\stdClass {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT " . self::LEDGER_STATE_FIELDS . "
               FROM {block_feedback_tracker_sub}
              WHERE cmid = :cmid
                AND userid = :userid
                AND userid > 0
                AND submissionstatus = :substatus
                AND timesubmitted > 0
                AND timesubmitted <= :when
              ORDER BY attemptnumber DESC, id DESC",
            [
                'cmid' => $cmid,
                'userid' => $userid,
                'substatus' => submission_status::SUBMITTED,
                'when' => $when,
            ],
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * Record the live workflow state, stamping timereleased on the first
     * observed release.
     *
     * $when is used only as the resolution instant and as the release
     * timestamp; the STATE itself is always re-read from
     * {assign_user_flags} (rule R6), and a release is confirmed there before
     * timeclosed is written.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $when
     * @param string $source One of workflow_state::SOURCE_*.
     * @param int|null $subid Resolved row id, when the caller already has it.
     * @return void
     */
    public static function apply_workflow_state(
        int $cmid,
        int $userid,
        int $when,
        string $source,
        ?int $subid = null
    ): void {
        global $DB;

        $row = $subid !== null
            ? $DB->get_record('block_feedback_tracker_sub', ['id' => $subid], self::LEDGER_STATE_FIELDS)
            : self::latest_row_at($cmid, $userid, $when);
        if (!$row) {
            // The block may have been installed after this submission was
            // graded. Seed the row, then retry once.
            if ($subid === null && self::seed_missing_row($cmid, $userid, $when)) {
                $row = self::latest_row_at($cmid, $userid, $when);
            }
            if (!$row) {
                return;
            }
        }

        $corestate = workflow_state::normalise(
            $DB->get_field(
                'assign_user_flags',
                'workflowstate',
                ['assignment' => (int) $row->iteminstance, 'userid' => $userid],
                IGNORE_MISSING
            ) ?: null
        );

        $update = (object) [
            'id' => (int) $row->id,
            'workflowstate' => $corestate,
            'timemodified' => time(),
        ];

        // First release wins (R1). A later un-release (released -> inmarking
        // wipes the gradebook grade, 405 locallib.php:6125-6131) leaves
        // timereleased in place: the student DID see the feedback, and
        // workflowstate above already records that it was withdrawn.
        // The instant is floored at timegraded because the grading-form path
        // fires workflow_state_updated (405:8687) BEFORE update_grade()
        // (405:8724), so a naive assignment could close the row before the
        // feedback existed.
        if ($corestate === workflow_state::RELEASED && $row->timereleased === null) {
            $instant = max($when, (int) ($row->timegraded ?? 0));
            $update->timereleased = $instant;
            $update->releasesource = $source;
            $update->timeclosed = $instant;
        }

        $DB->update_record('block_feedback_tracker_sub', $update);

        if (isset($update->timeclosed)) {
            self::recompute_measures((int) $row->id);
            dirty_queue::enqueue((int) $row->courseid, (int) $row->groupid, dirty_queue::REASON_GRADE);
        } else if ($corestate !== workflow_state::normalise($row->workflowstate)) {
            // Only a genuine state change dirties the rollup — otherwise the
            // reconciler would re-enqueue every polled row every 15 minutes.
            dirty_queue::enqueue((int) $row->courseid, (int) $row->groupid, dirty_queue::REASON_GRADE);
        }
    }

    /**
     * Record the live marker allocation.
     *
     * The marker set is re-read from core rather than taken from the event,
     * because 5.3 fires one marker_updated per marker per save in insertion
     * order (503 locallib.php:7885-7901): "last event wins" and "MIN(marker)"
     * would disagree on every multi-marker student and the reconciler would
     * rewrite the row on every cron tick, forever.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $when
     * @param string $source One of workflow_state::SOURCE_*.
     * @param int|null $subid Resolved row id, when the caller already has it.
     * @return void
     */
    public static function apply_allocation(
        int $cmid,
        int $userid,
        int $when,
        string $source,
        ?int $subid = null
    ): void {
        global $DB;

        $row = $subid !== null
            ? $DB->get_record('block_feedback_tracker_sub', ['id' => $subid], self::LEDGER_STATE_FIELDS)
            : self::latest_row_at($cmid, $userid, $when);
        if (!$row || (int) $row->markingallocation !== 1) {
            return;
        }

        $markerid = marker_source::lowest_marker((int) $row->iteminstance, $userid);
        $known = (int) ($row->allocmarkerid ?? 0);
        if ($markerid === $known) {
            return; // Idempotent: nothing observable changed.
        }

        $update = (object) [
            'id' => (int) $row->id,
            'allocmarkerid' => $markerid > 0 ? $markerid : null,
            'timemodified' => time(),
        ];

        if ($markerid > 0) {
            // R1: the queue clock stops at the FIRST allocation and never moves.
            if ($row->timeallocated === null) {
                $update->timeallocated = $when;
            }
            // The marker clock restarts whenever the marker changes, so a new
            // marker is not charged for the previous marker's idle time.
            $update->timeallocmarker = $when;

            // R4: a non-positive marker interval must be NULL, never 0.0.
            // academic_time::elapsed_with_audit() returns 0.0 hours for
            // tsto <= tsfrom (classes/local/calendar/academic_time.php:98-100)
            // and bucket::for_effective(0.0) is 'excellent' (bucket.php:51-58),
            // so an unmeasurable interval would read as a flawless turnaround.
            $graded = $row->timegraded !== null ? (int) $row->timegraded : null;
            $update->allocsource = ($graded !== null && $when >= $graded)
                ? workflow_state::SOURCE_LATE
                : $source;
        }
        // De-allocation (C6): allocmarkerid clears, but timeallocated and
        // timeallocmarker are kept — the work still has to be done by someone.

        $DB->update_record('block_feedback_tracker_sub', $update);
        self::recompute_measures((int) $row->id);
        dirty_queue::enqueue((int) $row->courseid, (int) $row->groupid, dirty_queue::REASON_SUBMISSION);
    }
```

**`recompute_measures(int $subid)` is an extraction, not a new function.** Today those measures are computed inline at `submission_ledger.php:126-165`. That block moves into `recompute_measures()`, and `upsert_for_cm_user_attempt()`, `apply_workflow_state()`, `apply_allocation()` and `pending_recomputer` all call it — otherwise R2 and R4 drift between entry points. It computes, in one place:

* student wait `timesubmitted → (timeclosed ?? now)` → `waitinghours`, `effectivehours`, `effectivedays`, `slabucket`;
* `timesubmitted → timeallocated` → `queuehours`;
* `timeallocmarker → (timegraded ?? timeclosed ?? now)` → `allochours`, `allocdays`, `allocbucket` — **capped at `timeclosed`**, so a released-but-never-graded row (B10) does not grow an unbounded marker interval;
* R4 on all three: `NULL`, never `0.0`, for a non-positive interval.

`upsert_for_cm_user_attempt()` gains, after the `$grade` fetch (and its `$existing` lookup moves above this block, widening its column list from `'id'` to `self::LEDGER_STATE_FIELDS`):

```php
        $markingworkflow = (int) ($assign->markingworkflow ?? 0);
        $markingallocation = (int) ($assign->markingallocation ?? 0);
        $status = isset($submission->status) ? (string) $submission->status : 'new';

        // Rule R2: timegraded is sticky, but only while this is still the same
        // submitted attempt. Core re-runs update_grade() on every batch
        // workflow transition (405 locallib.php:8499) and on revert_to_draft()
        // (405:8349), both of which bump assign_grades.timemodified with no
        // grading having happened. An UNCONDITIONAL sticky rule survives a
        // revert-and-resubmit and yields timegraded < timesubmitted, i.e.
        // 0.0 effective hours, i.e. bucket 'excellent'.
        if (
            $existing !== null && $existing->timegraded !== null
            && (int) $existing->timegraded >= $timesubmitted
            && $status === submission_status::SUBMITTED
        ) {
            $timegraded = max((int) $timegraded, (int) $existing->timegraded);
        }

        // Rule R3 + the no-workflow shortcut: without marking workflow the
        // grade is visible the instant it is saved.
        if ($markingworkflow === 0) {
            $timereleased = $timegraded;
            $releasesource = $timegraded !== null ? workflow_state::SOURCE_IMPLICIT : null;
            $timeclosed = $timegraded;
        } else {
            $timereleased = $existing->timereleased ?? null;
            $releasesource = $existing->releasesource ?? null;
            // NOT a coalesce onto timegraded: that is the FALSE-GRADED bug.
            $timeclosed = $timereleased;
        }
```

`resync_instance_flags(int $cmid)` re-reads `{assign}.markingworkflow` / `.markingallocation`, rewrites both snapshots for every ledger row on that cm, and re-derives `timereleased`/`timeclosed` under the new setting — closing rows implicitly when workflow is switched off, re-opening them when it is switched on. Without it the snapshot is a wrong-partition bug with no self-healing path, since `upsert_for_cm_user_attempt()` only runs on submission/grade events.

### 4.5 The reconciler — `classes/task/reconcile_workflow.php`

Covers every `MISSED-no-event` row in §1: the grading-form and quick-grading allocation paths on 4.5/5.1 (C3, C4), `mod_assign_set_user_flags` on all versions (B7, C7), and de-allocation (C6).

`db/tasks.php` registration (with the matching `task_reconcile_workflow` lang string — `validate` fails on a registered task with no string):

```php
    [
        'classname' => 'block_feedback_tracker\task\reconcile_workflow',
        'blocking'  => 0,
        'minute'    => '*/15',
        'hour'      => '*',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
```

**Detection SQL, 4.5 / 5.1** (`allocatedmarker` still on `assign_user_flags`). Note the allocation clause is gated on `a.markingallocation = 1`: without that gate, `s.timeallocmarker IS NULL` matches every workflow row on a non-allocation assignment forever and the keyset re-walks them every 15 minutes in perpetuity.

```sql
SELECT s.id AS subid,
       s.courseid,
       s.cmid,
       s.userid,
       s.iteminstance,
       s.timesubmitted,
       s.timegraded,
       s.timereleased,
       s.timeallocmarker,
       s.workflowstate AS knownstate,
       s.allocmarkerid AS knownmarker,
       a.markingworkflow,
       a.markingallocation,
       uf.workflowstate AS corestate,
       uf.allocatedmarker AS coremarker
  FROM {block_feedback_tracker_sub} s
  JOIN {assign} a ON a.id = s.iteminstance
  LEFT JOIN {assign_user_flags} uf
         ON uf.assignment = s.iteminstance
        AND uf.userid = s.userid
 WHERE s.submissionstatus = :substatus
   AND s.userid > 0
   AND s.id > :lastid
   AND s.timesubmitted >= :since
   AND (
        (a.markingworkflow = 1 AND (s.timeclosed IS NULL OR s.timereleased IS NULL))
        OR (a.markingallocation = 1 AND s.timeallocmarker IS NULL)
       )
 ORDER BY s.id ASC
```

**Detection SQL, 5.3** — `allocatedmarker` is gone (503 `db/upgrade.php:205`), allocations live in `assign_allocated_marker`, and the migration inserted a `marker = 0` row for every pre-existing flags row with **no `WHERE` clause** (503 `db/upgrade.php:200-204`), so the `am.marker > 0` guard is mandatory:

```sql
SELECT s.id AS subid,
       s.courseid,
       s.cmid,
       s.userid,
       s.iteminstance,
       s.timesubmitted,
       s.timegraded,
       s.timereleased,
       s.timeallocmarker,
       s.workflowstate AS knownstate,
       s.allocmarkerid AS knownmarker,
       a.markingworkflow,
       a.markingallocation,
       uf.workflowstate AS corestate,
       MIN(am.marker) AS coremarker
  FROM {block_feedback_tracker_sub} s
  JOIN {assign} a ON a.id = s.iteminstance
  LEFT JOIN {assign_user_flags} uf
         ON uf.assignment = s.iteminstance
        AND uf.userid = s.userid
  LEFT JOIN {assign_allocated_marker} am
         ON am.assignment = s.iteminstance
        AND am.student = s.userid
        AND am.marker > 0
 WHERE s.submissionstatus = :substatus
   AND s.userid > 0
   AND s.id > :lastid
   AND s.timesubmitted >= :since
   AND (
        (a.markingworkflow = 1 AND (s.timeclosed IS NULL OR s.timereleased IS NULL))
        OR (a.markingallocation = 1 AND s.timeallocmarker IS NULL)
       )
 GROUP BY s.id, s.courseid, s.cmid, s.userid, s.iteminstance, s.timesubmitted,
          s.timegraded, s.timereleased, s.timeallocmarker, s.workflowstate,
          s.allocmarkerid, a.markingworkflow, a.markingallocation, uf.workflowstate
 ORDER BY s.id ASC
```

Every non-aggregated column is in `GROUP BY` — PostgreSQL requires it and MariaDB in `ONLY_FULL_GROUP_BY` does too.

Task body:

```php
    /** Rows examined per run. */
    private const BATCH = 500;

    /**
     * Poll {assign_user_flags} (and, on 5.3, {assign_allocated_marker}) for
     * state the plugin could not have learned from an event.
     *
     * Timestamp resolution is the cron period: rows detected here carry
     * allocsource/releasesource 'reconciled' and are excluded from the
     * headline marker medians, which report over the 'event' subset and
     * publish alloc_coverage_pct beside themselves.
     *
     * The resolved subid is passed straight through. Re-deriving the row from
     * (cmid, userid, now) would resolve to the newest submitted attempt, which
     * after an add_attempt() is a brand-new submission nobody has looked at —
     * assign_user_flags has no attemptnumber column and add_attempt() does not
     * reset workflowstate (405 locallib.php:9076-9134), so a stale 'released'
     * flag would close a fresh attempt at ~0 hours, bucket 'excellent'.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $now = time();
        $windowdays = (int) (get_config('block_feedback_tracker', 'workflow_reconcile_window_days') ?: 30);
        $lastid = (int) (get_config('block_feedback_tracker', 'reconcile_lastid') ?: 0);
        $params = [
            'substatus' => submission_status::SUBMITTED,
            'lastid' => $lastid,
            'since' => $now - ($windowdays * DAYSECS),
        ];

        $rows = $DB->get_records_sql(marker_source::reconcile_sql(), $params, 0, self::BATCH);

        foreach ($rows as $r) {
            $lastid = (int) $r->subid;

            if (!course_access::is_processable((int) $r->courseid)) {
                continue;
            }

            if ((int) $r->markingworkflow === 1) {
                $corestate = workflow_state::normalise($r->corestate ?? null);
                if ($corestate !== workflow_state::normalise($r->knownstate ?? null)) {
                    submission_ledger::apply_workflow_state(
                        (int) $r->cmid,
                        (int) $r->userid,
                        $now,
                        workflow_state::SOURCE_RECONCILED,
                        (int) $r->subid
                    );
                }
            }

            if ((int) $r->markingallocation === 1
                    && (int) ($r->coremarker ?? 0) !== (int) ($r->knownmarker ?? 0)) {
                submission_ledger::apply_allocation(
                    (int) $r->cmid,
                    (int) $r->userid,
                    $now,
                    workflow_state::SOURCE_RECONCILED,
                    (int) $r->subid
                );
            }
        }

        // Wrap the keyset when the batch came back short, so the window slides.
        set_config(
            'reconcile_lastid',
            count($rows) < self::BATCH ? '0' : (string) $lastid,
            'block_feedback_tracker'
        );
    }
```

`use` statements required: `course_access`, `marker_source`, `submission_ledger`, `submission_status`, `workflow_state`. `marker_source::reconcile_sql()` and `marker_source::lowest_marker()` are defined in §7 alongside `mode()`.

---

## 5. Settings

### 5.1 Why `sla_clock` is not a phase-1 setting

`waitinghours` / `effectivehours` / `effectivedays` / `slabucket` are **materialised per row** (`submission_ledger.php:126-165`) and refreshed only for pending rows (`pending_recomputer.php:60-105`, `WHERE timegraded IS NULL … effectiveasof < now-3600`). Closed rows are never recomputed. So a "switch" flipped after the fact would produce a mixture of rows computed under two endpoints, and flipping back would restore nothing — `invalidate_rollups` only re-aggregates already-wrong per-row values.

Phase 1 therefore changes the endpoint **unconditionally**, documents it in `CHANGELOG.md` and `README.md`, and ships no clock setting. `sla_clock` lands in phase 3 as a **write-time** endpoint selector whose `set_updatedcallback` arms a new `recompute_endpoint` scheduled task that walks the whole ledger in time-capped batches (the same shape as `backfill_effectivedays`), then re-enqueues every `(courseid, groupid)` and forces a `cli/backfill_trends.php` + site-stats rebuild. The cost is stated on the setting's own description: it is O(all ledger rows) × `academic_time::elapsed_with_audit`, the exact call the architecture exists to keep off the inline path.

### 5.2 `settings.php`

New heading placed **after the Views block** (`settings.php:236`), not after Scoring — a heading inserted after the scoring weights would split the `scoring_simulator` heading (`settings.php:172-173`) from its own block.

```php
    // Heading: Marking workflow.
    $settings->add(new admin_setting_heading(
        $plugin . '/markingworkflow',
        get_string('settings_markingworkflow_heading', $plugin),
        get_string('settings_markingworkflow_desc', $plugin)
    ));

    $workflowbools = [
        'track_marking_workflow'   => 1,
        'track_marking_allocation' => 1,
    ];
    foreach ($workflowbools as $key => $default) {
        $settings->add(new admin_setting_configcheckbox(
            $plugin . '/' . $key,
            get_string('settings_' . $key, $plugin),
            get_string('settings_' . $key . '_desc', $plugin),
            $default
        ));
    }

    $s = new admin_setting_configtext(
        $plugin . '/workflow_reconcile_window_days',
        get_string('settings_workflow_reconcile_window_days', $plugin),
        get_string('settings_workflow_reconcile_window_days_desc', $plugin),
        '30',
        PARAM_INT
    );
    $settings->add($s);

    $s = new admin_setting_configtext(
        $plugin . '/release_lag_alert_hours',
        get_string('settings_release_lag_alert_hours', $plugin),
        get_string('settings_release_lag_alert_hours_desc', $plugin),
        '24',
        PARAM_INT
    );
    $settings->add($s);
    $settings->hide_if($plugin . '/release_lag_alert_hours', $plugin . '/track_marking_workflow', 'eq', 0);

    // Phase 2 adds alloc_goal_hours + alloc_bucket_thresholds_eff here,
    // each hide_if'd on track_marking_allocation and each with the
    // invalidate_rollups callback. Phase 3 adds sla_clock, whose callback
    // arms the full-ledger recompute_endpoint task.
```

| Key | Phase | Type | Default | Callback | Effect on today's numbers |
|---|---|---|---|---|---|
| `track_marking_workflow` | 1 | checkbox | `1` | none | Observers no-op on `markingworkflow = 0` assignments regardless |
| `workflow_reconcile_window_days` | 1 | text `PARAM_INT` | `30` | none | Bounds the reconciler's poll; larger values cost cron time, not correctness |
| `release_lag_alert_hours` | 1 | text `PARAM_INT` | `24` | none | Display-only badge threshold |
| `track_marking_allocation` | 2 | checkbox | `1` | none | Allocation columns stay NULL on non-allocation assignments |
| `alloc_goal_hours` | 2 | text `PARAM_INT` | `8` | `invalidate_rollups` | Only feeds new `alloc_*` columns |
| `alloc_bucket_thresholds_eff` | 2 | text `PARAM_TEXT` | `8,24,72` | `invalidate_rollups` | Deliberately a different ruler from `bucket_thresholds_eff` (24,48,120): reusing the submission ruler would band nearly every marker turnaround `excellent` |
| `sla_clock` | 3 | select | `submission_release` | `invalidate_rollups` **+ full-ledger recompute** | On `markingworkflow = 0` every clock is numerically identical |

**Every one of these must also be seeded in `db/install.php`'s `$defaults` array** (`db/install.php:43-95`) — otherwise a fresh install has none of them stored, and the fleet's "only an explicit '0' means off" rule is the only thing keeping the observers alive.

`block_feedback_tracker_invalidate_rollups()` in `lib.php` already short-circuits during bootstrap; no change needed there.

### 5.3 New lang keys (both files, **strict alphabetical**, no section comments)

`moodle.Files.LangFilesOrdering` fails on any out-of-order key. The existing file proves strict ordering at `lang/en/block_feedback_tracker.php:457-460`. The trap is the `_desc` suffix: it does **not** sort adjacent to its base key when siblings exist.

Correct order for the `sla_clock` family:

```
settings_sla_clock
settings_sla_clock_alloc
settings_sla_clock_desc
settings_sla_clock_grade
settings_sla_clock_release
```

Full key list (phase 1 keys marked ¹, phase 2 ², phase 3 ³, phase 4 ⁴):

```
alloc_band_critical³, alloc_band_excellent³, alloc_band_good³, alloc_band_regular³
card_alloc_median³, card_awaiting_release¹, card_queue_median³, card_release_lag¹, card_unallocated³
col_alloc_effective³, col_allocated³, col_marker⁴, col_released¹
dashboard_kpi_alloc_pending³, dashboard_kpi_unallocated³
privacy:metadata:alloc⁴, privacy:metadata:alloc:markerid⁴, privacy:metadata:alloc:subid⁴,
privacy:metadata:alloc:timeallocated⁴, privacy:metadata:alloc:timedeallocated⁴
privacy:metadata:sub:allocbucket¹, privacy:metadata:sub:allocdays¹,
privacy:metadata:sub:allochours¹, privacy:metadata:sub:allocmarkerid¹,
privacy:metadata:sub:allocsource¹, privacy:metadata:sub:markingallocation¹,
privacy:metadata:sub:markingworkflow¹, privacy:metadata:sub:queuehours¹,
privacy:metadata:sub:releasesource¹, privacy:metadata:sub:timeallocated¹,
privacy:metadata:sub:timeallocmarker¹, privacy:metadata:sub:timeclosed¹,
privacy:metadata:sub:timereleased¹, privacy:metadata:sub:workflowstate¹
report_kpi_alloc_compliance³, report_kpi_alloc_median³
settings_alloc_bucket_thresholds_eff²(+_desc), settings_alloc_goal_hours²(+_desc),
settings_markingworkflow_desc¹, settings_markingworkflow_heading¹,
settings_release_lag_alert_hours¹(+_desc),
settings_sla_clock³, settings_sla_clock_alloc³, settings_sla_clock_desc³,
settings_sla_clock_grade³, settings_sla_clock_release³,
settings_track_marking_allocation²(+_desc), settings_track_marking_workflow¹(+_desc),
settings_workflow_reconcile_window_days¹(+_desc)
task_backfill_workflow¹, task_recompute_endpoint³, task_reconcile_workflow²
workflow_state_inmarking¹, workflow_state_inreview¹, workflow_state_notmarked¹,
workflow_state_readyforrelease¹, workflow_state_readyforreview¹, workflow_state_released¹
```

Note there is **no** `task_backfill_allocation` — the first draft listed one with no class behind it. `validate` fails in both directions: a registered task without a `task_<classname>` string, and an orphan string with no task.

Representative pairs, English:

```php
$string['settings_sla_clock'] = 'SLA clock';
$string['settings_sla_clock_desc'] = 'Which interval the responsiveness score and every SLA metric measure. "Submission to release" (default) closes a submission when the student can actually see the feedback — on assignments without marking workflow that is the same instant the grade is saved, so this default reproduces the previous figures exactly there. "Submission to grading" reproduces the pre-1.0.37 behaviour on marking-workflow assignments too, where the grade was counted as delivered days before release. "Allocation to grading" measures only the allocated marker\'s turnaround and excludes submissions that were never allocated. Changing this setting recomputes every ledger row in the background and can take a long time on a large site.';
$string['settings_sla_clock_release'] = 'Submission to release (what the student experiences)';
$string['settings_sla_clock_grade'] = 'Submission to grading (legacy)';
$string['settings_sla_clock_alloc'] = 'Allocation to grading (marker turnaround)';
$string['task_reconcile_workflow'] = 'Feedback Flow: reconcile marking workflow and allocation';
$string['task_backfill_workflow'] = 'Feedback Flow: recover release instants from the log store';
$string['privacy:metadata:sub:allocmarkerid'] = 'Teacher allocated to mark this submission.';
```

Brazilian Portuguese, same keys, same slots:

```php
$string['settings_sla_clock'] = 'Relógio do SLA';
$string['settings_sla_clock_desc'] = 'Qual intervalo a pontuação de responsividade e todas as métricas de SLA medem. "Do envio à liberação" (padrão) encerra um envio quando o estudante realmente consegue ver o feedback — em tarefas sem fluxo de avaliação esse é o mesmo instante em que a nota é salva, então esse padrão reproduz exatamente os números anteriores nesses casos. "Do envio à avaliação" reproduz o comportamento anterior à 1.0.37 também nas tarefas com fluxo de avaliação, em que a nota era contada como entregue dias antes da liberação. "Da alocação à avaliação" mede apenas o tempo de resposta do avaliador alocado e exclui os envios que nunca foram alocados. Alterar essa configuração recalcula todas as linhas do registro em segundo plano e pode demorar bastante em sites grandes.';
$string['settings_sla_clock_release'] = 'Do envio à liberação (o que o estudante vive)';
$string['settings_sla_clock_grade'] = 'Do envio à avaliação (legado)';
$string['settings_sla_clock_alloc'] = 'Da alocação à avaliação (tempo do avaliador)';
$string['task_reconcile_workflow'] = 'Feedback Flow: reconciliar fluxo de avaliação e alocação';
$string['task_backfill_workflow'] = 'Feedback Flow: recuperar instantes de liberação do registro de logs';
$string['privacy:metadata:sub:allocmarkerid'] = 'Professor alocado para avaliar este envio.';
```

---

## 6. Backfill — `classes/task/backfill_workflow.php`

`{assign_user_flags}` has **no** `timecreated` and **no** `timemodified` in 4.5, 5.1 or 5.3 (503 `db/install.xml:135-152`: `id, userid, assignment, locked, mailed, extensionduedate, workflowstate` — nothing else). `{assign_allocated_marker}` has no timestamp either (503 `db/install.xml:193-206`: `id, student, assignment, marker`). **`{logstore_standard_log}` is the only historical source.**

This is a real class, registered in `db/tasks.php` with a `task_backfill_workflow` string, driven by the `workflow_backfill_done` / `workflow_backfill_lastid` flags armed in §3.6. The first draft armed the flags and wrote the method bodies but never defined the class or its registration.

### 6.1 `timereleased` from the log store

```php
    /**
     * Recover release instants from the standard log store.
     *
     * Only rows whose releasesource is 'assumed' or NULL are touched: an
     * 'event' or 'reconciled' stamp is already at least as good.
     *
     * @param int $lastid Keyset cursor over logstore_standard_log.id.
     * @param int $batch
     * @return int New cursor position.
     */
    private function backfill_releases(int $lastid, int $batch): int {
        global $DB;

        $sql = "SELECT l.id, l.contextinstanceid AS cmid, l.relateduserid AS userid,
                       l.timecreated, l.other
                  FROM {logstore_standard_log} l
                 WHERE l.eventname = :eventname
                   AND l.contextlevel = :ctxlevel
                   AND l.relateduserid > 0
                   AND l.id > :lastid
              ORDER BY l.id ASC";
        $params = [
            // Single-quoted: backslash-m and backslash-e are not PHP escapes,
            // so this is the literal value the log store holds.
            'eventname' => '\mod_assign\event\workflow_state_updated',
            'ctxlevel' => CONTEXT_MODULE,
            'lastid' => $lastid,
        ];

        $rows = $DB->get_records_sql($sql, $params, 0, $batch);
        foreach ($rows as $r) {
            $lastid = (int) $r->id;
            $other = \tool_log\helper\reader::decode_other($r->other);
            if (workflow_state::normalise($other['newstate'] ?? null) !== workflow_state::RELEASED) {
                continue;
            }
            submission_ledger::apply_release_at(
                (int) $r->cmid,
                (int) $r->userid,
                (int) $r->timecreated,
                workflow_state::SOURCE_BACKFILL
            );
        }
        return $lastid;
    }
```

`apply_release_at()` is a narrow sibling of `apply_workflow_state()` that stamps `timereleased`/`timeclosed` at a **historical** instant without re-reading the live flags row (the live state is irrelevant to a past release) and overwrites only when `releasesource IN (NULL, 'assumed')`. It uses the same `latest_row_at()` resolution, with `$when` = the log timestamp, which is exactly why R5 resolves by `timesubmitted <= $when`.

### 6.2 `timeallocated` from the log store

Identical shape with `'eventname' => '\mod_assign\event\marker_updated'`. Because the allocation mutator only stamps while `timeallocated IS NULL` (R1), the 5.3 duplicate-event storm collapses to the earliest occurrence without any `MIN()` in SQL. Here the marker id **is** taken from `$other['markerid']` — the live core table no longer reflects a historical allocation — and `timeallocmarker` is set equal to `timeallocated` for backfilled rows, with `allocsource = 'backfill'`.

### 6.3 Decoding `other`

`other` is `json_encode()`d when `logstore_standard/jsonformat` is on (shipped default 1, `store/standard/settings.php`) and PHP-`serialize()`d when off (`admin/tool/log/classes/helper/buffered_writer.php:75-78`). **Both formats coexist in the same table on any upgraded site.**

Use core's own helper — `\tool_log\helper\reader::decode_other()` (`admin/tool/log/classes/helper/reader.php:83-89`) is already `public static` and handles both. The first draft shipped a private copy whose `is_array($decoded) ? $decoded : (array) $decoded` tail turns a failed `json_decode()` returning `false` into `[false]` rather than `[]`.

Never filter on `other` in SQL: with `jsonformat` on the payload is `{"markerid":123}`, with it off `a:1:{s:8:"markerid";i:123;}`, and PostgreSQL JSONB operators are not portable to MariaDB. Select by `eventname` + `contextlevel` + `relateduserid` and decode in PHP.

### 6.4 Join keys

| Log column | Maps to |
|---|---|
| `contextinstanceid` (with `contextlevel = 70`) | `block_feedback_tracker_sub.cmid` — **the reliable key** |
| `relateduserid` | `block_feedback_tracker_sub.userid` (the student) |
| `userid` | the actor (coordinator), not the marker |
| `other['markerid']` | the marker |
| `objectid` | the **assign instance id** (`objecttable = 'assign'`), not the cmid and not a submission id — useless as a join key |
| `timecreated` | the transition instant |

There is no `attemptnumber` in the log, which is exactly why `latest_row_at()` resolves by `timesubmitted <= $when`.

### 6.5 Guard: the log store may not exist

```php
        if (!$DB->get_manager()->table_exists('logstore_standard_log')) {
            set_config('workflow_backfill_done', '1', 'block_feedback_tracker');
            return;
        }
```

### 6.6 What is **not** recoverable, ever

| Path | Why |
|---|---|
| 4.5/5.1 grading-form allocation (405 `:8682`) | The trigger at 405 `:8683-8689` is guarded on `$formdata->workflowstate !== $oldworkflowstate` — `marker_updated` is never reached. **This is the dominant per-student workflow.** |
| 4.5/5.1 quick-grading allocation (405 `:7339`) | Same defect, guarded on `$workflowstatemodified` (405 `:7341-7346`) |
| `mod_assign_set_user_flags` — workflow **and** allocation, all versions | No `->trigger()` anywhere in the method (405 `externallib.php:961-1054`) |
| Allocation **removal** / clearing, all versions | No `marker_removed`-style event class exists; on 5.3 `array_filter()` (503 `:7884`) drops marker 0 before the trigger loop |
| Restore / course-module duplicate, **all versions** | 405 `restore_assign_stepslib.php:205-206` re-inserts `assign_user_flags` with both `workflowstate` and `allocatedmarker` mapped; 503 `restore_assign_stepslib.php:244-253` inserts `assign_allocated_marker`. No events on any branch |
| 5.3 upgrade migration | 503 `db/upgrade.php:200-204` is a bulk `INSERT…SELECT`, no event, and it copies `marker = 0` rows |
| Anything older than `logstore_standard/loglifetime` | `logstore_standard\task\cleanup_task` prunes on schedule |
| The **previous** marker after any re-allocation | Overwritten in place (4.5/5.1) or deleted (5.3); `marker_updated` carries only the new `markerid` |
| Grading of a superseded attempt | `update_grade()` returns at 405 `:3025-3027` — no event ever existed to log |

**Consequences the read path must state explicitly:** `alloc_numgraded30d < numgraded30d` for at least 30 days post-upgrade; alloc medians are computed over a smaller, newer, and on 4.5/5.1 permanently biased sample; hence `alloc_coverage_pct` is a required companion to every alloc median, not a nice-to-have. On 5.3 only, `{assign_mark}.timecreated` (503 `db/install.xml:180`) is a genuine lower bound for markers who actually marked — a secondary source in phase 5.

---

## 7. Version compatibility

`$plugin->supported = [405, 502]` today. 503 is **out of the mounted set** (`plugins.conf` `stacks = auto` resolves from `version.php`), so 5.3 code paths cannot be exercised locally until `supported` max moves to `503` — and per the fleet rule that bump lands in the same commit as the `ci.yml` job list and the README compatibility table.

| Concern | 4.5 | 5.1 | 5.3 | One code path |
|---|---|---|---|---|
| `assign_user_flags.workflowstate` | present | present | present | **No branching.** The release clock needs nothing version-specific |
| Workflow state constants | 6 values, `''` seeded | identical | identical + orthogonal `ASSIGN_MULTIMARKING_*` (503 `:87-98`) | `workflow_state::normalise()` |
| `workflow_state_updated` / `marker_updated` classes | — | byte-identical to 4.5 | byte-identical | one observer each |
| `workflow_state_updated` firing sites | 3: quick `:7344`, batch `:8501`, form/WS `:8687` | 3: `:7508`, `:8661`, `:8847` | 4: quick `:7815`, batch `:9112`, aggregation `:9367`, form/WS `:9418` | idempotent handler |
| Event **ordering** | form: workflow→graded; **quick: workflow→graded** (405 `:7344` then `:7347`); **batch: graded→workflow** (405 `:8499` then `:8501`) | same | same | never depend on ordering — the handler is order-free by construction |
| `other['newstate']` reliability | matches the stored column | matches | **can differ** — batch with `workflowcontext='mark'` writes the aggregate (503 `:9360`) but fires the per-marker state (503 `:9112`) | rule R6: mirror the live column, confirm releases |
| `assign_user_flags.allocatedmarker` | present | present | **dropped** (503 `db/upgrade.php:205`) | `marker_source::mode()` probe |
| `assign_allocated_marker` | absent | absent | present, 0..N rows, phantom `marker = 0` rows on upgraded sites | `AND am.marker > 0` always |
| `marker_updated` firing | batch only (`:8559`) | batch only (`:8719`) | `update_allocated_markers()` (`:7900`), N× per call — reached unconditionally only from the grading form (`:9425`, and only when `!teamsubmission`) | `apply_allocation()` re-reads core and stamps only while NULL |
| 5.3 team submissions + allocation | n/a | n/a | the grading form **never** updates allocations for team submissions (503 `:9424`); only batch (`:9174`) and quick grading (`:7823`) can | reconciler covers it |
| Per-marker timestamps | none | none | `assign_mark.timecreated/timemodified` (503 `db/install.xml:180-181`) — the only real per-state timestamp in mod_assign | phase 5 enrichment only |
| `set_user_flags` WS + `allocatedmarker` | writes | writes | accepted (503 `externallib.php:957`, `:1003-1004`), **silently discarded** — `update_record()` drops unknown properties | document; nothing to reconcile on 503 |

The single branching point:

```php
/**
 * Which core storage holds marker allocations on this site.
 *
 * 4.5 and 5.1 keep it in {assign_user_flags}.allocatedmarker; 5.3 moved it to
 * {assign_allocated_marker} and dropped the column (503 db/upgrade.php:205).
 * Probed from the column metadata rather than from $CFG->branch so a site on a
 * backported or forked core is still handled correctly. get_columns() is
 * MUC-cached by core in the databasemeta cache, so this is effectively free.
 */
class marker_source {
    /** Allocation lives in assign_user_flags.allocatedmarker (4.5, 5.1). */
    public const MODE_FLAGS = 'flags';
    /** Allocation lives in assign_allocated_marker (5.3+). */
    public const MODE_TABLE = 'table';
    /** Neither is available. */
    public const MODE_NONE = 'none';

    /** @var string|null Per-request memo of the resolved mode. */
    private static ?string $mode = null;

    /**
     * Resolve the allocation storage mode for this site.
     *
     * @return string One of self::MODE_*.
     */
    public static function mode(): string {
        global $DB;
        if (self::$mode !== null) {
            return self::$mode;
        }
        $columns = $DB->get_columns('assign_user_flags');
        if (array_key_exists('allocatedmarker', $columns)) {
            return self::$mode = self::MODE_FLAGS;
        }
        if ($DB->get_manager()->table_exists('assign_allocated_marker')) {
            return self::$mode = self::MODE_TABLE;
        }
        return self::$mode = self::MODE_NONE;
    }

    /**
     * Lowest non-zero marker currently allocated to a student.
     *
     * The `marker > 0` filter is mandatory on 5.3: the migration inserted one
     * row per pre-existing flags row with no WHERE clause (503
     * db/upgrade.php:200-204), so essentially every graded student on an
     * upgraded site carries a phantom marker = 0 allocation.
     *
     * @param int $assignid
     * @param int $userid Student.
     * @return int Marker user id, or 0 when none.
     */
    public static function lowest_marker(int $assignid, int $userid): int {
        global $DB;
        return match (self::mode()) {
            self::MODE_FLAGS => (int) $DB->get_field(
                'assign_user_flags',
                'allocatedmarker',
                ['assignment' => $assignid, 'userid' => $userid],
                IGNORE_MISSING
            ),
            self::MODE_TABLE => (int) $DB->get_field_sql(
                "SELECT MIN(marker)
                   FROM {assign_allocated_marker}
                  WHERE assignment = :assignid AND student = :userid AND marker > 0",
                ['assignid' => $assignid, 'userid' => $userid]
            ),
            default => 0,
        };
    }

    /**
     * The reconciler's detection query for this site's storage mode.
     *
     * @return string SQL with named placeholders substatus, lastid, since.
     */
    public static function reconcile_sql(): string {
        return self::mode() === self::MODE_TABLE ? self::SQL_TABLE : self::SQL_FLAGS;
    }
}
```

`MODE_NONE` degrades to workflow-only tracking, keeping the plugin installable on a core that reorganises allocation again.

Also noted for 5.3 readiness, **not to be implemented before the `supported` bump**: `{assign_mark}` is keyed on `gradeid`, and `{assign_grades}` carries `attemptnumber` — making it the only attempt-scoped workflow artefact in any version, and the only place a per-marker `readyforreview` instant can be read.

---

## 8. Prioritised implementation order

Each phase is independently shippable, leaves CI green, and does not depend on the next. Every phase carries its own `version.php` bump + `CHANGELOG.md` entry in the same commit, and any `amd/src` edit ships its rebuilt `amd/build` bundle in that same commit.

### Phase 1 — Release clock (`2026080300`, release `1.0.37`)

**Fixes:** `FALSE-GRADED` (B3, B4, B5, C8) — the actual bug.

**Ships:** the whole §3.2 column block (both halves, so the schema is final and `latest_row_at()` can select its full list); `idx_status_closed`, `idx_cg_closed`, `idx_status_alloc`, `idx_marker`; `awaiting_release`, `median_release_lag_h` on `_group`; the `workflow_state` helper; `observer::workflow_state_updated` + `observer::course_module_updated` + their `db/events.php` entries; `submission_ledger::apply_workflow_state()`, `apply_release_at()`, `resync_instance_flags()`, `seed_missing_row()`, and the extraction of `recompute_measures()`; rules R1–R6; `backfill_workflow` task + registration + string; **the complete §2.6 predicate migration**; `track_marking_workflow`, `workflow_reconcile_window_days`, `release_lag_alert_hours` settings, seeded in `db/install.php`; privacy provider metadata + export for the new columns; `awaiting_release` as a **separate badge**, never a fourth count tile.

**Do not** touch the three-way pending partition. `rollup_service.php:172-182` documents that critical/overgoal/within-goal sum to `pending`, and `responsiveness_card.php:86-89` derives the first tile by subtraction (`$waiting = max(0, $p['pending'] - $overgoal - $critical)`), duplicated in `GroupCard.js:187`. A fourth tile makes both wrong in opposite directions with no test failure.

**Post-upgrade CLI, in the release note:** `cli/backfill_trends.php --days=60`, `cli/recompute_all.php`, and one forced `recompute_site_stats` run.

**Tests**
- `tests/local/sla/submission_ledger_test.php`: workflow OFF → `timeclosed === timegraded`, `releasesource === 'implicit'`, every existing assertion unchanged — the regression gate for "preserves today's numbers".
- workflow ON, grade saved at `inmarking` → `timegraded` set, `timereleased` NULL, `timeclosed` NULL, **row still pending**. (This is the test the first draft's own code would have failed.)
- workflow ON → `released` → `timeclosed === release instant`; a second `released` event does not move it (R1).
- release event whose `timecreated` precedes `timegraded` → `timeclosed === timegraded`, not the earlier instant.
- un-release after release → `workflowstate === 'inmarking'`, `timereleased` unchanged.
- R2: batch transition bumps `assign_grades.timemodified` → `timegraded` does not move. **And**: revert-to-draft then resubmit → `timegraded` is **cleared**, `waitinghours` is not `0.0`, `slabucket` is not `excellent`.
- R3: `workflowstate = ''` normalises to `notmarked` and is not a transition.
- R5: `add_attempt()` after release → the new attempt row has `timereleased` NULL and status `reopened` (therefore not pending).
- R6: an event carrying `newstate` that disagrees with `{assign_user_flags}` → the stored column follows the table, not the payload; a second identical poll does not re-enqueue the dirty queue.
- Released-with-no-grade (B10): row lands in the graded window, not nowhere.
- `userid = 0` team submission row is excluded from `awaiting_release`.
- `tests/local/sla/rollup_service_test.php`: `awaiting_release` counted; the three pending bands still sum to `pending`.
- Upgrade test: a fixture with one workflow-released and one workflow-unreleased historical row → `releasesource` `'assumed'` and NULL respectively; `timeclosed` set and NULL respectively.
- `tests/external/get_pending_submissions_test.php` via `call_external_function()` + `clean_returnvalue()`, asserting the new `timereleased`/`timeclosed` keys survive.
- `tests/privacy/provider_test.php`: the new `_sub` columns in metadata **and** in `export_user_data`.
- Behat: one thin smoke scenario — grade with workflow on, assert the block still shows the submission as pending; release, assert it clears.

### Phase 2 — Allocation clock, ledger only (`2026080400`, release `1.0.38`)

**Fixes:** `MISSED-no-event` for allocation (C2–C7); makes C9 separable. No schema change (phase 1 shipped the columns).

**Ships:** `marker_source` (all three methods + both SQL constants); `observer::marker_updated` + `db/events.php` entry; `submission_ledger::apply_allocation()` with **rule R4** and the `timeallocated` / `timeallocmarker` split; `reconcile_workflow` task + `db/tasks.php` entry; `track_marking_allocation`, `alloc_goal_hours`, `alloc_bucket_thresholds_eff` settings (+ `db/install.php` seeds); `bucket::for_alloc()` + `parse_thresholds_alloc()`; the §6.2 allocation backfill. **No UI, no WS shape change yet** — the columns fill quietly for a release cycle so the coverage percentage is meaningful before anything is displayed.

**Tests**
- R4 headline: allocation stamped at or after `timegraded` → `allocsource === 'late'`, `allochours === null`, `allocbucket === null`. Assert **not** `0.0` and **not** `'excellent'`.
- `marker_updated` fired three times for the same student (the 5.3 re-save pattern) → `timeallocated` equals the first event's `timecreated`, and no redundant `update_record` / dirty-queue write on events 2 and 3.
- Marker reassignment: A on day 0, B on day 8, graded day 9 → `allocmarkerid === B`, `timeallocated === day 0` (queue metric), `timeallocmarker === day 8`, `allochours ≈ 1 business day` — **not** 9.
- De-allocation detected by the reconciler → `allocmarkerid` NULL, `timeallocated` and `timeallocmarker` unchanged.
- Reconciler idempotence: two consecutive runs over an unchanged fixture perform zero writes and zero dirty-queue enqueues.
- Reconciler does not close a reopened attempt: release the flags row via a direct DB write (simulating the WS), `add_attempt()`, resubmit, run the task → the new attempt is **not** closed.
- `marker_source::mode()` returns `flags` on the 405/501 stacks; both SQL variants are parsed with a `LIMIT 0` execution so the 5.3 branch is syntax-checked on 501.
- Mutation check: remove `AND am.marker > 0` mentally; a fixture seeding a `marker = 0` row must go red.
- Cross-DB: `mdl ci moodle-block_feedback_tracker` — the reconcile `GROUP BY` must pass on PostgreSQL and MariaDB.

### Phase 3 — Rollup, score clock switch, and display (`2026080500`, release `1.0.39`)

**Ships:** the remaining eleven `_group` columns and the two `_trend` columns; `rollup_service::recompute_group_locked()` gains the allocated/unallocated partition and the alloc/queue medians; `trend_service` gains the alloc series; `sla_clock` + the `recompute_endpoint` task (§5.1); `responsiveness_calculator` honours `sla_clock` (no sixth weight — the clock changes which interval feeds the existing five terms, so `effective_weights()` and `tests/lockstep/js_php_lockstep_test.php` are untouched); WS shape extensions to `get_responsiveness`, `get_dashboard` (`CACHE_KEY_VERSION` 7 → 8, `get_dashboard.php:52`), `get_report_scopes`, `get_pending_submissions::row_structure()` (`:207-222`, shared with `get_graded_submissions.php:176`), `get_grader_priority_list`, `responsiveness_payload::group_payload()` + its cache key; UI in `GroupCard.js`, `PendingReportView.js`, `DashboardView.js` (**including `:156`'s `const numeric = [...]` sortable whitelist — a metric omitted there renders but cannot be sorted, silently**), `PriorityCard.js`, `GradeNowPanel.js`, the server no-JS twin `responsiveness_card.php::build_metrics()`, and `templates/responsiveness_card.mustache`.

`cli/recompute_all.php` must run after upgrade — `{block_feedback_tracker_group}` is materialised and every new column reads NULL until it does. Copy the null-tolerant fallback shape from `responsiveness_payload.php:471-476`.

**Tests**
- `rollup_service_test.php`: the §1 C9 scenario end to end — submit Mon 09:00, allocate Thu+10d 09:00, grade Thu+10d 11:00 → `effectivehours ≈ 82.00`, `queuehours ≈ 80.00`, `allochours = 2.00`, `allocbucket = 'excellent'`, `unallocated` correct while pending.
- `sla_clock = 'submission_grade'` **after** the `recompute_endpoint` task drains reproduces the pre-1.0.37 numbers exactly on a workflow fixture (the legacy escape hatch actually works — and does not before the task runs, which is asserted too).
- `sla_clock = 'allocation_grade'` excludes `timeallocmarker IS NULL` rows from `numgraded30d` while `waitinghours` / `effectivedays` / `slabucket` keep the student endpoint.
- `alloc_coverage_pct` computed and non-null whenever `alloc_numgraded30d > 0`.
- Trend continuity: `cli/backfill_trends.php` over a fixture spanning the upgrade produces a single-clock series with no step at the upgrade date.
- WS tests via `call_external_function()` + `clean_returnvalue()` for every extended `execute_returns()` — `clean_returnvalue()` strips undeclared keys silently, and `group_payload()` feeds three surfaces, so a partial edit shows up as `null` in exactly one UI.
- `js_php_lockstep_test.php` still green (proof the score contract did not move).

### Phase 4 — Marker attribution (`2026080600`, release `1.1.0`)

**This is the phase that turns a reading hazard into a data claim.** Today no metric is attributed to a named person: the rollup keys on `(courseid, groupid)` (`db/install.xml`, `uq_course_group`). Storing `allocmarkerid` already makes the **marker a data subject** in phase 1 — the privacy provider work is therefore *not* deferrable to here; phase 4 only adds the second table.

**Ships:** `{block_feedback_tracker_alloc}`; per-marker rollup; a `mod/assign:manageallocations`-gated visibility rule so a marker sees only their own figures; **mandatory** display of `alloc_coverage_pct` beside every per-marker median, plus an explicit "allocation queue time excluded" label.

**Ship only if the product decision is explicitly made.** On 4.5/5.1 the exact-timestamp subset is *batch allocations only*; every other allocation is `reconciled` at cron resolution. Publishing a per-marker league table off that sample is not defensible.

**Tests:** privacy provider tests for the marker as a second data subject (contexts, userlist, export, all three deletes); a capability mutation test — remove the marker-visibility gate and exactly one test goes red.

### Phase 5 — Moodle 5.3 multi-marker (`2026080700`, release `1.2.0`)

Blocked on `$plugin->supported` max moving to `503`, with `.github/workflows/ci.yml` job list and the README compatibility section in the same commit.

**Ships:** N-marker support through `{block_feedback_tracker_alloc}`; `{assign_mark}.timecreated` (503 `db/install.xml:180`) as a secondary allocation lower bound — allocation is provably `<=` it on the mark path, since `apply_grade_to_user()` refuses a mark from a non-allocated user (503 `:9379-9387`); handling for the 5.3 aggregation event (503 `:9367`) and the C12 double-event; a documented note that `ASSIGN_MULTIMARKING_METHOD_FIRST` is defined (503 `:90`) but absent from `update_mark()`'s switch (503 `:3332-3338`) and therefore behaves like `manual`.

**Tests:** `mdl phpunit m53 block_feedback_tracker` plus the full three-stack run before tagging.

---

## 9. Files this design touches that the first draft never mentioned

| Path | Phase | What changes |
|---|---|---|
| `db/install.php` | 1, 2, 3 | `$defaults` (`:43-95`) — every new setting seeded here or a fresh install has none of them |
| `classes/privacy/provider.php` | 1 | `get_metadata()` (`:89-107`) and `export_user_data()` (`:307`) enumerate every `_sub` column; metadata-only fails the core compliance test in CI |
| `templates/responsiveness_card.mustache` | 1 | the `awaiting_release` badge, the "Context variables required" docblock list, and non-empty data in the mandatory `Example context (json):` block |
| `CLAUDE.md` (this repo) | 1 | `:530-536` documents `idx_status_graded (submissionstatus, timegraded)` as the covering index for every SLA read and enumerates the read sites; both go stale |
| `tests/generator/lib.php` | 1 | `create_ledger_row()` defaults (~`:139`) and its docblock example (`:32`) — otherwise every new test hand-writes fourteen columns |
| `README.md` | 1 | `:25` compatibility line (phase 5), plus a new section: the SLA endpoint moved, non-workflow sites see identical numbers, marking-workflow sites see a backlog appear, and the post-upgrade CLI is required |
| `docs/` | 1 | the metric glossary — `waitinghours`/`effectivehours` now end at close, not at grading |
| `classes/task/backfill_workflow.php` + `db/tasks.php` + `task_backfill_workflow` | 1 | the task the first draft armed with config flags but never defined |
| `classes/task/recompute_endpoint.php` + `db/tasks.php` + `task_recompute_endpoint` | 3 | what makes `sla_clock` actually do something |

---

## 10. The five things most likely to ship broken

1. **`timeclosed` written as a coalesce.** `timereleased ?? timegraded` looks harmless and reads naturally, and it silently reinstates the exact bug this work exists to remove — with the phase-1 pending test still passing on non-workflow fixtures. The rule is conditional on `markingworkflow`, always.
2. **A partial predicate migration.** Moving the `IS NULL` predicates without the graded-window ones puts a row in `pending` and `numgraded30d` simultaneously; the pending trio still sums to `pending`, so no existing test fails. §2.6 is a checklist, not a suggestion.
3. **R4 forgotten.** `academic_time.php:98-100` returns `0.0` for a non-positive interval and `bucket::for_effective(0.0)` is `excellent` (`bucket.php:51-58`). A reconciled allocation landing after grading then reads as a flawless marker turnaround — the worst possible failure direction for a fairness metric. The same rule now guards the student clock after a revert-and-resubmit.
4. **`awaiting_release` added as a fourth count tile.** The trio is derived by subtraction in two independent copies (`responsiveness_card.php:86-89` and `GroupCard.js:187`); over-counting clamps to 0 in one surface and reports a wrong positive in the other, with no test failure.
5. **A payload key added without the matching `execute_returns()` entry.** `clean_returnvalue()` strips it silently; the field appears as `null` in exactly one of the three surfaces `group_payload()` feeds.

Honourable mention, because it has shipped inverted before: **the trend sign** is duplicated in `responsiveness_card.php:168-172`, `amd/src/lib/format.js:152-164` and `amd/src/lib/trend.js`. Route anything new exclusively through `trend.js`.