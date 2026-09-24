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
 * Insight card — the dashboard's "Bright spot / Most improved / Gentle watch"
 * row. Each tone has its own accent colour and decorative icon.
 *
 * Stateless. `metric_value` and `metric_suffix` keep the get_insights key
 * names so callers pass those fields straight through.
 *
 * @module    block_feedback_tracker/components/InsightCard
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import Sparkle from 'block_feedback_tracker/components/Sparkle';

/**
 * Render the variant-specific icon. The bright variant uses a sparkle, the
 * climbing variant uses an up-arrow, the watch variant uses a flag.
 *
 * @param {string} tone  'bright' | 'climbing' | 'watch'
 * @returns {object} vnode
 */
const VariantIcon = ({tone}) => {
    if (tone === 'climbing') {
        return html`
            <svg width="11" height="11" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="3"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="6 14 12 8 18 14" />
            </svg>
        `;
    }
    if (tone === 'watch') {
        return html`
            <svg width="11" height="11" viewBox="0 0 24 24"
                 fill="currentColor" aria-hidden="true">
                <path d="M4 2 v20 M4 4 h13 l-2 4 l2 4 H4" />
            </svg>
        `;
    }
    return html`<${Sparkle} size=${11} />`;
};

/**
 * @param {object} props
 * @param {'bright'|'climbing'|'watch'} props.tone
 * @param {string} props.eyebrow      Uppercase variant label (e.g. "MOST IMPROVED").
 * @param {string} props.title        Course name.
 * @param {string} [props.grouplabel] Group line shown under the title when the
 *                                    insight refers to a specific named group
 *                                    (disambiguates multi-group teachers).
 * @param {string|number} props.metric_value
 * @param {string} props.metric_suffix Caption to the right of the metric (e.g. "/ 100").
 * @param {string} props.body         Free-form supporting line.
 * @returns {object} vnode
 */
export default function InsightCard({tone, eyebrow, title, grouplabel, metric_value: metricValue,
    metric_suffix: metricSuffix, body}) {
    const cls = 'bft-insight bft-insight-tone-' + tone;
    return html`
        <div class=${cls}>
            <div class="bft-insight-head">
                <span class="bft-insight-eyebrow">
                    <${VariantIcon} tone=${tone} />
                    ${eyebrow}
                </span>
            </div>
            <div class="bft-insight-title">${title}</div>
            ${grouplabel && html`<div class="bft-insight-group">${grouplabel}</div>`}
            <div class="bft-insight-metric">
                <span class="bft-insight-metric-value bft-mono">${metricValue}</span>
                ${metricSuffix && html`<span class="bft-insight-metric-suffix bft-mono">${metricSuffix}</span>`}
            </div>
            <div class="bft-insight-body">${body}</div>
        </div>
    `;
}
