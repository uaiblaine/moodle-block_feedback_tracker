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
 * Template rows for the bulk block-removal list.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

use block_feedback_tracker\local\sla\course_finder;

/**
 * Turns {@see course_finder::candidates()} rows into the `rows` context of
 * templates/bulk_remove.mustache.
 *
 * The template prints every name through a double stash, which escapes it, so
 * names are filtered by format_string() but not escaped: an escaped name would
 * show a course called "A & B" as "A &amp; B". Course names are formatted in
 * the course context and category names in the category context, as core
 * formats them, so a filter switched off for one course stays off here.
 */
final class bulk_remove_rows {
    /**
     * Build the template rows, in the order given.
     *
     * @param array $rows Candidate rows keyed by course id: id, fullname,
     *                    shortname, category, visible, enddate, categoryname
     *                    and ledgerrows.
     * @return array<int, array{courseid:int, fullname:string, shortname:string,
     *               categoryname:string, hidden:bool, enddate:string,
     *               ledgerrows:string, collapsed:bool}>
     */
    public static function for_template(array $rows): array {
        self::preload_contexts($rows);

        $out = [];
        $index = 0;
        foreach ($rows as $row) {
            $courseoptions = ['context' => \context_course::instance((int) $row->id), 'escape' => false];
            $out[] = [
                'courseid' => (int) $row->id,
                'fullname' => format_string((string) $row->fullname, true, $courseoptions),
                'shortname' => format_string((string) $row->shortname, true, $courseoptions),
                'categoryname' => format_string(
                    (string) $row->categoryname,
                    true,
                    ['context' => \context_coursecat::instance((int) $row->category), 'escape' => false]
                ),
                'hidden' => !$row->visible,
                'enddate' => $row->enddate
                    ? userdate((int) $row->enddate, get_string('strftimedateshort', 'langconfig'))
                    : get_string('bulk_noenddate', 'block_feedback_tracker'),
                'ledgerrows' => numfmt::count((int) $row->ledgerrows),
                // Rows past the first page start collapsed; the control reveals them.
                'collapsed' => $index++ >= course_finder::PAGE_SIZE,
            ];
        }
        return $out;
    }

    /**
     * Load the course and category contexts of the rows into the context
     * cache in one query, so formatting a name does not fetch its context
     * alone.
     *
     * @param array $rows Candidate rows; each needs id and category.
     * @return void
     */
    private static function preload_contexts(array $rows): void {
        global $DB;
        if (empty($rows)) {
            return;
        }
        $courseids = [];
        $categoryids = [];
        foreach ($rows as $row) {
            $courseids[(int) $row->id] = true;
            $categoryids[(int) $row->category] = true;
        }
        [$coursesql, $courseparams] = $DB->get_in_or_equal(array_keys($courseids), SQL_PARAMS_NAMED, 'bftc');
        [$catsql, $catparams] = $DB->get_in_or_equal(array_keys($categoryids), SQL_PARAMS_NAMED, 'bftk');
        $params = $courseparams + $catparams + [
            'bftlevelcourse' => CONTEXT_COURSE,
            'bftlevelcat' => CONTEXT_COURSECAT,
        ];
        $contexts = $DB->get_records_select(
            'context',
            "(contextlevel = :bftlevelcourse AND instanceid $coursesql)
             OR (contextlevel = :bftlevelcat AND instanceid $catsql)",
            $params,
            '',
            \context_helper::get_preload_record_columns_sql('{context}')
        );
        foreach ($contexts as $ctx) {
            \context_helper::preload_from_record($ctx);
        }
    }
}
