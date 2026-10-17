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
 * Tests for the course_access processing gate.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Covers the two-gate decision: block presence at the course's own
 * context + course visibility (with the process_hidden_courses opt-in).
 */
#[CoversClass(course_access::class)]
final class course_access_test extends \advanced_testcase {
    /**
     * Reset the memo before every test method so prior decisions don't
     * leak between scenarios.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        course_access::reset_memo();
    }

    /**
     * Non-positive courseids short-circuit to false without touching the DB.
     */
    public function test_zero_or_negative_courseid_is_not_processable(): void {
        $this->resetAfterTest();
        $this->assertFalse(course_access::is_processable(0));
        $this->assertFalse(course_access::is_processable(-1));
    }

    /**
     * Course id that doesn't resolve to a row → false.
     */
    public function test_nonexistent_course_is_not_processable(): void {
        $this->resetAfterTest();
        $this->assertFalse(course_access::is_processable(999999));
    }

    /**
     * Visible course + block on its own context → true.
     */
    public function test_visible_course_with_block_is_processable(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['visible' => 1]);
        $this->add_block_to_course($course);
        $this->assertTrue(course_access::is_processable((int) $course->id));
    }

    /**
     * Visible course without the block → false (block-presence gate).
     */
    public function test_visible_course_without_block_is_not_processable(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['visible' => 1]);
        $this->assertFalse(course_access::is_processable((int) $course->id));
    }

    /**
     * Hidden course + block + setting off (default) → false.
     */
    public function test_hidden_course_with_block_defaults_to_not_processable(): void {
        $this->resetAfterTest();
        set_config('process_hidden_courses', '0', 'block_feedback_tracker');
        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $this->add_block_to_course($course);
        $this->assertFalse(course_access::is_processable((int) $course->id));
    }

    /**
     * Hidden course + block + setting on → true.
     */
    public function test_hidden_course_with_block_and_setting_on_is_processable(): void {
        $this->resetAfterTest();
        set_config('process_hidden_courses', '1', 'block_feedback_tracker');
        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $this->add_block_to_course($course);
        $this->assertTrue(course_access::is_processable((int) $course->id));
    }

    /**
     * A block at category context does NOT count — only course-context
     * blocks gate the course. This is the intentional "strict opt-in"
     * semantic; admins drop the block on every course they want tracked.
     */
    public function test_block_at_category_context_does_not_count(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course([
            'visible' => 1,
            'category' => $category->id,
        ]);
        $catctx = \context_coursecat::instance($category->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $catctx->id,
        ]);
        $this->assertFalse(course_access::is_processable((int) $course->id));
    }

    /**
     * Similarly, a system-context block does not count.
     */
    public function test_block_at_system_context_does_not_count(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['visible' => 1]);
        $sysctx = \context_system::instance();
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $sysctx->id,
        ]);
        $this->assertFalse(course_access::is_processable((int) $course->id));
    }

    /**
     * Per-request memo: the second call returns the cached answer even
     * if the underlying state changes mid-request. reset_memo() flushes it.
     */
    public function test_memo_caches_and_can_be_reset(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['visible' => 1]);
        $this->add_block_to_course($course);
        $this->assertTrue(course_access::is_processable((int) $course->id));

        // Delete the block — without a memo reset the helper still says
        // true, proving the previous call was cached.
        global $DB;
        $coursectx = \context_course::instance($course->id);
        $DB->delete_records(
            'block_instances',
            [
                'blockname' => 'feedback_tracker',
                'parentcontextid' => $coursectx->id,
            ]
        );
        $this->assertTrue(
            course_access::is_processable((int) $course->id),
            'Memo should serve the prior result.'
        );

        course_access::reset_memo();
        $this->assertFalse(
            course_access::is_processable((int) $course->id),
            'After reset, the fresh DB state is read.'
        );
    }

    /**
     * processable_course_ids() enumerates exactly the set of courses that
     * pass is_processable(). Excludes hidden courses unless the setting
     * is on, excludes category- and system-context block instances,
     * deduplicates if a course has multiple block instances.
     */
    public function test_processable_course_ids_enumerates_gated_set(): void {
        $this->resetAfterTest();
        set_config('process_hidden_courses', '0', 'block_feedback_tracker');

        // Visible + block → included.
        $coursea = $this->getDataGenerator()->create_course(['visible' => 1]);
        $this->add_block_to_course($coursea);

        // Visible, no block → excluded.
        $courseb = $this->getDataGenerator()->create_course(['visible' => 1]);

        // Hidden + block + setting off → excluded.
        $coursec = $this->getDataGenerator()->create_course(['visible' => 0]);
        $this->add_block_to_course($coursec);

        // Visible + category-context block only → excluded.
        $category = $this->getDataGenerator()->create_category();
        $coursed = $this->getDataGenerator()->create_course([
            'visible' => 1,
            'category' => $category->id,
        ]);
        $catctx = \context_coursecat::instance($category->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $catctx->id,
        ]);

        // Visible + TWO course-context block instances → included once.
        $coursee = $this->getDataGenerator()->create_course(['visible' => 1]);
        $this->add_block_to_course($coursee);
        $this->add_block_to_course($coursee);

        course_access::reset_memo();
        $ids = course_access::processable_course_ids();

        $this->assertSame(
            [(int) $coursea->id, (int) $coursee->id],
            $ids,
            'Only visible courses with a course-context block; deduplicated.'
        );

        // Setting the toggle on includes the hidden course too.
        set_config('process_hidden_courses', '1', 'block_feedback_tracker');
        course_access::reset_memo();
        $idswithhidden = course_access::processable_course_ids();
        $this->assertContains((int) $coursec->id, $idswithhidden);
    }

    /**
     * Calling processable_course_ids() pre-populates the per-courseid
     * memo for the returned ids — subsequent is_processable() calls
     * don't re-query the DB.
     */
    public function test_processable_course_ids_warms_per_id_memo(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['visible' => 1]);
        $this->add_block_to_course($course);

        course_access::reset_memo();
        $ids = course_access::processable_course_ids();
        $this->assertContains((int) $course->id, $ids);

        // Delete the block AFTER enumeration. The per-id memo, warmed
        // by the prior call, still returns true. reset_memo() flushes
        // and a fresh is_processable() returns false.
        global $DB;
        $coursectx = \context_course::instance($course->id);
        $DB->delete_records('block_instances', [
            'blockname' => 'feedback_tracker',
            'parentcontextid' => $coursectx->id,
        ]);

        $this->assertTrue(
            course_access::is_processable((int) $course->id),
            'Memo (warmed by enumeration) should still return true.'
        );
        course_access::reset_memo();
        $this->assertFalse(course_access::is_processable((int) $course->id));
    }

    // Helpers.

    /**
     * Drop a feedback_tracker block instance on the given course's own
     * context.
     *
     * @param \stdClass $course
     * @return void
     */
    private function add_block_to_course(\stdClass $course): void {
        $coursectx = \context_course::instance($course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
    }
}
