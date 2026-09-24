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
 * Bulk CSV import form for the calendar editor.
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
 * Free-text CSV body. Validation + dispatch is handled by the
 * `bulk_import_calendar` external function.
 */
class bulk_import_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $plugin = 'block_feedback_tracker';

        $mform->addElement(
            'textarea',
            'csv',
            get_string('caleditor_bulk_heading', $plugin),
            [
                'rows' => 10,
                'cols' => 80,
                'placeholder' => "2026-04-03, holiday, Good Friday\n2026-04-06, holiday, Easter Monday",
            ]
        );
        $mform->setType('csv', PARAM_RAW);
        $mform->addRule('csv', null, 'required', null, 'client');

        $mform->addElement('submit', 'importbutton', get_string('caleditor_bulk_button', $plugin));
        $mform->closeHeaderBefore('importbutton');
    }
}
