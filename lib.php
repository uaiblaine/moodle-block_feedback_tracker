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
 * Helper hooks for Feedback Flow.
 *
 * Defines callbacks the settings page can wire as `set_updatedcallback()`
 * targets plus the reset routine used by the admin Tools page.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_feedback_tracker\local\audit\recompute_log;
use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\calendar\calendar;
use block_feedback_tracker\local\sla\dirty_queue;

/**
 * Returns true when the current request is part of an install / upgrade /
 * plugin-bootstrap flow where touching plugin tables or MUC stores is unsafe.
 *
 * Three signals (any one is sufficient):
 *
 *  1. `during_initial_install()` — first-run install of the Moodle core.
 *  2. `$CFG->upgraderunning` — set by upgrade_started() and held throughout
 *     `upgrade_noncore()`. Plugin install + `admin_apply_default_settings()`
 *     run inside this window, so any updated-callback fires under it.
 *  3. The plugin's own tables don't exist yet — belt-and-suspenders for
 *     any path the first two checks miss (CLI uninstall reinstall, partially
 *     restored backup, etc.).
 *
 * @return bool
 */
function block_feedback_tracker_is_bootstrapping(): bool {
    global $CFG, $DB;

    if (during_initial_install()) {
        return true;
    }
    if (!empty($CFG->upgraderunning)) {
        return true;
    }
    try {
        return !$DB->get_manager()->table_exists('block_feedback_tracker_group');
    } catch (\Throwable $e) {
        return true;
    }
}

/**
 * Force a global recompute; the `set_updatedcallback()` of every rule-changing
 * setting in settings.php. Bumps `calver` so the calver-keyed caches roll over,
 * purges the calendar caches, resets per-request memos, enqueues every
 * existing (course, group) rollup, and writes an audit row.
 *
 * Returns early while {@see block_feedback_tracker_is_bootstrapping()}: during
 * install, `admin_apply_default_settings()` fires this callback before the
 * plugin's tables and caches are usable.
 *
 * @return void
 */
function block_feedback_tracker_invalidate_rollups(): void {
    global $DB;

    if (block_feedback_tracker_is_bootstrapping()) {
        return;
    }

    calendar::bump_version();
    academic_time::reset_memos();

    $started = time();
    $count = 0;
    $rs = $DB->get_recordset(
        'block_feedback_tracker_group',
        null,
        '',
        'id, courseid, groupid'
    );
    foreach ($rs as $row) {
        dirty_queue::enqueue((int) $row->courseid, (int) $row->groupid, dirty_queue::REASON_BULK);
        $count++;
    }
    $rs->close();

    try {
        \cache_helper::purge_by_definition('block_feedback_tracker', 'calendar_effective_day');
        \cache_helper::purge_by_definition('block_feedback_tracker', 'pause_windows_by_course');
    } catch (\Throwable $e) {
        debugging('block_feedback_tracker: cache purge failed: ' . $e->getMessage());
    }

    recompute_log::record(
        recompute_log::REASON_MANUAL_RESET,
        $count,
        null,
        ['source' => 'invalidate_rollups', 'calver' => calendar::current_version()],
        $started,
        time()
    );
}

/**
 * Full data reset: drop every ledger / rollup / trend / site / queue row and
 * bump the calendar version. The platform calendar configuration (cday /
 * chours / cpause) is preserved, and so is the recompute audit log: the reset
 * records itself there, and a log it wiped would hold nothing but that entry.
 * Optionally re-enables the backfill task so historic submissions are
 * re-ingested.
 *
 * Caller must hold `block/feedback_tracker:resetdata` (system).
 *
 * @param bool $reenablebackfill Activate the backfill task afterwards.
 * @return array{ledger:int, rollups:int, trends:int, sites:int, queue:int} Rows
 *         deleted from each table.
 */
function block_feedback_tracker_reset_data(bool $reenablebackfill = false): array {
    global $DB;

    if (block_feedback_tracker_is_bootstrapping()) {
        return [
            'ledger' => 0, 'rollups' => 0, 'trends' => 0,
            'sites' => 0, 'queue' => 0,
        ];
    }

    $counts = [
        'ledger'  => (int) $DB->count_records('block_feedback_tracker_sub'),
        'rollups' => (int) $DB->count_records('block_feedback_tracker_group'),
        'trends'  => (int) $DB->count_records('block_feedback_tracker_trend'),
        'sites'   => (int) $DB->count_records('block_feedback_tracker_site'),
        'queue'   => (int) $DB->count_records('block_feedback_tracker_queue'),
    ];

    $DB->delete_records('block_feedback_tracker_sub');
    $DB->delete_records('block_feedback_tracker_group');
    $DB->delete_records('block_feedback_tracker_trend');
    $DB->delete_records('block_feedback_tracker_site');
    $DB->delete_records('block_feedback_tracker_queue');

    calendar::bump_version();
    academic_time::reset_memos();

    try {
        \cache_helper::purge_by_definition('block_feedback_tracker', 'calendar_effective_day');
        \cache_helper::purge_by_definition('block_feedback_tracker', 'pause_windows_by_course');
    } catch (\Throwable $e) {
        debugging('block_feedback_tracker: cache purge failed: ' . $e->getMessage());
    }

    if ($reenablebackfill) {
        set_config('backfill_active', '1', 'block_feedback_tracker');
        // Deleting the per-course cursors makes backfill_history recreate them
        // from the start for every processable course on its next tick.
        $DB->delete_records('block_feedback_tracker_bfcursor');
    }

    recompute_log::record(
        recompute_log::REASON_MANUAL_RESET,
        array_sum($counts),
        isset($GLOBALS['USER']) ? (int) $GLOBALS['USER']->id : null,
        $counts
    );

    return $counts;
}

/**
 * Per-user preferences this plugin exposes via the core
 * user-preferences API.
 *
 * `core_user::can_edit_preference()` rejects any preference no component
 * declares, so these must be listed for `core_user/repository::setUserPreference()`
 * (and `core_user_set_user_preferences`) to store them. The permission callback
 * limits writes to the user's own preference.
 *
 * @return array<string, array{type:string, null:bool, default:string, permissioncallback:callable}>
 */
function block_feedback_tracker_user_preferences(): array {
    return [
        // Collapsed state of the teacher dashboard's combined Responsiveness
        // hero + Insights block, stored as '0' | '1' (preferences are strings).
        'block_feedback_tracker_dashboard_collapsed' => [
            'type'    => PARAM_BOOL,
            'null'    => NULL_NOT_ALLOWED,
            'default' => '0',
            'permissioncallback' => [\core_user::class, 'is_current_user'],
        ],
        // Collapsed state of the report page's combined hero + academic-days
        // strip; same shape as the dashboard one.
        'block_feedback_tracker_report_collapsed' => [
            'type'    => PARAM_BOOL,
            'null'    => NULL_NOT_ALLOWED,
            'default' => '0',
            'permissioncallback' => [\core_user::class, 'is_current_user'],
        ],
    ];
}
