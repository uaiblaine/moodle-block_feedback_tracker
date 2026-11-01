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
 * One cell in the three-column KPI grid that sits below a group's score
 * (Effective / Perceived / On target). Tiny uppercase label, mono numeric value
 * with unit, faint sub-label.
 *
 * Stateless. Tone control is via a band-slug → CSS class mapping so the
 * value picks up the design palette through `--bft-band-*-fg` tokens.
 *
 * @module    block_feedback_tracker/components/KpiTile
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * @param {object} props
 * @param {string} props.label  Uppercase eyebrow label.
 * @param {string|number} props.value  Numeric value, mono-rendered.
 * @param {string} [props.unit]  Optional unit suffix (e.g. 'h', '%', 'd').
 * @param {string} [props.sub]   Optional secondary caption.
 * @param {string} [props.tone]  Optional band slug — picks the value colour.
 * @returns {object} vnode
 */
export default function KpiTile({label, value, unit, sub, tone}) {
    const toneClass = tone ? ' bft-kpi-tile-tone-' + tone : '';
    return html`
        <div class=${'bft-kpi-tile' + toneClass}>
            <div class="bft-kpi-tile-label">${label}</div>
            <div class="bft-kpi-tile-value bft-mono">
                ${value}${unit && html`<span class="bft-kpi-tile-unit">${unit}</span>`}
            </div>
            ${sub && html`<div class="bft-kpi-tile-sub">${sub}</div>`}
        </div>
    `;
}
