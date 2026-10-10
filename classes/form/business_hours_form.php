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
 * Weekly business-hours form (one day-of-week per instance).
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
 * Up to {@see self::SLOTS_PER_DAY} `start → end` slot pairs (minutes since
 * midnight). Empty pairs are dropped at save time. Used once per dayofweek.
 */
class business_hours_form extends \moodleform {
    /** Maximum slots configurable per day-of-week. */
    public const SLOTS_PER_DAY = 3;

    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'dayofweek');
        $mform->setType('dayofweek', PARAM_INT);

        for ($i = 0; $i < self::SLOTS_PER_DAY; $i++) {
            $group = [
                $mform->createElement('text', "start_$i", '', [
                    'size' => 5,
                    'min' => 0,
                    'max' => 1439,
                    'placeholder' => 'start',
                ]),
                $mform->createElement('text', "end_$i", '', [
                    'size' => 5,
                    'min' => 1,
                    'max' => 1440,
                    'placeholder' => 'end',
                ]),
            ];
            $label = get_string('caleditor_hours_slot', 'block_feedback_tracker', $i + 1);
            $mform->addGroup($group, "slotgroup_$i", $label, ' &rarr; ', false);
            $mform->setType("start_$i", PARAM_INT);
            $mform->setType("end_$i", PARAM_INT);
        }

        $mform->addElement('submit', 'savehoursbutton', get_string('save', 'core'));
        $mform->closeHeaderBefore('savehoursbutton');
    }

    /**
     * Each slot must have end > start when both are present; slots within
     * a single day must not overlap.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $slots = [];
        for ($i = 0; $i < self::SLOTS_PER_DAY; $i++) {
            $start = isset($data["start_$i"]) && $data["start_$i"] !== '' ? (int) $data["start_$i"] : null;
            $end = isset($data["end_$i"]) && $data["end_$i"] !== '' ? (int) $data["end_$i"] : null;
            if ($start === null && $end === null) {
                continue;
            }
            if ($start === null || $end === null || $end <= $start) {
                $errors["slotgroup_$i"] = get_string('caleditor_err_badslot', 'block_feedback_tracker');
                continue;
            }
            $slots[] = [$start, $end];
        }
        usort($slots, static fn($a, $b) => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($slots); $i++) {
            if ($slots[$i][0] < $slots[$i - 1][1]) {
                $errors["slotgroup_0"] = get_string('caleditor_err_overlap', 'block_feedback_tracker');
                break;
            }
        }
        return $errors;
    }
}
