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
 * Trend row — arrow + percentage + verbal label + 14-day sparkline.
 *
 * `pct` is the week-over-week change in median effective hours (last 7 days
 * vs the 7 before); negative means work was returned faster. The speed tone,
 * arrow and unsigned magnitude come from lib/trend.js::classifySpeed().
 *
 * @module    block_feedback_tracker/components/TrendRow
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import Sparkline from 'block_feedback_tracker/components/Sparkline';
import {classifySpeed, speedLabel} from 'block_feedback_tracker/lib/trend';

/**
 * @param {object} props
 * @param {number|null|undefined} props.pct  Trend percentage; negative = faster.
 * @param {Array<number|null>} props.series  14-day sparkline values.
 * @param {object} props.i18n  Bundle with trend_faster / trend_slower / trend_stable / trend_window_label /
 *                             sparkline_aria.
 * @param {number|null} [props.goal]  Optional SLA goal line on the sparkline.
 * @returns {object|null} vnode
 */
export default function TrendRow({pct, series, i18n, goal}) {
    const {tone, arrow, magnitude} = classifySpeed(pct);
    const label = speedLabel(tone, i18n);
    const hasSeries = Array.isArray(series) && series.some((v) => v !== null && v !== undefined);

    return html`
        <div class=${'bft-trend-row bft-trend-tone-' + tone}>
            <div class="bft-trend-row-text">
                <div class="bft-trend-row-eyebrow">${i18n.trend_window_label || 'Last 14 days'}</div>
                <div class="bft-trend-row-line">
                    <span class="bft-trend-row-arrow">${arrow}</span>
                    ${tone !== 'stable'
                        && html`<span class="bft-trend-row-pct bft-mono">${magnitude}</span>`}
                    <span class="bft-trend-row-label">${label}</span>
                </div>
            </div>
            ${hasSeries && html`
                <div class="bft-trend-row-spark">
                    <${Sparkline} values=${series} goal=${goal} width=${96} height=${28}
                        arialabel=${i18n.sparkline_aria} />
                </div>
            `}
        </div>
    `;
}
