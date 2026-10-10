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
 * Site benchmarks on the teacher dashboard: the site-wide daily series of
 * get_school_comparison as a table, with a compact trend of the median above
 * it.
 *
 * Collapsed until the viewer opens it, and it fetches only then, so a viewer
 * who never opens it costs the page nothing. The viewer picks the window
 * (WINDOWS); each window is fetched once per page load and kept.
 *
 * Every figure is in effective (business) hours. The site series stores no
 * day counts, so the section stays in hours when the display unit is business
 * days and says so: days are never derived from hours.
 *
 * DashboardView renders this only when bootstrap::config_bundle() flags the
 * viewer as holding block/feedback_tracker:viewschoolcomparison; the web
 * service checks that capability again on every call.
 *
 * @module    block_feedback_tracker/components/SchoolComparison
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState, useRef} from 'block_feedback_tracker/lib/preact';
import Sparkline from 'block_feedback_tracker/components/Sparkline';
import RetryNotice from 'block_feedback_tracker/components/RetryNotice';
import {getSchoolComparison} from 'block_feedback_tracker/lib/api';
import {colourFor} from 'block_feedback_tracker/lib/bands';
import {formatHours, formatCount, formatYmd, usesDays} from 'block_feedback_tracker/lib/format';

/** Window lengths the viewer can pick, in days; all within get_school_comparison::MAX_DAYS. */
const WINDOWS = [7, 30, 90];

/** Window shown first, the web service's own default. */
const DEFAULT_WINDOW = 30;

/** Id tying the toggle to the region it opens (one section per page). */
const BODY_ID = 'bft-comparison-body';

/**
 * A share in percent, rounded to a whole number as the other dashboard
 * percentages are, or the em-dash for a day with no graded submission.
 *
 * @param {number|null|undefined} value
 * @returns {string}
 */
const formatShare = (value) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }
    return Math.round(Number(value)) + '%';
};

/**
 * Replace the {$a} placeholder of a lang string.
 *
 * @param {string} template
 * @param {number} days
 * @returns {string}
 */
const withDays = (template, days) => String(template).replace('{$a}', String(days));

/**
 * The series as a table, newest day first, with the median's trend above it.
 *
 * @param {object} props
 * @param {Array<object>} props.rows  get_school_comparison days[], oldest first.
 * @param {number} props.days         Window length, for the caption.
 * @param {object} props.i18n
 * @param {object} props.config
 * @returns {object} vnode
 */
const SeriesTable = ({rows, days, i18n, config}) => {
    const cols = {
        day: i18n.dashboard_comparison_col_day || 'Day',
        median: i18n.dashboard_comparison_col_median || 'Median (business hours)',
        p10: i18n.dashboard_comparison_col_p10 || '10th percentile (business hours)',
        p90: i18n.dashboard_comparison_col_p90 || '90th percentile (business hours)',
        compliance: i18n.dashboard_comparison_col_compliance || 'Within SLA goal',
        graded: i18n.dashboard_comparison_col_graded || 'Graded',
    };
    const caption = withDays(i18n.dashboard_comparison_caption || 'Site benchmarks per day, last {$a} days', days);
    const goal = config.sla_goal_hours;
    const zonelabel = goal !== null && goal !== undefined
        ? (i18n.sparkline_zone_label || 'Desired speed: 0 to {$a}').replace('{$a}', String(Math.round(Number(goal))))
        : '';
    const medians = rows.map((d) => (d.medianh_eff === null || d.medianh_eff === undefined ? null : Number(d.medianh_eff)));
    const newestfirst = rows.slice().reverse();

    // The chart repeats the median column, so it stays out of the accessibility
    // tree (no arialabel); the table carries every figure.
    return html`
        ${medians.some((v) => v !== null) && html`
            <div class="bft-comparison-chart">
                <${Sparkline}
                    values=${medians}
                    goal=${goal}
                    width=${360}
                    height=${48}
                    color=${colourFor('good')}
                    zonelabel=${zonelabel} />
                <span class="bft-comparison-chart-caption">
                    ${i18n.dashboard_comparison_chart || 'Daily site median (business hours)'}
                </span>
            </div>
        `}
        <div class="bft-comparison-table-wrap" tabindex="0" role="region" aria-label=${caption}>
            <table class="bft-comparison-table">
                <caption class="bft-comparison-caption">${caption}</caption>
                <thead>
                    <tr>
                        <th scope="col">${cols.day}</th>
                        <th scope="col" class="bft-comparison-num">${cols.median}</th>
                        <th scope="col" class="bft-comparison-num">${cols.p10}</th>
                        <th scope="col" class="bft-comparison-num">${cols.p90}</th>
                        <th scope="col" class="bft-comparison-num">${cols.compliance}</th>
                        <th scope="col" class="bft-comparison-num">${cols.graded}</th>
                    </tr>
                </thead>
                <tbody>
                    ${newestfirst.map((d) => html`
                        <tr key=${'d-' + d.day}>
                            <th scope="row" class="bft-comparison-day">${formatYmd(d.day)}</th>
                            <td class="bft-comparison-num bft-mono">${formatHours(d.medianh_eff)}</td>
                            <td class="bft-comparison-num bft-mono">${formatHours(d.p10h_eff)}</td>
                            <td class="bft-comparison-num bft-mono">${formatHours(d.p90h_eff)}</td>
                            <td class="bft-comparison-num bft-mono">${formatShare(d.compliance_pct_site)}</td>
                            <td class="bft-comparison-num bft-mono">${formatCount(d.numgraded)}</td>
                        </tr>
                    `)}
                </tbody>
            </table>
        </div>
        ${usesDays(config) && html`
            <p class="bft-comparison-note">
                ${i18n.dashboard_comparison_unit_note
                    || 'These figures stay in business hours: the site history records no business-day counts.'}
            </p>
        `}
    `;
};

