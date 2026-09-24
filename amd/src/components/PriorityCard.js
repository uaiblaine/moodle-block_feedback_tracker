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
 * Priority card — used three-up on the dashboard for "Grade now · picked
 * for you" (the most urgent pending submissions across the dashboard's
 * courses).
 *
 * Takes one row of the get_grader_priority_list WS response.
 *
 * @module    block_feedback_tracker/components/PriorityCard
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import Badge from 'block_feedback_tracker/components/Badge';
import {formatHours, formatDays, usesDays} from 'block_feedback_tracker/lib/format';

/**
 * Initials from a full name; falls back to "??" on empty input.
 *
 * @param {string} name
 * @returns {string}
 */
const initialsOf = (name) => {
    if (!name) {
        return '??';
    }
    const parts = String(name).trim().split(/\s+/);
    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
};

/**
 * Perceived calendar wait as "Nd", from the server's date-based
 * perceived_days; never derive days from hours.
 *
 * @param {number|null|undefined} days
 * @returns {string}
 */
const perceivedDays = (days) => {
    const n = Number(days);
    return (Number.isFinite(n) && n >= 0 ? Math.round(n) : 0) + 'd';
};

/**
 * Label for a priority card's band badge. The list is pending work, so it
 * uses the Waiting / Attention / Priority strings of the group-card stat
 * tiles; the score-gauge words ("Excellent", "Good") stay on the gauge. The
 * band slug still drives the badge colour. Other slugs fall back to the
 * score band label.
 *
 * @param {string} band   SLA bucket slug (excellent|good|regular|critical|…).
 * @param {object} i18n   Label bundle.
 * @returns {string}
 */
const priorityLabel = (band, i18n) => {
    switch (band) {
        case 'critical': return i18n.card_critical || '';
        case 'regular': return i18n.card_overgoal || '';
        case 'good':
        case 'excellent': return i18n.card_pending || '';
        default: return (i18n.bands || {})[band] || '';
    }
};

/**
 * @param {object} props
 * @param {number} props.idx          1-based rank shown in the header.
 * @param {object} props.submission   Row from get_grader_priority_list.
 * @param {object} props.i18n         Label bundle.
 * @param {object} [props.config]     Config bundle (effective-time display unit).
 * @returns {object} vnode
 */
export default function PriorityCard({idx, submission, i18n, config}) {
    const band = submission.slabucket || 'pending';
    const bandLabel = priorityLabel(band, i18n);
    const studentname = submission.studentname || '';
    const initials = initialsOf(studentname);
    const eff = Number(submission.effectivehours) || 0;
    const cmid = Number(submission.cmid) || 0;
    const userid = Number(submission.userid) || 0;

    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    // Deep-link straight to the single-student grader (action=grader&userid),
    // not the generic grading list (action=grading), so the teacher lands on
    // the grade form for this exact submission in one click.
    const gradeUrl = cmid > 0 && userid > 0
        ? wwwroot + '/mod/assign/view.php?id=' + encodeURIComponent(String(cmid))
            + '&action=grader&userid=' + encodeURIComponent(String(userid))
        : '#';

    return html`
        <article class="bft-priority-card">
            <header class="bft-priority-header">
                <span class="bft-priority-idx">#${idx}</span>
                <${Badge} band=${band} label=${bandLabel} />
            </header>
            <div class="bft-priority-title">${submission.activityname || ''}</div>
            <div class="bft-priority-course">${(submission.coursename || '').toUpperCase()}</div>
            <div class="bft-priority-student">
                <span class="bft-priority-avatar" aria-hidden="true">${initials}</span>
                <span class="bft-priority-name">${studentname}</span>
            </div>
            <div class="bft-priority-waits">
                <div class="bft-priority-wait">
                    <div class="bft-priority-wait-label">
                        ${i18n.pendingreport_col_effective || 'Effective'}
                    </div>
                    <div class=${'bft-priority-wait-value bft-mono bft-overall-score-tone-' + band}>
                        ${usesDays(config)
                            ? formatDays(submission.effective_days)
                            : formatHours(eff)}
                    </div>
                </div>
                <div class="bft-priority-wait">
                    <div class="bft-priority-wait-label">
                        ${i18n.pendingreport_col_perceived || 'Perceived'}
                    </div>
                    <div class="bft-priority-wait-value bft-priority-wait-muted bft-mono">
                        ${perceivedDays(submission.perceived_days)}
                    </div>
                </div>
            </div>
            <a class="bft-priority-cta" href=${gradeUrl}>
                ${i18n.priority_open || 'Open submission'}
            </a>
        </article>
    `;
}
