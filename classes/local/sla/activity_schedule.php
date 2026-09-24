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
 * Per-group assign open/close schedule + override-action resolver.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Builds the list of `mod_assign` activities for a course and resolves, per
 * group, the effective open/close dates plus the action a teacher can take in
 * the group-override editor.
 *
 * The catalog (assign global dates, group mode, manage capability, group
 * overrides) is built once per course render via {@see catalog_for_course()};
 * {@see for_group()} then resolves every (assign, group) pair in memory, so a
 * page of many group cards costs a fixed handful of queries rather than one per
 * pair. Date resolution reuses {@see rule_resolver::merge_override()} so the
 * group-override-wins priority stays defined in one place.
 *
 * Action model, first match wins (canmanage = the viewing user holds
 * mod/assign:manageoverrides on the module). Each row also carries an
 * `editable` flag (= canmanage); the chip links to the override editor only
 * when editable, otherwise it is a static, informational badge:
 *   - !canmanage, any effective schedule               → 'done'   (no link)
 *   - !canmanage, no schedule                          → 'norule' (no link)
 *   - a non-zero group override exists for the group   → 'done'
 *   - a global rule exists and the assign is SEPARATEGROUPS → 'override'
 *   - a global rule exists (non-separate groups)       → 'done'
 *   - no dates anywhere                                → 'create'
 */
class activity_schedule {
    /**
     * Build the course-level assign catalog plus a group-override map. Computed
     * once per course render and fed to {@see for_group()} for each group.
     *
     * @param \stdClass $course Course record.
     * @param int $userid Viewing user, whose manageoverrides capability is tested.
     * @return array{items: array<int, \stdClass>, overrides: array<int, array<int, \stdClass>>}
     */
    public static function catalog_for_course(\stdClass $course, int $userid): array {
        global $DB;

        $modinfo = get_fast_modinfo($course, $userid);
        $cms = $modinfo->get_instances_of('assign');
        if (empty($cms)) {
            return ['items' => [], 'overrides' => []];
        }

        $instanceids = [];
        foreach ($cms as $cm) {
            $instanceids[] = (int) $cm->instance;
        }

        // Global open/due/cutoff for every assign in the course in one read.
        $assignrows = $DB->get_records_list(
            'assign',
            'id',
            $instanceids,
            '',
            'id, allowsubmissionsfromdate, duedate, cutoffdate'
        );

        $items = [];
        foreach ($cms as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $instance = (int) $cm->instance;
            $arow = $assignrows[$instance] ?? null;
            if ($arow === null) {
                continue;
            }
            $item = new \stdClass();
            $item->cmid = (int) $cm->id;
            $item->instance = $instance;
            $item->name = (string) $cm->name;
            $item->groupmode = (int) groups_get_activity_groupmode($cm, $course);
            $item->canmanage = has_capability('mod/assign:manageoverrides', $cm->context, $userid);
            $item->assign = $arow;
            $items[] = $item;
        }

        // Group overrides (a set groupid, null userid) for these assigns in
        // one read, keyed by assign id then group id for in-memory resolution.
        $overrides = [];
        if (!empty($items)) {
            [$insql, $inparams] = $DB->get_in_or_equal($instanceids, SQL_PARAMS_NAMED, 'aid');
            $ovrrows = $DB->get_records_select(
                'assign_overrides',
                'assignid ' . $insql . ' AND groupid IS NOT NULL',
                $inparams,
                '',
                'id, assignid, groupid, allowsubmissionsfromdate, duedate, cutoffdate'
            );
            foreach ($ovrrows as $o) {
                $overrides[(int) $o->assignid][(int) $o->groupid] = $o;
            }
        }

        return ['items' => $items, 'overrides' => $overrides];
    }

    /**
     * Resolve the per-group activity rows from a pre-built catalog.
     *
     * @param array $catalog Pre-built catalog: items + per-group overrides.
     * @param int $groupid Real group id.
     * @return array<int, array{cmid:int, name:string, opens:?int, closes:?int, action:string, editable:bool}>
     */
    public static function for_group(array $catalog, int $groupid): array {
        $out = [];
        foreach ($catalog['items'] as $item) {
            $override = $catalog['overrides'][$item->instance][$groupid] ?? null;
            $merged = rule_resolver::merge_override($item->assign, $override);
            $out[] = [
                'cmid'     => $item->cmid,
                'name'     => $item->name,
                'opens'    => $merged['timeopens'],
                'closes'   => $merged['timecloses'],
                'action'   => self::action_for($item, $override),
                'editable' => $item->canmanage,
            ];
        }
        return $out;
    }

    /**
     * Decide the action chip for one (assign item, optional group override).
     *
     * @param \stdClass $item Catalog item (assign defaults + groupmode + canmanage).
     * @param \stdClass|null $override Group override row, or null.
     * @return string One of 'norule', 'done', 'override', 'create'.
     */
    private static function action_for(\stdClass $item, ?\stdClass $override): string {
        $hasoverride = self::has_dates($override);
        $hasglobal = self::has_dates($item->assign);
        if (!$item->canmanage) {
            // No edit rights: informational only, rendered without a link.
            return ($hasoverride || $hasglobal) ? 'done' : 'norule';
        }
        if ($hasoverride) {
            return 'done';
        }
        if ($hasglobal && $item->groupmode === SEPARATEGROUPS) {
            return 'override';
        }
        if ($hasglobal) {
            return 'done';
        }
        return 'create';
    }

    /**
     * Whether a row carries any non-zero open / due / cutoff date.
     *
     * @param \stdClass|null $row
     * @return bool
     */
    private static function has_dates(?\stdClass $row): bool {
        if ($row === null) {
            return false;
        }
        return ((int) ($row->allowsubmissionsfromdate ?? 0)) > 0
            || ((int) ($row->duedate ?? 0)) > 0
            || ((int) ($row->cutoffdate ?? 0)) > 0;
    }
}
