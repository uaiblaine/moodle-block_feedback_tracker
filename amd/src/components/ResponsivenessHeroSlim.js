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
 * Slim variant of the responsiveness hero — a single horizontal strip shown
 * when the user collapses the full hero.
 *
 * Stateless; ResponsivenessModule picks this or the full variant from the
 * collapsed state its calling view owns.
 *
 * @module    block_feedback_tracker/components/ResponsivenessHeroSlim
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import ScoreRing from 'block_feedback_tracker/components/ScoreRing';
import Badge from 'block_feedback_tracker/components/Badge';
import {formatHours, formatDays, usesDays} from 'block_feedback_tracker/lib/format';
import {classifySpeed} from 'block_feedback_tracker/lib/trend';

/**
 * @param {object} props
 * @param {number|null} props.score
 * @param {string|null} props.band
 * @param {string} props.bandlabel
 * @param {number|null} props.effectivehours
 * @param {number|null} [props.effectivedays] Median elapsed business days (date-based).
 * @param {string} props.perceivedlabel
 * @param {number|null} props.compliancepct
 * @param {number|null} props.trendpct
 * @param {object} props.i18n
 * @param {() => void} props.onExpand   Click handler for the trailing expand button.
 * @param {string} [props.eyebrow]      Overrides the default caption copy.
 * @param {object} [props.config]       Config bundle (effective-time display unit).
 * @returns {object} vnode
 */
export default function ResponsivenessHeroSlim({score, band, bandlabel, effectivehours, effectivedays,
    perceivedlabel, compliancepct, trendpct, i18n, onExpand, eyebrow, config}) {
    const display = score === null || score === undefined ? '—' : Math.round(Number(score));
    const trend = classifySpeed(trendpct);
    const trendTone = trend.colour;
    let trendText = '—';
    if (trend.n !== null) {
        trendText = trend.tone === 'stable' ? trend.arrow : trend.arrow + ' ' + trend.magnitude;
    }

    return html`
        <section class=${'bft-rh-slim bft-rh-tone-' + (band || 'pending')}>
            <div class="bft-rh-slim-hero">
                <${ScoreRing} score=${score} band=${band} size=${48} thickness=${5} />
                <div class="bft-rh-slim-text">
                    <div class="bft-rh-slim-row">
                        <span class=${'bft-rh-slim-score bft-mono bft-overall-score-tone-' + (band || 'pending')}>
                            ${display}
                        </span>
                        <span class="bft-rh-slim-of bft-mono">/ 100</span>
                    </div>
                    <span class="bft-rh-slim-caption">
                        ${eyebrow || i18n.dashboard_hero_eyebrow || 'Academic responsiveness'}
                    </span>
                </div>
            </div>

            <div class="bft-rh-slim-divider"></div>
            <${Badge} band=${band} label=${bandlabel} />

            <div class="bft-rh-slim-divider"></div>
            <div class="bft-rh-slim-stat">
                <div class="bft-rh-slim-stat-label">${i18n.hero_effective_eyebrow || 'Effective'}</div>
                <div class=${'bft-rh-slim-stat-val bft-mono bft-overall-score-tone-' + (band || 'pending')}>
                    ${usesDays(config) ? formatDays(effectivedays) : formatHours(effectivehours)}
                </div>
            </div>

            <div class="bft-rh-slim-divider"></div>
            <div class="bft-rh-slim-stat">
                <div class="bft-rh-slim-stat-label">${i18n.hero_perceived_label || 'Perceived'}</div>
                <div class="bft-rh-slim-stat-val bft-mono bft-overall-score-tone-pending">
                    ${perceivedlabel}
                </div>
            </div>

            <div class="bft-rh-slim-divider"></div>
            <div class="bft-rh-slim-stat">
                <div class="bft-rh-slim-stat-label">${i18n.hero_sla_eyebrow || 'SLA'}</div>
                <div class=${'bft-rh-slim-stat-val bft-mono bft-overall-score-tone-' + (band || 'pending')}>
                    ${compliancepct === null || compliancepct === undefined
                        ? '—'
                        : Math.round(compliancepct) + '%'}
                </div>
            </div>

            <div class="bft-rh-slim-divider"></div>
            <div class="bft-rh-slim-stat">
                <div class="bft-rh-slim-stat-label">${i18n.hero_trend_eyebrow || 'Trend'}</div>
                <div class=${'bft-rh-slim-stat-val bft-mono bft-overall-score-tone-' + trendTone}>
                    ${trendText}
                </div>
            </div>

            <div class="bft-rh-slim-spacer"></div>

            <button type="button"
                    class="bft-rh-slim-expand"
                    onClick=${onExpand}
                    aria-label=${i18n.dashboard_expand || 'Expand'}>
                ${i18n.dashboard_expand || 'Expand'}
            </button>
        </section>
    `;
}
