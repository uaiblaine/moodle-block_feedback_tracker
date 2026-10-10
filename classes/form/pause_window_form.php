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
 * Manual pause-window add form.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Adds one row to {block_feedback_tracker_cpause}. The save WS picks the
 * Moodle context for the capability check using scopelevel + scopeid.
 */
class pause_window_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $plugin = 'block_feedback_tracker';

        $scopes = [
            'site'   => get_string('caleditor_scope_site', $plugin),
            'course' => get_string('caleditor_scope_course', $plugin),
            'group'  => get_string('caleditor_scope_group', $plugin),
        ];
        $mform->addElement('select', 'scopelevel', get_string('caleditor_col_scope', $plugin), $scopes);
        $mform->setDefault('scopelevel', 'site');

        $mform->addElement('text', 'scopeid', get_string('caleditor_scopeid', $plugin), ['size' => 8]);
        $mform->setType('scopeid', PARAM_INT);
        $mform->setDefault('scopeid', 0);
        $mform->disabledIf('scopeid', 'scopelevel', 'eq', 'site');

        $mform->addElement('text', 'reason', get_string('caleditor_col_reason', $plugin), ['size' => 20]);
        $mform->setType('reason', PARAM_ALPHANUMEXT);
        $mform->setDefault('reason', 'other');

        $mform->addElement(
            'date_time_selector',
            'timestart',
            get_string('caleditor_col_from', $plugin)
        );

        $mform->addElement(
            'date_time_selector',
            'timeend',
            get_string('caleditor_col_to', $plugin),
            ['optional' => true]
        );

        $mform->addElement(
            'text',
            'note',
            get_string('caleditor_col_note', $plugin),
            ['size' => 40, 'maxlength' => 255]
        );
        $mform->setType('note', PARAM_TEXT);

        $mform->addElement('submit', 'savepausebutton', get_string('save', 'core'));
        $mform->closeHeaderBefore('savepausebutton');
    }

    /**
     * scopeid > 0 required for course / group scope; timeend (when set) must
     * be strictly after timestart.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $scope = (string) ($data['scopelevel'] ?? 'site');
        $scopeid = (int) ($data['scopeid'] ?? 0);
        if (in_array($scope, ['course', 'group'], true) && $scopeid <= 0) {
            $errors['scopeid'] = get_string('caleditor_err_scopeid', 'block_feedback_tracker');
        }
        $start = (int) ($data['timestart'] ?? 0);
        $end = (int) ($data['timeend'] ?? 0);
        if ($end !== 0 && $end <= $start) {
            $errors['timeend'] = get_string('caleditor_err_endbeforestart', 'block_feedback_tracker');
        }
        return $errors;
    }
}
