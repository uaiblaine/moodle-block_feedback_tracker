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
 * Finds courses carrying the block, for bulk administration.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * The candidate list behind the bulk block-removal tool.
 *
 * Deliberately does NOT go through {@see course_access::is_processable()}.
 * That method excludes hidden courses, and a hidden course is exactly what an
 * archived one looks like — so the tool would be blind to the very courses it
 * exists to clear.
 *
 * Every filter is optional and they combine with AND. `enddate` is the natural
 * way to ask "which courses are over", but it is optional in Moodle and is
 * frequently 0, especially on older courses — which is why "started before"
 * and "has no end date" are separate, combinable questions rather than a
 * silent fallback. A tool that quietly reinterpreted one as the other would
 * either miss most of an old archive or sweep in courses still running.
 */
final class course_finder {
    /** Rows revealed at a time by the "load more" control. */
    public const PAGE_SIZE = 25;

    /**
     * Hard ceiling on one selection.
     *
     * Four pages' worth. Past this the tool asks for a narrower filter rather
     * than paginating: with paging, "select all" acquires two meanings — this
     * page, or every match — and the difference between them is a few hundred
     * courses cleared by accident. A ceiling has one meaning.
     */
    public const MAX_RESULTS = 100;

    /**
     * Courses carrying the block that match the given filters.
     *
     * Recognised filter keys, all optional:
     *  - `endedbefore`   (int) course ended strictly before this instant
     *  - `startedbefore` (int) course started strictly before this instant
     *  - `noenddate`     (bool) include courses with no end date set
     *  - `categoryid`    (int) that category or any descendant of it
     *  - `hiddenonly`    (bool) only courses hidden from students
     *
     * @param array $filters See the description for the recognised keys.
     * @return array Rows of id, fullname, shortname, visible, startdate,
     *               enddate, categoryname and ledgerrows, keyed by course id.
     */
    public static function candidates(array $filters): array {
        global $DB;

        [$wheresql, $params] = self::build_filter($filters);
        if ($wheresql === null) {
            return [];
        }

        /* The ledger count is what makes the confirmation informed: an
         * administrator should see how much measured history each course would
         * eventually lose, not just how many courses they ticked. */
        $sql = "SELECT c.id, c.fullname, c.shortname, c.visible, c.startdate, c.enddate,
                       cat.name AS categoryname,
                       (SELECT COUNT(1)
                          FROM {block_feedback_tracker_sub} l
                         WHERE l.courseid = c.id) AS ledgerrows
                  FROM {course} c
                  JOIN {course_categories} cat ON cat.id = c.category
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :ctxlevel
                  JOIN {block_instances} bi ON bi.parentcontextid = ctx.id
                                           AND bi.blockname = :blockname
                 WHERE $wheresql
              GROUP BY c.id, c.fullname, c.shortname, c.visible, c.startdate, c.enddate, cat.name
              ORDER BY c.fullname ASC";

        return $DB->get_records_sql($sql, $params, 0, self::MAX_RESULTS);
    }

    /**
     * How many courses match, ignoring the ceiling.
     *
     * Reported beside the visible rows so an administrator can tell a complete
     * selection from a truncated one. Without it, a filter matching 340
     * courses and a filter matching 100 look identical on screen.
     *
     * @param array $filters Same keys as {@see self::candidates()}.
     * @return int
     */
    public static function count_candidates(array $filters): int {
        global $DB;

        [$wheresql, $params] = self::build_filter($filters);
        if ($wheresql === null) {
            return 0;
        }
        $sql = "SELECT COUNT(DISTINCT c.id)
                  FROM {course} c
                  JOIN {course_categories} cat ON cat.id = c.category
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :ctxlevel
                  JOIN {block_instances} bi ON bi.parentcontextid = ctx.id
                                           AND bi.blockname = :blockname
                 WHERE $wheresql";
        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * Build the shared WHERE clause and its parameters.
     *
     * One implementation so the list and its total can never disagree — a
     * counter reading 340 beside a list built from a different predicate is
     * worse than no counter at all.
     *
     * @param array $filters Same keys as {@see self::candidates()}.
     * @return array{0:string|null, 1:array} Null clause when the filter can
     *                                       match nothing at all.
     */
    private static function build_filter(array $filters): array {
        global $DB;

        $where = ['c.id <> :siteid'];
        $params = ['siteid' => SITEID, 'blockname' => 'feedback_tracker'];

        $endedbefore = (int) ($filters['endedbefore'] ?? 0);
        $startedbefore = (int) ($filters['startedbefore'] ?? 0);
        $noenddate = !empty($filters['noenddate']);

        /* The three date questions are OR-ed with each other and AND-ed with
         * everything else: an administrator asking for "ended before July, or
         * never had an end date" means one set of courses, not two searches. */
        $dateclauses = [];
        if ($endedbefore > 0) {
            $dateclauses[] = '(c.enddate > 0 AND c.enddate < :endedbefore)';
            $params['endedbefore'] = $endedbefore;
        }
        if ($startedbefore > 0) {
            $dateclauses[] = '(c.startdate > 0 AND c.startdate < :startedbefore)';
            $params['startedbefore'] = $startedbefore;
        }
        if ($noenddate) {
            $dateclauses[] = '(c.enddate = 0)';
        }
        if (!empty($dateclauses)) {
            $where[] = '(' . implode(' OR ', $dateclauses) . ')';
        }

        if (!empty($filters['hiddenonly'])) {
            $where[] = 'c.visible = 0';
        }

        $categoryid = (int) ($filters['categoryid'] ?? 0);
        if ($categoryid > 0) {
            [$catsql, $catparams] = self::category_subtree_clause($categoryid);
            if ($catsql === null) {
                // A category that does not exist matches nothing, not everything.
                return [null, []];
            }
            $where[] = $catsql;
            $params += $catparams;
        }

        $params['ctxlevel'] = CONTEXT_COURSE;
        return [implode(' AND ', $where), $params];
    }

    /**
     * A predicate matching one category and every descendant of it.
     *
     * `{course_categories}.path` looks like `/1/3/17`. The obvious
     * `LIKE '%/3/%'` is wrong twice over: it matches `/1/30/…` because 3 is a
     * prefix of 30, and it matches a category that merely has 3 somewhere in
     * its ancestry rather than under the one asked for. Anchoring on the
     * target's own full path and requiring a trailing slash fixes both.
     *
     * @param int $categoryid
     * @return array{0:string|null, 1:array} Clause and params; clause null when
     *                                       the category does not exist.
     */
    private static function category_subtree_clause(int $categoryid): array {
        global $DB;

        $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid], IGNORE_MISSING);
        if ($path === false) {
            return [null, []];
        }
        $like = $DB->sql_like('cat.path', ':catpath');
        return [
            "(cat.id = :catid OR $like)",
            ['catid' => $categoryid, 'catpath' => $DB->sql_like_escape($path) . '/%'],
        ];
    }
}
