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
        // The installed version as such a site has it, or every savepoint reads as a downgrade.
        set_config('version', 2026041999, 'block_feedback_tracker');

        $this->assertTrue(xmldb_block_feedback_tracker_upgrade(2026041999));
    }

    /**
     * The 2026042005 step adds the attribution label to the ledger on a site
     * that lacks it; the steps after it run too, so the last savepoint is recorded.
     *
     * @return void
     */
    public function test_the_attribution_step_adds_the_column(): void {
        global $DB;
        $this->load_upgrade_lib();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('block_feedback_tracker_sub');
        $field = new \xmldb_field('allocattrib', XMLDB_TYPE_CHAR, '12', null, null, null, null, 'allocsource');
        $dbman->drop_field($table, $field);
        $this->assertFalse($dbman->field_exists($table, $field), 'Control: the column is gone.');
        set_config('version', 2026042004, 'block_feedback_tracker');

        $this->assertTrue(xmldb_block_feedback_tracker_upgrade(2026042004));

        $this->assertTrue($dbman->field_exists($table, $field));
        $this->assertSame('2026042014', (string) get_config('block_feedback_tracker', 'version'));
    }

    /**
     * The 2026042006 step creates the per-marker slot table on a site that
     * lacks it, with its unique index, and the steps after it run too; on a site
     * that has it (as the 5.3 branch's own step finds it) it changes nothing.
     *
     * @return void
     */
    public function test_the_slot_table_step_creates_the_table(): void {
        global $DB;
        $this->load_upgrade_lib();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('block_feedback_tracker_alloc');
        $dbman->drop_table($table);
        $this->assertFalse($dbman->table_exists($table), 'Control: the table is gone.');
        set_config('version', 2026042005, 'block_feedback_tracker');

        $this->assertTrue(xmldb_block_feedback_tracker_upgrade(2026042005));

        $this->assertTrue($dbman->table_exists($table));
        $this->assertTrue($dbman->index_exists($table, new \xmldb_index('uq_sub_marker', XMLDB_INDEX_UNIQUE, ['subid', 'marker'])));
        $this->assertSame('2026042014', (string) get_config('block_feedback_tracker', 'version'));

        set_config('version', 2026042005, 'block_feedback_tracker');
        $this->assertTrue(xmldb_block_feedback_tracker_upgrade(2026042005), 'An existing table is left as it is.');
    }

    /**
     * The 2026042014 step removes the cursor of the reconciler's rule sweep,
     * which no code reads any more, and leaves the other sweeps' cursors.
     *
     * @return void
     */
    public function test_the_rule_cursor_step_removes_the_cursor(): void {
        $this->load_upgrade_lib();
        $this->resetAfterTest();
        set_config('reconcile_cursor_rules', '1234', 'block_feedback_tracker');
        set_config('reconcile_cursor_latest', '5678', 'block_feedback_tracker');
        set_config('version', 2026042013, 'block_feedback_tracker');

        $this->assertTrue(xmldb_block_feedback_tracker_upgrade(2026042013));

        $this->assertFalse(get_config('block_feedback_tracker', 'reconcile_cursor_rules'));
        $this->assertSame('5678', get_config('block_feedback_tracker', 'reconcile_cursor_latest'), 'Control.');
        $this->assertSame('2026042014', (string) get_config('block_feedback_tracker', 'version'));
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
