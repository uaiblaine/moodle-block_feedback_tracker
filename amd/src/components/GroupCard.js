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
 * Per-group responsiveness card. Collapsible: a clickable header (chevron +
 * title/subtitle + band badge) toggles the body — hero (ring + score) → KPI
 * row → trend row → stat-tile row (the three exclusive pending bands) →
 * optional activities → optional peer context → drilldown foot.
 *
 * Local open/closed state only (default open). Props mirror
 * responsiveness_payload::group_payload(); optional fields hide gracefully.
 *
 * @module    block_feedback_tracker/components/GroupCard
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState} from 'block_feedback_tracker/lib/preact';
import ScoreRing from 'block_feedback_tracker/components/ScoreRing';
import Badge from 'block_feedback_tracker/components/Badge';
import KpiTile from 'block_feedback_tracker/components/KpiTile';
import TrendRow from 'block_feedback_tracker/components/TrendRow';
import StatTile from 'block_feedback_tracker/components/StatTile';
import PeerContext from 'block_feedback_tracker/components/PeerContext';
import TimelineBar from 'block_feedback_tracker/components/TimelineBar';
import {colourFor} from 'block_feedback_tracker/lib/bands';
import {usesDays, formatDecimal} from 'block_feedback_tracker/lib/format';

/**
 * Drilldown URL builder. Optional `band` pre-applies the pending-band filter
 * (aguardando / atencao / prioridade) on the report page.
 *
 * @param {number} courseid
 * @param {number} groupid
 * @param {string} [band]
 * @returns {string}
 */
const buildDrilldownUrl = (courseid, groupid, band) => {

    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    const params = ['courseid=' + encodeURIComponent(String(courseid)),
                    'groupid=' + encodeURIComponent(String(groupid))];
    if (band) {
        params.push('band=' + encodeURIComponent(band));
    }
    return wwwroot + '/blocks/feedback_tracker/pages/pending_report.php?' + params.join('&');
};

/**
 * Group-override editor URL for an assign course-module.
 *
 * @param {number} cmid
 * @returns {string}
 */
const buildOverridesUrl = (cmid) => {

    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    return wwwroot + '/mod/assign/overrides.php?cmid=' + encodeURIComponent(String(cmid)) + '&mode=group';
};

/**
 * Main view URL for an assign course-module.
 *
 * @param {number} cmid
 * @returns {string}
 */
const buildActivityUrl = (cmid) => {

    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    return wwwroot + '/mod/assign/view.php?id=' + encodeURIComponent(String(cmid));
};

/**
 * Localised label for an activity action chip. 'norule' (no manage capability)
 * reuses the rule_off string; the rest are the actionable verbs.
 *
 * @param {string} action  One of 'done', 'create', 'override', 'norule'.
 * @param {object} i18n
 * @returns {string}
 */
const actionLabel = (action, i18n) => {
    switch (action) {
        case 'done': return i18n.rule_done;
        case 'create': return i18n.rule_create;
        case 'override': return i18n.rule_override;
        default: return i18n.rule_off;
    }
};

/**
 * Format a day-median for a KPI tile: whole numbers plain, halves with one
 * decimal in the page language's separator.
 *
 * @param {number|null|undefined} n
 * @returns {string|number}
 */
const fmtDayMedian = (n) => {
    if (n === null || n === undefined) {
        return '—';
    }
    const v = Number(n);
    return Number.isInteger(v) ? v : formatDecimal(v, 1);
};

/**
 * Effective-time value + unit for the KPI tile, honouring the configured
 * display unit. Hours: rounded effective-hours median + "h". Business days:
 * the date-based day median + "d" (counted server-side from the submit/grade
 * dates — no client conversion).
 *
 * @param {object} group
 * @param {object} config
 * @returns {{value: (string|number), unit: string}}
 */
