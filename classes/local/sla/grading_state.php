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
 * Grading-state resolver for one mod_assign measurement cycle.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * The single decision point for "does this cycle carry a real mark, and has
 * that mark reached the student?".
 *
 * A mark is real when its grade is not null and not negative, as in
 * {@see \assign::get_grading_status()}; that alone rejects the placeholder
 * rows mod_assign auto-creates with grade -1. `grader` is deliberately not
 * tested: restore maps it through get_mappingid(), which stores 0 for an
 * unmapped grader, so a restored genuine grading would read as ungraded.
 *
 * A mark belongs to this cycle when it postdates the hand-in, mirroring the
 * `s.timemodified >= g.timemodified` clause of
 * {@see \assign::count_submissions_need_grading()}, but compared with the
 * cycle's frozen hand-in time rather than the live, mutable submission row.
 */
final class grading_state {
    /** Marking-workflow state in which the grade is visible to the student. */
    public const WORKFLOW_RELEASED = 'released';

    /** Marking-workflow state assumed when no flag row exists yet. */
    public const WORKFLOW_NOTMARKED = 'notmarked';

    /** Core grading status: a real mark exists. Mirrors ASSIGN_GRADING_STATUS_GRADED. */
    public const STATUS_GRADED = 'graded';

    /** Core grading status: no real mark. Mirrors ASSIGN_GRADING_STATUS_NOT_GRADED. */
    public const STATUS_NOT_GRADED = 'notgraded';

    /**
     * Resolve the grading state of one measurement cycle.
     *
     * The returned array carries: `hasmark` (bool, a real mark exists at all),
     * `markbelongs` (bool, that mark postdates this cycle's hand-in),
     * `timemarked` (int|null, set only when markbelongs), `isreleased` (bool),
     * `isclosed` (bool, the response actually reached the student),
     * `gradestate` (string, core's own status verbatim) and `usesworkflow`
     * (bool).
     *
     * @param \stdClass $assign An {assign} row; only `markingworkflow` is read.
     * @param \stdClass|null $grade An {assign_grades} row for this attempt, or null.
     * @param \stdClass|null $flags An {assign_user_flags} row for this user, or null.
     * @param int $timesubmitted This cycle's frozen hand-in time.
     * @param bool $isgradable Whether the activity can carry a numeric mark at all.
     * @return array Resolved state; see the description for the shape.
     */
    public static function resolve(
        \stdClass $assign,
        ?\stdClass $grade,
        ?\stdClass $flags,
        int $timesubmitted,
        bool $isgradable
    ): array {
        $gradetime = $grade !== null ? (int) $grade->timemodified : 0;

        if ($isgradable) {
            $hasmark = $grade !== null
                && $gradetime > 0
                && $grade->grade !== null
                && (float) $grade->grade >= 0.0;
            /* Strictly later: core's needs-grading counter treats a tie as
             * still needing grading, because the placeholder grade row copies
             * the submission's timemodified. The grade >= 0 test already
             * rejects placeholders here, but the boundary matches core so both
             * report the same pending count and the reconciler has no
             * difference to chase. */
            $markbelongs = $hasmark && $gradetime > $timesubmitted;
        } else {
            /* Grade type "None": no numeric mark is ever possible, so the
             * grade-value test would leave the row pending for ever. Follow the
             * needs-grading counter instead: any grade row strictly later than
             * the hand-in, which excludes the auto-created placeholder whose
             * timemodified is copied from the submission. */
            $hasmark = $grade !== null && $gradetime > 0;
            $markbelongs = $hasmark && $gradetime > $timesubmitted;
        }

        /* Under marking workflow the student sees nothing until the state is
         * released, so the cycle closes on release, not on marking. Mirrors
         * the workflow branch of get_grading_status(), including reading an
         * empty workflowstate (how core seeds the flags row) as notmarked. */
        $workflow = null;
        if (!empty($assign->markingworkflow)) {
            $workflow = ($flags !== null && !empty($flags->workflowstate))
                ? (string) $flags->workflowstate
                : self::WORKFLOW_NOTMARKED;
        }
        $isreleased = $workflow === null || $workflow === self::WORKFLOW_RELEASED;

        if ($workflow !== null) {
            $gradestate = $workflow;
        } else {
            $gradestate = $hasmark ? self::STATUS_GRADED : self::STATUS_NOT_GRADED;
        }

        return [
            'hasmark' => $hasmark,
            'markbelongs' => $markbelongs,
            'timemarked' => $markbelongs ? $gradetime : null,
            'isreleased' => $isreleased,
            'isclosed' => $markbelongs && $isreleased,
            'gradestate' => $gradestate,
            'usesworkflow' => $workflow !== null,
        ];
    }

    /**
     * Whether a marked cycle is still waiting for a marking-workflow release.
     *
     * True only when a real mark exists for this cycle, the activity uses
     * marking workflow, and the state has not reached released. The teacher
     * who entered the mark is frequently not the one holding
     * mod/assign:releasegrade, so this is surfaced in the UI as an explicit
     * "awaiting release" affordance rather than being silently folded into
     * "graded".
     *
     * @param array $state The array returned by {@see self::resolve()}.
     * @return bool
     */
    public static function is_awaiting_release(array $state): bool {
        return !empty($state['markbelongs'])
            && !empty($state['usesworkflow'])
            && empty($state['isreleased']);
    }
}
