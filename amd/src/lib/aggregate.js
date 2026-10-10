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
 * Hero figures over several rollup rows: the per-course rows of the teacher
 * dashboard (get_dashboard) and the per-group scopes of the pending report
 * (get_report_scopes). Both heroes go through here so they cannot drift.
 *
 * @module    block_feedback_tracker/lib/aggregate
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Result key → row key of each figure the hero shows as a plain mean over the
 * rows that carry a value. The medians are the include-pending (cur_median_*)
 * ones, so the hero shows the live backlog; the *_days keys are the
 * date-based twins shown in the business-days display unit.
 *
 * @type {Object<string, string>}
 */
const MEAN_FIELDS = {
    effective: 'cur_median_eff_h',
    perceived: 'cur_median_raw_h',
    effectivedays: 'cur_median_eff_days',
    perceiveddays: 'cur_median_perc_days',
    compliance: 'compliance_pct',
    compliancedays: 'compliance_pct_days',
    trendpct: 'trend_pct_30d',
};

/**
 * True for a value the row actually carries.
 *
 * @param {*} v
 * @returns {boolean}
 */
const present = (v) => v !== null && v !== undefined;

/**
 * Aggregate rollup rows into one hero: the counts are summed, the score is
 * the mean weighted by pending count (minimum weight 1, so a row with nothing
 * pending still counts), and every figure in MEAN_FIELDS is a plain mean over
 * the rows that carry it. A row missing a key counts as missing that value,
 * with no error, so each caller's web service must keep returning the keys
 * read here.
 *
 * @param {Array<object>} rows
 * @param {string} scorekey  Row key holding the score: 'avgscore' on dashboard
 *                           rows, 'responsiveness_score' on report scopes.
 * @returns {{pending: number, critical: number, overgoal: number,
 *            score: number|null, effective: number|null,
 *            perceived: number|null, effectivedays: number|null,
 *            perceiveddays: number|null, compliance: number|null,
 *            compliancedays: number|null, trendpct: number|null}}
 */
export const aggregate = (rows, scorekey) => {
    const list = Array.isArray(rows) ? rows : [];
    const totals = {pending: 0, critical: 0, overgoal: 0, score: null};
    let scoresum = 0;
    let scoreweight = 0;
    list.forEach((row) => {
        totals.pending += Number(row.pending) || 0;
        totals.critical += Number(row.critical) || 0;
        totals.overgoal += Number(row.overgoal) || 0;
        if (present(row[scorekey])) {
            const weight = Math.max(1, Number(row.pending) || 0);
            scoresum += Number(row[scorekey]) * weight;
            scoreweight += weight;
        }
    });
    totals.score = scoreweight > 0 ? scoresum / scoreweight : null;
    Object.keys(MEAN_FIELDS).forEach((name) => {
        const values = list.map((row) => row[MEAN_FIELDS[name]]).filter(present).map(Number);
        totals[name] = values.length > 0 ? values.reduce((sum, v) => sum + v, 0) / values.length : null;
    });
    return totals;
};

/**
 * Perceived wait in whole calendar days from the raw (wall-clock) median
 * hours, for the hours display unit, e.g. "4d" (never below "1d"), or "—" when
 * there is nothing to show. The raw median already runs through weekends and
 * holidays, so it converts to calendar days with no inflation factor.
 *
 * @param {number|null|undefined} rawhours  Median raw (wall-clock) hours.
 * @returns {string}
 */
export const perceivedLabel = (rawhours) => {
    const n = Number(rawhours);
    if (!Number.isFinite(n) || n <= 0) {
        return '—';
    }
    return Math.max(1, Math.round(n / 24)) + 'd';
};
