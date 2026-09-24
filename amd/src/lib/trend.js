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
 * "Speed" model for the trend signal — shared by the Preact components
 * (TrendRow, the responsiveness heroes, the simulator) so the sign, arrow,
 * colour and wording never drift apart. The server no-JS card
 * (classes/output/responsiveness_card.php) and format.js formatTrend keep
 * their own arrow rule, without the ±2% dead band.
 *
 * The trend percentage is the change in median effective hours over the
 * window (negative = fewer hours = work returned faster). For display we
 * speak in terms of speed: faster = ▲ green, slower = ▼ red, within ±2% =
 * → muted. The magnitude is always shown unsigned — direction is carried by
 * the arrow, colour and label, never by a leading +/−.
 *
 * @module    block_feedback_tracker/lib/trend
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Dead-band (in percentage points) within which a change reads as "stable". */
export const STABLE_BAND = 2;

/** Arrow glyph per tone. */
const TONEARROWS = {faster: '▲', slower: '▼', stable: '→'};

/** Tone → bft-overall-score-tone-* colour class slug (see styles.css). */
const TONECOLOURS = {faster: 'excellent', slower: 'critical', stable: 'pending'};

/**
 * Resolve the speed tone for a normalised trend number.
 *
 * @param {number|null} n  Normalised percentage (negative = faster) or null.
 * @returns {('faster'|'slower'|'stable')}
 */
const classifyTone = (n) => {
    if (n === null || Math.abs(n) < STABLE_BAND) {
        return 'stable';
    }
    return n < 0 ? 'faster' : 'slower';
};

/**
 * Classify a trend percentage into the speed model.
 *
 * @param {number|null|undefined} pct  Change in median effective hours (negative = faster).
 * @returns {{n: number|null, tone: ('faster'|'slower'|'stable'), arrow: string,
 *            colour: string, magnitude: string}}
 */
export const classifySpeed = (pct) => {
    const n = (pct === null || pct === undefined || Number.isNaN(Number(pct))) ? null : Number(pct);
    const tone = classifyTone(n);
    return {
        n,
        tone,
        arrow: TONEARROWS[tone],
        colour: TONECOLOURS[tone],
        // Unsigned magnitude; '—' when there is no data to compare.
        magnitude: n === null ? '—' : Math.round(Math.abs(n)) + '%',
    };
};

/**
 * Localised verbal label for a tone, from the i18n bundle.
 *
 * @param {('faster'|'slower'|'stable')} tone
 * @param {object} [i18n]  Bundle with trend_faster / trend_slower / trend_stable.
 * @returns {string}
 */
export const speedLabel = (tone, i18n = {}) => {
    if (tone === 'faster') {
        return i18n.trend_faster || 'faster';
    }
    if (tone === 'slower') {
        return i18n.trend_slower || 'slower';
    }
    return i18n.trend_stable || 'stable';
};
