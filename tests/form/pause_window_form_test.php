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
 * Tests for pause-window validation.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\form;

/**
 * Two rules, both with a value that means something other than itself: a site
 * scope legitimately has no id, and a zero end time means open-ended rather
 * than "ends at the epoch".
 *
 * @covers \block_feedback_tracker\form\pause_window_form
 */
final class pause_window_form_test extends \advanced_testcase {
    /**
     * Validate a pause definition.
     *
     * @param array $data Overrides for scopelevel, scopeid, timestart, timeend.
     * @return array Validation errors.
     */
    private function validate(array $data): array {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/');

        $now = time();
        $defaults = [
            'scopelevel' => 'site',
            'scopeid' => 0,
            'timestart' => $now,
            'timeend' => $now + 3600,
        ];
        return (new pause_window_form())->validation(array_merge($defaults, $data), []);
    }

    /**
     * A site pause needs no scope id — that is what site scope means.
     *
     * @return void
     */
    public function test_site_scope_needs_no_id(): void {
        $this->assertSame([], $this->validate(['scopelevel' => 'site', 'scopeid' => 0]));
    }

    /**
     * A course or group pause without an id is incomplete.
     *
     * @return void
     */
    public function test_course_and_group_scopes_require_an_id(): void {
        $this->assertArrayHasKey('scopeid', $this->validate(['scopelevel' => 'course', 'scopeid' => 0]));
        $this->assertArrayHasKey('scopeid', $this->validate(['scopelevel' => 'group', 'scopeid' => 0]));
        $this->assertArrayHasKey('scopeid', $this->validate(['scopelevel' => 'course', 'scopeid' => -5]));
    }

    /**
     * With an id supplied, a scoped pause passes.
     *
     * @return void
     */
    public function test_scoped_pause_with_an_id(): void {
        $this->assertSame([], $this->validate(['scopelevel' => 'course', 'scopeid' => 42]));
        $this->assertSame([], $this->validate(['scopelevel' => 'group', 'scopeid' => 7]));
    }

    /**
     * A window that ends before it starts is rejected, and equal timestamps
     * count as an error too.
     *
     * @return void
     */
    public function test_end_must_be_after_start(): void {
        $now = time();
        $this->assertArrayHasKey('timeend', $this->validate([
            'timestart' => $now, 'timeend' => $now - 60,
        ]));
        $this->assertArrayHasKey('timeend', $this->validate([
            'timestart' => $now, 'timeend' => $now,
        ]));
    }

    /**
     * Zero means open-ended, not "ends at the epoch". Treating it as a
     * timestamp would reject every open-ended pause, which is the normal way
     * to record a closure with no announced end.
     *
     * @return void
     */
    public function test_zero_end_time_means_open_ended(): void {
        $this->assertSame([], $this->validate(['timestart' => time(), 'timeend' => 0]));
        $this->assertSame([], $this->validate(['timestart' => 2000000000, 'timeend' => 0]));
    }

    /**
     * A normal bounded window passes.
     *
     * @return void
     */
    public function test_bounded_window_passes(): void {
        $now = time();
        $this->assertSame([], $this->validate(['timestart' => $now, 'timeend' => $now + 86400]));
    }
}
