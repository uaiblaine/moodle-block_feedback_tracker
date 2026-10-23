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
 * Tests for the block class.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The block's own element carries the stylesheet's scope class.
 */
#[CoversClass(\block_feedback_tracker::class)]
final class block_feedback_tracker_test extends \advanced_testcase {
    /**
     * styles.css applies only inside .block-feedback-tracker, so the block's
     * element must carry it beside the classes core gives every block.
     *
     * @return void
     */
    public function test_the_block_element_carries_the_style_scope(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $record = $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        $block = block_instance('feedback_tracker', $record);

        $classes = explode(' ', $block->html_attributes()['class']);
        // The classes core gives every block are kept, so this is the block's real attribute list.
        $this->assertContains('block_feedback_tracker', $classes);
        $this->assertContains('block', $classes);
        $this->assertContains('block-feedback-tracker', $classes);
    }
}
