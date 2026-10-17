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
 * Whose queue a ledger row belongs to when the activity allocates markers.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Marker-allocation scoping of the cross-course Grade Now list (Moodle 5.1).
 *
 * Moodle 5.1 keeps ONE marker per student: marking allocation stores it in
 * {assign_user_flags}.allocatedmarker (0 = none), as
 * {@see \assign::get_user_flags()} reads it. There are no marker slots and no
 * per-marker marks, so the question is only whose queue a pending row is in.
 */
final class allocation_scope {
    /**
     * SQL condition: this ledger row is pending for the viewer.
     *
     * The condition passes when any of three arms holds:
     * - the activity does not use marking allocation (marking workflow or
     *   marker allocation is off), so nobody is allocated and the row is
     *   everybody's, as before;
     * - the student has no allocated marker: the coordinator's queue, which
     *   every teacher sees so that nothing waits for an allocation unseen;
     * - the student's allocated marker is the viewer and no grade exists yet
     *   for the row's attempt (a grade row with grade >= 0, the test of
     *   {@see \assign::is_graded()}): once graded, what remains is the release
     *   under marking workflow, which belongs to no marker.
     *
     * Everything is a correlated sub-query, so the condition adds no join to
     * the caller's query and its LIMIT applies to rows that already satisfy
     * it. Every placeholder is used once; the SQL is portable to PostgreSQL
     * and MariaDB.
     *
     * Grade type "None" is not special-cased: such a grade row never carries
     * grade >= 0, so the third arm keeps the row on the allocated marker's list
     * until the row leaves the pending ledger by the grading state's own rule.
     *
     * @param int $viewerid The teacher the list is for.
     * @param string $itemcolumn Column holding the assign id, e.g. "sub.iteminstance".
     * @param string $usercolumn Column holding the student id, e.g. "sub.userid".
     * @param string $attemptcolumn Column holding the attempt number, e.g. "sub.attemptnumber".
     * @param string $prefix Prefix of the inner aliases and placeholders.
     * @return array [SQL condition in parentheses, named parameters].
     */
    public static function pending_for_viewer_sql(
        int $viewerid,
        string $itemcolumn,
        string $usercolumn,
        string $attemptcolumn,
        string $prefix = 'mpv'
    ): array {
        $sql = "(NOT EXISTS (SELECT 1
                               FROM {assign} {$prefix}a
                              WHERE {$prefix}a.id = $itemcolumn
                                AND {$prefix}a.markingworkflow = 1
                                AND {$prefix}a.markingallocation = 1)
                 OR NOT EXISTS (SELECT 1
                                  FROM {assign_user_flags} {$prefix}n
                                 WHERE {$prefix}n.assignment = $itemcolumn
                                   AND {$prefix}n.userid = $usercolumn
                                   AND {$prefix}n.allocatedmarker > 0)
                 OR (EXISTS (SELECT 1
                               FROM {assign_user_flags} {$prefix}f
                              WHERE {$prefix}f.assignment = $itemcolumn
                                AND {$prefix}f.userid = $usercolumn
                                AND {$prefix}f.allocatedmarker = :{$prefix}viewer)
                     AND NOT EXISTS (SELECT 1
                                       FROM {assign_grades} {$prefix}g
                                      WHERE {$prefix}g.assignment = $itemcolumn
                                        AND {$prefix}g.userid = $usercolumn
                                        AND {$prefix}g.attemptnumber = $attemptcolumn
                                        AND {$prefix}g.grade >= 0)))";
        return [$sql, [$prefix . 'viewer' => $viewerid]];
    }
}
