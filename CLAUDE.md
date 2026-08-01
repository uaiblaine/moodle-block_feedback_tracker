# Claude instructions for `block_feedback_tracker`

This file is auto-loaded as context whenever Claude works in this plugin's
directory tree. It captures the **Moodle development standards** this plugin
follows so future edits stay in the same style and pass CI on the first try.

Plugin context: a Moodle block plugin that measures teacher response time
for `mod_assign` submissions using business/academic time. Supports
Moodle **4.5 through 5.2** (`$plugin->requires = 2024100700`,
`$plugin->supported = [405, 502]`). CI is the
**moodle-an-hochschulen/moodle-workflows** reusable workflow, called once
per supported Moodle branch in `.github/workflows/ci.yml` (5.02 full
PHP × DB matrix; 5.01/5.00/4.05 PostgreSQL-only) — **update those calls
when `supported` changes**. Development happens on 5.1.

## Commands

Run from the plugin repo (`~/dev/moodle-block_feedback_tracker`). It is
bind-mounted into the `m405` and `m501` stacks at `blocks/feedback_tracker`,
and that mount only exists **inside the container** — on the host,
`~/dev/moodle-501/public/blocks/feedback_tracker` is an empty directory.
A linter pointed at the stack checkout therefore scans nothing and passes
vacuously; target the repo itself, or go through `mdl`.

Repo layout (Moodle 5.x split): the webroot is `public/` (plugin code +
`public/config.php`), but **core CLI scripts live in `admin/cli/` at the
repo root, outside `public/`** — e.g. `php admin/cli/purge_caches.php`.
Plugin CLIs resolve `public/config.php` via `__DIR__/../../../config.php`.

| Command | What it does |
|---------|--------------|
| `mdl grunt m501 blocks/feedback_tracker` | Rebuild `amd/build/**/*.min.js` from `amd/src/`. Core's `amd` task is `eslint:amd` + `rollup`, so this lints the JS on the way. Required before committing JS. |
| `mdl ci moodle-block_feedback_tracker --only grunt` | The CI JS/CSS gate itself: `grunt --max-lint-warnings 0` (ESLint + Stylelint). |
| `xmllint --noout --schema ~/dev/moodle-501/public/lib/xmldb/xmldb.xsd db/install.xml` | Validate the XMLDB schema. Core libs sit under `public/` on the 5.x split layout; against `~/dev/moodle-405` drop that segment. |

CI (moodle-an-hochschulen/moodle-workflows, full `moodle-plugin-ci install`
per job) gates on: a static leg (`phplint`, `phpmd` informational,
`phpcs --max-warnings 0` — **warnings fail**, `phpdoc --max-warnings 0`, a
development-leftover checker that fails on stray to-do markers, test-me
annotations, or merge-conflict markers in ANY file — docs included, never
write those tokens literally, `validate`, `savepoints`, `mustache`,
`grunt --max-lint-warnings 0` incl. stylelint) plus runtime legs running
**PHPUnit (`--fail-on-warning`) and Behat (`--profile chrome`) on every
PHP × DB combination**, with Behat faildumps uploaded as artifacts on
failure. `.moodle-plugin-ci.yml` filters `node_modules`/`vendor` because
the install step npm-installs inside the plugin and phpcs would scan the
result. Reproduce the whole pipeline locally with
`mdl ci moodle-block_feedback_tracker` before pushing — see
*Debugging CI failures* for this plugin's invocations.

PHPUnit runs as `mdl phpunit m501 block_feedback_tracker`. Its `phpunit.xml`
is generated at the **stack checkout root** (`$CFG->root`, above `public/`),
never `public/phpunit.xml`; `mdl phpunit-init <stack>` regenerates it, which
is required after the mounted plugin set changes.

## Code layout

```
block_feedback_tracker.php   Block class (get_content / instance config)
lib.php                      Procedural hooks + bootstrapping guard
settings.php                 Admin settings tree
manage.php                   Management landing page (viewdashboard-gated)
classes/
  event/                     Custom plugin events (cal_*_updated)
  external/                  Web-service functions (one class each)
  form/                      moodleform subclasses
  output/                    Renderer + renderables (score_gauge, responsiveness_card, sparkline)
  privacy/                   GDPR provider
  task/                      Scheduled + adhoc tasks (drain, backfill, recompute, prune)
  local/
    audit/                   Recompute audit log
    calendar/                Academic-time engine (business/effective hours)
    output/                  JS bootstrap helper
    payload/                 responsiveness_payload (block + WS share this)
    score/                   responsiveness_calculator (5-term formula) + peer_stats
    sla/                     Ledger, rollup, observer, course_access gate
cli/                         reset / recompute_all / recompute_one / backfill_* maintenance scripts
pages/                       Admin + teacher UIs (dashboard, calendar editor, drilldown)
templates/                   Mustache (server-rendered UI)
amd/src/                     Preact UI (Phase 2A) — see "React conventions"
  *_app.js                   Per-surface entrypoints (block_app, dashboard_app,
                             pending_report_app, simulator_app, spike_react)
  views/                     Entrypoint orchestrators (BlockView, DashboardView,
                             PendingReportView, SimulatorView)
  components/                Leaf components (one per file, PascalCase)
  lib/                       preact shim + helpers (api, bands, format, score, trend)
db/                          install.xml, upgrade.php, events, tasks, caches, access
tests/                       PHPUnit (local/ external/ task/ privacy/) + behat/
```

The runtime **data model** (ledger → pause audit → rollup → queue →
calendar config) is described in [`README.md`](README.md).

## Coding style

### File header

Every PHP file starts with:

```php
<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software... [full GPL block]
// ...

/**
 * <One-line file description>.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);   // for namespaced classes

namespace block_feedback_tracker\<sub>;

defined('MOODLE_INTERNAL') || die();
```

