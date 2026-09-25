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
 * Teacher dashboard view — cross-course overview.
 *
 * Composition (top → bottom):
 *   1. Brand-tag eyebrow
 *   2. Greeting H1 (time-of-day aware) + WaveMark
 *   3. Subline (pending count · critical count · business-time chip · latest event)
 *   4. ResponsivenessModule — full hero ↔ slim strip (collapsible)
 *   5. Scheduled-pause notice
 *   6. Insights row (Bright spot / Most improved / Gentle watch)
 *   7. "Grade now · picked for you" — 3 priority cards
 *   8. "Your courses" table with inline ScoreRing + sparkline + Open link
 *   9. Site benchmarks (SchoolComparison), for viewers the config bundle
 *      flags with `school_comparison`
 *
 * The mount-point JSON carries strings, config, the collapse preference and
 * the pause / event sidecars only; course rows, the grade-now list and the
 * insights load through web services after mount.
 *
 * @module    block_feedback_tracker/views/DashboardView
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState, useMemo, useEffect} from 'block_feedback_tracker/lib/preact';
import ResponsivenessModule from 'block_feedback_tracker/components/ResponsivenessModule';
import InsightCard from 'block_feedback_tracker/components/InsightCard';
import PriorityCard from 'block_feedback_tracker/components/PriorityCard';
import CoursesTable from 'block_feedback_tracker/components/CoursesTable';
import WaveMark from 'block_feedback_tracker/components/WaveMark';
import RetryNotice from 'block_feedback_tracker/components/RetryNotice';
import ScheduledPauses from 'block_feedback_tracker/components/ScheduledPauses';
import SchoolComparison from 'block_feedback_tracker/components/SchoolComparison';
import {getDashboard, getGraderPriorityList, getInsights}
    from 'block_feedback_tracker/lib/api';
import {bandForScore} from 'block_feedback_tracker/lib/bands';
import {aggregate, perceivedLabel} from 'block_feedback_tracker/lib/aggregate';
import {usesDays, formatDays, formatCount} from 'block_feedback_tracker/lib/format';
import {setUserPreference} from 'core_user/repository';
import Notification from 'core/notification';

/** Moodle user-preference name persisting the hero+insights collapse state. */
const PREF_DASHBOARD_COLLAPSED = 'block_feedback_tracker_dashboard_collapsed';

/**
 * Pure client-side sort for the courses table.
 *
 * @param {Array<object>} rows
 * @param {string|null} sortKey
 * @param {string} sortOrder
 * @returns {Array<object>}
 */
const sortCourses = (rows, sortKey, sortOrder) => {
    if (!sortKey || !Array.isArray(rows)) {
        return rows;
    }
    const dir = sortOrder === 'asc' ? 1 : -1;
    const numeric = ['pending', 'critical', 'overgoal', 'avgscore', 'cur_median_eff_h', 'cur_median_eff_days'];
    const numkey = numeric.indexOf(sortKey) !== -1;
    const copy = rows.slice();
    copy.sort((a, b) => {
        const va = a[sortKey];
        const vb = b[sortKey];
        if (numkey) {
            return ((Number(va) || 0) - (Number(vb) || 0)) * dir;
        }
        return String(va || '').localeCompare(String(vb || '')) * dir;
    });
    return copy;
};

/**
 * Pick the time-of-day greeting string key from local clock.
 *
 * @returns {string}
 */
const greetingKey = () => {
    const h = new Date().getHours();
    if (h < 12) {
        return 'dashboard_greeting_morning';
    }
    if (h < 18) {
        return 'dashboard_greeting_afternoon';
    }
    return 'dashboard_greeting_evening';
};

/**
 * Format YYYYMMDD → "DD/MM" for the dashboard event chip.
 *
 * @param {number} ymd
 * @returns {string}
 */
const fmtEventYmd = (ymd) => {
    const n = Number(ymd) || 0;
    const m = Math.floor((n / 100) % 100);
    const d = n % 100;
    return String(d).padStart(2, '0') + '/' + String(m).padStart(2, '0');
};

/**
 * Format minutes-since-midnight → "HH:MM" for the dashboard event chip.
 *
 * @param {number} min
 * @returns {string}
 */
const fmtEventMin = (min) => {
    const n = Number(min) || 0;
    const h = Math.floor(n / 60);
    const m = n % 60;
    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
};

/**
 * Build the group label for an insight card. Insights point at one specific
 * (course, group); the card title shows the course, so when the insight
 * refers to a named group we add a "Group: X" line beneath it — otherwise a
 * teacher in several groups can't tell which group the callout is praising
 * or flagging. Empty for whole-course insights (groupid 0).
 *
 * @param {object|null|undefined} insight  One of bright_spot/most_improved/gentle_watch.
 * @param {object} i18n
 * @returns {string}
 */
