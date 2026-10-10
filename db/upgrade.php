<?php
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
 * Upgrade steps for Feedback Flow.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade steps for block_feedback_tracker.
 *
 * Versions are numbered in the range of the Moodle version a branch requires
 * (5.2: 20260420XX, 5.1: 20251006XX), so a release for one Moodle version
 * never carries the number of another. The steps of 1.0.0 to 1.1.0 were
 * numbered by date, above every such range: a site that lowered its version
 * to switch numbering would have run them all again, so they were removed.
 * 1.1.0 (2026100902) is the last date-numbered release and the one every
 * existing site switches from; CHANGELOG.md, under 1.2.0, gives the steps.
 *
 * A step added from now on is numbered inside its own branch's range and
 * goes below the schema check.
 *
 * @param int $oldversion The currently-installed plugin version code.
 * @return bool True on success.
 * @throws upgrade_exception When the site never reached 1.1.0.
 */
function xmldb_block_feedback_tracker_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager(); // Loads ddl manager and xmldb classes.

    /* Whether the site went through 1.1.0 is read from the schema, not from
     * $oldversion: switching numbering sets the version by hand, so it says
     * nothing about which steps ran. 1.1.0's last step added this column. */
    $table = new xmldb_table('block_feedback_tracker_sub');
    if (!$dbman->field_exists($table, new xmldb_field('timedismissed'))) {
        throw new upgrade_exception(
            'block_feedback_tracker',
            $oldversion,
            'Upgrade to Feedback Flow 1.1.0 (version 2026100902) first, which '
                . 'runs every earlier upgrade step. Then set the version to the '
                . 'number CHANGELOG.md gives for this branch with '
                . 'admin/cli/cfg.php --component=block_feedback_tracker '
                . '--name=version --set=<number>, and install this release.'
        );
    }

    return true;
}