Procedural files (settings.php, lib.php, db/*.php) skip `declare(strict_types=1)`
and `namespace`. `defined('MOODLE_INTERNAL') || die()` is required everywhere.

### PHPDoc

Moodle's `phpdoc --max-warnings 0` enforces:

- Every class, method, property, and constant has a `/** */` docblock
- `@param`, `@return`, `@throws` declared explicitly (even when types are
  fully implied by signatures)
- Type hints in PHPDoc use `int|null`, `?int` — match the actual PHP type
- **`@param` array types must be plain `array`** — `local_moodlecheck`
  (the engine behind `phpdoc`) can't pair the `$var` to its parameter when
  the type is a generic (`array<int, string>`) or a shape
  (`array{key: type}`), and reports `<function> has incomplete parameters
  list (error)`. Put the shape detail in the description prose instead:
  `@param array $rows Resolved rows keyed by id.` This only bites `@param`
  (which is matched to the signature); `@return array{...}` / `array<...>`
  is fine because there is no variable to pair. (The old catalyst phpdoc
  tolerated generics; the migrated workflow does not.)
- File-level docblock has `@package`, `@copyright`, `@license`
- No `@author` tags (Moodle convention)

### Naming

- Classes: `lower_snake_case` (Moodle convention, not PSR-4 PascalCase)
- Methods: `lower_snake_case`
- Constants: `UPPER_SNAKE_CASE`
- Properties: `lowercase` (no camel/snake mixing — single lowercase word
  where possible)
- Frankenstyle prefix on globals: `block_feedback_tracker_*`

### Table prefix

Database tables use the **full frankenstyle**: `block_feedback_tracker_*`.
The longest table name is `block_feedback_tracker_chours` at 29 chars,
inside the 30-char limit. The four calendar tables use a `c` prefix
(`_cday`, `_chours`, `_cpause`, `_cscope`) to stay within the limit.

### Lang strings

`lang/en/block_feedback_tracker.php` strings are **alphabetically sorted**.
The CI's `moodle-plugin-ci validate` step enforces ordering. Insert new
strings in the correct alphabetic position.

Required strings:
- One per capability: `feedback_tracker:<capname>` (`block/` prefix is dropped
  in the lang key)
- One per scheduled / adhoc task: `task_<classname>`
- One per custom event: `event_<eventname>`
- One per cache definition: `cachedef_<name>`
- One per admin setting: `settings_<key>` and `settings_<key>_desc`

### Dynamic string references

The PHPDoc / string-checker can't statically verify dynamically-constructed
string IDs. **Don't** do `get_string('band_' . $band, ...)`. **Do** use a
literal switch / match:

```php
private static function band_label(string $band): string {
    switch ($band) {
        case 'excellent': return get_string('band_excellent', 'block_feedback_tracker');
        case 'good':      return get_string('band_good',      'block_feedback_tracker');
        // ...
        default:          return '';
    }
}
```

### CodeSniffer rules that bite

Eight rules from `moodle.*` / `PSR2.*` / `PSR12.*` standards routinely break
CI on this plugin. Pre-empt them at write time.

**1. Variables are lower-case only.** No camelCase, no snake_case — a single
lower-case word (concatenated if needed). Sniff:
`moodle.NamingConventions.ValidVariableName.VariableNameLowerCase`.

```php
// ✘ $cmA, $studentA, $cm_a, $student_a
// ✓ $cma, $studenta
```

**2. PSR-2 multi-line function call layout.** When a call spans lines:
opening `(` is the last content on its line; one argument per line; closing
`)` on its own line at the call's indent level. Sniffs:
`PSR2.Methods.FunctionCallSignature.{ContentAfterOpenBracket,MultipleArguments,Indent,CloseBracketLine}`.

```php
// ✘ Two args on the wrap line:
$DB->set_field('block_feedback_tracker_sub', 'groupid', $groupa->id,
    ['userid' => $studenta->id, 'courseid' => $course->id]);

// ✓ One arg per line, ) on its own line:
$DB->set_field(
    'block_feedback_tracker_sub',
    'groupid',
    $groupa->id,
    ['userid' => $studenta->id, 'courseid' => $course->id]
);
```

**3. Inline `//` comments — one space, capital first letter, no inline
indentation.** The sniff fires on the **first line** of any `//` comment
block that starts with a lowercase letter, digit-preceded-by-letter (`v1.0`),
or lacks terminal punctuation. Need multi-line or version-tagged commentary?
Use a block comment (`/* ... */`) — the sniff does not apply inside `/* */`.
Sniffs: `moodle.Commenting.InlineComment.{NotCapital,InvalidEndChar,SpacingBefore}`.

```php
// ✘ Starts with lowercase 'v':
// v1.0.9 — sub-day event window. Only meaningful when daytype is
// 'optional'; hideIf hides the inputs otherwise.

/* ✓ Block comment — 'v' prefix and multi-line content allowed: */
/* v1.0.9 — sub-day event window. Only meaningful when daytype is
 * 'optional'; hideIf hides the inputs otherwise. */

// ✘ Trailing inline comment starting with lowercase, no punctuation:
backfill_cursor::get_or_create(3);   // active

// ✓ Remove trivial trailing comments — the method call speaks for itself:
backfill_cursor::get_or_create(3);
```

**4. Property docblocks need `@var`.** A `/** ... */` on a class property
*must* declare the type even when PHP's typed-property syntax already does.
Sniff: `moodle.Commenting.VariableComment.MissingVar`.

```php
// ✘ /** Per-request memo keyed by "courseid:userid". */
//   private static array $memo = [];

/** @var array<string, int[]|null> Per-request memo keyed by "courseid:userid". */
private static array $memo = [];
```

**5. Ternary operator alignment spaces.** The operator-spacing sniff requires
**exactly one space** before `!==`, `===`, `?`, `:` etc. Aligning columns
with extra spaces breaks it. Sniff:
`Squiz.WhiteSpace.OperatorSpacing.SpacingBefore`.

```php
// ✘ Alignment spaces trigger "3 found" / "6 found" errors:
$starttime = $params['starttime'] !== null ? (int) $params['starttime'] : null;
$endtime   = $params['endtime']   !== null ? (int) $params['endtime']   : null;

// ✓ One space everywhere — no column alignment:
$starttime = $params['starttime'] !== null ? (int) $params['starttime'] : null;
$endtime = $params['endtime'] !== null ? (int) $params['endtime'] : null;
```

This also applies inside array literals built from DB rows:

```php
// ✘
'endtime' => $r->endtime   !== null ? (int) $r->endtime   : null,
'note'    => $r->note      !== null ? (string) $r->note   : null,

// ✓
'endtime' => $r->endtime !== null ? (int) $r->endtime : null,
'note' => $r->note !== null ? (string) $r->note : null,
```

**6. PSR-12 multi-line `if` layout.** When an `if` condition spans lines, the
first expression must be on the line **after** `(` and the closing `)` on the
line **after** the last expression. Sniffs:
`PSR12.ControlStructures.ControlStructureSpacing.{FirstExpressionLine,CloseParenthesisLine}`.

```php
// ✘ First expression on the same line as (:
if ($type === calendar::DAYTYPE_OPTIONAL
    && $starttime !== null && $endtime !== null
    && $endtime > $starttime) {

// ✓ Expression on next line; ) on its own line:
if (
    $type === calendar::DAYTYPE_OPTIONAL
    && $starttime !== null && $endtime !== null
    && $endtime > $starttime
) {
```

**7. Line-length limits.** Hard max is **180 characters** (error); soft max is
**132 characters** (warning). Both matter — the warning count feeds
`phpdoc --max-warnings 0`. Long `@return` type annotations in PHPDoc are the
most common offender; wrap them at a natural boundary:

```php
// ✘ 183 characters — exceeds the hard limit:
// @return array{type:string, ..., optional_window:?array{startmin:int,...}}

// ✓ Wrapped after a comma:
// @return array{type:string, dayofweek:int, is_weekend:bool, business_hours:array,
//               is_active:bool, day_note:?string,
//               optional_window:?array{startmin:int,endmin:int,note:?string}}
```

**8. Squiz "commented-out code" false positive.** The sniff
`Squiz.PHP.CommentedOutCode.Found` fires when a trailing `//` comment
contains text that looks like PHP (e.g. `// active=0`). Fix: remove trivial
trailing comments entirely (the code is self-documenting), or rephrase to
avoid `=` inside the comment text.

**9. `defined('MOODLE_INTERNAL')` not needed in pure namespaced class files.**
The sniff `moodle.Files.MoodleInternal.MoodleInternalNotNeeded` fires when a
namespaced PHP file has no side-effects (no `require_once`, no globals, only a
single class/interface/enum definition). Pure constant-holder or enum-like
classes fall into this category. Remove the guard from those files; the
namespace itself prevents direct instantiation outside Moodle.

```php
// ✘ Pure class with no require_once or global assignments:
namespace block_feedback_tracker\local\sla;

defined('MOODLE_INTERNAL') || die();   // ← sniff fires: not needed

final class submission_status { ... }

// ✓ Guard removed — namespace is sufficient:
namespace block_feedback_tracker\local\sla;

final class submission_status { ... }
```

Files that DO need the guard: any file with side-effects — `require_once`,
`global $CFG`, multiple class definitions, or procedural top-level code
(`settings.php`, `db/*.php`, form classes with `require_once`). The sniff
applies to **any pure-declaration file, namespaced or not** (a `lib.php`
holding only functions counts), and since CI runs
`phpcs --max-warnings 0`, this warning **fails the build** — it is not
advisory anymore.

**10. `@var` on inline comments inside array literals inside `external_*`
structures.** This sniff fires on `/** */` property docblocks; it does NOT
fire on `// …` inline comments inside method bodies. However, inline `// v…`
comments that are the **first** line of a standalone comment block inside an
array literal (e.g. inside `execute_returns()` structure definitions) still
hit rule 3 (NotCapital). Convert them to `/* */` exactly as with any other
standalone lowercase comment.

## Database (XMLDB)

- Every `<FIELD>` element needs `SEQUENCE="true"` or `SEQUENCE="false"`
  explicitly. Missing → XSD validation fails.
- Validate locally with the `xmllint` invocation in *Commands* (the schema
  lives in a stack checkout, not in this repo).
- `db/install.php`'s `xmldb_<plugin>_install()` function uses raw
  `set_config()` and direct `$DB->insert_record()` — these **don't fire
  setting update callbacks**, so they're safe for default seeding.
- `block_feedback_tracker_group` is a **materialized** rollup: after changing
  any rollup computation, existing rows stay stale until recomputed — run
  `cli/recompute_all.php` (or let `drain_queue` process re-enqueued tuples).

### Upgrade savepoints

Each upgrade step ends with:
```php
upgrade_block_savepoint(true, <version>, 'feedback_tracker');
```
Match `<version>` to the version.php bump.

### Cross-DB SQL

CI runs against both PostgreSQL 15 and MariaDB 10. Patterns that break:

- `SELECT :literal FROM table` — PG infers the placeholder as text and
  comparisons against bigint columns fail. **Fix**: select from `{context}`
  with `EXISTS()` predicates instead:

  ```sql
  SELECT ctx.id
    FROM {context} ctx
   WHERE ctx.id = :sysctxid
     AND (EXISTS (SELECT 1 FROM {plugin_table} WHERE ...))
  ```

- `ORDER BY col ASC NULLS FIRST` — PG-only. Use `COALESCE(col, 0) ASC`
  for cross-DB.

- `\moodle_database::get_records()` returns string values for numeric
  columns under both drivers. Cast to `(int)` / `(float)` when typing matters.

## Forms (moodleform)

Each form class file sits under `classes/form/` and starts with:

```php
namespace block_feedback_tracker\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');   // moodleform is not autoloaded

class my_form extends \moodleform { ... }
```

Conventions:

- Use moodleform's default button label (`add_action_buttons(false)` →
  "Save changes") unless the form genuinely needs a unique verb (e.g.
  "Import" for bulk CSV).
