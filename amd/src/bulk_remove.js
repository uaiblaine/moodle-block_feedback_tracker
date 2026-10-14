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
 * Every loaded candidate (up to course_finder::MAX_RESULTS) is already in the
 * DOM; this only uncollapses the next batch, so the ticks already made on a
 * destructive tool survive paging. The "showing N of M" counter keeps a
 * partly revealed list from reading as the complete one. "Select all" ticks
 * every loaded row and reveals the collapsed ones, so nothing is selected out
 * of sight; the list never holds more rows than one request may queue.
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
    all: '[data-bft-bulk-all]',
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
    const allel = root.querySelector(SELECTORS.all);
    const boxes = rows.map((r) => r.querySelector(SELECTORS.checkbox)).filter((box) => box);
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
     * The same check runs server-side in pages/bulk_remove.php, which also
     * explains why a count is asked for; this only spares the round trip.
     *
     * @returns {void}
     */
    const refreshSelection = () => {
        const selected = boxes.filter((box) => box.checked).length;
        if (allel) {
            allel.checked = selected > 0 && selected === boxes.length;
            allel.indeterminate = selected > 0 && selected < boxes.length;
        }
        if (countel) {
            countel.textContent = String(selected);
        }
        if (submitel) {
            const typed = confirmel ? parseInt(confirmel.value, 10) : NaN;
            submitel.disabled = selected === 0 || typed !== selected;
        }
    };

    const revealUpTo = (target) => {
        for (let i = visible; i < target; i++) {
            rows[i].classList.remove('bft-bulk-row-collapsed');
        }
        visible = Math.max(visible, target);
        refreshShown();
    };

    if (more) {
        more.addEventListener('click', (e) => {
            e.preventDefault();
            revealUpTo(Math.min(visible + pagesize, rows.length));
        });
    }

    if (allel) {
        allel.addEventListener('change', () => {
            boxes.forEach((box) => {
                box.checked = allel.checked;
            });
            if (allel.checked) {
                revealUpTo(rows.length);
            }
            refreshSelection();
        });
    }

    boxes.forEach((box) => box.addEventListener('change', refreshSelection));
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
