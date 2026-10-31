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
 * Course restore of the block: the ledger rows when the backup has them, and
 * a rebuild of the restored course in every case.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/feedback_tracker/backup/moodle2/restore_feedback_tracker_stepslib.php');

/**
 * Follows what the backup file holds, not the site's current
 * `block_feedback_tracker/backup_ledger`: a backup taken in the full mode
 * carries `feedback_tracker.xml` and its rows are restored; one without the
 * file, from the default mode, from a backup without user data or from a
 * version before backups had the ledger, restores none.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_feedback_tracker_block_task extends restore_block_task {
    /**
     * No settings of the block's own.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Add the ledger step when the backup carries the ledger.
     *
     * The structure step throws on a missing file, so the file decides.
     *
     * @return void
     */
    protected function define_my_steps() {
        if (file_exists($this->get_taskbasepath() . '/feedback_tracker.xml')) {
            $this->add_step(
                new restore_feedback_tracker_block_structure_step('feedback_tracker_structure', 'feedback_tracker.xml')
            );
        }
    }

    /**
     * The block keeps no files.
     *
     * @return array
     */
    public function get_fileareas() {
        return [];
    }

    /**
     * The block's configdata holds nothing to decode.
     *
     * @return array
     */
    public function get_configdata_encoded_attributes() {
        return [];
    }

    /**
     * No contents to decode.
     *
     * @return array
     */
    public static function define_decode_contents() {
        return [];
    }

    /**
     * No decode rules.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [];
    }

    /**
     * Queue the rebuild of the restored course.
     *
     * A restore fires none of the events the ledger is written from. In the
     * full mode the rebuild fills what the file did not carry (an activity
     * restored without its rows, a submission newer than the backup's
     * ledger); otherwise it is how the course gets its measurements back.
     * Runs after every step's own `after_restore()`, so restored rows exist.
     *
     * @return void
     */
    public function after_restore() {
        \block_feedback_tracker\task\rederive_course::queue((int) $this->get_courseid());
    }
}
