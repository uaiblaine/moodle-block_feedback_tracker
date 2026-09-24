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
 * "Last 30 academic days" heatmap strip. One square per calendar day: paused
 * days (weekend / holiday / recess) render hatched; academic days render in
 * their responsiveness-band colour. A summary line beneath spells out how many
 * days were paused and why.
 *
 * Pure presentational — PendingReportView loads the data from the
 * get_academic_days web service. Renders nothing when there are no days and no
 * load error.
 *
 * @module    block_feedback_tracker/components/AcademicDaysStrip
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import {usesDays, formatDays} from 'block_feedback_tracker/lib/format';
import RetryNotice from 'block_feedback_tracker/components/RetryNotice';

/**
 * YYYYMMDD int → "DD/MM".
 *
 * @param {number} ymd
 * @returns {string}
 */
const fmtYmd = (ymd) => {
    const n = Number(ymd) || 0;
    const m = Math.floor((n / 100) % 100);
    const d = n % 100;
    return String(d).padStart(2, '0') + '/' + String(m).padStart(2, '0');
};

/**
 * Minutes-since-midnight → "HH:MM".
 *
 * @param {number} min
 * @returns {string}
 */
const fmtMin = (min) => {
    const n = Number(min) || 0;
    return String(Math.floor(n / 60)).padStart(2, '0') + ':' + String(n % 60).padStart(2, '0');
};

/**
 * Render one event entry's "DD/MM HH:MM-HH:MM · label" string.
 *
 * @param {{date:number, starttime:number, endtime:number, label:string}} ev
 * @returns {string}
 */
const fmtEvent = (ev) => {
    const ts = fmtYmd(ev.date) + ' ' + fmtMin(ev.starttime) + '-' + fmtMin(ev.endtime);
    return ev.label ? ts + ' · ' + ev.label : ts;
};

/**
 * BEM modifier for one day cell. Critical days fold into "regular" so the
 * legend keeps three bands.
 *
 * @param {{paused:boolean, band:string}} day
 * @returns {string}
 */
const cellModifier = (day) => {
    if (day.paused) {
        return 'bft-acaday-paused';
    }
    switch (day.band) {
        case 'excellent': return 'bft-acaday-ongoal';
        case 'good': return 'bft-acaday-good';
        case 'regular':
        case 'critical': return 'bft-acaday-regular';
        default: return 'bft-acaday-nodata';
    }
};

/**
 * Localised reason label for a paused day's tooltip.
 *
 * @param {string} reason
 * @param {object} i18n
 * @returns {string}
 */
const reasonLabel = (reason, i18n) => {
    switch (reason) {
        case 'weekend': return i18n.pause_reason_weekend || 'Weekend';
        case 'holiday': return i18n.pause_reason_holiday || 'Holiday';
        case 'recess': return i18n.pause_reason_recess || 'Recess';
        default: return '';
    }
};

/**
 * Per-cell tooltip: date plus either the pause reason or that day's median
 * wait in the configured display unit — business days (date-based count)
 * when the business-days unit is active, effective hours otherwise.
 *
 * @param {object} day
 * @param {object} i18n
 * @param {object} [config]  Config bundle (display_time_unit).
 * @returns {string}
 */
const cellTitle = (day, i18n, config) => {
    const date = fmtYmd(day.ymd);
    if (day.paused) {
        return date + ' · ' + reasonLabel(day.reason, i18n);
    }
    if (usesDays(config)) {
        if (day.eff_days === null || day.eff_days === undefined) {
            return date;
        }
        return date + ' · ' + formatDays(day.eff_days);
    }
    if (day.eff_h === null || day.eff_h === undefined) {
        return date;
    }
    return date + ' · ' + Math.round(Number(day.eff_h)) + 'h';
};

/**
 * Build the "N days paused — X weekend · Y holidays (dates) · …" summary.
 *
 * @param {object} summary  {total_days, weekend, holiday, recess}.
 * @param {Array<object>} days  Per-day series (for dated holiday/recess).
 * @param {Array<object>} events  Sub-day optional events sidecar.
 * @param {object} i18n
 * @returns {string}
 */
