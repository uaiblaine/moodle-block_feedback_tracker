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
 * Drift detector for values shared between PHP and the AMD bundle.
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

/**
 * Several values live on both sides, once in PHP and once in amd/src: the
 * default score thresholds, the keys the band classifier reads from the
 * config bundle, and the band slugs. Shared values drift silently — a slug
 * added on one side alone shows up as a card with no colour or no label,
 * with nothing failing.
 *
 * The JS is read from disk rather than mirrored into an expected array here:
 * a mirrored copy would be a third copy, free to drift like the other two.
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
     * The band slugs keyed in amd/src/lib/bands.js BAND_COLOURS, sorted.
     *
     * @return string[]
     */
    private function js_band_slugs(): array {
        $js = $this->amd_source('lib/bands.js');
        $this->assertSame(
            1,
            preg_match('/BAND_COLOURS\s*=\s*\{(.*?)\}/s', $js, $block),
            'Could not find BAND_COLOURS in amd/src/lib/bands.js'
        );
        preg_match_all("/(\w+)\s*:\s*'#/", $block[1], $slugs);
        $jsslugs = $slugs[1];
        sort($jsslugs);
        $this->assertNotEmpty($jsslugs, 'No band slugs parsed out of bands.js');
        return $jsslugs;
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
     * The band slugs in bands.js are exactly the slugs the i18n bundles label,
     * and each has its band_<slug> lang string, so a slug added or dropped on
     * one side alone is caught. The slugs are frozen identifiers — relabelling
     * a band is a lang-string change, never a slug change.
     *
     * @return void
     */
    public function test_band_slugs_match_the_bundle_labels(): void {
        $this->resetAfterTest();
        $jsslugs = $this->js_band_slugs();

        $bundles = [
            'i18n_bundle' => bootstrap::i18n_bundle()['bands'],
            'simulator_i18n' => bootstrap::simulator_i18n()['bands'],
        ];
        foreach ($bundles as $name => $bands) {
            $phpslugs = array_keys($bands);
            sort($phpslugs);
            $this->assertSame($jsslugs, $phpslugs, "bootstrap::{$name}() labels a different set of bands than bands.js.");
        }

        $strings = get_string_manager();
        foreach ($jsslugs as $slug) {
            $this->assertTrue(
                $strings->string_exists('band_' . $slug, 'block_feedback_tracker'),
                "Band '{$slug}' has no band_{$slug} lang string."
            );
        }
    }

    /**
     * Every band the score calculator can assign has a colour in bands.js, so
     * no server-computed band renders uncoloured.
     *
     * @return void
     */
    public function test_calculator_bands_have_a_js_colour(): void {
        $this->resetAfterTest();
        unset_config('score_thresholds_band', 'block_feedback_tracker');
        $jsslugs = $this->js_band_slugs();

        $calculated = [responsiveness_calculator::BAND_NODATA];
        for ($score = 0; $score <= 100; $score++) {
            $calculated[] = responsiveness_calculator::band_for((float) $score);
        }
        $calculated = array_values(array_unique($calculated));
        $this->assertCount(5, $calculated, 'Precondition: the scores 0..100 reach every scored band plus nodata.');

        $this->assertSame([], array_values(array_diff($calculated, $jsslugs)));
    }

    /**
     * Every amd/src module, concatenated.
     *
     * @return string
     */
    private function all_amd_sources(): string {
        $root = __DIR__ . '/../../amd/src/';
        $files = array_merge(glob($root . '*.js'), glob($root . '*/*.js'));
        $this->assertNotEmpty($files, 'Precondition: amd/src holds the modules.');
        return implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));
    }

    /**
     * Every string the four i18n bundles ship is read somewhere in amd/src,
     * so the bundles carry nothing the page pays for and no view shows. A key
     * counts as read when it appears as a whole word, or when a prefix of it
     * is concatenated with a variable (`i18n['sim_term_' + k]`). The bundles
     * are built for an administrator, who is offered every optional section.
     *
     * @return void
     */
    public function test_every_bundled_string_is_read_by_the_js(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $js = $this->all_amd_sources();

        $bundles = [
            'i18n_bundle' => bootstrap::i18n_bundle(),
            'dashboard_i18n' => bootstrap::dashboard_i18n(),
            'pending_report_i18n' => bootstrap::pending_report_i18n(),
            'simulator_i18n' => bootstrap::simulator_i18n(),
        ];
        $this->assertArrayHasKey(
            'dashboard_comparison_title',
            $bundles['dashboard_i18n'],
            'Precondition: the optional site-benchmarks strings are in the bundle.'
        );

        $unread = [];
        foreach ($bundles as $name => $bundle) {
            foreach (array_keys($bundle) as $key) {
                if (preg_match('/\b' . preg_quote($key, '/') . '\b/', $js)) {
                    continue;
                }
                $parts = explode('_', $key);
                $dynamic = false;
                for ($i = 1; $i < count($parts) && !$dynamic; $i++) {
                    $prefix = implode('_', array_slice($parts, 0, $i)) . '_';
                    $dynamic = (bool) preg_match("/'" . preg_quote($prefix, '/') . "'\s*\+/", $js);
                }
                if (!$dynamic) {
                    $unread[] = "{$name}: {$key}";
                }
            }
        }
        $this->assertSame([], $unread);
    }

    /**
     * Every string the site-benchmarks section reads is shipped to the
     * dashboard, so no label silently falls back to its English default.
     *
     * @return void
     */
    public function test_site_benchmarks_strings_reach_the_dashboard(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $js = $this->amd_source('components/SchoolComparison.js');
        preg_match_all('/\bi18n\.([a-z0-9_]+)/', $js, $reads);
        $keys = array_values(array_unique($reads[1]));
        $this->assertContains('dashboard_comparison_title', $keys, 'Precondition: the section\'s reads were found.');

        $shipped = array_merge(bootstrap::i18n_bundle(), bootstrap::dashboard_i18n());
        $this->assertSame([], array_values(array_diff($keys, array_keys($shipped))));
    }
}
