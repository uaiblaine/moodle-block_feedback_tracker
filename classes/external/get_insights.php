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
 * External: dashboard insights — bright spot, most improved, gentle watch.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\calendar\calendar;
use block_feedback_tracker\local\output\numfmt;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns up to three insight rows for the dashboard hero callouts: a bright
 * spot (top-scoring group), the most improved group (week-over-week momentum
 * first, else the 30-day trend) and a gentle watch (group with the most
 * critical-band pending submissions). A key is omitted when no row qualifies.
 *
 * Results are cached for 900 s per calver, user, scope mode and language, so
 * a settings save that bumps calver re-keys the cache.
 */
class get_insights extends external_api {
    /** Cache TTL in seconds. */
    public const CACHE_TTL = 900;

    /** Cache-key version. Bump when the result shape or the formatting of its values changes. */
    public const CACHE_KEY_VERSION = 5;

    /**
     * Parameters — no inputs.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Run.
     *
     * @return array
     */
    public static function execute(): array {
        global $USER;

        $sysctx = \context_system::instance();
        self::validate_context($sysctx);

        // Authorisation + scope via dashboard_scope so the insight pool
        // matches the courses table the user is already looking at.
        $userid = (int) $USER->id;
        $scope = \block_feedback_tracker\local\sla\dashboard_scope::visible_course_ids($userid);
        if ($scope !== null && empty($scope)) {
            throw new \required_capability_exception(
                $sysctx,
                'block/feedback_tracker:viewdashboard',
                'nopermissions',
                'error'
            );
        }

        $cache = \cache::make('block_feedback_tracker', 'dashboard_payload');
        // Language is part of the key because the metric suffixes are
        // localised and the course and group names filtered server-side.
        $key = 'insights_v' . self::CACHE_KEY_VERSION
            . '_' . calendar::current_version()
            . '_' . $USER->id
            . '_' . ($scope === null ? 'all' : 'scoped')
            . '_' . current_language();
        $cached = $cache->get($key);
        if (
            $cached !== false && is_array($cached)
            && isset($cached['lastsynced'])
            && (time() - (int) $cached['lastsynced']) < self::CACHE_TTL
        ) {
            return $cached;
        }

        $rows = self::source_rows($userid);
        $brightspot = self::pick_bright_spot($rows);
        $mostimproved = self::pick_most_improved($rows);
        $gentlewatch = self::pick_gentle_watch($rows);

        // Omit null insight keys entirely — external_single_structure with
        // VALUE_OPTIONAL on the field allows missing keys but not literal
        // null. The JS view treats absence as "no insight to show".
        $result = [
            'success'    => true,
            'lastsynced' => time(),
        ];
        if ($brightspot !== null) {
            $result['bright_spot'] = $brightspot;
        }
        if ($mostimproved !== null) {
            $result['most_improved'] = $mostimproved;
        }
        if ($gentlewatch !== null) {
            $result['gentle_watch'] = $gentlewatch;
        }
        $cache->set($key, $result);
        return $result;
    }