const groupLabel = (insight, i18n) => {
    if (!insight || !insight.groupname) {
        return '';
    }
    return (i18n.insight_group_label || 'Group') + ': ' + insight.groupname;
};

/**
 * Top-level dashboard view.
 *
 * @param {object} props
 * @param {object} props.initial   Mount-point payload: {greeting_firstname,
 *                                 dashboard, gradenow, insights, events, upcoming,
 *                                 dashboard_collapsed, i18n, config}.
 * @returns {object} vnode
 */
// Branch count over the lint cap is acknowledged debt (refactor pass pending).
// eslint-disable-next-line complexity
export default function DashboardView({initial}) {
    const i18n = initial.i18n || {};
    const config = initial.config || {};
    const scoreThresholds = config.score_thresholds || null;
    const dashboard = initial.dashboard || {};

    const [courses, setCourses] = useState(
        Array.isArray(dashboard.courses) ? dashboard.courses : []
    );
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState(null);
    const [sortKey, setSortKey] = useState('pending');
    const [sortOrder, setSortOrder] = useState('desc');
    const [gradenow, setGradenow] = useState(initial.gradenow || null);
    const [gradenowError, setGradenowError] = useState(null);
    const [insights, setInsights] = useState(initial.insights || null);
    // True until the first course-rows fetch settles. The page ships no rows,
    // so this starts true unless the payload inlined some.
    const [loadingcourses, setLoadingCourses] = useState(
        !(Array.isArray(dashboard.courses) && dashboard.courses.length > 0)
    );
    // Site-scope sub-day optional events of the last 30 days, preloaded by
    // teacher_dashboard.php so the subline can show the most recent named
    // one (e.g. "Recent event: Match day · 21/05 16:00-18:00").
    const [events] = useState(Array.isArray(initial.events) ? initial.events : []);
    // Scheduled-pause notice — preloaded site-scope by teacher_dashboard.php,
    // already decorated + visibility-filtered server-side. Gated by the admin
    // toggle (default ON).
    const [upcoming] = useState(Array.isArray(initial.upcoming) ? initial.upcoming : []);
    /*
     * Collapsed state for the combined Responsiveness hero +
     * Insights block. Initial value comes from the user preference
     * preloaded by teacher_dashboard.php so the first paint already
     * matches the user's saved choice; toggling writes back through
     * core_user/repository::setUserPreference().
     */
    const [collapsed, setCollapsed] = useState(Boolean(initial.dashboard_collapsed));

    const sorted = useMemo(() => sortCourses(courses, sortKey, sortOrder), [courses, sortKey, sortOrder]);
    const totals = useMemo(() => aggregate(courses, 'avgscore'), [courses]);
    const heroBand = bandForScore(totals.score, scoreThresholds);
    const heroBandLabel = (i18n.bands || {})[heroBand] || '';

    // Local-clock greeting, recomputed every render (cheap).
    const greetingTemplate = i18n[greetingKey()]
        || 'Hi there, {$a->firstname}';
    const greeting = greetingTemplate.replace('{$a->firstname}', initial.greeting_firstname || '');

    /**
     * Toggle the hero+insights collapsed state. Optimistic: local state
     * flips at once and the preference is written in the background; if the
     * write fails the state reverts and core/notification reports that the
     * choice was not saved.
     *
     * @param {boolean} next
     */
    const handleToggleCollapsed = (next) => {
        setCollapsed(next);
        setUserPreference(PREF_DASHBOARD_COLLAPSED, next ? '1' : '0')
            .catch((e) => {
                setCollapsed(!next);
                Notification.exception(e);
            });
    };

    /**
     * Pick the user-facing message for a failed fetch: a friendly connectivity
     * notice for network drops (api.js tags these `bftNetwork` and suppresses
     * the technical toast) or the generic dashboard error otherwise.
     *
     * @param {*} e  The rejection value from a web-service call.
     * @returns {string}
     */
    const netMsg = (e) => (e && e.bftNetwork)
        ? (i18n.connection_lost || 'Connection lost. Check your internet and try again.')
        : (i18n.dashboard_error || 'Failed to load dashboard.');

    /**
     * Refresh courses + grade-now + insights in parallel.
     */
    const handleRefresh = async() => {
        if (refreshing) {
            return;
        }
        setRefreshing(true);
        setError(null);
        setGradenowError(null);
        try {
            const [resCourses, resGradenow, resInsights] = await Promise.all([
                getDashboard({}),
                getGraderPriorityList({limit: 3}),
                getInsights(),
            ]);
            if (resCourses && Array.isArray(resCourses.courses)) {
                setCourses(resCourses.courses);
            }
            if (resGradenow) {
                setGradenow(resGradenow);
            }
            if (resInsights) {
                setInsights(resInsights);
            }
        } catch (e) {
            setError(netMsg(e));
        } finally {
            setRefreshing(false);
        }
    };

    // Initial async load. teacher_dashboard.php ships no data, so the page
    // never blocks on per-course aggregation. Course rows (from which
    // aggregate() derives the hero score) and the grade-now list are fetched
    // here; insights lazy-load in the effect below. Each WS re-applies the
    // server-side dashboard_scope gate, so this does not widen visibility.
    useEffect(() => {
        let cancelled = false;
        getDashboard({})
            .then((res) => {
                if (!cancelled && res && Array.isArray(res.courses)) {
                    setCourses(res.courses);
                }
                return null;
            })
            .catch((e) => {
                if (!cancelled) {
                    setError(netMsg(e));
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoadingCourses(false);
                }
            });
        getGraderPriorityList({limit: 3})
            .then((res) => {
                if (!cancelled && res) {
                    setGradenow(res);
                }
                return null;
            })
            // Grade-now is a non-critical extra; swallow its fetch errors.
            .catch(() => null);
        return () => {
            cancelled = true;
        };
    }, []);

    // Lazy-load insights on mount if the server didn't preload them.
    useEffect(() => {
        if (insights !== null) {
            return;
        }
        getInsights()
            .then((res) => setInsights(res || {}))
            .catch(() => setInsights({}));
    }, []);

    const handleSort = (key) => {
        if (sortKey === key) {
            setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
        } else {
            setSortKey(key);
            setSortOrder(key === 'coursename' ? 'asc' : 'desc');
        }
    };

    const priorities = (gradenow && Array.isArray(gradenow.submissions))
        ? gradenow.submissions.slice(0, 3) : [];

    // Build the hero props once so both the full and slim variants get the same shape.
    const heroprops = {
        score: totals.score,
        band: heroBand,
        bandlabel: heroBandLabel,
        effectivehours: totals.effective,
        effectivedays: totals.effectivedays,
        perceivedlabel: usesDays(config)
            ? formatDays(totals.perceiveddays)
            : perceivedLabel(totals.perceived),
        compliancepct: usesDays(config) ? totals.compliancedays : totals.compliance,
        trendpct: totals.trendpct,
        i18n,
        config,
    };

    let coursesbody;
    if (loadingcourses && courses.length === 0) {
        coursesbody = html`<div class="bft-empty">${i18n.gradenow_loading || 'Loading…'}</div>`;
    } else if (error && courses.length === 0) {
        coursesbody = html`<${RetryNotice}
            message=${error}
            onRetry=${handleRefresh}
            retrying=${refreshing}
            i18n=${i18n}
            variant="block" />`;
    } else {
        coursesbody = html`<${CoursesTable}
            rows=${sorted}
            i18n=${i18n}
            sortKey=${sortKey}
            sortOrder=${sortOrder}
            onSort=${handleSort}
            thresholds=${scoreThresholds}
            goal=${config.sla_goal_hours}
            config=${config} />`;
    }

    return html`
        <div class="bft-dashboard">
            <header class="bft-dashboard-header">
                <div class="bft-dashboard-header-text">
                    <div class="bft-dashboard-brandtag">
                        ${i18n.dashboard_brandtag || 'Feedback tracker'}
                    </div>
                    <h1 class="bft-dashboard-h1">
                        ${greeting}
                        <span class="bft-dashboard-wave"><${WaveMark} size=${26} /></span>
                    </h1>
                    <div class="bft-dashboard-subline">
                        <strong>${formatCount(totals.pending)}</strong>
                        <span>${i18n.dashboard_subline_waiting || 'waiting'}</span>
                        <span class="bft-dashboard-dot">·</span>
                        <strong class=${totals.critical > 0 ? 'bft-overall-score-tone-critical' : ''}>
                            ${formatCount(totals.critical)}
                        </strong>
                        <span>${i18n.dashboard_subline_critical || 'critical'}</span>
                        <span class="bft-dashboard-dot">·</span>
                        <span class="bft-dashboard-business-chip">
                            ${i18n.dashboard_business_chip
                                || 'Business-time only · weekends, holidays & recess paused'}
                        </span>
                        ${events.length > 0 && (() => {
                            // Latest named optional event from the past 30
                            // days site-scope. paused_aggregator emits in
                            // date order, so the last entry is the most recent.
                            const latest = events[events.length - 1];
                            const head = fmtEventYmd(latest.date) + ' '
                                + fmtEventMin(latest.starttime) + '-'
                                + fmtEventMin(latest.endtime);
                            return html`
                                <span class="bft-dashboard-dot">·</span>
                                <span class="bft-dashboard-event-chip"
                                      title=${i18n.dashboard_event_chip_tooltip
                                          || 'Most recent named optional event'}>
                                    <span class="bft-dashboard-event-chip-label">
                                        ${i18n.dashboard_event_chip_label || 'Recent event'}:
                                    </span>
                                    ${' '}${latest.label ? latest.label + ' · ' : ''}${head}
                                </span>
                            `;
                        })()}
                    </div>
                </div>
                <button type="button"
                        class=${'bft-refresh' + (refreshing ? ' bft-refresh-busy' : '')}
                        disabled=${refreshing}
                        title=${i18n.dashboard_refresh || 'Refresh'}
                        aria-label=${i18n.dashboard_refresh || 'Refresh'}
                        onClick=${handleRefresh}>⟳</button>
            </header>

            ${error && courses.length > 0 && html`
                <${RetryNotice}
                    message=${error}
                    onRetry=${handleRefresh}
                    retrying=${refreshing}
                    i18n=${i18n}
                    variant="banner" />`}

            <${ResponsivenessModule}
                collapsed=${collapsed}
                onToggle=${handleToggleCollapsed}
                heroprops=${heroprops} />

            ${config.show_scheduled_pauses !== false && html`
                <${ScheduledPauses} pauses=${upcoming} i18n=${i18n} />
            `}

            ${!collapsed
                && insights
                && (insights.bright_spot || insights.most_improved || insights.gentle_watch) && html`
                <section class="bft-dashboard-insights">
                    <div class="bft-dashboard-section-eyebrow">
                        ${i18n.dashboard_insights_title || 'Insights'}
                    </div>
                    <div class="bft-insights-row">
                        ${insights.bright_spot && html`
                            <${InsightCard}
                                tone="bright"
                                eyebrow=${i18n.insight_brightspot_eyebrow || "This week's bright spot"}
                                title=${insights.bright_spot.coursename}
                                grouplabel=${groupLabel(insights.bright_spot, i18n)}
                                metric_value=${insights.bright_spot.metric_value}
                                metric_suffix=${insights.bright_spot.metric_suffix}
                                body=${i18n.insight_brightspot_body || ''} />
                        `}
                        ${insights.most_improved && html`
                            <${InsightCard}
                                tone="climbing"
                                eyebrow=${insights.most_improved.momentum
                                    ? (i18n.insight_momentum_eyebrow || "This week's momentum")
                                    : (i18n.insight_mostimproved_eyebrow || 'Most improved')}
                                title=${insights.most_improved.coursename}
                                grouplabel=${groupLabel(insights.most_improved, i18n)}
                                metric_value=${insights.most_improved.metric_value}
                                metric_suffix=${insights.most_improved.metric_suffix}
                                body=${insights.most_improved.momentum
                                    ? (i18n.insight_momentum_body || '')
                                    : (i18n.insight_mostimproved_body || '')} />
                        `}
                        ${insights.gentle_watch && html`
                            <${InsightCard}
                                tone="watch"
                                eyebrow=${i18n.insight_gentlewatch_eyebrow || 'Gentle watch'}
                                title=${insights.gentle_watch.coursename}
                                grouplabel=${groupLabel(insights.gentle_watch, i18n)}
                                metric_value=${insights.gentle_watch.metric_value}
                                metric_suffix=${insights.gentle_watch.metric_suffix}
                                body=${i18n.insight_gentlewatch_body || ''} />
                        `}
                    </div>
                </section>
            `}

            ${priorities.length > 0 && html`
                <section class="bft-dashboard-priorities">
                    <div class="bft-dashboard-section-eyebrow">
                        ${i18n.dashboard_priority_title || 'Grade now · picked for you'}
                    </div>
                    ${gradenowError && html`<div class="bft-error" role="alert">${gradenowError}</div>`}
                    <div class="bft-priority-row">
                        ${priorities.map((sub, i) => html`
                            <${PriorityCard}
                                key=${'p-' + (sub.submissionid || i)}
                                idx=${i + 1}
                                submission=${sub}
                                i18n=${i18n}
                                config=${config} />
                        `)}
                    </div>
                </section>
            `}

            <section class="bft-dashboard-courses">
                <div class="bft-dashboard-section-eyebrow">
                    ${i18n.dashboard_courses_title || 'Your courses'}
                </div>
                ${coursesbody}
            </section>

            ${config.school_comparison === true && html`
                <${SchoolComparison} i18n=${i18n} config=${config} />
            `}
        </div>
    `;
}
