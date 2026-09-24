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
 * Typed wrappers around the plugin's web-service surface.
 *
 * One named export per web service the views call, each accepting a single
 * options object; the services no view calls have no wrapper here. The
 * `Ajax.call([...])` plumbing and `Notification.exception` error routing
 * are centralised here so views can `await getResponsiveness({courseid})`
 * without handling core/ajax's request-array shape.
 *
 * Function names are the camelCase methodname without the
 * `block_feedback_tracker_` prefix.
 *
 * @module    block_feedback_tracker/lib/api
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Decide whether a rejected web-service call failed because of a connectivity
 * problem (offline, dropped Wi-Fi, server unreachable, timeout) rather than a
 * genuine application error returned by Moodle.
 *
 * The browser's offline flag is the strongest signal. Beyond that, a real
 * web-service exception always carries a Moodle `errorcode`; core/ajax rejects
 * transport failures with jQuery's bare `errorThrown` (an empty string or a
 * plain Error), which has none. Treating "no errorcode" as a network failure
 * keeps genuine application errors (capability, invalid param, coding) on the
 * technical toast while connectivity drops get the friendly retry affordance.
 *
 * @param {*} error  The rejection value from core/ajax.
 * @returns {boolean}  True when the failure looks like a connectivity drop.
 */
const isNetworkError = (error) => {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        return true;
    }
    return !(error && typeof error === 'object' && error.errorcode);
};

/**
 * Generic single-WS caller. Connectivity failures are tagged with `bftNetwork`
 * and re-thrown WITHOUT a toast so the calling view can render a friendly
 * inline "connection lost / try again" notice; genuine application errors keep
 * routing through core/notification as a toast. Either way the error is
 * re-thrown so the caller's own catch / try can react.
 *
 * core/ajax resolves a jQuery promise (thenable, but with no native
 * .finally()). Promise.resolve() adopts it into a native Promise so callers
 * can chain .then()/.catch()/.finally() — the dashboard's mount-time loader
 * relies on .finally() and otherwise throws "finally is not a function".
 *
 * @param {string} methodname  Moodle WS function name (with prefix).
 * @param {object} args        Argument bag as the WS expects.
 * @returns {Promise<*>}  Native Promise.
 */
const call = (methodname, args) => Promise.resolve(Ajax.call([{methodname, args}])[0])
    .catch((error) => {
        if (isNetworkError(error)) {
            const tagged = (error && typeof error === 'object') ? error : {message: String(error || '')};
            tagged.bftNetwork = true;
            throw tagged;
        }
        Notification.exception(error);
        throw error;
    });

/**
 * Get the responsiveness payload for one course.
 *
 * @param {object} options
 * @param {number} options.courseid
 * @param {boolean} [options.force]  Bypass the session cache.
 * @param {number} [options.limit]   Page size; 0 returns every visible group.
 * @param {number} [options.offset]  Zero-based offset into the group list.
 * @param {string} [options.sort]    Order key: 'default' | 'priority' | 'wait'.
 * @returns {Promise<object>}
 */
export const getResponsiveness = ({courseid, force = false, limit = 0, offset = 0, sort = 'default'}) =>
    call('block_feedback_tracker_get_responsiveness',
        {courseid, force: force ? 1 : 0, limit, offset, sort});

/**
 * Paginated list of pending submissions in a course. Search + sort + the
 * pending-band distribution counts are all server-side, so they span every
 * matching row, not just the current page.
 *
 * @param {object} options
 * @param {number} options.courseid
 * @param {number} [options.groupid]
 * @param {string} [options.bucket]   "excellent" | "good" | "regular" | "critical"
 * @param {string} [options.sort]     "longestwait" | "recent" | column key
 * @param {number} [options.page]
 * @param {number} [options.perpage]
 * @param {string} [options.status]   "submitted" (default, awaiting feedback) | "draft"
 * @param {string} [options.band]     "aguardando" | "atencao" | "prioridade" (effective-hours range)
 * @param {string} [options.search]   Free-text needle (student / activity name)
 * @param {string} [options.order]    "asc" | "desc" for column sorts
 * @returns {Promise<object>}
 */
