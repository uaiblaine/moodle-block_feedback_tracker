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
 * Drift-check covering every entry in db/services.php.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

/**
 * For every function declared in db/services.php, asserts:
 *   - the class exists,
 *   - it extends \core_external\external_api,
 *   - execute_parameters() / execute() / execute_returns() are defined,
 *   - the listed capability exists in db/access.php (or is a core capability),
 *   - a test file exists for it and claims coverage of the class,
 *   - and, when the function is capability-gated, that test file exercises a
 *     refusal.
 *
 * The last two turn coverage from something an audit notices into something
 * the build enforces: registering a web service without a test, or without a
 * failure-path test for its gate, fails here. The missing refusal test is
 * precisely how a cross-context write IDOR reached production once.
 *
 * Catches the common drift modes — renaming a class without updating
 * services.php, deleting a capability someone still references — at
 * the cost of one cheap PHPUnit pass per CI run.
 *
 * @coversNothing
 */
final class services_coverage_test extends \advanced_testcase {
    public function test_every_declared_service_has_a_valid_class(): void {
        $functions = self::load_functions();
        $this->assertNotEmpty($functions, 'db/services.php should declare at least one function.');

        foreach ($functions as $name => $def) {
            $msg = "WS function `{$name}`: ";
            $this->assertArrayHasKey(
                'classname',
                $def,
                $msg . 'missing classname'
            );
            $classname = (string) $def['classname'];
            $this->assertTrue(
                class_exists($classname),
                $msg . "class `{$classname}` does not exist"
            );
            $this->assertTrue(
                is_subclass_of($classname, \core_external\external_api::class),
                $msg . "{$classname} does not extend external_api"
            );
            $this->assertTrue(
                method_exists($classname, 'execute_parameters'),
                $msg . "{$classname}::execute_parameters() missing"
            );
            $this->assertTrue(
                method_exists($classname, 'execute_returns'),
                $msg . "{$classname}::execute_returns() missing"
            );
            $this->assertTrue(
                method_exists($classname, 'execute'),
                $msg . "{$classname}::execute() missing"
            );
        }
    }

    public function test_every_listed_capability_exists(): void {
        $functions = self::load_functions();
        $declaredcaps = self::load_capabilities();

        foreach ($functions as $name => $def) {
            if (!isset($def['capabilities']) || $def['capabilities'] === '') {
                continue;
            }
            $caps = preg_split('/\s*,\s*/', (string) $def['capabilities']);
            foreach ($caps as $cap) {
                if ($cap === '') {
                    continue;
                }
                $this->assertTrue(
                    isset($declaredcaps[$cap]) || self::is_core_capability($cap),
                    "WS function `{$name}` references unknown capability `{$cap}`"
                );
            }
        }
    }

    /**
     * Every declared web service has a test file that claims coverage of it.
     *
     * @return void
     */
    public function test_every_declared_service_has_a_test_file(): void {
        foreach (self::load_functions() as $name => $def) {
            $classname = (string) $def['classname'];
            $short = substr($classname, (int) strrpos($classname, '\\') + 1);
            $path = __DIR__ . '/' . $short . '_test.php';

            $this->assertFileExists(
                $path,
                "WS function `{$name}` has no test file — expected tests/external/{$short}_test.php"
            );
            $this->assertStringContainsString(
                '@covers \\' . $classname,
                (string) file_get_contents($path),
                "tests/external/{$short}_test.php must declare @covers \\{$classname}"
            );
        }
    }

    /**
     * Every capability-gated web service has a test that exercises the
     * refusal, not just the happy path.
     *
     * Removing a gate must turn exactly one test red. Without this assertion
     * a gate can be deleted in silence, which is how the save_pause_window
     * authorisation hole shipped.
     *
     * @return void
     */
    public function test_every_gated_service_has_a_refusal_test(): void {
        foreach (self::load_functions() as $name => $def) {
            if (!isset($def['capabilities']) || $def['capabilities'] === '') {
                continue;
            }
            $classname = (string) $def['classname'];
            $short = substr($classname, (int) strrpos($classname, '\\') + 1);
            $path = __DIR__ . '/' . $short . '_test.php';
            if (!file_exists($path)) {
                // The previous test already reports this; do not double-fail.
                continue;
            }
            $this->assertStringContainsString(
                'required_capability_exception',
                (string) file_get_contents($path),
                "WS function `{$name}` is capability-gated but tests/external/{$short}_test.php "
                    . 'never asserts a refusal'
            );
        }
    }

    public function test_every_service_returns_a_structure(): void {
        $functions = self::load_functions();
        foreach ($functions as $name => $def) {
            $classname = (string) $def['classname'];
            $returns = $classname::execute_returns();
            $this->assertInstanceOf(
                \core_external\external_description::class,
                $returns,
                "WS function `{$name}`: execute_returns() must return an external_description"
            );
        }
    }

    /**
     * Read db/services.php into the $functions array via include.
     *
     * @return array
     */
    private static function load_functions(): array {
        $functions = [];
        require(__DIR__ . '/../../db/services.php');
        return $functions;
    }

    /**
     * Read db/access.php into the $capabilities array via include.
     *
     * @return array
     */
    private static function load_capabilities(): array {
        $capabilities = [];
        require(__DIR__ . '/../../db/access.php');
        return $capabilities;
    }

    /**
     * Heuristic for whether a capability lives outside the plugin's
     * own access.php (a core capability or one from another plugin).
     * The plugin only references its own capabilities today, but the
     * check is here so this test doesn't fail when a future WS
     * legitimately requires a core capability.
     *
     * @param string $cap
     * @return bool
     */
    private static function is_core_capability(string $cap): bool {
        // The plugin owns the block/feedback_tracker:* namespace.
        return strpos($cap, 'block/feedback_tracker:') !== 0;
    }
}
