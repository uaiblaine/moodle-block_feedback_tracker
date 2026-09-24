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
 * Block-level React view — owns load + refresh + sort state for the in-course
 * block.
 *
 * The mount-point payload ships an empty groups array (a light shell). The
 * view loads cards lazily: it fetches a small batch, then an
 * IntersectionObserver on a bottom sentinel pulls the next batch as the user
 * scrolls (one request in flight at a time). To keep the DOM bounded on
 * courses with thousands of groups it stops auto-loading at a safety cap and
 * offers a manual "Load more" beyond it. Sorting is server-side (the first
 * cards are the true top-priority ones) and the overall banner uses a
 * whole-course aggregate. Loaded pages are cached in sessionStorage keyed by
 * course + calendar version + session + sort, so a reload within the 15-minute
 * window restores instantly without any WS round-trip; Refresh clears that
 * cache and refetches with force.
 *
 * @module    block_feedback_tracker/views/BlockView
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState, useMemo, useEffect, useRef} from 'block_feedback_tracker/lib/preact';
import GroupCard from 'block_feedback_tracker/components/GroupCard';
import OverallBanner from 'block_feedback_tracker/components/OverallBanner';
import Skeleton from 'block_feedback_tracker/components/Skeleton';
import RetryNotice from 'block_feedback_tracker/components/RetryNotice';
import {bandForScore} from 'block_feedback_tracker/lib/bands';
import {getResponsiveness} from 'block_feedback_tracker/lib/api';
import {formatDateTime} from 'block_feedback_tracker/lib/format';

/** Client cache: how long a stored page set stays fresh (matches responsiveness_payload::CACHE_TTL). */
const CACHE_TTL_SECONDS = 900;
/** SessionStorage key prefix + schema version (bump to invalidate old shapes). */
const CACHE_PREFIX = 'bft-resp-v2-';

/**
 * Build the sessionStorage key for one (course, calver, session, sort) tuple.
 *
 * @param {number} courseid
 * @param {number} calver    Calendar version (invalidates on calendar saves).
 * @param {string} sesskey   Moodle session key (per-user, per-session scope).
 * @param {string} sort
 * @returns {string}
 */
const cacheKey = (courseid, calver, sesskey, sort) =>
    CACHE_PREFIX + courseid + '-' + calver + '-' + sesskey + '-' + sort;

/**
 * Read a cached page set. Returns null when absent, unparseable, or storage
 * is unavailable (private mode / disabled).
 *
 * @param {string} key
 * @returns {object|null}
 */
const readCache = (key) => {
    try {
        const raw = window.sessionStorage.getItem(key);
        if (!raw) {
            return null;
        }
        const data = JSON.parse(raw);
        return data && Array.isArray(data.groups) ? data : null;
    } catch (e) {
        return null;
    }
};

/**
 * Persist a page set. Best-effort — swallows QuotaExceededError and any
 * storage-disabled exception so a failed write never breaks rendering.
 *
 * @param {string} key
 * @param {object} data
 * @returns {void}
 */
const writeCache = (key, data) => {
    try {
        window.sessionStorage.setItem(key, JSON.stringify(data));
    } catch (e) {
        // Quota exceeded or storage disabled — the cache is purely an
        // optimisation, so a failed write is safe to ignore.
    }
};

/**
 * Drop every cached page set for one course (all calvers + sorts). Used by
 * Refresh so the next load always hits the server.
 *
 * @param {number} courseid
 * @returns {void}
 */
const clearCacheForCourse = (courseid) => {
    try {
        const store = window.sessionStorage;
        const prefix = CACHE_PREFIX + courseid + '-';
        const kill = [];
        for (let i = 0; i < store.length; i++) {
            const k = store.key(i);
            if (k && k.indexOf(prefix) === 0) {
                kill.push(k);
            }
        }
        kill.forEach((k) => store.removeItem(k));
    } catch (e) {
        // Storage unavailable — nothing to clear.
    }
};