const buildSummary = (summary, days, events, i18n) => {
    const datesFor = (reason) => days
        .filter((d) => d.paused && d.reason === reason)
        .map((d) => fmtYmd(d.ymd));
    const dated = (list) => (list.length > 0 && list.length <= 3 ? ' (' + list.join(', ') + ')' : '');
    const parts = [];
    if (summary.weekend > 0) {
        parts.push(summary.weekend + ' ' + (i18n.paused_breakdown_weekend || 'weekend days'));
    }
    if (summary.holiday > 0) {
        const word = summary.holiday === 1
            ? (i18n.acaday_holiday_one || 'holiday')
            : (i18n.paused_breakdown_holiday || 'holidays');
        parts.push(summary.holiday + ' ' + word + dated(datesFor('holiday')));
    }
    if (summary.recess > 0) {
        const word = summary.recess === 1
            ? (i18n.acaday_recess_one || 'recess day')
            : (i18n.paused_breakdown_recess || 'recess days');
        parts.push(summary.recess + ' ' + word + dated(datesFor('recess')));
    }
    if (Array.isArray(events) && events.length > 0) {
        const word = events.length === 1
            ? (i18n.paused_callout_event_singular || 'event')
            : (i18n.paused_callout_event_plural || 'events');
        const latest = events[events.length - 1];
        parts.push(events.length + ' ' + word + (latest ? ' (' + fmtEvent(latest) + ')' : ''));
    }
    return parts.join(' · ');
};

/**
 * @param {object} props
 * @param {Array<object>} props.days     Per-day series from get_academic_days.
 * @param {object} props.summary         {total_days, weekend, holiday, recess}.
 * @param {Array<object>} [props.events] Sub-day optional events sidecar.
 * @param {boolean} [props.loading]      True while the WS call is in flight.
 * @param {string} [props.error]         Friendly message when the load failed.
 * @param {Function} [props.onRetry]     Re-run the load (retry affordance).
 * @param {object} props.i18n            Label bundle.
 * @param {object} [props.config]        Config bundle (display unit).
 * @returns {object|null} vnode
 */
export default function AcademicDaysStrip({days, summary, events, loading, error, onRetry, i18n, config}) {
    const list = Array.isArray(days) ? days : [];
    const sum = summary || {'total_days': 0, 'weekend': 0, 'holiday': 0, 'recess': 0};
    const evlist = Array.isArray(events) ? events : [];

    if (loading) {
        return html`
            <div class="bft-acaday bft-acaday-loading">
                <div class="bft-acaday-head">
                    <span class="bft-acaday-title">${i18n.acaday_title || 'Last 30 academic days'}</span>
                </div>
                <div class="bft-acaday-skeleton" aria-hidden="true"></div>
                <div class="bft-acaday-loadnote">${i18n.acaday_loading || 'Loading…'}</div>
            </div>
        `;
    }

    if (list.length === 0) {
        if (error) {
            return html`
                <div class="bft-acaday">
                    <div class="bft-acaday-head">
                        <span class="bft-acaday-title">${i18n.acaday_title || 'Last 30 academic days'}</span>
                    </div>
                    <${RetryNotice}
                        message=${error}
                        onRetry=${onRetry}
                        i18n=${i18n}
                        variant="banner" />
                </div>
            `;
        }
        return null;
    }

    const legend = [
        {mod: 'bft-acaday-ongoal', label: i18n.acaday_legend_ongoal || 'On goal'},
        {mod: 'bft-acaday-good', label: i18n.acaday_legend_good || 'Good'},
        {mod: 'bft-acaday-regular', label: i18n.acaday_legend_regular || 'Regular'},
        {mod: 'bft-acaday-paused', label: i18n.acaday_legend_paused || 'Paused'},
    ];
    const summaryline = buildSummary(sum, list, evlist, i18n);

    return html`
        <div class="bft-acaday">
            <div class="bft-acaday-head">
                <span class="bft-acaday-title">${i18n.acaday_title || 'Last 30 academic days'}</span>
                <div class="bft-acaday-legend">
                    ${legend.map((item) => html`
                        <span class="bft-acaday-legend-item" key=${item.mod}>
                            <span class=${'bft-acaday-swatch ' + item.mod} aria-hidden="true"></span>
                            ${item.label}
                        </span>
                    `)}
                </div>
            </div>
            <div class="bft-acaday-grid" role="img"
                 aria-label=${i18n.acaday_title || 'Last 30 academic days'}>
                ${list.map((day) => html`
                    <span class=${'bft-acaday-cell ' + cellModifier(day)}
                          key=${'ad-' + day.ymd}
                          title=${cellTitle(day, i18n, config)}></span>
                `)}
            </div>
            ${(sum.total_days > 0 || evlist.length > 0) && html`
                <div class="bft-acaday-summary">
                    <strong>${sum.total_days} ${i18n.paused_callout_days || 'days paused in the last 30'}</strong>
                    ${summaryline && html` — ${summaryline}`}
                </div>
            `}
        </div>
    `;
}