- **The submit-button label must differ from the form's section-header
  label**: the collapsible header toggle is exposed as `role=button` with
  the section name and precedes the real button in the DOM, so identical
  strings make screen readers and Behat hit the toggle instead of
  submitting.
- **Two or more moodleforms on one page**: `add_action_buttons()` hardcodes
  the element name `submitbutton`, duplicating `id_submitbutton` in the DOM.
  Use named submit elements instead
  (`$mform->addElement('submit', 'mysavebutton', ...)` +
  `$mform->closeHeaderBefore('mysavebutton')`).
- For float fields where users may type non-canonical strings (e.g.
  `"0.40"`), **don't use `PARAM_FLOAT`** — its validator strict-string-
  compares against the `clean_param` result, which normalises `"0.40"` to
  `"0.4"`. Use a regex paramtype: `'/^[0-9]+(\.[0-9]+)?$/'`.
- Custom validation in `validation($data, $files)` — return an array of
  `field => errormsg` strings.

## Custom events

```php
class my_event extends \core\event\base {
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        // Do NOT set 'objecttable' unless EVERY caller will pass an objectid.
        // The two must appear together or not at all — bulk-operation
        // callers that have no single object id need the event to omit
        // objecttable.
    }
}
```

Pair: `objectid` + `objecttable` must both be present or both absent in
`::create()` data. If you set one without the other, Moodle throws a
`coding_exception`.

