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
 * SLA bucket classifier.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Maps effective hours, or a business-day count, to an SLA bucket using the
 * configured thresholds (defaults 24, 48, 120 hours and 2, 5, 10 days).
 */
class bucket {
    /** Excellent bucket. */
    public const EXCELLENT = 'excellent';
    /** Good bucket. */
    public const GOOD = 'good';
    /** Regular bucket. */
    public const REGULAR = 'regular';
    /** Critical bucket. */
    public const CRITICAL = 'critical';
    /** Pending sentinel — used when effective hours are not yet known. */
    public const PENDING = 'pending';

    /**
     * Classify a value of effective hours into a bucket. Each threshold is an
     * exclusive upper bound: exactly 24 h is good, not excellent.
     *
     * @param float|null $hours
     * @return string One of self::EXCELLENT|GOOD|REGULAR|CRITICAL|PENDING.
     */
    public static function for_effective(?float $hours): string {
        if ($hours === null) {
            return self::PENDING;
        }
        $thresholds = self::parse_thresholds_eff();
        if ($hours < $thresholds[0]) {
            return self::EXCELLENT;
        }
        if ($hours < $thresholds[1]) {
            return self::GOOD;
        }
        if ($hours < $thresholds[2]) {
            return self::REGULAR;
        }
        return self::CRITICAL;
    }

    /**
     * Parse the effective-hours thresholds setting (CSV) into a three-element
     * float array. Each missing or non-numeric element falls back to its own
     * default (24, 48, 120); the values are not sorted.
     *
     * @return array{0:float, 1:float, 2:float}
     */
    public static function parse_thresholds_eff(): array {
        $raw = (string) (get_config('block_feedback_tracker', 'bucket_thresholds_eff') ?: '24,48,120');
        $parts = array_map('trim', explode(',', $raw));
        $t1 = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 24.0;
        $t2 = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : 48.0;
        $t3 = isset($parts[2]) && is_numeric($parts[2]) ? (float) $parts[2] : 120.0;
        return [$t1, $t2, $t3];
    }

    /**
     * Classify an elapsed-business-days count into a bucket. Unlike the hour
     * classifier, boundaries are inclusive: day counts are whole numbers, and
     * "up to 2 days" naturally includes day 2.
     *
     * @param float|null $days Elapsed business days (date-based count).
     * @return string One of self::EXCELLENT|GOOD|REGULAR|CRITICAL|PENDING.
     */
    public static function for_effective_days(?float $days): string {
        if ($days === null) {
            return self::PENDING;
        }
        $thresholds = self::parse_thresholds_days();
        if ($days <= $thresholds[0]) {
            return self::EXCELLENT;
        }
        if ($days <= $thresholds[1]) {
            return self::GOOD;
        }
        if ($days <= $thresholds[2]) {
            return self::REGULAR;
        }
        return self::CRITICAL;
    }

    /**
     * Parse the business-days thresholds setting (CSV) into a three-element
     * float array. Each missing or non-numeric element falls back to its own
     * default (2, 5, 10); the values are not sorted.
     *
     * @return array{0:float, 1:float, 2:float}
     */
    public static function parse_thresholds_days(): array {
        $raw = (string) (get_config('block_feedback_tracker', 'bucket_thresholds_days') ?: '2,5,10');
        $parts = array_map('trim', explode(',', $raw));
        $t1 = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 2.0;
        $t2 = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : 5.0;
        $t3 = isset($parts[2]) && is_numeric($parts[2]) ? (float) $parts[2] : 10.0;
        return [$t1, $t2, $t3];
    }

    /**
     * True when banding should use the business-days ruler, i.e. the global
     * display unit is business days. Every server-side surface reads this one
     * switch so they all classify with the same ruler.
     *
     * @return bool
     */
    public static function use_day_thresholds(): bool {
        return (string) (get_config('block_feedback_tracker', 'display_time_unit') ?: 'hours')
            === 'business_days';
    }
}
