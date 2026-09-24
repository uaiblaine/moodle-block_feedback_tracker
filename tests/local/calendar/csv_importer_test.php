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
 * Tests for the CSV calendar importer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Format + edge-case coverage for the bulk import parser.
 *
 * @covers \block_feedback_tracker\local\calendar\csv_importer
 */
final class csv_importer_test extends \advanced_testcase {
    public function test_blank_and_comment_lines_skipped(): void {
        $this->resetAfterTest();
        $csv = "\n\n# this is a comment\n2026-04-03, holiday\n";
        $result = csv_importer::import($csv, 0);
        $this->assertSame(1, $result['saved']);
        $this->assertSame([], $result['errors']);
    }

    public function test_semicolon_separator_accepted(): void {
        $this->resetAfterTest();
        $result = csv_importer::import('2026-04-03; holiday; Good Friday', 0);
        $this->assertSame(1, $result['saved']);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_cday', ['daydate' => 20260403]);
        $this->assertSame('holiday', $row->daytype);
        $this->assertSame('Good Friday', $row->note);
    }

    public function test_invalid_date_reports_error(): void {
        $this->resetAfterTest();
        $result = csv_importer::import('2026-02-30, holiday', 0);
        $this->assertSame(0, $result['saved']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(1, $result['errors'][0]['line']);
    }

    public function test_unknown_daytype_reports_error(): void {
        $this->resetAfterTest();
        $result = csv_importer::import('2026-04-03, banana', 0);
        $this->assertSame(0, $result['saved']);
        $this->assertCount(1, $result['errors']);
    }

    public function test_missing_fields_reports_error(): void {
        $this->resetAfterTest();
        $result = csv_importer::import('only one field', 0);
        $this->assertSame(0, $result['saved']);
        $this->assertCount(1, $result['errors']);
    }

    public function test_reimport_upserts_same_date(): void {
        $this->resetAfterTest();
        csv_importer::import("2026-04-03, holiday, First", 0);
        csv_importer::import("2026-04-03, recess, Second", 0);

        global $DB;
        $rows = $DB->get_records('block_feedback_tracker_cday', ['daydate' => 20260403]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('recess', $row->daytype);
        $this->assertSame('Second', $row->note);
    }

    public function test_case_insensitive_daytype(): void {
        $this->resetAfterTest();
        $result = csv_importer::import('2026-04-03, HOLIDAY', 0);
        $this->assertSame(1, $result['saved']);
    }

    /**
     * Re-importing a date whose optional row has a sub-day window stores a
     * full-day rule: the CSV format cannot express a window, so the old one
     * must not survive on the updated row.
     *
     * @return void
     */
    public function test_reimport_clears_an_earlier_sub_day_window(): void {
        global $DB;
        $this->resetAfterTest();
        $id = (int) $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate' => 20260403,
            'daytype' => 'optional',
            'starttime' => 960,
            'endtime' => 1080,
            'note' => 'Workshop',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $before = $DB->get_record('block_feedback_tracker_cday', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(960, (int) $before->starttime, 'Precondition: the row starts with a window.');

        $result = csv_importer::import('2026-04-03, optional, Workshop', 0);

        $this->assertSame(1, $result['saved']);
        $rows = $DB->get_records('block_feedback_tracker_cday', ['daydate' => 20260403]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame($id, (int) $row->id, 'The import updates the existing row.');
        $this->assertSame('optional', $row->daytype);
        $this->assertNull($row->starttime);
        $this->assertNull($row->endtime);
    }

    /**
     * The message for a malformed row is the plugin's lang string, so it
     * follows the site's language and its string customisations.
     *
     * @return void
     */
    public function test_malformed_row_message_is_localised(): void {
        global $CFG;
        $this->resetAfterTest();
        // The en_local file is how a customised string (tool_customlang) reaches get_string().
        $dir = $CFG->langlocalroot . '/en_local';
        make_writable_directory($dir);
        $file = $dir . '/block_feedback_tracker.php';
        file_put_contents($file, "<?php\n\$string['caleditor_bulk_error_format'] = 'Custom format hint';\n");
        get_string_manager()->reset_caches();
        try {
            $result = csv_importer::import('not a row', 0);
        } finally {
            unlink($file);
            get_string_manager()->reset_caches();
        }

        $this->assertCount(1, $result['errors']);
        $this->assertSame('Custom format hint', $result['errors'][0]['message']);
    }
}
