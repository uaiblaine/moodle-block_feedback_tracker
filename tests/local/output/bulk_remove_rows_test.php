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
 * Tests for the bulk block-removal list rows.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\course_finder;

/**
 * The list prints course, short and category names through Mustache double
 * stashes, so the rows must carry them filtered but unescaped, each formatted
 * in its own context.
 *
 * @covers \block_feedback_tracker\local\output\bulk_remove_rows
 */
final class bulk_remove_rows_test extends \advanced_testcase {
    /**
     * An ampersand in a course, short or category name reaches the template
     * as typed, not as an entity.
     *
     * @return void
     */
    public function test_names_are_plain_text(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category(['name' => 'C & D']);
        $course = $this->course_with_block([
            'fullname' => 'A & B',
            'shortname' => 'S & T',
            'category' => $category->id,
        ]);

        $rows = bulk_remove_rows::for_template(course_finder::candidates([]));

        $this->assertCount(1, $rows);
        $this->assertSame((int) $course->id, $rows[0]['courseid']);
        $this->assertSame('A & B', $rows[0]['fullname']);
        $this->assertSame('S & T', $rows[0]['shortname']);
        $this->assertSame('C & D', $rows[0]['categoryname']);
    }

    /**
     * Rendered through the page's template, the course name is escaped once:
     * the label reads "A &amp; B" in the HTML, never "A &amp;amp; B".
     *
     * @return void
     */
    public function test_template_escapes_the_name_once(): void {
        global $OUTPUT;
        $this->resetAfterTest();
        $course = $this->course_with_block(['fullname' => 'A & B']);

        // The labels are passed because a missing "str" would resolve to core's str helper.
        $html = $OUTPUT->render_from_template('block_feedback_tracker/bulk_remove', [
            'filtered' => true,
            'hasrows' => true,
            'rows' => bulk_remove_rows::for_template(course_finder::candidates([])),
            'str' => ['colcourse' => 'Course'],
        ]);

        $pattern = '~<label for="bftbulk' . (int) $course->id . '">(.*?)</label>~s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'The course row renders its label.');
        $this->assertSame('A &amp; B', $matches[1]);
    }

    /**
     * The checkbox column header names the column's action with the plugin's
     * own visually-hidden class, not Bootstrap 4's sr-only (deprecated on 5.x),
     * and does not repeat the Course header beside it.
     *
     * @return void
     */
    public function test_checkbox_column_header_is_a_hidden_select_label(): void {
        global $OUTPUT;
        $this->resetAfterTest();
        $this->course_with_block();

        $html = $OUTPUT->render_from_template('block_feedback_tracker/bulk_remove', [
            'filtered' => true,
            'hasrows' => true,
            'rows' => bulk_remove_rows::for_template(course_finder::candidates([])),
            'str' => ['colselect' => 'Select', 'colcourse' => 'Course'],
        ]);

        $this->assertSame(1, preg_match('~<thead>\s*<tr>\s*(<th\b.*?</th>)~s', $html, $matches), 'The table has a header row.');
        $this->assertSame('<th scope="col"><span class="bft-sr-only">Select</span></th>', $matches[1]);
        $this->assertNotEmpty(get_string('bulk_col_select', 'block_feedback_tracker'));
    }

    /**
     * Course names are formatted in the course context and category names in
     * the category context: a filter switched off for the course leaves the
     * course name unfiltered, while the category name is still filtered.
     *
     * @return void
     */
    public function test_names_are_formatted_in_their_own_contexts(): void {
        $this->resetAfterTest();
        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_applies_to_strings('multilang', true);
        $multilang = '<span lang="en" class="multilang">A & B</span><span lang="es" class="multilang">C & D</span>';
        $category = $this->getDataGenerator()->create_category(['name' => $multilang]);
        $course = $this->course_with_block(['fullname' => $multilang, 'category' => $category->id]);
        filter_set_local_state('multilang', \context_course::instance($course->id)->id, TEXTFILTER_OFF);
        \filter_manager::reset_caches();

        $rows = bulk_remove_rows::for_template(course_finder::candidates([]));

        $this->assertCount(1, $rows);
        $this->assertSame('A & B', $rows[0]['categoryname'], 'Control: the filter runs at the category.');
        $this->assertSame('A & BC & D', $rows[0]['fullname']);
    }

    /**
     * Rows past the first page start collapsed, in the order given.
     *
     * @return void
     */
    public function test_rows_past_the_first_page_are_collapsed(): void {
        $this->resetAfterTest();
        for ($i = 0; $i <= course_finder::PAGE_SIZE; $i++) {
            $this->course_with_block(['fullname' => sprintf('Course %03d', $i)]);
        }

        $rows = bulk_remove_rows::for_template(course_finder::candidates([]));

        $this->assertCount(course_finder::PAGE_SIZE + 1, $rows);
        $this->assertFalse($rows[course_finder::PAGE_SIZE - 1]['collapsed']);
        $this->assertTrue($rows[course_finder::PAGE_SIZE]['collapsed']);
    }

    /**
     * Create a course carrying the block.
     *
     * @param array $opts Course generator options.
     * @return \stdClass
     */
    private function course_with_block(array $opts = []): \stdClass {
        $course = $this->getDataGenerator()->create_course($opts);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        course_access::reset_memo();
        return $course;
    }
}