/**
 * @param {object} props
 * @param {object} props.i18n    Dashboard label map (i18n_bundle + dashboard_i18n).
 * @param {object} props.config  Config bundle (SLA goal, display unit).
 * @returns {object} vnode
 */
export default function SchoolComparison({i18n, config}) {
    const [open, setOpen] = useState(false);
    const [windowdays, setWindowDays] = useState(DEFAULT_WINDOW);
    // Rows per window length, filled as each window is first shown.
    const [series, setSeries] = useState({});
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    // Number of the latest request: only it may settle the loading and error
    // state, so a slow answer for a window the viewer has left changes nothing
    // on screen (its rows are still kept under their own window).
    const latest = useRef(0);

    const load = (days) => {
        latest.current += 1;
        const ticket = latest.current;
        setLoading(true);
        setError(null);
        getSchoolComparison({days})
            .then((res) => {
                const rows = res && Array.isArray(res.days) ? res.days : [];
                setSeries((prev) => ({...prev, [days]: rows}));
                return null;
            })
            .catch((e) => {
                if (ticket === latest.current) {
                    setError((e && e.bftNetwork)
                        ? (i18n.connection_lost || 'Connection lost. Check your internet and try again.')
                        : (i18n.dashboard_comparison_error || 'The site benchmarks could not be loaded.'));
                }
            })
            .finally(() => {
                if (ticket === latest.current) {
                    setLoading(false);
                }
            });
    };

    const toggle = () => {
        const next = !open;
        setOpen(next);
        if (next && series[windowdays] === undefined && !loading) {
            load(windowdays);
        }
    };

    const pick = (days) => {
        if (days === windowdays) {
            return;
        }
        setWindowDays(days);
        if (series[days] === undefined) {
            load(days);
        }
    };

    const rows = series[windowdays];
    let body;
    if (rows === undefined && error && !loading) {
        body = html`<${RetryNotice}
            message=${error}
            onRetry=${() => load(windowdays)}
            retrying=${loading}
            i18n=${i18n}
            variant="block" />`;
    } else if (rows === undefined) {
        body = html`<div class="bft-empty" role="status">
            ${i18n.dashboard_comparison_loading || 'Loading benchmarks…'}
        </div>`;
    } else if (rows.length === 0) {
        body = html`<div class="bft-empty">${i18n.dashboard_comparison_empty || 'No benchmark data yet.'}</div>`;
    } else {
        body = html`<${SeriesTable} rows=${rows} days=${windowdays} i18n=${i18n} config=${config} />`;
    }

    const windowtext = i18n.dashboard_comparison_window_days || 'Last {$a} days';
    return html`
        <section class="bft-dashboard-comparison">
            <h2 class="bft-comparison-heading">
                <button type="button"
                        class="bft-comparison-toggle"
                        aria-expanded=${open ? 'true' : 'false'}
                        aria-controls=${BODY_ID}
                        onClick=${toggle}>
                    <span class="bft-comparison-caret" aria-hidden="true">${open ? '▾' : '▸'}</span>
                    ${i18n.dashboard_comparison_title || 'Site benchmarks'}
                </button>
            </h2>
            <div id=${BODY_ID} class="bft-comparison-body" hidden=${!open}>
                ${open && html`
                    <p class="bft-comparison-subtitle">${i18n.dashboard_comparison_subtitle || ''}</p>
                    <div class="bft-comparison-windows" role="group"
                         aria-label=${i18n.dashboard_comparison_window || 'Period'}>
                        ${WINDOWS.map((d) => html`
                            <button type="button"
                                    key=${'w-' + d}
                                    class=${'bft-comparison-window' + (d === windowdays ? ' is-active' : '')}
                                    aria-pressed=${d === windowdays ? 'true' : 'false'}
                                    onClick=${() => pick(d)}>
                                ${withDays(windowtext, d)}
                            </button>
                        `)}
                    </div>
                    ${body}
                `}
            </div>
        </section>
    `;
}
