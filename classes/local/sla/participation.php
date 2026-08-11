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
 * The reconciler's delete-side sweep decides this with core's
 * `get_enrolled_sql($context, '', 0, true)`, because it is course-scoped and
 * asks about a whole course at once. The repair path needs the same answer for
 * a single (course, user) pair, and asking it with an inlined copy of that SQL
 * would be the wrong shape twice over: the copy has to be kept in step with a
 * moving core helper, and it samples the clock at a different instant from the
 * sweep it must agree with.
 *
 * So this defers to core per user instead. `is_enrolled()` carries the rule
 * that matters most here and is the easiest to lose in a hand-written
 * predicate: **everybody participates on the front page**. Core skips the
 * entire enrolment join when the course context is SITEID, and nobody holds a
 * {user_enrolments} row there — so a predicate that demands one silently stops
 * repairing every front-page activity, which is quieter and worse than the
 * problem it was written to solve.
 */
class participation {
    /**
     * Whether the user is an active participant of the course.
     *
     * Deleted accounts are excluded separately because `is_enrolled()` does not
     * test for them, while core's `get_enrolled_sql()` applies `u.deleted = 0`
     * OUTSIDE its front-page branch — that is, on every course including the
     * site one. Without the extra test the two would disagree about exactly the
     * population the delete-side sweep removes.
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
        // The fourth argument is $onlyactive, matching the delete-side sweep's
        // get_enrolled_sql($context, '', 0, true): a suspended enrolment, a
        // disabled method, or an enrolment outside its window all disqualify.
        return is_enrolled($context, $userid, '', true);
    }
}
