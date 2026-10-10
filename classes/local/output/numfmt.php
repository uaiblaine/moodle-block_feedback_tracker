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
 * Locale-aware integer formatter for submission counts.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

/**
 * Groups large submission tallies with the active language's thousands
 * separator so a count reads as "22,000" / "1.232.123" instead of a wall of
 * digits. The separator is read from langconfig ('thousandssep'), so it
 * follows the user's Moodle language — a comma in English, a dot in pt_br.
 *
 * The JS surfaces apply the same grouping client-side (the formatCount helper
 * in amd/src/lib/format.js), fed the same separator through
 * {@see \block_feedback_tracker\local\output\bootstrap::config_bundle()}.
 */
final class numfmt {
    /**
     * Group an integer count with the active language's thousands separator.
     *
     * @param int $n The count to format.
     * @return string The grouped representation, e.g. "1,232,123".
     */
    public static function count(int $n): string {
        return number_format($n, 0, '', get_string('thousandssep', 'langconfig'));
    }
}
