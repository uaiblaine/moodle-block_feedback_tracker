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
 * Tests for the removal grace period's reading of its settings.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * The "follow the recycle bin" toggle is a default-ON checkbox. Until an admin
 * saves the new settings after an upgrade its key is unset, and that must read
 * as the declared default, not as off.
 *
 * @covers \block_feedback_tracker\local\sla\removal_grace
 */
final class removal_grace_test extends \advanced_testcase {
    /**
     * A plugin grace of one day and a single enabled recycle bin that keeps
     * items for thirty days, so following the bin changes the answer.
     *
     * @return void
     */
    private function seed_windows(): void {
        set_config('removal_grace_seconds', (string) DAYSECS, 'block_feedback_tracker');
        set_config('coursebinenable', '1', 'tool_recyclebin');
        set_config('coursebinexpiry', (string) (30 * DAYSECS), 'tool_recyclebin');
        set_config('categorybinenable', '0', 'tool_recyclebin');
    }

    /**
     * An unset toggle follows the recycle bin, as its default says; the control
     * with an explicit '0' shows the bin is what lengthens the window.
     *
     * @return void
     */
    public function test_unset_toggle_follows_the_recycle_bin(): void {
        $this->resetAfterTest();
        $this->seed_windows();

        set_config('removal_grace_follow_recyclebin', '0', 'block_feedback_tracker');
        $this->assertSame(DAYSECS, removal_grace::seconds(), 'Control: switched off, the plugin grace applies.');

        unset_config('removal_grace_follow_recyclebin', 'block_feedback_tracker');
        $this->assertFalse(get_config('block_feedback_tracker', 'removal_grace_follow_recyclebin'));
        $this->assertSame(30 * DAYSECS, removal_grace::seconds());
    }
}
