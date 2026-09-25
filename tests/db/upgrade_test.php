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
 * Tests for the upgrade steps.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\db;

/**
 * Upgrade steps whose effect is data rather than schema.
 *
 * @covers ::xmldb_block_feedback_tracker_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * The 2026092402 step queues every stored rollup for a recompute, because
     * the figures it stores changed meaning in that version.
     *
     * @return void
     */
    public function test_every_rollup_is_queued_for_recompute(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/blocks/feedback_tracker/db/upgrade.php');
        $this->resetAfterTest();

        $tuples = [[1101, 0], [1101, 7], [1102, 0]];
        foreach ($tuples as [$courseid, $groupid]) {
            $DB->insert_record('block_feedback_tracker_group', (object) [
                'courseid' => $courseid,
                'groupid' => $groupid,
                'timemodified' => time(),
            ]);
        }
        // Precondition: nothing is queued before the step runs.
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_queue'));
        // The step runs for a site on the version before it.
        set_config('version', 2026092401, 'block_feedback_tracker');

        xmldb_block_feedback_tracker_upgrade(2026092401);

        $queued = [];
        foreach ($DB->get_records('block_feedback_tracker_queue') as $row) {
            $queued[] = [(int) $row->courseid, (int) $row->groupid];
        }
        sort($queued);
        $this->assertSame($tuples, $queued);
        // The step reached its savepoint (a later step may have moved the version on).
        $this->assertGreaterThanOrEqual(2026092402, (int) get_config('block_feedback_tracker', 'version'));
    }

    /**
     * The 2026092500 step resets a stored cutoff triple the settings page
     * refuses to its shipped default, then does what the settings' updated
     * callback does after a save: a new calendar version and a recompute of
     * every rollup. A valid triple, the control, stays as stored.
     *
     * @return void
     */
    public function test_refused_thresholds_are_reset_and_the_rollups_recomputed(): void {
        global $DB;
        $this->load_upgrade_lib();
        $this->resetAfterTest();

        $DB->insert_record('block_feedback_tracker_group', (object) [
            'courseid' => 1101,
            'groupid' => 7,
            'timemodified' => time(),
        ]);
        set_config('score_thresholds_band', '70,90,40', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '48,24,120', 'block_feedback_tracker');
        set_config('bucket_thresholds_days', '2,5,10', 'block_feedback_tracker');
        set_config('calver', '5', 'block_feedback_tracker');
        // Precondition: nothing is queued before the step runs.
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_queue'));
        set_config('version', 2026092402, 'block_feedback_tracker');

        xmldb_block_feedback_tracker_upgrade(2026092402);

        $this->assertSame('90,70,40', get_config('block_feedback_tracker', 'score_thresholds_band'));
        $this->assertSame('24,48,120', get_config('block_feedback_tracker', 'bucket_thresholds_eff'));
        $this->assertSame('2,5,10', get_config('block_feedback_tracker', 'bucket_thresholds_days'));
        $this->assertSame('6', get_config('block_feedback_tracker', 'calver'));
        $queued = [];
        foreach ($DB->get_records('block_feedback_tracker_queue') as $row) {
            $queued[] = [(int) $row->courseid, (int) $row->groupid];
        }
        $this->assertSame([[1101, 7]], $queued);
        $this->assertEquals(2026092500, get_config('block_feedback_tracker', 'version'));
    }

    /**
     * With every triple valid or never saved, the 2026092500 step writes
     * nothing: no setting, no calendar version, no recompute.
     *
     * @return void
     */
    public function test_valid_thresholds_are_left_alone(): void {
        global $DB;
        $this->load_upgrade_lib();
        $this->resetAfterTest();

        $DB->insert_record('block_feedback_tracker_group', (object) [
            'courseid' => 1101,
            'groupid' => 7,
            'timemodified' => time(),
        ]);
        set_config('score_thresholds_band', '95, 80, 50', 'block_feedback_tracker');
        unset_config('bucket_thresholds_eff', 'block_feedback_tracker');
        set_config('bucket_thresholds_days', '0,1,2', 'block_feedback_tracker');
        set_config('calver', '5', 'block_feedback_tracker');
        set_config('version', 2026092402, 'block_feedback_tracker');

        xmldb_block_feedback_tracker_upgrade(2026092402);

        $this->assertSame('95, 80, 50', get_config('block_feedback_tracker', 'score_thresholds_band'));
        $this->assertFalse(get_config('block_feedback_tracker', 'bucket_thresholds_eff'));
        $this->assertSame('0,1,2', get_config('block_feedback_tracker', 'bucket_thresholds_days'));
        $this->assertSame('5', get_config('block_feedback_tracker', 'calver'));
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_queue'));
        $this->assertEquals(2026092500, get_config('block_feedback_tracker', 'version'));
    }

    /**
     * The 2026092500 step's rules, one stored value at a time. The verdicts
     * are those thresholds_setting::validate() gives at that version
     * (thresholds_setting_test samples the same values); they are written out
     * here because the step must keep them when the setting's rules change.
     *
     * @return void
     */
    public function test_the_threshold_repair_keeps_the_settings_rules(): void {
        $this->load_upgrade_lib();
        $this->resetAfterTest();
        $defaults = [
            'bucket_thresholds_days' => '2,5,10',
            'bucket_thresholds_eff' => '24,48,120',
            'score_thresholds_band' => '90,70,40',
        ];
        // Setting, stored value, whether the step keeps it.
        $samples = [
            ['score_thresholds_band', '95, 80, 50', true],
            ['score_thresholds_band', '100,50,0', true],
            ['score_thresholds_band', '90,70,70', false],
            ['score_thresholds_band', '110,70,40', false],
            ['score_thresholds_band', '90,70,-1', false],
            ['score_thresholds_band', '90,70', false],
            ['score_thresholds_band', '90,70,40,10', false],
            ['score_thresholds_band', 'a,b,c', false],
            ['score_thresholds_band', '', false],
            ['bucket_thresholds_eff', '1,2,3', true],
            ['bucket_thresholds_eff', '0.5,1,500', true],
            ['bucket_thresholds_eff', '24,24,120', false],
            ['bucket_thresholds_eff', '-1,48,120', false],
            ['bucket_thresholds_eff', '24;48;120', false],
            ['bucket_thresholds_days', '0,1,2', true],
            ['bucket_thresholds_days', '10,5,2', false],
            ['bucket_thresholds_days', '2,5,x', false],
        ];

        foreach ($samples as [$name, $stored, $kept]) {
            foreach ($defaults as $other => $default) {
                set_config($other, $default, 'block_feedback_tracker');
            }
            set_config($name, $stored, 'block_feedback_tracker');
            set_config('version', 2026092402, 'block_feedback_tracker');

            xmldb_block_feedback_tracker_upgrade(2026092402);

            $this->assertSame($kept ? $stored : $defaults[$name], get_config('block_feedback_tracker', $name), "$name '$stored'.");
        }
    }

    /**
     * No upgrade step records a version above version.php's. The next upgrade
     * of a site running such a tree records it, and the site then reads
     * version.php as a downgrade and stops.
     *
     * @return void
     */
    public function test_no_savepoint_is_ahead_of_version_php(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/blocks/feedback_tracker/db/upgrade.php');
        preg_match_all('/upgrade_block_savepoint\(\s*true,\s*(\d{10}),/', $source, $matches);
        $savepoints = array_map('intval', $matches[1]);
        // Control: the pattern finds the file's savepoints.
        $this->assertGreaterThan(20, count($savepoints));

        $versiondisk = (int) \core_plugin_manager::instance()->get_plugin_info('block_feedback_tracker')->versiondisk;
        $this->assertLessThanOrEqual($versiondisk, max($savepoints));
    }

    /**
     * Load the upgrade function and the savepoint helpers it calls.
     *
     * @return void
     */
    private function load_upgrade_lib(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/blocks/feedback_tracker/db/upgrade.php');
    }
}
