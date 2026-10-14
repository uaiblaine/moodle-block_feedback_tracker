# Deferred / future features

Items captured during MVP3 design exploration that aren't shipping in the
1.0.x line. Each section lists what the design proposed, why it's deferred,
and the minimum implementation surface a future maintainer would need.

## On-goal academic days (dashboard hero)

**Design intent.** The teacher dashboard's warm hero shows a dotted row —
one dot per academic day this month, filled with a checkmark on days the
teacher hit their feedback goal. Caption reads "12 / 20 on-goal academic
days this month — no streak to break, every day counts on its own."

**Status.** Deferred from MVP3. The visual is documented; the data
infrastructure isn't built.

**Implementation surface.**

1. **New table** — `block_feedback_tracker_daygoal` keyed on
   `(userid, day_ymd)` with `(num_graded, num_within_sla, on_goal)`
   columns. `on_goal` is a boolean evaluated at end-of-day rollup time.
2. **New scheduled task** — `\block_feedback_tracker\task\compute_daygoals`
   running once per day after midnight in the platform timezone. Snapshots
   the previous day from `_sub` rows where `userid_grader = $u` and
   `day_ymd = yesterday`.
3. **New WS** — `block_feedback_tracker_get_on_goal_days({month_ymd})`
   returning `{days: [{ymd, on_goal}], goal: int}`. Capability:
   `viewdashboard`.
4. **New admin setting** — `daygoal_target` (default `20`) for the
   per-month goal.
5. **JS** — extend [`ResponsivenessHero.js`](../amd/src/components/ResponsivenessHero.js)
   with a dotted-row sub-component (`OnGoalDays.js`) accepting
   `{days, target}`. Hidden when feature flag off.
6. **Feature flag** — gate behind an admin checkbox
   (`enable_on_goal_days`, default off) until the daily task has been
   running long enough on a site to back-fill a meaningful first month.

**Why deferred.** Daily snapshots require a new table + cron pipeline + a
fresh ingest path. The other 3E surfaces (hero / insights / priority cards
/ courses table) deliver the design's visual intent without this
infrastructure. On-goal-days is a natural 1.1.x story once MVP3 has
soaked.

## Audit log as a React view

**Design intent.** The current [`pages/audit_log.php`](../pages/audit_log.php)
is server-rendered Mustache. A future React port would gain the same
filter / paginate / row-detail affordances the pending report already has.

**Status.** Deferred. Phase 3F lands a `get_audit_log` WS (paginated)
that unblocks the migration, but the React view itself ships in 1.0.7+
once the page's filter UX is designed.

## Mobile responsive overhaul

**Design intent.** The design mock is desktop-first; the existing CSS has
media queries at 540px / 900px / 1024px but they're per-component
shortcuts, not a coherent mobile reflow.

**Status.** Deferred. The current breakpoints prevent layout breakage;
a true small-screen reflow (sidebar block becoming bottom-sheet,
hero collapsing into a strip, table → cards) is a separate workstream.

## Self-hosted Manrope + JetBrains Mono

**Design intent.** Design uses Manrope (display) and JetBrains Mono
(numerals). Plugin currently lists them as the first font in the CSS
stack but doesn't ship the woff2 files — users without the fonts
installed get system-ui as the fallback.

**Status.** Deferred. Self-hosting adds ~150KB of compressed font files,
declaration entries in [thirdpartylibs.xml](../thirdpartylibs.xml), and a
`styles/fonts.css` shim loaded via `$PAGE->requires->css()`. Mechanical
work; lowest priority because the system-font fallback already reads
cleanly.

## Migration to Moodle 5.2's native React subsystem

**Design intent.** Once every supported branch is Moodle 5.2 or later, the
vendored Preact + htm bundle can be retired and the client rebuilt on the
React that core ships through its import map.

**Status.** Deferred while `MOODLE_405_STABLE` is supported (Moodle 4.5 LTS
security support runs to October 2027) and `MOODLE_501_STABLE` takes fixes:
until then every branch keeps Preact, so one JS tree serves them all and UI
fixes carry across branches unchanged. The migration is a port of the client
from AMD to `js/esm`, not a change to the shim; what it involves is in
[CLAUDE.md](../CLAUDE.md) under "Forward migration (Moodle 5.2+)".
