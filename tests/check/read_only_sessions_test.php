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
 * Tests for the read-only sessions performance check.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_feedback_tracker\check\performance;

use advanced_testcase;
use core\check\manager;
use core\check\result;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * The check's verdict follows the site flag, and core finds the check through the plugin's callback.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(read_only_sessions::class)]
#[CoversFunction('block_feedback_tracker_performance_checks')]
final class read_only_sessions_test extends advanced_testcase {
    /**
     * A site with the flag set is told nothing is wrong.
     *
     * @return void
     */
    public function test_the_flag_set_is_ok(): void {
        global $CFG;
        $this->resetAfterTest();

        $CFG->enable_read_only_sessions = true;

        $this->assertSame(result::OK, (new read_only_sessions())->get_result()->get_status());
    }

    /**
     * A site without the flag is warned, and the check has no page to link to.
     *
     * @return void
     */
    public function test_the_flag_unset_warns(): void {
        global $CFG;
        $this->resetAfterTest();

        unset($CFG->enable_read_only_sessions);
        $check = new read_only_sessions();

        $this->assertSame(result::WARNING, $check->get_result()->get_status());
        $this->assertNull($check->get_action_link());
    }

    /**
     * The plugin's callback returns the check.
     *
     * @return void
     */
    public function test_the_callback_returns_the_check(): void {
        $checks = block_feedback_tracker_performance_checks();

        $this->assertCount(1, $checks);
        $this->assertInstanceOf(read_only_sessions::class, $checks[0]);
    }

    /**
     * Core's Performance report lists the check under the block's component.
     *
     * @return void
     */
    public function test_core_collects_the_check(): void {
        $refs = array_map(
            static fn($check): string => $check->get_ref(),
            manager::get_performance_checks()
        );

        $this->assertContains('block_feedback_tracker_read_only_sessions', $refs);
    }
}
