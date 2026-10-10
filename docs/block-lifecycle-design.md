# Design — delayed cleanup on block removal + bulk block-removal tool

`block_feedback_tracker` · current `$plugin->version = 2026080302`, `release = '1.0.39'`, `supported = [405, 502]` (`version.php:27-31`)

**Citation roots.** Core paths are relative to `/Users/uaiblaine/dev/moodle-501/public/` unless prefixed `405:` (= `/Users/uaiblaine/dev/moodle-405/`). Plugin paths are relative to `/Users/uaiblaine/dev/moodle-block_feedback_tracker/`. Every line number below was re-read from the file during this revision.

---

## 0. Premises: what holds, what does not

| Premise | Verdict |
|---|---|
| `blocks_delete_instance()` (`lib/blocklib.php:2545`) calls `instance_delete()` synchronously | **Confirmed** — `lib/blocklib.php:2557-2559`. The block class has no override, so it inherits `block_base::instance_delete()` → `return true` (`blocks/moodleblock.class.php:550`); `block_feedback_tracker.php` defines only `init`/`get_content`/`build_block_payload`/`has_config`/`applicable_formats` (`:41`, `:57`, `:131`, `:150`, `:160`). |
| No `backup/` directory → plugin tables not in course backups | **Confirmed** — no `backup/` in the repo. Only the generic `block_instances` row is backed up, by `backup_default_block_task` (`backup/moodle2/backup_default_block_task.class.php:36`). **This is why the grace window is the primary recovery mechanism and why the delay needs a floor.** |
| A scheduled `reconcile_ledger` + retention `prune_ledger` exist; `processable_course_ids()` drives the reconciler | **Confirmed** — `db/tasks.php:96`, `db/tasks.php:107`, `classes/task/reconcile_ledger.php:94`. |
| **`is_processable()` returns true only when a block instance lives on the course context** | **INCOMPLETE — load-bearing.** `classes/local/sla/course_access.php:64` ANDs `$courseid > 0`, the course record exists, **and `(int) $course->visible === 1` unless `process_hidden_courses` is set** (`:83-86`), *then* block presence (`:88-90`). `processable_course_ids()` (`:143`) appends `AND c.visible = 1` in SQL (`:153`). Consequence: **`is_processable()` must never be the run-time guard for a deletion and must never build the bulk tool's candidate list.** Hiding a course would otherwise become a data-destruction trigger, and the bulk tool would go blind to exactly the archived courses it exists to clear. |
| *(implied by "event observer vs instance_delete")* `\core\event\block_deleted` | **DOES NOT EXIST**, on 5.1 or 4.5. `lib/classes/event/` contains only `message_user_blocked.php`, `message_user_unblocked.php`, `url_blocked.php`; `rg block_deleted` over both checkouts → zero hits. `blocks_delete_instance()` triggers no event of any kind (`lib/blocklib.php:2545-2567`). No `block_created`/`block_updated`/`block_moved` either. **Removing a block currently leaves zero trace in `{logstore_standard_log}`.** The only two hooks are `instance_delete()` and the `pre_block_delete` plugin callback (`lib/blocklib.php:2549`). |

### 0.1 Corrections to the previous draft of this design

Four findings from review that I verified and that change the design, not just its citations:

1. **`blocks/feedback_tracker/lib.php` is never loaded on the deletion path.** `block_load_class()` (`lib/blocklib.php:2206`) includes only `blocks/<name>/block_<name>.php`, and `block_feedback_tracker.php` contains no `require`/`include` at all. `get_plugins_with_function('pre_block_delete')` includes a plugin's `lib.php` only when that plugin *defines* the requested function, which this one does not. So calling `block_feedback_tracker_is_bootstrapping()` (`lib.php:49`) from `instance_delete()` **fatals on every block deletion site-wide**. The plugin's own pages prove the rule: `pages/reset.php:26` and `settings.php:27` both `require_once` lib.php explicitly. Fix in §1.1.
2. **`blocks_delete_all_for_context()` does NOT set `$skipblockstables`.** `lib/blocklib.php:2613` is `blocks_delete_instance($instance, true)` — the second positional binds to `$nolongerused` (signature at `:2545`), despite that parameter's own docblock at `:2543` naming this caller. So on the course-deletion and delete-mode-restore paths, `{block_instances}` rows **are** deleted per instance inside the loop (`:2563-2566`). Only `blocks_delete_instances()` passes it (`:2588`). The "arm unconditionally" conclusion is unchanged; the stated reason was half wrong.
3. **`reconcile_ledger::sweep_orphans()` is both course-ungated and row-creating.** It accepts `array $processable` and never uses it — its SQL predicate is only `l.id > :cursor AND (cm.id IS NULL OR s.id IS NULL)` (`classes/task/reconcile_ledger.php:346-364`) — and at `:379-381` it calls `dirty_queue::enqueue($courseid, $groupid, …)`, which applies no gate whatsoever (`classes/local/sla/dirty_queue.php:60-82`). This makes two things false that were previously asserted: that "every row-creating sweep is filtered by `processable_course_ids()`", and that the ungated sweeps are harmless. Worse, `remove_course_contents()` deletes the blocks (`lib/moodlelib.php:4817`, `:4820`) *and then* the course modules, so during a delete-mode restore every ledger row for the course has a missing `cm` — and `sweep_orphans` runs every 2h (`db/tasks.php:96-99`). A restore longer than two hours has its history destroyed by the reconciler before the grace window ever expires. **Fix required, and it is in scope: §1.7 gates `sweep_orphans` on `$processable`.**
4. **`removal_cleanup_active` IS retroactive.** Arming materialises `timedue` at arming time. If rows accumulate while the switch is off, every one of them is already past due the moment an admin ticks the box, and the next tick destroys the whole backlog with no grace and no review. Fix in §1.3.

---

## 1. Delayed cleanup on block removal

### 1.1 Trigger: `instance_delete()` only

| Candidate | Verdict |
|---|---|
| `\core\event\block_deleted` observer | Impossible — the event does not exist. |
| `block_feedback_tracker_pre_block_delete($instance)` in `lib.php` | **Rejected.** `get_plugins_with_function()` returns `[]` when `during_initial_install() \|\| isset($CFG->upgraderunning)` (`lib/moodlelib.php:7211-7215`), so this hook is silently dead through every install/upgrade/uninstall. It also fires for *every* block type and would need a `blockname` filter. |
| **`block_feedback_tracker::instance_delete()` override** | **Chosen.** Fires on every deletion path with no upgrade suppression, is type-scoped by construction, and has `$this->instance` populated (`blocks/moodleblock.class.php:463` in `_load_instance()`), so `parentcontextid` is available. |

```php
// block_feedback_tracker.php

/** @var bool True while uninstall_cleanup() is tearing every instance down. */
private static $uninstalling = false;

/**
 * Called ONCE by \core\plugininfo\block::uninstall_cleanup() (lib/classes/plugininfo/block.php:181)
 * before it loops blocks_delete_instance() over every instance (:186-188).
 * The flag is the only signal that distinguishes "this plugin is going away"
 * from "an admin removed the block from a course".
 *
 * The flag MUST be static: before_delete() is invoked on a separate,
 * instance-less object (block_instance($block->name)), while each
 * instance_delete() runs on block_instance($instance->blockname, $instance).
 * An instance property would silently do nothing.
 *
 * @return void
 */
public function before_delete() {
    self::$uninstalling = true;
}

/**
 * Arm the delayed discard of this course's measured data.
 *
 * Deliberately unconditional. instance_delete() runs BEFORE the row is
 * deleted (lib/blocklib.php:2558 vs :2564), and blocks_delete_instances()
 * defers every row deletion to after the loop ($skipblockstables = true at
 * lib/blocklib.php:2588), so a sibling count taken here is wrong in both
 * directions on that path. The decision is made at sweep time.
 *
 * @return bool
 */
public function instance_delete() {
    if (self::$uninstalling) {
        return true;
    }
    try {
        \block_feedback_tracker\local\sla\pending_removal::arm_from_instance($this->instance);
    } catch (\Throwable $e) {
        // Never let bookkeeping break block deletion for the whole site.
        debugging('block_feedback_tracker: could not arm cleanup: ' . $e->getMessage());
    }
    return true;
}
```

**No `lib.php` call from this path.** `pending_removal` is an autoloaded namespaced class and must not depend on a globals file having been included. It carries its own inline bootstrap guard, duplicating the three signals `block_feedback_tracker_is_bootstrapping()` uses (`lib.php:49-63`) against its own table:

```php
// classes/local/sla/pending_removal.php
private static function unsafe_to_arm(): bool {
    global $CFG, $DB;
    if (during_initial_install() || !empty($CFG->upgraderunning)) {
        return true;
    }
    try {
        return !$DB->get_manager()->table_exists('block_feedback_tracker_pend');
    } catch (\Throwable $e) {
        return true;
    }
}
```

`arm_from_instance()` filters on the parent context level: `context::instance_by_id($instance->parentcontextid, IGNORE_MISSING)` must be `CONTEXT_COURSE`. That single test excludes module-context blocks torn down during course deletion (`lib/moodlelib.php:4817` deletes child-context blocks before `:4820` deletes the course-context ones) and category/system blocks — matching the gate's own deliberate scope (`course_access.php:96-107`).

`instance_create()` is also overridden to cancel a pending row when the block comes back. It is an optimisation, not the correctness mechanism: its only two core call sites are `lib/blocklib.php:863` and `:1238`, and it is bypassed by restore (raw `$DB->insert_record('block_instances', …)` at `backup/moodle2/restore_stepslib.php:4581`), by `my/lib.php`, and by the test generator (`lib/testing/generator/block_generator.php:136`).

### 1.2 Storage: a plugin table, not `{task_adhoc}`

**Rejected: a delayed adhoc task.** Four reasons, all verified:

1. **No cancel path on 4.5.** `manager::delete_adhoc_task()` exists on 5.1 (`lib/classes/task/manager.php:1005`) and `admin/tool/task/delete_adhoctasks.php` with it; neither exists on 4.5 (`405:lib/classes/task/manager.php` has no such method; `405:admin/tool/task/` has no `delete_adhoctasks.php`). `get_queued_adhoc_task_record()` is `protected` there with no `$includefailed` parameter (`405:lib/classes/task/manager.php:197` vs `lib/classes/task/manager.php:200`), so a permanently-failed row blocks re-queueing that course for ever.
2. **Dedupe is exact-JSON string equality and races.** `queue_adhoc_task($task, true)` compares `sql_compare_text(customdata)`, and `sql_compare_text()` is a no-op on both CI drivers (`lib/dml/moodle_database.php:2249-2251` → `sql_order_by_text`). `{task_adhoc}` has no unique index (`lib/db/install.xml:3523-3532`: keys `primary`, `useriduser`; indexes `nextruntime_idx`, `timestarted_idx`, `nextruntime_classname`), so it is a read-then-insert race.
3. **Orphan rows are immortal.** A renamed class makes `adhoc_task_from_record()` throw; cron `debugging()`s and skips for ever, and `clean_failed_adhoc_tasks()` (`lib/classes/task/manager.php:1906-1918`) only reaps `attemptsavailable = 0 AND firststartingtime < …`.
4. **The intent is invisible.** No "which courses are pending cleanup?" query, no UI, no reconfigurable window.

**Chosen: a plugin-owned table swept by a scheduled task** — the `tool_recyclebin` shape. It gives cancel, list, count and idempotence for free, behaves identically on 4.5 and 5.x, and **dies with `drop_plugin_tables()` on uninstall** (`lib/adminlib.php:240`).

