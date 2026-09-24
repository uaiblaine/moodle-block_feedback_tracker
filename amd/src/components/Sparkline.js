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
 * Compact SVG line chart for a daily trend series. Null values mean "no data
 * that day" and are skipped — the polyline runs through the days that do have
 * values.
 *
 * The vertical axis reads as speed: fewer effective hours plot higher. A
 * `goal` adds the desired-speed zone, a band from 0 hours at the top edge
 * down to the goal, with a solid line at 0 and a dotted line at the goal
 * (colours in styles.css). The optional `zonelabel` is drawn inside the
 * chart when it is at least ZONE_LABEL_MIN_WIDTH wide.
 *
 * `arialabel` is the chart's accessible name; callers pass the i18n bundle's
 * sparkline_aria string. Without one the chart is aria-hidden, since it has
 * no name to announce.
 *
 * @module    block_feedback_tracker/components/Sparkline
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/** Minimum width (user units) at which the zone text label is drawn. */
const ZONE_LABEL_MIN_WIDTH = 110;

/**
 * @param {object} props
 * @param {Array<number|null>} props.values  Ordered (oldest → newest).
 * @param {number|null} [props.goal]         SLA goal hours; drives the zone.
 * @param {number} [props.width]
 * @param {number} [props.height]
 * @param {string} [props.color]             Polyline stroke (band colour).
 * @param {string} [props.zonelabel]         Discreet "improvement zone" caption.
 * @param {string} [props.arialabel]         Accessible name (i18n sparkline_aria).
 * @returns {object} vnode
 */
export default function Sparkline({values, goal = null, width = 120, height = 30,
    color = '#6366f1', zonelabel = '', arialabel = ''}) {
    const arr = Array.isArray(values) ? values : [];
    const valid = arr.filter((v) => v !== null && v !== undefined);
    const haszone = goal !== null && goal !== undefined && Number(goal) > 0;

    const min = 0;
    let max = valid.length > 0 ? Math.max.apply(null, valid) : 0;
    if (haszone) {
        max = Math.max(max, Number(goal));
    }
    if (max <= min) {
        max = min + 1;
    }

    // Inverted (speed) axis: fewer hours → smaller y → higher on the chart.
    const yfor = (v) => ((Number(v) - min) / (max - min)) * height;
    const zoney = haszone ? yfor(goal) : 0;
    const showlabel = haszone && zonelabel && width >= ZONE_LABEL_MIN_WIDTH;

    const n = arr.length;
    const stride = n > 1 ? width / (n - 1) : 0;
    const points = [];
    arr.forEach((v, i) => {
        if (v === null || v === undefined) {
            return;
        }
        points.push((i * stride).toFixed(2) + ',' + yfor(v).toFixed(2));
    });

    return html`
        <svg class="bft-sparkline"
             viewBox=${'0 0 ' + width + ' ' + height}
             width=${width} height=${height}
             role=${arialabel ? 'img' : null}
             aria-label=${arialabel || null}
             aria-hidden=${arialabel ? null : 'true'}>
            ${haszone && html`
                <rect class="bft-sparkline-zone"
                      x="0" y="0"
                      width=${width} height=${zoney.toFixed(2)} />
                <line class="bft-sparkline-zone-base"
                      x1="0" y1="0" x2=${width} y2="0" />
                <line class="bft-sparkline-zone-top"
                      x1="0" y1=${zoney.toFixed(2)} x2=${width} y2=${zoney.toFixed(2)} />
            `}
            ${points.length > 0
                ? html`<polyline class="bft-sparkline-line" fill="none" stroke=${color}
                          stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                          points=${points.join(' ')} />`
                : html`<line x1="0" y1=${height} x2=${width} y2=${height}
                          stroke="var(--bft-border-strong, #cbd5e1)" stroke-width="0.5" />`}
            ${showlabel && html`
                <text class="bft-sparkline-zone-label" x="2" y="9">${zonelabel}</text>
            `}
        </svg>
    `;
}
