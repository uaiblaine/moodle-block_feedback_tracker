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
 * Component smoke test — mounts a demo card built from the shared components
 * into every [data-bft-spike-root] div on pages/spike_react.php, so a site
 * admin can check that the built Preact + htm pipeline works under Moodle's
 * AMD loader.
 *
 * A successful render shows that:
 *  - The vendored bundle exposed window.bftPreact / bftPreactHooks / bftHtm.
 *  - The lib/preact shim re-exports them as ES named exports.
 *  - h() + render() + htm tagged templates produce real DOM.
 *  - useState works: the re-roll button re-renders ScoreGauge.
 *  - Multi-root mount: every [data-bft-spike-root] is initialised.
 *
 * @module    block_feedback_tracker/spike_react
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {render, html, useState} from 'block_feedback_tracker/lib/preact';
import ScoreGauge from 'block_feedback_tracker/components/ScoreGauge';
import Sparkline from 'block_feedback_tracker/components/Sparkline';
import Badge from 'block_feedback_tracker/components/Badge';
import Counts from 'block_feedback_tracker/components/Counts';
import MetricsRow from 'block_feedback_tracker/components/MetricsRow';
import BreakdownPanel from 'block_feedback_tracker/components/BreakdownPanel';

/** Demo trend series — 30 points with a couple of null gaps. */
const TREND = [
    18, 22, 19, 17, 21, 16, null, 14, 15, 13,
    null, 12, 11, 13, 10, 9, 8, 9, 7, 10,
    8, 6, 7, 6, 5, 7, 6, 4, 5, 6,
];

/**
 * Demo breakdown rows in the BreakdownPanel row shape, labelled with the
 * score-term strings.
 *
 * @param {object} i18n The page's label bundle.
 * @returns {Array<object>} rows
 */
const breakdownRows = (i18n) => [
    {label: i18n.breakdown_compliance, valuestr: '0.78', weightstr: '0.40', ptsstr: '31.2 / 40.0'},
    {label: i18n.breakdown_median, valuestr: '0.66', weightstr: '0.25', ptsstr: '16.5 / 25.0'},
    {label: i18n.breakdown_critical, valuestr: '0.40', weightstr: '0.15', ptsstr: '6.0 / 15.0'},
    {label: i18n.breakdown_pending, valuestr: '0.60', weightstr: '0.10', ptsstr: '6.0 / 10.0'},
    {label: i18n.breakdown_trend, valuestr: '0.50', weightstr: '0.10', ptsstr: '5.0 / 10.0'},
];

/**
 * The payload pages/spike_react.php embeds in each root.
 *
 * @param {HTMLElement} root
 * @returns {object|null} Parsed payload, or null when missing or malformed.
 */
const readPayload = (root) => {
    const tag = root.querySelector('script[type="application/json"][data-bft-init]');
    if (!tag) {
        return null;
    }
    try {
        return JSON.parse(tag.textContent || '{}');
    } catch (e) {
        // eslint-disable-next-line no-console
        console.error('block_feedback_tracker: spike payload parse failed', e);
        return null;
    }
};

/**
 * Top-level demo component. Uses useState to make the score re-rollable,
 * proving the hook plumbing reaches all the way through.
 *
 * @param {object} props
 * @param {object} props.i18n The page's label bundle.
 * @returns {object} vnode
 */
const Demo = ({i18n}) => {
    const [score, setScore] = useState(72);
    let band = 'critical';
    if (score >= 85) {
        band = 'excellent';
    } else if (score >= 70) {
        band = 'good';
    } else if (score >= 50) {
        band = 'regular';
    }

    return html`
        <div class="block_feedback_tracker">
            <div class="bft-card">
                <div class="bft-card-head">
                    <span class="bft-card-title">${i18n.spike_react_title}</span>
                    <${Badge} band=${band} label=${i18n.bands[band]} />
                </div>
                <div class="bft-card-body">
                    <div class="bft-card-gauge">
                        <${ScoreGauge} score=${score} band=${band} size=${100} arialabel=${i18n.gauge_aria} />
                    </div>
                    <div class="bft-card-data">
                        <${Counts} items=${[
                            {label: i18n.card_pending, value: 12},
                            {label: i18n.card_critical, value: 3},
                            {label: i18n.card_overgoal, value: 5},
                        ]} />
                        <${MetricsRow} items=${[
                            {label: i18n.breakdown_median, value: '8.4 h'},
                            {label: i18n.breakdown_compliance, value: '78%'},
                            {label: i18n.breakdown_trend, value: '▼ 12%'},
                        ]} />
                        <div class="bft-card-sparkline">
                            <${Sparkline} values=${TREND} goal=${24} width=${240} height=${30}
                                arialabel=${i18n.sparkline_aria} />
                        </div>
                    </div>
                </div>
                <div class="bft-card-foot">
                    <${BreakdownPanel}
                        summary=${i18n.breakdown_summary}
                        strterm=${i18n.breakdown_term}
                        strvalue=${i18n.breakdown_value}
                        strweight=${i18n.breakdown_weight}
                        strpts=${i18n.breakdown_pts}
                        strtotal=${i18n.breakdown_total}
                        rows=${breakdownRows(i18n)}
                        totalstr="64.7 / 100.0" />
                </div>
                <div class="bft-card-foot">
                    <button type="button" class="bft-refresh"
                            onClick=${() => setScore(Math.floor(Math.random() * 100))}>
                        ${i18n.spike_reroll}
                    </button>
                </div>
            </div>
        </div>
    `;
};

/**
 * Initialise the spike. Idempotent — multiple init() calls are safe.
 */
export const init = () => {
    if (window.bftSpikeReactInitDone) {
        return;
    }
    window.bftSpikeReactInitDone = true;
    document.querySelectorAll('[data-bft-spike-root]').forEach((root) => {
        const initial = readPayload(root);
        if (!initial || !initial.i18n) {
            return;
        }
        render(html`<${Demo} i18n=${initial.i18n} />`, root);
    });
};
