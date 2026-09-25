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
 * Tests for the escaping of the calendar editor template.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

/**
 * pages/calendar_editor.php puts exception messages, which can carry a
 * submitted value, into the notice as plain text. The template must escape
 * the notice and its error lines while it prints the pre-rendered forms raw.
 *
 * @coversNothing
 */
final class calendar_editor_template_test extends \advanced_testcase {
    /** Markup that renders as an element when it is not escaped. */
    private const PROBE = '<img src="x" data-probe="1">';

    /**
     * Render the template around a notice and one form.
     *
     * @param array $notice The notice context.
     * @return string
     */
    private function render_with(array $notice): string {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        $section = [
            'heading' => 'Section',
            'addheading' => 'Add',
            'bulkheading' => 'Bulk',
            'empty' => true,
            'emptytext' => 'Nothing yet.',
            'cols' => ['Column'],
            'rows' => [],
            'addform' => '<form id="bft-form-probe"></form>',
            'bulkform' => '',
        ];
        return $PAGE->get_renderer('core')->render_from_template('block_feedback_tracker/calendar_editor', [
            'heading' => 'Academic calendar editor',
            'notice' => $notice,
            'days' => $section,
            'hours' => ['heading' => 'Hours', 'days' => []],
            'pauses' => $section,
        ]);
    }

    /**
     * The notice text and each error line reach the page escaped; the form,
     * a triple stash in the same template, is printed as markup.
     *
     * @return void
     */
    public function test_notice_is_escaped_and_forms_are_not(): void {
        $this->resetAfterTest();
        $html = $this->render_with([
            'text' => 'Invalid daytype: ' . self::PROBE,
            'level' => 'danger',
            'haserrors' => true,
            'errors' => ['Line 3: ' . self::PROBE],
        ]);

        // Control: the raw sink is live, so an unescaped notice would show.
        $this->assertStringContainsString('<form id="bft-form-probe"></form>', $html);

        $this->assertStringNotContainsString(self::PROBE, $html);
        $this->assertStringContainsString('Invalid daytype: ' . s(self::PROBE), $html);
        $this->assertStringContainsString('<li>Line 3: ' . s(self::PROBE) . '</li>', $html);
    }
}
