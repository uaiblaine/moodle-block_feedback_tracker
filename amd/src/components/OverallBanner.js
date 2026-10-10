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
 * Overall responsiveness banner — sits at the top of the block view above
 * the group cards. Renders the overall_eyebrow label, a ScoreRing with the
 * big numeric score + status pill, and an optional scheduled-pause notice
 * underneath.
 *
 * The caller supplies the score: BlockView uses the whole-course aggregate
 * from get_responsiveness and falls back to a pending-weighted average of
 * the loaded cards.
 *
 * @module    block_feedback_tracker/components/OverallBanner
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import ScoreRing from 'block_feedback_tracker/components/ScoreRing';
import Badge from 'block_feedback_tracker/components/Badge';
import ScheduledPauses from 'block_feedback_tracker/components/ScheduledPauses';

/**
 * @param {object} props
 * @param {number|null} props.score   0..100, or null.
 * @param {string|null} props.band    Band slug.
 * @param {string} props.bandlabel    Localised band label.
 * @param {object} props.i18n         Label bundle.
 * @param {Array<object>} [props.pauses]  Upcoming scheduled pauses (already gated).
 * @returns {object} vnode
 */
export default function OverallBanner({score, band, bandlabel, i18n, pauses}) {
    const display = score === null || score === undefined ? '—' : Math.round(Number(score));
    return html`
        <div class="bft-overall-banner">
            <div class="bft-overall-eyebrow">${i18n.overall_eyebrow || 'Academic responsiveness'}</div>
            <div class="bft-overall-row">
                <${ScoreRing} score=${score} band=${band} size=${52} thickness=${5} />
                <div class="bft-overall-text">
                    <div class="bft-overall-score-row">
                        <span class=${'bft-overall-score bft-mono bft-overall-score-tone-' + (band || 'pending')}>
                            ${display}
                        </span>
                        <span class="bft-overall-score-of bft-mono">/ 100</span>
                    </div>
                    <${Badge} band=${band} label=${bandlabel} />
                </div>
            </div>
            <${ScheduledPauses} pauses=${pauses} i18n=${i18n} compact=${true} />
        </div>
    `;
}
