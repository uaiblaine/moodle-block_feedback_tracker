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
 * Renders the responsiveness hero (teacher dashboard and pending report) in
 * either its full or slim variant.
 *
 * Stateless: the calling view owns `collapsed`, because other sections of
 * the page (the dashboard insights, the report's academic-days strip) hide
 * with the same toggle, and persists it in a Moodle user preference. The
 * module maps `collapsed` to a hero variant and routes the user's click to
 * `onToggle`.
 *
 * @module    block_feedback_tracker/components/ResponsivenessModule
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import ResponsivenessHero from 'block_feedback_tracker/components/ResponsivenessHero';
import ResponsivenessHeroSlim from 'block_feedback_tracker/components/ResponsivenessHeroSlim';

/**
 * @param {object} props
 * @param {boolean} props.collapsed              True = slim, false = full.
 * @param {(next: boolean) => void} props.onToggle Receives the next collapsed value.
 * @param {object} props.heroprops               Forwarded to both hero variants.
 * @returns {object} vnode
 */
export default function ResponsivenessModule({collapsed, onToggle, heroprops}) {
    if (collapsed) {
        return html`<${ResponsivenessHeroSlim} ...${heroprops}
            onExpand=${() => onToggle(false)} />`;
    }
    return html`<${ResponsivenessHero} ...${heroprops}
        onCollapse=${() => onToggle(true)} />`;
}
