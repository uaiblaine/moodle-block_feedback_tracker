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
 * Status distribution bar. Segmented buttons sized proportionally to the count
 * in each band; click a segment to filter the table. Mode-aware:
 *
 *  - pending: three segments (Waiting / Attention / Priority) over the pending
 *    bands, plus a trailing "Graded" button that switches the table to the
 *    graded view.
 *  - graded: three segments (On goal / Good / Regular) over the slabucket
 *    result bands, plus a "Pending" button back to the pending view.
 *
 * The counts come from the web service so they reflect every matching row, not
 * just the loaded page. The bar renders even when a mode has no rows so the
 * mode toggle stays reachable.
 *
 * @module    block_feedback_tracker/components/StatusDistributionBar
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import {formatCount} from 'block_feedback_tracker/lib/format';

/**
 * Segment descriptors per mode. `slug` is the filter value sent to the table
 * (the WS `band` for pending, `bucket` for graded); `tone` drives the colour
 * class.
 *
 * @param {string} mode
 * @param {object} i18n
 * @returns {Array<{slug: string, label: string, tone: string}>}
 */
const segmentsFor = (mode, i18n) => {
    if (mode === 'graded') {
        // Three-band result set (Excellent / Good / Regular) reusing the
        // academic-days vocabulary; critical graded results fold into Regular.
        return [
            {slug: 'excellent', label: i18n.acaday_legend_ongoal || 'On goal', tone: 'excellent'},
            {slug: 'good', label: i18n.acaday_legend_good || 'Good', tone: 'good'},
            {slug: 'regular', label: i18n.acaday_legend_regular || 'Regular', tone: 'regular'},
        ];
    }
    return [
        {slug: 'aguardando', label: i18n.card_pending || 'Waiting', tone: 'aguardando'},
        {slug: 'atencao', label: i18n.card_overgoal || 'Attention', tone: 'atencao'},
        {slug: 'prioridade', label: i18n.card_critical || 'Priority', tone: 'prioridade'},
    ];
};

/**
 * @param {object} props
 * @param {string} props.mode                    'pending' | 'graded'.
 * @param {Object<string, number>} props.counts  Per-band counts for the mode.
 * @param {string} props.active                  Currently-filtered band slug ('' = none).
 * @param {(slug: string) => void} props.onSelect  Filter click — passes '' to clear.
 * @param {(mode: string) => void} props.onModeChange  Pending ↔ graded toggle.
 * @param {object} props.i18n                    Label bundle.
 * @returns {object} vnode
 */
export default function StatusDistributionBar({mode, counts, active, onSelect, onModeChange, i18n}) {
    const safecounts = counts || {};
    const segments = segmentsFor(mode, i18n);
    const total = segments.reduce((sum, s) => sum + (Number(safecounts[s.slug]) || 0), 0);
    const graded = mode === 'graded';

    return html`
        <div class="bft-dist">
            <div class="bft-dist-head">
                <span class="bft-dist-title">
                    ${graded
                        ? (i18n.distribution_title_result || 'Distribution by result')
                        : (i18n.distribution_title || 'Distribution by status')}
                </span>
                <span class="bft-dist-hint">
                    ${i18n.distribution_hint || 'Click a band to filter the table'}
                </span>
            </div>
            <div class="bft-dist-bar" role="group">
                ${segments.map((seg) => {
                    const n = Number(safecounts[seg.slug]) || 0;
                    const pct = total > 0 ? (n / total) * 100 : (100 / segments.length);
                    const dim = active !== '' && active !== seg.slug;
                    return html`
                        <button type="button"
                                key=${'d-' + seg.slug}
                                class=${'bft-dist-seg bft-dist-seg-' + seg.tone + (dim ? ' bft-dist-seg-dim' : '')}
                                style=${'flex: ' + Math.max(pct, 1).toFixed(2) + ' 1 0;'}
                                aria-pressed=${active === seg.slug}
                                aria-label=${seg.label + ' — ' + formatCount(n)}
                                onClick=${() => onSelect(active === seg.slug ? '' : seg.slug)}>
                            <span class="bft-dist-seg-n bft-mono">${formatCount(n)}</span>
                            <span class="bft-dist-seg-l">${seg.label}</span>
                        </button>
                    `;
                })}
                <button type="button"
                        class=${'bft-dist-modebtn' + (graded ? ' bft-dist-modebtn-active' : '')}
                        aria-pressed=${graded}
                        onClick=${() => onModeChange(graded ? 'pending' : 'graded')}>
                    ${graded
                        ? '← ' + (i18n.pendingreport_mode_pending || 'Pending')
                        : (i18n.pendingreport_mode_graded || 'Graded') + ' →'}
                </button>
            </div>
        </div>
    `;
}
