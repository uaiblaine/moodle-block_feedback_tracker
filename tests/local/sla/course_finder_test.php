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
 * Tests for the bulk tool's candidate query.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * The candidate list decides what an administrator is offered for mass
 * removal, so a wrong row here is a course cleared by mistake and a missing
 * row is an archive that never gets tidied.
 *
 * @covers \block_feedback_tracker\local\sla\course_finder
 */
final class course_finder_test extends \advanced_testcase {
    /**
     * Flush the block-presence memo between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        course_access::reset_memo();
    }

    /**
     * Only courses carrying the block are ever offered.
     *
     * @return void
     */
    public function test_only_courses_with_the_block_are_listed(): void {
        $this->resetAfterTest();
        $with = $this->course_with_block(['fullname' => 'Has block']);
        $this->getDataGenerator()->create_course(['fullname' => 'No block']);

        $found = course_finder::candidates([]);

        $this->assertArrayHasKey($with->id, $found);
        $this->assertCount(1, $found);
    }

    /**
     * A hidden course is what an archived one looks like, so the tool must see
     * it — unlike every SLA read path, which deliberately does not.
     *
     * @return void
     */
    public function test_hidden_courses_are_visible_to_the_tool(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->course_with_block();
        $DB->set_field('course', 'visible', 0, ['id' => $course->id]);
        course_access::reset_memo();

        $this->assertFalse(course_access::is_processable((int) $course->id), 'Sanity.');
        $this->assertArrayHasKey(
            $course->id,
            course_finder::candidates([]),
            'The tool exists to clear archived courses; it cannot be blind to them.'
        );
        $this->assertArrayHasKey($course->id, course_finder::candidates(['hiddenonly' => true]));
    }

    /**
     * The three date questions are separate and combinable, because
     * course.enddate is optional and frequently zero — treating "no end date"
     * as a silent fallback for "ended before" would either miss most of an old
     * archive or sweep in courses still running.
     *
     * @return void
     */
    public function test_the_date_filters_are_separate_and_combinable(): void {
        $this->resetAfterTest();
        $now = time();
        $ended = $this->course_with_block([
            'fullname' => 'Ended',
            'startdate' => $now - 400 * DAYSECS,
            'enddate' => $now - 300 * DAYSECS,
        ]);
        $running = $this->course_with_block([
            'fullname' => 'Running',
            'startdate' => $now - 10 * DAYSECS,
            'enddate' => $now + 300 * DAYSECS,
        ]);
        $noend = $this->course_with_block([
            'fullname' => 'No end date',
            'startdate' => $now - 900 * DAYSECS,
            'enddate' => 0,
        ]);

        $cutoff = $now - 100 * DAYSECS;

        // Ended before the cutoff: only the finished one.
        $found = course_finder::candidates(['endedbefore' => $cutoff]);
        $this->assertSame([(int) $ended->id], array_map('intval', array_keys($found)));

        // The open-ended course is only reachable by asking for it.
        $found = course_finder::candidates(['noenddate' => true]);
        $this->assertSame([(int) $noend->id], array_map('intval', array_keys($found)));

        // Combined, the two questions are a union, not a second search.
        $found = course_finder::candidates(['endedbefore' => $cutoff, 'noenddate' => true]);
        $ids = array_map('intval', array_keys($found));
        sort($ids);
        $expected = [(int) $ended->id, (int) $noend->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertNotContains((int) $running->id, $ids);

        // Started before, on its own, reaches the old open-ended course too.
        $found = course_finder::candidates(['startedbefore' => $now - 500 * DAYSECS]);
        $this->assertSame([(int) $noend->id], array_map('intval', array_keys($found)));
    }

    /**
     * The category filter includes descendants — and must not match a sibling
     * whose id merely starts with the same digits. `path` is `/1/3/17`, so a
     * naive LIKE '%/3/%' matches `/1/30/...` as well.
     *
     * @return void
     */
    public function test_the_category_filter_covers_descendants_without_prefix_collisions(): void {
        global $DB;
        $this->resetAfterTest();

        $parent = $this->getDataGenerator()->create_category(['name' => 'Parent']);
        $child = $this->getDataGenerator()->create_category([
            'name' => 'Child',
            'parent' => $parent->id,
        ]);
        $other = $this->getDataGenerator()->create_category(['name' => 'Other']);

        $inparent = $this->course_with_block(['category' => $parent->id]);
        $inchild = $this->course_with_block(['category' => $child->id]);
        $inother = $this->course_with_block(['category' => $other->id]);

        $found = course_finder::candidates(['categoryid' => (int) $parent->id]);
        $ids = array_map('intval', array_keys($found));
        sort($ids);
        $expected = [(int) $inparent->id, (int) $inchild->id];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertNotContains((int) $inother->id, $ids);

        /* Force the prefix collision the naive query would hit: a category
         * whose path literally begins with the target's path but is a
         * different branch — the /1/3 vs /1/30 case.
         *
         * The course is created FIRST and the path forced afterwards, because
         * create_course() runs fix_course_sortorder(), which rebuilds category
         * paths and would quietly undo the collision — leaving a test that
         * asserts nothing and passes against the very bug it names. */
        $collider = $this->getDataGenerator()->create_category(['name' => 'Collider']);
        $incollider = $this->course_with_block(['category' => $collider->id]);
        $parentpath = $DB->get_field('course_categories', 'path', ['id' => $parent->id]);
        $DB->set_field('course_categories', 'path', $parentpath . '9', ['id' => $collider->id]);

        $found = course_finder::candidates(['categoryid' => (int) $parent->id]);
        $this->assertArrayNotHasKey(
            $incollider->id,
            $found,
            'A category whose path merely starts with the target path is a different branch.'
        );
    }

    /**
     * The ledger count travels with each row so the confirmation can state
     * what would actually be lost, not just how many courses were ticked.
     *
     * @return void
     */
    public function test_each_candidate_carries_its_ledger_row_count(): void {
        $this->resetAfterTest();
        $course = $this->course_with_block();
        $gen = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $gen->create_ledger_row(['courseid' => $course->id]);
        $gen->create_ledger_row(['courseid' => $course->id]);

        $found = course_finder::candidates([]);
        $this->assertSame(2, (int) $found[$course->id]->ledgerrows);
    }

    /**
     * A course carrying the block, with the given overrides.
     *
     * @param array $opts Passed to create_course().
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
