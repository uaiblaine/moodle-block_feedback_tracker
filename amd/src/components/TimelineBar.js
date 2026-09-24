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
 * Activity open/close timeline. Shows the position of "today" between an
 * activity's open and close timestamps as a coloured progress bar with
 * dates flanking the track.
 *
 * Three phases set the track colour (styles.css) and the thumb position:
 *   - before  (now <= open):   thumb pinned at the left.
 *   - running (open < now < close): thumb at the elapsed %.
 *   - closed  (now >= close):   thumb pinned at the end, close date flagged.
 *
 * Renders the `norulelabel` pill when the activity has no usable open/close
 * pair rather than guessing — a "no rule" assignment is information, not an
 * error.
 *
 * @module    block_feedback_tracker/components/TimelineBar
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * Format a unix-seconds timestamp as DD/MM in the browser's timezone.
 *
 * @param {number} ts
 * @returns {string}
 */
const fmtDay = (ts) => {
    const d = new Date(Number(ts) * 1000);
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    return dd + '/' + mm;
};

/**
 * Pick the calendar phase: before the open date, running, or closed once the
 * close date is reached or passed.
 *
 * @param {number} opens
 * @param {number} closes
 * @param {number} now
 * @returns {'before'|'running'|'closed'}
 */
const phaseFor = (opens, closes, now) => {
    if (now >= closes) {
        return 'closed';
    }
    if (now <= opens) {
        return 'before';
    }
    return 'running';
};

/**
 * @param {object} props
 * @param {number|null} props.opens   Unix seconds, or null when no rule.
 * @param {number|null} props.closes  Unix seconds, or null when no rule.
 * @param {string} [props.norulelabel]
 * @returns {object} vnode
 */
export default function TimelineBar({opens, closes, norulelabel}) {
    const safeopens = Number(opens) || 0;
    const safecloses = Number(closes) || 0;
    if (safeopens <= 0 || safecloses <= 0 || safecloses <= safeopens) {
        return html`
            <span class="bft-timeline-bar bft-timeline-bar-empty">
                ${norulelabel || 'no rule'}
            </span>
        `;
    }
    const now = Math.floor(Date.now() / 1000);
    const total = safecloses - safeopens;
    const elapsed = Math.max(0, Math.min(total, now - safeopens));
    const pct = (elapsed / total) * 100;
    const phase = phaseFor(safeopens, safecloses, now);

    return html`
        <div class=${'bft-timeline-bar bft-timeline-bar-' + phase}>
            <span class="bft-timeline-bar-date bft-mono">${fmtDay(safeopens)}</span>
            <div class="bft-timeline-bar-track">
                <div class="bft-timeline-bar-fill"
                     style=${'width: ' + Math.min(100, pct).toFixed(1) + '%;'}></div>
                <div class="bft-timeline-bar-thumb"
                     style=${'left: calc(' + Math.min(100, pct).toFixed(1) + '% - 4px);'}></div>
            </div>
            <span class=${'bft-timeline-bar-date bft-mono'
                + (phase === 'closed' ? ' bft-timeline-bar-date-overdue' : '')}>
                ${fmtDay(safecloses)}
            </span>
        </div>
    `;
}