    /**
     * Pull the per-(course, group) rollup rows the caller can see, with the
     * course name joined in. Visibility is delegated to dashboard_scope so
     * the insight pool matches get_dashboard's courses table exactly.
     *
     * @param int $userid
     * @return array<int, \stdClass>
     */
    private static function source_rows(int $userid): array {
        global $DB;
        [$where, $params] = \block_feedback_tracker\local\sla\dashboard_scope::sql_visibility(
            $userid,
            'g.courseid',
            'g.groupid',
            'ic'
        );
        if ($where === \block_feedback_tracker\local\sla\dashboard_scope::MATCH_NONE) {
            return [];
        }
        $sql = "SELECT g.id, g.courseid, g.groupid, c.fullname AS coursename,
                       grp.name AS groupname,
                       g.responsiveness_score, g.score_band, g.trend_pct_30d,
                       g.median_eff_h, g.critical, g.pending, g.numgraded30d
                  FROM {block_feedback_tracker_group} g
                  JOIN {course} c ON c.id = g.courseid
             LEFT JOIN {groups} grp ON grp.id = g.groupid
                 WHERE $where";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Bright spot — the highest-scoring group, with the oldest tie broken
     * by larger numgraded30d. Returns null when no group has a score yet.
     *
     * @param array $rows
     * @return array|null
     */
    private static function pick_bright_spot(array $rows): ?array {
        $scored = array_values(array_filter($rows, static fn ($r) => $r->responsiveness_score !== null));
        if (empty($scored)) {
            return null;
        }
        usort($scored, static function ($a, $b) {
            return (float) $b->responsiveness_score <=> (float) $a->responsiveness_score;
        });
        $top = $scored[0];
        $score = (float) $top->responsiveness_score;
        return self::identity($top) + [
            'metric_value' => (string) round($score),
            'metric_suffix' => get_string('insight_outof100', 'block_feedback_tracker'),
        ];
    }

    /**
     * Most improved — preferring sharp week-over-week recoveries
     * (momentum) when present, falling back to the largest negative
     * trend_pct_30d otherwise.
     *
     * Momentum is dashboard-only and never feeds the score. It recognises a
     * teacher who turns a slow class around in days, before the 30-day trend
     * has caught up. The picked row carries a `momentum` flag so
     * DashboardView can swap the card's eyebrow and body text.
     *
     * @param array $rows
     * @return array|null
     */
    private static function pick_most_improved(array $rows): ?array {
        $thresh = \block_feedback_tracker\local\score\responsiveness_calculator::MOMENTUM_TRIGGER_PCT;

        // First pass: any group with sharp momentum wins, no matter what the
        // 30-day trend looks like. Momentum needs MOMENTUM_MIN_GRADES grades
        // in the last 7 days, a window inside the numgraded30d count, so a
        // group with fewer than that in the rollup window cannot qualify.
        // Skipping those saves the two ledger queries per group that
        // momentum_pct() issues. The filter drops only rows momentum_pct()
        // would return null for while trend_window_days is at least 7 and the
        // rollup row is current.
        $mingrades = \block_feedback_tracker\local\score\responsiveness_calculator::MOMENTUM_MIN_GRADES;
        $best = null;
        $bestpct = 0.0;
        foreach ($rows as $r) {
            if ((int) $r->numgraded30d < $mingrades) {
                continue;
            }
            $pct = \block_feedback_tracker\local\score\responsiveness_calculator::momentum_pct(
                (int) $r->courseid,
                (int) $r->groupid
            );
            if ($pct === null || $pct >= $thresh) {
                continue;
            }
            if ($best === null || $pct < $bestpct) {
                $best = $r;
                $bestpct = $pct;
            }
        }
        if ($best !== null) {
            return self::identity($best) + [
                'metric_value'  => '▲ ' . (string) round(abs($bestpct)) . '%',
                'metric_suffix' => get_string('insight_faster_week_suffix', 'block_feedback_tracker'),
                'momentum'      => true,
            ];
        }

        // Fallback: largest 30-day improvement.
        $trended = array_values(array_filter(
            $rows,
            static fn ($r) => $r->trend_pct_30d !== null && (float) $r->trend_pct_30d < -2.0
        ));
        if (empty($trended)) {
            return null;
        }
        usort($trended, static function ($a, $b) {
            return (float) $a->trend_pct_30d <=> (float) $b->trend_pct_30d;
        });
        $top = $trended[0];
        $pct = (float) $top->trend_pct_30d;
        return self::identity($top) + [
            'metric_value'  => '▲ ' . (string) round(abs($pct)) . '%',
            'metric_suffix' => get_string('insight_faster_suffix', 'block_feedback_tracker'),
            'momentum'      => false,
        ];
    }

    /**
     * Gentle watch — the group with the most critical-band pending
     * submissions. Returns null when no group has any critical pending.
     *
     * @param array $rows
     * @return array|null
     */
    private static function pick_gentle_watch(array $rows): ?array {
        $critical = array_values(array_filter(
            $rows,
            static fn ($r) => (int) $r->critical > 0
        ));
        if (empty($critical)) {
            return null;
        }
        usort($critical, static function ($a, $b) {
            return (int) $b->critical <=> (int) $a->critical;
        });
        $top = $critical[0];
        $n = (int) $top->critical;
        return self::identity($top) + [
            'metric_value' => numfmt::count($n),
            'metric_suffix' => get_string('insight_criticalpending', 'block_feedback_tracker'),
        ];
    }

    /**
     * The course and group an insight row names.
     *
     * Names are filtered in the course context but not escaped: PARAM_TEXT and
     * the text nodes the dashboard renders them into escape for themselves.
     * The ungrouped row (groupid 0) has no group name.
     *
     * @param \stdClass $row A source_rows() row.
     * @return array{courseid:int, coursename:string, groupid:int, groupname:string}
     */
    private static function identity(\stdClass $row): array {
        $options = ['context' => \context_course::instance((int) $row->courseid), 'escape' => false];
        return [
            'courseid'   => (int) $row->courseid,
            'coursename' => format_string((string) $row->coursename, true, $options),
            'groupid'    => (int) $row->groupid,
            'groupname'  => format_string((string) ($row->groupname ?? ''), true, $options),
        ];
    }

    /**
     * Returns shape — three optional insight rows, each omitted (never null)
     * when nothing qualifies.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $insight = new external_single_structure([
            'courseid'      => new external_value(PARAM_INT, ''),
            'coursename'    => new external_value(PARAM_TEXT, ''),
            'groupid'       => new external_value(PARAM_INT, ''),
            'groupname'     => new external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
            'metric_value'  => new external_value(PARAM_TEXT, ''),
            'metric_suffix' => new external_value(PARAM_TEXT, ''),
            /* True when this row was picked from week-over-week momentum
             * rather than the 30-day trend. Optional because the bright_spot
             * and gentle_watch picks never set it. */
            'momentum'      => new external_value(PARAM_BOOL, '', VALUE_OPTIONAL),
        ], '', VALUE_OPTIONAL);
        return new external_single_structure([
            'success'       => new external_value(PARAM_BOOL, ''),
            'lastsynced'    => new external_value(PARAM_INT, ''),
            'bright_spot'   => $insight,
            'most_improved' => $insight,
            'gentle_watch'  => $insight,
        ]);
    }
}
