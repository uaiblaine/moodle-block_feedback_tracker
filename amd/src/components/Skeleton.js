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
 * Shimmer placeholder cards shown while the block or the pending report is
 * loading.
 *
 * Mirrors the GroupCard silhouette (header line + hero block + metrics row)
 * so the layout doesn't jump when the real cards replace it. The stack is
 * aria-hidden: announcing the loading state is the caller's job (BlockView
 * sets aria-busy and a label on its region).
 *
 * @module    block_feedback_tracker/components/Skeleton
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * One shimmer placeholder card.
 *
 * @returns {object} vnode
 */
const SkeletonCard = () => html`
    <div class="bft-skeleton-card">
        <div class="bft-skeleton-head">
            <div class="bft-skeleton-line bft-skeleton-line-lg"></div>
            <div class="bft-skeleton-pill"></div>
        </div>
        <div class="bft-skeleton-hero"></div>
        <div class="bft-skeleton-row">
            <div class="bft-skeleton-line bft-skeleton-line-sm"></div>
            <div class="bft-skeleton-line bft-skeleton-line-sm"></div>
            <div class="bft-skeleton-line bft-skeleton-line-sm"></div>
        </div>
    </div>
`;

/**
 * A vertical stack of `count` shimmer placeholder cards.
 *
 * @param {object} props
 * @param {number} [props.count]  How many placeholder cards to render.
 * @returns {object} vnode
 */
export default function Skeleton({count = 3}) {
    const n = Math.max(1, Number(count) || 1);
    const cards = [];
    for (let i = 0; i < n; i++) {
        cards.push(html`<${SkeletonCard} key=${'sk-' + i} />`);
    }
    return html`<div class="bft-skeleton-list" aria-hidden="true">${cards}</div>`;
}
