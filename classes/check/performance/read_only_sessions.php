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
 * Performance check: read-only sessions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\check\performance;

use core\check\check;
use core\check\result;

/**
 * Whether one viewer's parallel requests can run side by side.
 *
 * Every web service of the plugin is a read declared `readonlysession`, and core honours that only when
 * $CFG->enable_read_only_sessions is set in config.php. Without it each request holds the session lock, so the
 * three or four calls a dashboard or report load starts together answer one after another.
 *
 * - OK when the flag is set.
 * - WARNING when it is not. The flag is not an admin setting, and core refuses to start a session while any cache
 *   definition in session mode is mapped to the session store, so the check links to no page.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class read_only_sessions extends check {
    /**
     * The check's name on the Performance overview report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('checkreadonlysessions', 'block_feedback_tracker');
    }

    /**
     * Read the site flag.
     *
     * @return result
     */
    public function get_result(): result {
        global $CFG;

        if (!empty($CFG->enable_read_only_sessions)) {
            return new result(
                result::OK,
                get_string('checkreadonlysessions_ok', 'block_feedback_tracker'),
                get_string('checkreadonlysessions_details', 'block_feedback_tracker')
            );
        }
        return new result(
            result::WARNING,
            get_string('checkreadonlysessions_warning', 'block_feedback_tracker'),
            get_string('checkreadonlysessions_details', 'block_feedback_tracker')
        );
    }
}
