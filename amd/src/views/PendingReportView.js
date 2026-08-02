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
 * Full-page React view for pages/pending_report.php — MVP3 redesign that
 * inherits the dashboard's hero + collapse pattern and the block's vocabulary.
 *
 * Composition (top → bottom):
 *   1. Breadcrumb (back to course + current crumb)
 *   2. Title row (course name H1 + overall status pill)
 *   3. Collapsible container: ResponsivenessModule hero + AcademicDaysStrip
 *   4. StatusDistributionBar — pending bands (Waiting/Attention/Priority) with a
 *      "Já avaliados" toggle into the graded view (Excellent/Good/Up Next/Priority)
 *   5. Toolbar — class select, real (server-side) search, refresh
 *   6. Table — Student / Activity / Class / Submitted / Effective / Perceived |
 *      Graded / Status|Result / Action (grade + pause-timeline)
 *
 * Everything is server-driven: the group filter, distribution filter, search,
 * column sort, paging, and the distribution counts all re-fetch the WS so they
 * span every matching row, not just the page on screen. The hero scope is the
 * one client-side computation — it recomputes instantly from the groupscopes
 * payload when the class filter changes (no round-trip).
 *
 * @module    block_feedback_tracker/views/PendingReportView
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState, useEffect, useRef} from 'block_feedback_tracker/lib/preact';
import Badge from 'block_feedback_tracker/components/Badge';
import ResponsivenessModule from 'block_feedback_tracker/components/ResponsivenessModule';
import AcademicDaysStrip from 'block_feedback_tracker/components/AcademicDaysStrip';
import StatusDistributionBar from 'block_feedback_tracker/components/StatusDistributionBar';
import Skeleton from 'block_feedback_tracker/components/Skeleton';
import RetryNotice from 'block_feedback_tracker/components/RetryNotice';
import ScheduledPauses from 'block_feedback_tracker/components/ScheduledPauses';
import {bandForScore, colourFor} from 'block_feedback_tracker/lib/bands';
import {getPendingSubmissions, getGradedSubmissions, getAcademicDays, getReportScopes}
    from 'block_feedback_tracker/lib/api';
import {formatHours, formatDays, formatDate, usesDays, formatCount} from 'block_feedback_tracker/lib/format';
import {setUserPreference} from 'core_user/repository';
import Notification from 'core/notification';

/** Moodle user-preference name persisting the hero+heatmap collapse state. */
const PREF_REPORT_COLLAPSED = 'block_feedback_tracker_report_collapsed';

/** Business hours above which effective/perceived differ enough to tag a row. */
const PAUSED_TAG_EPSILON = 0.5;

/**
 * Perceived calendar-days label from raw (wall-clock) median hours. The raw
 * median already includes weekends and holidays so it converts straight to
 * calendar days.
 *
 * @param {number|null|undefined} rawhours
 * @returns {string}
 */
const perceivedLabel = (rawhours) => {
    const n = Number(rawhours);
    if (!Number.isFinite(n) || n <= 0) {
        return '—';
    }
    return Math.max(1, Math.round(n / 24)) + 'd';
};

/**
 * Map a pending row's band to its Status badge {colour band slug, label}. Uses
 * the Waiting / Attention / Priority vocabulary (card_* strings) and the same
 * colour tones as the distribution bar, so the badge always agrees with the
 * filter that produced the row.
 *
 * @param {string} pendingband  aguardando | atencao | prioridade.
 * @param {object} i18n
 * @returns {{band: string, label: string}}
 */
const pendingBadge = (pendingband, i18n) => {
    switch (pendingband) {
        case 'prioridade': return {band: 'critical', label: i18n.card_critical || 'Priority'};
        case 'atencao': return {band: 'regular', label: i18n.card_overgoal || 'Attention'};
        default: return {band: 'pending', label: i18n.card_pending || 'Waiting'};
    }
};

/**
 * Map a graded row's slabucket to its result Badge {band, label}. Graded
 * submissions use the three-band result set (Excellent / Good / Regular) from
 * the academic-days strip; critical results fold into Regular. The server
 * already folds them, so the default arm only guards the display.
 *
 * @param {string} slabucket  excellent | good | regular (critical pre-folded).
 * @param {object} i18n
 * @returns {{band: string, label: string}}
 */
