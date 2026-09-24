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

/**
 * Tests for Feedback Flow
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {
    /**
     * Plugin installs and exposes its version in config.
     *
     * @coversNothing
     */
    public function test_plugin_installed(): void {
        $this->assertNotEmpty(get_config('block_feedback_tracker', 'version'));
    }

    /**
     * The reset empties the ledger and keeps the recompute audit log, adding
     * its own entry, and reports as removed only what it removed.
     *
     * @covers ::block_feedback_tracker_reset_data
     */
    public function test_reset_keeps_the_audit_log_and_reports_only_what_it_removed(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/blocks/feedback_tracker/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $generator->create_ledger_row();
        $generator->create_ledger_row();
        $generator->seed_audit_log(3);
        $logbefore = $DB->count_records('block_feedback_tracker_log');
        $this->assertGreaterThanOrEqual(3, $logbefore);

        $counts = block_feedback_tracker_reset_data();

        $this->assertSame(0, $DB->count_records('block_feedback_tracker_sub'));
        $this->assertSame(2, $counts['ledger']);
        $this->assertSame(
            ['ledger', 'rollups', 'trends', 'sites', 'queue'],
            array_keys($counts),
            'The counts are shown as rows removed, so they name only the tables the reset empties.'
        );
        $this->assertSame(
            $logbefore + 1,
            $DB->count_records('block_feedback_tracker_log'),
            'The audit log survives the reset and records it.'
        );
    }
}
