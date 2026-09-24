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
 * Teacher dashboard entrypoint — mounts DashboardView into the single
 * [data-bft-dashboard-root] div on pages/teacher_dashboard.php.
 *
 * @module    block_feedback_tracker/dashboard_app
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {render, html} from 'block_feedback_tracker/lib/preact';
import DashboardView from 'block_feedback_tracker/views/DashboardView';
import {setGroupingSeparator, setDecimalSeparator, setDateContext} from 'block_feedback_tracker/lib/format';

/**
 * Pull the JSON payload embedded inside the mount-point root.
 *
 * @param {HTMLElement} root
 * @returns {object|null}
 */
const readPayload = (root) => {
    const tag = root.querySelector('script[type="application/json"][data-bft-init]');
    if (!tag) {
        return null;
    }
    try {
        return JSON.parse(tag.textContent || '{}');
    } catch (e) {
        // eslint-disable-next-line no-console
        console.error('block_feedback_tracker: dashboard payload parse failed', e);
        return null;
    }
};

/**
 * Initialise. Idempotent.
 */
export const init = () => {
    if (window.bftDashboardInitDone) {
        return;
    }
    window.bftDashboardInitDone = true;

    document.querySelectorAll('[data-bft-dashboard-root]').forEach((root) => {
        const initial = readPayload(root);
        if (!initial) {
            return;
        }
        const config = initial.config || {};
        setGroupingSeparator(config.thousandssep);
        setDecimalSeparator(config.decsep);
        setDateContext(config.locale, config.timezone);
        render(html`<${DashboardView} initial=${initial} />`, root);
    });
};