const gradedBadge = (slabucket, i18n) => {
    switch (slabucket) {
        case 'excellent': return {band: 'excellent', label: i18n.acaday_legend_ongoal || 'On goal'};
        case 'good': return {band: 'good', label: i18n.acaday_legend_good || 'Good'};
        default: return {band: 'regular', label: i18n.acaday_legend_regular || 'Regular'};
    }
};

/**
 * Compute the hero scope for the active class filter. Single group → that
 * group's metrics; "all" → pending-weighted score, mean of the include-pending
 * medians, summed counts. Mirrors the server's old build_pending_report_scope
 * and the dashboard's aggregate().
 *
 * @param {Array<object>} scopes  Trimmed per-group metrics from the payload.
 * @param {number} gid            Active group id, 0 = whole course.
 * @returns {object|null}
 */
const computeScope = (scopes, gid) => {
    if (!Array.isArray(scopes) || scopes.length === 0) {
        return null;
    }
    if (gid > 0) {
        const g = scopes.find((s) => Number(s.groupid) === gid);
        if (!g) {
            return null;
        }
        return {
            score: g.responsiveness_score,
            band: g.score_band || null,
            effective: g.cur_median_eff_h,
            perceivedraw: g.cur_median_raw_h,
            effectivedays: g.cur_median_eff_days,
            perceiveddays: g.cur_median_perc_days,
            compliance: g.compliance_pct,
            compliancedays: g.compliance_pct_days,
            trendpct: g.trend_pct_30d,
            'total_pending': Number(g.pending) || 0,
            'total_critical': Number(g.critical) || 0,
            'total_overgoal': Number(g.overgoal) || 0,
        };
    }
    let pending = 0;
    let critical = 0;
    let overgoal = 0;
    let scoreSum = 0;
    let scoreWeight = 0;
    let effSum = 0;
    let effCount = 0;
    let rawSum = 0;
    let rawCount = 0;
    let effDaysSum = 0;
    let effDaysCount = 0;
    let percDaysSum = 0;
    let percDaysCount = 0;
    let compSum = 0;
    let compCount = 0;
    let compDaysSum = 0;
    let compDaysCount = 0;
    let trendSum = 0;
    let trendCount = 0;
    // Branch count over the lint cap is acknowledged debt (refactor pass pending).
    // eslint-disable-next-line complexity
    scopes.forEach((g) => {
        pending += Number(g.pending) || 0;
        critical += Number(g.critical) || 0;
        overgoal += Number(g.overgoal) || 0;
        if (g.responsiveness_score !== null && g.responsiveness_score !== undefined) {
            const weight = Math.max(1, Number(g.pending) || 0);
            scoreSum += Number(g.responsiveness_score) * weight;
            scoreWeight += weight;
        }
        if (g.cur_median_eff_h !== null && g.cur_median_eff_h !== undefined) {
            effSum += Number(g.cur_median_eff_h);
            effCount += 1;
        }
        if (g.cur_median_raw_h !== null && g.cur_median_raw_h !== undefined) {
            rawSum += Number(g.cur_median_raw_h);
            rawCount += 1;
        }
        // Date-based day medians — the headline pair for the business-days unit.
        if (g.cur_median_eff_days !== null && g.cur_median_eff_days !== undefined) {
            effDaysSum += Number(g.cur_median_eff_days);
            effDaysCount += 1;
        }
        if (g.cur_median_perc_days !== null && g.cur_median_perc_days !== undefined) {
            percDaysSum += Number(g.cur_median_perc_days);
            percDaysCount += 1;
        }
        if (g.compliance_pct !== null && g.compliance_pct !== undefined) {
            compSum += Number(g.compliance_pct);
            compCount += 1;
        }
        // Day-ruler compliance twin — chosen at display when the unit is days.
        if (g.compliance_pct_days !== null && g.compliance_pct_days !== undefined) {
            compDaysSum += Number(g.compliance_pct_days);
            compDaysCount += 1;
        }
        if (g.trend_pct_30d !== null && g.trend_pct_30d !== undefined) {
            trendSum += Number(g.trend_pct_30d);
            trendCount += 1;
        }
    });
    return {
        score: scoreWeight > 0 ? scoreSum / scoreWeight : null,
        band: null,
        effective: effCount > 0 ? effSum / effCount : null,
        perceivedraw: rawCount > 0 ? rawSum / rawCount : null,
        effectivedays: effDaysCount > 0 ? effDaysSum / effDaysCount : null,
        perceiveddays: percDaysCount > 0 ? percDaysSum / percDaysCount : null,
        compliance: compCount > 0 ? compSum / compCount : null,
        compliancedays: compDaysCount > 0 ? compDaysSum / compDaysCount : null,
        trendpct: trendCount > 0 ? trendSum / trendCount : null,
        'total_pending': pending,
        'total_critical': critical,
        'total_overgoal': overgoal,
    };
};

