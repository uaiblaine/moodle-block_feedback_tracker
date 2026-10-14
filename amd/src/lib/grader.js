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
 * The one builder of grade links, so every surface sends a teacher to the
 * same student.
 *
 * mod_assign's grader opens the first student of its filtered list when the
 * linked one is filtered out. A link therefore goes through
 * pages/open_grader.php, which clears what hides the student, whenever the
 * viewer may carry such state (`config.grader_gate`, from
 * local\grading\grader_filters::viewer_has_saved_state()) or the row says the
 * grader starts on one of the viewer's groups (`gradergate`). Every other link
 * goes straight to the grader and costs nothing extra.
 *
 * @module    block_feedback_tracker/lib/grader
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Grade link for one submission row.
 *
 * @param {object} row Row with cmid, userid and gradergate.
 * @param {object} [config] Config bundle (grader_gate).
 * @returns {string} The URL, or '#' when the row names no activity or student.
 */
export const graderUrl = (row, config = {}) => {
    const cmid = Number(row && row.cmid) || 0;
    const userid = Number(row && row.userid) || 0;
    if (cmid <= 0 || userid <= 0) {
        return '#';
    }
    const cfg = (typeof M !== 'undefined' && M.cfg) || {};
    const wwwroot = cfg.wwwroot || '';
    const query = 'id=' + encodeURIComponent(String(cmid)) + '&userid=' + encodeURIComponent(String(userid));
    if ((config && config.grader_gate) || Number(row.gradergate) === 1) {
        return wwwroot + '/blocks/feedback_tracker/pages/open_grader.php?' + query
            + '&sesskey=' + encodeURIComponent(String(cfg.sesskey || ''));
    }
    return wwwroot + '/mod/assign/view.php?' + query + '&action=grader';
};
