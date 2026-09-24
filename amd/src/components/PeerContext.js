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
 * Peer context — supportive (not punitive) comparison panel. Three rows:
 * You (highlighted), Department (median), Top 10% (aspirational).
 *
 * Hidden entirely when neither department nor top10 is supplied, which is
 * what peer_stats returns below its minimum sample of other scored groups.
 * Accepts nulls so callers can pass payload fields directly.
 *
 * @module    block_feedback_tracker/components/PeerContext
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import {colourFor} from 'block_feedback_tracker/lib/bands';

/**
 * Render a single peer-row bar (score only, clamped to 0..100).
 *
 * @param {object} props
 * @param {string} props.label
 * @param {number|null|undefined} props.score
 * @param {string|null|undefined} props.tone
 * @param {boolean} [props.highlight]
 * @param {boolean} [props.aspirational]
 * @returns {object} vnode
 */
function PeerLine({label, score, tone, highlight, aspirational}) {
    const clamped = score === null || score === undefined
        ? null : Math.max(0, Math.min(100, Number(score)));
    const widthpct = clamped === null ? 0 : clamped;
    const colour = colourFor(tone);
    const rowcls = 'bft-peer-line'
        + (highlight ? ' bft-peer-line-highlight' : '')
        + (aspirational ? ' bft-peer-line-aspirational' : '');
    return html`
        <div class=${rowcls}>
            <span class="bft-peer-label">${label}</span>
            <div class="bft-peer-track">
                <div class="bft-peer-fill"
                     style=${'width: ' + widthpct + '%; background: ' + colour + ';'}></div>
            </div>
            <span class="bft-peer-score bft-mono">
                ${clamped === null ? '—' : Math.round(clamped)}
            </span>
        </div>
    `;
}

/**
 * @param {object} props
 * @param {number|null} props.you            Caller's score 0..100.
 * @param {string|null} props.youband        Caller's band slug (for colour).
 * @param {number|null|undefined} props.department  Department median score.
 * @param {number|null|undefined} props.top10  Top-10% benchmark score.
 * @param {object} props.i18n  Label bundle: {peer_title, peer_you, peer_department, peer_top10}.
 * @returns {object|null} vnode
 */
export default function PeerContext({you, youband, department, top10, i18n}) {
    const hasdept = department !== null && department !== undefined;
    const hastop10 = top10 !== null && top10 !== undefined;
    if (!hasdept && !hastop10) {
        return null;
    }
    return html`
        <div class="bft-peer-context">
            <div class="bft-peer-context-head">${i18n.peer_title || 'Peer context'}</div>
            <${PeerLine}
                label=${i18n.peer_you || 'You'}
                score=${you}
                tone=${youband} highlight=${true} />
            ${hasdept && html`
                <${PeerLine}
                    label=${i18n.peer_department || 'Department'}
                    score=${department}
                    tone="pending" />
            `}
            ${hastop10 && html`
                <${PeerLine}
                    label=${i18n.peer_top10 || 'Top 10%'}
                    score=${top10}
                    tone="excellent" aspirational=${true} />
            `}
        </div>
    `;
}
