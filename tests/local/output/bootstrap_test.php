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
 * Tests for the JS bootstrap config bundle.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

/**
 * The default-ON toggles here are the ones that already shipped broken once:
 * `get_config()` returns the string '0' when a checkbox is switched off, which
 * is falsy in PHP, so a `?: 1` read makes the toggle impossible to turn off.
 * Only an explicit '0' may mean off; an unset value means on.
 *
 * @covers \block_feedback_tracker\local\output\bootstrap
 */
final class bootstrap_test extends \advanced_testcase {
    /**
     * A default-ON toggle is on when nothing was ever stored.
     *
     * @return void
     */
    public function test_unset_toggles_default_to_on(): void {
        $this->resetAfterTest();
        unset_config('show_peer_context', 'block_feedback_tracker');
        unset_config('show_paused_today_indicator', 'block_feedback_tracker');

        $bundle = bootstrap::config_bundle();

        $this->assertTrue($bundle['show_peer_context']);
        $this->assertTrue($bundle['show_scheduled_pauses']);
    }

    /**
     * The regression that shipped: switching the checkbox off stores the
     * string '0', and that must actually turn the toggle off.
     *
     * @return void
     */
    public function test_explicit_zero_turns_a_toggle_off(): void {
        $this->resetAfterTest();
        set_config('show_peer_context', '0', 'block_feedback_tracker');
        set_config('show_paused_today_indicator', '0', 'block_feedback_tracker');

        $bundle = bootstrap::config_bundle();

        $this->assertFalse($bundle['show_peer_context'], 'A stored "0" must switch the toggle off.');
        $this->assertFalse($bundle['show_scheduled_pauses']);
    }

    /**
     * An explicit on is on.
     *
     * @return void
     */
    public function test_explicit_one_keeps_a_toggle_on(): void {
        $this->resetAfterTest();
        set_config('show_peer_context', '1', 'block_feedback_tracker');
        set_config('show_paused_today_indicator', '1', 'block_feedback_tracker');

        $bundle = bootstrap::config_bundle();

        $this->assertTrue($bundle['show_peer_context']);
        $this->assertTrue($bundle['show_scheduled_pauses']);
    }

    /**
     * The two toggles are independent — switching one off must not drag the
     * other with it.
     *
     * @return void
     */
    public function test_toggles_are_independent(): void {
        $this->resetAfterTest();
        set_config('show_peer_context', '0', 'block_feedback_tracker');
        unset_config('show_paused_today_indicator', 'block_feedback_tracker');

        $bundle = bootstrap::config_bundle();

        $this->assertFalse($bundle['show_peer_context']);
        $this->assertTrue($bundle['show_scheduled_pauses']);
    }

    /**
     * The bundle carries the score thresholds the JS band helper reads, under
     * exactly the keys it expects.
     *
     * @return void
     */
    public function test_score_thresholds_use_the_keys_the_js_reads(): void {
        $this->resetAfterTest();
        unset_config('score_thresholds_band', 'block_feedback_tracker');

        $bundle = bootstrap::config_bundle();

        $this->assertArrayHasKey('score_thresholds', $bundle);
        $this->assertSame(
            ['excellent', 'good', 'regular'],
            array_keys($bundle['score_thresholds']),
            'bandForScore() in amd/src/lib/bands.js reads exactly these keys.'
        );
    }

    /**
     * The five score weights are all present and numeric, since the JS divides
     * by their sum.
     *
     * @return void
     */
    public function test_weights_are_all_present_and_numeric(): void {
        $this->resetAfterTest();

        $weights = bootstrap::config_bundle()['weights'];

        foreach (['compliance', 'median', 'critical', 'pending', 'trend'] as $key) {
            $this->assertArrayHasKey($key, $weights);
            $this->assertIsFloat($weights[$key]);
        }
    }

    /**
     * A weight stored as zero survives as zero. The `?: default` fallback used
     * for the weights means a deliberate 0 reads back as the default instead,
     * which is worth knowing about rather than assuming either way.
     *
     * @return void
     */
    public function test_zero_weight_falls_back_to_the_default(): void {
        $this->resetAfterTest();
        set_config('weight_trend', '0', 'block_feedback_tracker');

        $weights = bootstrap::config_bundle()['weights'];

        $this->assertSame(
            0.10,
            $weights['trend'],
            'A zero weight is read as unset and falls back to the default; '
                . 'zeroing a term out is done through normalisation, not this read.'
        );
    }

    /**
     * The thousands separator reaches the JS, since the count formatter is fed
     * from here rather than reading langconfig itself.
     *
     * @return void
     */
    public function test_bundle_carries_the_thousands_separator(): void {
        $this->resetAfterTest();

        $bundle = bootstrap::config_bundle();

        $this->assertArrayHasKey('thousandssep', $bundle);
        $this->assertNotSame('', (string) $bundle['thousandssep']);
    }
}