/**
 * Header cell — column label as a button that drives the server-side sort.
 *
 * @param {object} props
 * @param {string} props.label
 * @param {string} props.sortKey
 * @param {string} props.currentKey
 * @param {string} props.currentOrder
 * @param {Function} props.onClick
 * @returns {object} vnode
 */
const SortableHeader = ({label, sortKey, currentKey, currentOrder, onClick}) => {
    const active = currentKey === sortKey;
    let arrow = '';
    let ariaSort = 'none';
    if (active) {
        arrow = currentOrder === 'asc' ? ' ▲' : ' ▼';
        ariaSort = currentOrder === 'asc' ? 'ascending' : 'descending';
    }
    return html`
        <th scope="col"
            class=${'bft-th-sortable' + (active ? ' is-active' : '')}
            aria-sort=${ariaSort}>
            <button type="button"
                    class="bft-th-sortable-btn"
                    onClick=${() => onClick(sortKey)}>
                ${label}${arrow}
            </button>
        </th>
    `;
};

/**
 * Paused-period info chip — a hatched swatch + "i" toggle that pins a small
 * popover explaining that the row crossed a paused window. Shared by the
 * pending (perceived) cell and the graded (result) cell so both annotate a
 * pause identically; the caller passes the already unit-resolved tip copy
 * (business-hours vs business-days wording).
 *
 * @param {object} props
 * @param {number} props.submissionid  Row id; drives the open-popover state.
 * @param {string} props.tip           Localised, unit-resolved explanation.
 * @param {string} props.label         Localised "paused" aria label.
 * @param {number|null} props.openid   Currently open submissionid, or null.
 * @param {Function} props.onToggle    Sets the open submissionid (or null).
 * @returns {object} vnode
 */
const PausedTag = ({submissionid, tip, label, openid, onToggle}) => html`
    <span class="bft-row-paused-wrap">
        <button type="button"
                class="bft-row-paused-tag"
                title=${tip}
                aria-label=${label}
                aria-expanded=${openid === submissionid ? 'true' : 'false'}
                onClick=${() => onToggle(openid === submissionid ? null : submissionid)}>
            <span class="bft-row-paused-swatch" aria-hidden="true"></span>
            <span class="bft-row-paused-i" aria-hidden="true">i</span>
        </button>
        ${openid === submissionid && html`
            <span class="bft-row-paused-pop" role="note">${tip}</span>
        `}
    </span>
`;

/**
 * Top-level view.
 *
 * @param {object} props
 * @param {object} props.initial  Mount-point payload.
 * @returns {object} vnode
 */
