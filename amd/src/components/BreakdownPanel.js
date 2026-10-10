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
 * Collapsible score-breakdown panel — term / value / weight / points table.
 *
 * Keeps its open/closed flag in local state (closed by default). Every value
 * arrives pre-formatted.
 *
 * @module    block_feedback_tracker/components/BreakdownPanel
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState} from 'block_feedback_tracker/lib/preact';

/**
 * @typedef {object} BreakdownRow
 * @property {string} label
 * @property {string} valuestr
 * @property {string} weightstr
 * @property {string} ptsstr
 */

/**
 * @param {object} props
 * @param {string} props.summary
 * @param {string} props.strterm
 * @param {string} props.strvalue
 * @param {string} props.strweight
 * @param {string} props.strpts
 * @param {string} props.strtotal
 * @param {Array<BreakdownRow>} props.rows
 * @param {string} props.totalstr
 * @param {string} [props.footnote]  Shown below the table when set; explains
 *                                   any excluded-by-insufficient-data terms.
 * @returns {object} vnode
 */
export default function BreakdownPanel({
    summary, strterm, strvalue, strweight, strpts, strtotal, rows, totalstr, footnote,
}) {
    const [open, setOpen] = useState(false);
    return html`
        <div class="bft-breakdown">
            <button type="button"
                    class="bft-breakdown-toggle"
                    aria-expanded=${open ? 'true' : 'false'}
                    onClick=${() => setOpen(!open)}>
                ${(open ? '▾ ' : '▸ ') + summary}
            </button>
            ${open && html`
                <table class="bft-breakdown-table">
                    <thead>
                        <tr>
                            <th>${strterm}</th>
                            <th class="bft-breakdown-num">${strvalue}</th>
                            <th class="bft-breakdown-num">${strweight}</th>
                            <th class="bft-breakdown-num">${strpts}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${(rows || []).map((r) => html`
                            <tr key=${r.label}>
                                <td>${r.label}</td>
                                <td class="bft-breakdown-num">${r.valuestr}</td>
                                <td class="bft-breakdown-num">${r.weightstr}</td>
                                <td class="bft-breakdown-num">${r.ptsstr}</td>
                            </tr>
                        `)}
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3">${strtotal}</th>
                            <th class="bft-breakdown-num">${totalstr}</th>
                        </tr>
                    </tfoot>
                </table>
            `}
            ${open && footnote && html`
                <div class="bft-breakdown-footnote">${footnote}</div>
            `}
        </div>
    `;
}
