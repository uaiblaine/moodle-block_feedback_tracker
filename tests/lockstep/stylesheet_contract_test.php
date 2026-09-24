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
 * Drift detector between styles.css and the code that uses it.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\lockstep;

/**
 * The JS builds class names and token names from a band slug at run time, so
 * no linter can see which rules and tokens it needs. A missing one fails
 * silently: an undeclared custom property invalidates the declaration that
 * reads it, and an unknown class paints nothing.
 *
 * @coversNothing
 */
final class stylesheet_contract_test extends \basic_testcase {
    /**
     * Class families the JS builds as prefix + band slug.
     */
    private const BAND_CLASS_FAMILIES = ['bft-badge-', 'bft-rh-tone-', 'bft-overall-score-tone-', 'bft-sim-tone-'];

    /**
     * Read a file of the plugin.
     *
     * @param string $relative Path below the plugin root.
     * @return string
     */
    private function source(string $relative): string {
        $path = __DIR__ . '/../../' . $relative;
        $this->assertFileExists($path, "{$relative} is missing");
        return (string) file_get_contents($path);
    }

    /**
     * BAND_COLOURS of amd/src/lib/bands.js, slug => lower-case colour.
     *
     * @return array
     */
    private function band_colours(): array {
        $js = $this->source('amd/src/lib/bands.js');
        $this->assertSame(
            1,
            preg_match('/BAND_COLOURS\s*=\s*\{(.*?)\}/s', $js, $block),
            'Could not find BAND_COLOURS in amd/src/lib/bands.js'
        );
        preg_match_all("/(\w+)\s*:\s*'(#[0-9a-fA-F]{6})'/", $block[1], $pairs, PREG_SET_ORDER);
        $colours = [];
        foreach ($pairs as $pair) {
            $colours[$pair[1]] = strtolower($pair[2]);
        }
        $this->assertCount(6, $colours, 'Expected the six band slugs in BAND_COLOURS');
        return $colours;
    }

    /**
     * Every band has its text token in both colour modes, and every class a
     * band slug completes.
     *
     * @return void
     */
    public function test_every_band_has_its_tokens_and_classes(): void {
        $css = $this->source('styles.css');
        foreach (array_keys($this->band_colours()) as $slug) {
            $this->assertGreaterThanOrEqual(
                2,
                preg_match_all('/--bft-band-' . $slug . '-fg\s*:/', $css),
                "--bft-band-{$slug}-fg needs a light and a dark declaration"
            );
            foreach (self::BAND_CLASS_FAMILIES as $prefix) {
                $this->assertMatchesRegularExpression(
                    '/\.' . preg_quote($prefix . $slug, '/') . '(?![\w-])/',
                    $css,
                    "styles.css has no rule for .{$prefix}{$slug}"
                );
            }
        }
    }

    /**
     * In light mode the text tokens hold the BAND_COLOURS values that
     * colourFor() falls back to, so a page without the tokens looks the same.
     * nodata is the exception: its text token is darker than the stroke grey.
     *
     * @return void
     */
    public function test_light_text_tokens_equal_the_js_fallbacks(): void {
        $css = $this->source('styles.css');
        foreach ($this->band_colours() as $slug => $colour) {
            $this->assertSame(
                1,
                preg_match('/--bft-band-' . $slug . '-fg\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $css, $match),
                "No light --bft-band-{$slug}-fg declaration"
            );
            if ($slug === 'nodata') {
                $this->assertNotSame($colour, strtolower($match[1]));
                continue;
            }
            $this->assertSame($colour, strtolower($match[1]), "--bft-band-{$slug}-fg differs from BAND_COLOURS");
        }
    }

    /**
     * Every --bft-* custom property read anywhere is declared in styles.css.
     * A name built at run time (it ends in a hyphen in the source) is left to
     * the band test above.
     *
     * @return void
     */
    public function test_every_token_read_is_declared(): void {
        $css = $this->source('styles.css');
        preg_match_all('/(--bft-[a-z0-9-]+)\s*:/', $css, $declarations);
        $declared = array_flip($declarations[1]);
        $this->assertNotEmpty($declared);

        $sources = ['styles.css' => $css];
        $root = __DIR__ . '/../../';
        $files = array_merge(
            glob($root . 'amd/src/*.js'),
            glob($root . 'amd/src/*/*.js'),
            glob($root . 'templates/*.mustache')
        );
        foreach ($files as $file) {
            $sources[substr($file, strlen($root))] = (string) file_get_contents($file);
        }

        $undeclared = [];
        foreach ($sources as $name => $text) {
            preg_match_all('/var\(\s*(--bft-[a-z0-9-]+)/', $text, $reads);
            foreach ($reads[1] as $token) {
                if (substr($token, -1) !== '-' && !isset($declared[$token])) {
                    $undeclared[] = "{$token} in {$name}";
                }
            }
        }
        $this->assertSame([], array_values(array_unique($undeclared)));
    }
}
