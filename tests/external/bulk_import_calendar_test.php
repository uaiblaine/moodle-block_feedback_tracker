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
 * Tests for the bulk_import_calendar external function + csv_importer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use core_external\external_api;

/**
 * Tests for the bulk_import_calendar external function and CSV importer.
 *
 * @covers \block_feedback_tracker\external\bulk_import_calendar
 * @covers \block_feedback_tracker\local\calendar\csv_importer
 */
final class bulk_import_calendar_test extends \advanced_testcase {
    /**
     * A pasted CSV with mixed valid and malformed rows: valid rows save,
     * malformed rows are reported back with line numbers and reasons.
     */
    public function test_mixed_csv_partial_save_and_per_line_errors(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // A line containing only "; " parses as two empty fields, which is
        // a malformed row (NOT a separator-only line) — so it counts as a
        // 4th error. Comment lines starting with "#" are skipped, as are
        // truly-empty lines.
        $csv = <<<CSV
2026-04-03, holiday, Good Friday
2026-04-06, holiday, Easter Monday
2026-13-99, holiday, Bad date
2026-05-25, holiday, Memorial Day
2026-06-01, banana, Bad type
not a date at all
2026-07-04, recess, Mid-year break
;
# comment line, ignored
CSV;

        $result = bulk_import_calendar::execute($csv);
        $result = external_api::clean_returnvalue(bulk_import_calendar::execute_returns(), $result);

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['saved']);
        $this->assertCount(4, $result['errors']);

        $errorlines = array_column($result['errors'], 'line');
        $this->assertContains(3, $errorlines);
        $this->assertContains(5, $errorlines);
        $this->assertContains(6, $errorlines);
        $this->assertContains(8, $errorlines);

        global $DB;
        $rows = $DB->get_records('block_feedback_tracker_cday', null, 'daydate ASC');
        $this->assertCount(4, $rows);
        $dates = array_map(static fn($r) => (int) $r->daydate, array_values($rows));
        $this->assertSame([20260403, 20260406, 20260525, 20260704], $dates);
    }

    /**
     * Re-importing the same date updates the existing row rather than
     * inserting a duplicate (unique key on daydate).
     */
    public function test_reimport_upserts_existing_dates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        bulk_import_calendar::execute("2026-04-03, holiday, First note");
        bulk_import_calendar::execute("2026-04-03, recess, Second note");

        global $DB;
        $rows = $DB->get_records('block_feedback_tracker_cday', ['daydate' => 20260403]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('recess', $row->daytype);
        $this->assertSame('Second note', $row->note);
    }

    /**
     * A successful import bumps the calver via the cal_day_updated event +
     * calendar observer.
     */
    public function test_calver_bumps_on_save(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('calver', '5', 'block_feedback_tracker');

        $result = bulk_import_calendar::execute("2026-04-03, holiday, X");
        $result = external_api::clean_returnvalue(bulk_import_calendar::execute_returns(), $result);

        $this->assertGreaterThan(5, $result['calver']);
    }

    /**
     * The import is gated by managecalendar at system context; a course role
     * is not a way in.
     *
     * @return void
     */
    public function test_teacher_without_managecalendar_is_refused(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);
        bulk_import_calendar::execute("daydate,daytype\n20260601,holiday\n");
    }
}
