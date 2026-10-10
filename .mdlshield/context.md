# Review context for block_feedback_tracker

`block_feedback_tracker` ("Feedback Flow") measures how long teachers take to respond to
`mod_assign` submissions, counting business and academic time rather than wall-clock time. It
keeps a per-submission ledger fed by `mod_assign`, gradebook, enrolment and group events,
rolls it up per course and group, and shows the result in a course block, a pending-work
report, a group drill-down and a cross-course teacher dashboard. It is a block plugin that
supports Moodle 4.5 through 5.2 on one branch, **with tables of its own** (ten, all prefixed
`block_feedback_tracker_`).

## Who is trusted

- Site administrators are fully trusted. The view-all dashboard, the teacher score simulator
  and hidden-course processing are admin-only settings.
- 11 capabilities. Course context: `addinstance`, `myaddinstance`, `viewresponsiveness` and
  `viewdashboard` (the last two default to teacher, editing teacher and manager). System
  context: `viewalldata` (no archetype, opt-in), `viewschoolcomparison` (manager; editing
  teacher explicitly prevented), `managecalendar` (manager, `RISK_CONFIG`),
  `managepausewindows` (manager, editing teacher), `resetdata` and `bulkmanageblocks` (manager,
  both `RISK_DATALOSS`) and `viewaudit` (manager).
- Students have no surface. Everything shown concerns students' submissions and a teacher's
  response times, so the exposure to check is a teacher reading a course or group they may not see.
- Group names, course names, activity names and assignment content are untrusted input.

## Surfaces

- 17 web services in `classes/external/`, all `ajax`, session-based. By capability in
  `services.php`: `viewresponsiveness` (6: `get_responsiveness`, `get_pending_submissions`,
  `get_graded_submissions`, `get_report_scopes`, `get_academic_days`, `get_pause_timeline`),
  `managecalendar` (4: `get_calendar`, `save_calendar_day`, `bulk_import_calendar`,
  `save_business_hours`), `managepausewindows` (2: `save_pause_window`,
  `delete_pause_window`), `viewdashboard` (3: `get_dashboard`, `get_insights`,
  `get_grader_priority_list`), `viewschoolcomparison` (1) and `viewaudit` (1).
- Course-scoped services derive the context from the course id (or from the stored row) and
  call `validate_context()` and `require_capability()`. The three dashboard services have no
  `require_capability()` of their own: `dashboard_scope::visible_course_ids()` authorises them
  and `sql_visibility()` filters their rows. `get_calendar` also admits a `viewdashboard` holder.
- Page scripts in `pages/`: `pending_report.php` and `group_drilldown.php` (course capability),
  `teacher_dashboard.php` and `score_simulator.php` (dashboard scope), `calendar_editor.php`,
  `audit_log.php`, `reset.php` and `bulk_remove.php` (system capabilities) and `spike_react.php`
  (site administrators only). The calendar editor and bulk removal check the session key.
- 10 scheduled and 6 adhoc tasks (bulk block removal, discarding a course's data, backfill,
  recompute, attribution and allocation repairs) and event observers on assign, gradebook,
  course, enrolment, user and group events.
- `cli/*.php` maintenance scripts (`CLI_SCRIPT`). No `pluginfile` callback, no file storage
  and no outbound HTTP. The calendar CSV import (a textarea, parsed by `csv_importer`) is the
  only free-text parser, and the report search term is bound as an escaped `LIKE` parameter.
- Privacy provider: metadata, plugin, userlist and user preference providers. The ledger is
  exported and deleted per course; calendar and audit rows at system context keep the row and
  clear the user attribution. Rollup, trend, site, queue and cursor tables hold no user link.

## Facts that look like findings but are by design

- **Group visibility has one decision point**, `local\sla\group_access` (and `dashboard_scope`
  for the dashboard). It follows the course's group mode and `moodle/site:accessallgroups`,
  never an activity's mode. A new read of ledger or rollup rows that bypasses it is a finding;
  a course capability alone is not enough.
- **A site administrator is scoped like a teacher on the dashboard** (`doanything` is off in
  `dashboard_scope`); the full view needs `viewalldata` or the `enable_admin_view_all` setting.
- **Opt-in per course.** `course_access::is_processable()` is true only when a block instance
  sits on the course's own context and the course is visible (unless an admin setting says
  otherwise). Write paths call it; cleanup paths (course, activity, enrolment or user deleted)
  deliberately do not, so data is still removed. Removing the block starts a grace period
  (`removal_grace`) before its data is discarded, and ledger pruning is off by default.
- **Only submitted work counts.** Drafts and new or reopened rows are stored but filtered at
  read time by `submission_status`; every read over the ledger binds that filter.
- **Web service return structures are an allowlist** (`clean_returnvalue()` strips undeclared
  keys). Names are passed through `format_string(..., ['escape' => false])` before they enter
  a `PARAM_TEXT` field or a double stash; a raw name in a triple stash is a finding.

## De-emphasise

- `amd/build/**` is minified output of `amd/src/**`; review the source. `js/vendor/**` is a
  third-party Preact bundle listed in `thirdpartylibs.xml`.
- `docs/**`, `lang/**` and `tests/**` carry no production behaviour.
- Visual details of `styles.css` and the templates, unless they show data the viewer should not see.