// Branch count over the lint cap is acknowledged debt: decomposing this view
// is tracked for a dedicated refactor pass (see CLAUDE.md, CI workflow notes).
// eslint-disable-next-line complexity
export default function PendingReportView({initial}) {
    const i18n = initial.i18n || {};
    const config = initial.config || {};
    const scoreThresholds = config.score_thresholds || null;
    const courseid = Number(initial.courseid) || 0;
    const initialPending = initial.pending || {};


    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    const courseUrl = wwwroot + '/course/view.php?id=' + encodeURIComponent(String(courseid));

    // --- Mode + server-driven filters. ---
    const [mode, setMode] = useState('pending');
    const [groupid, setGroupid] = useState(Number(initialPending.groupid) || 0);
    const [filter, setFilter] = useState(String(initialPending.band || ''));
    const [serverSort, setServerSort] = useState(String(initialPending.sort || 'longestwait'));
    const [sortOrder, setSortOrder] = useState('desc');
    const [page, setPage] = useState(Number(initialPending.page) || 0);
    const [perpage] = useState(Number(initialPending.perpage) || 25);

    // Debounced search: searchInput is bound to the box; search drives the WS.
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');

    // --- Page data — all fetched after mount (the bootstrap ships only the
    // filter parameters), so the page's first byte never blocks on the
    // submissions queries. loading starts true so the first paint shows the
    // table skeleton instead of a flash of "no submissions".
    const [submissions, setSubmissions] = useState([]);
    const [total, setTotal] = useState(0);
    const [counts, setCounts] = useState({});
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    // Drafts (pending mode only) — lazy-loaded after the main table lands,
    // refetched on class change.
    const [drafts, setDrafts] = useState([]);

    // Hero scopes + class-filter list, from the lightweight rollup-only
    // get_report_scopes WS (the full responsiveness payload is never built
    // for this page). Hero renders "—" and the class filter stays hidden
    // until this lands.
    const [groupScopes, setGroupScopes] = useState([]);
    const availableGroups = groupScopes.map((g) => ({
        id: Number(g.groupid) || 0,
        name: g.name || '',
    }));

    /**
     * Load the per-group scopes (hero + class filter).
     */
    const fetchScopes = async() => {
        try {
            const result = await getReportScopes({courseid});
            setGroupScopes(Array.isArray(result.groups) ? result.groups : []);
        } catch (e) {
            setGroupScopes([]);
        }
    };

    // Collapse state for the hero + heatmap container (Moodle user preference).
    const [collapsed, setCollapsed] = useState(Boolean(initial.report_collapsed));
    // Scheduled-pause notice — preloaded course-scope by pending_report.php,
    // already decorated + visibility-filtered server-side. Gated by the admin
    // toggle (default ON).
    const [upcoming] = useState(Array.isArray(initial.upcoming) ? initial.upcoming : []);

    // Which row's paused-info popover is open (submissionid, or null). The
    // info icon keeps its hover title; click pins the explanation for
    // touch devices and discoverability.
    const [pausedinfo, setPausedinfo] = useState(null);

    // Academic-days heatmap (async).
    const [acaDays, setAcaDays] = useState([]);
    const [acaSummary, setAcaSummary] = useState(null);
    const [acaEvents, setAcaEvents] = useState([]);
    const [acaLoading, setAcaLoading] = useState(true);
    const [acaError, setAcaError] = useState(null);

    const firstRun = useRef(true);
    const firstRunDrafts = useRef(true);

    /**
     * Pick the user-facing message for a failed fetch: a friendly connectivity
     * notice for network drops (api.js tags these `bftNetwork` and suppresses
     * the technical toast) or the generic report error otherwise.
     *
     * @param {*} e  The rejection value from a web-service call.
     * @returns {string}
     */
    const netMsg = (e) => (e && e.bftNetwork)
        ? (i18n.connection_lost || 'Connection lost. Check your internet and try again.')
        : (i18n.pendingreport_error || 'Failed to load.');

    /**
     * Fetch a page using the current mode + filters.
     */
    const fetchPage = async() => {
        setLoading(true);
        setError(null);
        const args = {courseid, groupid, sort: serverSort, order: sortOrder, search, page, perpage};
        try {
            const result = mode === 'graded'
                ? await getGradedSubmissions(Object.assign({}, args, {bucket: filter}))
                : await getPendingSubmissions(Object.assign({}, args, {band: filter}));
            setSubmissions(Array.isArray(result.submissions) ? result.submissions : []);
            setTotal(Number(result.total) || 0);
            setCounts(result.counts || {});
        } catch (e) {
            setError(netMsg(e));
        } finally {
            setLoading(false);
        }
    };

    // Debounce keystrokes into the search term (reset to page 0 on change).
    useEffect(() => {
        const t = setTimeout(() => {
            setPage(0);
            setSearch(searchInput);
        }, 350);
        return () => clearTimeout(t);
    }, [searchInput]);

    // Re-fetch on any server-driven change. The guard skips the mount run —
    // the mount effect below owns the initial load.
    useEffect(() => {
        if (firstRun.current) {
            firstRun.current = false;
            return;
        }
        fetchPage();
    }, [mode, groupid, filter, serverSort, sortOrder, search, page]);

    // Drafts depend only on the class filter.
    const fetchDrafts = async() => {
        try {
            const result = await getPendingSubmissions({
                courseid, groupid, status: 'draft', sort: 'recent', perpage,
            });
            setDrafts(Array.isArray(result.submissions) ? result.submissions : []);
        } catch (e) {
            setDrafts([]);
        }
    };
    // Guard skips the mount run — the mount effect below chains the initial
    // drafts fetch after the first table page resolves.
    useEffect(() => {
        if (firstRunDrafts.current) {
            firstRunDrafts.current = false;
            return;
        }
        fetchDrafts();
    }, [groupid]);

    // Mount: the main table is what the teacher came for, so it fetches
    // first, in parallel with the lightweight hero scopes; drafts follow once
    // the table has landed. The academic-days strip has its own mount effect.
    useEffect(() => {
        fetchScopes();
        // Errors surface inside fetchPage/fetchDrafts themselves.
        fetchPage().then(fetchDrafts).catch(() => null);
    }, []);

    // Academic-days heatmap loads on mount and on class change.
    const fetchAcademic = async() => {
        setAcaLoading(true);
        setAcaError(null);
        try {
            const result = await getAcademicDays({courseid, groupid});
            setAcaDays(Array.isArray(result.days) ? result.days : []);
            setAcaSummary(result.summary || null);
            setAcaEvents(Array.isArray(result.events) ? result.events : []);
        } catch (e) {
            setAcaDays([]);
            setAcaError(netMsg(e));
        } finally {
            setAcaLoading(false);
        }
    };
    useEffect(() => {
        fetchAcademic();
    }, [groupid]);

    const totalPages = Math.max(1, Math.ceil(total / perpage));
    const graded = mode === 'graded';

    // Column captions — each cell repeats its caption through data-label, which
    // surfaces as the field label when the table reflows into stacked cards on
    // narrow screens (mirrors the dashboard courses table). Values match the
    // header strings so the card caption always equals its column header.
    const cols = {
        activity: i18n.drilldown_col_activity,
        'class': i18n.pendingreport_filter_class_label || 'Class',
        submitted: i18n.drilldown_col_submitted,
        graded: i18n.pendingreport_col_graded || 'Graded',
        effective: i18n.pendingreport_col_effective || 'Effective',
        perceived: i18n.pendingreport_col_perceived || 'Perceived',
        status: graded
            ? (i18n.pendingreport_col_result || 'Result')
            : i18n.drilldown_col_status,
        lastsaved: i18n.drilldown_col_lastsaved || 'Last saved',
    };

    /**
     * Toggle the collapse state, persisting it as a user preference.
     *
     * @param {boolean} next
     */
    const handleToggleCollapsed = (next) => {
        setCollapsed(next);
        setUserPreference(PREF_REPORT_COLLAPSED, next ? '1' : '0')
            .catch((e) => {
                setCollapsed(!next);
                Notification.exception(e);
            });
    };

    /**
     * Switch pending ↔ graded, resetting the dependent filters.
     *
     * @param {string} m
     */
    const handleModeChange = (m) => {
        if (m === mode) {
            return;
        }
        setMode(m);
        setFilter('');
        setCounts({});
        setPage(0);
        setServerSort(m === 'graded' ? 'graded' : 'longestwait');
        setSortOrder('desc');
    };

    /**
     * Toggle / set the server-side column sort.
     *
     * @param {string} key
     */
    const handleSort = (key) => {
        if (serverSort === key) {
            setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
        } else {
            setServerSort(key);
            const textcol = key === 'student' || key === 'activity' || key === 'class';
            setSortOrder(textcol ? 'asc' : 'desc');
        }
        setPage(0);
    };

    /**
     * Deep-link to the single-student grader for one submission.
     *
     * @param {object} row
     * @returns {string}
     */
    const graderUrl = (row) => wwwroot + '/mod/assign/view.php?id='
        + encodeURIComponent(String(row.cmid))
        + '&action=grader&userid=' + encodeURIComponent(String(row.userid));

    // --- Hero scope. ---
    const scope = computeScope(groupScopes, groupid);
    const scopeScore = scope && scope.score !== null && scope.score !== undefined
        ? Number(scope.score) : null;
    const scopeBand = (scope && scope.band) || bandForScore(scopeScore, scoreThresholds);
    const scopeBandLabel = (i18n.bands || {})[scopeBand] || '';
    // Compliance chip + hero honour the display unit: business-days mode uses
    // the day-ruler twin (compliancedays), hours mode the effective-hours value.
    let scopeCompliance = null;
    if (scope) {
        scopeCompliance = usesDays(config) ? scope.compliancedays : scope.compliance;
    }

    const chips = [];
    if (scope) {
        if (scopeCompliance !== null && scopeCompliance !== undefined) {
            chips.push({
                label: Math.round(Number(scopeCompliance)) + '% ' + (i18n.report_chip_sla || 'within SLA'),
                tone: scopeBand,
            });
        }
        if (scope.total_overgoal > 0) {
            chips.push({label: formatCount(scope.total_overgoal) + ' ' + (i18n.hero_sla_atrisk || 'at risk'), tone: 'regular'});
        }
        if (scope.total_critical > 0) {
            chips.push({label: formatCount(scope.total_critical) + ' ' + (i18n.hero_sla_critical || 'critical'), tone: 'critical'});
        }
    }

    let perceivedlabel;
    if (usesDays(config)) {
        perceivedlabel = formatDays(scope ? scope.perceiveddays : null);
    } else {
        perceivedlabel = scope ? perceivedLabel(scope.perceivedraw) : '—';
    }

    const heroprops = {
        score: scopeScore,
        band: scopeBand,
        bandlabel: scopeBandLabel,
        effectivehours: scope && scope.effective !== null && scope.effective !== undefined
            ? Number(scope.effective) : null,
        effectivedays: scope && scope.effectivedays !== null && scope.effectivedays !== undefined
            ? Number(scope.effectivedays) : null,
        perceivedlabel,
        compliancepct: scopeCompliance !== null && scopeCompliance !== undefined
            ? Number(scopeCompliance) : null,
        trendpct: scope && scope.trendpct !== null && scope.trendpct !== undefined
            ? Number(scope.trendpct) : null,
        i18n,
        config,
        chips,
        eyebrow: i18n.report_hero_eyebrow,
        headline: i18n.report_hero_headline,
        body: i18n.report_hero_body,
    };

    const sublineLabel = (graded
        ? (i18n.pendingreport_subline_graded || '{$a} graded')
        : (i18n.pendingreport_subline_pending || '{$a} awaiting feedback')).replace('{$a}', formatCount(total));

    // Empty-state precedence: skeleton while the first page loads, retry on
    // error, "nothing matches" otherwise; null means the table renders.
    let emptystate = null;
    if (loading && submissions.length === 0) {
        emptystate = html`<${Skeleton} count=${5} />`;
    } else if (error && submissions.length === 0) {
        emptystate = html`
            <${RetryNotice}
                message=${error}
                onRetry=${fetchPage}
                retrying=${loading}
                i18n=${i18n}
                variant="block" />`;
    } else if (submissions.length === 0) {
        emptystate = html`
            <div class="bft-empty">
                ${i18n.pendingreport_empty || 'No submissions match the current filter.'}
            </div>
        `;
    }

    return html`
        <div class="bft-report">
            <nav class="bft-breadcrumb" aria-label="breadcrumb">
                <a class="bft-crumb-link" href=${courseUrl}>
                    ${i18n.pendingreport_breadcrumb_course || '← Back to course'}
                </a>
                <span class="bft-crumb-sep">/</span>
                <span class="bft-crumb-current">
                    ${i18n.pendingreport_crumb_current || 'Pending grading · detailed report'}
                </span>
            </nav>

            <div class="bft-report-title-row">
                <div class="bft-report-title-text">
                    <h1 class="bft-report-h1">${initial.coursename}</h1>
                    <div class="bft-report-subline">${sublineLabel}</div>
                </div>
                ${scopeBand && scopeBandLabel && html`
                    <${Badge} band=${scopeBand} label=${scopeBandLabel} />
                `}
            </div>

            ${scope && html`
                <${ResponsivenessModule}
                    collapsed=${collapsed}
                    onToggle=${handleToggleCollapsed}
                    heroprops=${heroprops} />
            `}

            ${config.show_scheduled_pauses !== false && html`
                <${ScheduledPauses} pauses=${upcoming} i18n=${i18n} />
            `}

            ${!collapsed && html`
                <${AcademicDaysStrip}
                    days=${acaDays}
                    summary=${acaSummary}
                    events=${acaEvents}
                    loading=${acaLoading}
                    error=${acaError}
                    onRetry=${fetchAcademic}
                    i18n=${i18n}
                    config=${config} />
            `}

            <${StatusDistributionBar}
                mode=${mode}
                counts=${counts}
                active=${filter}
                onSelect=${(slug) => {
 setPage(0); setFilter(slug);
}}
                onModeChange=${handleModeChange}
                i18n=${i18n} />

            <div class="bft-report-controls">
                ${availableGroups.length > 1 && html`
                    <label class="bft-filter-label">
                        <span>${i18n.pendingreport_filter_class_label || 'Class'}</span>
                        <select value=${String(groupid)}
                                onChange=${(e) => {
 setPage(0); setGroupid(Number(e.target.value));
}}>
                            <option value="0">${i18n.pendingreport_filter_group_all || 'All groups'}</option>
                            ${availableGroups.map((g) => html`
                                <option value=${String(g.id)} key=${'g-' + g.id}>${g.name}</option>
                            `)}
                        </select>
                    </label>
                `}
                <div class="bft-report-controls-spacer"></div>
                <input type="search"
                       class="bft-search"
                       placeholder=${i18n.pendingreport_search_placeholder || 'Search by name…'}
                       aria-label=${i18n.pendingreport_search_placeholder || 'Search by name'}
                       value=${searchInput}
                       onInput=${(e) => setSearchInput(e.target.value)} />
                <button type="button"
                        class=${'bft-refresh' + (loading ? ' bft-refresh-busy' : '')}
                        disabled=${loading}
                        title=${i18n.card_refresh}
                        aria-label=${i18n.card_refresh}
                        onClick=${fetchPage}>⟳</button>
            </div>

            ${error && submissions.length > 0 && html`
                <${RetryNotice}
                    message=${error}
                    onRetry=${fetchPage}
                    retrying=${loading}
                    i18n=${i18n}
                    variant="banner" />`}

            ${emptystate}
            ${!emptystate && html`
                    <table class="bft-report-table">
                        <thead>
                            <tr>
                                <${SortableHeader} label=${i18n.drilldown_col_student}
                                    sortKey="student" currentKey=${serverSort}
                                    currentOrder=${sortOrder} onClick=${handleSort} />
                                <${SortableHeader} label=${i18n.drilldown_col_activity}
                                    sortKey="activity" currentKey=${serverSort}
                                    currentOrder=${sortOrder} onClick=${handleSort} />
                                <${SortableHeader} label=${(i18n.pendingreport_filter_class_label || 'Class')}
                                    sortKey="class" currentKey=${serverSort}
                                    currentOrder=${sortOrder} onClick=${handleSort} />
                                <${SortableHeader} label=${i18n.drilldown_col_submitted}
                                    sortKey="submitted" currentKey=${serverSort}
                                    currentOrder=${sortOrder} onClick=${handleSort} />
                                ${graded && html`
                                    <${SortableHeader} label=${i18n.pendingreport_col_graded || 'Graded'}
                                        sortKey="graded" currentKey=${serverSort}
                                        currentOrder=${sortOrder} onClick=${handleSort} />
                                `}
                                <${SortableHeader} label=${i18n.pendingreport_col_effective || 'Effective'}
                                    sortKey="effective" currentKey=${serverSort}
                                    currentOrder=${sortOrder} onClick=${handleSort} />
                                ${!graded && html`
                                    <${SortableHeader} label=${i18n.pendingreport_col_perceived || 'Perceived'}
                                        sortKey="perceived" currentKey=${serverSort}
                                        currentOrder=${sortOrder} onClick=${handleSort} />
                                `}
                                <${SortableHeader} label=${cols.status}
                                    sortKey="status" currentKey=${serverSort}
                                    currentOrder=${sortOrder} onClick=${handleSort} />
                                <th class="bft-report-col-action">
                                    ${i18n.pendingreport_col_action || 'Action'}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            ${submissions.map(
                                // Branch count over the lint cap is acknowledged debt.
                                // eslint-disable-next-line complexity
                                (row) => {
                                // Paused = part of the window fell in a non-business
                                // period. Detected per the active display unit:
                                // business-days mode compares elapsed calendar vs
                                // business days; hours mode compares wall-clock vs
                                // effective hours.
                                const paused = usesDays(config)
                                    ? Number(row.perceived_days || 0)
                                        - Number(row.effective_days || 0) >= 1
                                    : Number(row.waitinghours || 0)
                                        - Number(row.effectivehours || 0) > PAUSED_TAG_EPSILON;
                                const rowColor = colourFor(row.slabucket);
                                const resultbadge = graded ? gradedBadge(row.slabucket, i18n) : null;
                                return html`
                                    <tr class="bft-report-row" key=${'r-' + row.submissionid}>
                                        <td class="bft-report-cell-title">${row.studentname}</td>
                                        <td data-label=${cols.activity}>${row.activityname}</td>
                                        <td data-label=${cols.class}>${row.groupname || '-'}</td>
                                        <td class="bft-mono" data-label=${cols.submitted}>${formatDate(row.timesubmitted)}</td>
                                        ${graded && html`
                                            <td class="bft-mono" data-label=${cols.graded}>${formatDate(row.timegraded)}</td>
                                        `}
                                        <td class="bft-report-effective bft-mono" data-label=${cols.effective}
                                            style=${'color: ' + rowColor + ';'}>
                                            ${usesDays(config)
                                                ? formatDays(row.effective_days)
                                                : formatHours(row.effectivehours)}
                                        </td>
                                        ${!graded && html`
                                            <td class="bft-mono" data-label=${cols.perceived}>
                                                <span class="bft-report-perceived">
                                                    ${usesDays(config)
                                                        ? formatDays(row.perceived_days)
                                                        : formatHours(row.waitinghours)}
                                                    ${paused && html`<${PausedTag}
                                                        submissionid=${row.submissionid}
                                                        tip=${i18n.pendingreport_row_paused_tip || ''}
                                                        label=${i18n.pendingreport_row_paused || 'paused'}
                                                        openid=${pausedinfo}
                                                        onToggle=${setPausedinfo} />`}
                                                </span>
                                            </td>
                                        `}
                                        <td data-label=${cols.status}>
                                            ${graded
                                                ? html`
                                                    <span class="bft-row-result">
                                                        <${Badge} band=${resultbadge.band}
                                                            label=${resultbadge.label} />
                                                        ${paused && html`<${PausedTag}
                                                            submissionid=${row.submissionid}
                                                            tip=${i18n.pendingreport_row_paused_graded_tip || ''}
                                                            label=${i18n.pendingreport_row_paused || 'paused'}
                                                            openid=${pausedinfo}
                                                            onToggle=${setPausedinfo} />`}
                                                    </span>
                                                `
                                                : (() => {
                                                    const pb = pendingBadge(row.pendingband, i18n);
                                                    return html`<${Badge} band=${pb.band} label=${pb.label} />`;
                                                })()}
                                        </td>
                                        <td class="bft-report-col-action">
                                            <a class="bft-report-action-grade" href=${graderUrl(row)}>
                                                ${graded
                                                    ? (i18n.pendingreport_action_review || 'Review')
                                                    : (i18n.pendingreport_action_grade || 'Grade')}
                                            </a>
                                        </td>
                                    </tr>
                                `;
                            })}
                        </tbody>
                    </table>
                `}

            ${total > perpage && html`
                <div class="bft-pagination">
                    <button type="button"
                            disabled=${page === 0 || loading}
                            onClick=${() => setPage(Math.max(0, page - 1))}>
                        ${i18n.pendingreport_page_prev || '« Prev'}
                    </button>
                    <span class="bft-pagination-state">
                        ${(i18n.pendingreport_page_template || 'Page {$a->page} of {$a->total}')
                            .replace('{$a->page}', String(page + 1))
                            .replace('{$a->total}', String(totalPages))
                        }
                    </span>
                    <button type="button"
                            disabled=${page >= totalPages - 1 || loading}
                            onClick=${() => setPage(page + 1)}>
                        ${i18n.pendingreport_page_next || 'Next »'}
                    </button>
                </div>
            `}

            ${!graded && drafts.length > 0 && html`
                <div class="bft-report-drafts">
                    <h2 class="bft-report-drafts-heading">
                        ${i18n.drafts_heading || 'Not yet submitted'}
                    </h2>
                    ${i18n.drafts_note && html`
                        <p class="bft-report-drafts-note">${i18n.drafts_note}</p>
                    `}
                    <table class="bft-report-table bft-report-drafts-table">
                        <thead>
                            <tr>
                                <th>${i18n.drilldown_col_student}</th>
                                <th>${i18n.drilldown_col_activity}</th>
                                <th>${i18n.pendingreport_filter_class_label || 'Class'}</th>
                                <th>${i18n.drilldown_col_lastsaved || 'Last saved'}</th>
                                <th>${i18n.drilldown_col_status}</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${drafts.map((row) => html`
                                <tr class="bft-report-row bft-report-row--draft"
                                    key=${'d-' + row.submissionid}>
                                    <td class="bft-report-cell-title">${row.studentname}</td>
                                    <td data-label=${cols.activity}>${row.activityname}</td>
                                    <td data-label=${cols.class}>${row.groupname || '-'}</td>
                                    <td class="bft-mono" data-label=${cols.lastsaved}>${formatDate(row.timesubmitted)}</td>
                                    <td data-label=${cols.status}>
                                        <span class="bft-badge bft-badge-draft">
                                            ${i18n.status_draft || 'Draft'}
                                        </span>
                                    </td>
                                </tr>
                            `)}
                        </tbody>
                    </table>
                </div>
            `}
        </div>
    `;
}
