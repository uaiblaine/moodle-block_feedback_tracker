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

/** Slug → display label; hard-coded because this demo loads no lang strings. */
const BAND_LABEL = {
    excellent: 'Excellent',
    good: 'Good',
    regular: 'Up Next',
    critical: 'Priority',
    pending: 'Pending',
};

/** Demo breakdown rows in the BreakdownPanel row shape. */
const BREAKDOWN_ROWS = [
    {label: 'Compliance', valuestr: '0.78', weightstr: '0.40', ptsstr: '31.2 / 40.0'},
    {label: 'Median', valuestr: '0.66', weightstr: '0.25', ptsstr: '16.5 / 25.0'},
    {label: 'Priority', valuestr: '0.40', weightstr: '0.15', ptsstr: '6.0 / 15.0'},
    {label: 'Pending', valuestr: '0.60', weightstr: '0.10', ptsstr: '6.0 / 10.0'},
    {label: 'Trend', valuestr: '0.50', weightstr: '0.10', ptsstr: '5.0 / 10.0'},
];

/**
 * Top-level demo component. Uses useState to make the score re-rollable,
 * proving the hook plumbing reaches all the way through.
 *
 * @returns {object} vnode
 */
const Demo = () => {
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
                    <span class="bft-card-title">Spike demo (React)</span>
                    <${Badge} band=${band} label=${BAND_LABEL[band]} />
                </div>
                <div class="bft-card-body">
                    <div class="bft-card-gauge">
                        <${ScoreGauge} score=${score} band=${band} size=${100} />
                    </div>
                    <div class="bft-card-data">
                        <${Counts} items=${[
                            {label: 'Pending', value: 12},
                            {label: 'Priority', value: 3},
                            {label: 'Over goal', value: 5},
                        ]} />
                        <${MetricsRow} items=${[
                            {label: 'Median', value: '8.4 h'},
                            {label: 'Compliance', value: '78%'},
                            {label: 'Trend 30d', value: '▼ 12%'},
                        ]} />
                        <div class="bft-card-sparkline">
                            <${Sparkline} values=${TREND} goal=${24} width=${240} height=${30} />
                        </div>
                    </div>
                </div>
                <div class="bft-card-foot">
                    <${BreakdownPanel}
                        summary="Score breakdown"
                        strterm="Term"
                        strvalue="Value"
                        strweight="Weight"
                        strpts="Points"
                        strtotal="Total"
                        rows=${BREAKDOWN_ROWS}
                        totalstr="64.7 / 100.0" />
                </div>
                <div class="bft-card-foot">
                    <button type="button" class="bft-refresh"
                            onClick=${() => setScore(Math.floor(Math.random() * 100))}>
                        Re-roll score
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
        render(html`<${Demo} />`, root);
    });
};
