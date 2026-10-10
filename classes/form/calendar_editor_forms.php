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
 * The set of moodleforms the calendar editor page renders.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\form;

/**
 * Builds the ten forms of pages/calendar_editor.php: one calendar day form,
 * one bulk import form, one pause window form and one business hours form per
 * weekday.
 *
 * They share one page, and moodleform derives each element id from the
 * element name, so the seven hours forms would repeat every id and the day and
 * pause forms would share id_note. Every form is therefore built with core's
 * 'data-random-ids' attribute, which suffixes each generated element id with a
 * random string. Only ids change: element names, and so what each form posts,
 * stay as they are.
 */
class calendar_editor_forms {
    /** Form attributes shared by every form on the page. */
    private const ATTRIBUTES = ['data-random-ids' => 1];

    /**
     * Build the page's forms, the hours forms preloaded with the stored slots.
     *
     * @param string $action The URL every form posts to.
     * @return array Keys 'day', 'bulk' and 'pause' hold one form each; 'hours' holds
     *               the business_hours_form of each dayofweek, keyed 0 (Monday) to 6.
     */
    public static function build(string $action): array {
        global $DB;

        $day = new calendar_day_form($action, null, 'post', '', self::ATTRIBUTES);
        $bulk = new bulk_import_form($action, null, 'post', '', self::ATTRIBUTES);
        $pause = new pause_window_form($action, null, 'post', '', self::ATTRIBUTES);

        $hours = [];
        for ($dow = 0; $dow <= 6; $dow++) {
            $existing = $DB->get_records(
                'block_feedback_tracker_chours',
                ['dayofweek' => $dow, 'enabled' => 1],
                'starttime ASC',
                'id, starttime, endtime'
            );
            $defaults = ['dayofweek' => $dow];
            $i = 0;
            foreach ($existing as $row) {
                if ($i >= business_hours_form::SLOTS_PER_DAY) {
                    break;
                }
                $defaults["start_$i"] = (int) $row->starttime;
                $defaults["end_$i"] = (int) $row->endtime;
                $i++;
            }
            $form = new business_hours_form(
                $action,
                null,
                'post',
                '',
                ['id' => 'bft-hours-' . $dow] + self::ATTRIBUTES
            );
            $form->set_data($defaults);
            $hours[$dow] = $form;
        }

        return ['day' => $day, 'bulk' => $bulk, 'pause' => $pause, 'hours' => $hours];
    }
}
