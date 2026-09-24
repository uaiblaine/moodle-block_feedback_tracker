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
 * Dashboard courses table — one row per course with a small ScoreRing,
 * pending / priority / effective counts, and an inline 14-day sparkline.
 *
 * A native <table> with sortable column headers (aria-sort) that reflows
 * into stacked cards on narrow screens; each cell carries a data-label that
 * surfaces as the field caption in that card layout (see styles.css).
 *
 * Stateless. The parent owns the sort state; rows link to the course's
 * pending report.
 *
 * @module    block_feedback_tracker/components/CoursesTable
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import ScoreRing from 'block_feedback_tracker/components/ScoreRing';
import Sparkline from 'block_feedback_tracker/components/Sparkline';
import {bandForScore, colourFor} from 'block_feedback_tracker/lib/bands';
import {formatHours, formatDays, usesDays, formatCount} from 'block_feedback_tracker/lib/format';

/**
 * Header cell that toggles sort when clicked.
 *
 * @param {object} props
 * @param {string} props.label
 * @param {string} props.sortKey
 * @param {string|null} props.currentKey
 * @param {string} props.currentOrder
 * @param {Function} props.onClick
 * @param {object} props.i18n
 * @returns {object} vnode
 */
const SortHeader = ({label, sortKey, currentKey, currentOrder, onClick, i18n}) => {
    const active = currentKey === sortKey;
    let arrow = '';
    let ariasort = 'none';
    if (active) {
        arrow = currentOrder === 'asc' ? ' ▲' : ' ▼';
        ariasort = currentOrder === 'asc' ? 'ascending' : 'descending';
    }
    const sortlabel = (i18n.dashboard_sort_by || 'Sort by {$a}').replace('{$a}', label);
    return html`
        <th scope="col" class=${'bft-th-sortable' + (active ? ' is-active' : '')} aria-sort=${ariasort}>
            <button type="button"
                    class="bft-th-sortable-btn"
                    aria-label=${sortlabel}
                    onClick=${() => onClick(sortKey)}>
                ${label}${arrow}
            </button>
        </th>
    `;
};

/**
 * @param {object} props
 * @param {Array<object>} props.rows  Sorted by the caller.
 * @param {object} props.i18n
 * @param {string|null} props.sortKey
 * @param {string} props.sortOrder
 * @param {Function} props.onSort     Receives sortKey on header click.
 * @param {object|null} props.thresholds
 * @param {number|null} [props.goal]  SLA goal hours; drives the trend improvement zone.
 * @param {object} [props.config]     Config bundle (effective-time display unit).
 * @returns {object} vnode
 */
