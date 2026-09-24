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
 * Tests for the ordered-thresholds admin setting and its use in settings.php.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\admin;

/**
 * The score bands are read from the highest cutoff down and the wait-time
 * buckets from the lowest up, so a threshold saved out of order silently
 * misclassifies the whole site. These tests pin what the setting accepts, and
 * that settings.php puts the three threshold settings behind it.
 *
 * @covers \block_feedback_tracker\local\admin\thresholds_setting
 */
final class thresholds_setting_test extends \advanced_testcase {
    /**
     * A score-band setting as settings.php builds it.
     *
     * @return thresholds_setting
     */
    private function band_setting(): thresholds_setting {
        return new thresholds_setting(
            'block_feedback_tracker/score_thresholds_band',
            'Bands',
            '',
            '90,70,40',
            thresholds_setting::DESCENDING,
            0.0,
            100.0
        );
    }

    /**
     * A wait-time bucket setting as settings.php builds it.
     *
     * @return thresholds_setting
     */
    private function bucket_setting(): thresholds_setting {
        return new thresholds_setting(
            'block_feedback_tracker/bucket_thresholds_eff',
            'Buckets',
            '',
            '24,48,120',
            thresholds_setting::ASCENDING
        );
    }

    /**
     * The shipped defaults and ordinary edits pass, spaces and decimals included.
     *
     * @return void
     */
    public function test_values_in_order_are_accepted(): void {
        $this->assertTrue($this->band_setting()->validate('90,70,40'));
        $this->assertTrue($this->band_setting()->validate('100, 80.5, 0'));
        $this->assertTrue($this->bucket_setting()->validate('24,48,120'));
        $this->assertTrue($this->bucket_setting()->validate('0, 1.5, 200'));
    }

    /**
     * Anything but three numbers is rejected, with the default as the example.
     *
     * @return void
     */
    public function test_anything_but_three_numbers_is_rejected(): void {
        $expected = get_string('settings_thresholds_error_format', 'block_feedback_tracker', '24,48,120');
        foreach (['', '24,48', '24,48,120,240', '24,x,120', '24,,120', '24;48;120'] as $value) {
            $this->assertSame($expected, $this->bucket_setting()->validate($value), "'{$value}'");
        }
    }

    /**
     * Each setting enforces its own direction, and equal neighbours are
     * rejected too, since they leave a band nothing can reach.
     *
     * @return void
     */
    public function test_order_is_enforced_in_each_direction(): void {
        $descending = get_string('settings_thresholds_error_descending', 'block_feedback_tracker');
        $ascending = get_string('settings_thresholds_error_ascending', 'block_feedback_tracker');

        $this->assertSame($descending, $this->band_setting()->validate('40,70,90'));
        $this->assertSame($descending, $this->band_setting()->validate('90,40,70'));
        $this->assertSame($descending, $this->band_setting()->validate('90,70,70'));

        $this->assertSame($ascending, $this->bucket_setting()->validate('120,48,24'));
        $this->assertSame($ascending, $this->bucket_setting()->validate('24,120,48'));
        $this->assertSame($ascending, $this->bucket_setting()->validate('24,24,120'));
    }

    /**
     * Score cutoffs stay inside 0-100, and bucket cutoffs cannot be negative.
     *
     * @return void
     */
    public function test_values_outside_the_range_are_rejected(): void {
        $range = get_string('settings_thresholds_error_range', 'block_feedback_tracker', (object) ['min' => '0', 'max' => '100']);
        $this->assertSame($range, $this->band_setting()->validate('120,70,40'));
        $this->assertSame($range, $this->band_setting()->validate('90,70,-5'));

        $min = get_string('settings_thresholds_error_min', 'block_feedback_tracker', '0');
        $this->assertSame($min, $this->bucket_setting()->validate('-1,48,120'));
        // Control: a bucket cutoff has no upper bound.
        $this->assertTrue($this->bucket_setting()->validate('24,48,1000'));
    }

    /**
     * A rejected value is not stored: the previous value stays in force.
     *
     * @return void
     */
    public function test_rejected_value_is_not_stored(): void {
        $this->resetAfterTest();
        set_config('score_thresholds_band', '85,70,50', 'block_feedback_tracker');

        $error = $this->band_setting()->write_setting('40,70,85');

        $this->assertNotSame('', $error);
        $this->assertSame('85,70,50', get_config('block_feedback_tracker', 'score_thresholds_band'));
        // Control: a valid value is written through the same path.
        $this->assertSame('', $this->band_setting()->write_setting('95,75,45'));
        $this->assertSame('95,75,45', get_config('block_feedback_tracker', 'score_thresholds_band'));
    }

    /**
     * settings.php puts the three threshold settings behind this class, each
     * in its own direction, and no longer offers the two settings nothing read.
     *
     * @return void
     */
    public function test_settings_page_uses_the_validating_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $root = new \admin_root(true);
        $root->add('root', new \admin_category('blocksettings', 'Blocks'));
        \core_plugin_manager::instance()->get_plugin_info('block_feedback_tracker')
            ->load_settings($root, 'blocksettings', true);
        $page = $root->locate('blocksettingfeedback_tracker');
        $this->assertInstanceOf(\admin_settingpage::class, $page);

        $byname = [];
        foreach ((array) $page->settings as $setting) {
            if ($setting->plugin === 'block_feedback_tracker') {
                $byname[$setting->name] = $setting;
            }
        }
        // Precondition: the page was built with its settings.
        $this->assertArrayHasKey('sla_goal_hours', $byname);

        foreach (['score_thresholds_band', 'bucket_thresholds_eff', 'bucket_thresholds_days'] as $name) {
            $this->assertInstanceOf(thresholds_setting::class, $byname[$name], $name);
        }
        $this->assertTrue($byname['score_thresholds_band']->validate('90,70,40'));
        $this->assertNotTrue($byname['score_thresholds_band']->validate('40,70,90'));
        $this->assertNotTrue($byname['score_thresholds_band']->validate('100,90,-1'));
        $this->assertTrue($byname['bucket_thresholds_eff']->validate('24,48,120'));
        $this->assertNotTrue($byname['bucket_thresholds_eff']->validate('120,48,24'));
        $this->assertTrue($byname['bucket_thresholds_days']->validate('2,5,10'));
        $this->assertNotTrue($byname['bucket_thresholds_days']->validate('10,5,2'));

        $this->assertArrayNotHasKey('bucket_thresholds_raw', $byname);
        $this->assertArrayNotHasKey('enable_school_comparison', $byname);
    }

    /**
     * The upgrade step removes the two settings nothing read and leaves the
     * others alone.
     *
     * @return void
     */
    public function test_upgrade_removes_the_unused_settings(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/blocks/feedback_tracker/db/upgrade.php');
        $this->resetAfterTest();

        set_config('bucket_thresholds_raw', '24,48,120', 'block_feedback_tracker');
        set_config('enable_school_comparison', '1', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');
        // The step runs for a site on the version before it.
        set_config('version', 2026092401, 'block_feedback_tracker');

        xmldb_block_feedback_tracker_upgrade(2026092401);

        $this->assertFalse(get_config('block_feedback_tracker', 'bucket_thresholds_raw'));
        $this->assertFalse(get_config('block_feedback_tracker', 'enable_school_comparison'));
        // Control: a setting that is read survives.
        $this->assertSame('24,48,120', get_config('block_feedback_tracker', 'bucket_thresholds_eff'));
    }
}
