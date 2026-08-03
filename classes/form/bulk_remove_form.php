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
 * Filter form for the bulk block-removal tool.
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
 * Narrows the candidate list.
 *
 * The three date questions are deliberately separate rather than one clever
 * control. `course.enddate` is optional in Moodle and is frequently 0, so
 * "ended before" alone misses most of an old archive while a silent fallback
 * to `startdate` would sweep in courses still running. Asking the three
 * questions plainly lets an administrator say which one they mean.
 */
class bulk_remove_form extends \moodleform {
    /**
     * Build the filter controls.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $plugin = 'block_feedback_tracker';

        $mform->addElement(
            'date_selector',
            'endedbefore',
            get_string('bulk_filter_endedbefore', $plugin),
            ['optional' => true]
        );
        $mform->addHelpButton('endedbefore', 'bulk_filter_endedbefore', $plugin);

        $mform->addElement(
            'date_selector',
            'startedbefore',
            get_string('bulk_filter_startedbefore', $plugin),
            ['optional' => true]
        );
        $mform->addHelpButton('startedbefore', 'bulk_filter_startedbefore', $plugin);

        $mform->addElement(
            'advcheckbox',
            'noenddate',
            get_string('bulk_filter_noenddate', $plugin)
        );
        $mform->addHelpButton('noenddate', 'bulk_filter_noenddate', $plugin);

        $options = [0 => get_string('bulk_filter_anycategory', $plugin)]
            + \core_course_category::make_categories_list();
        $mform->addElement(
            'select',
            'categoryid',
            get_string('bulk_filter_category', $plugin),
            $options
        );

        $mform->addElement(
            'advcheckbox',
            'hiddenonly',
            get_string('bulk_filter_hiddenonly', $plugin)
        );
        $mform->addHelpButton('hiddenonly', 'bulk_filter_hiddenonly', $plugin);

        /* Named rather than the default submitbutton: the page carries a second
         * form (the removal itself) and add_action_buttons() hardcodes the
         * element name, which would duplicate id_submitbutton in the DOM. */
        $mform->addElement('submit', 'applyfilter', get_string('bulk_filter_apply', $plugin));
        $mform->closeHeaderBefore('applyfilter');
    }

    /**
     * Reject a filter that would offer the whole site.
     *
     * With every control empty the tool would list every course carrying the
     * block, which is not a considered selection — it is the default. Bulk
     * removal should be the result of answering a question.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $any = !empty($data['endedbefore'])
            || !empty($data['startedbefore'])
            || !empty($data['noenddate'])
            || !empty($data['categoryid'])
            || !empty($data['hiddenonly']);
        if (!$any) {
            $errors['endedbefore'] = get_string('bulk_filter_required', 'block_feedback_tracker');
        }
        return $errors;
    }
}
