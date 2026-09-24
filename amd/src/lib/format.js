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
 * Count, duration and date formatters shared by every component.
 *
 * The separators, the date locale and the time zone come from the page's
 * config bundle (bootstrap::config_bundle()). Each *_app.js entrypoint sets
 * them once at mount, before the first render, so a component never reads
 * them itself.
 *
 * @module    block_feedback_tracker/lib/format
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Em-dash returned for null / undefined / NaN values. */
const EMPTY = '—';

/**
 * Thousands separator for integer counts: langconfig 'thousandssep' once an
 * entrypoint sets it, a comma until then.
 */
let groupingSeparator = ',';

/**
 * Decimal separator for fractional values: langconfig 'decsep' once an
 * entrypoint sets it, a dot until then.
 */
let decimalSeparator = '.';

/** BCP 47 locale tag for dates; null leaves the browser's own. */
let dateLocale = null;

/** Time zone name for dates; null leaves the browser's own. */
let dateTimeZone = null;

/** Intl.DateTimeFormat instances by options key, emptied when the locale or zone changes. */
let dateFormatters = {};

/**
 * Set the thousands separator used by formatCount. Every *_app.js
 * entrypoint calls this once at mount from initial.config.thousandssep
 * so the JS surfaces group counts exactly like
 * \block_feedback_tracker\local\output\numfmt::count() does on the server.
 * A non-string or empty value is ignored, keeping the current separator.
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
 * Set the decimal separator used by formatDecimal, formatHours and
 * formatDays. Every *_app.js entrypoint calls this once at
 * mount from initial.config.decsep, so a fraction reads as format_float()
 * writes it on the server. A non-string or empty value is ignored, keeping
 * the current separator.
 *
 * @param {string} sep  The separator, e.g. "." (English) or "," (pt_br).
 * @returns {void}
 */
export const setDecimalSeparator = (sep) => {
    if (typeof sep === 'string' && sep.length > 0) {
        decimalSeparator = sep;
    }
};

/**
 * Set the locale and time zone the date formatters use. Every *_app.js
 * entrypoint calls this once at mount from initial.config.locale
 * (the language's langconfig locale as a BCP 47 tag, e.g. "pt-BR") and
 * initial.config.timezone (the user's Moodle time zone), so a timestamp
 * falls on the same day and hour as userdate() puts it. An empty value keeps
 * the browser's own.
 *
 * @param {string} locale    BCP 47 tag, e.g. "en-AU".
 * @param {string} timezone  Time zone name, e.g. "America/Sao_Paulo".
 * @returns {void}
 */
export const setDateContext = (locale, timezone) => {
    dateLocale = typeof locale === 'string' && locale.length > 0 ? locale : null;
    dateTimeZone = typeof timezone === 'string' && timezone.length > 0 ? timezone : null;
    dateFormatters = {};
};

/**
 * The Intl formatter for one set of options in the page's locale and time
 * zone, built once. Intl throws a RangeError for a malformed locale tag or a
 * time zone it does not know; each failure retries with a looser pair, ending
 * at the browser's own locale and zone.
 *
 * @param {string} key      Cache key naming the options.
 * @param {object} options  Intl.DateTimeFormat options, without timeZone.
 * @returns {Intl.DateTimeFormat}
 */
const dateFormatter = (key, options) => {
    if (dateFormatters[key]) {
        return dateFormatters[key];
    }
    const attempts = [[dateLocale, dateTimeZone], [dateLocale, null], [null, null]];
    let formatter = null;
    for (const [locale, timeZone] of attempts) {
        try {
            formatter = new Intl.DateTimeFormat(locale || undefined, timeZone ? {...options, timeZone} : options);
            break;
        } catch (e) {
            // A RangeError: try the next, looser pair.
        }
    }
    dateFormatters[key] = formatter;
    return formatter;
};

/**
 * Format a Unix timestamp (seconds) with one of the date formatters, or return
 * the em-dash for null, 0 and non-numeric input.
 *
 * @param {number|string|null|undefined} timestamp  Seconds since epoch.
 * @param {string} key      Cache key naming the options.
 * @param {object} options  Intl.DateTimeFormat options.
 * @returns {string}
 */
const formatTimestamp = (timestamp, key, options) => {
    const n = Number(timestamp);
    if (!Number.isFinite(n) || n <= 0) {
        return EMPTY;
    }
    return dateFormatter(key, options).format(new Date(n * 1000));
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
 * Round a number to `digits` decimal places and write it with the active
 * language's decimal separator, e.g. 12.34 → "12.3" (English) or "12,3"
 * (pt_br). The caller handles null / NaN.
 *
 * @param {number|string} value
 * @param {number} digits
 * @returns {string}
 */
export const formatDecimal = (value, digits) => Number(value).toFixed(digits).replace('.', decimalSeparator);

/**
 * Hours to `digits` decimals (one by default) with an "h" suffix, e.g.
 * "12.3 h".
 *
 * @param {number|null|undefined} value
 * @param {number} digits
 * @returns {string}
 */
export const formatHours = (value, digits = 1) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return EMPTY;
    }
    return formatDecimal(value, digits) + ' h';
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
    return (Number.isInteger(n) ? String(n) : formatDecimal(n, 1)) + ' d';
};

/**
 * Day, month and year of a Unix timestamp in the page's locale and the
 * user's time zone, e.g. "24/09/2026". Returns the em-dash for null / 0.
 *
 * @param {number|null|undefined} timestamp Seconds since epoch.
 * @returns {string}
 */
export const formatDate = (timestamp) =>
    formatTimestamp(timestamp, 'date', {day: '2-digit', month: '2-digit', year: 'numeric'});

/**
 * Date and time of a Unix timestamp in the page's locale and the user's time
 * zone, e.g. "24/09/2026, 14:05". Returns the em-dash for null / 0.
 *
 * @param {number|null|undefined} timestamp Seconds since epoch.
 * @returns {string}
 */
export const formatDateTime = (timestamp) =>
    formatTimestamp(timestamp, 'datetime', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

/**
 * Day and month of a Unix timestamp in the page's locale and the user's time
 * zone, e.g. "24/09". Returns the em-dash for null / 0.
 *
 * @param {number|null|undefined} timestamp Seconds since epoch.
 * @returns {string}
 */
export const formatDayMonth = (timestamp) =>
    formatTimestamp(timestamp, 'daymonth', {day: '2-digit', month: '2-digit'});
