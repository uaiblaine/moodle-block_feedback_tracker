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
 * Paused-periods transparency callout: a single bar that explains how many
 * days were paused in the last 30 and which categories contributed.
 *
 * Renders nothing when there are no paused days and no events, so a new
 * course does not show "0 days paused".
 *
 * @module    block_feedback_tracker/components/PausedCallout
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * YYYYMMDD int → "DD/MM", in that fixed order whatever the language.
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
    const h = Math.floor(n / 60);
    const m = n % 60;
    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
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
 * Build the "X weekend days · Y holidays · Z recess days · N events" body
 * line. Events get an inline summary with the most recent label + time so
 * admins see what the paused windows actually were.
 *
 * @param {object} breakdown {weekend, holiday, recess}
 * @param {Array<{date:number, starttime:number, endtime:number, label:string}>} events
 * @param {object} i18n
 * @returns {string}
 */
const buildBreakdownLine = (breakdown, events, i18n) => {
    const parts = [];
    if (breakdown.weekend > 0) {
        parts.push(breakdown.weekend + ' ' + (i18n.paused_breakdown_weekend || 'weekend days'));
    }
    if (breakdown.holiday > 0) {
        parts.push(breakdown.holiday + ' ' + (i18n.paused_breakdown_holiday || 'holidays'));
    }
    if (breakdown.recess > 0) {
        parts.push(breakdown.recess + ' ' + (i18n.paused_breakdown_recess || 'recess days'));
    }
    if (Array.isArray(events) && events.length > 0) {
        const n = events.length;
        const word = n === 1
            ? (i18n.paused_callout_event_singular || 'event')
            : (i18n.paused_callout_event_plural || 'events');
        // The server's paused_aggregator emits events in date order, so the last is the newest.
        const latest = events[events.length - 1];
        const detail = latest ? fmtEvent(latest) : '';
        parts.push(n + ' ' + word + (detail ? ' (' + detail + ')' : ''));
    }
    return parts.join(' · ');
};

/**
 * @param {object} props
 * @param {number} props.totaldays      Aggregated paused-day count.
 * @param {object} props.breakdown      {weekend, holiday, recess} counts.
 * @param {Array<object>} [props.events] Sub-day optional events sidecar.
 * @param {object} props.i18n           Label bundle.
 * @param {() => void} [props.onView]   Optional click handler for "View calendar".
 * @returns {object|null} vnode
 */
export default function PausedCallout({totaldays, breakdown, events, i18n, onView}) {
    const days = Number(totaldays) || 0;
    const evlist = Array.isArray(events) ? events : [];
    if (days <= 0 && evlist.length === 0) {
        return null;
    }
    const brk = breakdown || {weekend: 0, holiday: 0, recess: 0};
    const body = buildBreakdownLine(brk, evlist, i18n);
    return html`
        <div class="bft-paused-callout">
            <span class="bft-paused-callout-swatch" aria-hidden="true"></span>
            <div class="bft-paused-callout-text">
                <div class="bft-paused-callout-title">
                    ${i18n.paused_callout_title || 'Paused periods excluded from this report'}
                </div>
                <div class="bft-paused-callout-body">
                    <strong>${days} ${i18n.paused_callout_days || 'days paused'}</strong>
                    ${body && html` — ${body}`}.
                    ${' '}${i18n.paused_callout_explain
                        || "Paused periods don't penalize responsiveness scores."}
                </div>
            </div>
            ${onView && html`
                <button type="button"
                        class="bft-paused-callout-view"
                        onClick=${onView}>
                    ${i18n.paused_callout_view || 'View calendar'}
                </button>
            `}
        </div>
    `;
}
