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
 * Tests for the tool_page_viewed access event.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\event;

/**
 * Tests for the tool_page_viewed access event.
 *
 * @covers \block_feedback_tracker\event\tool_page_viewed
 */
final class tool_page_viewed_test extends \advanced_testcase {
    /**
     * An admin tool-page view triggers exactly one read event at the
     * administrative education level, carrying the page slug and a link back
     * to the matching tool page.
     *
     * @return void
     */
    public function test_tool_page_view_is_logged(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = \context_system::instance();

        $event = tool_page_viewed::create([
            'context' => $context,
            'other' => ['page' => 'calendar'],
        ]);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $logged = $events[0];
        $this->assertInstanceOf(tool_page_viewed::class, $logged);
        $this->assertEquals($context, $logged->get_context());
        $this->assertSame('r', $logged->crud);
        $this->assertSame(\core\event\base::LEVEL_OTHER, $logged->edulevel);
        $this->assertSame('calendar', $logged->other['page']);
        $this->assertNotEmpty($logged->get_description());
        $this->assertInstanceOf(\moodle_url::class, $logged->get_url());
        $this->assertStringContainsString('calendar_editor.php', $logged->get_url()->out(false));
    }

    /**
     * An unknown slug — including the legacy 'manage' one left in historic log
     * rows — falls back to the plugin's admin settings page, and the localised
     * event name resolves.
     *
     * @return void
     */
    public function test_manage_default_url_and_localised_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = \context_system::instance();

        $event = tool_page_viewed::create([
            'context' => $context,
            'other' => ['page' => 'manage'],
        ]);

        $this->assertStringContainsString(
            'section=blocksettingfeedback_tracker',
            $event->get_url()->out(false)
        );
        $this->assertNotEmpty(tool_page_viewed::get_name());
    }
}
