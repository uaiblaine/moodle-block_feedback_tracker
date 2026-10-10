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
 * Tests for the report_viewed access event.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\event;

/**
 * Tests for the report_viewed access event.
 *
 * @covers \block_feedback_tracker\event\report_viewed
 */
final class report_viewed_test extends \advanced_testcase {
    /**
     * A course-scoped report view triggers exactly one read event carrying
     * the report type, group scope and course context, and links back to the
     * pending-report page.
     *
     * @return void
     */
    public function test_pending_report_view_is_logged(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $this->setAdminUser();

        $event = report_viewed::create([
            'context' => $context,
            'courseid' => (int) $course->id,
            'other' => ['report' => 'pending', 'groupid' => 0],
        ]);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $logged = $events[0];
        $this->assertInstanceOf(report_viewed::class, $logged);
        $this->assertEquals($context, $logged->get_context());
        $this->assertEquals((int) $course->id, (int) $logged->courseid);
        $this->assertSame('r', $logged->crud);
        $this->assertSame(\core\event\base::LEVEL_PARTICIPATING, $logged->edulevel);
        $this->assertSame('pending', $logged->other['report']);
        $this->assertNotEmpty($logged->get_description());
        $this->assertInstanceOf(\moodle_url::class, $logged->get_url());
        $this->assertStringContainsString('pending_report.php', $logged->get_url()->out(false));
    }

    /**
     * The cross-course dashboard view logs at system context with no course
     * and links back to the dashboard page.
     *
     * @return void
     */
    public function test_dashboard_view_is_logged_at_system_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = \context_system::instance();

        $event = report_viewed::create([
            'context' => $context,
            'other' => ['report' => 'dashboard'],
        ]);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(report_viewed::class, $events[0]);
        $this->assertEquals($context, $events[0]->get_context());
        $this->assertStringContainsString('teacher_dashboard.php', $events[0]->get_url()->out(false));
    }

    /**
     * The drill-down report type maps to its own page URL.
     *
     * @return void
     */
    public function test_drilldown_view_links_to_drilldown_page(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $this->setAdminUser();

        $event = report_viewed::create([
            'context' => $context,
            'courseid' => (int) $course->id,
            'other' => ['report' => 'drilldown', 'groupid' => 0],
        ]);

        $this->assertStringContainsString('group_drilldown.php', $event->get_url()->out(false));
        $this->assertNotEmpty(report_viewed::get_name());
    }
}
