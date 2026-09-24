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
 * Clickable count tile — used three-up for Waiting / Attention / Priority in
 * each group card, each linking to the pending report with that group and
 * pending band pre-filtered.
 *
 * Tone is forced to 'neutral' when the value is 0 so an all-clear group
 * reads calm.
 *
 * @module    block_feedback_tracker/components/StatTile
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import {formatCount} from 'block_feedback_tracker/lib/format';

/**
 * @param {object} props
 * @param {string} props.label   Uppercase label.
 * @param {number} props.value   Numeric count.
 * @param {'neutral'|'warn'|'critical'} [props.tone]  Visual tone; 'neutral' when value is 0.
 * @param {string} [props.href]  Optional URL — renders as <a> when present, <button> otherwise.
 * @param {() => void} [props.onClick]  Optional click handler.
 * @returns {object} vnode
 */
export default function StatTile({label, value, tone, href, onClick}) {
    const effectivetone = (Number(value) || 0) === 0 ? 'neutral' : (tone || 'neutral');
    const cls = 'bft-stat-tile bft-stat-tile-tone-' + effectivetone;
    const contents = html`
        <span class="bft-stat-tile-label">${label}</span>
        <span class="bft-stat-tile-value bft-mono">${formatCount(value)}</span>
    `;
    if (href) {
        return html`<a class=${cls} href=${href} onClick=${onClick}>${contents}</a>`;
    }
    return html`<button type="button" class=${cls} onClick=${onClick}>${contents}</button>`;
}
