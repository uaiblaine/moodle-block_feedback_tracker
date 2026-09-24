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
 * Tests for the upgrade steps.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\db;

/**
 * Upgrade steps whose effect is data rather than schema.
 *
 * @covers ::xmldb_block_feedback_tracker_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * The 2026092402 step queues every stored rollup for a recompute, because
     * the figures it stores changed meaning in that version.
     *
     * @return void
     */
    public function test_every_rollup_is_queued_for_recompute(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/blocks/feedback_tracker/db/upgrade.php');
        $this->resetAfterTest();

        $tuples = [[1101, 0], [1101, 7], [1102, 0]];
        foreach ($tuples as [$courseid, $groupid]) {
            $DB->insert_record('block_feedback_tracker_group', (object) [
                'courseid' => $courseid,
                'groupid' => $groupid,
                'timemodified' => time(),
            ]);
        }
        // Precondition: nothing is queued before the step runs.
        $this->assertSame(0, $DB->count_records('block_feedback_tracker_queue'));
        // The step runs for a site on the version before it.
        set_config('version', 2026092401, 'block_feedback_tracker');

        xmldb_block_feedback_tracker_upgrade(2026092401);

        $queued = [];
        foreach ($DB->get_records('block_feedback_tracker_queue') as $row) {
            $queued[] = [(int) $row->courseid, (int) $row->groupid];
        }
        sort($queued);
        $this->assertSame($tuples, $queued);
        $this->assertEquals(2026092402, get_config('block_feedback_tracker', 'version'));
    }
}
