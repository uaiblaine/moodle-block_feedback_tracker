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
 * The same blindness covers contrast: stylelint accepts an `opacity` that
 * fades a label under 4.5:1, so the colour rules are pinned here too.
 *
 * @coversNothing
 */
final class stylesheet_contract_test extends \basic_testcase {
    /**
     * Class families the JS builds as prefix + band slug.
     */
    private const BAND_CLASS_FAMILIES = ['bft-badge-', 'bft-rh-tone-', 'bft-overall-score-tone-', 'bft-sim-tone-'];

    /**
     * The only selectors allowed an opacity below 1, each a purely decorative
     * element holding no text: opacity fades a text colour and its background
     * together, which is how three labels fell under 4.5:1.
     */
    private const DECORATIVE_OPACITY = [
        // The SVG band behind a sparkline's line: a fill, never text.
        '.bft-sparkline-zone',
        // The aria-hidden sparkle icons of the responsiveness hero.
        '.bft-rh-sparkle',
    ];

    /**
     * Selectors naming a dimmed or disabled state: a :disabled control, or a
     * class ending in -dim, -off, -busy, -disabled or -muted.
     */
    private const STATE_SELECTOR = '/:disabled\b|-(?:dim|off|busy|disabled|muted)\b/';

    /**
     * Glyphs that are the only sign of what a control does, so they need 3:1
     * as non-text indicators (WCAG 1.4.11). Each owns its colour and its
     * background, because what surrounds it belongs to the theme.
     */
    private const INDICATORS = [
        // The sortable-column arrow of the drill-down table (core's generaltable).
        'table.bft-sortable th[data-sort]::after',
    ];

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

