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
 * The gradebook read: a response the activity never learns about.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Reads the one thing {assign_grades} cannot show: a grade a teacher entered
 * straight into the gradebook.
 *
 * The plugin measures when a response reached the *student*, and the student
 * reads the gradebook — mod_assign's own student page derives "is there a
 * grade", "can they see it" and "when was it graded" from {grade_grades}, not
 * from its own table. So a gradebook grade is a response, and this is where it
 * is read.
 *
 * Two rules make that read safe, and both are load-bearing.
 *
 * **The instant comes from `overridden`, never from `timemodified`.** Core sets
 * `overridden` when a human grades outside the activity, and deliberately
 * suppresses it for the one bulk operation that would otherwise look like mass
 * grading (a whole-item rescale). Meanwhile a course regrade, a calculated
 * item recompute and 5.2's penalty manager all move `timemodified` and never
 * touch `overridden`. Keying on `timemodified` would therefore have credited
 * every teacher on a site with a response the moment an admin changed a
 * category aggregation.
 *
 * **A hidden grade is not a response.** If the grade or its item is hidden, or
 * hidden until a future date, the student sees no feedback block at all and
 * core will not even email them. That is the same reasoning the marking-workflow
 * branch already applies to an unreleased mark, applied to the gradebook's own
 * release gate.
 */
final class gradebook_response {
    /** The response was measured from a mark inside the activity. */
    public const SOURCE_ASSIGN = 'assign';

    /** The response was measured from a grade entered in the gradebook. */
    public const SOURCE_GRADEBOOK = 'gradebook';

    /**
     * The gradebook's view of one user's grade on one assign.
     *
     * Returns `overridden` as the response instant only when the grade is
     * actually visible to the student; `hidden` reports the visibility fact on
     * its own, so a caller can disclose a hidden grade without treating it as a
     * response.
     *
     * @param int $assignid The {assign} id.
     * @param int $userid
     * @param int|null $now Override for tests; defaults to time().
     * @return array Keys: respondedat (int|null), hidden (bool), hasgrade (bool).
     */
    public static function for_assign_user(int $assignid, int $userid, ?int $now = null): array {
        global $DB;

        $none = ['respondedat' => null, 'hidden' => false, 'hasgrade' => false];
        if ($assignid <= 0 || $userid <= 0) {
            return $none;
        }

        /* itemnumber = 0 is the activity's own grade item. Outcome items
         * attached to the same activity carry itemtype 'mod' and itemmodule
         * 'assign' too, and are told apart only by itemnumber (>= 1000) —
         * matching on type and module alone would read an outcome's grade as
         * the assignment's. */
        $row = $DB->get_record_sql(
            "SELECT gg.id, gg.finalgrade, gg.overridden, gg.hidden AS gradehidden,
                    gi.hidden AS itemhidden
               FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id
              WHERE gi.itemtype = :itemtype
                AND gi.itemmodule = :itemmodule
                AND gi.iteminstance = :assignid
                AND gi.itemnumber = 0
                AND gg.userid = :userid",
            [
                'itemtype' => 'mod',
                'itemmodule' => 'assign',
                'assignid' => $assignid,
                'userid' => $userid,
            ],
            IGNORE_MULTIPLE
        );
        if (!$row || $row->finalgrade === null) {
            return $none;
        }

        $now = $now ?? time();
        $hidden = self::is_hidden((int) $row->gradehidden, (int) $row->itemhidden, $now);
        $overridden = (int) $row->overridden;

        return [
            /* Only an overridden stamp is a human grading outside the activity,
             * and only a visible one has reached anybody. */
            'respondedat' => ($overridden > 0 && !$hidden) ? $overridden : null,
            'hidden' => $hidden,
            'hasgrade' => true,
        ];
    }

    /**
     * Whether the gradebook keeps this grade from the student.
     *
     * Core overloads one column with two meanings: 1 is "hidden", and any
     * larger value is a hidden-until timestamp, which stops hiding once it
     * passes. The item's own flag hides every grade in it regardless.
     *
     * @param int $gradehidden {grade_grades}.hidden
     * @param int $itemhidden {grade_items}.hidden
     * @param int $now
     * @return bool
     */
    private static function is_hidden(int $gradehidden, int $itemhidden, int $now): bool {
        foreach ([$gradehidden, $itemhidden] as $flag) {
            if ($flag === 1) {
                return true;
            }
            if ($flag > 1 && $flag > $now) {
                return true;
            }
        }
        return false;
    }
}
