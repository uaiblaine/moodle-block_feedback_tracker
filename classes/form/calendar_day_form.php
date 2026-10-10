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
 * Single-day add / edit form for the calendar editor.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\form;

use block_feedback_tracker\local\calendar\calendar;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Upserts one row in {block_feedback_tracker_cday}.
 *
 * When daytype = 'optional' the form also shows a start and an end time
 * (HH:MM) for a sub-day event, and the note, present for every daytype,
 * serves as the event name.
 *
 * The HH:MM values are post-processed in get_data() to minutes-since-
 * midnight ints so the WS layer doesn't need its own time parser.
 */
class calendar_day_form extends \moodleform {
    /** Regex for an HH:MM input (24-hour clock). */
    public const TIME_REGEX = '/^([0-1]?\d|2[0-3]):[0-5]\d$/';

    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $plugin = 'block_feedback_tracker';

        $mform->addElement(
            'text',
            'daydate',
            get_string('caleditor_col_date', $plugin),
            ['size' => 10, 'placeholder' => 'YYYYMMDD']
        );
        $mform->setType('daydate', PARAM_INT);
        $mform->addRule('daydate', null, 'required', null, 'client');

        // Reuse calendar::daytype_label() so the form's dropdown stays
        // in lockstep with the day-list display in calendar_editor.php.
        $types = [
            calendar::DAYTYPE_SCHOOLDAY => calendar::daytype_label(calendar::DAYTYPE_SCHOOLDAY),
            calendar::DAYTYPE_HOLIDAY   => calendar::daytype_label(calendar::DAYTYPE_HOLIDAY),
            calendar::DAYTYPE_RECESS    => calendar::daytype_label(calendar::DAYTYPE_RECESS),
            calendar::DAYTYPE_CLOSED    => calendar::daytype_label(calendar::DAYTYPE_CLOSED),
            calendar::DAYTYPE_OPTIONAL  => calendar::daytype_label(calendar::DAYTYPE_OPTIONAL),
        ];
        $mform->addElement('select', 'daytype', get_string('caleditor_col_type', $plugin), $types);
        $mform->setDefault('daytype', 'holiday');

        /* Sub-day event window, only meaningful when daytype is 'optional'.
         * Leaving both empty keeps the full-day optional rule. */
        $mform->addElement(
            'text',
            'starttime',
            get_string('caleditor_starttime', $plugin),
            ['size' => 6, 'placeholder' => 'HH:MM']
        );
        $mform->setType('starttime', PARAM_TEXT);
        $mform->hideIf('starttime', 'daytype', 'neq', calendar::DAYTYPE_OPTIONAL);

        $mform->addElement(
            'text',
            'endtime',
            get_string('caleditor_endtime', $plugin),
            ['size' => 6, 'placeholder' => 'HH:MM']
        );
        $mform->setType('endtime', PARAM_TEXT);
        $mform->hideIf('endtime', 'daytype', 'neq', calendar::DAYTYPE_OPTIONAL);
        $mform->addElement(
            'static',
            'caleditor_event_window_help_static',
            '',
            get_string('caleditor_event_window_help', $plugin)
        );
        $mform->hideIf('caleditor_event_window_help_static', 'daytype', 'neq', calendar::DAYTYPE_OPTIONAL);

        $mform->addElement(
            'text',
            'note',
            get_string('caleditor_col_note', $plugin),
            ['size' => 40, 'maxlength' => 255]
        );
        $mform->setType('note', PARAM_TEXT);

        // A named submit, as on every form of the calendar editor, so the page's
        // forms do not all post "submitbutton". The "Save changes" label is the
        // only one on that page (the other forms say "Save" or "Import"), and
        // Behat presses it by label.
        $mform->addElement('submit', 'savedaybutton', get_string('savechanges'));
        $mform->closeHeaderBefore('savedaybutton');
    }

    /**
     * Reject malformed dates and time windows.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $ymd = (int) ($data['daydate'] ?? 0);
        if ($ymd < 19700101 || $ymd > 99991231) {
            $errors['daydate'] = get_string('caleditor_err_baddate', 'block_feedback_tracker');
        } else {
            $y = (int) substr((string) $ymd, 0, 4);
            $m = (int) substr((string) $ymd, 4, 2);
            $d = (int) substr((string) $ymd, 6, 2);
            if (!checkdate($m, $d, $y)) {
                $errors['daydate'] = get_string('caleditor_err_baddate', 'block_feedback_tracker');
            }
        }

        /* The optional time window is either both empty (full-day optional)
         * or both set with start < end. */
        if (($data['daytype'] ?? '') === calendar::DAYTYPE_OPTIONAL) {
            $start = trim((string) ($data['starttime'] ?? ''));
            $end = trim((string) ($data['endtime'] ?? ''));
            if ($start !== '' || $end !== '') {
                if ($start === '' || $end === '') {
                    $errors['endtime'] = get_string('caleditor_err_window_partial', 'block_feedback_tracker');
                } else if (!preg_match(self::TIME_REGEX, $start)) {
                    $errors['starttime'] = get_string('caleditor_err_badtime', 'block_feedback_tracker');
                } else if (!preg_match(self::TIME_REGEX, $end)) {
                    $errors['endtime'] = get_string('caleditor_err_badtime', 'block_feedback_tracker');
                } else if (self::hhmm_to_minutes($start) >= self::hhmm_to_minutes($end)) {
                    $errors['endtime'] = get_string('caleditor_err_window_order', 'block_feedback_tracker');
                }
            }
        }
        return $errors;
    }

    /**
     * Post-process: convert HH:MM strings to minutes-since-midnight ints
     * before the form data reaches the WS layer. Empty strings become
     * nulls so the cday row reverts to full-day semantics.
     *
     * @return \stdClass|null
     */
    public function get_data() {
        $data = parent::get_data();
        if ($data === null) {
            return null;
        }
        if (($data->daytype ?? '') === calendar::DAYTYPE_OPTIONAL) {
            $start = trim((string) ($data->starttime ?? ''));
            $end = trim((string) ($data->endtime ?? ''));
            $data->starttime = $start === '' ? null : self::hhmm_to_minutes($start);
            $data->endtime = $end === '' ? null : self::hhmm_to_minutes($end);
        } else {
            // Drop the window: hidden time inputs still post what they held from
            // an earlier "optional" selection. save_calendar_day drops it as well.
            $data->starttime = null;
            $data->endtime = null;
        }
        return $data;
    }

    /**
     * Convert an HH:MM string to minutes since midnight.
     *
     * @param string $hhmm
     * @return int
     */
    private static function hhmm_to_minutes(string $hhmm): int {
        [$h, $m] = explode(':', $hhmm);
        return ((int) $h) * 60 + (int) $m;
    }
}
