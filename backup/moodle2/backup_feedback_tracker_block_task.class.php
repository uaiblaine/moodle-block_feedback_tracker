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
 * Course backup of the block: the ledger rows, when the site asks for them.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/feedback_tracker/backup/moodle2/backup_feedback_tracker_stepslib.php');

/**
 * Adds the ledger to a course backup only in the full mode of
 * `block_feedback_tracker/backup_ledger` and only when the backup carries user
 * data. Otherwise the block leaves no file of its own, and a restore rebuilds
 * the course's measurements from its submissions instead
 * ({@see restore_feedback_tracker_block_task::after_restore()}).
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_feedback_tracker_block_task extends backup_block_task {
    /** The site setting's value that puts the ledger in backups. */
    public const MODE_FULL = 'full';

    /** The default: no ledger rows in backups, rebuilt after a restore. */
    public const MODE_REDERIVE = 'rederive';

    /**
     * No settings of the block's own.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Add the ledger step when this backup should carry the ledger.
     *
     * @return void
     */
    protected function define_my_steps() {
        if (get_config('block_feedback_tracker', 'backup_ledger') !== self::MODE_FULL) {
            return;
        }
        if (!$this->get_setting_value('users')) {
            return;
        }
        $this->add_step(new backup_feedback_tracker_block_structure_step('feedback_tracker_structure', 'feedback_tracker.xml'));
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
     * The block's configdata holds nothing to encode.
     *
     * @return array
     */
    public function get_configdata_encoded_attributes() {
        return [];
    }

    /**
     * No links to encode.
     *
     * @param string $content The content to encode.
     * @return string
     */
    public static function encode_content_links($content) {
        return $content;
    }
}