### View / access-logging events

User access to the pages is logged with two read events (`crud = 'r'`):
`event\report_viewed` (`LEVEL_PARTICIPATING`, the teacher-facing data
surfaces — `other['report']` = `dashboard`/`pending`/`drilldown`) and
`event\tool_page_viewed` (`LEVEL_OTHER`, the admin tool pages —
`other['page']` = `manage`/`calendar`/`audit`/`reset`). They exist as two
classes only because `edulevel` is fixed in `init()` and can't vary per
instance. Conventions when adding/extending view logging:

- **Fire server-side at page render, immediately before
  `$OUTPUT->header()`** — once per navigation. Placing it after any
  POST-process `redirect()` means form submits/cancels don't double-log.
- **Never fire from a web service `execute()`.** The full-page surfaces ship
  a null bootstrap and fetch via WS on mount + on every refresh / filter /
  sort / page — logging there would multiply rows per view and grow the log
  table non-linearly. The page-view event is the single access signal.
- **No `db/events.php` observer is needed.** `logstore_standard` subscribes
  to every event, so `->trigger()` is all that's required for the access to
  appear in *Reports → Logs*. (The `cal_*` events have observers only because
  they drive plugin side-effects — calver bump + rollup recompute — not for
  logging.) Origin (userid/IP/origin/timecreated) is captured automatically;
  we only supply `context`, `courseid` and `other`.
- **No plugin privacy-provider change.** The rows live in
  `logstore_standard_log`, whose own privacy provider handles GDPR
  export/delete; the plugin that *fires* the event declares nothing extra.

## Processing scope (course_access gate)

