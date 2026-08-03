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
 * Pure data loader for the responsiveness payload.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\payload;

use block_feedback_tracker\local\calendar\calendar;
use block_feedback_tracker\local\calendar\paused_aggregator;
use block_feedback_tracker\local\calendar\upcoming_pauses;
use block_feedback_tracker\local\score\peer_stats;
use block_feedback_tracker\local\sla\activity_schedule;
use block_feedback_tracker\local\sla\bucket;

/**
 * Builds the responsiveness payload (groups array + lastsynced) without
 * touching $PAGE / $OUTPUT. Safe to call from inside `get_content()` on a
 * block (which runs after page output has started) — `external_api::
 * validate_context()` calls `$PAGE->set_context()` which fails at that
 * point, so the WS layer cannot be used directly there.
 *
 * Capability checks remain the caller's responsibility.
 */
class responsiveness_payload {
    /** Session cache TTL in seconds. */
    public const CACHE_TTL = 900;

    /**
     * Build the payload for one course as seen by one user.
     *
     * When $limit is greater than zero the groups are paginated and the result
     * carries total / offset / limit / hasmore so the caller can fetch the
     * next page. $sort orders the whole visible group list server-side, so the
     * first page reflects the true top-priority groups (not just whatever is
     * loaded). $limit = 0 keeps the legacy behaviour: every visible group in
     * one call, hasmore false. overall_score is the pending-weighted mean over
     * the entire visible course, independent of pagination.
     *
     * @param int $courseid
     * @param int $userid
     * @param bool $force Skip the session cache read and write a fresh entry.
     * @param int $limit Page size; 0 returns every visible group.
     * @param int $offset Zero-based offset into the ordered group list.
     * @param string $sort Order key: 'default' (groupid), 'priority', or 'wait'.
     * @return array{success:bool, courseid:int, lastsynced:int, total:int,
     *               offset:int, limit:int, hasmore:bool, overall_score:float|null,
     *               groups:array<int, array>}
     */
    public static function for_course(
        int $courseid,
        int $userid,
        bool $force = false,
        int $limit = 0,
        int $offset = 0,
        string $sort = 'default'
    ): array {
        global $DB;

        $cache = \cache::make('block_feedback_tracker', 'responsiveness_payload');
        // The banding ruler (hours vs business days) swaps the pending-band
        // counts, so it is part of the key — flipping the display unit takes
        // effect on the next fetch instead of waiting out the TTL.
        $key = calendar::current_version() . '_' . $userid . '_' . $courseid
            . (bucket::use_day_thresholds() ? '_d' : '');
        if ($limit > 0) {
            $key .= '_' . $offset . '_' . $limit;
        }
        if ($sort !== 'default') {
            $key .= '_' . $sort;
        }
        if (!$force) {
            $cached = $cache->get($key);
            if (
                is_array($cached)
                && isset($cached['lastsynced'])
                && (time() - (int) $cached['lastsynced']) < self::CACHE_TTL
            ) {
                return $cached;
            }
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $groupmode = (int) groups_get_course_groupmode($course);

        // Delegate the visibility decision (NOGROUPS / accessallgroups /
        // VISIBLEGROUPS / SEPARATEGROUPS) to the shared helper used by the
        // WS endpoints. null = unrestricted; array = named-group whitelist
        // (empty = SEPARATEGROUPS teacher with no group membership →
        // nothing renders).
        $visible = \block_feedback_tracker\local\sla\group_access::visible_group_ids($courseid, $userid);

        // A restricted user with zero visible groups has nothing to render —
        // short-circuit to an empty (but well-formed) paginated result. This
        // also guards get_in_or_equal() below, which rejects an empty set.
        if ($visible === []) {
            $result = [
                'success'    => true,
                'courseid'   => $courseid,
                'lastsynced' => time(),
                'total'      => 0,
                'offset'     => $offset,
                'limit'      => $limit,
                'hasmore'    => false,
                'overall_score' => null,
                'groups'     => [],
            ];
            $cache->set($key, $result);
            return $result;
        }

        // Fold the visibility whitelist into the rollup query so pagination
        // totals + offsets count only rows the caller can see. Unrestricted
        // users (null) match every rollup, including groupid 0 ("ungrouped").
        $where = 'courseid = :courseid';
        $params = ['courseid' => $courseid];
        if ($visible !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($visible, SQL_PARAMS_NAMED, 'grp');
            $where .= ' AND groupid ' . $insql;
            $params = array_merge($params, $inparams);
        }

        $total = $DB->count_records_select('block_feedback_tracker_group', $where, $params);

        // Whole-course overall score (pending-weighted mean of per-group
        // scores, mirroring the JS overallScore()) so the block's banner
        // stays accurate no matter how many pages are loaded. Single indexed
        // aggregate over the visible rows; the result is MUC-cached per page.
        $overallscore = self::overall_score($where, $params);

        $rollups = $DB->get_records_select(
            'block_feedback_tracker_group',
            $where,
            $params,
            self::order_by_for_sort($sort),
            '*',
            $offset,
            $limit
        );

        // Resolve display names for ONLY this page's real groups (gid > 0).
        // Naming the whole course on every page is O(total) and was a major
        // cost on courses with thousands of groups; page-scoping keeps it
        // O(batch).
        $pagegroupids = [];
        foreach ($rollups as $r) {
            $gid = (int) $r->groupid;
            if ($gid > 0) {
                $pagegroupids[] = $gid;
            }
        }
        $groupnames = [];
        if (!empty($pagegroupids)) {
            $namerows = $DB->get_records_list('groups', 'id', $pagegroupids, '', 'id, name');
            foreach ($namerows as $nr) {
                $groupnames[(int) $nr->id] = (string) $nr->name;
            }
        }
        // Composed display titles + subtitles, driven by the
        // group_title_fields / group_subtitle_fields custom-field settings.
        $grouptitles = self::resolve_group_titles($groupnames);

        // Pre-compute the trend-sparkline window once. 14 days (two weeks)
        // frames the rolling 7-day-vs-prior-7-day comparison and fits the
        // block's narrow sparkline; the recent-stats window stays 30 days.
        $trendwindow = self::trend_window(14);

        // Course-level paused aggregate for the last 30 days. One call
        // per render — the design's PausedNote + report-page callout
        // share the same numbers.
        $now = time();
        $pausedwindowstart = $now - 30 * 86400;
        $pausedaggregate = paused_aggregator::for_window($courseid, $pausedwindowstart, $now);

        // Scheduled-pause notice ("Pausa prevista"): the upcoming pauses
        // visible now (3 days before → day after), course scope, decorated
        // with localised when/typelabel strings. Platform-wide, so it is
        // attached identically to every group payload and the block renders
        // it once above the cards.
        $upcoming = upcoming_pauses::for_display($courseid, 0, $now);

        // Course-level assign catalog (global dates, group mode, manage
        // capability, group overrides), built once and resolved per group
        // below. Activities surface only on real-group cards.
        $activitycatalog = activity_schedule::catalog_for_course($course, $userid);

        $payloadgroups = [];
        foreach ($rollups as $r) {
            $gid = (int) $r->groupid;
            if ($gid === 0) {
                $name = $groupmode === NOGROUPS
                    ? get_string('card_nogroup', 'block_feedback_tracker')
                    : get_string('card_ungrouped', 'block_feedback_tracker');
                $subtitle = null;
            } else {
                $resolved = $grouptitles[$gid]
                    ?? ['title' => $groupnames[$gid] ?? sprintf('Group #%d', $gid), 'subtitle' => null];
                $name = $resolved['title'];
                $subtitle = $resolved['subtitle'];
            }
            $series = self::trend_series_for_group($courseid, $gid, $trendwindow);
            $peer = peer_stats::for_exclusion($gid);
            $activities = $gid > 0 ? activity_schedule::for_group($activitycatalog, $gid) : [];
            $payloadgroups[] = self::group_payload(
                $gid,
                $name,
                $course,
                $r,
                $series,
                $pausedaggregate,
                $peer,
                $subtitle,
                $activities,
                $upcoming
            );
        }

        $hasmore = $limit > 0 ? ($offset + count($rollups)) < $total : false;

        $result = [
            'success'    => true,
            'courseid'   => $courseid,
            'lastsynced' => time(),
            'total'      => $total,
            'offset'     => $offset,
            'limit'      => $limit,
            'hasmore'    => $hasmore,
            'overall_score' => $overallscore,
            'groups'     => $payloadgroups,
        ];

        $cache->set($key, $result);
        return $result;
    }

    /**
     * Map a sort key to a cross-DB ORDER BY clause over the rollup table.
     * Always tie-breaks on groupid so pagination stays stable across pages.
     * Unknown keys fall back to the default (groupid) order.
     *
     * @param string $sort One of 'priority', 'wait', or 'default'.
     * @return string SQL ORDER BY clause (without the "ORDER BY" keyword).
     */
    private static function order_by_for_sort(string $sort): string {
        switch ($sort) {
            case 'priority':
                // Most urgent first: more critical, then more overgoal, then
                // the worse (lower) score. NULL scores (no data) sort last via
                // COALESCE to a large sentinel (no PG-only NULLS LAST).
                return 'critical DESC, overgoal DESC, '
                    . 'COALESCE(responsiveness_score, 100000) ASC, groupid ASC';
            case 'wait':
                // Longest median effective wait first; NULL waits sort last.
                return 'COALESCE(median_eff_h, -1) DESC, groupid ASC';
            default:
                return 'groupid ASC';
        }
    }

    /**
     * Pending-weighted mean of per-group responsiveness scores over the
     * visible rollup rows, mirroring the JS overallScore() so the block's
     * banner matches the headline figure regardless of pagination. Groups
     * with no score are excluded; each contributing group's weight is
     * max(1, pending).
     *
     * @param string $where WHERE fragment already scoping course + visibility.
     * @param array $params Bound params for $where.
     * @return float|null Weighted mean, or null when no group carries a score.
     */
    private static function overall_score(string $where, array $params): ?float {
        global $DB;
        $weight = 'CASE WHEN pending > 1 THEN pending ELSE 1 END';
        $sql = "SELECT SUM(responsiveness_score * ($weight)) AS wsum,
                       SUM($weight) AS wtot
                  FROM {block_feedback_tracker_group}
                 WHERE $where AND responsiveness_score IS NOT NULL";
        $agg = $DB->get_record_sql($sql, $params);
        if (!$agg || $agg->wtot === null || (float) $agg->wtot <= 0) {
            return null;
        }
        return (float) $agg->wsum / (float) $agg->wtot;
    }

    /**
     * Resolve the composed display title + subtitle for each real group, per
     * the group_title_fields / group_subtitle_fields settings. Falls back to
     * the real group name when nothing is configured or a group has no data.
     *
     * Public so the lightweight report-scopes endpoint shows the same
     * composed names as the full payload without rebuilding it.
     *
     * @param array $groupnames Real group names keyed by group id.
     * @return array<int, array{title: string, subtitle: string|null}>
     */
    public static function resolve_group_titles(array $groupnames): array {
        $titlefields = self::parse_shortnames(
            (string) (get_config('block_feedback_tracker', 'group_title_fields') ?: '')
        );
        $subtitlespec = trim((string) (get_config('block_feedback_tracker', 'group_subtitle_fields') ?: ''));
        $subtitleisname = strtolower($subtitlespec) === 'groupname';
        $subtitlefields = ($subtitlespec === '' || $subtitleisname) ? [] : self::parse_shortnames($subtitlespec);

        $out = [];
        // Fast path — no custom-field config at all.
        if (empty($titlefields) && empty($subtitlefields) && !$subtitleisname) {
            foreach ($groupnames as $gid => $name) {
                $out[$gid] = ['title' => $name, 'subtitle' => null];
            }
            return $out;
        }

        $values = self::group_field_values(
            array_map('intval', array_keys($groupnames)),
            array_merge($titlefields, $subtitlefields)
        );
        foreach ($groupnames as $gid => $name) {
            $title = $name;
            if (!empty($titlefields)) {
                $composed = self::compose_fields($values[$gid] ?? [], $titlefields);
                if ($composed !== '') {
                    $title = $composed;
                }
            }
            $subtitle = null;
            if ($subtitleisname) {
                // Show the real name as the subtitle only when the title differs.
                $subtitle = $title !== $name ? $name : null;
            } else if (!empty($subtitlefields)) {
                $composed = self::compose_fields($values[$gid] ?? [], $subtitlefields);
                $subtitle = $composed !== '' ? $composed : null;
            }
            $out[$gid] = ['title' => $title, 'subtitle' => $subtitle];
        }
        return $out;
    }

    /**
     * Split a comma-separated shortname list into trimmed, non-empty parts.
     *
     * @param string $csv
     * @return array<int, string>
     */
    private static function parse_shortnames(string $csv): array {
        $parts = array_map('trim', explode(',', $csv));
        return array_values(array_filter($parts, static fn($s) => $s !== ''));
    }

    /**
     * Batch-load group custom-field values, keyed by group id then shortname.
     * Returns only fields that actually carry a value. Degrades to an empty
     * map (callers fall back to the real group name) on any error.
     *
     * @param array $groupids
     * @param array $shortnames
     * @return array<int, array<string, string>>
     */
    private static function group_field_values(array $groupids, array $shortnames): array {
        $out = [];
        if (empty($groupids) || empty($shortnames)) {
            return $out;
        }
        try {
            $handler = \core_group\customfield\group_handler::create();
            $wanted = [];
            $idtoshort = [];
            foreach ($handler->get_fields() as $field) {
                $sn = $field->get('shortname');
                if (in_array($sn, $shortnames, true)) {
                    $wanted[$field->get('id')] = $field;
                    $idtoshort[$field->get('id')] = $sn;
                }
            }
            if (empty($wanted)) {
                return $out;
            }
            $data = \core_customfield\api::get_instances_fields_data($wanted, $groupids, false);
            foreach ($data as $gid => $fieldsdata) {
                foreach ($fieldsdata as $fid => $datacontroller) {
                    if ($datacontroller === null || !isset($idtoshort[$fid])) {
                        continue;
                    }
                    $val = trim((string) $datacontroller->export_value());
                    if ($val !== '') {
                        $out[(int) $gid][$idtoshort[$fid]] = $val;
                    }
                }
            }
        } catch (\Throwable $e) {
            debugging('block_feedback_tracker: group custom-field load failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Join the given shortnames' values (in order) with " | ".
     *
     * @param array $valuesbyshort
     * @param array $shortnames
     * @return string
     */
    private static function compose_fields(array $valuesbyshort, array $shortnames): string {
        $parts = [];
        foreach ($shortnames as $sn) {
            if (isset($valuesbyshort[$sn]) && $valuesbyshort[$sn] !== '') {
                $parts[] = $valuesbyshort[$sn];
            }
        }
        return implode(' | ', $parts);
    }

    /**
     * Build one group card payload from a rollup row.
     *
     * Optional Phase 3C parameters ($pausedaggregate, $peer) are nullable
     * so unit tests that build a payload from a bare rollup row still work
     * without wiring the aggregator + peer_stats. for_course() always
     * passes both.
     *
     * @param int $groupid Group ID.
     * @param string $groupname Group name.
     * @param \stdClass $course Course object.
     * @param \stdClass $row Rollup row.
     * @param array $trendseries Last-30-day median values.
     * @param array|null $pausedaggregate Output of paused_aggregator::for_window().
     * @param array|null $peer Output of peer_stats::for_exclusion().
     * @param string|null $groupsubtitle Optional smaller line shown under the title.
     * @param array $activities Per-group assign schedule rows from activity_schedule::for_group().
     * @param array $upcoming Visible scheduled pauses from upcoming_pauses::for_course_group().
     * @return array
     */
    public static function group_payload(
        int $groupid,
        string $groupname,
        \stdClass $course,
        \stdClass $row,
        array $trendseries = [],
        ?array $pausedaggregate = null,
        ?array $peer = null,
        ?string $groupsubtitle = null,
        array $activities = [],
        array $upcoming = []
    ): array {
        $pausedaggregate = $pausedaggregate ?? ['total_days' => 0, 'weekend' => 0, 'holiday' => 0, 'recess' => 0, 'events' => []];
        $peer = $peer ?? ['department_score' => null, 'department_hours' => null,
                          'top10_score' => null, 'top10_hours' => null];
        // Pending-band counts follow the banding ruler: business-days mode
        // serves the day-ruler twins, falling back to the hour counts until
        // the rollup has been recomputed with the new columns. The stored
        // hour-based critical keeps feeding the score either way.
        $criticalout = (int) $row->critical;
        $overgoalout = (int) $row->overgoal;
        if (bucket::use_day_thresholds() && isset($row->critical_days) && $row->critical_days !== null) {
            $criticalout = (int) $row->critical_days;
            $overgoalout = (int) ($row->overgoal_days ?? 0);
        }
        return [
            'groupid'              => $groupid,
            'groupname'            => $groupname,
            'groupsubtitle'        => $groupsubtitle,
            'coursename'           => $course->fullname,
            'pending'              => (int) $row->pending,
            'critical'             => $criticalout,
            'overgoal'             => $overgoalout,
            'numgraded30d'         => (int) $row->numgraded30d,
            'compliance_pct'       => $row->compliance_pct !== null ? (float) $row->compliance_pct : null,
            'compliance_pct_days'  => isset($row->compliance_pct_days) && $row->compliance_pct_days !== null
                ? (float) $row->compliance_pct_days : null,
            'median_eff_h'         => $row->median_eff_h !== null ? (float) $row->median_eff_h : null,
            'p90_eff_h'            => $row->p90_eff_h !== null ? (float) $row->p90_eff_h : null,
            'max_eff_h'            => $row->max_eff_h !== null ? (float) $row->max_eff_h : null,
            'median_raw_h'         => $row->median_raw_h !== null ? (float) $row->median_raw_h : null,
            'p90_raw_h'            => $row->p90_raw_h !== null ? (float) $row->p90_raw_h : null,
            'max_raw_h'            => $row->max_raw_h !== null ? (float) $row->max_raw_h : null,
            // Phase 3C — design's "Perceived" KPI; same data source as
            // median_raw_h, renamed for the user-facing language layer.
            // We keep median_raw_h around for back-compat with any caller
            // already binding the old key.
            'perceived_median_hours' => $row->median_raw_h !== null ? (float) $row->median_raw_h : null,
            // Headline "current" medians — graded ∪ currently-pending — so the
            // block's Effective / Perceived KPI tiles reflect the live backlog
            // instead of reading ~0 when little has been graded, matching the
            // dashboard. The score still uses graded-only median_eff_h above.
            'cur_median_eff_h'     => isset($row->cur_median_eff_h) && $row->cur_median_eff_h !== null
                ? (float) $row->cur_median_eff_h : null,
            'cur_median_raw_h'     => isset($row->cur_median_raw_h) && $row->cur_median_raw_h !== null
                ? (float) $row->cur_median_raw_h : null,
            'cur_median_eff_days'  => isset($row->cur_median_eff_days) && $row->cur_median_eff_days !== null
                ? (float) $row->cur_median_eff_days : null,
            'cur_median_perc_days' => isset($row->cur_median_perc_days) && $row->cur_median_perc_days !== null
                ? (float) $row->cur_median_perc_days : null,
            /* Coordination queue vs marker turnaround. Null-tolerant like the
             * other materialised columns: the rollup is rebuilt out of band,
             * so every one of these reads null until a recompute runs. */
            'unallocated'          => isset($row->unallocated) && $row->unallocated !== null
                ? (int) $row->unallocated : null,
            'median_queue_h'       => isset($row->median_queue_h) && $row->median_queue_h !== null
                ? (float) $row->median_queue_h : null,
            'median_alloc_h'       => isset($row->median_alloc_h) && $row->median_alloc_h !== null
                ? (float) $row->median_alloc_h : null,
            'alloc_coverage_pct'   => isset($row->alloc_coverage_pct) && $row->alloc_coverage_pct !== null
                ? (float) $row->alloc_coverage_pct : null,
            'responsiveness_score' => $row->responsiveness_score !== null ? (float) $row->responsiveness_score : null,
            'score_band'           => $row->score_band !== null ? (string) $row->score_band : null,
            'comp_compliance'      => isset($row->comp_compliance) && $row->comp_compliance !== null
                ? (float) $row->comp_compliance : null,
            'comp_median'          => isset($row->comp_median) && $row->comp_median !== null
                ? (float) $row->comp_median : null,
            'comp_critical'        => isset($row->comp_critical) && $row->comp_critical !== null
                ? (float) $row->comp_critical : null,
            'comp_pending'         => isset($row->comp_pending) && $row->comp_pending !== null
                ? (float) $row->comp_pending : null,
            'comp_trend'           => isset($row->comp_trend) && $row->comp_trend !== null
                ? (float) $row->comp_trend : null,
            'trend_pct_30d'        => $row->trend_pct_30d !== null ? (float) $row->trend_pct_30d : null,
            'trend_series'         => $trendseries,
            'nextpause_ts'         => $row->nextpause_ts !== null ? (int) $row->nextpause_ts : null,
            'nextpause_reason'     => $row->nextpause_reason !== null ? (string) $row->nextpause_reason : null,
            'nextpause_note'       => $row->nextpause_note !== null ? (string) $row->nextpause_note : null,
            'lastpause_endts'      => $row->lastpause_endts !== null ? (int) $row->lastpause_endts : null,
            'lastpause_reason'     => $row->lastpause_reason !== null ? (string) $row->lastpause_reason : null,
            /* Scheduled-pause notice ("Pausa prevista"): up to 3 upcoming
             * pauses visible now. Each entry carries the localised display
             * strings (when / typelabel); label is format_string()-sanitised. */
            'upcoming_pauses' => array_map(static fn ($u) => [
                'start' => (int) $u['start'],
                'type' => (string) $u['type'],
                'label' => (string) $u['label'],
                'when' => (string) $u['when'],
                'typelabel' => (string) $u['typelabel'],
            ], $upcoming),
            // Phase 3C — paused-window transparency aggregate (course scope).
            'paused_days_30d'      => (int) $pausedaggregate['total_days'],
            'paused_breakdown_30d' => [
                'weekend' => (int) $pausedaggregate['weekend'],
                'holiday' => (int) $pausedaggregate['holiday'],
                'recess'  => (int) $pausedaggregate['recess'],
            ],
            /* v1.0.9 — sub-day optional events sidecar list. Each entry
             * is {date: YYYYMMDD, starttime: min, endtime: min, label: str}.
             * Label is already format_string()-sanitised by paused_aggregator. */
            'paused_events_30d' => is_array($pausedaggregate['events'] ?? null)
                ? array_map(static fn ($e) => [
                    'date'      => (int) $e['date'],
                    'starttime' => (int) $e['starttime'],
                    'endtime'   => (int) $e['endtime'],
                    'label'     => (string) $e['label'],
                ], $pausedaggregate['events'])
                : [],
            // Phase 3C — peer comparison (excluding this group).
            'peer_department_score' => $peer['department_score'] !== null ? (float) $peer['department_score'] : null,
            'peer_department_hours' => $peer['department_hours'] !== null ? (float) $peer['department_hours'] : null,
            'peer_top10_score'      => $peer['top10_score'] !== null ? (float) $peer['top10_score'] : null,
            'peer_top10_hours'      => $peer['top10_hours'] !== null ? (float) $peer['top10_hours'] : null,
            // Per-group assign open/close schedule and override action; empty for the ungrouped card.
            'activities'            => array_values($activities),
        ];
    }

    /**
     * Produce an ordered list of YYYYMMDD ints for the last $days days
     * (today inclusive), in platform tz.
     *
     * @param int $days
     * @return array<int, int>
     */
    private static function trend_window(int $days): array {
        $tz = \block_feedback_tracker\local\calendar\calendar::timezone();
        $today = (new \DateTimeImmutable('@' . time()))->setTimezone($tz)->setTime(0, 0, 0);
        $window = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $window[] = (int) $today->modify("-{$i} days")->format('Ymd');
        }
        return $window;
    }

    /**
     * Look up the trend rows for one (course, group) and return them aligned
     * to the supplied date window, with null for missing days.
     *
     * @param int $courseid Course ID.
     * @param int $groupid Group ID.
     * @param array $window YYYYMMDD ints, oldest → newest.
     * @return array
     */
    private static function trend_series_for_group(int $courseid, int $groupid, array $window): array {
        global $DB;

        $first = (int) reset($window);
        $last = (int) end($window);
        $rows = $DB->get_records_select(
            'block_feedback_tracker_trend',
            'courseid = :courseid AND groupid = :groupid AND day >= :first AND day <= :last',
            [
                'courseid' => $courseid,
                'groupid'  => $groupid,
                'first'    => $first,
                'last'     => $last,
            ],
            'day ASC',
            'id, day, medianh_eff'
        );

        $bymd = [];
        foreach ($rows as $r) {
            $bymd[(int) $r->day] = $r->medianh_eff !== null ? (float) $r->medianh_eff : null;
        }

        $series = [];
        foreach ($window as $ymd) {
            $series[] = [
                'day'   => $ymd,
                'value' => $bymd[$ymd] ?? null,
            ];
        }
        return $series;
    }
}
