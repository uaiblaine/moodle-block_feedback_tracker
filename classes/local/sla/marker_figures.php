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
 * Per-marker figures for the group drill-down.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * One line per marker, read from the slot table ({@see slot_ledger}), for the
 * drill-down's Markers section (owner decision D3).
 *
 * Figures about other markers are shown only for the assign activities where
 * the viewer may manage marker allocations (`mod/assign:manageallocations`).
 * The check is per activity, in the module context where core checks it, and
 * on the activity the viewer can see: a module-level prohibit or override, or
 * a hidden activity, hides that activity's marker names and turnaround, and a
 * grant on one module alone shows that module only (a course-level grant
 * reaches every module by inheritance). The gate is here, so no caller can
 * show the figures without it. The rows are the drill-down's: the course, the viewer's visible groups
 * ({@see group_access::visible_group_ids()}) and the page's group filter, and
 * submitted work only, as every SLA read.
 *
 * Per marker:
 * - open slots: live slots (no completion, not removed) on a pending cycle,
 *   the current cycle of the latest attempt with no recorded response;
 * - marks completed: slots with a first "Marking completed", every cycle;
 * - median own turnaround over those, in effective hours, or in business days
 *   when the display unit is business days (the stored day count, never hours
 *   divided);
 * - coverage: the share of completed slots whose allocation instant was
 *   recorded as it happened (observed or carried) rather than found by the
 *   reconciler.
 *
 * On Moodle 5.2 every completion instant is found by the reconciler (the
 * last edit of the marker's mark row, {@see slot_ledger::complete_slot()}),
 * and so is every removal, so a marker's open slots and completed marks lag
 * core by up to one sweep period. Coverage still speaks of the allocation
 * instant only.
 */
final class marker_figures {
    /**
     * The Markers section of one drill-down page, or null when it is not shown.
     *
     * Not shown when the viewer may manage allocations in no activity they can
     * see, sees no group of the course, or the rows in scope hold no slot row (no multi-marking work).
     *
     * @param int $courseid
     * @param int $groupid The page's group filter, 0 for every visible group.
     * @param int $viewerid The user viewing the page.
     * @param bool $usedays True when display_time_unit is business_days.
     * @return array|null List of rows sorted by marker name, each with markerid, name, open,
     *                    completed, median (float or null), coverage (int percent or null),
     *                    mediantext and coveragetext.
     */
    public static function for_page(int $courseid, int $groupid, int $viewerid, bool $usedays): ?array {
        global $DB;

        $cmids = [];
        foreach (get_fast_modinfo($courseid, $viewerid)->get_instances_of('assign') as $cm) {
            if ($cm->uservisible && has_capability('mod/assign:manageallocations', $cm->context, $viewerid)) {
                $cmids[] = (int) $cm->id;
            }
        }
        if (empty($cmids)) {
            return null;
        }
        $visible = group_access::visible_group_ids($courseid, $viewerid);
        if ($visible !== null && empty($visible)) {
            return null;
        }

        [$csql, $cparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'mfc');
        $where = "s.courseid = :courseid AND s.cmid $csql AND l.submissionstatus = :substatus";
        $params = ['courseid' => $courseid, 'substatus' => submission_status::SUBMITTED] + $cparams;
        if ($visible !== null) {
            [$gsql, $gparams] = $DB->get_in_or_equal($visible, SQL_PARAMS_NAMED, 'mfg');
            $where .= " AND l.groupid $gsql";
            $params += $gparams;
        }
        if ($groupid > 0) {
            $where .= ' AND l.groupid = :groupid';
            $params['groupid'] = $groupid;
        }

        $counts = $DB->get_records_sql(
            "SELECT s.marker,
                    SUM(CASE WHEN s.timemarked IS NULL AND s.timeremoved IS NULL
                              AND l.iscurrent = 1 AND l.islatest = 1 AND l.timegraded IS NULL
                             THEN 1 ELSE 0 END) AS openslots,
                    SUM(CASE WHEN s.timemarked IS NOT NULL THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN s.timemarked IS NOT NULL AND s.allocsource IN (:observed, :carried)
                             THEN 1 ELSE 0 END) AS covered
               FROM {block_feedback_tracker_alloc} s
               JOIN {block_feedback_tracker_sub} l ON l.id = s.subid
              WHERE $where
           GROUP BY s.marker",
            $params + [
                'observed' => slot_ledger::SOURCE_OBSERVED,
                'carried' => slot_ledger::SOURCE_CARRIED,
            ]
        );
        if (empty($counts)) {
            return null;
        }

        $measure = $usedays ? 'markdays' : 'markhours';
        $values = [];
        $rs = $DB->get_recordset_sql(
            "SELECT s.id, s.marker, s.$measure AS measure
               FROM {block_feedback_tracker_alloc} s
               JOIN {block_feedback_tracker_sub} l ON l.id = s.subid
              WHERE $where
                AND s.timemarked IS NOT NULL
                AND s.$measure IS NOT NULL",
            $params
        );
        foreach ($rs as $r) {
            $values[(int) $r->marker][] = (float) $r->measure;
        }
        $rs->close();

        $users = $DB->get_records_list(
            'user',
            'id',
            array_map('intval', array_keys($counts)),
            '',
            'id, ' . implode(', ', \core_user\fields::get_name_fields())
        );

        $rows = [];
        foreach ($counts as $markerid => $c) {
            $markerid = (int) $markerid;
            $completed = (int) $c->completed;
            $median = stats::median($values[$markerid] ?? []);
            $coverage = $completed > 0 ? (int) round(100 * (int) $c->covered / $completed) : null;
            $rows[] = [
                'markerid' => $markerid,
                'name' => isset($users[$markerid]) ? fullname($users[$markerid]) : get_string('deleteduser', 'bulkusers'),
                'open' => (int) $c->openslots,
                'completed' => $completed,
                'median' => $median,
                'coverage' => $coverage,
                'mediantext' => self::median_text($median, $usedays),
                'coveragetext' => $coverage !== null ? get_string('percents', 'moodle', $coverage) : '-',
            ];
        }
        usort($rows, [self::class, 'compare_rows']);
        return $rows;
    }

    /**
     * Order two rows by marker name, then by id so equal names stay stable.
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    private static function compare_rows(array $a, array $b): int {
        $byname = strcmp(\core_text::strtolower($a['name']), \core_text::strtolower($b['name']));
        return $byname !== 0 ? $byname : $a['markerid'] <=> $b['markerid'];
    }

    /**
     * Text of a median turnaround in the display unit, "-" when there is none.
     *
     * @param float|null $median Hours, or business days when $usedays.
     * @param bool $usedays True when display_time_unit is business_days.
     * @return string
     */
    private static function median_text(?float $median, bool $usedays): string {
        if ($median === null) {
            return '-';
        }
        if ($usedays) {
            $decimals = floor($median) === $median ? 0 : 1;
            return get_string('drilldown_value_days', 'block_feedback_tracker', format_float($median, $decimals));
        }
        return get_string('drilldown_value_hours', 'block_feedback_tracker', format_float($median, 1));
    }
}
