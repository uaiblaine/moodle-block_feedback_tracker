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
 * Tests for the upgrade function.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\db;

/**
 * The upgrade from 1.1.0 into per-Moodle-version numbering, and the rule
 * that keeps savepoints at or below version.php.
 *
 * @covers ::xmldb_block_feedback_tracker_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * A site that reached 1.1.0 and set its version below this branch's
     * upgrades without complaint.
     *
     * @return void
     */
    public function test_a_site_that_reached_1_1_0_upgrades(): void {
        $this->load_upgrade_lib();
        $this->resetAfterTest();

        $this->assertTrue(xmldb_block_feedback_tracker_upgrade(2026041999));
    }

    /**
     * A site whose schema predates 1.1.0 is refused, whatever version it was
     * given by hand: the steps that would bring it up to date are gone.
     *
     * @return void
     */
    public function test_a_site_that_skipped_1_1_0_is_refused(): void {
        global $DB;
        $this->load_upgrade_lib();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('block_feedback_tracker_sub');
        $field = new \xmldb_field('timedismissed', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null, 'closedsource');
        $dbman->drop_field($table, $field);
        try {
            $this->expectException(\upgrade_exception::class);
            xmldb_block_feedback_tracker_upgrade(2026041999);
        } finally {
            $dbman->add_field($table, $field);
        }
    }

    /**
     * No upgrade step records a version above version.php's. The next upgrade
     * of a site running such a tree records it, and the site then reads
     * version.php as a downgrade and stops. A branch may hold no step at all.
     *
     * @return void
     */
    public function test_no_savepoint_is_ahead_of_version_php(): void {
        global $CFG;
        $pattern = '/upgrade_block_savepoint\(\s*true,\s*(\d{10}),/';
        // Control: the pattern reads a savepoint written the way the steps write it.
        $this->assertSame(1, preg_match($pattern, "upgrade_block_savepoint(true, 2026042001, 'feedback_tracker');"));

        $source = file_get_contents($CFG->dirroot . '/blocks/feedback_tracker/db/upgrade.php');
        preg_match_all($pattern, $source, $matches);
        $versiondisk = (int) \core_plugin_manager::instance()->get_plugin_info('block_feedback_tracker')->versiondisk;
        foreach (array_map('intval', $matches[1]) as $savepoint) {
            $this->assertLessThanOrEqual($versiondisk, $savepoint);
        }
    }

    /**
     * Load the upgrade library and this plugin's upgrade function.
     *
     * @return void
     */
    private function load_upgrade_lib(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/blocks/feedback_tracker/db/upgrade.php');
    }
}
