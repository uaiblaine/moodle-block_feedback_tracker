# Moodle 5.3 readiness — what changes for this plugin

Everything recorded here was verified against the 5.3-dev checkout at
`~/dev/moodle` on 2026-08-03 and against 5.1 (`~/dev/moodle-501`) for the
delta. Line numbers are the 5.3-dev tree and will drift; the surrounding code
is quoted so a claim can be re-found.

The plugin currently declares `$plugin->supported = [405, 502]`, so it is not
mounted on the `m53` stack. This file is the checklist for the day a
`MOODLE_503_STABLE` branch is opened — plus the one item that had to be fixed
immediately, because `supported` is advisory and does not block installation.

---

## 0. Already handled — do not redo

**`assign_user_flags.allocatedmarker` no longer exists.** 5.3 drops the column
and migrates its contents into the new `{assign_allocated_marker}` table
(`mod/assign/db/upgrade.php:194-206`). Reading it raises a DML error, and
`marker_updated` *does* fire on 5.3, so the observer would have gone down with
it. `submission_ledger::allocated_marker_id()` now branches on
`table_exists('assign_allocated_marker')` and reads whichever model the site
has. No further action on the 5.3 branch.

---

## 1. No new event classes at all

`diff -rq` over `mod/assign/classes/event/` between 5.1 and 5.3 reports **no
differences**. Every 5.3 behaviour change is a change in *where core triggers*
an existing event, never in the event's own payload. Two consequences:

- The plugin's `db/events.php` needs no new subscriptions for 5.3.
- Every payload assumption already verified on 4.5/5.1 still holds.

The only event class added anywhere in the module in recent releases is
`\assignfeedback_file\event\feedback_downloaded`, which is **5.1+** (absent
from 4.5) and irrelevant here — a download is not a response.

---

## 2. Multi-marking: the new data model

Three new `{assign}` columns (`markercount`, `multimarkmethod`,
`multimarkrounding`) and two new tables.

### `{assign_mark}` — one row per marker per grade

```
id, assignment, gradeid (-> assign_grades.id), timecreated, timemodified,
marker (userid), mark, workflowstate
```

**`timecreated` is the only per-marker timestamp core has ever stored**, and it
is a usable secondary lower bound for allocation: `apply_grade_to_user()`
refuses a mark from a user who is not allocated, so allocation provably
precedes the mark. Worth mining on the 5.3 branch to raise allocation coverage
above what `marker_updated` alone provides.

### `{assign_allocated_marker}` — N markers per student

```
id, student (userid), assignment, marker (userid)
```

**No timestamp column**, exactly like the 4.5/5.1 flag it replaces. The
allocation instant remains unknowable unless observed as it happens. This is
the same constraint the plugin already works around; 5.3 does not relieve it.

---

## 3. Aggregation fires `submission_graded` late, or never

`update_mark()` (`locallib.php:3275`) writes the `assign_mark` row and then:

```php
$marks = $this->get_marks($grade->id, $grade->userid);

// If not all markers have left a mark, we can't calculate the grade yet.
if (count($marks) < $this->get_instance()->markercount) {
    return true;
}

switch ($this->get_instance()->multimarkmethod) {
    case 'maximum':
        return $this->calculate_and_update_grade_from_maximum_mark($grade, $marks);
    case 'average':
        return $this->calculate_and_update_grade_from_average_mark($grade, $marks);
}

// The manual method requires a manual intervention to set the grade, so nothing to do here.

return true;
```

Three things the 5.3 branch must account for:

1. **Partial marking emits nothing.** Until the last of `markercount` markers
   submits, no `assign_grades` row is touched and no event fires. A submission
   with 1 of 2 marks in is genuinely still pending — the current behaviour is
   *correct*, and the 5.3 branch should assert that rather than "fix" it.
2. **`manual` never aggregates.** The switch has no `manual` case; it falls
   through to `return true`. A separate manual grade save is required, which
   *does* fire `submission_graded` through the normal path.
3. **`ASSIGN_MULTIMARKING_METHOD_FIRST` is defined (`locallib.php:90`) but
   absent from the switch**, so `first` behaves exactly like `manual`. If a
   later 5.3 build wires it up, this note goes stale — re-check the switch
   before relying on it.

Note also that the switch compares against **string literals**, not the
`ASSIGN_MULTIMARKING_METHOD_*` constants. Mirror the literals, not the
constants, if the plugin ever has to branch on the method.

---

## 4. `marker_updated` is fixed in 5.3 — partially

On 4.5/5.1 the event fires only from the batch "Set allocated marker"
operation; quick grading and the grading form write the flag silently. 5.3
routes every path through `update_allocated_markers()` (`locallib.php:7872`),
which fires the event **once per marker**:

```php
// First, remove all markers allocated to this student and assignment.
$DB->delete_records('assign_allocated_marker', ['student' => $studentid, 'assignment' => ...]);
...
foreach ($markerids as $markerid) {
    ...
    \mod_assign\event\marker_updated::create_from_marker($this, $student, $marker)->trigger();
}
```

So on 5.3 `allocsource` should reach `observed` far more often — but two traps
survive:

- **De-allocation still fires nothing.** The `delete_records()` at the top is
  silent, and re-allocating a subset emits events only for the survivors. A
  marker removed entirely leaves no trace.
- **One event per marker** means the last event does not identify "the" marker.
  The plugin already re-reads core rather than trusting the payload, and
  already picks `MIN(marker)` deterministically; keep both properties.

---

## 5. Workflow-state events: four trigger sites, one of them new

5.3 has four `workflow_state_updated` triggers against three on 5.1:

| Line | Path |
|---|---|
| `7815` | quick grading |
| `9112` | batch "Set marking workflow state" |
| `9367` | **new** — the aggregate state recomputed from all markers' `assign_mark` rows |
| `9418` | the grading form |

The new one carries an *overall* state derived from the per-marker rows, while
`:9112` carries a per-marker state that is **not** what the table settles on.
The plugin's rule of mirroring the live `{assign_user_flags}.workflowstate` and
treating `other['newstate']` as a hint is what makes this safe — the 5.3 branch
must keep it, or the stored column will flap on every poll and permanently
re-dirty the rollup.

---

## 6. What the 5.3 branch should actually ship

In dependency order:

1. **`$plugin->supported` max to `503`**, with `.github/workflows/ci.yml` job
   list and the README compatibility table in the same commit (fleet rule).
2. **N-marker support** in the allocation columns. Today `allocmarkerid` holds
   one id; 5.3 needs the per-marker table from the allocation design's phase 4
   before per-marker figures mean anything.
3. **`assign_mark.timecreated` as an allocation lower bound**, raising
   coverage where `marker_updated` was missed.
4. **A reconciliation sweep over `assign_mark`** for the `manual` / `first`
   methods, where partial marking leaves no event trail at all.
5. **Assert, do not fix, partial marking**: a submission with fewer marks than
   `markercount` is correctly pending. Add the test before touching anything.
6. Re-run `mdl phpunit m53 block_feedback_tracker` plus the full three-stack
   run before tagging.

## 7. What does not need doing

- No `db/events.php` additions (§1).
- No payload-shape changes for existing events (§1).
- No change to the cycle model, the latest-attempt gate, or the team fan-out —
  `assign_submission`, `assign_grades` and the `latest` flag are unchanged in
  5.3, and `count_submissions_need_grading_with_groups()` keeps the same
  predicate it gained in 5.1.