    /**
     * styles.css with its comments removed, so prose naming a class or token
     * is not read as a rule.
     *
     * @return string
     */
    private function stylesheet(): string {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->source('styles.css'));
    }

    /**
     * The rules of styles.css, comments removed: each with its selector list
     * and its declarations (lower-case property => value). Rules nested in
     * an at-rule block (@media, @keyframes) are included, the at-rule itself
     * is not.
     *
     * @return array List of ['selectors' => string[], 'declarations' => array].
     */
    private function css_rules(): array {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $this->stylesheet(), $matches, PREG_SET_ORDER);
        $rules = [];
        foreach ($matches as $match) {
            $selectors = array_values(array_filter(array_map(
                static fn (string $sel): string => (string) preg_replace('/\s+/', ' ', trim($sel)),
                explode(',', $match[1])
            )));
            $declarations = [];
            foreach (explode(';', $match[2]) as $declaration) {
                $parts = explode(':', $declaration, 2);
                if (count($parts) === 2 && trim($parts[0]) !== '') {
                    $declarations[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
            }
            $rules[] = ['selectors' => $selectors, 'declarations' => $declarations];
        }
        $this->assertGreaterThan(100, count($rules), 'Precondition: styles.css parsed into its rules.');
        return $rules;
    }

    /**
     * The light-mode literal a colour value falls back to: the last hex colour
     * written in it, or, for a value that only reads another --bft-* token,
     * that token's light value. This is the colour a page without the theme's
     * --bs-* tokens paints, which the stylesheet keeps at the contrast the
     * theme's own values are chosen for.
     *
     * @param string $value A declaration value, e.g. "var(--bft-text-muted)".
     * @return string Lower-case "#rrggbb".
     */
    private function light_colour(string $value): string {
        if (preg_match_all('/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $value, $hexes)) {
            $hex = strtolower(end($hexes[1]));
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            return '#' . $hex;
        }
        $this->assertSame(
            1,
            preg_match('/var\(\s*(--bft-[a-z0-9-]+)/', $value, $token),
            "No colour to resolve in '{$value}'"
        );
        // The first declaration of a token is its light one; the dark-mode rule comes after it.
        $this->assertSame(
            1,
            preg_match('/' . preg_quote($token[1], '/') . '\s*:\s*([^;]+);/', $this->stylesheet(), $declared),
            "{$token[1]} is not declared"
        );
        return $this->light_colour($declared[1]);
    }

    /**
     * WCAG 2 contrast ratio of two "#rrggbb" colours.
     *
     * @param string $one
     * @param string $two
     * @return float
     */
    private function contrast(string $one, string $two): float {
        $luminance = static function (string $hex): float {
            $channels = [];
            foreach ([1, 3, 5] as $offset) {
                $c = hexdec(substr($hex, $offset, 2)) / 255;
                $channels[] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }
            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };
        $lighter = max($luminance($one), $luminance($two));
        $darker = min($luminance($one), $luminance($two));
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * The contrast helpers agree with known WCAG figures, so the thresholds
     * below measure what they claim to.
     *
     * @return void
     */
    public function test_the_contrast_helpers_measure_known_pairs(): void {
        $this->assertEqualsWithDelta(21.0, $this->contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(4.54, $this->contrast('#767676', '#ffffff'), 0.01);
        $this->assertSame('#5f6b7f', $this->light_colour('var(--bft-text-faint)'));
        $this->assertSame('#ffffff', $this->light_colour('var(--bft-surface)'));
    }

    /**
     * No rule fades its element with an opacity below 1 unless the element is
     * decorative and holds no text. An opacity scales a label and whatever it
     * sits on together, so a pair that passes at full strength fails once
     * faded; a dimmed state takes a colour pair of its own instead.
     *
     * @return void
     */
    public function test_no_text_is_dimmed_with_opacity(): void {
        $offenders = [];
        foreach ($this->css_rules() as $rule) {
            $opacity = $rule['declarations']['opacity'] ?? null;
            if ($opacity === null) {
                continue;
            }
            $value = str_ends_with($opacity, '%') ? (float) $opacity / 100 : (float) $opacity;
            if ($value >= 1) {
                continue;
            }
            foreach ($rule['selectors'] as $selector) {
                // Keyframe steps belong to an animation, not to a resting state.
                if (preg_match('/^(from|to|[0-9.]+%)$/', $selector)) {
                    continue;
                }
                if (!in_array($selector, self::DECORATIVE_OPACITY, true)) {
                    $offenders[] = "{$selector} (opacity: {$opacity})";
                }
            }
        }
        $this->assertSame([], $offenders, 'Give these a colour pair instead of an opacity.');
    }

    /**
     * Every dimmed or disabled state declares its own text colour, and that
     * colour keeps 4.5:1 on the state's background (its own, or the plugin
     * surface when it sets none), measured on the light-mode literals.
     *
     * @return void
     */
    public function test_dimmed_and_disabled_states_declare_a_readable_colour(): void {
        $surface = $this->light_colour('var(--bft-surface)');
        $checked = 0;
        $failures = [];
        foreach ($this->css_rules() as $rule) {
            $states = preg_grep(self::STATE_SELECTOR, $rule['selectors']);
            if (empty($states)) {
                continue;
            }
            $name = implode(', ', $states);
            $colour = $rule['declarations']['color'] ?? null;
            if ($colour === null) {
                $failures[] = "{$name}: declares no colour";
                continue;
            }
            $background = $rule['declarations']['background'] ?? $rule['declarations']['background-color'] ?? null;
            $ratio = $this->contrast(
                $this->light_colour($colour),
                $background === null ? $surface : $this->light_colour($background)
            );
            if ($ratio < 4.5) {
                $failures[] = sprintf('%s: %.2f:1', $name, $ratio);
            }
            $checked++;
        }
        $this->assertGreaterThanOrEqual(8, $checked, 'Precondition: the dimmed and disabled states were found.');
        $this->assertSame([], $failures);
    }

    /**
     * Each indicator glyph owns its colour and background, and the pair keeps
     * the 3:1 a non-text indicator needs.
     *
     * @return void
     */
    public function test_indicator_glyphs_own_a_visible_pair(): void {
        $rules = [];
        foreach ($this->css_rules() as $rule) {
            foreach ($rule['selectors'] as $selector) {
                $rules[$selector] = $rule['declarations'];
            }
        }
        foreach (self::INDICATORS as $selector) {
            $this->assertArrayHasKey($selector, $rules, "styles.css has no rule for {$selector}");
            $declarations = $rules[$selector];
            $this->assertArrayNotHasKey('opacity', $declarations, "{$selector} must not fade");
            $this->assertArrayHasKey('color', $declarations, "{$selector} must own its colour");
            $this->assertArrayHasKey('background', $declarations, "{$selector} must own its background");
            $this->assertGreaterThanOrEqual(
                3.0,
                $this->contrast($this->light_colour($declarations['color']), $this->light_colour($declarations['background'])),
                "{$selector} is under 3:1"
            );
        }
    }
}
