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
 * Segmented-control filter. Tightly-packed pill row where exactly one
 * button is active at a time.
 *
 * Stateless — the caller owns the selected value.
 *
 * @module    block_feedback_tracker/components/SegmentedFilter
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * @param {object} props
 * @param {Array<{value: string, label: string, tone?: string}>} props.options
 * @param {string} props.value       Currently-selected value.
 * @param {(v: string) => void} props.onChange
 * @param {string} [props.ariaLabel] Optional accessible group label.
 * @returns {object} vnode
 */
export default function SegmentedFilter({options, value, onChange, ariaLabel}) {
    return html`
        <div class="bft-segmented" role="group" aria-label=${ariaLabel}>
            ${options.map((opt) => {
                const active = opt.value === value;
                const toneCls = opt.tone ? ' bft-segmented-btn-tone-' + opt.tone : '';
                return html`
                    <button type="button"
                            key=${'s-' + opt.value}
                            class=${'bft-segmented-btn' + toneCls + (active ? ' is-active' : '')}
                            aria-pressed=${active}
                            onClick=${() => onChange(opt.value)}>
                        ${opt.label}
                    </button>
                `;
            })}
        </div>
    `;
}
