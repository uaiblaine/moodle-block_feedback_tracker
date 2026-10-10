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
 * Tests for the calendar editor's set of forms.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\form;

/**
 * The calendar editor renders ten moodleforms on one page. Their element ids
 * must not repeat, each form type posts under its own submit name, and each
 * hours form still posts the weekday it edits.
 *
 * @covers \block_feedback_tracker\form\calendar_editor_forms
 */
final class calendar_editor_forms_test extends \advanced_testcase {
    /**
     * Build the forms the way the page does and render each one.
     *
     * @return array Rendered HTML keyed 'day', 'bulk', 'pause', then 'hours0' to 'hours6'.
     */
    private function render_page_forms(): array {
        global $PAGE;
        $PAGE->set_url('/blocks/feedback_tracker/pages/calendar_editor.php');
        $PAGE->set_context(\context_system::instance());

        $forms = calendar_editor_forms::build($PAGE->url->out(false));
        $html = [
            'day' => $forms['day']->render(),
            'bulk' => $forms['bulk']->render(),
            'pause' => $forms['pause']->render(),
        ];
        foreach ($forms['hours'] as $dow => $form) {
            $html['hours' . $dow] = $form->render();
        }
        return $html;
    }

    /**
     * No id attribute appears twice across the ten rendered forms.
     *
     * @return void
     */
    public function test_element_ids_are_unique_across_the_page(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = implode('', $this->render_page_forms());
        preg_match_all('/\sid="([^"]+)"/', $html, $matches);
        $counts = array_count_values($matches[1]);

        $this->assertArrayHasKey('bft-hours-6', $counts, 'Precondition: all ten forms rendered.');
        $this->assertGreaterThan(40, count($counts), 'Precondition: the forms rendered their elements.');
        $this->assertSame([], array_keys(array_filter($counts, static fn ($n) => $n > 1)));
    }

    /**
     * Each form type submits under its own name, so no two form types post
     * "submitbutton".
     *
     * @return void
     */
    public function test_each_form_type_posts_its_own_submit_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page_forms();

        $expected = ['day' => 'savedaybutton', 'bulk' => 'importbutton', 'pause' => 'savepausebutton'];
        for ($dow = 0; $dow <= 6; $dow++) {
            $expected['hours' . $dow] = 'savehoursbutton';
        }
        foreach ($expected as $key => $name) {
            $this->assertMatchesRegularExpression('~<input[^>]*type="submit"[^>]*name="' . $name . '"~', $html[$key], $key);
            $this->assertStringNotContainsString('name="submitbutton"', $html[$key], $key);
        }
    }

    /**
     * The random ids do not disturb what the hours forms post: each keeps its
     * own form id and the weekday it edits in the dayofweek field.
     *
     * @return void
     */
    public function test_each_hours_form_posts_its_weekday(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page_forms();

        for ($dow = 0; $dow <= 6; $dow++) {
            $form = $html['hours' . $dow];
            $this->assertStringContainsString('id="bft-hours-' . $dow . '"', $form);
            $this->assertSame(1, preg_match('~<input[^>]*name="dayofweek"[^>]*>~', $form, $input), "hours$dow");
            $this->assertStringContainsString('value="' . $dow . '"', $input[0], "hours$dow");
        }
    }
}