Since v1.0.0 the plugin is **strict opt-in**: nothing happens for a
course unless a `feedback_tracker` block instance lives on that course's
own context (category- and system-context blocks deliberately don't
count — they don't render on courses). Hidden courses are also skipped
unless the `process_hidden_courses` admin setting is on.

The gate lives in
[`classes/local/sla/course_access.php`](classes/local/sla/course_access.php).
`course_access::is_processable($courseid)` is the single decision
point — call it whenever you add a new event observer or batch job that
writes ledger / rollup data. Don't reimplement the check.

The gate is applied at every **write-path entry**:
- Event observers in `classes/local/sla/observer.php`
  (submission_changed / submission_graded / override_changed /
  group_membership_changed / group_deleted).
- `classes/task/backfill_history.php` per-row filter.

Cleanup paths (`course_deleted`, `course_module_deleted`) skip the gate
so previously-tracked data still gets garbage-collected when its course
goes away. `rollup_service::recompute_group()` deliberately does NOT
gate — it's downstream of the observer + queue and gating there would
force a wide test-fixture rewrite without closing any leak.

When adding a PHPUnit test that fires assign events or invokes
backfill, add a block instance to the course in your setup helper:
```php
$coursectx = \context_course::instance($course->id);
$this->getDataGenerator()->create_block('feedback_tracker', [
    'parentcontextid' => $coursectx->id,
]);
course_access::reset_memo();  // flush memo for recycled courseids
```

## Submission-status scope (submitted-only)

Only `assign_submission.status = 'submitted'` counts toward the SLA. `draft`
(saved, not submitted), `new`, and `reopened` are awaiting the *student*, not
the teacher, so they must never enter pending counts, response time, score,
compliance, or trend. The ledger still **stores** every status (the row
transitions new→draft→submitted on later events, and drafts must exist to be
displayed) — filtering is **read-time only**.

[`classes/local/sla/submission_status.php`](classes/local/sla/submission_status.php)
is the single source of truth (`SUBMITTED`/`DRAFT`/`REOPENED`/`NEW`, mirroring
mod_assign's `ASSIGN_SUBMISSION_STATUS_*`). Every SLA read binds
`submissionstatus = :substatus`. When adding a new query over
`block_feedback_tracker_sub`, add that filter — the existing sites are
`rollup_service` (pending / graded / trend), `pending_recomputer`,
`responsiveness_calculator` (momentum), `site_stats_service`, `trend_service`,
`get_grader_priority_list`, and `get_pending_submissions`. The
`idx_status_graded (submissionstatus, timegraded)` index covers them.

Drafts are surfaced read-only and de-emphasised (report + drilldown only) via
`get_pending_submissions`' optional `status` param (`submitted` default |
`draft`); they never reach the block/dashboard counts.

## MUC caches

Keys must avoid characters that are unsafe in file paths (no `:`).
Convention used in this plugin: `"{calver}_{<id>}"`. The `calver` site
setting is bumped on every calendar-affecting save so old cache keys
naturally fall out of routing — no explicit purge call is needed for
calver-keyed caches.

## Mustache templates

Every `templates/*.mustache` file must include an `Example context (json):`
block in its docblock. The Mustache lint renders the template against
that context and validates the resulting HTML.

```mustache
{{!
    @template    block_feedback_tracker/my_template

    Description.

    Context variables required:
    * field   Type   What it represents

    Example context (json):
    {
        "field": "example value"
    }
}}
<div>{{field}}</div>
```

When a template renders a table whose `<thead><tr>{{#cols}}<th>...</th>{{/cols}}</tr></thead>`
loop would produce empty `<tr></tr>` with empty cols, the example context
**must** supply non-empty cols — otherwise the HTML validator rejects the
preview render.

For raw HTML insertion (form HTML from `moodleform::render()`), use
triple-stash: `{{{form_html}}}`.

## Renderables

Server-side rendering uses the `templatable` + Mustache pattern, **not**
inline `html_writer`:

```php
class my_renderable implements \renderable, \templatable {
    public function export_for_template(\renderer_base $output): array {
        return [...];
    }
}

// In the renderer:
protected function render_my_renderable(my_renderable $r): string {
    return $this->render_from_template('block_feedback_tracker/my_template',
        $r->export_for_template($this));
}
```

**Zero `html_writer` calls** in plugin code. The only exceptions are
moodleform's own internal markup (which Moodle controls).

## Web services

- All function classes under `classes/external/` extend
  `\core_external\external_api`
- Function parameters: `execute_parameters()` returns an
  `external_function_parameters`
- Return shape: `execute_returns()` returns an `external_single_structure`
- Every read function checks `validate_context()` + `require_capability()`;
  every write function does the same + fires an event
- Don't call WS classes from within `block_base::get_content()` — the WS's
  `validate_context()` calls `$PAGE->set_context()` which adds body
  classes, and the header has already started by then. Use a separate
  data-loading helper (e.g. `responsiveness_payload::for_course()`) that
  both the WS and the block call directly.
- `get_dashboard`'s per-course return shape is read key-by-key by
  `amd/src/views/DashboardView.js::aggregate()`; a field `aggregate()` reads but
  the WS omits silently becomes `null` (no error). Keep them in sync and bump
  the WS `CACHE_KEY_VERSION` on any shape change. A WS that emits localised
  strings (e.g. `get_insights`) must also include `current_language()` in its key.
- Registering a new function in `db/services.php` requires a `version.php`
  bump — services install only on upgrade.
- Full-page surfaces (`teacher_dashboard.php`, `pending_report.php`) ship a
  null-data bootstrap (i18n + config + prefs + URL params only) and fetch via
  WS after mount, main content first. Never call
  `responsiveness_payload::for_course()` synchronously from a page — it builds
  per-group trend/peer/activity data; when only headline numbers are needed,
  read the rollup columns directly (see `get_report_scopes`).
- Per-course group visibility has one decision point:
  `local\sla\group_access::visible_group_ids()` — `null` = unrestricted, `[]` =
  nothing visible (short-circuit), `int[]` = whitelist that **never** includes
  groupid 0 ("ungrouped" is unrestricted-only). Don't reimplement.

## Capability checks

- Always pass an explicit `\context` — never rely on `$PAGE->context`
- `has_capability('mod/assign:grade', $context, $userid)` respects the
  user's **real** role assignments, not Moodle's "Switch role to..."
  temporary state — useful for filtering role-switched test submissions
- `get_user_capability_course($cap, $uid, $doanything, ...)`: the 3rd arg
  is **`$doanything`** (not "onlyactive"), so it returns *every* course for
  a site admin. To scope an admin like a normal teacher, enumerate with
  `enrol_get_users_courses($uid, true)` and filter with
  `has_capability($cap, $ctx, $uid, false)` (doanything off). The dashboard
  read-path scope lives in `classes/local/sla/dashboard_scope.php`.

## Install / upgrade guards

Any function called from a setting's `set_updatedcallback` (the most common
trigger: `block_feedback_tracker_invalidate_rollups`) must short-circuit
while the plugin is bootstrapping. Otherwise `admin_apply_default_settings()`
runs the callback **before** the plugin's MUC stores are registered, and
cache calls fail with a `debugging()` notice that breaks `phpunit
--fail-on-warning`.

The canonical guard is in [`lib.php`](lib.php):
`block_feedback_tracker_is_bootstrapping()`, combining three checks:

1. `during_initial_install()` — first-run Moodle install.
2. `!empty($CFG->upgraderunning)` — any plugin install/upgrade (including
   `admin/tool/phpunit/cli/util.php --install`).
