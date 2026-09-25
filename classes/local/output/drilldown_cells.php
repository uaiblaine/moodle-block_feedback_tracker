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
 * Cell text of the group drill-down table.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

use block_feedback_tracker\local\sla\bucket;

/**
 * Builds the wait and status cells of pages/group_drilldown.php.
 *
 * Every value returned is plain text for a double-stash Mustache variable.
 */
final class drilldown_cells {
    /**
     * Text of a wait column (Waiting or Effective) in the display unit.
     *
     * Business days show the whole-day count ("3 d"); hours show one decimal
     * in the user's language ("27.5 h" in English, "27,5 h" in pt_br).
     *
     * @param bool $usedays True when display_time_unit is business_days.
     * @param int $days Day count shown in business-days mode.
     * @param float $hours Hour count shown in hours mode.
     * @return string
     */
    public static function wait(bool $usedays, int $days, float $hours): string {
        if ($usedays) {
            return get_string('drilldown_value_days', 'block_feedback_tracker', $days);
        }
        return get_string('drilldown_value_hours', 'block_feedback_tracker', format_float($hours, 1));
    }

    /**
     * The band slug the status badge is drawn with.
     *
     * A slug outside the five bucket values is shown as pending, the band
     * bucket uses for a row whose wait is not yet known, so the badge always
     * has a colour rule in styles.css (.badge.badge-<slug>) and a label.
     *
     * @param string $slug slabucket from get_pending_submissions.
     * @return string One of the bucket constants.
     */
    public static function band(string $slug): string {
        $known = [bucket::EXCELLENT, bucket::GOOD, bucket::REGULAR, bucket::CRITICAL, bucket::PENDING];
        return in_array($slug, $known, true) ? $slug : bucket::PENDING;
    }

    /**
     * Localised label of a band: the band_* string an administrator can rename.
     *
     * @param string $slug slabucket from get_pending_submissions.
     * @return string
     */
    public static function band_label(string $slug): string {
        return match (self::band($slug)) {
            bucket::EXCELLENT => get_string('band_excellent', 'block_feedback_tracker'),
            bucket::GOOD => get_string('band_good', 'block_feedback_tracker'),
            bucket::REGULAR => get_string('band_regular', 'block_feedback_tracker'),
            bucket::CRITICAL => get_string('band_critical', 'block_feedback_tracker'),
            default => get_string('band_pending', 'block_feedback_tracker'),
        };
    }
}
