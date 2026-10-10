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
 * Single import surface for the vendored Preact + htm bundle.
 *
 * The bundle at js/vendor/bft-vendor-*.min.js sets `window.bftPreact`,
 * `window.bftPreactHooks`, and `window.bftHtm`. This module is the only
 * place those globals are read; everything else in the plugin imports
 * `h`, `render`, `html`, hooks, etc. from here, so moving to another
 * implementation (e.g. Moodle 5.2's native React) changes the imports in
 * this file only.
 *
 * @module    block_feedback_tracker/lib/preact
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Read a vendored global, throwing if the bundle never loaded.
 *
 * @param {string} name  The bft-prefixed global name.
 * @returns {object}
 */
const need = (name) => {
    if (typeof window === 'undefined' || !window[name]) {
        throw new Error('block_feedback_tracker: ' + name + ' not loaded — '
            + 'is js/vendor/bft-vendor-*.min.js included before any AMD module?');
    }
    return window[name];
};

const preact = need('bftPreact');
const hooks = need('bftPreactHooks');
const htm = need('bftHtm');

export const h = preact.h;
export const render = preact.render;
export const Fragment = preact.Fragment;
export const createContext = preact.createContext;
export const Component = preact.Component;

export const useState = hooks.useState;
export const useEffect = hooks.useEffect;
export const useReducer = hooks.useReducer;
export const useMemo = hooks.useMemo;
export const useRef = hooks.useRef;
export const useCallback = hooks.useCallback;
export const useContext = hooks.useContext;
export const useId = hooks.useId;

export const html = htm.bind(preact.h);
