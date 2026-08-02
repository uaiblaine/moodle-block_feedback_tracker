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
 * Tests for business-hours slot validation.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\form;

/**
 * Slot validation is pure logic with no database, so every branch is cheap to
 * pin. The boundary that matters most is that slots which merely touch are
 * legal: 09:00-12:00 followed by 12:00-18:00 is a day without a lunch break,
 * not an overlap.
 *
 * @covers \block_feedback_tracker\form\business_hours_form
 */
final class business_hours_form_test extends \advanced_testcase {
    /**
     * Validate a set of slots.
     *
     * @param array $slots Pairs of [start, end]; null entries leave the field empty.
     * @return array Validation errors.
     */
    private function validate(array $slots): array {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/');

        $data = [];
        foreach ($slots as $i => $pair) {
            $data["start_$i"] = $pair[0] ?? '';
            $data["end_$i"] = $pair[1] ?? '';
        }
        return (new business_hours_form())->validation($data, []);
    }

    /**
     * An entirely empty day is valid — that is how a weekday is marked
     * non-working.
     *
     * @return void
     */
    public function test_empty_day_is_valid(): void {
        $this->assertSame([], $this->validate([[null, null]]));
    }

    /**
     * A well-formed slot passes.
     *
     * @return void
     */
    public function test_single_valid_slot(): void {
        $this->assertSame([], $this->validate([[480, 1080]]));
    }

    /**
     * A start with no end is incomplete, and so is an end with no start.
     *
     * @return void
     */
    public function test_half_filled_slot_is_rejected(): void {
        $this->assertArrayHasKey('slotgroup_0', $this->validate([[480, null]]));
        $this->assertArrayHasKey('slotgroup_0', $this->validate([[null, 1080]]));
    }

    /**
     * A slot ending before it starts is rejected.
     *
     * @return void
     */
    public function test_inverted_slot_is_rejected(): void {
        $this->assertArrayHasKey('slotgroup_0', $this->validate([[900, 600]]));
    }

    /**
     * A zero-length slot is an error too — the guard is <=, not <.
     *
     * @return void
     */
    public function test_zero_length_slot_is_rejected(): void {
        $this->assertArrayHasKey('slotgroup_0', $this->validate([[600, 600]]));
    }

    /**
     * The error is reported against the offending slot, not always the first.
     *
     * @return void
     */
    public function test_error_is_reported_on_the_offending_slot(): void {
        $errors = $this->validate([[480, 720], [900, 600]]);

        $this->assertArrayHasKey('slotgroup_1', $errors);
        $this->assertArrayNotHasKey('slotgroup_0', $errors);
    }

    /**
     * Two overlapping slots are rejected.
     *
     * @return void
     */
    public function test_overlapping_slots_are_rejected(): void {
        $errors = $this->validate([[480, 800], [700, 900]]);

        $this->assertArrayHasKey('slotgroup_0', $errors);
    }

    /**
     * Overlap is detected even when the slots arrive out of order, which is
     * what the sort before the comparison exists for.
     *
     * @return void
     */
    public function test_overlap_is_detected_out_of_order(): void {
        $errors = $this->validate([[700, 900], [480, 800]]);

        $this->assertArrayHasKey('slotgroup_0', $errors);
    }

    /**
     * Slots that merely touch are legal. The comparison is strictly less-than,
     * so a day with no gap between morning and afternoon is allowed — turning
     * it into <= would reject a perfectly normal working day.
     *
     * @return void
     */
    public function test_touching_slots_are_allowed(): void {
        $this->assertSame([], $this->validate([[540, 720], [720, 1080]]));
    }

    /**
     * Three non-overlapping slots in any order are fine.
     *
     * @return void
     */
    public function test_three_disjoint_slots_in_any_order(): void {
        $this->assertSame([], $this->validate([[900, 1080], [480, 600], [660, 800]]));
    }

    /**
     * An empty slot between two filled ones is skipped rather than treated as
     * a zero-length slot at midnight.
     *
     * @return void
     */
    public function test_empty_slot_between_filled_ones_is_skipped(): void {
        $this->assertSame([], $this->validate([[480, 600], [null, null], [700, 900]]));
    }
}
