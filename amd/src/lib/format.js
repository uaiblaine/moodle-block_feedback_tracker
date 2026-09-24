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
 * Number / hours / date formatters shared by every component.
 *
 * These mirror the PHP-side rendering choices in classes/output/responsiveness_card.php:
 *   cur_median_eff_h      → "12.3 h"
 *   compliance_pct        → "78%"
 *   trend_pct_30d         → "▲ 5%" / "▼ 12%" / "→ 0%"
 *
 * @module    block_feedback_tracker/lib/format
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Em-dash returned for null / undefined / NaN values. */
const EMPTY = '—';

/**
 * Round to `digits` decimal places, return as string.
 *
 * @param {number|null|undefined} value
 * @param {number} digits
 * @returns {string}
 */
export const formatNumber = (value, digits = 0) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return EMPTY;
    }
    const n = Number(value);
    return n.toFixed(digits);
};

/**
 * Module-level thousands separator for integer counts. Set once per page from
 * the active Moodle language (langconfig 'thousandssep') via the config
 * bundle; defaults to a comma so a surface that never configures it still
 * groups sensibly.
 */
let groupingSeparator = ',';

/**
 * Set the thousands separator used by formatCount. The block, dashboard and
 * report entrypoints call this once at mount from initial.config.thousandssep
 * so the JS surfaces group counts exactly like the PHP server card does
 * (\block_feedback_tracker\local\output\numfmt::count). A non-string or empty
 * value is ignored, keeping the current separator.
 *
 * @param {string} sep  The separator, e.g. "," (English) or "." (pt_br).
 * @returns {void}
 */
export const setGroupingSeparator = (sep) => {
    if (typeof sep === 'string' && sep.length > 0) {
        groupingSeparator = sep;
    }
};

/**
 * Format an integer submission count with the active language's thousands
 * separator, e.g. 1232123 → "1,232,123". Null / undefined / NaN coerce to 0,
 * so a missing count renders "0" rather than the em-dash.
 *
 * @param {number|string|null|undefined} value  The count to format.
 * @returns {string}
 */
export const formatCount = (value) => {
    const n = Math.trunc(Number(value) || 0);
    const negative = n < 0;
    const digits = String(Math.abs(n));
    const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, groupingSeparator);
    return negative ? '-' + grouped : grouped;
};

/**
 * Hours suffix (matches the PHP "12.3 h" shape).
 *
 * @param {number|null|undefined} value
 * @param {number} digits
 * @returns {string}
 */
export const formatHours = (value, digits = 1) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return EMPTY;
    }
    return Number(value).toFixed(digits) + ' h';
};

/**
 * True when the configured display unit is business days (not hours).
 *
 * @param {object|null|undefined} config  Config bundle (display_time_unit).
 * @returns {boolean}
 */
export const usesDays = (config) =>
    !!(config && config.display_time_unit === 'business_days');

/**
 * Format a date-based elapsed-day count as "N d" (integer) or "1.5 d"
 * (fractional medians). The day counts are computed server-side from the
 * submit/grade timestamps; this only formats the value.
 *
 * @param {number|null|undefined} value Elapsed days (business or calendar).
 * @returns {string}
 */
export const formatDays = (value) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return EMPTY;
    }
    const n = Number(value);
    return (Number.isInteger(n) ? String(n) : n.toFixed(1)) + ' d';
};

/**
 * Percentage with no decimal places by default.
 *
 * @param {number|null|undefined} value
 * @param {number} digits
 * @returns {string}
 */
export const formatPercent = (value, digits = 0) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return EMPTY;
    }
    return Number(value).toFixed(digits) + '%';
};

/**
 * Trend value rendered on the speed model with a directional arrow. The
 * value is the change in median effective hours; fewer hours (negative) means
 * faster turnaround, shown as ▲, while more hours (positive) is slower (▼).
 * Magnitude is unsigned. Only exactly 0 reads as →, like the server card;
 * the live components use lib/trend.js classifySpeed, which treats |value| < 2
 * as stable.
 *
 * @param {number|null|undefined} value Percentage delta (negative = faster, positive = slower).
 * @param {number} digits
 * @returns {string} e.g. "▲ 12%", "▼ 5%", "→ 0%", or "—"
 */
export const formatTrend = (value, digits = 0) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return EMPTY;
    }
    const n = Number(value);
    let arrow = '→';
    if (n < 0) {
        arrow = '▲';
    } else if (n > 0) {
        arrow = '▼';
    }
    return arrow + ' ' + Math.abs(n).toFixed(digits) + '%';
};

/**
 * Unix timestamp (seconds) → locale-formatted short date string. Returns
 * the em-dash for null / 0.
 *
 * @param {number|null|undefined} timestamp Seconds since epoch.
 * @returns {string}
 */
export const formatDate = (timestamp) => {
    if (!timestamp) {
        return EMPTY;
    }
    const ms = Number(timestamp) * 1000;
    return new Date(ms).toLocaleDateString();
};