const effectiveKpi = (group, config) => {
    if (usesDays(config)) {
        return {value: fmtDayMedian(group.cur_median_eff_days), unit: 'd'};
    }
    const h = group.cur_median_eff_h;
    return {value: h === null || h === undefined ? '—' : Math.round(Number(h)), unit: 'h'};
};

/**
 * Round a compliance percentage, or "—"; the caller adds the % unit.
 *
 * @param {number|null|undefined} p
 * @returns {string|number}
 */
const fmtPct = (p) => (p === null || p === undefined ? '—' : Math.round(Number(p)));

/**
 * @param {object} props
 * @param {object} props.group      One element of payload.groups.
 * @param {number} props.courseid   Course id (for drilldown URLs).
 * @param {object} props.i18n       Localised label map.
 * @param {object} props.config     Config bundle (sla_goal_hours, display_time_unit, show_peer_context).
 * @returns {object} vnode
 */
// Branch count over the lint cap is acknowledged debt (refactor pass pending).
// eslint-disable-next-line complexity
export default function GroupCard({group, courseid, i18n, config}) {
    const [open, setOpen] = useState(true);
    const score = group.responsiveness_score !== null && group.responsiveness_score !== undefined
        ? Number(group.responsiveness_score) : null;
    const band = group.score_band || 'pending';
    const title = group.groupname && String(group.groupname).length > 0
        ? group.groupname
        : (group.coursename || i18n.card_nogroup);
    const subtitle = group.groupsubtitle && String(group.groupsubtitle).length > 0
        ? group.groupsubtitle : null;
    const bandLabel = i18n.bands && i18n.bands[band] ? i18n.bands[band] : '';
    const series = Array.isArray(group.trend_series)
        ? group.trend_series.map((p) => (p && p.value !== null && p.value !== undefined ? Number(p.value) : null))
        : [];
    const groupid = Number(group.groupid) || 0;
    const goal = config && config.sla_goal_hours ? Number(config.sla_goal_hours) : null;

    // Mutually-exclusive pending bands (sum = total pending): critical
    // | over-goal | within-goal (the remainder within SLA).
    const critical = Number(group.critical) || 0;
    const overgoal = Number(group.overgoal) || 0;
    const waiting = Math.max(0, (Number(group.pending) || 0) - overgoal - critical);

    const allhref = buildDrilldownUrl(courseid, groupid);
    const waitinghref = buildDrilldownUrl(courseid, groupid, 'aguardando');
    const overgoalhref = buildDrilldownUrl(courseid, groupid, 'atencao');
    const criticalhref = buildDrilldownUrl(courseid, groupid, 'prioridade');

    const hasActivities = Array.isArray(group.activities) && group.activities.length > 0;
    // Headline Effective / Perceived use the include-pending "current" medians
    // (cur_median_*) so the block reflects the live backlog, matching the
    // dashboard. The score keeps using graded-only median_eff_h.
    const eff = effectiveKpi(group, config);
    // The Perceived tile is in days in both display units, so it always shows
    // the date-based calendar-day median; days are never derived from hours.
    const perceivedvalue = fmtDayMedian(group.cur_median_perc_days);
    // SLA compliance honours the display unit: business-days mode shows the
    // day-ruler twin (compliance_pct_days), hours mode the effective-hours
    // compliance. Both are display-only; the score is unaffected.
    const compliance = usesDays(config) ? group.compliance_pct_days : group.compliance_pct;
    // Peer panel is opt-out via the global show_peer_context setting; it also
    // self-hides inside PeerContext when there is no dept/top10 data.
    const showpeer = !config || config.show_peer_context !== false;

    return html`
        <div class=${'bft-card' + (open ? ' bft-card-open' : '')}>
            <button type="button"
                    class="bft-card-head"
                    aria-expanded=${open ? 'true' : 'false'}
                    onClick=${() => setOpen(!open)}>
                <span class="bft-card-head-chevron" aria-hidden="true">${open ? '▾' : '▸'}</span>
                <span class="bft-card-head-titles">
                    <span class="bft-card-title" title=${title}>${title}</span>
                    ${subtitle && html`<span class="bft-card-subtitle">${subtitle}</span>`}
                </span>
                <${Badge} band=${band} label=${bandLabel} />
            </button>

            ${open && html`
                <div class="bft-card-stack">
                    <div class="bft-card-hero">
                        <${ScoreRing} score=${score} band=${band} size=${52} thickness=${5} />
                        <div class="bft-card-hero-text">
                            <div class="bft-card-hero-score-row">
                                <span class=${'bft-card-hero-score bft-mono'
                                    + ' bft-overall-score-tone-' + band}>
                                    ${score === null ? '—' : Math.round(score)}
                                </span>
                                <span class="bft-card-hero-score-of bft-mono">/ 100</span>
                            </div>
                            <span class="bft-card-hero-caption">${i18n.card_score_caption}</span>
                        </div>
                    </div>

                    <div class="bft-kpi-row">
                        <${KpiTile}
                            label=${i18n.card_effective}
                            value=${eff.value}
                            unit=${eff.unit}
                            sub=${i18n.card_effective_sub}
                            tone=${band} />
                        <${KpiTile}
                            label=${i18n.card_perceived}
                            value=${perceivedvalue}
                            unit="d"
                            sub=${i18n.card_perceived_sub}
                            tone="muted" />
                        <${KpiTile}
                            label=${i18n.card_sla}
                            value=${fmtPct(compliance)}
                            unit="%"
                            sub=${i18n.card_sla_sub}
                            tone=${band} />
                    </div>

                    <${TrendRow}
                        pct=${group.trend_pct_30d}
                        series=${series}
                        i18n=${i18n}
                        goal=${goal} />

                    <div class="bft-stat-row">
                        <${StatTile}
                            label=${i18n.card_pending}
                            value=${waiting}
                            tone="neutral"
                            href=${waitinghref} />
                        <${StatTile}
                            label=${i18n.card_overgoal}
                            value=${overgoal}
                            tone="warn"
                            href=${overgoalhref} />
                        <${StatTile}
                            label=${i18n.card_critical}
                            value=${critical}
                            tone="critical"
                            href=${criticalhref} />
                    </div>

                    ${hasActivities && html`
                        <div class="bft-activities">
                            <div class="bft-activities-head">${i18n.card_activities_head}</div>
                            ${group.activities.map((act) => html`
                                <div class="bft-activity" key=${'act-' + (act.cmid || act.name)}>
                                    <div class="bft-activity-row">
                                        <a class="bft-activity-title"
                                           href=${buildActivityUrl(act.cmid)}
                                           title=${act.name}>${act.name}</a>
                                        ${act.editable
                                            ? html`<a class=${'bft-activity-action bft-activity-action-' + act.action}
                                                      href=${buildOverridesUrl(act.cmid)}>
                                                ${actionLabel(act.action, i18n)}
                                            </a>`
                                            : html`<span class=${'bft-activity-action bft-activity-action-' + act.action}>
                                                ${actionLabel(act.action, i18n)}
                                            </span>`}
                                    </div>
                                    <${TimelineBar}
                                        opens=${act.opens}
                                        closes=${act.closes}
                                        norulelabel=${i18n.timeline_norule} />
                                </div>
                            `)}
                        </div>
                    `}

                    ${showpeer && html`
                        <${PeerContext}
                            you=${score}
                            youband=${band}
                            department=${group.peer_department_score}
                            top10=${group.peer_top10_score}
                            i18n=${i18n} />
                    `}

                    <div class="bft-card-foot">
                        <a class="bft-drilldown" href=${allhref} style=${'color: ' + colourFor(band) + ';'}>
                            ${i18n.card_open_drilldown}
                        </a>
                    </div>
                </div>
            `}
        </div>
    `;
}
