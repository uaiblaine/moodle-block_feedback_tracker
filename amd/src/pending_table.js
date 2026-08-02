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
 * Block Feedback Flow — client-side sorting for the pending-submissions
 * table on the group drilldown page.
 *
 * Any <table class="bft-sortable"> in the page is enhanced: clicking a
 * <th data-sort="..."> reorders the <tbody> rows by that column. Cells may
 * carry data-sort-value to provide a numeric / sortable key independent of
 * the formatted display.
 *
 * @module    block_feedback_tracker/pending_table
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Pull the comparable key from a cell: data-sort-value first, textContent as
 * the fallback.
 *
 * @param {HTMLTableCellElement} cell
 * @returns {string}
 */
const cellKey = (cell) => {
    if (!cell) {
        return '';
    }
    return cell.dataset.sortValue ?? cell.textContent.trim();
};

/**
 * Numeric-aware comparator. Pure-numeric strings sort numerically; mixed
 * strings sort lexicographically.
 *
 * @param {string} a
 * @param {string} b
 * @returns {number}
 */
const compareKeys = (a, b) => {
    const an = parseFloat(a);
    const bn = parseFloat(b);
    if (!Number.isNaN(an) && !Number.isNaN(bn) && String(an) === a && String(bn) === b) {
        return an - bn;
    }
    if (!Number.isNaN(an) && !Number.isNaN(bn)) {
        return an - bn;
    }
    return a.localeCompare(b);
};

/**
 * Enhance one table.
 *
 * @param {HTMLTableElement} table
 */
const enhanceTable = (table) => {
    const headers = table.querySelectorAll('thead th[data-sort]');
    headers.forEach((th) => {
        // The listener goes on the button, not the cell: a button is
        // focusable and fires on Enter and Space for free, which a th does
        // not. Sorting used to be reachable by mouse only.
        const trigger = th.querySelector('.bft-th-sortable-btn') || th;
        trigger.addEventListener('click', () => {
            const colIdx = Array.from(th.parentElement.children).indexOf(th);
            const isAsc = !th.classList.contains('bft-sort-asc');
            th.parentElement.querySelectorAll('th').forEach((x) => {
                x.classList.remove('bft-sort-asc', 'bft-sort-desc');
                if (x.hasAttribute('aria-sort')) {
                    x.setAttribute('aria-sort', 'none');
                }
            });
            th.classList.add(isAsc ? 'bft-sort-asc' : 'bft-sort-desc');
            // Announce the active column and direction to assistive tech.
            th.setAttribute('aria-sort', isAsc ? 'ascending' : 'descending');

            const tbody = table.tBodies[0];
            if (!tbody) {
                return;
            }
            const rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort((ra, rb) => {
                const cmp = compareKeys(cellKey(ra.cells[colIdx]), cellKey(rb.cells[colIdx]));
                return isAsc ? cmp : -cmp;
            });
            rows.forEach((r) => tbody.appendChild(r));
        });
    });
};

/**
 * Initialise sortable tables in the current page.
 */
export const init = () => {
    // Without this guard a second init binds a second listener to every
    // header, so one click sorts ascending then immediately re-sorts
    // descending and the indicator no longer matches the row order.
    if (window.bftPendingTableInitDone) {
        return;
    }
    window.bftPendingTableInitDone = true;
    document.querySelectorAll('table.bft-sortable').forEach(enhanceTable);
};
