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
 * Tests for the composed group titles of the responsiveness payload.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\payload;

/**
 * Verifies that titles and subtitles composed from group custom fields are
 * plain text for every field type: no entities, no markup. They reach
 * PARAM_TEXT web service fields and JS text nodes, both of which need the
 * plain spelling.
 *
 * @covers \block_feedback_tracker\local\payload\responsiveness_payload
 */
final class responsiveness_payload_test extends \advanced_testcase {
    /** @var int Id of the group carrying the custom-field values. */
    private int $groupid;

    /**
     * Create one group with a value in each field type core ships: a text
     * field, a text field with a link configured, a select, a textarea, a
     * checkbox, a date without time and a number with a display format.
     *
     * @return void
     */
    private function seed_group_fields(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $category = $generator->create_custom_field_category([
            'component' => 'core_group',
            'area' => 'group',
        ]);
        $categoryid = (int) $category->get('id');
        $generator->create_custom_field([
            'categoryid' => $categoryid,
            'type' => 'text',
            'shortname' => 'room',
        ]);
        $generator->create_custom_field([
            'categoryid' => $categoryid,
            'type' => 'text',
            'shortname' => 'roomlink',
            'configdata' => ['link' => 'https://example.com/rooms/$$', 'linktarget' => '_blank'],
        ]);
        $generator->create_custom_field([
            'categoryid' => $categoryid,
            'type' => 'select',
            'shortname' => 'wing',
            'configdata' => ['options' => "North & South\nEast"],
        ]);
        $generator->create_custom_field([
            'categoryid' => $categoryid,
            'type' => 'textarea',
            'shortname' => 'notes',
            'configdata' => ['defaultvalueformat' => FORMAT_HTML],
        ]);
        $generator->create_custom_field([
            'categoryid' => $categoryid,
            'type' => 'checkbox',
            'shortname' => 'accessible',
        ]);
        $generator->create_custom_field([
            'categoryid' => $categoryid,
            'type' => 'date',
            'shortname' => 'opens',
            'configdata' => ['includetime' => 0],
        ]);
        $generator->create_custom_field([
            'categoryid' => $categoryid,
            'type' => 'number',
            'shortname' => 'floor',
            'configdata' => ['decimalplaces' => 0, 'display' => '{value} & up', 'displaywhenzero' => ''],
        ]);

        $course = $generator->create_course();
        $group = $generator->create_group([
            'courseid' => $course->id,
            'name' => 'Raw name',
            'customfield_room' => 'A & B',
            'customfield_roomlink' => 'C & D',
            // Select values are 1-based indexes into the option list.
            'customfield_wing' => 1,
            'customfield_notes_editor' => ['text' => '<p>Level &amp; 2</p><p>Annex</p>', 'format' => FORMAT_HTML],
            'customfield_accessible' => 1,
            'customfield_opens' => gmmktime(12, 0, 0, 5, 22, 2026),
            'customfield_floor' => 3,
        ]);
        $this->groupid = (int) $group->id;
    }

    /**
     * Resolve the title and subtitle of the seeded group under the given
     * title and subtitle field settings.
     *
     * @param string $titlefields Value for group_title_fields.
     * @param string $subtitlefields Value for group_subtitle_fields.
     * @return array{title: string, subtitle: string|null}
     */
    private function resolve(string $titlefields, string $subtitlefields = ''): array {
        set_config('group_title_fields', $titlefields, 'block_feedback_tracker');
        set_config('group_subtitle_fields', $subtitlefields, 'block_feedback_tracker');
        return responsiveness_payload::resolve_group_titles([$this->groupid => 'Raw name'])[$this->groupid];
    }

    /**
     * A text field's value arrives unescaped.
     *
     * @return void
     */
    public function test_text_field_title_is_plain(): void {
        $this->resetAfterTest();
        $this->seed_group_fields();

        $this->assertSame(['title' => 'A & B', 'subtitle' => null], $this->resolve('room'));
    }

    /**
     * A text field with a link configured gives its text, not the link markup.
     *
     * @return void
     */
    public function test_linked_text_field_title_has_no_markup(): void {
        $this->resetAfterTest();
        $this->seed_group_fields();

        $resolved = $this->resolve('room', 'roomlink');

        $this->assertSame('A & B', $resolved['title']);
        $this->assertSame('C & D', $resolved['subtitle']);
    }

    /**
     * A select field gives its option label, unescaped.
     *
     * @return void
     */
    public function test_select_field_title_is_plain(): void {
        $this->resetAfterTest();
        $this->seed_group_fields();

        $this->assertSame('North & South', $this->resolve('wing')['title']);
    }

    /**
     * A textarea's formatted HTML becomes one line of text.
     *
     * @return void
     */
    public function test_textarea_field_title_is_one_plain_line(): void {
        $this->resetAfterTest();
        $this->seed_group_fields();

        $this->assertSame('Level & 2 Annex', $this->resolve('notes')['title']);
    }

    /**
     * A checkbox gives its Yes/No label, not the stored 1.
     *
     * @return void
     */
    public function test_checkbox_field_title_is_its_label(): void {
        $this->resetAfterTest();
        $this->seed_group_fields();

        $this->assertSame('Yes', $this->resolve('accessible')['title']);
    }

    /**
     * A date gives the day formatted in the user's timezone, not the stored
     * timestamp.
     *
     * @return void
     */
    public function test_date_field_title_is_the_formatted_day(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC');
        $this->seed_group_fields();

        $this->assertSame('22 May 2026', $this->resolve('opens')['title']);
    }

    /**
     * A number gives its display format with the value filled in, unescaped.
     *
     * @return void
     */
    public function test_number_field_title_is_plain(): void {
        $this->resetAfterTest();
        $this->seed_group_fields();

        $this->assertSame('3 & up', $this->resolve('floor')['title']);
    }

    /**
     * Several fields compose with " | ", each converted on its own, and a
     * group name subtitle is shown because the title differs from it.
     *
     * @return void
     */
    public function test_composed_title_joins_plain_values(): void {
        $this->resetAfterTest();
        $this->seed_group_fields();

        $resolved = $this->resolve('room,wing', 'groupname');

        $this->assertSame('A & B | North & South', $resolved['title']);
        $this->assertSame('Raw name', $resolved['subtitle']);
    }

    /**
     * Control: with no field configured the title is the group name as
     * passed in, so the other tests are reading the custom fields.
     *
     * @return void
     */
    public function test_without_fields_the_group_name_is_the_title(): void {
        $this->resetAfterTest();
        $this->seed_group_fields();

        $this->assertSame(['title' => 'Raw name', 'subtitle' => null], $this->resolve(''));
    }
}
