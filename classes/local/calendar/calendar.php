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
 * Platform academic calendar policy & settings.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Static accessors for the platform academic calendar policy.
 *
 * Owns the monotonic `calver` version stamp (bumped on every calendar-affecting
 * write), wraps the platform timezone + weekend definition, and decides whether
 * a given (day-type, weekend) combination should accumulate effective hours.
 */
class calendar {
    /** Default weekend mask: Sat (bit 5) + Sun (bit 6) = 32 + 64 = 96. */
    public const WEEKEND_MASK_DEFAULT = 96;

    /** Day types stored in {block_feedback_tracker_cday}.daytype. A school day is active even on a weekend. */
    public const DAYTYPE_SCHOOLDAY = 'schoolday';
    /** A holiday; inactive while holidays are excluded. */
    public const DAYTYPE_HOLIDAY = 'holiday';
    /** An institutional recess (academic break); inactive while recesses are excluded. */
    public const DAYTYPE_RECESS = 'recess';
    /** A full institutional closure (always inactive). */
    public const DAYTYPE_CLOSED = 'closed';
    /** An optional event: the whole day is inactive, unless the row carries a sub-day window. */
    public const DAYTYPE_OPTIONAL = 'optional';
    /** Implicit (no cday row); active iff not a (excluded) weekend. */
    public const DAYTYPE_IMPLICIT = 'implicit';

    /** Grading-during-pause mode (default): manual pauses subtract from effective hours. */
    public const PAUSE_MODE_CLIPPED = 'clipped';
    /** Manual pauses do not subtract effective hours (audit-only). */
    public const PAUSE_MODE_LIVE = 'live';

    /**
     * Current calendar version. Bumped on every calendar-affecting save.
     *
     * @return int
     */
    public static function current_version(): int {
        $v = get_config('block_feedback_tracker', 'calver');
        return $v === false ? 1 : (int) $v;
    }

    /**
     * Increment and persist the calendar version. Returns the new value.
     *
     * @return int
     */
    public static function bump_version(): int {
        $next = self::current_version() + 1;
        set_config('calver', (string) $next, 'block_feedback_tracker');
        return $next;
    }

    /**
     * Platform timezone. 'server', an empty value or an unknown zone name
     * resolves to Moodle's server timezone.
     *
     * @return \DateTimeZone
     */
    public static function timezone(): \DateTimeZone {
        $tz = (string) (get_config('block_feedback_tracker', 'timezone') ?: 'server');
        if ($tz === 'server' || $tz === '') {
            return \core_date::get_server_timezone_object();
        }
        try {
            return new \DateTimeZone($tz);
        } catch (\Exception $e) {
            return \core_date::get_server_timezone_object();
        }
    }

    /**
     * Weekend mask: bit i set iff dayofweek i is treated as weekend
     * (0=Mon..6=Sun, ISO 8601). 0 or an out-of-range value reads as
     * {@see self::WEEKEND_MASK_DEFAULT}.
     *
     * @return int
     */
    public static function weekendmask(): int {
        $mask = (int) get_config('block_feedback_tracker', 'weekendmask');
        if ($mask <= 0 || $mask >= 128) {
            return self::WEEKEND_MASK_DEFAULT;
        }
        return $mask;
    }

    /**
     * Whether a given ISO dayofweek (0=Mon..6=Sun) is part of the weekend mask.
     *
     * @param int $dayofweek
     * @return bool
     */
    public static function is_weekend(int $dayofweek): bool {
        if ($dayofweek < 0 || $dayofweek > 6) {
            return false;
        }
        return (self::weekendmask() & (1 << $dayofweek)) !== 0;
    }

    /**
     * Check if weekends are excluded.
     *
     * @return bool Whether the platform excludes weekends from effective time.
     */
    public static function excludeweekends(): bool {
        return self::bool_config('excludeweekends', true);
    }

    /**
     * Check if holidays are excluded.
     *
     * @return bool Whether the platform excludes holidays from effective time.
     */
    public static function excludeholidays(): bool {
        return self::bool_config('excludeholidays', true);
    }

    /**
     * Check if recesses are excluded.
     *
     * @return bool Whether the platform excludes recesses from effective time.
     */
    public static function excluderecesses(): bool {
        return self::bool_config('excluderecesses', true);
    }

    /**
     * Check if business hours are enabled.
     *
     * @return bool Whether the weekly business-hours window is applied.
     */
    public static function enablebusinesshours(): bool {
        return self::bool_config('enablebusinesshours', true);
    }

    /**
     * Get the grading during pause mode.
     *
     * @return string One of self::PAUSE_MODE_*; defaults to PAUSE_MODE_CLIPPED.
     */
    public static function grading_during_pause_mode(): string {
        $val = (string) get_config('block_feedback_tracker', 'grading_during_pause_mode');
        return $val === self::PAUSE_MODE_LIVE ? self::PAUSE_MODE_LIVE : self::PAUSE_MODE_CLIPPED;
    }

    /**
     * Localised display label for a daytype slug.
     *
     * Shared by the dropdown in {@see \block_feedback_tracker\form\calendar_day_form},
     * the day list in pages/calendar_editor.php and the upcoming-pause notice,
     * so they always agree.
     *
     * @param string $daytype One of self::DAYTYPE_* values.
     * @return string Localised label, or the raw slug if unrecognised.
     */
    public static function daytype_label(string $daytype): string {
        switch ($daytype) {
            case self::DAYTYPE_SCHOOLDAY:
                return get_string('caleditor_type_schoolday', 'block_feedback_tracker');
            case self::DAYTYPE_HOLIDAY:
                return get_string('caleditor_type_holiday', 'block_feedback_tracker');
            case self::DAYTYPE_RECESS:
                return get_string('caleditor_type_recess', 'block_feedback_tracker');
            case self::DAYTYPE_CLOSED:
                return get_string('caleditor_type_closed', 'block_feedback_tracker');
            case self::DAYTYPE_OPTIONAL:
                return get_string('caleditor_type_optional', 'block_feedback_tracker');
            default:
                return $daytype;
        }
    }

    /**
     * Decide whether a day is "active" for effective-time accumulation.
     *
     * Active days accumulate within their business-hours window; inactive
     * days contribute zero and produce one pause record for the whole day.
     * A sub-day optional event is resolved by {@see day_rule_resolver::for_date()},
     * not here.
     *
     * @param string $daytype One of self::DAYTYPE_*.
     * @param bool $isweekend Whether the day falls in the configured weekend mask.
     * @return bool
     */
    public static function is_active_day(string $daytype, bool $isweekend): bool {
        switch ($daytype) {
            case self::DAYTYPE_SCHOOLDAY:
                return true;
            case self::DAYTYPE_HOLIDAY:
                return !self::excludeholidays();
            case self::DAYTYPE_RECESS:
                return !self::excluderecesses();
            case self::DAYTYPE_CLOSED:
            case self::DAYTYPE_OPTIONAL:
                return false;
            case self::DAYTYPE_IMPLICIT:
            default:
                return !($isweekend && self::excludeweekends());
        }
    }

    /**
     * Helper: read a boolean config, treating "1" / 1 / "on" / "true" as true.
     *
     * @param string $name
     * @param bool $default
     * @return bool
     */
    private static function bool_config(string $name, bool $default): bool {
        $raw = get_config('block_feedback_tracker', $name);
        if ($raw === null || $raw === false) {
            return $default;
        }
        $sval = (string) $raw;
        return $sval === '1' || $sval === 'on' || $sval === 'true';
    }
}
