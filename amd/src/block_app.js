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
 * Block entrypoint — mounts BlockView into every [data-bft-block-root] div
 * on the page.
 *
 * Each mount-point contains a <script type="application/json"
 * data-bft-init> payload built by block_feedback_tracker::get_content():
 * course id, calendar version, i18n and config bundles, and an empty
 * groups array. BlockView fetches the group cards after mount.
 *
 * @module    block_feedback_tracker/block_app
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {render, html} from 'block_feedback_tracker/lib/preact';
import BlockView from 'block_feedback_tracker/views/BlockView';
import {setGroupingSeparator, setDecimalSeparator, setDateContext} from 'block_feedback_tracker/lib/format';

/**
 * Pull the JSON payload embedded inside a mount-point root.
 *
 * @param {HTMLElement} root
 * @returns {object|null} Parsed payload or null if absent / unparseable.
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
        console.error('block_feedback_tracker: payload JSON parse failed', e);
        return null;
    }
};

/**
 * Initialise every block on the page. Idempotent — multiple calls are safe.
 */
export const init = () => {
    if (window.bftBlockAppInitDone) {
        return;
    }
    window.bftBlockAppInitDone = true;

    document.querySelectorAll('[data-bft-block-root]').forEach((root) => {
        const initial = readPayload(root);
        if (!initial) {
            return;
        }
        const config = initial.config || {};
        setGroupingSeparator(config.thousandssep);
        setDecimalSeparator(config.decsep);
        setDateContext(config.locale, config.timezone);
        render(html`<${BlockView} initial=${initial} />`, root);
    });
};