```xml
<!-- db/install.xml -->
<TABLE NAME="block_feedback_tracker_pend"
       COMMENT="Courses whose feedback_tracker block was removed and whose measured data is scheduled for discard. One row per course. Swept by cleanup_removed_courses, which re-verifies block absence and restore quiescence before deleting anything. Carries no user-linked column ON PURPOSE: the actor is recorded in {block_feedback_tracker_log} and in the course_data_discarded event, so this table needs no privacy-provider entry.">
  <FIELDS>
    <FIELD NAME="id"             TYPE="int"  LENGTH="10" NOTNULL="true"  UNSIGNED="true" SEQUENCE="true"/>
    <FIELD NAME="courseid"       TYPE="int"  LENGTH="10" NOTNULL="true"  UNSIGNED="true" SEQUENCE="false"/>
    <FIELD NAME="timequeued"     TYPE="int"  LENGTH="10" NOTNULL="true"  UNSIGNED="true"
           COMMENT="When the removal was observed. Restarts on re-arm; doubles as this table's timecreated." SEQUENCE="false"/>
    <FIELD NAME="timedue"        TYPE="int"  LENGTH="10" NOTNULL="true"  UNSIGNED="true"
           COMMENT="Materialised at arming time. The sweeper may defer past this instant (cleanup switched on later, cron outage, restore in flight); it never fires before it." SEQUENCE="false"/>
    <FIELD NAME="reason"         TYPE="char" LENGTH="20" NOTNULL="true"  DEFAULT="manual"
           COMMENT="manual|bulk|orphan" SEQUENCE="false"/>
    <FIELD NAME="lastinstanceid" TYPE="int"  LENGTH="10" NOTNULL="false" UNSIGNED="true"
           COMMENT="block_instances.id that triggered the arming; already deleted by sweep time. Audit only." SEQUENCE="false"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
  </KEYS>
  <INDEXES>
    <INDEX NAME="uq_courseid"  UNIQUE="true"  FIELDS="courseid"/>
    <INDEX NAME="idx_timedue"  UNIQUE="false" FIELDS="timedue"/>
  </INDEXES>
</TABLE>
```

Conventions, each checked against the file:

- **Index names.** `uq_*` for unique, `idx_*` for non-unique, without exception in this plugin — `uq_cm_user_att_cycle` (`db/install.xml:52`), `uq_course_group` (`:109`, `:165`), `uq_course_group_day` (`:129`), `uq_day` (`:150`), `uq_daydate` (`:185`), `uq_courseid` (`:260`). Reusing the name `uq_courseid` is fine; index names are per-table in XMLDB, and `uq_course_group` already appears twice.
- **Table-name length.** `xmldb_table::NAME_MAX_LENGTH = 63 - PREFIX_MAX_LENGTH(10) = 53` (`lib/xmldb/xmldb_table.php:41`, `:50`, enforced at `:719`). `block_feedback_tracker_pend` is 27; the longest existing plugin table is `block_feedback_tracker_bfcursor` at 31 (`db/install.xml:247`). Comfortable.
- **No `timecreated`.** Every other plugin table has one, and this one deliberately does not: `timequeued` *is* the creation stamp and restarts on re-arm, so a separate `timecreated` would either duplicate it or lie. Stated so nobody reads it as an oversight.
- **No `queuedby`.** Load-bearing for §1.8: the provider declares only tables with a user-linked column (`classes/privacy/provider.php` declares `_sub`, `_cday`, `_chours`, `_cpause`, `_log`; `_group`/`_trend`/`_site`/`_queue`/`_bfcursor` are absent and CI passes), so a user-free `_pend` needs **zero privacy work**. The actor lives in the audit row and in the event's `userid`.

**Arming is a plain read-then-write upsert, never a caught unique violation.** `blocks_delete_instances()` is demonstrably called inside a delegated transaction in core (`my/lib.php:279`), and `instance_delete()` runs inside whatever transaction its caller opened. On PostgreSQL a unique-index violation aborts the entire transaction, so the fallback `update_record` would also fail and the swallowing `catch (\Throwable)` in `instance_delete()` would hide it while the caller's commit blew up. Use `get_record` → `update_record` else `insert_record`, and accept the harmless idempotent race. A repeated removal **restarts** the clock — the last removal is the one the admin remembers.

### 1.3 Settings

New heading **"Removal and cleanup"** in `settings.php`, immediately after the Processing-scope block (heading `:236-240`, `process_hidden_courses` `:247-252`, `backfill_active` `:259-264`) and before the Performance heading (`:267-271`). It is the mirror image of the opt-in gate, and `backfill_active` — a destructive-adjacent master switch with its own explanatory comment — is the shape to copy.

| Key | Type | Default | Where |
|---|---|---|---|
| `removal_cleanup_active` | `admin_setting_configcheckbox` | **`0`** | new heading, own `add()` with comment |
| `removal_grace_hours` | `admin_setting_configtext`, `PARAM_INT` | `1` | new heading |
| `removal_orphan_scan` | `admin_setting_configcheckbox` | `0` | new heading |
| `removal_batch_size` | `admin_setting_configtext`, `PARAM_INT` | `200` | **the `$perf` array (`settings.php:273-284`)** |

**Correction:** `removal_batch_size` belongs in `$perf`, not under the new heading. Every numeric batch/cap knob in this plugin lives there, including the direct analogues `reconcile_batch_size` (`:282`) and `retention_batch_size` (`:284`), all written by one loop (`:285-292`).

**Correction:** the previous draft cited `settings.php:254-264` as the `retention_active` precedent. Lines 254-264 are `backfill_active`. `retention_active` is the last member of the `$viewbools` array under the Views heading (`settings.php:317`, loop `:319-326`) — an odd home, and not one to copy.

**Master switch defaults to OFF.** Intentional, and it is the plugin's own rule for every destructive switch: `retention_active` = 0, `backfill_active` = 0, "an upgrade must never start deleting a site's history because a new version shipped a policy" (`classes/local/sla/retention.php:37-41`; `lang/en/block_feedback_tracker.php:464`). The *delay* defaults to 1 hour as specified; the *feature* is opt-in.

**With the switch off, removal still arms a pending row** so the pending list shows what would be discarded — and that creates a retroactivity trap the previous draft got wrong. `timedue` is materialised at arming time, so every row armed while the switch was off is already past due the instant the switch is ticked, and one `*/15` tick would destroy the entire backlog with no grace and no review.

**Fix — `removal_cleanup_enabledsince`, plus one `set_updatedcallback`.** This is the one setting in this feature that has a retroactive effect, and it is the only one that gets a callback.

```php
// lib.php — settings.php:27 already require_once's this file.
/**
 * Stamp the instant delayed cleanup was switched on, so rows armed while it
 * was off get a full fresh grace window instead of firing immediately.
 *
 * @return void
 */
function block_feedback_tracker_removal_cleanup_toggled(): void {
    if (block_feedback_tracker_is_bootstrapping()) {
        return;
    }
    $on = (int) (get_config('block_feedback_tracker', 'removal_cleanup_active') ?: 0) === 1;
    if ($on && !get_config('block_feedback_tracker', 'removal_cleanup_enabledsince')) {
        set_config('removal_cleanup_enabledsince', (string) time(), 'block_feedback_tracker');
    } else if (!$on) {
        unset_config('removal_cleanup_enabledsince', 'block_feedback_tracker');
    }
}
```

`execute_row()` then treats the effective due instant as `max($row->timedue, $enabledsince + grace_seconds())`. Every backlogged row gets a full grace window, visible in the pending list, after the switch flips. The bootstrap short-circuit is mandatory — `admin_apply_default_settings()` fires updated-callbacks during install (fleet rule; the plugin already applies it at `settings.php:232`).

**The `MAX_PENDING_DAYS` abandon pass runs even when the master switch is off.** Otherwise rows accumulate unbounded for the entire time cleanup is disabled. Abandoning deletes only a `_pend` row, never data, so it is safe on the disabled path.

**What 0 and absurd values mean.** Mirror `retention::cutoff()` (`retention.php:61-76`): an out-of-range value is a misconfiguration and falls back to the default. **Both** ends are clamped — the previous draft's prose promised that and its code clamped only the floor.

```php
// classes/local/sla/pending_removal.php
public const DEFAULT_GRACE_HOURS = 1;
public const MIN_GRACE_SECONDS = 1800;      // 30 minutes.
public const MAX_GRACE_SECONDS = 604800;    // 7 days; beyond this, MAX_PENDING_DAYS eats it anyway.
public const MAX_PENDING_DAYS = 14;

public static function grace_seconds(): int {
    $hours = (int) (get_config('block_feedback_tracker', 'removal_grace_hours') ?: self::DEFAULT_GRACE_HOURS);
    $seconds = $hours * HOURSECS;
    if ($seconds < self::MIN_GRACE_SECONDS || $seconds > self::MAX_GRACE_SECONDS) {
        /* Too small and the sweeper can fire inside a restore's delete-then-
         * recreate window; too large and the row outlives MAX_PENDING_DAYS and
         * is abandoned instead of acted on. Either way it is a
         * misconfiguration, so fall back rather than behave surprisingly. */
        $seconds = self::DEFAULT_GRACE_HOURS * HOURSECS;
    }
    return $seconds;
}
```

So `removal_grace_hours = 0` does **not** mean "delete immediately". **Immediate deletion is only ever reachable through the bulk tool's separately-confirmed "remove and discard now" action** (§2.6) — an explicit, attributable, foreground act with a human looking at the row counts.

**Staleness ceiling.** A row whose effective due instant is more than `MAX_PENDING_DAYS` in the past is **abandoned**: the sweeper deletes the pending row *without touching data* and writes an audit entry. Cron down for a fortnight is not a mandate to destroy data on a two-week-old decision the moment it comes back — and nothing in core caps how late a queued job runs (the adhoc dispatcher only ever filters `nextruntime < :timestart`, with no upper bound and no GC for a never-started row, `lib/classes/task/manager.php:1906-1918`). With `removal_orphan_scan` on, the next scan re-arms with a fresh timestamp and a fresh grace, so the deletion happens on a *current* decision that was visible in the pending list first.

**Cron-outage resume.** The ceiling covers a fortnight; it does not cover three days. On the first sweep after a detected gap — `time() - $this->get_last_run_time() > 2 * grace_seconds()` — the task re-stamps every pending row's `timedue` to `time() + grace_seconds()` and fires nothing that tick. Without this, everything armed during a 3-day outage (including anything armed by a restore that crashed mid-plan) is destroyed within 15 minutes of cron returning.

### 1.4 The sweeper

New scheduled task, `*/15` in `db/tasks.php` (four checks an hour is ample against a ≥30-minute grace), sharing `drain_time_cap_seconds` like every other bounded task here, with a `/* … */` block comment in the file's house style (`db/tasks.php:91-94`, `:104-105`).

```php
// classes/task/cleanup_removed_courses.php
class cleanup_removed_courses extends \core\task\scheduled_task {
    public function execute(): void {
        // The abandon pass runs unconditionally: it only ever deletes _pend
        // rows, and without it a disabled site accumulates them for ever.
        $abandoned = pending_removal::abandon_stale();

        if ((int) (get_config('block_feedback_tracker', 'removal_cleanup_active') ?: 0) !== 1) {
            mtrace('cleanup_removed_courses: disabled by setting; no measured data is ever deleted.');
            return;
        }
        if (pending_removal::resume_after_outage($this->get_last_run_time())) {
            mtrace('cleanup_removed_courses: cron gap detected; pending rows re-stamped, nothing discarded.');
            return;
        }

        $batch = (int) (get_config('block_feedback_tracker', 'removal_batch_size') ?: 200);
        $deadline = time() + (int) (get_config('block_feedback_tracker', 'drain_time_cap_seconds') ?: 50);
        $discarded = 0;

        foreach (pending_removal::due($batch) as $row) {
            if (time() > $deadline) { break; }
            $discarded += pending_removal::execute_row($row) ? 1 : 0;
        }
        if ($discarded > 0) {
            // Site aggregates changed under every viewer.
            \cache_helper::purge_by_definition('block_feedback_tracker', 'site_comparison');
        }

        if ((int) (get_config('block_feedback_tracker', 'removal_orphan_scan') ?: 0) === 1) {
            pending_removal::scan_orphans($batch);   // ARMS ONLY. Never deletes.
        }
    }
}
```

