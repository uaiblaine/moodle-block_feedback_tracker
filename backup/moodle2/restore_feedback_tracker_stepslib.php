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
 * Restore structure step of the block: the course's ledger and marker slots.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_feedback_tracker\local\sla\dirty_queue;

/**
 * Core restores the course's blocks before its sections and activities
 * (`restore_plan_builder::build_course_plan()`), so while this step parses
 * the file no activity has a new id yet. Each row is therefore parked in the
 * restore's own id table as it is read, and written in `after_restore()`,
 * which runs once every activity is restored and before core drops that
 * table (`restore_execute_after_restore` precedes
 * `restore_drop_and_clean_temp_stuff` in `restore_final_task`).
 *
 * A row is written only when its activity, its assignment and its student
 * were restored (a restore without user data maps no student, so it writes
 * nothing); anything else is left out, as core leaves out the
 * submissions of an activity it does not restore. A group or a marker that
 * did not travel reads as none (0), the value the ledger stores for "no
 * group" and "unallocated".
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_feedback_tracker_block_structure_step extends restore_structure_step {
    /** Restore id-table item name of a parked ledger row. */
    private const SUB_ITEM = 'block_feedback_tracker_sub';

    /** Restore id-table item name of a parked marker-slot row. */
    private const ALLOC_ITEM = 'block_feedback_tracker_alloc';

    /**
     * The paths of the cycles and their slots.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        return [
            new restore_path_element('feedback_tracker_sub', '/block/feedback_tracker/subs/sub'),
            new restore_path_element('feedback_tracker_alloc', '/block/feedback_tracker/subs/sub/allocs/alloc'),
        ];
    }

    /**
     * Park one ledger row until the activities have their new ids.
     *
     * @param array $data The parsed row, ids as on the source site.
     * @return void
     */
    public function process_feedback_tracker_sub($data) {
        $data = (object) $data;
        restore_dbops::set_backup_ids_record($this->get_restoreid(), self::SUB_ITEM, $data->id, 0, null, $data);
    }

    /**
     * Park one marker-slot row, keyed under the ledger row it belongs to.
     *
     * @param array $data The parsed row, ids as on the source site.
     * @return void
     */
    public function process_feedback_tracker_alloc($data) {
        $data = (object) $data;
        restore_dbops::set_backup_ids_record($this->get_restoreid(), self::ALLOC_ITEM, $data->id, 0, $data->subid, $data);
    }

    /**
     * Write the parked rows with their ids mapped, then queue the rollups.
     *
     * @return void
     */
    protected function after_restore() {
        global $DB;
        $courseid = (int) $this->get_courseid();
        $subids = [];
        $groupids = [];

        $rs = $DB->get_recordset(
            'backup_ids_temp',
            ['backupid' => $this->get_restoreid(), 'itemname' => self::SUB_ITEM],
            'itemid ASC',
            'itemid, info'
        );
        foreach ($rs as $parked) {
            $row = backup_controller_dbops::decode_backup_temp_info($parked->info);
            $cmid = $this->get_mappingid('course_module', $row->cmid);
            $assignid = $this->get_mappingid('assign', $row->iteminstance);
            $userid = $this->get_mappingid('user', $row->userid);
            if (!$cmid || !$assignid || !$userid) {
                continue;
            }
            $oldid = (int) $row->id;
            unset($row->id);
            $row->courseid = $courseid;
            $row->cmid = $cmid;
            $row->iteminstance = $assignid;
            $row->userid = $userid;
            $row->groupid = $this->map_optional('group', $row->groupid);
            $row->teamgroupid = $this->map_optional('group', $row->teamgroupid);
            $row->allocmarkerid = $this->map_optional('user', $row->allocmarkerid);
            /* The rule dates are the activity's own dates, which core moves
             * with the course start; every other instant is when something
             * happened and stays. */
            foreach (['timeopens', 'timecloses', 'timecutoff'] as $field) {
                if ($row->$field !== null) {
                    $row->$field = $this->apply_date_offset($row->$field);
                }
            }
            $subids[$oldid] = [(int) $DB->insert_record('block_feedback_tracker_sub', $row), $cmid];
            $groupids[(int) $row->groupid] = true;
        }
        $rs->close();

        $rs = $DB->get_recordset(
            'backup_ids_temp',
            ['backupid' => $this->get_restoreid(), 'itemname' => self::ALLOC_ITEM],
            'itemid ASC',
            'itemid, info'
        );
        foreach ($rs as $parked) {
            $row = backup_controller_dbops::decode_backup_temp_info($parked->info);
            [$subid, $cmid] = $subids[(int) $row->subid] ?? [0, 0];
            $userid = $this->get_mappingid('user', $row->userid);
            $marker = $this->get_mappingid('user', $row->marker);
            if (!$subid || !$userid || !$marker) {
                continue;
            }
            unset($row->id);
            $row->subid = $subid;
            $row->courseid = $courseid;
            $row->cmid = $cmid;
            $row->userid = $userid;
            $row->marker = $marker;
            $DB->insert_record('block_feedback_tracker_alloc', $row);
        }
        $rs->close();

        foreach (array_keys($groupids) as $groupid) {
            dirty_queue::enqueue($courseid, $groupid, dirty_queue::REASON_BULK);
        }
    }

    /**
     * Map an id that may be 0 or may not have travelled.
     *
     * @param string $itemname The restore item name ('group', 'user').
     * @param int|string $oldid The id on the source site.
     * @return int The id on this site, or 0 for none.
     */
    private function map_optional(string $itemname, $oldid): int {
        if (empty($oldid)) {
            return 0;
        }
        return (int) $this->get_mappingid($itemname, $oldid, 0);
    }
}
