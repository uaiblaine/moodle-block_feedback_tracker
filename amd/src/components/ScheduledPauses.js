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
 * Scheduled-pause notice ("Upcoming pause"). Lists up to a few upcoming
 * calendar pauses, each with its label, date / time window and type, e.g.
 *
 *   Upcoming pause:
 *   ⚽ World Cup | Brazil v Japan
 *   06/29/2026, 16:00–17:00
 *   Type: Optional
 *
 * Every entry arrives pre-formatted from the server (the `when` and
 * `typelabel` strings are localised in the platform timezone by
 * upcoming_pauses::for_display()), so this component only lays them out.
 * Renders nothing when there are no visible pauses. The `bft-rh-tone-regular`
 * class supplies the regular band's amber tone, which the `.bft-scheduled`
 * rules in styles.css paint with.
 *
 * @module    block_feedback_tracker/components/ScheduledPauses
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * @param {object} props
 * @param {Array<{start:number, type:string, label:string, when:string, typelabel:string}>} [props.pauses]
 * @param {object} props.i18n
 * @param {boolean} [props.compact]  Narrow variant for the in-course block.
 * @returns {object|null} vnode
 */
export default function ScheduledPauses({pauses, i18n, compact}) {
    const list = Array.isArray(pauses) ? pauses : [];
    if (list.length === 0) {
        return null;
    }
    const eyebrow = i18n.pause_upcoming_label || 'Upcoming pause';
    const typelabel = i18n.pause_type_label || 'Type';
    const cls = 'bft-scheduled bft-rh-tone-regular' + (compact ? ' bft-scheduled-compact' : '');

    return html`
        <section class=${cls} aria-label=${eyebrow}>
            <span class="bft-scheduled-swatch" aria-hidden="true"></span>
            <div class="bft-scheduled-body">
                <div class="bft-scheduled-eyebrow">${eyebrow}:</div>
                <ul class="bft-scheduled-list">
                    ${list.map((p) => html`
                        <li class="bft-scheduled-item" key=${'sp-' + p.start + '-' + p.type}>
                            ${p.label && html`<span class="bft-scheduled-label">${p.label}</span>`}
                            <span class="bft-scheduled-when">${p.when}</span>
                            ${p.typelabel && html`
                                <span class="bft-scheduled-type">${typelabel}: ${p.typelabel}</span>
                            `}
                        </li>
                    `)}
                </ul>
            </div>
        </section>
    `;
}