**Run-time re-verification — the correctness mechanism.** `pending_removal::execute_row()` in exact order:

```php
$courseid = (int) $row->courseid;
$due = max((int) $row->timedue, self::enabled_since() + self::grace_seconds());
if ($due > time()) {
    return false;   // Not due yet under the effective deadline.
}

// 1. Course gone. course_deleted (lib/moodlelib.php:4741) already ran
//    delete_for_course() via observer.php:452-458; repeat it because the call
//    is delete_records-by-courseid and therefore idempotent, and because the
//    observer may never have fired (event lost, cron-side deletion path).
if (!$DB->record_exists('course', ['id' => $courseid])) {
    submission_ledger::delete_for_course($courseid);
    self::cancel($courseid);
    return true;
}

// 2. Context unresolvable on a course row that exists = broken site.
//    ABORT and leave the row pending. Absence of the block cannot be proven.
$coursectx = \context_course::instance($courseid, IGNORE_MISSING);
if (!$coursectx) {
    debugging(...);
    return false;
}

// 3. A restore is in flight against this course. Its content — including the
//    block — was deleted at restore_controller::execute_plan()
//    (backup/controller/restore_controller.class.php:394-399) and is
//    recreated only near the end of the plan
//    (backup/moodle2/restore_stepslib.php:4581). Firing now destroys the
//    history of a course that is about to have its block back. Defer.
if (self::restore_in_flight($courseid)) {
    self::restamp($courseid);
    return false;
}

// 4. THE GUARD. Block presence ONLY. Never course_access::is_processable():
//    that ANDs course visibility (course_access.php:83-86), so hiding a course
//    would let this pass and delete data for a course that still has the block.
if (course_access::block_present_for_course($courseid)) {
    self::cancel($courseid);
    recompute_log::record(recompute_log::REASON_COURSE_DISCARDED, 0, null,
        ['courseid' => $courseid, 'outcome' => 'cancelled_block_present']);
    return false;
}

// 5. Delete, then invalidate the caches that hold the deleted rows.
$counts = submission_ledger::delete_for_course($courseid);
$DB->delete_records('block_feedback_tracker_queue', ['courseid' => $courseid]); // Last, not only first.
self::invalidate_caches_for_course($courseid);
self::cancel($courseid);
recompute_log::record(recompute_log::REASON_COURSE_DISCARDED, array_sum($counts), null,
    ['courseid' => $courseid, 'reason' => $row->reason] + $counts);
\block_feedback_tracker\event\course_data_discarded::create([
    'context' => $coursectx,
    'courseid' => $courseid,
    'other' => $counts + ['source' => $row->reason],
])->trigger();
return true;
```

**Step 3 — the in-flight-restore guard**, verified implementable and exact:

```php
/**
 * True when a restore controller is currently pointed at this course.
 *
 * restore_controller_dbops::save_controller() writes
 * $rec->itemid = $controller->get_courseid() (backup/util/dbops/
 * restore_controller_dbops.class.php:64) with operation = 'restore', and
 * set_status() persists the row at STATUS_AWAITING / NEED_PRECHECK /
 * EXECUTING (restore_controller.class.php:228-229). The content delete at
 * execute_plan() (:394-399) happens before STATUS_EXECUTING is set by
 * restore_plan::execute() (backup/util/plan/restore_plan.class.php:167), so
 * test status < STATUS_FINISHED_ERR rather than == STATUS_EXECUTING.
 *
 * The timemodified bound is mandatory: a hard crash (fatal, OOM) leaves a row
 * at status 800 for ever, and without the bound that course could never be
 * cleaned again.
 *
 * @param int $courseid
 * @return bool
 */
private static function restore_in_flight(int $courseid): bool {
    global $DB;
    return $DB->record_exists_select(
        'backup_controllers',
        'operation = :op AND itemid = :cid AND status < :done AND :cutoff < ' .
            $DB->sql_greatest_column_expr_placeholder,   // see note
        ['op' => 'restore', 'cid' => $courseid, 'done' => \backup::STATUS_FINISHED_ERR,
         'cutoff' => time() - DAYSECS]
    );
}
```

In practice write the recency test as `AND (timemodified > :cutoff OR (timemodified = 0 AND timecreated > :cutoff))` — `save_controller()` inserts with `timemodified = 0` and only sets it on update (`restore_controller_dbops.class.php:80-86`). `{backup_controllers}` carries `operation`, `itemid`, `status`, `timecreated`, `timemodified` on both branches (`lib/db/install.xml:3019`; `405:lib/db/install.xml:3008`), and `backup::STATUS_EXECUTING = 800` / `STATUS_FINISHED_ERR = 900` / `STATUS_FINISHED_OK = 1000` are identical on both (`backup/backup.class.php:115-117`; `405:backup/backup.class.php:116-117`).

**Step 5 — cache invalidation.** The previous draft had none, which contradicted both of the plugin's own destructive paths: `block_feedback_tracker_invalidate_rollups()` and `block_feedback_tracker_reset_data()` each call `calendar::bump_version()`, `academic_time::reset_memos()` and `cache_helper::purge_by_definition()` for `calendar_effective_day` + `pause_windows_by_course` (`lib.php:86-87`, `:103-107`, `:156-164`). Deleting `_cpause` rows without invalidating leaves `pause_lookup` serving them from both the MUC entry and its per-request memo (`classes/local/calendar/pause_lookup.php:88-95`, `:113-115`).

A per-course discard does **not** justify a global calver bump (wrong blast radius — every cache on the site). Targeted instead:

```php
private static function invalidate_caches_for_course(int $courseid): void {
    $key = calendar::current_version() . '_' . $courseid;   // pause_lookup.php:89-90
    \cache::make('block_feedback_tracker', 'pause_windows_by_course')->delete($key);
    pause_lookup::reset_memo();
}
```
`site_comparison` (`{calver}_v1`) is purged once per sweep that discarded anything, in `execute()`. The session-scoped `dashboard_payload` / `responsiveness_payload` cannot be targeted; they are short-lived and the block is gone from that course anyway. Say so rather than pretend.

Required change: **`course_access::block_present_for_course()` becomes `public`** (currently `private static`, `course_access.php:109-126`). It is already the exact predicate — `context_course::instance($courseid, IGNORE_MISSING)` in a `try` plus `record_exists('block_instances', ['blockname' => 'feedback_tracker', 'parentcontextid' => $coursectx->id])`. Do not re-express it; one implementation, one place to be wrong.

### 1.5 Every false-positive path, and its defusal

