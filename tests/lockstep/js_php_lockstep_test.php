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
 * Drift detector for constants duplicated between PHP and the AMD bundle.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\lockstep;

use block_feedback_tracker\local\output\bootstrap;
use block_feedback_tracker\local\score\responsiveness_calculator;
use block_feedback_tracker\output\score_gauge;

/**
 * Several values exist twice, once in PHP and once in amd/src. Duplicated
 * constants drift silently — a colour changed on one side alone shows up as a
 * block and a dashboard disagreeing, with nothing failing.
 *
 * The JS is read from disk rather than mirrored into an expected array here.
 * A mirrored copy would be a third copy, free to drift like the other two;
 * reading the real file cannot. tests/external/services_coverage_test.php
 * already reads db/services.php the same way, so this is in-convention.
 *
 * @coversNothing
 */
final class js_php_lockstep_test extends \advanced_testcase {
    /**
     * Read a file from amd/src.
     *
     * @param string $relative Path below amd/src.
     * @return string
     */
    private function amd_source(string $relative): string {
        $path = __DIR__ . '/../../amd/src/' . $relative;
        $this->assertFileExists($path, "amd/src/{$relative} is missing");
        return (string) file_get_contents($path);
    }

    /**
     * The six band colours must be identical on both sides.
     *
     * @return void
     */
    public function test_band_colours_match_bands_js(): void {
        $js = $this->amd_source('lib/bands.js');

        $this->assertSame(
            1,
            preg_match('/BAND_COLOURS\s*=\s*\{(.*?)\}/s', $js, $block),
            'Could not find BAND_COLOURS in amd/src/lib/bands.js'
        );

        preg_match_all("/(\w+)\s*:\s*'(#[0-9a-fA-F]{3,8})'/", $block[1], $pairs, PREG_SET_ORDER);
        $jscolours = [];
        foreach ($pairs as $pair) {
            $jscolours[$pair[1]] = strtolower($pair[2]);
        }
        $this->assertNotEmpty($jscolours, 'No colours parsed out of bands.js');

        $phpcolours = array_map('strtolower', score_gauge::BAND_COLOURS);

        ksort($jscolours);
        ksort($phpcolours);
        $this->assertSame(
            $phpcolours,
            $jscolours,
            'score_gauge::BAND_COLOURS and amd/src/lib/bands.js::BAND_COLOURS have drifted apart.'
        );
    }

    /**
     * The default score thresholds match. The PHP side is not a constant: it
     * parses a setting with a fallback, so this compares the fallback that a
     * site with no stored value actually gets.
     *
     * @return void
     */
    public function test_default_score_thresholds_match_bands_js(): void {
        $this->resetAfterTest();
        unset_config('score_thresholds_band', 'block_feedback_tracker');

        $js = $this->amd_source('lib/bands.js');
        $this->assertSame(
            1,
            preg_match('/DEFAULT_SCORE_THRESHOLDS\s*=\s*\{(.*?)\}/s', $js, $block),
            'Could not find DEFAULT_SCORE_THRESHOLDS in amd/src/lib/bands.js'
        );
        preg_match_all('/(\w+)\s*:\s*([0-9.]+)/', $block[1], $pairs, PREG_SET_ORDER);
        $jsdefaults = [];
        foreach ($pairs as $pair) {
            $jsdefaults[$pair[1]] = (float) $pair[2];
        }

        [$excellent, $good, $regular] = responsiveness_calculator::parse_thresholds_band();

        $this->assertSame($jsdefaults['excellent'] ?? null, $excellent);
        $this->assertSame($jsdefaults['good'] ?? null, $good);
        $this->assertSame($jsdefaults['regular'] ?? null, $regular);
    }

    /**
     * The bundle ships the thresholds under exactly the keys bandForScore()
     * reads. A renamed key would leave every band computing as the fallback.
     *
     * @return void
     */
    public function test_config_bundle_keys_match_what_bandforscore_reads(): void {
        $this->resetAfterTest();

        $js = $this->amd_source('lib/bands.js');
        preg_match_all('/t\.(\w+)\s*\?\?/', $js, $reads);
        $jskeys = array_values(array_unique($reads[1] ?? []));
        sort($jskeys);

        $phpkeys = array_keys(bootstrap::config_bundle()['score_thresholds']);
        sort($phpkeys);

        $this->assertNotEmpty($jskeys, 'Could not determine which keys bandForScore() reads.');
        $this->assertSame($phpkeys, $jskeys);
    }

    /**
     * Every band slug used by the PHP side exists in the JS map, so a slug
     * added on one side alone is caught. The slugs are frozen identifiers —
     * relabelling a band is a lang-string change, never a slug change.
     *
     * @return void
     */
    public function test_band_slugs_are_the_same_set(): void {
        $js = $this->amd_source('lib/bands.js');
        preg_match('/BAND_COLOURS\s*=\s*\{(.*?)\}/s', $js, $block);
        preg_match_all("/(\w+)\s*:\s*'#/", $block[1], $slugs);

        $jsslugs = $slugs[1];
        sort($jsslugs);
        $phpslugs = array_keys(score_gauge::BAND_COLOURS);
        sort($phpslugs);

        $this->assertSame($phpslugs, $jsslugs);
    }
}