3. `!$DB->get_manager()->table_exists('block_feedback_tracker_group')` —
   belt-and-suspenders for partial installs / restored backups.

## PHPUnit tests

- Every test file in `tests/<area>/<thing>_test.php`
- Class: `block_feedback_tracker\<namespace>\<thing>_test extends \advanced_testcase`
- `@covers \block_feedback_tracker\...` annotation on the class docblock
- Call `$this->resetAfterTest()` in every test that touches the DB
- DB rows from `$DB->get_records()` and `getDataGenerator()->create_*()`
  return **string** ids under both drivers. Cast to `(int)` when passing
  to typed-int method signatures, e.g.
  `submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0)`.
- `submission_graded` events must be constructed via
  `\mod_assign\event\submission_graded::create_from_grade($assigninst, $grade)` —
  direct `::create()` throws `cannot be called directly`. Requires
  `require_once($CFG->dirroot . '/mod/assign/locallib.php')` + instantiating
  `new \assign($context, $cm, $course)`.
- `submission_ledger::upsert_for_cm_user_attempt()` requires an existing
  `{assign_submission}` row — without one it returns `null` and creates
  no ledger row.
- `assertContains` is strict (`===`) by default. When asserting against
  DB-derived arrays (which carry string ids), normalise the haystack:
  `array_map('intval', $contextlist->get_contextids())`.

## Behat scenarios

Features under `tests/behat/` **actually run in CI** on every runtime
PHP × DB leg (they were dormant while the old catalyst snapshot workflow
had `disable_behat`); a failing scenario uploads a faildump artifact.

- Multi-line text into fields: use the `... to multiline:` step + PyString,
  not `\n` escapes in a quoted string (Behat treats `\n` as literal).
- `I press "Save changes"` matches moodleform's default submit-button label
  (`add_action_buttons(false)` with no second arg).
- Mink matches field labels by **substring** (`contains()`): a bare
  locator like "Language" also matches "Preferred language". Scope lookups
  to a container — `I set the field "X" in the "Section name" "fieldset"
  to "Y"` — whenever the page may carry similar labels.

## Settings (settings.php) reset pattern

- Per-form-write settings carry `set_updatedcallback('block_feedback_tracker_invalidate_rollups')`
- The five score weights do **not** chain through any normalisation
  callback. Normalisation happens at read time in
  `responsiveness_calculator::load_weights()`, not at save time —
  save-time normalisation races with `admin_apply_default_settings()`
  and corrupts values mid-install.
- Default-ON checkboxes: never read with `get_config(...) ?: 1` — the
  stored off-state is the string `'0'`, which is falsy, so the toggle can
  never turn off. Treat unset (`false`/`null`) as the default and only an
  explicit `'0'` as off (pattern: `bootstrap::config_bundle()`'s
  `show_peer_context` read).

## Score formula

Normalisation is **read-time only**. `load_weights()` rescales values
to sum 1.0 if the stored sum is outside `[0.95, 1.05]`. Stored values
are kept as the admin typed them.

## Dashboard display conventions (trend, medians, sparkline)

`trend_pct_30d` (% change in median effective hours, **negative = faster**) is
shown as **speed** on every surface: faster = `▲` green, slower = `▼` red,
`|pct| < 2` = `→` muted; magnitude is **unsigned** (direction = arrow + colour
+ word, never `+/−`). Classifier: [`amd/src/lib/trend.js`](amd/src/lib/trend.js)
(`classifySpeed` / `speedLabel`), used by `TrendRow` / `ResponsivenessHero` /
`ResponsivenessHeroSlim`. When touching trend direction, mirror the sign in the
copies that each keep their own: `classes/output/responsiveness_card.php` (the
server no-JS card) and `amd/src/lib/format.js::formatTrend` — both were easy to
leave **inverted**.

