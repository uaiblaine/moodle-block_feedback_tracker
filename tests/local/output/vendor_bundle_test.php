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
 * Tests for the vendored Preact + htm bundle location.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

/**
 * A page that loads a bundle file which is not there renders nothing and
 * reports nothing server-side: the 404 leaves window.bftPreact unset and
 * amd/src/lib/preact.js throws in the browser. These tests keep the file name
 * in one class, keep that file on disk, and keep thirdpartylibs.xml and
 * js/vendor/README.md describing the same file.
 *
 * @covers \block_feedback_tracker\local\output\vendor_bundle
 */
final class vendor_bundle_test extends \basic_testcase {
    /** Matches a spelled-out bundle file name (a version digit after the prefix). */
    private const SPELLED = '/bft-vendor-\d/';

    /**
     * Absolute plugin root.
     *
     * @return string
     */
    private function root(): string {
        return dirname(__DIR__, 3);
    }

    /**
     * Preact and htm versions encoded in the file name.
     *
     * @return array{0:string, 1:string} Preact version, htm version.
     */
    private function versions(): array {
        $matched = preg_match(
            '/^bft-vendor-(\d+\.\d+\.\d+)-(\d+\.\d+\.\d+)\.min\.js$/',
            vendor_bundle::FILENAME,
            $m
        );
        $this->assertSame(1, $matched, 'FILENAME must read bft-vendor-<preact>-<htm>.min.js');
        return [$m[1], $m[2]];
    }

    /**
     * The file the class names exists, and the URL and path both point at it.
     *
     * @return void
     */
    public function test_named_file_exists(): void {
        $this->assertFileExists(vendor_bundle::path());
        $this->assertSame(
            $this->root() . '/js/vendor/' . vendor_bundle::FILENAME,
            vendor_bundle::path()
        );
        $this->assertStringEndsWith(
            '/blocks/feedback_tracker/js/vendor/' . vendor_bundle::FILENAME,
            vendor_bundle::url()->out(false)
        );
        // The globals amd/src/lib/preact.js reads are set by the bundle's epilogue.
        $source = (string) file_get_contents(vendor_bundle::path());
        foreach (['bftPreact', 'bftPreactHooks', 'bftHtm'] as $global) {
            $this->assertStringContainsString("window.{$global} = ", $source);
        }
    }

    /**
     * An update replaces the bundle: no second version is left in js/vendor.
     *
     * @return void
     */
    public function test_only_the_named_bundle_ships(): void {
        $files = array_map('basename', glob($this->root() . '/js/vendor/bft-vendor-*.min.js'));
        $this->assertSame([vendor_bundle::FILENAME], $files);
    }

    /**
     * No PHP file of the plugin outside vendor_bundle spells the file name.
     *
     * Tests are left out: this one spells the pattern it looks for.
     *
     * @return void
     */
    public function test_no_other_php_file_names_the_bundle(): void {
        $root = $this->root();
        // Hidden directories (.git) hold no plugin code.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn(\SplFileInfo $file): bool => !str_starts_with($file->getFilename(), '.')
            )
        );
        $scanned = [];
        $offenders = [];
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || str_starts_with($relative, 'tests/')) {
                continue;
            }
            $scanned[] = $relative;
            if (preg_match(self::SPELLED, (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $relative;
            }
        }
        // Control: the walk reached the block and the pages that load the
        // bundle, and the pattern does find the one legitimate spelling.
        $loaders = [
            'block_feedback_tracker.php',
            'pages/teacher_dashboard.php',
            'pages/pending_report.php',
            'pages/score_simulator.php',
            'pages/spike_react.php',
        ];
        foreach ($loaders as $loader) {
            $this->assertContains($loader, $scanned);
        }
        $this->assertSame(['classes/local/output/vendor_bundle.php'], $offenders);
    }

    /**
     * thirdpartylibs.xml lists the named file, with the versions its name
     * carries, for each of the three libraries in it.
     *
     * @return void
     */
    public function test_thirdpartylibs_describes_the_bundle(): void {
        [$preact, $htm] = $this->versions();
        $xml = simplexml_load_file($this->root() . '/thirdpartylibs.xml');
        $this->assertNotFalse($xml);
        $found = [];
        foreach ($xml->library as $library) {
            $this->assertSame('js/vendor/' . vendor_bundle::FILENAME, (string) $library->location);
            $found[(string) $library->name] = (string) $library->version;
        }
        $this->assertSame(['Preact' => $preact, 'Preact hooks' => $preact, 'htm' => $htm], $found);
    }

    /**
     * The README names the file and records the hash of the bytes on disk,
     * as does the comment in thirdpartylibs.xml.
     *
     * @return void
     */
    public function test_recorded_hash_matches_the_file(): void {
        $hash = 'sha384-' . base64_encode(hash_file('sha384', vendor_bundle::path(), true));
        $readme = (string) file_get_contents($this->root() . '/js/vendor/README.md');
        $this->assertStringContainsString(vendor_bundle::FILENAME, $readme);
        $this->assertStringContainsString($hash, $readme);
        $this->assertStringContainsString(
            $hash,
            (string) file_get_contents($this->root() . '/thirdpartylibs.xml')
        );
    }
}
