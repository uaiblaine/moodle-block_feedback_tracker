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
 * SVG ring gauge for the Academic Responsiveness Score.
 *
 * Direct port of templates/score_gauge.mustache. The geometry maths is
 * identical to classes/output/score_gauge.php::export_for_template(); both
 * must move together if the design ever changes.
 *
 * @module    block_feedback_tracker/components/ScoreGauge
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import {colourFor} from 'block_feedback_tracker/lib/bands';

/**
 * Render the gauge.
 *
 * @param {object} props
 * @param {number|null} props.score  0..100, or null for "no data".
 * @param {string|null} props.band   Band slug — drives the stroke colour.
 * @param {number} [props.size]      Square pixel size; defaults to 100.
 * @param {string} [props.arialabel] Localised label for assistive tech. Falls
 *                                   back to English only when a caller omits
 *                                   it; every caller should pass one.
 * @returns {object} vnode
 */
export default function ScoreGauge({score, band, size = 100, arialabel = ''}) {
    const r = size / 2 - 6;
    const cx = size / 2;
    const circumference = 2 * Math.PI * r;
    const clamped = score === null || score === undefined ? null
        : Math.max(0, Math.min(100, Number(score)));
    const arc = clamped === null ? 0 : (clamped / 100) * circumference;
    const label = clamped === null ? '—' : String(Math.round(clamped));
    const colour = colourFor(band);
    const texty = cx + size * 0.07;
    const fontsize = Math.round(size * 0.28);

    return html`
        <svg class="bft-gauge"
             viewBox="0 0 ${size} ${size}"
             width=${size} height=${size}
             role="img" aria-label=${arialabel || ('Responsiveness score ' + label)}>
            <circle cx=${cx} cy=${cx} r=${r.toFixed(2)}
                    fill="none" stroke="#e5e7eb" stroke-width="8" />
            <circle cx=${cx} cy=${cx} r=${r.toFixed(2)}
                    fill="none" stroke=${colour} stroke-width="8"
                    stroke-linecap="round"
                    stroke-dasharray=${arc.toFixed(2) + ' ' + circumference.toFixed(2)}
                    transform=${'rotate(-90 ' + cx + ' ' + cx + ')'} />
            <text x=${cx} y=${texty.toFixed(2)}
                  text-anchor="middle" font-size=${fontsize}
                  font-weight="700" fill="#0f172a">${label}</text>
        </svg>
    `;
}