**Median families — never conflate:** `median_eff_h` is graded-only and feeds
the **score** (don't repurpose it for display). `cur_median_eff_h` /
`cur_median_raw_h` (hours) and `cur_median_eff_days` / `cur_median_perc_days`
(date-based day counts) are graded ∪ currently-pending medians that drive the
**display** headline. The `display_time_unit` setting ('hours' |
'business_days') only selects which family the UI shows (`usesDays(config)` in
`lib/format.js`) — both are always computed by the rollup, so the toggle is
display-only, no recompute. Day counts come from
`local\calendar\day_counter` (day boundaries crossed, time-of-day ignored;
business days skip weekend/holiday/recess) — **never** derive days by dividing
hours. Per-submission rows get `effective_days`/`perceived_days` at read time
(`submission_browser`, `get_grader_priority_list`).

The block renders **twice** — a server card (`responsiveness_card.php` +
`responsiveness_card.mustache`, the no-JS first paint) **and** a Preact app
(`block_app.js` → `GroupCard` → `TrendRow`); display changes land in both. The
sparkline has three lockstep copies (`amd/src/components/Sparkline.js`,
`classes/output/sparkline.php`, `templates/sparkline.mustache`) on a **speed**
Y axis: fewer hours = higher, green "desired-speed" zone anchored at the top.

**Band slug ≠ visible label:** the slugs `excellent/good/regular/critical/pending/nodata`
and the `bft-*-tone-<slug>` CSS classes are frozen identifiers; relabel a band by editing
only its `band_*` lang string (e.g. `regular` → "Up Next" / "Atenção", `critical` →
"Priority" / "Prioridade"). Labels resolve via literal switches, so no code change is needed.

Two vocabularies share these slugs: the **score** gauge shows the `band_*` strings
(Excelente / Bom / …); **pending/priority** surfaces (group-card stat tiles,
`PriorityCard`) show the `card_pending` / `card_overgoal` / `card_critical` trio
(Aguardando / Atenção / Prioridade). To fix a pending-surface label, remap the slug
to a `card_*` string in that component (e.g. `PriorityCard::priorityLabel`) — never
relabel `band_*` globally, which would change the score gauge too.

**Count formatting (thousands separator):** every integer submission count is
grouped with the active language's separator — `numfmt::count()`
([`classes/local/output/numfmt.php`](classes/local/output/numfmt.php)) in PHP,
`formatCount()` in [`amd/src/lib/format.js`](amd/src/lib/format.js) in JS. The
separator is `langconfig`'s `thousandssep` (comma in en, dot in pt_br): the PHP
helper reads it directly; the JS helper is fed it via
`bootstrap::config_bundle()` → `config.thousandssep`, pinned once per page by
`setGroupingSeparator()` in each `*_app.js` entrypoint (never read it inside a
component). The **renders-twice** rule applies — a new count on the block formats
in both `responsiveness_card.php` and its Preact component. Both helpers coerce
non-numeric input to `0`, so they take **integer counts only**: never feed them
an already-formatted string or an hours/%/score value (those keep `formatHours` /
`formatPercent`). `Counts.js` stays **generic** (its `value` may be a
pre-formatted metric string like `"8.4 h"`), so the caller formats, not the
component. Hours/percent/score/dates are deliberately left ungrouped.

## React conventions (Phase 2A foundation)

Moodle 5.1 doesn't ship React. The plugin vendors **Preact + htm**
(API-compatible with React, no JSX build step) and exposes them through
a single AMD shim. Moodle 5.2's native React subsystem will replace this
with a one-file change to the shim.

### Vendor layout

- Vendored UMD code lives in [`js/vendor/`](js/vendor/), **never** under
  `amd/`. Moodle's grunt only globs `amd/src/**/*.js`, so anything in
  `js/vendor/` is left alone by ESLint and Babel.
- Declared in [`thirdpartylibs.xml`](thirdpartylibs.xml) (three library
  entries, all pointing to the single concatenated bundle).
- Loaded into `<head>` via `$PAGE->requires->js(..., $inhead = true)` so
  the bundle's globals are set before the AMD loader resolves any module.
- The bundle is wrapped in an outer IIFE that shadows `define`, forcing
  the upstream UMDs to take their global-script branch instead of
  registering as anonymous AMD modules.

### AMD shim ([`amd/src/lib/preact.js`](amd/src/lib/preact.js))

The only file that reads `window.bftPreact` / `window.bftPreactHooks` /
`window.bftHtm`. Every component imports through it:

```js
import {html, useState} from 'block_feedback_tracker/lib/preact';
```

Never import from a `preact` / `react` specifier (that path doesn't
exist) and never reach for `window.bft*` directly outside the shim.

### Markup syntax

`htm` tagged templates — no JSX, no Babel preset:

```js
return html`
    <div class="bft-card">
        <${ScoreGauge} score=${score} band=${band} />
        ${items.map((it) => html`<span key=${it.id}>${it.label}</span>`)}
    </div>
`;
```

Component references use `<${ComponentName}>`. Children that are
arrays must carry a `key` attribute (Preact's reconciliation rule).

ESLint caps `amd/src/**` lines at **132** (`max-len`); when an htm element's
attributes push a line over, break the child/label onto its own line or grunt fails.

CI runs `grunt --max-lint-warnings 0`, so **every ESLint warning fails the
build** — there is no warning tier. The rules that bite this codebase:
`no-nested-ternary` (unwind into `if`/`else` or a lookup map — see
`lib/trend.js` `TONEARROWS`/`TONECOLOURS`; don't nest `? :`),
`complexity` (max 20 — the large orchestrator views/components carry a
documented `// eslint-disable-next-line complexity` with a refactor-pending
note rather than a silent disable), `camelcase` (WS payload keys like
`overall_score`/`total_pending` must be quoted string literals, not bare
identifiers), and `promise/always-return` (every `.then()` returns a value;
non-critical fetches use `.catch(() => null)`). Run
`mdl ci moodle-block_feedback_tracker --only grunt` before pushing — it is
the CI gate verbatim, and unlike a bare `eslint --fix` it corrects nothing,
so the mechanical offenders (spacing, `async()`) are fixed by hand here.

### Component conventions

- Files in `amd/src/components/*.js` are default-exported function
  components. One component per file. PascalCase filenames match the
  component name.
- Components are **stateless** unless local state is genuinely needed.
  Where state is required, use hooks from the shim
  (`useState`, `useEffect`, `useReducer`, etc.).
- Props match the **existing** payload shape from
  `responsiveness_payload::group_payload()` / `responsiveness_card.php`
  so Phase 2B can feed them without transforms. Don't invent new keys.
- CSS classes are `bft-*` BEM from [`styles.css`](styles.css). No
  inline styles except SVG geometry attributes (cx, r, viewBox, etc.).
- Band colours and slugs are defined once in
  [`amd/src/lib/bands.js`](amd/src/lib/bands.js) and mirror the PHP
  constants in `classes/output/score_gauge.php::BAND_COLOURS`. Keep
  them in lockstep.

### Mount-point convention

Entrypoints find their roots via a `data-bft-<role>-root` attribute and
mount each one — never assume a single root, course pages can host
multiple block instances:

```js
document.querySelectorAll('[data-bft-spike-root]').forEach((el) => {
    render(html`<${App} />`, el);
});
```

### Idempotent init

Mirror the existing pattern from
[`amd/src/responsiveness.js`](amd/src/responsiveness.js):

```js
export const init = () => {
    if (window.bftXxxInitDone) { return; }
    window.bftXxxInitDone = true;
    // ...
};
```

### Web-service calls

Always through [`amd/src/lib/api.js`](amd/src/lib/api.js) — one named
export per WS. Errors flow through `core/notification.exception()` then
re-throw so the caller's UI can react. Don't call `Ajax.call([...])`
inline in a component.

`Ajax.call([...])[0]` resolves a **jQuery promise** — it has `.then()` /
`.catch()` but **no native `.finally()`**. `api.js::call()` wraps the
result in `Promise.resolve(...)` so callers can chain `.finally()` (e.g.
a mount-time loader) without `TypeError: finally is not a function`.

### Build artefacts

Every new `amd/src/**/*.js` file must have its `amd/build/**/*.min.js`
counterpart committed in the same PR. Build with
`mdl grunt m501 blocks/feedback_tracker`.

`amd/build/**` is **tracked** in git (not gitignored) — Moodle serves the
compiled bundle, not `amd/src`. When resolving a cherry-pick conflict,
never drop the `.min.js` / `.map` files; a source-only fix won't take
effect. Compiled output is branch-agnostic: `git checkout <branch> --
amd/build/...` is a valid way to restore it.

### Dev loop

- The stacks already serve `amd/src/*.js` directly, so an edit is live on
  reload — rebuild only before committing.
- Visit `/blocks/feedback_tracker/pages/spike_react.php` as site admin
  (e.g. http://localhost:8501/…) for the canonical smoke test: it mounts
  every shared component.

### Forward migration (Moodle 5.2+)

When the plugin's required Moodle version bumps to 5.2+ (which ships
React natively via `react`/`react-dom` import-map specifiers), the
migration is mechanical:

- `js/vendor/bft-vendor-*.min.js` → delete.
- `amd/src/lib/preact.js` → re-export from `react` / `react/jsx-runtime`.
- Move `amd/src/components/*.js` → `js/esm/src/components/*.tsx`,
  optionally rename `html\`...\`` to JSX (htm still works in 5.2).
- `$PAGE->requires->js(..., true)` of the bundle → remove.
- Spike page's raw mount-point divs → `{{#react}}` Mustache helper.

Component logic, hook usage, props shapes, and CSS classes stay the same.

## CI workflow

The plugin uses **moodle-an-hochschulen/moodle-workflows** as a reusable
workflow. [`.github/workflows/ci.yml`](.github/workflows/ci.yml) calls it
once per supported Moodle branch (see the Commands section for what it
gates on). It runs on every push/PR with no protected-ref gate — unlike the
previous catalyst workflow, whose gate silently **skipped every job** on
pushes to unprotected refs (historical "passing" runs on unprotected
branches were skip-successes), and whose snapshot images could not run
plugin Behat at all (no chrome behat profile in the snapshot config, no
`MOODLE_START_BEHAT_SERVERS`, `behat.yml` generated before the plugin was
copied in — the reason `disable_behat` was set here for a while).

The plugin is its **own git repo** (branch `main`) at
`~/dev/moodle-block_feedback_tracker`, a sibling of the stack checkouts
rather than a directory inside one. The stacks' branches
(`MOODLE_405_STABLE` / `MOODLE_501_STABLE`) are core's, unrelated to this
repo's; git run from a stack checkout never sees plugin changes.

`version.php` **diverges by branch on purpose**: `main` carries
`$plugin->supported = [405, 502]`, `MOODLE_501_STABLE` carries `[501, 501]`.
Cherry-picking a version bump conflicts on that line — keep each branch's own
`supported` when resolving. **`.github/workflows/ci.yml` diverges the same
way**: `main` makes one reusable-workflow call per supported core branch,
while `MOODLE_XX_STABLE` plugin branches carry a single call with no
`moodle-core-branch` input (the workflow auto-detects it from the branch
name) — keep each branch's own when resolving cherry-picks.

### Debugging CI failures

- Read a failed job's raw log with
  `gh api repos/uaiblaine/moodle-block_feedback_tracker/actions/jobs/<jobid>/logs`
  (get `<jobid>` from `gh run view <runid> --json jobs`). For the MAH
  reusable-workflow jobs `gh run view --log-failed` often returns empty —
  use the API endpoint. Failures cluster in the "Static checks" job
  (phplint/phpcs/phpdoc/grunt/leftover) since runtime legs rarely break.
- **The whole gate runs locally — don't push to find out whether phpcs
  passes.** The repo dir is `moodle-block_feedback_tracker`; it mounts at
  `blocks/feedback_tracker` on the `m405` and `m501` stacks
  (`$plugin->supported = [405, 502]` keeps it off `m53`). This plugin's
  invocations:

  ```sh
  mdl ci moodle-block_feedback_tracker --only phpcs,phpdoc   # fast static pass
  mdl ci moodle-block_feedback_tracker --only grunt          # ESLint + Stylelint
  mdl ci moodle-block_feedback_tracker                       # full static + PHPUnit
  mdl ci moodle-block_feedback_tracker --behat               # add the Behat leg
  mdl phpunit m501 block_feedback_tracker                    # this plugin's testsuite
  mdl behat m501 @block_feedback_tracker                     # this plugin's scenarios
  mdl grunt m501 blocks/feedback_tracker                     # rebuild amd/build
  ```

- `mdl ci` defaults to the 5.1 leg (`--branch MOODLE_501_STABLE --php 8.3
  --db pgsql`) and skips Behat unless asked. Because `supported` starts at
  405, anything version-conditional needs the 4.5 leg run too:
  `mdl ci moodle-block_feedback_tracker --branch MOODLE_405_STABLE`. The
  MariaDB leg (`--db mariadb`) is worth a run whenever the change touches
  SQL — CI runs it only on the highest branch.

## When in doubt

Follow the patterns in existing files. The codebase is internally
consistent — if a new file feels like it doesn't match any existing
shape, that's a signal to re-examine the approach.
