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
 * Responsiveness-band → colour and CSS-class lookups, and the client-side
 * score → band classifier.
 *
 * @module    block_feedback_tracker/lib/bands
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Band slug → light-mode colour, the fallback colourFor() gives when the
 * page declares no --bft-band-<slug>-fg token. The tokens in styles.css hold
 * the same values in light mode (nodata aside) plus a dark-mode set. The
 * slugs are frozen identifiers shared with the PHP side;
 * tests/lockstep/js_php_lockstep_test.php fails when a slug is added or
 * dropped here without its band_<slug> lang string and bundle label, and
 * tests/lockstep/stylesheet_contract_test.php when styles.css lacks its
 * tokens and classes.
 *
 * @type {Object<string, string>}
 */
export const BAND_COLOURS = {
    excellent: '#047857',
    good:      '#0e7490',
    regular:   '#b45309',
    critical:  '#be4b25',
    pending:   '#475569',
    nodata:    '#94a3b8',
};

/**
 * The colour of a band's text and marks, as a CSS value: the band's
 * --bft-band-<slug>-fg token from styles.css, which changes with the theme's
 * dark mode, with the BAND_COLOURS value as the fallback where no token is
 * declared. An unknown or null band reads as "pending". Style declarations
 * and SVG presentation attributes both resolve var().
 *
 * @param {string|null|undefined} band
 * @returns {string} e.g. "var(--bft-band-good-fg, #0e7490)"
 */
export const colourFor = (band) => {
    const slug = BAND_COLOURS[band] ? band : 'pending';
    return 'var(--bft-band-' + slug + '-fg, ' + BAND_COLOURS[slug] + ')';
};

/**
 * The CSS class used by the existing styles.css badge pills
 * (.bft-badge-excellent, .bft-badge-good, ...). Returns the "pending" suffix
 * when the band is null/unknown so styling stays defined.
 *
 * @param {string|null|undefined} band
 * @returns {string} e.g. "bft-badge-good"
 */
export const badgeClass = (band) => 'bft-badge-' + (BAND_COLOURS[band] ? band : 'pending');

/**
 * Default score-band cutoffs. Must equal the fallback in
 * responsiveness_calculator::parse_thresholds_band() (pinned by the lockstep test).
 *
 * @type {{excellent: number, good: number, regular: number}}
 */
export const DEFAULT_SCORE_THRESHOLDS = {
    excellent: 90,
    good:      70,
    regular:   40,
};

/**
 * Classify a 0..100 responsiveness score into a band slug. Pass the bootstrap
 * bundle's `config.score_thresholds`; missing cutoffs fall back to
 * DEFAULT_SCORE_THRESHOLDS. Server payloads already carry their band, so this
 * serves client-derived scores (averages across groups, the simulator) and
 * rows that arrive without a band.
 *
 * @param {number|null|undefined} score
 * @param {{excellent?: number, good?: number, regular?: number}} [thresholds]
 * @returns {string} band slug
 */
export const bandForScore = (score, thresholds) => {
    if (score === null || score === undefined || Number.isNaN(Number(score))) {
        return 'pending';
    }
    const t = thresholds || DEFAULT_SCORE_THRESHOLDS;
    const excellent = Number(t.excellent ?? DEFAULT_SCORE_THRESHOLDS.excellent);
    const good = Number(t.good ?? DEFAULT_SCORE_THRESHOLDS.good);
    const regular = Number(t.regular ?? DEFAULT_SCORE_THRESHOLDS.regular);
    const n = Number(score);
    if (n >= excellent) {
        return 'excellent';
    }
    if (n >= good) {
        return 'good';
    }
    if (n >= regular) {
        return 'regular';
    }
    return 'critical';
};
