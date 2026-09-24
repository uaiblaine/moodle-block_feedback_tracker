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
 * CSV calendar-day importer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Parses pasted CSV text into {block_feedback_tracker_cday} upserts.
 *
 * Accepted row formats (whitespace tolerant, case-insensitive on the type):
 *   YYYY-MM-DD, daytype
 *   YYYY-MM-DD, daytype, note text
 *
 * Day-types: schoolday | holiday | recess | closed | optional.
 *
 * Row separators: line break. Field separators: comma or semicolon; only the
 * first two split, so a note may itself contain either. Blank lines and lines
 * beginning with `#` are skipped.
 *
 * Per-line errors are reported back rather than aborting the import: valid
 * rows are upserted, malformed rows are returned in the result with line
 * number + reason.
 */
class csv_importer {
    /** Valid daytype slugs. */
    public const VALID_TYPES = [
        calendar::DAYTYPE_SCHOOLDAY,
        calendar::DAYTYPE_HOLIDAY,
        calendar::DAYTYPE_RECESS,
        calendar::DAYTYPE_CLOSED,
        calendar::DAYTYPE_OPTIONAL,
    ];

    /**
     * Parse and upsert. Returns counts plus a list of per-line errors.
     *
     * @param string $csvtext Raw text pasted by the admin.
     * @param int $userid Auditing user id.
     * @param int|null $now Override "now" for tests; defaults to time().
     * @return array{saved:int, errors:array<int, array{line:int, raw:string, message:string}>}
     */
    public static function import(string $csvtext, int $userid, ?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $saved = 0;
        $errors = [];

        $lines = preg_split("/\r\n|\r|\n/", $csvtext);
        $lineno = 0;
        foreach ($lines as $raw) {
            $lineno++;
            $trim = trim($raw);
            if ($trim === '' || strpos($trim, '#') === 0) {
                continue;
            }

            $parsed = self::parse_line($trim);
            if ($parsed === null) {
                $errors[] = [
                    'line'    => $lineno,
                    'raw'     => $raw,
                    'message' => get_string('caleditor_bulk_error_format', 'block_feedback_tracker'),
                ];
                continue;
            }
            [$daydate, $daytype, $note] = $parsed;

            $existing = $DB->get_record('block_feedback_tracker_cday', ['daydate' => $daydate], 'id');
            // A CSV row has no window syntax, so it always stores a full-day rule:
            // the window columns are written as null, or an earlier sub-day window
            // on the same date would survive the re-import. save_calendar_day
            // clears them the same way.
            $record = (object) [
                'daydate'      => $daydate,
                'daytype'      => $daytype,
                'starttime'    => null,
                'endtime'      => null,
                'note'         => $note,
                'usermodified' => $userid > 0 ? $userid : null,
                'timemodified' => $now,
            ];
            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('block_feedback_tracker_cday', $record);
            } else {
                $record->timecreated = $now;
                $DB->insert_record('block_feedback_tracker_cday', $record);
            }
            $saved++;
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * Parse one row into [daydate, daytype, note?] or null on error.
     *
     * @param string $row Already trimmed.
     * @return array{0:int, 1:string, 2:?string}|null
     */
    private static function parse_line(string $row): ?array {
        $parts = preg_split('/[,;]/', $row, 3);
        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }

        $date = trim($parts[0]);
        $type = strtolower(trim($parts[1]));
        $note = isset($parts[2]) ? trim($parts[2]) : null;
        if ($note === '') {
            $note = null;
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            return null;
        }

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return null;
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        $daydate = $year * 10000 + $month * 100 + $day;
        return [$daydate, $type, $note];
    }
}