| # | Path | What core does | Defusal |
|---|---|---|---|
| 1 | **Restore / import with "delete existing contents"** | `restore_controller::execute_plan()` calls `restore_dbops::delete_course_content()` **before** the plan runs, for **`TARGET_CURRENT_DELETING` OR `TARGET_EXISTING_DELETING`** (`backup/controller/restore_controller.class.php:394`, delete at `:399`) → `remove_course_contents()` (`backup/util/dbops/restore_dbops.class.php:1949-1951`) → `blocks_delete_all_for_context()` for every child context and the course context (`lib/moodlelib.php:4817`, `:4820`) → `instance_delete()`. The block is re-inserted near the end of the plan by raw `$DB->insert_record` (`restore_stepslib.php:4581`) with a **new instance id, same courseid**. Also reached from `backup/import.php:196-197` and `core_course_import_course` with `deletecontent=1` (`course/externallib.php:1812-1813`, `:1847-1848`). | **Four layers, in order of strength.** (a) **The step-3 `{backup_controllers}` guard** — the actual defence, and the only one that holds for a restore longer than the grace window. (b) The step-4 `record_exists` re-check cancels the row once the block is back. (c) `MIN_GRACE_SECONDS = 1800` keeps short restores clear of the sweeper entirely. (d) A `\core\event\course_restored` observer cancels early so the pending list is not polluted for an hour after every delete-mode restore. |
| 2 | **Restore with the root `blocks` setting off, or a source backup with no feedback_tracker block** | `restore_block_task::build()` returns immediately, building no steps (`backup/moodle2/restore_block_task.class.php:70-73`); the delete happened anyway. The block is gone **permanently**. | **The cleanup must fire.** This is why the `course_restored` observer **re-checks block presence** and only then cancels — a blanket "a restore touched this course, cancel" rule would leak the data for ever. It is also why `course_restored` is layer (d) only: `restore_plan::execute()` triggers it exclusively for `backup::TYPE_1COURSE` (`backup/util/plan/restore_plan.class.php:172`, trigger at `:192`), so a single-activity delete-mode restore emits nothing — and *not firing* correctly leaves the cleanup armed. |
| 3 | **Course copy / duplicate / merge restore** | `TARGET_NEW_COURSE` (`backup/backup.class.php:99`) and the `*_ADDING` targets fail the condition at `restore_controller.class.php:394` — `delete_course_content()` is never called. | Nothing arms. (Consequence for §2: a copied course has a block and no data; the bulk tool's `ledgerrows` column shows 0, which is honest.) |
| 4 | **Course delete / category delete-with-contents** | `delete_course()` (`lib/moodlelib.php:4684`) → `remove_course_contents()` (called `:4726`, declared `:4776`) deletes the blocks at `:4817`/`:4820`, then the modules → arms. Context deleted `:4729`, course row `:4731`, `\core\event\course_deleted` fires `:4741`. Category path: `course/classes/category.php:2046` → `delete_course()`. | **`observer::course_deleted()` (`classes/local/sla/observer.php:452-458`) gains `pending_removal::cancel($courseid)` after the existing `delete_for_course()`.** Sweeper step 1 is the belt-and-braces. **No double-delete**: `delete_for_course()` is `delete_records` by `courseid` — the second call removes 0 rows. Note `\core\event\course_content_deleted` (`lib/moodlelib.php:5040`) fires on **both** real deletion and delete-mode restore, so it cannot discriminate — do not observe it. |
| 5 | **Plugin uninstall** | `uninstall_plugin()` → `\core\plugininfo\block::uninstall_cleanup()` (`lib/adminlib.php:193`) calls `before_delete()` **once** (`lib/classes/plugininfo/block.php:181`) then `blocks_delete_instance()` **per instance** (`:186-188`). `unset_all_config_for_plugin()` at `lib/adminlib.php:225`, tables dropped at `:240`. Also reached from `admin/cli/uninstall_plugins.php --purge-missing`, which the fleet CLAUDE.md tells you to run after mount changes. | **`before_delete()` sets `self::$uninstalling = true`; `instance_delete()` short-circuits.** Belt and braces: the `unsafe_to_arm()` table check, and the `_pend` table is dropped ~15 lines later anyway. |
| 6 | **Block re-parented, or rows deleted by raw SQL** | `upgrade_block_delete_instances()` deletes `{block_instances}` rows with `delete_records_select` (`lib/db/upgradelib.php:1270-1285`), bypassing `instance_delete()` entirely. A `parentcontextid` move via `block_manager::save_block_data()` (`lib/blocklib.php:1910`, `:1963`) writes one plain `update_record` with no delete, no create, no hook, no event — but `bui_parentcontextid` is not a user-editable form element and `save_block_data()` only rewrites `parentcontextid` inside the front-page-editing branch, which this block cannot reach (`applicable_formats()` sets `'site' => false`, `block_feedback_tracker.php:160-167`). | No deletion hook can see either. Covered only by the **orphan scan** — and for the re-parenting sub-case the orphan scan is the *cause* of destruction, not the fix: a block made sticky at a higher context still renders on the course page while `block_present_for_course()` returns false, so the scan would arm and the sweeper would discard a visibly-tracked course. Stated here and in §5 because it is the orphan scan's real cost. |
| 7 | **A second instance still present** | Nothing prevents two `feedback_tracker` instances on one course context: `instance_allow_multiple()` defaults false (`blocks/moodleblock.class.php:506`) and the block does not override it, but `{block_instances}` has **no unique index** on `(blockname, parentcontextid)` (`lib/db/install.xml:2798-2803`) and the restore duplicate guards only compare blocks on the same page pattern. | **Arm unconditionally; decide at sweep time.** On `blocks_delete_instances()` the whole chunk still exists at hook time (`$skipblockstables = true`, `lib/blocklib.php:2588`); on `blocks_delete_all_for_context()` each row is deleted inside the loop (`:2613` passes `$nolongerused`, so `!$skipblockstables` holds) and you see yourself plus the not-yet-processed siblings. Wrong in different ways on different paths — hence: never count siblings in the hook. The step-4 `record_exists` runs long after all deletions have landed. |
| 8 | **Deleted then re-added inside the window** | `block_manager::add_block()` → `instance_create()` (`lib/blocklib.php:863`; second call site `:1238`). | `instance_create()` cancels the pending row immediately (fast, visible). The step-4 re-check catches it regardless — necessary, because restore/copy/generator all bypass `instance_create()`. |
| 8b | **A pre-removal course backup restored in ADDING mode** | `backup_default_block_task` (`backup/moodle2/backup_default_block_task.class.php:36`) *does* back up the `{block_instances}` row even though the plugin's tables are not backed up. | Restoring it inside the grace window re-creates the block, and the step-4 re-check cancels the discard. **This is the only recovery other than the pending-list Cancel** and belongs in the confirmation copy. |
| 9 | **Course hidden** (unrelated admin act, or `\core\task\hide_ended_courses_task`) | Nothing block-related happens. | Nothing arms. The guard is block presence, never `is_processable()`. See §5, risk 2. |
| 10 | **Course reset** | `reset_course_userdata()` (`lib/moodlelib.php:5090`) contains no reference to blocks anywhere in its body. | Nothing arms. Correct — reset deletes the submissions, and `reconcile_ledger::sweep_orphans()` removes the stranded ledger rows. |
| 11 | **PHPUnit / Behat fixtures** | `create_block()` bypasses `instance_create()` (`lib/testing/generator/block_generator.php:136`); a fixture teardown may delete instances. | Harmless — `resetAfterTest()` wipes `_pend` too. Tests that assert on arming must call `blocks_delete_instance()` explicitly. |

**The orphan scan** (`removal_orphan_scan`, default **0**) is the only thing that closes row 6's raw-SQL path. It is a keyset-paged sweep, bounded by `removal_batch_size`, that finds courses with plugin data and no block on their course context and **arms a pending row — it never deletes directly**:

```sql
SELECT DISTINCT l.courseid
  FROM {block_feedback_tracker_sub} l
  JOIN {course} c ON c.id = l.courseid
 WHERE l.courseid > :cursor
   AND NOT EXISTS (
       SELECT 1
         FROM {block_instances} bi
         JOIN {context} ctx ON ctx.id = bi.parentcontextid
                           AND ctx.contextlevel = :ctxlevel
        WHERE bi.blockname = :blockname
          AND ctx.instanceid = l.courseid)
   AND NOT EXISTS (
       SELECT 1 FROM {block_feedback_tracker_pend} p WHERE p.courseid = l.courseid)
 ORDER BY l.courseid ASC
```

Off by default because on a site that used the plugin before the strict opt-in the first run would arm hundreds of courses at once — that may be exactly what the admin wants, but it must be a decision, and the pending list shows every armed row for the full grace period first. `AND c.visible = 1` is **deliberately absent** (§0).

### 1.6 What is deleted, and what is deliberately not

`submission_ledger::delete_for_course()` (`classes/local/sla/submission_ledger.php:1116-1124`) is extended and made to return counts. Three changes beyond the signature:

**(a) Delete order.** Currently `_sub`, `_group`, `_trend`, `_queue`, `_bfcursor`. Reorder to **`_queue` first**, and re-delete `_queue` as the sweeper's last statement (§1.4 step 5). `drain_queue` runs `*/5` (`db/tasks.php:29`) and `rollup_service::recompute_group()` deliberately does not gate on `course_access`, so a drain that pops a queue row mid-cleanup can write a fresh `_group` row after the cleanup deleted it. First-and-last narrows that to the width of one recompute; the §1.7 gating of `sweep_orphans` closes the larger hole of a *later* re-enqueue.

**(b) Course- and group-scoped pause windows.** `delete_for_course()` does not touch `{block_feedback_tracker_cpause}` today — a real pre-existing bug, not new work. `_cpause` keys on `(scopelevel, scopeid)` where scopeid is a courseid or a groupid (`db/install.xml:210-211`).

**Correction to the previous draft's rationale:** it claimed "Moodle recycles both [course and group ids]". That is false — `{course}.id` and `{groups}.id` are monotonic sequence values and are never reused, so no collision can occur. The two real justifications are: a course-scoped window left behind **silently re-applies** if the block is ever re-added, skewing every effective-hours figure; and a group-scoped window whose group is gone is permanently unreachable dead weight, because `pause_lookup::all_for_course()` resolves group pauses through `LEFT JOIN {groups} g ON g.id = cp.scopeid` (`classes/local/calendar/pause_lookup.php:102-107`).

Group ids must be resolved from the **plugin's own data**, not from `{groups}`: on the `course_deleted` path the course row and its groups are already gone when the observer fires (`lib/moodlelib.php:4731` before `:4741`).

```php
$groupids = $DB->get_fieldset_sql(
    'SELECT DISTINCT groupid FROM {block_feedback_tracker_group} WHERE courseid = :cid AND groupid > 0',
    ['cid' => $courseid]
);
// ... union with {block_feedback_tracker_sub}.groupid, then:
$DB->delete_records('block_feedback_tracker_cpause', ['scopelevel' => 'course', 'scopeid' => $courseid]);
if ($groupids) {
    [$gsql, $gparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'g');
    $DB->delete_records_select('block_feedback_tracker_cpause',
        "scopelevel = :lvl AND scopeid $gsql", $gparams + ['lvl' => 'group']);
}
```

**Scope note that must be said out loud, not buried.** `_cpause` rows carry `usermodified`, `reason` and `note` (`db/install.xml:213-217`) — they are hand-authored administrative configuration, not measurement, and they are declared in the privacy provider. Deleting them widens the blast radius past "measured data", and no course backup contains them. **Disputed (partially):** one reviewer wanted `_cpause` excluded from the delayed path entirely. Rejected, because `observer::course_deleted()` is the dominant caller of `delete_for_course()` and there the course is genuinely gone; splitting the method would leave the real bug half-fixed. Instead the widened scope is named explicitly in the confirmation copy (`bulkremove_confirm_pausewindows`), in the CHANGELOG, and in the `course_data_discarded` event's `pauses` count.

**(c) Cache invalidation** — the sweeper's `invalidate_caches_for_course()` (§1.4). `observer::course_deleted()` calls it too.

**Deleted (per course):** `_sub`, `_group`, `_trend`, `_queue`, `_bfcursor`, and `_cpause` rows at `scopelevel='course'` for that courseid and `scopelevel='group'` for that course's groups.

**Deliberately NOT deleted:**

| | Why |
|---|---|
| `block_feedback_tracker_site` | Site-wide daily aggregates, one row per day, no courseid — there is no per-course contribution to subtract. `recompute_site_stats` (`db/tasks.php:56`) rebuilds forward; `site_comparison` is purged so the stale figure is not served meanwhile. |
| `_cday` / `_chours` | The platform academic calendar. Site configuration, not course data. |
| `_cpause` at `scopelevel='site'` | Same. Test 12 asserts the site row survives. |
| `block_feedback_tracker_log` | The audit log. Deleting the record of a deletion is self-defeating, and the table has no `courseid` column (`db/install.xml:230-244`). Its own retention is `prune_audit_log` (90 days). |
| `{logstore_standard_log}` rows | Owned by the logstore and by its privacy provider. |
| `reconcile_cursor_*` / other `get_config` state | Site-scoped. |
| The block instance and its config | Already gone — this runs after deletion. |
| Anything in a course that still has the block | The step-4 guard. |

### 1.7 Interaction with `prune_ledger` and `reconcile_ledger` — one required change

**Correction.** The previous draft asserted "every row-*creating* sweep is filtered by `processable_course_ids()`" and that the ungated sweeps "are strict subsets of what the cleanup does". Both are false, and the second one mattered.

- **`sweep_orphans` (`classes/task/reconcile_ledger.php:346`) must be gated, and this change does it.** It accepts `array $processable` and never uses it — the SQL predicate is only `l.id > :cursor AND (cm.id IS NULL OR s.id IS NULL)` — and at `:379-381` it calls `dirty_queue::enqueue()`, which applies no gate at all (`classes/local/sla/dirty_queue.php:60-82`). Two consequences: it can re-insert a `_queue` row for a course the cleanup just emptied, and — far worse — because `remove_course_contents()` deletes the course modules at the start of a delete-mode restore, it **destroys the ledger of any course whose restore runs longer than two hours**, entirely outside the pending-removal machinery. Add `[$csql, $cparams] = $DB->get_in_or_equal($processable, SQL_PARAMS_NAMED, 'c')` and `AND l.courseid $csql` to its SQL, matching its six siblings (`:167`, `:210`, `:254`, `:301`, `:463`, `:508`). This is a behavioural change to an existing task, it is required for the grace window to be a real undo, and it goes in the CHANGELOG.
  Cost, stated: orphan rows in a course that lost the block are no longer swept by the reconciler. They do not need to be — the pending cleanup deletes them wholesale, and `course_deleted` deletes them on the other path.
- **`sweep_departed_participants` (`:400`) needs nothing.** It uses `$processable` as a per-tick course index (`:406-411`), so it only ever touches courses in the list. Effectively gated.
- **Reconciler — no resurrection.** With `sweep_orphans` gated, all seven sweeps are filtered. The moment the block leaves the course context, the course drops out of `processable_course_ids()` (`course_access.php:143-160`) and nothing can recreate a row the cleanup deleted. This is now structurally as strong as claimed — it was not before.
- **Reconciler — the expected staleness window.** Between removal and the sweep, the course is outside the processable set, so pending rows stop being re-bucketed and rule drift stops being repaired. Correct: the data is on death row.
- **Pruner — orthogonal.** `prune_ledger` deletes closed rows past `retention::cutoff()` regardless of course. It shares no state with the cleanup and simply finds fewer rows afterwards.
- **Pruner — one asymmetry for the CHANGELOG.** The pruner *never* deletes a pending measurement, by design (`classes/task/prune_ledger.php:39-41`). The removal cleanup does. Defensible precisely because the course is no longer tracked — a pending item there is nobody's outstanding task on any surface this plugin renders — but it is the single exception to "only closed rows are ever deleted" and should be said out loud rather than discovered.
- **`retention::cutoff()` is not consulted by the cleanup at all.** Retention bounds the age of history in *kept* courses; the cleanup removes all history for *removed* courses.

### 1.8 Events, audit, privacy

**Two new plugin events** — core fires none, so if the plugin does not, a mass deletion leaves no site-log trace at all.

| Class | Meaning | Shape |
|---|---|---|
| `\block_feedback_tracker\event\tracking_removed` | An administrator removed the block from a course through the bulk tool. | `crud = 'd'`, `LEVEL_OTHER`, `context = context_course::instance($courseid)`, **no `objecttable`/`objectid`** (the `block_instances` row is gone by the time we can fire, and the plugin's rule is both-or-neither), `other = ['instances' => n, 'armed' => bool, 'timedue' => t]`. |
| `\block_feedback_tracker\event\course_data_discarded` | Measured data for a course was actually destroyed. | `crud = 'd'`, `LEVEL_OTHER`, course context, no objectid, `other = ['subs' => n, 'groups' => n, 'trends' => n, 'queue' => n, 'cursors' => n, 'pauses' => n, 'source' => 'delayed'\|'immediate'\|'orphan']`. |

`tracking_removed` fires **from the bulk tool only, never from `instance_delete()`**. Firing it from the hook would emit a "tracking removed" row for every course during a delete-mode restore (immediately contradicted by the re-creation), for every course during a course deletion, and once per course during plugin uninstall. Stated gap: a teacher removing the block by hand through the course page (`lib/blocklib.php:1715`) fires nothing — core gives us no way to distinguish that from the restore path. The `_pend` row is the record for that case, and `course_data_discarded` closes it. **`course_data_discarded` must never be skipped** — it is the only record that data was destroyed.

No `db/events.php` observer is needed for either (`logstore_standard` subscribes to everything). `db/events.php` gains exactly one entry: `\core\event\course_restored` → `observer::course_restored`, alongside the existing `course_deleted` registration (`db/events.php:142-143`).

**Audit rows** via `recompute_log::record(string $reason, int $affectedrows, ?int $triggeredby, ?array $details, ?int $timestarted, ?int $timefinished)` (`classes/local/audit/recompute_log.php:64-71`). Three new constants alongside the existing eight (`:37-51`):
```php
/** Reason: a course's block was removed and a discard armed. */
public const REASON_BLOCK_REMOVED = 'block_removed';
/** Reason: a bulk block-removal batch ran. */
public const REASON_BULK_REMOVE = 'bulk_remove';
/** Reason: a course's measured data was discarded, cancelled or abandoned. */
public const REASON_COURSE_DISCARDED = 'course_discarded';
```
All three are `[a-z_]` — required, because `get_audit_log`'s return declares `reason` as `PARAM_ALPHANUMEXT` (`classes/external/get_audit_log.php:196`). `{block_feedback_tracker_log}.reason` is `char(40)`, so all three fit. `pages/audit_log.php:75` renders the reason raw, so no lang string is required; the `details` JSON renders as `k=v` pairs (`:63-70`), which is exactly right for `courseid=42, subs=118`.

**Privacy: no provider change, by construction.**
- `_pend` has no user-linked column (§1.2). The provider declares only user-linked tables (`classes/privacy/provider.php:90`, `:118`, `:123`, `:128`, `:133` → `_sub`, `_cday`, `_chours`, `_cpause`, `_log`), so `_pend` needs no `add_database_table()` entry and no request/userlist work. **Adding a `queuedby` column later would silently create a metadata + request + userlist obligation** — that is what test 21 exists to catch.
- The deletion path removes *more* personal data than GDPR requires, which is never a compliance problem.
- One honest note for the docs: a GDPR export run inside the grace window still returns the course's ledger rows, because they still exist. Correct behaviour, not a leak.

---

## 2. Bulk block-removal tool

### 2.1 Location, capability, navigation

- **Page:** `pages/bulk_remove.php`, following the plugin's hand-rolled pattern (`pages/reset.php:25-36`): `require(__DIR__ . '/../../../config.php')` (three levels — works on both the 4.5 and the 5.x split layout), `require_once($CFG->dirroot . '/blocks/feedback_tracker/lib.php')` if it calls anything there, `require_login()`, `context_system::instance()`, `require_capability()`, `$PAGE->set_pagelayout('admin')`, and `\block_feedback_tracker\event\tool_page_viewed` with `other['page' => 'bulkremove']` fired **before `$OUTPUT->header()`** and after any POST `redirect()`.
  Trade-off accepted knowingly: without `admin_externalpage_setup()` the page is absent from the admin tree and from admin search, and the explicit `require_login()` + `require_capability()` pair carries the whole gate with no backstop. That matches every page in this plugin; do not mix patterns.
  **Three enumerations must be updated together**, not just the switch: `tool_page_viewed::get_url()` gains `case 'bulkremove'` (`classes/event/tool_page_viewed.php:77-88`), the class docblock's normative list `('manage' | 'calendar' | 'audit' | 'reset')` at `:33`, and the plugin `CLAUDE.md`'s repetition of it (`CLAUDE.md:461`).

- **Capability:** new, in `db/access.php`.
  ```php
  'block/feedback_tracker:bulkremoveinstances' => [
      'captype' => 'write',
      'contextlevel' => CONTEXT_SYSTEM,
      'archetypes' => [],           // Strictly opt-in, styled on :viewalldata (db/access.php:78).
      'riskbitmask' => RISK_DATALOSS,
  ],
  ```
  Nothing existing fits. `:resetdata` (`db/access.php:118`) means "wipe every table site-wide" — a different blast radius, and reusing it would let anyone who can reset everything silently reconfigure hundreds of courses. `moodle/site:manageblocks` is declared at `CONTEXT_BLOCK` with `RISK_SPAM|RISK_XSS` (`lib/db/access.php:381-391`) — wrong level and semantically wrong risk. `RISK_CONFIG` is not needed: the lasting effect is destroyed data, not site configuration.

- **Per-course authority re-check.** Before touching a course: `has_capability('moodle/site:manageblocks', context_course::instance($courseid))`. This mirrors exactly what the interactive path enforces — `block_manager::user_can_delete_block()` (`lib/blocklib.php:1531-1536`) → `moodle_page::user_can_edit_blocks()` → `has_capability($this->_blockseditingcap, $this->_context)` with `_blockseditingcap = 'moodle/site:manageblocks'` (`lib/pagelib.php:258`, `:1074`). A failing course becomes a per-row "skipped — no permission", never a hard error. Without it, a system-level holder strips blocks from categories they have no authority over.

- **Navigation:** a link in `manage.php` gated on the new capability (alongside the three existing gated links, `manage.php:38-55`), and a fourth entry in the `links` array passed to the `tools_links` template in `settings.php:369-382` (which is ungated — only site admins reach `settings.php`). Note `$toolslinks` (`:368`) is a rendered template string, not an array; the entry goes in the array feeding it.

### 2.2 The filter form

`classes/form/bulk_remove_filter_form.php`, `method = 'get'` so the filter is bookmarkable and the confirm step can re-run it verbatim.

| Field | Element | Empty behaviour | Non-empty behaviour |
|---|---|---|---|
| **End date before** | `date_time_selector`, `['optional' => true]` | **No end-date predicate at all** — every course carrying the block is a candidate, including the ~half of a real site with `enddate = 0`. | `AND c.enddate > 0 AND c.enddate < :cutoff`. |
| **Include courses with no end date** | `advcheckbox`, default **off**, `hideIf` the date is unset | n/a | Relaxes to `AND ((c.enddate > 0 AND c.enddate < :cutoff) OR c.enddate = 0)`. |
| **Category** | `select` from `core_course_category::make_categories_list()` (`course/classes/category.php:2630`), first option "Any category" | No category predicate. | See §2.3. |
| **Include subcategories** | `advcheckbox`, default **on** | n/a | Off → plain `AND c.category = :catid` (no path work, no boundary risk). |
| **Visibility** | `select`: any (default) / visible only / hidden only | No visibility predicate. | `AND c.visible = 1` or `= 0`. |
| **Only courses with measured data** | `advcheckbox`, default off | No predicate. | `AND EXISTS (SELECT 1 FROM {block_feedback_tracker_sub} s WHERE s.courseid = c.id)`. |
| **Course name contains** | `text` | No predicate. | `AND (` `$DB->sql_like('c.fullname', ':n1', false)` `OR` `$DB->sql_like('c.shortname', ':n2', false)` `)`, value `'%' . $DB->sql_like_escape($t) . '%'`. |

**The end-date checkbox is the single most dangerous control on the page and gets its own confirmed tick.** `{course}.enddate` is `NOTNULL="true" DEFAULT="0"` on both branches (`lib/db/install.xml:84`; `405:lib/db/install.xml:84`), the form element is `optional => true` (`course/edit_form.php:172`), and core reads guard with `!empty()`. **`WHERE enddate < :cutoff` is true for every course with `enddate = 0`, i.e. for the whole site.** `course_validate_dates()` never requires an end date (`course/lib.php:3434-3444`); `moodlecourse/courseenddateenabled` is a default, not an enforcement (`admin/settings/courses.php:184-185`).

Two things the help text must say, because they are not intuitions an admin will arrive at:

1. **"Ended" is an administrative filter, not a statement that the course is over.** Core's own help string is explicit that the end date does not restrict student access (`lang/en/moodle.php:790`); the stronger wording appears only when `\core\task\hide_ended_courses_task` is enabled (`lang/en/moodle.php:792`, swapped in at `course/edit_form.php:167-173`), and that task ships **disabled** (`lib/db/tasks.php:442`, `'disabled' => true` at `:449`). Teachers may still be grading.
2. **This filter is enddate-only.** Core's "Past" classification is `course_classify_for_timeline()` (`course/lib.php:3531`), which also shifts by `$CFG->coursegraceperiodafter` (`course_classify_end_date()`, `:3577-3580`) and ORs in per-user completion. There is no SQL classifier in core — `core_course_external` filters in PHP. Reimplementing half of it would make the tool's "past" and the dashboard's "Past" disagree by up to the grace period. Say "end date before X", not "past courses".

**Deliberately not a filter: "last measured activity before".** It forces `MAX(timegraded)` over the ledger for every candidate row. It appears instead as a **column on the confirmation page only**, where the aggregate is bounded by the selection.

### 2.3 The candidate query

`classes/local/sla/removal_candidates.php`. Built directly from `{block_instances}` — **never** from `course_access::processable_course_ids()`, which ANDs `c.visible = 1` (`course_access.php:153`) and would hide precisely the ended-and-hidden courses the tool targets (`hide_ended_courses_task` sets `visible = 0` on the end date).

```php
$where = ['bi.blockname = :blockname', 'c.id <> :siteid'];
$params = ['blockname' => 'feedback_tracker', 'ctxlevel' => CONTEXT_COURSE, 'siteid' => SITEID];

if ($cutoff > 0) {
    $where[] = $includenoenddate
        ? '((c.enddate > 0 AND c.enddate < :cutoff) OR c.enddate = 0)'
        : '(c.enddate > 0 AND c.enddate < :cutoff)';
    $params['cutoff'] = $cutoff;
}

if ($catid > 0 && $subcats) {
    $catctx = \context_coursecat::instance($catid);
    $where[] = $DB->sql_like('ctx.path', ':catpath');
    // TRAILING SLASH IS THE WHOLE POINT: '/1/3/%' matches /1/3/17 and never /1/30/...
    // No "OR c.category = :catid" term is needed — a course sitting directly in the
    // category has context path <catctxpath>/<coursectxid>, so the prefix covers
    // the category's own courses and every descendant.
    $params['catpath'] = $DB->sql_like_escape($catctx->path) . '/%';
} else if ($catid > 0) {
    $where[] = 'c.category = :catid';
    $params['catid'] = $catid;
}
// ... visibility, withdataonly (EXISTS), namelike ...

$sql = "SELECT c.id, c.fullname, c.shortname, c.enddate, c.visible, cc.name AS categoryname,
               COUNT(bi.id) AS instancecount
          FROM {block_instances} bi
          JOIN {context} ctx ON ctx.id = bi.parentcontextid AND ctx.contextlevel = :ctxlevel
          JOIN {course} c ON c.id = ctx.instanceid
          JOIN {course_categories} cc ON cc.id = c.category
         WHERE " . implode(' AND ', $where) . "
      GROUP BY c.id, c.fullname, c.shortname, c.enddate, c.visible, cc.name
      ORDER BY c.enddate ASC, c.shortname ASC";

$rows = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);
```

Cross-DB and correctness notes, each a real failure if dropped:

- **`{context}.path`, not `{course_categories}.path`.** Only the former is indexed, and specifically for prefix `LIKE`: `<INDEX NAME="path" UNIQUE="false" FIELDS="path" HINTS="varchar_pattern_ops"/>` (`lib/db/install.xml:1216`; `405:` at `:1213`). `{course_categories}` declares keys only. Core enumerates courses under a category exactly this way: `'WHERE ctx.path like :pathmask and ctx.contextlevel = :courselevel'` with `'pathmask' => $context->path . '/%'` (`course/classes/category.php:1986-1987`).
- **The trailing slash is the boundary.** `LIKE '/1/3%'` matches `/1/30/...`; `LIKE '/1/3/%'` does not. Without it, scoping a cleanup to one department wipes nine others. *(Correction: the previous draft cited `course/management.php:324` as the precedent — that line is a PHP `strpos($movetocat->path, $cattomove->path . '/') === 0` check on `{course_categories}.path`, not a SQL prefix `LIKE`. The SQL precedent is `category.php:1986-1987`.)*
- **Build the `LIKE` with `$DB->sql_like()`** (`lib/dml/moodle_database.php:2291-2298`) and escape the *value* with `sql_like_escape()` (`:2306`). `sql_like()` emits `debugging()` when its `$param` argument contains `%` (`:2292-2294`) — a warning, and warnings fail this build — so the `%` goes in the bound value, never in the fragment.
- **Drop the path clause entirely for "any category"** rather than passing `'/%'`.
- **`c.id <> SITEID`** always. The front page is a course row and can carry blocks.
- **`GROUP BY` lists every selected non-aggregate column** — PostgreSQL rejects anything less, and CI runs both drivers.
- **The count query repeats the identical `WHERE`**: `SELECT COUNT(DISTINCT c.id) FROM ... WHERE ...`. `c.enddate` is not indexed, so the `block_instances`/`context` join must lead — it does.
- **`get_records_sql()` returns strings.** Cast `id`/`enddate`/`visible`/`instancecount` to `(int)` before `userdate()` or comparison.
- `core_course_category::get_courses()` cannot serve this: it is MUC-cached with a fixed option set and no date or block predicate (`course/classes/category.php:1809-1830`), and `get_courses_count()` counts the full id array.

A separate bounded query supplies the ledger counts for the **selected** ids only:
```sql
SELECT courseid, COUNT(1) AS ledgerrows, MAX(timegraded) AS lastactivity
  FROM {block_feedback_tracker_sub}
 WHERE courseid <sql_from_get_in_or_equal>
GROUP BY courseid
```
**`AS ledgerrows`, never `AS rows`.** `ROWS` is reserved in MySQL 8 / MariaDB, and this plugin already carries the scar from exactly this class of bug: `{block_feedback_tracker_bfcursor}.lastsubid` is documented as "Named lastsubid rather than cursor because CURSOR is reserved in MariaDB and INSERT/UPDATE fail without quoting" (`db/install.xml:251`, repeated in the file docblock at `:2`). `ledgerrows` is also already the name the template and the lang key use.

### 2.4 Selection, confirmation, execution

Three stages, combining `admin/user/user_bulk_delete.php`'s confirm interstitial with `course/management.php`'s checkbox-array + per-item re-validation discipline — and copying neither's weaknesses (`user_bulk*.php` carries selection in `$SESSION->bulk_users` with a standing note about not scaling; `course/management.php` has no confirm step).

**Stage 1 — list.** Paged table (`$OUTPUT->paging_bar(...)`, exactly as `pages/audit_log.php:103`), one `<input type="checkbox" name="courseids[]" value="{{id}}">` per row, read back with `optional_param_array('courseids', [], PARAM_INT)` (the pattern at `course/management.php:293`). Columns: select, course (name + shortname), category, end date (or the `bulkremove_noenddate` string when 0), visible, instances, ledger rows.
Select-all uses **core's own AMD module** — `$PAGE->requires->js_call_amd('core/checkbox-toggleall', 'init')` with `data-togglegroup` / `data-toggle` attributes. Present on both `lib/amd/src/checkbox-toggleall.js` and `405:lib/amd/src/checkbox-toggleall.js`. **No new plugin JS, therefore no `amd/build` rebuild and no `mdl grunt` step in this change.**
The toggle's label is `bulkremove_selectall` = "Select all N rows on this page" — **on this page, never "all matching"**. State it; the two readings differ and admins assume the dangerous one.

**Stage 2 — confirm** (POST, `confirm=0`). **Re-run the candidate query and intersect with the posted ids.** Anything that no longer matches is dropped and listed by name (`bulkremove_confirm_dropped`); without it a hand-crafted POST removes blocks from arbitrary courses that never matched the approved filter. The confirmation states:

- how many **courses** lose the block, and how many **block instances** will be deleted (the second can exceed the first — §1.5 row 7);
- the **total `{block_feedback_tracker_sub}` rows that will eventually be discarded**, plus a per-course breakdown and each course's last measured activity;
- **that any course- or group-scoped SLA pause windows are discarded too** — hand-authored configuration, in no backup;
- **the deadline**: "measured data will be discarded after &lt;grace&gt; — about &lt;userdate&gt; — unless the block is re-added before then";
- **or, when `removal_cleanup_active` is off**: "the block will be removed; the measured data will be left in place, because delayed cleanup is switched off." Without this line the admin believes they cleaned up and did not;
- **that the plugin's tables are not in course backups**, so the two recoveries are the pending-list Cancel and re-adding the block (including by restoring a pre-removal course backup in *adding* mode, which restores the `block_instances` row).

**Stage 3 — execute** (POST, `confirm=1`, `require_sesskey()`). Per course, in its own `try/catch`:

```php
$coursectx = \context_course::instance($courseid, IGNORE_MISSING);
if (!$coursectx) { $failed[] = ...; continue; }
if (!has_capability('moodle/site:manageblocks', $coursectx)) { $skipped[] = ...; continue; }

$instanceids = $DB->get_fieldset_select('block_instances', 'id',
    'blockname = :b AND parentcontextid = :p',
    ['b' => 'feedback_tracker', 'p' => $coursectx->id]);
if (!$instanceids) { $skipped[] = ...; continue; }

blocks_delete_instances($instanceids);   // lib/blocklib.php:2574 — chunks at 1000,
                                         // still runs instance_delete() per instance (:2588),
                                         // so arming happens through the ONE code path.
$passed[] = ...;
```

- **Never delete `{block_instances}` rows directly.** That skips `instance_delete()` (nothing gets armed) and leaks a `CONTEXT_BLOCK` row with its role assignments — `blocks_delete_instance()` calls `context_helper::delete_instance(CONTEXT_BLOCK, …)` at `lib/blocklib.php:2560`.
- **`blocks_delete_instances()` (plural), not a loop over the singular.** It chunks and batches the three table deletes (`:2592-2593`).
- **No transaction across the batch.** `my/lib.php:279` wraps its bulk reset in a delegated transaction, but here a mid-batch failure should keep the successes, and holding `{context}` locks across N courses is worse than a partial result. Partial failure is reported, not rolled back.
- Results render as three lists — removed / skipped (no permission, or no instance) / failed (with the exception message) — the `$notificationspass` / `$notificationsfail` shape at `course/management.php:165-166`, rendered `:481-486`.
- Fire `tracking_removed` per successful course; write one `REASON_BULK_REMOVE` audit row for the batch with `affectedrows` = instances deleted and `details = ['courses' => n, 'skipped' => n, 'failed' => n, 'discardnow' => bool]`.
- The **pending-removals panel** renders under the results on the same page: courseid, queued, due, reason, and per-row **Cancel** / **Discard now** actions (both sesskey-guarded). An admin who just removed 40 courses lands back on a page showing the 40 armed rows.
  Honest limitation to document: with `removal_orphan_scan` **on**, Cancel is temporary — the next scan re-arms the course within 15 minutes. A per-course suppression list would fix that and is **deliberately out of scope**: it is a third feature with its own lifecycle, and the orphan scan is off by default.

### 2.5 There is no core precedent to copy for the removal itself

Worth knowing before someone goes looking: `admin/blocks.php` handles only enable/disable (`:40-63`) and protect/unprotect (`:65-87`) in 5.1 — the legacy "delete all instances of this block type" action is gone, and the only bulk removal of a block type is uninstall. `blocks_remove_inappropriate()` is dead code (`lib/blocklib.php:2482-2484`, body commented out). The dashboard bulk paths never apply here — `applicable_formats()` sets `'my' => false` (`block_feedback_tracker.php:160-167`). This tool drives `blocks_delete_instances()` itself, which is correct; the capability check, the confirmation and the sesskey are its responsibility, not the function's (the interactive UI does exactly the same at `lib/blocklib.php:1713-1715`).

### 2.6 "Remove block only" vs "remove and discard now"

**Recommendation: two separate, separately-labelled, separately-confirmed submit buttons. "Remove block only" is the default and the primary; "Remove block and discard data now" is secondary and requires a second explicit confirmation tick on the confirm page.**

Why both:

- "Remove block only" is the reversible act — the grace window is the undo.
- "Discard now" has to exist because the grace has a 30-minute floor (§1.3) and the genuine end-of-life case ("purge this department's 2019 archive") should not require waiting an hour and then verifying that it happened.

Why separate rather than one action plus a checkbox: a checkbox on the primary path gets ticked by muscle memory. Two buttons force the admin to aim.

Why *not* a third "discard data, keep the block" option: with the block still present the course stays in `processable_course_ids()`, so `backfill_history` and `reconcile_ledger::sweep_missing_rows()` refill the ledger within hours. It would read as a bug.

**Implementation has exactly one deletion code path**: remove the blocks as above (which arms rows through `instance_delete()`), then immediately call `pending_removal::execute_row()` for those courseids with **both** the step-3 restore guard and the step-4 block-presence re-check still applied. A course with a restore in flight is **refused with a visible per-row message**, not silently deferred — this is a foreground act and the admin must see it. `source = 'immediate'` in the event and the audit row. Never a second `delete_for_course()` call site.

---

## 3. Files to touch

### Create (16)

| Path | Notes |
|---|---|
| `classes/local/sla/pending_removal.php` | `arm_from_instance()`, `arm()`, `cancel()`, `restamp()`, `due()`, `execute_row()`, `abandon_stale()`, `resume_after_outage()`, `scan_orphans()`, `grace_seconds()`, `enabled_since()`, `restore_in_flight()`, `invalidate_caches_for_course()`, constants. `declare(strict_types=1)`; **no `MOODLE_INTERNAL` guard** (pure namespaced class, matching `course_access.php`). |
| `classes/local/sla/removal_candidates.php` | Query builder + `count()` + `ledger_counts()`. `declare(strict_types=1)`, no guard. |
| `classes/task/cleanup_removed_courses.php` | Scheduled task. `declare(strict_types=1)`, no guard. |
| `classes/event/course_data_discarded.php` | `declare(strict_types=1)`, no guard (matches `tool_page_viewed.php:25`). |
| `classes/event/tracking_removed.php` | Same. |
| `classes/form/bulk_remove_filter_form.php` | `declare(strict_types=1)` **before** `namespace`, then `defined('MOODLE_INTERNAL') || die();` + `global $CFG; require_once($CFG->libdir . '/formslib.php');` — the exact shape of `reset_form.php:25-32`. |
| `pages/bulk_remove.php` | Entry point; no guard (matches `pages/reset.php`). |
| `cli/pending_removals.php` | `--list` / `--cancel=<courseid>` / `--discard=<courseid>` / `--discard-all-due`. **Required by house pattern:** every destructive admin operation here has a CLI companion (`cli/reset.php` is the direct precedent, alongside `recompute_all.php`, `recompute_one.php`, `backfill_course.php`, `backfill_trends.php`). A delayed-discard queue with no CLI to inspect or cancel it breaks that pattern on the one feature where an admin most needs a way in without a browser. |
| `templates/bulk_remove.mustache` | Mandatory `Example context (json):` with **non-empty** `rows`/`cols`/`pending` arrays, or the lint's HTML validation rejects the empty `<tr></tr>`. No `{{…}}` inside the `{{! }}` docblock. |
| `templates/bulk_remove_confirm.mustache` | Same. |
| `tests/local/sla/pending_removal_test.php` | §4 |
| `tests/local/sla/removal_candidates_test.php` | §4 |
| `tests/task/cleanup_removed_courses_test.php` | §4 |
| `tests/event/course_data_discarded_test.php` | House pattern — every custom event has one (`tests/event/report_viewed_test.php`, `tests/event/tool_page_viewed_test.php`). |
| `tests/event/tracking_removed_test.php` | Same. |
| `tests/behat/bulk_remove.feature` | §4 |

### Modify (26)

| Path | Change |
|---|---|
| `block_feedback_tracker.php` | Add `before_delete()`, `instance_delete()`, `instance_create()`, the **static** `$uninstalling` (needs `/** @var bool … */`). No `lib.php` dependency. |
| `classes/local/sla/course_access.php` | `block_present_for_course()` `private` → `public` (`:109`), docblock updated to name the cleanup as a caller. |
| `classes/local/sla/submission_ledger.php` | `delete_for_course()` (`:1116-1124`): `_queue` first, add `_cpause` course + group scopes, cache invalidation, return `array{subs:int,groups:int,trends:int,queue:int,cursors:int,pauses:int}`, docblock. |
| `classes/local/sla/observer.php` | `course_deleted()` (`:452-458`) adds `pending_removal::cancel()`; new `course_restored()` that re-checks block presence and cancels only when present. |
| `classes/task/reconcile_ledger.php` | **`sweep_orphans()` (`:346`) gains the `$processable` filter its six siblings already carry.** Required for correctness (§1.7), not cosmetic. Docblock updated. |
| `classes/local/audit/recompute_log.php` | Three new `REASON_*` constants with docblocks (after `:51`). |
| `classes/event/tool_page_viewed.php` | `case 'bulkremove'` in `get_url()` (`:77-88`) **and** the normative page list in the class docblock (`:33`). |
| `db/install.xml` | New `_pend` table; bump the file's `VERSION` attribute (`:2`, currently `20260802` → `20260804`) and extend its summary comment. Validate: `xmllint --noout --schema ~/dev/moodle-501/public/lib/xmldb/xmldb.xsd db/install.xml`. Every `<FIELD>` needs an explicit `SEQUENCE`. |
| `db/upgrade.php` | `if ($oldversion < 2026080400)` → `$dbman->table_exists()` guard → `create_table()` → `upgrade_block_savepoint(true, 2026080400, 'feedback_tracker')`. |
| `db/tasks.php` | Register `cleanup_removed_courses`, `minute => '*/15'`, `blocking => 0`, with a `/* … */` block comment in the file's house style. |
| `db/events.php` | One entry: `\core\event\course_restored` → `observer::course_restored`. |
| `db/access.php` | `block/feedback_tracker:bulkremoveinstances`. |
| `db/install.php` | Seed `removal_cleanup_active=0`, `removal_grace_hours=1`, `removal_orphan_scan=0`, `removal_batch_size=200` in `$defaults` (`:43`). |
| `settings.php` | New "Removal and cleanup" heading + 3 checkboxes/text after the Processing-scope block (`:259-264`) and before the Performance heading (`:267`); `removal_batch_size` into `$perf` (`:273-284`); `set_updatedcallback('block_feedback_tracker_removal_cleanup_toggled')` on `removal_cleanup_active` only; fourth entry in the `links` array at `:369-382`. |
| `lib.php` | `block_feedback_tracker_removal_cleanup_toggled()` with the `block_feedback_tracker_is_bootstrapping()` short-circuit. |
| `manage.php` | Capability-gated link (mirrors `:50-55`). |
| `lang/en/block_feedback_tracker.php` | See list below. |
| `lang/pt_br/block_feedback_tracker.php` | **Every key, same commit, same alphabetic slots.** |
| `version.php` | `$plugin->version = 2026080400` (must exceed `2026080302`; if the commit lands today use `2026080303` and match the savepoint and `install.xml` VERSION), `$plugin->release = '1.0.40'`. `$plugin->supported` unchanged → `ci.yml` and README compatibility unchanged. |
| `CHANGELOG.md` | New `## [1.0.40]`. Must state: the master switch is off by default and why; **the `sweep_orphans` gating change and the restore-erosion bug it fixes**; that this is the one path that deletes *pending* measurements and why that is defensible; that course- and group-scoped pause windows are now discarded with a course (bug fix plus widened blast radius); the 30-minute floor and the restore reasoning. |
| `tests/behat/behat_block_feedback_tracker.php` | `case 'Bulk removal':` in `resolve_page_url()` (`:48-64`). |
| `tests/db/access_test.php` | Cover the new capability. |
| `tests/event/tool_page_viewed_test.php` | `bulkremove` case in the slug→URL assertions (`:66`, `:85`). |
| `tests/local/sla/submission_ledger_test.php` | `test_delete_for_course_drops_everything()` (`:132-143`) currently exercises the five-table, `void`-returning behaviour — update for the counts return, and host the `_cpause` assertions here where `@covers \…\submission_ledger` (`:35`) actually applies. |
| `tests/local/sla/course_access_test.php` | `block_present_for_course()` going public. |
| `tests/generator/lib.php` | Optional `create_pending_removal(array $overrides)` helper. |
| `CLAUDE.md` | New subsection under "Processing scope (course_access gate)": the gate now has an exit as well as an entry; `block_present_for_course()` is the deletion guard and `is_processable()` must never be; `instance_delete()` ordering vs `blocks_delete_instances()` vs `blocks_delete_all_for_context()`; `sweep_orphans` is now gated. Update the `tool_page_viewed` page enumeration at `:461`. **No to-do/fix-me/merge-marker tokens — the CI leftover checker scans this file.** |
| `README.md` | Data-model note: `_pend` and the removal lifecycle. |
| `docs/future-features.md` | Move/close the per-course orphan-scan suppression list, explicitly deferred in §2.4. |

**Deliberately not touched:** `db/services.php` (no web service — a destructive bulk action stays off AJAX, and this avoids a services install), `amd/**` and `js/**` (reuses `core/checkbox-toggleall`; **no grunt rebuild in this change**), `db/caches.php` (no new definition; the existing `pause_windows_by_course` and `site_comparison` are invalidated, not redefined), `classes/privacy/provider.php` (see §1.8), `.github/workflows/ci.yml`.

### Lang keys (both files, alphabetic slots verified against `lang/en/`)

- **Between `breakdown_weight` (en:71) and `cachedef_calendar_effective_day` (en:72):** the whole `bulkremove_*` block — `bulkremove_title`, `_intro`, `_filter_heading`, `_filter_enddate`, `_filter_enddate_help`, `_filter_includenoenddate`, `_filter_includenoenddate_help`, `_filter_category`, `_filter_category_any`, `_filter_subcategories`, `_filter_visibility`, `_filter_visibility_any`, `_filter_visibility_visible`, `_filter_visibility_hidden`, `_filter_withdataonly`, `_filter_namelike`, `_filter_submit`, `_col_select`, `_col_course`, `_col_category`, `_col_enddate`, `_col_visible`, `_col_instances`, `_col_ledgerrows`, `_col_lastactivity`, `_noenddate`, `_empty`, `_selectall`, `_nothingselected`, `_action_removeonly`, `_action_removeanddiscard`, `_confirm_heading`, `_confirm_summary`, `_confirm_deadline`, `_confirm_cleanupoff`, `_confirm_nobackup`, `_confirm_pausewindows`, `_confirm_discardnow_warning`, `_confirm_dropped`, `_confirm_yes`, `_confirm_no`, `_result_heading`, `_result_removed`, `_result_skipped_nopermission`, `_result_skipped_noinstance`, `_result_skipped_restoring`, `_result_failed`, `_pending_heading`, `_pending_empty`, `_pending_col_course`, `_pending_col_queued`, `_pending_col_due`, `_pending_col_reason`, `_pending_cancel`, `_pending_discardnow`, `_pending_cancelled`, `_reason_manual`, `_reason_bulk`, `_reason_orphan` — in their own alphabetic order within the run.
  *(Correction: the previous draft said "after `breakdown_value`, before `caleditor_*`", which would place the block after the five `cachedef_*` keys and fail `moodle.Files.LangFilesOrdering`.)*
- **Between `event_cal_pause_updated` (en:209) and `event_report_viewed` (en:210):** `event_course_data_discarded`.
- **After `event_tool_page_viewed` (en:211):** `event_tracking_removed`.
- **Between `feedback_tracker:addinstance` (en:212) and `feedback_tracker:managecalendar` (en:213):** `feedback_tracker:bulkremoveinstances`.
- **Between `manage_link_audit` (en:264) and `manage_link_calendar` (en:265):** `manage_link_bulkremove`.
- **Between `settings_release_stops_clock_desc` (en:462) and `settings_retention_active` (en:463):** `settings_removal_batch_size`(+`_desc`), `settings_removal_cleanup_active`(+`_desc`), `settings_removal_desc`, `settings_removal_grace_hours`(+`_desc`), `settings_removal_heading`, `settings_removal_orphan_scan`(+`_desc`) — in that alphabetic order.
- **Between `task_backfill_one_submission` (en:548) and `task_drain_queue` (en:549):** `task_cleanup_removed_courses`.

The three `bulkremove_reason_*` strings resolve through a **literal `switch`** — no `get_string('bulkremove_reason_' . $r, …)`.

---

## 4. Tests

Each entry names the assertion that goes red if the guard is removed.

### PHPUnit — `tests/local/sla/pending_removal_test.php`
`@covers \block_feedback_tracker\local\sla\pending_removal` + `\block_feedback_tracker\task\cleanup_removed_courses`. `resetAfterTest()` everywhere; `ob_start()` in `setUp()` / `ob_end_clean()` in `tearDown()` to swallow `mtrace()` — the pattern at `tests/task/prune_ledger_test.php:53-67`, which PHPUnit 11 otherwise flags as risky. `course_access::reset_memo()` after any block change.

1. `test_removing_the_block_arms_a_pending_row` — `blocks_delete_instance()` on the course's instance. → `assertSame(time() + pending_removal::grace_seconds(), (int) $row->timedue)`. **Red if `instance_delete()` is not wired.**
2. `test_arming_does_not_fatal_without_lib_php` — assert `function_exists('block_feedback_tracker_is_bootstrapping')` is **false** at the point of arming in a fresh request, then delete the block. → no error, row armed. **Red the day someone "reuses the existing helper" from `instance_delete()`** — the single fastest way to break every block deletion on the site.
3. `test_a_sibling_instance_stops_the_sweep_but_not_the_arming` — two instances on the course context, delete one, run the task past the due time. → ledger intact and `assertFalse($DB->record_exists('block_feedback_tracker_pend', ['courseid' => $c->id]))`. **Red if the run-time `record_exists` guard is removed.**
4. `test_the_sweeper_refuses_when_the_block_came_back` — arm, add a new instance, sweep. Ledger intact. **Red if the guard is removed** — the restore-with-delete case in miniature.
5. `test_a_hidden_course_that_still_has_the_block_is_never_swept` — `visible = 0`, `process_hidden_courses` off, block present, pending row armed by hand and due. → `assertGreaterThan(0, $DB->count_records('block_feedback_tracker_sub', ['courseid' => $c->id]))`. **Red the moment anyone "simplifies" the guard to `course_access::is_processable()`.** Highest-value test in the change.
6. `test_a_restore_in_flight_defers_the_sweep` — insert a `{backup_controllers}` row (`operation = 'restore'`, `itemid = $courseid`, `status = backup::STATUS_EXECUTING`, `timecreated = time()`), arm a due row, sweep. → ledger intact **and** the pending row re-stamped forward. **Red without the step-3 guard** — the only test covering a restore that outlives the grace window.
7. `test_a_long_dead_restore_controller_does_not_block_for_ever` — same fixture with `timecreated = time() - 3 * DAYSECS`. → the sweep proceeds. **Red if the recency bound is dropped**, which would make a single crashed restore permanently un-cleanable.
8. `test_the_grace_floor_and_ceiling_hold` — `set_config('removal_grace_hours', '0')` then `'100000'`. → both fall back to `DEFAULT_GRACE_HOURS`. **Red if either clamp is dropped.**
9. `test_a_row_that_is_not_due_yet_is_left_alone` — sweep before `timedue`. → row present, ledger intact.
10. `test_enabling_cleanup_does_not_fire_backlogged_rows` — arm with `removal_cleanup_active = 0`, advance past `timedue`, set the config to 1 (firing the callback), sweep. → ledger intact and the row still pending. **Red without `enabledsince`** — the "flip the switch, lose the backlog" bug.
11. `test_a_cron_gap_re_stamps_instead_of_firing` — arm a due row, simulate a last-run time older than `2 * grace`, sweep. → ledger intact, `timedue` moved forward. **Red if the outage-resume is dropped.**
12. `test_an_abandoned_row_drops_without_deleting` — `timedue` older than `MAX_PENDING_DAYS`. → `assertFalse(record_exists('..._pend'))` **and** `assertGreaterThan(0, count_records('..._sub'))`. **Red if the staleness ceiling is removed.**
13. `test_abandonment_runs_with_cleanup_switched_off` — same fixture, `removal_cleanup_active = 0`. → the row is still reaped. **Red if the abandon pass sits behind the master switch**, which would let `_pend` grow unbounded.
14. `test_course_deletion_cancels_the_pending_row` — arm, `delete_course($c)`, assert *before* any sweep. → pending row gone. **Red if `observer::course_deleted()` does not cancel.**
15. `test_the_sweeper_survives_a_deleted_course` — arm, delete the course row directly (no event), sweep. → no exception, row gone. **Red if step 2 resolves the context before checking the course row** (`dml_missing_record_exception`).
16. `test_uninstall_does_not_arm` — `$block->before_delete()` on one object, then `blocks_delete_instance()`. → pending table empty. **Red if `$uninstalling` is removed, or made non-static.**
17. `test_arming_does_not_break_an_enclosing_transaction` — open a delegated transaction, delete two instances on the same course, commit. → no exception, exactly one `_pend` row. **Red if the upsert is implemented as a caught unique violation** (poisons the transaction on PostgreSQL).
18. `test_re_adding_the_block_cancels_immediately` — arm, then `$page->blocks->add_block(...)`. → pending table empty. (Optimisation-level; test 4 is the safety net.)
19. `test_cleanup_is_off_by_default` — arm a due row, sweep with no config. → ledger intact. **Red if the default flips to 1.**

### PHPUnit — `tests/local/sla/submission_ledger_test.php` (existing file)

20. Update `test_delete_for_course_drops_everything()` (`:132`) for the counts return value.
21. `test_delete_for_course_removes_course_and_group_pause_windows` — site + course + group pause rows. → the course and group rows gone, **the site row still present**. **Red if the `_cpause` extension is dropped, and red in the other direction if the site scope is caught by mistake.** Lives here, where `@covers \…\submission_ledger` (`:35`) actually applies.
22. `test_delete_for_course_invalidates_the_pause_cache` — prime `pause_lookup` for the course, delete, re-read. → the deleted window is gone from the returned set. **Red without the targeted `cache->delete()` + `reset_memo()`.**

### PHPUnit — `tests/local/sla/removal_candidates_test.php`

23. `test_courses_with_no_end_date_are_excluded_when_a_cutoff_is_set` — one course `enddate = 0`, one `enddate = time() - YEARSECS`, both with the block; filter with a cutoff of today. → `assertSame([(int) $ended->id], $ids)`. **Red if `AND c.enddate > 0` is dropped — the single most likely destructive bug in the tool.**
24. `test_the_no_end_date_opt_in_includes_them` — same fixture, checkbox on. → both ids.
25. `test_the_category_predicate_carries_the_trailing_slash` — white-box: assert the built param equals `\context_coursecat::instance($cat->id)->path . '/%'`. Deterministic, and **red on exactly the `/1/3` vs `/1/30` bug** without depending on generated id values.
26. `test_the_category_filter_covers_the_subtree_and_nothing_else` — behavioural: parent category with a child, plus an unrelated sibling category, one course with the block in each. → `assertEqualsCanonicalizing([$parentcourse->id, $childcourse->id], $ids)`; `assertNotContains($siblingcourse->id, $ids)`.
27. `test_hidden_courses_are_candidates` — hidden course with the block, `process_hidden_courses` off. → present. **Red if anyone reuses `processable_course_ids()`.**
28. `test_the_front_page_is_never_a_candidate` — block on the `SITEID` course context. → absent.
29. `test_two_instances_yield_one_row_with_instancecount_two` — `assertSame(2, (int) $row->instancecount)`.
30. `test_ledger_counts_run_on_both_drivers` — call `ledger_counts()` for two courses and read `$row->ledgerrows`. **Red if the alias is `rows`** (reserved in MariaDB; the plugin already carries this scar at `db/install.xml:251`).
31. `test_the_pending_table_carries_no_user_column` — `foreach (array_keys($DB->get_columns('block_feedback_tracker_pend')) as $col) { assertStringNotContainsString('user', $col); }`. **Red the day someone adds `queuedby`, which is the day the privacy provider acquires a metadata + request + userlist obligation.**

### PHPUnit — `tests/task/cleanup_removed_courses_test.php`

32. `test_the_orphan_scan_is_off_by_default` — ledger rows for a course with no block, sweep. → nothing armed, nothing deleted.
33. `test_the_orphan_scan_arms_and_never_deletes` — enable, sweep once. → `assertTrue(record_exists('..._pend'))` **and** `assertGreaterThan(0, count_records('..._sub'))`. **Red if the orphan scan deletes directly** — the scariest possible implementation slip, because it bypasses the grace window entirely.
34. `test_the_batch_ceiling_is_respected` — more due rows than `removal_batch_size`. → exactly `$batch` processed.

### PHPUnit — `tests/task/reconcile_ledger_test.php` (existing file)

35. `test_sweep_orphans_ignores_courses_without_the_block` — ledger rows whose `cm` is gone, in a course with **no** block instance, run `reconcile_ledger`. → rows still present. **Red without the §1.7 gating** — and this is the test that stands between a two-hour restore and total loss of a course's history.

### Behat — `tests/behat/bulk_remove.feature`

Thin smoke only; no JS select-all scenario (plain checkboxes are enough — `core/checkbox-toggleall` is core's problem). Background mirrors `admin_pages.feature`, including the explicit `permission overrides` table, since archetype defaults propagate unevenly in Behat.

- *A manager reaches the bulk-removal page* — `I am on the "block_feedback_tracker > Bulk removal" page`; `I should see "Feedback Flow"`.
- *An editing teacher without the capability is refused* — `I should see "Sorry, but you do not currently have permissions to do that"`. **Red if `require_capability()` is dropped from the page.**
- *Selecting a course reaches a confirmation that names it and states the deadline* — press "Remove block only"; `I should see "Course A"` and the deadline string. **Red if the confirm interstitial is skipped.**
- *Cancelling leaves the block in place* — after Cancel, the course is still listed as a candidate.
- *Confirming removes the block* — after Confirm, the course appears in the removed list and is no longer a candidate.

Scope Mink lookups to a `"fieldset"` — labels match by substring, and "End date" would also match "Include courses with no end date".

**Run before pushing:** `mdl phpunit-init m501` first (the new table changes the versions hash), then `mdl ci moodle-block_feedback_tracker --only phpcs,phpdoc`, `mdl phpunit m501 block_feedback_tracker`, `mdl ci moodle-block_feedback_tracker --branch MOODLE_405_STABLE` (`supported` starts at 405 and the adhoc-API divergence checked above is 4.5-sensitive), `mdl ci moodle-block_feedback_tracker --db mariadb` (this change is SQL-heavy, reserved-word-sensitive, and CI only runs MariaDB on the highest branch), then `mdl behat m501 @block_feedback_tracker`.

---

## 5. The four things most likely to ship broken, and what makes each silent

**1. `enddate = 0` matching every course on the site.**
`{course}.enddate` is `NOTNULL DEFAULT 0` and 0 means "no end date" (`lib/db/install.xml:84`; `course/edit_form.php:172` makes the field optional). A filter written as `WHERE enddate < :cutoff` — the obvious way to write it — is **true for every course that never set one**, which on a real site is a large fraction and includes everything created before `courseenddateenabled` was on.
**Why it is silent:** the tool renders a list of real courses with real names. Nothing looks wrong. The admin ticked "select all on this page" on a filter they believe says "ended before January", pressed Remove, and got a plausible-looking confirmation. The block disappears from courses that are running right now, and the data disappears an hour later — by which time the removal and the deletion are separate events in separate places. Only test 23 catches it before release.

**2. The run-time guard drifting to `course_access::is_processable()`.**
It reads like the right call — it is *the* documented decision point, and the plugin's own `CLAUDE.md` ("don't reimplement the check") actively pushes a reviewer toward it. But it ANDs course visibility (`course_access.php:83-86`, `:153`), so for a **hidden course that still carries the block** it returns false, the guard passes, and the sweeper deletes the data of a course that is still tracked.
**Why it is silent:** every test in which the course is visible passes. Every manual check passes. The failure needs a course that is *both* hidden *and* still has the block — which is the normal end-of-term state, and disproportionately the population this whole feature targets. The trigger is an unrelated admin action (hiding a course, or `hide_ended_courses_task` firing on the end date), so nobody connects cause to effect. The data is unrecoverable — the plugin has no `backup/` directory, so it was never in any course backup. Only test 5 catches it.

**3. A delete-mode restore that outlives the grace window — and the reconciler that beats it there.**
`restore_controller::execute_plan()` calls `delete_course_content()` **before** the plan runs, for `TARGET_CURRENT_DELETING` **or** `TARGET_EXISTING_DELETING` (`backup/controller/restore_controller.class.php:394-399`), so every block on the course is deleted through `blocks_delete_all_for_context()` (`lib/moodlelib.php:4820`) and `instance_delete()` fires — and the block is re-created from the backup near the end of the same plan with a new instance id (`backup/moodle2/restore_stepslib.php:4581`). Three entry points reach this: the restore UI/async task, course import (`backup/import.php:196-197`), and `core_course_import_course` with `deletecontent=1` (`course/externallib.php:1847-1848`).
There are **two independent destroyers** here, and the previous draft named only the first. (a) The pending sweeper fires mid-restore if the restore runs longer than the grace — a 30-minute floor is an assumption about restore duration, not a defence, and large async restores routinely exceed an hour. Only the `{backup_controllers}` guard (§1.4 step 3) actually holds. (b) `remove_course_contents()` also deletes the course modules, so **every** ledger row for the course has a missing `cm`, and `reconcile_ledger::sweep_orphans()` — course-ungated, running every 2h — deletes them outright, entirely outside the pending-removal machinery. That is a live bug in the plugin today, before this feature exists.
**Why it is silent:** the restore reports success. The block is back on the course page. Nothing in any UI hints that anything happened. Later the course's measured history is gone, and the reconciler starts a fresh history from the restore date — so the course does not even look empty, it looks *young*. And it cannot be fixed by the obvious reflex of cancelling on `\core\event\course_restored`: that event fires only for `backup::TYPE_1COURSE` (`restore_plan.class.php:172`), does not fire at all on a crashed restore (and *not firing* is then correct, because the block really is gone), and fires even when the block was **not** restored — the root `blocks` setting being off makes `restore_block_task::build()` return with no steps (`restore_block_task.class.php:70-73`), which is exactly the case where the cleanup must fire. Only the combination of the `{backup_controllers}` guard, the gated `sweep_orphans`, the block-presence re-check, and a `course_restored` handler that **re-checks presence rather than blanket-cancelling** is correct in both directions. Tests 6 and 35.

**4. Flipping the master switch on after the pending list has filled up.**
The design arms rows even while `removal_cleanup_active` is off, so the pending list is informative. But `timedue` is materialised at arming time, so every one of those rows is already past due the instant the switch is ticked. Without `removal_cleanup_enabledsince`, one `*/15` tick destroys weeks of accumulated decisions in a single pass — no grace, no review, no confirmation, and the abandon pass never ran while the switch was off so nothing bounded the backlog either.
**Why it is silent:** the admin's action was "turn on a feature", not "delete data". There is no confirmation on a settings checkbox. The destruction happens up to fifteen minutes later, in cron, attributed to a scheduled task. The pending list — the one surface that would have shown what was about to go — is empty by the time anyone looks. Tests 10 and 13.