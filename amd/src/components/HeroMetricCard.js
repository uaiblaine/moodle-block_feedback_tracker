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
 * One card in the report page's hero metrics row. Used four-up at the top
 * of the pending report (Score / Effective / SLA / Trend).
 *
 * Children-driven: caller renders whatever main number / chip / spark it
 * wants; this component owns only the surrounding card chrome + eyebrow.
 *
 * @module    block_feedback_tracker/components/HeroMetricCard
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * @param {object} props
 * @param {string} props.eyebrow  Uppercase label above the card body.
 * @param {string} [props.tip]    Optional tooltip text for an info dot.
 * @param {boolean} [props.wide]  When true, the card spans 1.5fr in its grid (use for the Score card).
 * @param {*} props.children      Card body content.
 * @returns {object} vnode
 */
export default function HeroMetricCard({eyebrow, tip, wide, children}) {
    return html`
        <div class=${'bft-hero-card' + (wide ? ' bft-hero-card-wide' : '')}>
            <div class="bft-hero-card-eyebrow">
                <span>${eyebrow}</span>
                ${tip && html`<button type="button" class="bft-info-dot"
                    title=${tip} aria-label=${tip}>i</button>`}
            </div>
            <div class="bft-hero-card-body">${children}</div>
        </div>
    `;
}
