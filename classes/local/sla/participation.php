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
 * Whether a user still counts as a participant of a course.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * One answer to "is this person still someone whose work is outstanding".
 *
 * The reconciler's delete-side sweep answers it for a whole course with core's
 * `get_enrolled_sql($context, '', 0, true)`. The repair path
 * ({@see \block_feedback_tracker\task\backfill_one_submission}) needs the same
 * answer for one (course, user) pair, and asks core's `is_enrolled()` rather
 * than an inlined copy of that SQL that would have to track core by hand.
 *
 * The rule easiest to lose in a hand-written predicate: everybody
 * participates on the front page. Core skips the enrolment join for SITEID,
 * where nobody holds a {user_enrolments} row, so a predicate demanding one
 * would silently stop repairing every front-page activity.
 */
class participation {
    /**
     * Whether the user is an active participant of the course.
     *
     * Deleted accounts are excluded separately: `is_enrolled()` does not test
     * for them on the front page, while `get_enrolled_sql()` filters
     * `u.deleted = 0` on every course, the site course included. Without this
     * test the two would disagree about the users the delete-side sweep removes.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool True when the user's work is still somebody's outstanding task.
     */
    public static function is_active_participant(int $courseid, int $userid): bool {
        global $DB;

        if ($courseid <= 0 || $userid <= 0) {
            return false;
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return false;
        }
        try {
            $context = \context_course::instance($courseid);
        } catch (\Throwable $e) {
            return false;
        }
        // The fourth argument is $onlyactive, as in the sweep's get_enrolled_sql():
        // a suspended enrolment, a disabled method or an enrolment outside its
        // window all disqualify.
        return is_enrolled($context, $userid, '', true);
    }
}
