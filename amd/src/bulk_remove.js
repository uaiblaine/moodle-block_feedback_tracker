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
 * Progressive reveal for the bulk block-removal list.
 *
 * Every candidate is already in the DOM; this only uncollapses the next batch.
 * Fetching more rows per click would lose the ticks an administrator has
 * already made, which on a destructive tool is the difference between a
 * considered selection and a re-done one. The counter is the point of the
 * exercise — "showing 25 of 340" is what stops a truncated list reading as a
 * complete one.
 *
 * @module     block_feedback_tracker/bulk_remove
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    root: '[data-bft-bulk-root]',
    row: '[data-bft-bulk-row]',
    more: '[data-bft-bulk-more]',
    shown: '[data-bft-bulk-shown]',
    checkbox: '[data-bft-bulk-check]',
    count: '[data-bft-bulk-selected]',
    confirm: '[data-bft-bulk-confirm]',
    submit: '[data-bft-bulk-submit]',
};

/**
 * Wire one list root.
 *
 * @param {HTMLElement} root The container element.
 * @returns {void}
 */
const wire = (root) => {
    const rows = Array.from(root.querySelectorAll(SELECTORS.row));
    const more = root.querySelector(SELECTORS.more);
    const shownel = root.querySelector(SELECTORS.shown);
    const countel = root.querySelector(SELECTORS.count);
    const confirmel = root.querySelector(SELECTORS.confirm);
    const submitel = root.querySelector(SELECTORS.submit);
    const pagesize = parseInt(root.dataset.bftBulkPagesize, 10) || 25;

    let visible = rows.filter((r) => !r.classList.contains('bft-bulk-row-collapsed')).length;

    const refreshShown = () => {
        if (shownel) {
            shownel.textContent = String(visible);
        }
        if (more && visible >= rows.length) {
            more.classList.add('bft-hidden');
        }
    };

    /**
     * Keep the submit gated on the typed number matching the ticks.
     *
     * The same check runs server-side; this only spares the round trip. A
     * count has to be read to be typed, which a fixed confirmation word does
     * not, and it goes stale the moment the selection changes.
     *
     * @returns {void}
     */
    const refreshSelection = () => {
        const selected = rows.filter((r) => {
            const box = r.querySelector(SELECTORS.checkbox);
            return box && box.checked;
        }).length;
        if (countel) {
            countel.textContent = String(selected);
        }
        if (submitel) {
            const typed = confirmel ? parseInt(confirmel.value, 10) : NaN;
            submitel.disabled = selected === 0 || typed !== selected;
        }
    };

    if (more) {
        more.addEventListener('click', (e) => {
            e.preventDefault();
            const target = Math.min(visible + pagesize, rows.length);
            for (let i = visible; i < target; i++) {
                rows[i].classList.remove('bft-bulk-row-collapsed');
            }
            visible = target;
            refreshShown();
        });
    }

    rows.forEach((r) => {
        const box = r.querySelector(SELECTORS.checkbox);
        if (box) {
            box.addEventListener('change', refreshSelection);
        }
    });
    if (confirmel) {
        confirmel.addEventListener('input', refreshSelection);
    }

    refreshShown();
    refreshSelection();
};

/**
 * Entry point.
 *
 * @returns {void}
 */
export const init = () => {
    if (window.bftBulkRemoveInitDone) {
        return;
    }
    window.bftBulkRemoveInitDone = true;
    document.querySelectorAll(SELECTORS.root).forEach(wire);
};
