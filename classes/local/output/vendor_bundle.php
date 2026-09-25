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
 * Location of the vendored Preact + htm bundle.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

/**
 * Names the vendored Preact + htm bundle and loads it into a page.
 *
 * The file name carries both library versions, so an update renames the file.
 * This class is the only PHP code that spells it: the block and every page
 * that mounts a Preact root call load(). js/vendor/README.md explains how the
 * bundle is rebuilt; thirdpartylibs.xml must list the same file and versions.
 */
final class vendor_bundle {
    /** File name under js/vendor/: bft-vendor-<Preact version>-<htm version>.min.js. */
    public const FILENAME = 'bft-vendor-10.29.8-3.1.1.min.js';

    /**
     * URL the page loads the bundle from.
     *
     * @return \moodle_url
     */
    public static function url(): \moodle_url {
        return new \moodle_url('/blocks/feedback_tracker/js/vendor/' . self::FILENAME);
    }

    /**
     * Absolute path of the bundle on disk.
     *
     * Resolved from this file rather than $CFG->dirroot, which is the public/
     * directory on 5.1+ and the checkout root on 4.5.
     *
     * @return string
     */
    public static function path(): string {
        return dirname(__DIR__, 3) . '/js/vendor/' . self::FILENAME;
    }

    /**
     * Add the bundle to the page head.
     *
     * It has to be in the head: amd/src/lib/preact.js reads window.bftPreact
     * when the module is evaluated and throws if the bundle has not run yet.
     * Call it before the page head is written (a block's get_content() runs
     * early enough).
     *
     * @param \moodle_page $page The page that mounts a Preact root.
     * @return void
     */
    public static function load(\moodle_page $page): void {
        $page->requires->js(self::url(), true);
    }
}