/**
 * Average of the loaded groups' scores weighted by pending count, with a
 * minimum weight of 1 so a group with nothing pending still counts. Groups
 * without a score are skipped. Mirrors responsiveness_payload::overall_score(),
 * which computes the same figure over the whole course.
 *
 * @param {Array<object>} groups
 * @param {{excellent?: number, good?: number, regular?: number}|null} thresholds
 * @returns {{score: number|null, band: string}}
 */
const overallScore = (groups, thresholds) => {
    if (!Array.isArray(groups) || groups.length === 0) {
        return {score: null, band: 'pending'};
    }
    let totalw = 0;
    let totalv = 0;
    groups.forEach((g) => {
        if (g.responsiveness_score === null || g.responsiveness_score === undefined) {
            return;
        }
        const weight = Math.max(1, Number(g.pending) || 0);
        totalv += Number(g.responsiveness_score) * weight;
        totalw += weight;
    });
    if (totalw === 0) {
        return {score: null, band: 'pending'};
    }
    const score = totalv / totalw;
    return {score, band: bandForScore(score, thresholds)};
};

/**
 * The scheduled-pause list (already decorated + visibility-filtered by
 * upcoming_pauses::for_display() on the server). The payload builds it once
 * per course, without group-scoped pauses, and attaches it identically to
 * every group, so the first group carrying a non-empty list wins and the
 * block renders it once.
 *
 * @param {Array<object>} groups
 * @returns {Array<object>}
 */
const upcomingFromGroups = (groups) => {
    if (!Array.isArray(groups)) {
        return [];
    }
    for (const g of groups) {
        if (Array.isArray(g.upcoming_pauses) && g.upcoming_pauses.length > 0) {
            return g.upcoming_pauses;
        }
    }
    return [];
};

/**
 * Top-level block view.
 *
 * @param {object} props
 * @param {object} props.initial  Mount-point payload: {courseid, calver,
 *                                lastsynced, i18n, config}. groups ships empty —
 *                                the view loads them lazily via the WS.
 * @returns {object} vnode
 */
