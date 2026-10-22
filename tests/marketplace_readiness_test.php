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

namespace block_feedback_tracker;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Pins the Moodle plugins directory requirements no other gate checks.
 *
 * Each test is one rule of the plugin submission guidelines (Licensing,
 * Strings, Settings storage, CSS styles, Boilerplate, Privacy) or one finding
 * a directory reviewer raised on this fleet. They read files only, so they run
 * wherever the PHPUnit suite runs. The class is meant to be copied between
 * plugins: only COMPONENT and the core write map change.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class marketplace_readiness_test extends \basic_testcase {
    /** @var string The component under test. */
    private const COMPONENT = 'block_feedback_tracker';

    /**
     * The plugin root.
     *
     * @return string Absolute path without a trailing slash.
     */
    private function root(): string {
        return dirname(__DIR__);
    }

    /**
     * The export-ignore patterns of the plugin's .gitattributes.
     *
     * @return array List of patterns, without a leading or trailing slash.
     */
    private function export_ignored_patterns(): array {
        $file = $this->root() . '/.gitattributes';
        if (!is_readable($file)) {
            return [];
        }
        $patterns = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (in_array('export-ignore', array_slice($parts, 1), true)) {
                $patterns[] = trim($parts[0], '/');
            }
        }
        return $patterns;
    }

    /**
     * Whether git archive leaves a path out of the release package.
     *
     * @param string $path Path relative to the plugin root.
     * @return bool True when an export-ignore pattern covers it.
     */
    private function is_export_ignored(string $path): bool {
        foreach ($this->export_ignored_patterns() as $pattern) {
            if ($path === $pattern || str_starts_with($path, $pattern . '/') || fnmatch($pattern, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every shipped file under the given directories with one of the extensions.
     *
     * @param array $dirs Directories relative to the root ('' is the root itself).
     * @param array $extensions File extensions without the dot.
     * @return array Paths relative to the root.
     */
    private function shipped_files(array $dirs, array $extensions): array {
        $found = [];
        foreach ($dirs as $dir) {
            $base = rtrim($this->root() . '/' . $dir, '/');
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = substr($file->getPathname(), strlen($this->root()) + 1);
                if (!in_array($file->getExtension(), $extensions, true) || $this->is_export_ignored($path)) {
                    continue;
                }
                if (str_starts_with($path, '.') || str_starts_with($path, 'node_modules/')) {
                    continue;
                }
                $found[] = $path;
            }
        }
        sort($found);
        return array_values(array_unique($found));
    }

    /**
     * The package carries a licence file (Licensing): LICENSE for the plugins
     * directory and COPYING.txt for the MAH completeness checker, both shipped.
     *
     * @return void
     */
    public function test_the_package_carries_a_licence_file(): void {
        $licence = null;
        foreach (['LICENSE', 'LICENSE.txt', 'LICENSE.md'] as $name) {
            if (is_file($this->root() . '/' . $name)) {
                $licence = $name;
                break;
            }
        }
        $this->assertNotNull($licence, 'No LICENSE file in the plugin root.');
        $this->assertFileExists($this->root() . '/COPYING.txt');
        foreach ([$licence, 'COPYING.txt'] as $name) {
            $this->assertFalse($this->is_export_ignored($name), "$name is export-ignored, so the zip lacks it.");
            $this->assertStringContainsString('GNU GENERAL PUBLIC LICENSE', file_get_contents($this->root() . '/' . $name));
        }
    }

    /**
     * Only English strings ship (Strings): every other language directory is
     * export-ignored, since translations reach sites through AMOS.
     *
     * @return void
     */
    public function test_only_english_strings_ship(): void {
        $this->assertDirectoryExists($this->root() . '/lang/en');
        $this->assertFalse($this->is_export_ignored('lang/en'));
        foreach (glob($this->root() . '/lang/*', GLOB_ONLYDIR) as $dir) {
            $lang = basename($dir);
            if ($lang !== 'en') {
                $this->assertTrue($this->is_export_ignored('lang/' . $lang), "lang/$lang would ship in the package.");
            }
        }
    }

    /**
     * Language files are data only (Strings): each statement is
     * $string['id'] = 'literal'; with no concatenation, heredoc or other code.
     *
     * @return void
     */
    public function test_lang_files_are_data_only(): void {
        $files = glob($this->root() . '/lang/*/*.php');
        $this->assertNotEmpty($files);
        $skip = [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        $shape = [T_VARIABLE, '[', T_CONSTANT_ENCAPSED_STRING, ']', '=', T_CONSTANT_ENCAPSED_STRING, ';'];
        foreach ($files as $file) {
            $tokens = array_values(array_filter(token_get_all(file_get_contents($file)), static function ($token) use ($skip) {
                return !is_array($token) || !in_array($token[0], $skip, true);
            }));
            $name = basename(dirname($file)) . '/' . basename($file);
            // The MOODLE_INTERNAL guard may open the file, and nothing else may precede the strings.
            if ($tokens && is_array($tokens[0]) && $tokens[0][1] === 'defined') {
                $end = 0;
                while ($tokens[$end] !== ';') {
                    $end++;
                }
                $guard = implode('', array_map(static function ($token): string {
                    return is_array($token) ? $token[1] : $token;
                }, array_slice($tokens, 0, $end + 1)));
                $this->assertSame("defined('MOODLE_INTERNAL')||die();", $guard, "$name opens with other code.");
                $tokens = array_slice($tokens, $end + 1);
            }
            $this->assertSame(0, count($tokens) % count($shape), "$name holds something other than string assignments.");
            foreach (array_chunk($tokens, count($shape)) as $statement) {
                $line = is_array($statement[0]) ? $statement[0][2] : 0;
                foreach ($shape as $i => $expected) {
                    $actual = is_array($statement[$i]) ? $statement[$i][0] : $statement[$i];
                    $this->assertSame($expected, $actual, "$name line $line is not a plain \$string assignment.");
                }
                $this->assertSame('$string', $statement[0][1], "$name line $line assigns to {$statement[0][1]}.");
            }
        }
    }

    /**
     * The README names every table, capability and dependency the plugin has
     * (Metadata, Privacy): administrators judge from it what data the plugin
     * keeps, and a reviewer found a README that claimed no tables at all.
     *
     * @return void
     */
    public function test_the_readme_names_tables_capabilities_and_dependencies(): void {
        $readme = file_get_contents($this->root() . '/README.md');
        $this->assertNotFalse($readme);

        $installxml = $this->root() . '/db/install.xml';
        if (is_file($installxml)) {
            foreach (simplexml_load_file($installxml)->TABLES->TABLE as $table) {
                $this->assertStringContainsString((string) $table['NAME'], $readme, 'README does not name a table.');
            }
        }

        $accessfile = $this->root() . '/db/access.php';
        if (is_file($accessfile)) {
            $capabilities = (static function (string $file): array {
                $capabilities = [];
                include($file);
                return $capabilities;
            })($accessfile);
            foreach (array_keys($capabilities) as $capability) {
                $this->assertStringContainsString($capability, $readme, 'README does not name a capability.');
            }
        }

        $plugin = (static function (string $file): \stdClass {
            $plugin = new \stdClass();
            include($file);
            return $plugin;
        })($this->root() . '/version.php');
        foreach (array_keys((array) ($plugin->dependencies ?? [])) as $dependency) {
            $this->assertStringContainsString($dependency, $readme, 'README does not name a dependency.');
        }
    }

    /**
     * Settings live in config_plugins (Settings storage): every stored admin
     * setting is named '<component>/<name>'.
     *
     * @return void
     */
    public function test_settings_are_stored_per_plugin(): void {
        $file = $this->root() . '/settings.php';
        if (!is_file($file)) {
            $this->assertFileDoesNotExist($file);
            return;
        }
        $pattern = '/new\s+\\\\?admin_setting_(\w+)\s*\(\s*([\'"])([^\'"]*)\2/';
        preg_match_all($pattern, file_get_contents($file), $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches, 'No admin setting was recognised in settings.php.');
        foreach ($matches as [, $type, , $name]) {
            if (in_array($type, ['heading', 'description'], true)) {
                continue;
            }
            $this->assertStringStartsWith(self::COMPONENT . '/', $name, "Setting $name is stored in the main config table.");
        }
    }

    /**
     * Every stylesheet selector is scoped to the plugin (CSS styles), since
     * all plugin sheets are concatenated and served on every page.
     *
     * @return void
     */
    public function test_styles_are_namespaced(): void {
        $file = $this->root() . '/styles.css';
        if (!is_file($file)) {
            $this->assertFileDoesNotExist($file);
            return;
        }
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents($file));
        $prefixes = [self::COMPONENT, str_replace('_', '-', self::COMPONENT)];
        $checked = 0;
        // A selector is the text before a '{' that is neither an at-rule nor a keyframe step.
        $keyframestep = '/^(from|to|[\d.]+%)(\s*,\s*(from|to|[\d.]+%))*$/';
        preg_match_all('/([^{};]+)\{/', $css, $matches);
        foreach ($matches[1] as $prelude) {
            $prelude = trim($prelude);
            if ($prelude === '' || $prelude[0] === '@' || preg_match($keyframestep, $prelude)) {
                continue;
            }
            foreach (explode(',', $prelude) as $selector) {
                $scoped = false;
                foreach ($prefixes as $prefix) {
                    $scoped = $scoped || str_contains($selector, $prefix);
                }
                $this->assertTrue($scoped, 'Selector not scoped to the plugin: ' . trim($selector));
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    /**
     * Every shipped source file carries the GPL boilerplate and a copyright
     * line (Boilerplate, Copyrights).
     *
     * @return void
     */
    public function test_source_files_carry_the_boilerplate(): void {
        $files = array_merge(
            $this->shipped_files([''], ['php']),
            $this->shipped_files(['amd/src', 'js/esm/src'], ['js', 'ts', 'tsx', 'jsx'])
        );
        $this->assertNotEmpty($files);
        foreach ($files as $path) {
            $source = file_get_contents($this->root() . '/' . $path);
            $this->assertStringContainsString('GNU General Public License', $source, "$path lacks the boilerplate.");
            if (!str_starts_with($path, 'lang/')) {
                $this->assertStringContainsString('@copyright', $source, "$path lacks @copyright.");
            }
        }
    }

    /**
     * Core writes that stamp a row with this component, and the core_*
     * privacy helpers the provider must then call (Privacy).
     *
     * Core's subsystem providers handle only the rows with an empty component
     * and leave the stamped ones to their owner. Add an entry when the plugin
     * starts a new kind of stamped write. core_group's export_groups() is not
     * listed on purpose: it exports the memberships of $USER, which the privacy
     * manager never switches to the request's subject, so the provider exports
     * them itself and the plugin's provider test runs the export as another user.
     *
     * @return array Call regex => [subsystem, list of helper names].
     */
    private function stamped_core_writes(): array {
        return [
            '/groups_add_member\s*\([^;]*[\'"]' . self::COMPONENT . '[\'"]/s' => ['core_group', [
                'get_group_members_in_context',
                'delete_groups_for_all_users',
                'delete_groups_for_user',
                'delete_groups_for_users',
            ]],
        ];
    }

    /**
     * Core rows the plugin stamps with its component are covered by its
     * privacy provider: a subsystem link plus every export and delete helper.
     *
     * A directory reviewer found memberships written with the component and a
     * provider that covered only the plugin's own tables.
     *
     * @return void
     */
    public function test_stamped_core_rows_are_covered_by_the_provider(): void {
        $sources = '';
        foreach ($this->shipped_files([''], ['php']) as $path) {
            if (!str_starts_with($path, 'tests/') && $path !== 'classes/privacy/provider.php') {
                $sources .= file_get_contents($this->root() . '/' . $path);
            }
        }
        $providerfile = $this->root() . '/classes/privacy/provider.php';
        $provider = is_file($providerfile) ? file_get_contents($providerfile) : '';

        foreach ($this->stamped_core_writes() as $pattern => [$subsystem, $helpers]) {
            if (!preg_match($pattern, $sources)) {
                continue;
            }
            $this->assertMatchesRegularExpression(
                '/add_subsystem_link\(\s*[\'"]' . $subsystem . '[\'"]/',
                $provider,
                "The provider does not link $subsystem."
            );
            foreach ($helpers as $helper) {
                $this->assertMatchesRegularExpression(
                    '/' . $subsystem . '\\\\privacy\\\\provider::' . $helper . '\(\s*[^,]+,\s*[\'"]' . self::COMPONENT . '[\'"]/',
                    $provider,
                    "The provider never calls $subsystem's $helper() with its component."
                );
            }
        }
    }
}
