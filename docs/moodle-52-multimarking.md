# Multi-marking: Moodle 5.2 (supported today) and 5.3

**Correction, 2026-08-03.** An earlier revision of this file attributed every
change below to Moodle 5.3 and framed it as future work. That was wrong, and
CI caught it: **multi-marking landed in Moodle 5.2**, which is inside this
plugin's declared `$plugin->supported = [405, 502]` range. The consequences
were live defects on a supported branch, not forward-compatibility notes.

Verified on 2026-08-03 against `MOODLE_501_STABLE` and `MOODLE_502_STABLE`
(fetched from the upstream mirror) and the local 5.3-dev checkout at
`~/dev/moodle`. Line numbers are 5.2 unless stated and will drift; the
surrounding code is quoted so a claim can be re-found.

## What 5.2 changed, and what it broke

| | 5.1 | 5.2 and 5.3 |
|---|---|---|
| Marker allocation | `assign_user_flags.allocatedmarker` | `{assign_allocated_marker}`, one row per marker; the column is **dropped** |
| Per-marker marks | — | `{assign_mark}` |
| Assign settings | — | `markercount`, `multimarkmethod`, `multimarkrounding` |
| `marker_updated` | batch operation only | every allocation path, one event **per marker** |

Two things followed from the dropped column:

1. **`stamp_allocation_for_user()` read a column that no longer exists**, and
   `marker_updated` does fire on 5.2 — so the observer went down with it.
   Fixed: `submission_ledger::allocated_marker_id()` branches on
   `table_exists('assign_allocated_marker')`.
2. **The PHPUnit fixtures wrote the same column.** Fixed by routing them
   through `allocate_marker()` / `set_workflow_state()` in
   `tests/generator/lib.php`, which pick the model the running core has.

Process note, recorded so it is not repeated: the local stacks are 4.5, 5.1 and
5.3-dev, so 5.02 was never exercised — but `mdl ci --branch MOODLE_502_STABLE`
has always been able to run it. **Run every branch in `supported`, not the ones
that happen to be mounted.**

## 1. No new event classes at all

`diff -rq` over `mod/assign/classes/event/` between 5.1 and 5.3 reports **no
differences**. Every 5.2 and 5.3 behaviour change is a change in *where core
triggers* an existing event, never in the event's own payload. Two consequences:

- The plugin's `db/events.php` needs no new subscriptions for 5.3.
- Every payload assumption already verified on 4.5/5.1 still holds.

The only event class added anywhere in the module in recent releases is
`\assignfeedback_file\event\feedback_downloaded`, which is **5.1+** (absent
from 4.5) and irrelevant here — a download is not a response.

---

## 2. The new data model (5.2+)

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
precedes the mark. Worth mining to raise allocation coverage
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

Three things to account for — on 5.02 today, not only on a future 5.3 branch:

1. **Partial marking emits nothing.** Until the last of `markercount` markers
   submits, no `assign_grades` row is touched and no event fires. A submission
   with 1 of 2 marks in is genuinely still pending — the current behaviour is
   *correct*, so it should be asserted by a test rather than "fixed".
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

## 4. `marker_updated` is fixed in 5.2 — partially

On 4.5/5.1 the event fires only from the batch "Set allocated marker"
operation; quick grading and the grading form write the flag silently. 5.2
routes every path through `update_allocated_markers()` (5.2 `locallib.php:7835`,
5.3 `:7872`),
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

So on 5.2 and later `allocsource` should reach `observed` far more often — but two traps
survive:

- **De-allocation still fires nothing.** The `delete_records()` at the top is
  silent, and re-allocating a subset emits events only for the survivors. A
  marker removed entirely leaves no trace.
- **One event per marker** means the last event does not identify "the" marker.
  The plugin already re-reads core rather than trusting the payload, and
  already picks `MIN(marker)` deterministically; keep both properties.

---

## 5. Workflow-state events: four trigger sites, one of them new

5.2 and 5.3 have four `workflow_state_updated` triggers against three on 5.1
(5.2 line numbers; 5.3 differs by a few lines):

| Line | Path |
|---|---|
| `7778` | quick grading |
| `9075` | batch "Set marking workflow state" |
| `9330` | **new** — the aggregate state recomputed from all markers' `assign_mark` rows |
| `9381` | the grading form |

The new one carries an *overall* state derived from the per-marker rows, while
`:9112` carries a per-marker state that is **not** what the table settles on.
The plugin's rule of mirroring the live `{assign_user_flags}.workflowstate` and
treating `other['newstate']` as a hint is what makes this safe — keep it, or
the stored column will flap on every poll and permanently
re-dirty the rollup.

---

## 6. What still needs doing

In dependency order:

1. **N-marker support is still missing on 5.02, which is supported today.**
   `allocmarkerid` holds one id; a student with several allocated markers is
   reduced to the lowest. That is deterministic but incomplete — the per-marker
   table from the allocation design's phase 4 is what makes per-marker figures
   meaningful.
2. **`$plugin->supported` max to `503`** when 5.3 work starts, with
   `.github/workflows/ci.yml` job list and the README compatibility table in
   the same commit (fleet rule).
3. **`assign_mark.timecreated` as an allocation lower bound**, raising
   coverage where `marker_updated` was missed.
4. **A reconciliation sweep over `assign_mark`** for the `manual` / `first`
   methods, where partial marking leaves no event trail at all.
5. **Assert, do not fix, partial marking**: a submission with fewer marks than
   `markercount` is correctly pending. Add the test before touching anything.
6. Run **every** supported branch before tagging:
   `mdl ci moodle-block_feedback_tracker --branch MOODLE_405_STABLE`,
   `MOODLE_501_STABLE`, `MOODLE_502_STABLE`, plus `--db mariadb` and, once 5.3
   is in range, `mdl phpunit m53 block_feedback_tracker`.

## 7. What does not need doing

- No `db/events.php` additions (§1).
- No payload-shape changes for existing events (§1).
- No change to the cycle model, the latest-attempt gate, or the team fan-out —
  `assign_submission`, `assign_grades` and the `latest` flag are unchanged in
  5.2 and 5.3, and `count_submissions_need_grading_with_groups()` keeps the same
  predicate it gained in 5.1.