// Branch count over the lint cap is acknowledged debt (refactor pass pending).
// eslint-disable-next-line complexity
export default function BlockView({initial}) {
    const i18n = initial.i18n || {};
    const config = initial.config || {};
    const courseid = Number(initial.courseid) || 0;
    const calver = Number(initial.calver) || 0;

    const sesskey = (typeof M !== 'undefined' && M.cfg && M.cfg.sesskey) || '';

    // Batch sizes + the DOM safety cap. Auto-loading stops at CAP rendered
    // cards so a course with thousands of groups cannot crash the browser tab;
    // a manual button loads MANUAL_BATCH more.
    const BATCH = 3;
    const CAP = 200;
    const MANUAL_BATCH = 25;

    const [groups, setGroups] = useState([]);
    const [total, setTotal] = useState(0);
    const [hasmore, setHasmore] = useState(false);
    const [serverscore, setServerscore] = useState(null);
    const [lastsynced, setLastsynced] = useState(Number(initial.lastsynced) || 0);
    const [sort, setSort] = useState('default');
    const [loading, setLoading] = useState(true);
    const [loadingmore, setLoadingmore] = useState(false);
    const [error, setError] = useState(null);

    // Refs mirror the mutable bits the IntersectionObserver callback needs so
    // it reads fresh values without being re-created. callIdRef supersedes
    // in-flight loads (sort change / refresh); loadingRef enforces one request
    // at a time; mountedRef blocks state writes after unmount.
    const mountedRef = useRef(false);
    const callIdRef = useRef(0);
    const loadingRef = useRef(false);
    const groupsRef = useRef([]);
    const offsetRef = useRef(0);
    const hasmoreRef = useRef(false);
    const sortRef = useRef('default');
    const sentinelRef = useRef(null);
    const observerRef = useRef(null);
    const loadMoreRef = useRef(null);
    // Args of the most recent loadPage call, replayed by the retry affordance
    // when a fetch fails (network drop). Captured at the top of loadPage.
    const lastLoadRef = useRef({force: false, off: 0, srt: 'default', reset: true, manual: false});

    /**
     * Apply a full page set (from a fetch or the cache) to state + refs and
     * clear the loading flags.
     *
     * @param {object} data
     * @returns {void}
     */
    const applyState = (data) => {
        const g = Array.isArray(data.groups) ? data.groups : [];
        groupsRef.current = g;
        offsetRef.current = Number(data.offset) || g.length;
        hasmoreRef.current = data.hasmore === true;
        setGroups(g);
        setHasmore(hasmoreRef.current);
        setTotal(Number(data.total) || g.length);
        setServerscore(
            data.overall_score === null || data.overall_score === undefined
                ? null : Number(data.overall_score)
        );
        setLastsynced(Number(data.lastsynced) || 0);
        setLoading(false);
        setLoadingmore(false);
    };

    /**
     * Pick the user-facing message for a failed fetch: a friendly connectivity
     * notice for network drops (api.js tags these `bftNetwork` and suppresses
     * the technical toast) or the generic refresh error otherwise.
     *
     * @param {*} e  The rejection value from a web-service call.
     * @returns {string}
     */
    const netMsg = (e) => (e && e.bftNetwork)
        ? (i18n.connection_lost || 'Connection lost. Check your internet and try again.')
        : (i18n.block_refresh_error || 'Refresh failed.');

    /**
     * Fetch one page. `reset` starts a fresh sequence (mount / sort / refresh)
     * and clears the list; otherwise the page is appended. Guarded against
     * unmount and supersession via callIdRef.
     *
     * @param {object} opts
     * @param {boolean} [opts.force]   Bypass the server session cache.
     * @param {number} [opts.off]      Offset to fetch from.
     * @param {string} [opts.srt]      Sort key.
     * @param {boolean} [opts.reset]   Replace (true) vs append (false).
     * @param {boolean} [opts.manual]  Use the larger MANUAL_BATCH page size.
     * @returns {Promise<void>}
     */
    // Branch count over the lint cap is acknowledged debt (refactor pass pending).
    // eslint-disable-next-line complexity
    const loadPage = async({force = false, off = 0, srt = 'default', reset = false, manual = false}) => {
        lastLoadRef.current = {force, off, srt, reset, manual};
        const callid = ++callIdRef.current;
        loadingRef.current = true;
        if (reset) {
            groupsRef.current = [];
            offsetRef.current = 0;
            hasmoreRef.current = false;
            setGroups([]);
            setHasmore(false);
            setServerscore(null);
            setLoading(true);
        } else {
            setLoadingmore(true);
        }
        setError(null);
        const batch = manual ? MANUAL_BATCH : BATCH;
        try {
            const result = await getResponsiveness({courseid, force, limit: batch, offset: off, sort: srt});
            if (!mountedRef.current || callIdRef.current !== callid) {
                return;
            }
            if (!result || !Array.isArray(result.groups)) {
                return;
            }
            const newgroups = reset ? result.groups.slice() : groupsRef.current.concat(result.groups);
            const newoffset = off + result.groups.length;
            const more = result.hasmore === true && result.groups.length > 0;
            groupsRef.current = newgroups;
            offsetRef.current = newoffset;
            hasmoreRef.current = more;
            setGroups(newgroups);
            setHasmore(more);
            if (typeof result.total === 'number') {
                setTotal(result.total);
            }
            if (result.overall_score !== undefined) {
                setServerscore(result.overall_score === null ? null : Number(result.overall_score));
            }
            if (result.lastsynced) {
                setLastsynced(Number(result.lastsynced) || 0);
            }
            writeCache(cacheKey(courseid, calver, sesskey, srt), {
                groups: newgroups,
                offset: newoffset,
                hasmore: more,
                total: typeof result.total === 'number' ? result.total : newgroups.length,
                'overall_score': result.overall_score === undefined ? null : result.overall_score,
                lastsynced: Number(result.lastsynced) || 0,
            });
        } catch (e) {
            // Network drops are suppressed from the toast in api.js; the inline
            // RetryNotice carries recovery. Genuine WS errors still toast there.
            if (mountedRef.current && callIdRef.current === callid) {
                setError(netMsg(e));
            }
        } finally {
            // Only the still-current call owns the flags — a superseded call
            // must not clear loadingRef out from under its replacement.
            if (callIdRef.current === callid) {
                loadingRef.current = false;
                if (mountedRef.current) {
                    setLoading(false);
                    setLoadingmore(false);
                }
            }
        }
    };

    /**
     * Append the next batch if the viewport sentinel is in view and we're
     * below the safety cap. No-op while a request is in flight, when nothing
     * more remains, or once the cap is hit (manual "Load more" takes over).
     *
     * @returns {void}
     */
    const maybeLoadMore = () => {
        if (loadingRef.current || !hasmoreRef.current) {
            return;
        }
        if (groupsRef.current.length === 0 || groupsRef.current.length >= CAP) {
            return;
        }
        loadPage({force: false, off: offsetRef.current, srt: sortRef.current, reset: false});
    };
    // Keep the observer (created once) pointed at the latest closure.
    loadMoreRef.current = maybeLoadMore;

    /**
     * Start a fresh sequence for a sort key, restoring from sessionStorage
     * when a fresh entry exists (no WS round-trip) or fetching page 0.
     *
     * @param {string} srt
     * @param {boolean} force  True forces a server fetch (Refresh).
     * @returns {void}
     */
    const startSequence = (srt, force) => {
        sortRef.current = srt;
        if (!force) {
            const cached = readCache(cacheKey(courseid, calver, sesskey, srt));
            if (cached && (Date.now() / 1000 - (Number(cached.lastsynced) || 0)) < CACHE_TTL_SECONDS) {
                // Supersede any in-flight load and adopt the cached pages.
                callIdRef.current++;
                loadingRef.current = false;
                applyState(cached);
                return;
            }
        }
        loadPage({force, off: 0, srt, reset: true});
    };

    // Mount: wire the observer once, then load page 0 (or restore from cache).
    useEffect(() => {
        mountedRef.current = true;
        sortRef.current = sort;
        if (typeof IntersectionObserver !== 'undefined') {
            observerRef.current = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting) && loadMoreRef.current) {
                    loadMoreRef.current();
                }
            }, {rootMargin: '200px'});
        }
        startSequence(sort, false);
        return () => {
            mountedRef.current = false;
            if (observerRef.current) {
                observerRef.current.disconnect();
            }
        };
    }, []);

    // (Re)observe the sentinel whenever it appears or the list grows.
    useEffect(() => {
        const obs = observerRef.current;
        const node = sentinelRef.current;
        if (!obs || !node) {
            return undefined;
        }
        obs.observe(node);
        return () => obs.unobserve(node);
    }, [groups.length, hasmore]);

    const handleSortChange = (e) => {
        const newsort = e.target.value;
        setSort(newsort);
        startSequence(newsort, false);
    };

    const handleRefresh = () => {
        if (loading || loadingmore) {
            return;
        }
        clearCacheForCourse(courseid);
        startSequence(sortRef.current, true);
    };

    const handleLoadMore = () => {
        if (loadingRef.current || !hasmoreRef.current) {
            return;
        }
        loadPage({force: false, off: offsetRef.current, srt: sortRef.current, reset: false, manual: true});
    };

    /**
     * Replay the most recent (failed) fetch — the retry affordance after a
     * connectivity drop. No-op while a request is already in flight.
     *
     * @returns {void}
     */
    const retryLoad = () => {
        if (loadingRef.current) {
            return;
        }
        loadPage(lastLoadRef.current);
    };

    // Overall banner: prefer the whole-course server aggregate; fall back to a
    // client estimate over the loaded cards only when the server figure is absent.
    const fallback = useMemo(
        () => overallScore(groups, config && config.score_thresholds),
        [groups, config]
    );
    const overallvalue = serverscore !== null && serverscore !== undefined ? serverscore : fallback.score;
    const overallband = overallvalue === null || overallvalue === undefined
        ? 'pending'
        : bandForScore(overallvalue, config && config.score_thresholds);
    const overallBandLabel = i18n.bands && i18n.bands[overallband] ? i18n.bands[overallband] : '';
    // Scheduled-pause notice — gated by the admin toggle (default ON). The
    // server already filters to the visible window; here we just hide the
    // whole strip when the toggle is off.
    const showpauses = config.show_scheduled_pauses !== false;
    const upcoming = useMemo(
        () => (showpauses ? upcomingFromGroups(groups) : []),
        [groups, showpauses]
    );

    const capreached = groups.length >= CAP && hasmore;
    // When IntersectionObserver is unavailable, auto-scroll loading can't fire,
    // so fall back to a manual button whenever more groups remain.
    const observersupported = typeof IntersectionObserver !== 'undefined';
    const showmanual = hasmore && (capreached || !observersupported);
    const showSort = total > 1;
    const capnotice = (i18n.block_capnotice || 'Showing {shown} of {total} groups.')
        .replace('{shown}', String(groups.length))
        .replace('{total}', String(total));
    const synctext = (lastsynced
        ? (i18n.card_footer_sync || 'Last synced {$a}').replace('{$a}', formatDateTime(lastsynced)) + ' · '
        : '') + (i18n.card_footer_cache || 'Updates automatically every 15 minutes.');

    let blockbody;
    if (loading && groups.length === 0) {
        blockbody = html`<${Skeleton} count=${5} />`;
    } else if (groups.length === 0) {
        blockbody = error
            ? html`<${RetryNotice}
                message=${error}
                onRetry=${retryLoad}
                retrying=${loading || loadingmore}
                i18n=${i18n}
                variant="block" />`
            : html`<div class="bft-empty">${i18n.card_empty}</div>`;
    } else {
        blockbody = html`
            <${OverallBanner}
                score=${overallvalue}
                band=${overallband}
                bandlabel=${overallBandLabel}
                i18n=${i18n}
                pauses=${upcoming} />
            <div class="bft-card-list">
                ${groups.map((group) => html`
                    <${GroupCard}
                        key=${'g-' + (group.groupid || 0)}
                        group=${group}
                        courseid=${courseid}
                        i18n=${i18n}
                        config=${config} />
                `)}
            </div>
            ${loadingmore && html`<${Skeleton} count=${1} />`}
            ${showmanual && html`
                <div class="bft-block-cap">
                    ${capreached && html`
                        <span class="bft-block-cap-note">${capnotice}</span>
                    `}
                    <button type="button"
                            class="bft-loadmore"
                            disabled=${loadingmore}
                            onClick=${handleLoadMore}>${i18n.block_loadmore}</button>
                </div>
            `}
            ${observersupported && hasmore && !capreached && html`
                <div ref=${sentinelRef}
                     class="bft-scroll-sentinel"
                     aria-hidden="true"></div>
            `}
        `;
    }

    return html`
        <div class="bft-block-root">
            ${showSort && html`
                <div class="bft-block-controls">
                    <label class="bft-sort-label">
                        <span class="bft-sort-label-text">${i18n.block_sort_label}</span>
                        <select class="bft-sort-select"
                                value=${sort}
                                disabled=${loading}
                                onChange=${handleSortChange}>
                            <option value="default">${i18n.block_sort_default}</option>
                            <option value="priority">${i18n.block_sort_priority}</option>
                            <option value="wait">${i18n.block_sort_wait}</option>
                        </select>
                    </label>
                </div>
            `}
            ${error && groups.length > 0 && html`
                <${RetryNotice}
                    message=${error}
                    onRetry=${retryLoad}
                    retrying=${loading || loadingmore}
                    i18n=${i18n}
                    variant="banner" />
            `}
            <div class="bft-block-body"
                 aria-busy=${loading ? 'true' : 'false'}
                 aria-label=${loading ? i18n.block_loading : null}>
                ${blockbody}
            </div>
            <div class="bft-block-foot">
                <span class="bft-block-foot-note">${synctext}</span>
                <button type="button"
                        class=${'bft-refresh' + (loading || loadingmore ? ' bft-refresh-busy' : '')}
                        disabled=${loading || loadingmore}
                        aria-label=${i18n.card_refresh}
                        onClick=${handleRefresh}>⟳ ${i18n.card_refresh}</button>
            </div>
        </div>
    `;
}