export default function CoursesTable({rows, i18n, sortKey, sortOrder, onSort, thresholds, goal = null, config}) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return html`
            <div class="bft-empty">
                ${i18n.dashboard_courses_empty || 'No courses with feedback data yet.'}
            </div>
        `;
    }

    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    const reportUrl = (cid) =>
        wwwroot + '/blocks/feedback_tracker/pages/pending_report.php?courseid='
        + encodeURIComponent(String(cid));
    const zonelabel = goal !== null && goal !== undefined
        ? (i18n.sparkline_zone_label || 'Desired speed: 0 to {$a}')
            .replace('{$a}', String(Math.round(Number(goal))))
        : '';
    // Column labels, shared by the header row and each cell's data-label.
    const cols = {
        course: i18n.dashboard_col_course || 'Course',
        avgscore: i18n.dashboard_col_avgscore || 'Score',
        pending: i18n.dashboard_col_pending || 'Pending',
        critical: i18n.dashboard_col_critical || 'Priority',
        effective: i18n.hero_effective_eyebrow || 'Effective',
        trend: i18n.trend_window_label || '30 days',
    };

    return html`
        <div class="bft-courses-table-wrap">
        <table class="bft-courses-table">
            <thead>
                <tr>
                    <${SortHeader} label=${cols.course}
                        sortKey="coursename" currentKey=${sortKey} currentOrder=${sortOrder}
                        onClick=${onSort} i18n=${i18n} />
                    <${SortHeader} label=${cols.avgscore}
                        sortKey="avgscore" currentKey=${sortKey} currentOrder=${sortOrder}
                        onClick=${onSort} i18n=${i18n} />
                    <${SortHeader} label=${cols.pending}
                        sortKey="pending" currentKey=${sortKey} currentOrder=${sortOrder}
                        onClick=${onSort} i18n=${i18n} />
                    <${SortHeader} label=${cols.critical}
                        sortKey="critical" currentKey=${sortKey} currentOrder=${sortOrder}
                        onClick=${onSort} i18n=${i18n} />
                    <${SortHeader} label=${cols.effective}
                        sortKey="cur_median_eff_h" currentKey=${sortKey} currentOrder=${sortOrder}
                        onClick=${onSort} i18n=${i18n} />
                    <th scope="col">${cols.trend}</th>
                    <th scope="col">
                        <span class="bft-sr-only">${i18n.dashboard_open_course || 'Open'}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                ${rows.map((row) => {
                    const isIdle = row.avgscore === null || row.avgscore === undefined;
                    const band = row.score_band || bandForScore(row.avgscore, thresholds);
                    const trendSeries = (Array.isArray(row.trend_series)
                        ? row.trend_series.map((p) =>
                            (p && p.value !== null && p.value !== undefined ? Number(p.value) : null))
                        : []);
                    const hasSeries = trendSeries.some((v) => v !== null);
                    const trendColour = colourFor(band);
                    return html`
                        <tr key=${'cr-' + row.courseid} class="bft-courses-row">
                            <td class="bft-courses-name">
                                <a class="bft-courses-name-link" href=${reportUrl(row.courseid)}>
                                    ${row.coursename}
                                </a>
                            </td>
                            <td class="bft-courses-score" data-label=${cols.avgscore}>
                                ${isIdle
                                    ? html`<span class="bft-courses-dim">—</span>`
                                    : html`
                                        <span class="bft-courses-score-inline">
                                            <${ScoreRing} score=${row.avgscore} band=${band} size=${26} thickness=${3} />
                                            <span class=${'bft-mono bft-overall-score-tone-' + band}>
                                                ${Math.round(Number(row.avgscore))}
                                            </span>
                                            <span class="bft-courses-score-of bft-mono">/ 100</span>
                                        </span>
                                    `}
                            </td>
                            <td class="bft-mono bft-courses-num" data-label=${cols.pending}>
                                ${formatCount(row.pending)}
                            </td>
                            <td class=${'bft-mono bft-courses-num'
                                + (Number(row.critical) > 0 ? ' bft-courses-num-alert' : '')}
                                data-label=${cols.critical}>
                                ${formatCount(row.critical)}
                            </td>
                            <td class=${'bft-mono bft-courses-num bft-overall-score-tone-' + band}
                                data-label=${cols.effective}>
                                ${usesDays(config)
                                    ? formatDays(row.cur_median_eff_days)
                                    : formatHours(row.cur_median_eff_h)}
                            </td>
                            <td class="bft-courses-spark" data-label=${cols.trend}>
                                ${hasSeries
                                    ? html`<${Sparkline}
                                              values=${trendSeries}
                                              goal=${goal}
                                              width=${72}
                                              height=${20}
                                              color=${trendColour}
                                              zonelabel=${zonelabel} />`
                                    : html`<span class="bft-courses-dim">—</span>`}
                            </td>
                            <td class="bft-courses-cta">
                                <a class="bft-courses-open" href=${reportUrl(row.courseid)}>
                                    ${i18n.dashboard_open_course || 'Open'}
                                </a>
                            </td>
                        </tr>
                    `;
                })}
            </tbody>
        </table>
        </div>
    `;
}