export const getPendingSubmissions = ({
    courseid, groupid = 0, bucket = '', sort = 'longestwait', page = 0, perpage = 25,
    status = 'submitted', band = '', search = '', order = 'desc',
}) => call('block_feedback_tracker_get_pending_submissions',
    {courseid, groupid, bucket, sort, page, perpage, status, band, search, order});

/**
 * Paginated list of already-graded submissions in a course (the report's
 * graded mode). Mirrors getPendingSubmissions but each row carries a
 * timegraded and a result band (slabucket recorded at grading time). The
 * server folds critical results into regular, so counts.critical is always 0.
 *
 * @param {object} options
 * @param {number} options.courseid
 * @param {number} [options.groupid]
 * @param {string} [options.bucket]   "excellent" | "good" | "regular" | "critical" (result band)
 * @param {string} [options.sort]     "graded" | column key
 * @param {number} [options.page]
 * @param {number} [options.perpage]
 * @param {string} [options.search]   Free-text needle (student / activity name)
 * @param {string} [options.order]    "asc" | "desc" for column sorts
 * @returns {Promise<object>}
 */
export const getGradedSubmissions = ({
    courseid, groupid = 0, bucket = '', sort = 'graded', page = 0, perpage = 25,
    search = '', order = 'desc',
}) => call('block_feedback_tracker_get_graded_submissions',
    {courseid, groupid, bucket, sort, page, perpage, search, order});

/**
 * Heatmap series of the last 30 calendar days for the report page. Each entry
 * is one day, flagged paused (with a reason) or coloured by that day's
 * responsiveness band. Loaded asynchronously after first paint.
 *
 * @param {object} options
 * @param {number} options.courseid
 * @param {number} [options.groupid]  0 = aggregate over all visible groups
 * @returns {Promise<object>}
 */
export const getAcademicDays = ({courseid, groupid = 0}) =>
    call('block_feedback_tracker_get_academic_days', {courseid, groupid});

/**
 * Per-group hero scopes + class-filter list for the pending report. A
 * lightweight rollup-only read (no per-group trend/peer/activity assembly),
 * fetched after first paint so the page never blocks on it.
 *
 * @param {object} options
 * @param {number} options.courseid
 * @returns {Promise<object>}
 */
export const getReportScopes = ({courseid}) =>
    call('block_feedback_tracker_get_report_scopes', {courseid});

/**
 * Site / cross-course dashboard payload. The WS keeps its own 900-second
 * cache keyed per user, band and calendar version (calver), so there is no
 * client-driven force flag: a calver bump or TTL expiry refreshes it.
 *
 * @param {object} [options]
 * @param {string} [options.band]  Optional band filter ('' = no filter).
 * @returns {Promise<object>}
 */
export const getDashboard = ({band = ''} = {}) =>
    call('block_feedback_tracker_get_dashboard', {band});

/**
 * Cross-course "Grade Now" prioritised list — top-N most-urgent pending
 * submissions across every course the caller can view, sorted by
 * effective wait DESC. Powers the dashboard's "Grade now" priority cards.
 *
 * @param {object} [options]
 * @param {number} [options.limit]  1..50, default 10.
 * @param {string} [options.bucket] Optional band filter.
 * @returns {Promise<object>}
 */
export const getGraderPriorityList = ({limit = 10, bucket = ''} = {}) =>
    call('block_feedback_tracker_get_grader_priority_list', {limit, bucket});

/**
 * Dashboard insights — bright spot, most improved, gentle watch. Each
 * key is omitted from the response when no row qualifies, so callers
 * should check for presence (not nullness) before rendering.
 *
 * @returns {Promise<object>}
 */
export const getInsights = () =>
    call('block_feedback_tracker_get_insights', {});
