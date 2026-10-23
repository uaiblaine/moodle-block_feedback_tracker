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
 * Tests for the grader filter gate in a process that has not loaded mod_assign's locallib.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\grading;

use block_feedback_tracker\local\output\bootstrap;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The teacher dashboard, the pending report, the score simulator and the
 * block build bootstrap::config_bundle(), which reads the saved grader
 * filters, on requests that have not loaded mod/assign/locallib.php.
 *
 * No test can see that state in its own process: before a test starts, the
 * PHPUnit process has included every plugin's lib.php, and
 * mod/assign/feedback/editpdf/lib.php loads locallib.php at its top. So the
 * gate runs in a child PHP process that boots the test site as
 * admin/tool/phpunit/cli/util.php does (PHPUNIT_UTIL: lib/setup.php and
 * nothing more). A boot that rebuilds the plugin caches includes every
 * lib.php too, so a child that finds locallib loaded is run once more, on the
 * caches the first one left. The child only reads: it keeps the not-logged-in
 * user a CLI boot starts with, whose preferences live in memory only, so it
 * needs no row of the test site.
 */
#[CoversClass(grader_filters::class)]
#[CoversClass(bootstrap::class)]
final class grader_filters_without_locallib_test extends \basic_testcase {
    /** @var string The child script; argv: Composer's autoload.php, lib/phpunit/bootstrap.php. */
    private const CHILD = <<<'PHP'
        <?php
        define('PHPUNIT_UTIL', true);
        require($argv[1]);
        require($argv[2]);
        $USER->preference = [];
        $result = ['locallibbefore' => defined('ASSIGN_FILTER_NONE')];
        foreach (['none', 'submitted'] as $value) {
            $USER->preference['assign_filter'] = $value;
            $USER->preference['_lastloaded'] = time();
            try {
                $result[$value] = \block_feedback_tracker\local\output\bootstrap::config_bundle()['grader_gate'];
            } catch (\Throwable $e) {
                $result[$value] = get_class($e) . ': ' . $e->getMessage();
            }
        }
        $result['locallibafter'] = defined('ASSIGN_FILTER_NONE');
        echo json_encode($result);
        PHP;

    /**
     * A saved status filter, "No filter" or a real one, is read without
     * mod_assign's constants and without loading them.
     *
     * @return void
     */
    public function test_saved_status_filter_is_read_without_assign_locallib(): void {
        $result = $this->run_child();
        if ($result['locallibbefore']) {
            $result = $this->run_child();
        }

        // The state of the failing requests: without it the test proves nothing.
        $this->assertFalse($result['locallibbefore'], 'The child process booted with mod/assign/locallib.php loaded.');
        $this->assertFalse($result['none'], '"No filter" must not turn the gate on.');
        $this->assertTrue($result['submitted'], 'A saved status filter must turn the gate on.');
        $this->assertFalse($result['locallibafter'], 'The gate loaded mod/assign/locallib.php.');
    }

    /**
     * Run the child script and decode what it prints.
     *
     * @return array Keys: locallibbefore, none, submitted, locallibafter.
     */
    private function run_child(): array {
        global $CFG;
        $script = make_request_directory() . '/grader_gate_child.php';
        file_put_contents($script, self::CHILD);
        $loader = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
        $command = [
            PHP_BINARY,
            $script,
            dirname($loader->getFileName(), 2) . '/autoload.php',
            $CFG->libdir . '/phpunit/bootstrap.php',
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $result = json_decode((string) $stdout, true);
        $this->assertIsArray($result, "The child process exited with $exit:\n$stdout\n$stderr");
        return $result;
    }
}
