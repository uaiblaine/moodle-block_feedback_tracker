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
 * External: read the merged platform calendar for a date range.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\calendar\business_hours_lookup;
use block_feedback_tracker\local\calendar\calendar;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns the platform calendar's day overrides for a date range plus the
 * weekly business-hours schedule and the calendar settings. Readable with
 * either `:managecalendar` or `:viewdashboard` at system context.
 */
class get_calendar extends external_api {
    /** Most days one call may cover, both ends included: a leap year. */
    public const MAX_SPAN_DAYS = 366;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'startymd' => new external_value(PARAM_INT, 'YYYYMMDD start (inclusive)'),
            'endymd'   => new external_value(PARAM_INT, 'YYYYMMDD end (inclusive)'),
        ]);
    }

    /**
     * Run.
     *
     * @param int $startymd
     * @param int $endymd
     * @return array
     */
    public static function execute(int $startymd, int $endymd): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'startymd' => $startymd, 'endymd' => $endymd,
        ]);
        $start = (int) $params['startymd'];
        $end = (int) $params['endymd'];
        if ($end < $start) {
            throw new \invalid_parameter_exception('endymd must be >= startymd');
        }
        if (self::span_days($start, $end) > self::MAX_SPAN_DAYS) {
            throw new \invalid_parameter_exception('The range may cover at most ' . self::MAX_SPAN_DAYS . ' days');
        }

        $sysctx = \context_system::instance();
        self::validate_context($sysctx);
        if (
            !has_capability('block/feedback_tracker:managecalendar', $sysctx)
            && !has_capability('block/feedback_tracker:viewdashboard', $sysctx)
        ) {
            require_capability('block/feedback_tracker:managecalendar', $sysctx);
        }

        $days = $DB->get_records_select(
            'block_feedback_tracker_cday',
            'daydate >= :s AND daydate <= :e',
            ['s' => $start, 'e' => $end],
            'daydate ASC',
            'id, daydate, daytype, note, usermodified, timemodified'
        );
        $dayrows = [];
        foreach ($days as $d) {
            $dayrows[] = [
                'id'           => (int) $d->id,
                'daydate'      => (int) $d->daydate,
                'daytype'      => (string) $d->daytype,
                'note'         => $d->note !== null ? (string) $d->note : null,
                'timemodified' => (int) $d->timemodified,
            ];
        }

        $hoursrows = [];
        for ($dow = 0; $dow <= 6; $dow++) {
            foreach (business_hours_lookup::for_dayofweek($dow) as $iv) {
                $hoursrows[] = [
                    'dayofweek' => $dow,
                    'starttime' => (int) $iv[0],
                    'endtime'   => (int) $iv[1],
                ];
            }
        }

        return [
            'success'    => true,
            'startymd'   => $start,
            'endymd'     => $end,
            'calver'     => calendar::current_version(),
            'lastsynced' => time(),
            'settings'   => [
                'timezone'                  => (string) (get_config('block_feedback_tracker', 'timezone') ?: 'server'),
                'excludeweekends'           => calendar::excludeweekends() ? 1 : 0,
                'excludeholidays'           => calendar::excludeholidays() ? 1 : 0,
                'excluderecesses'           => calendar::excluderecesses() ? 1 : 0,
                'enablebusinesshours'       => calendar::enablebusinesshours() ? 1 : 0,
                'weekendmask'               => calendar::weekendmask(),
                'grading_during_pause_mode' => calendar::grading_during_pause_mode(),
            ],
            'days'         => $dayrows,
            'businesshours' => $hoursrows,
        ];
    }

    /**
     * Number of days from $start to $end, both included.
     *
     * @param int $start YYYYMMDD, not after $end.
     * @param int $end YYYYMMDD.
     * @return int For example 1 for a single day and 366 for all of 2024.
     * @throws \invalid_parameter_exception When either value is not a real calendar date.
     */
    private static function span_days(int $start, int $end): int {
        $utc = new \DateTimeZone('UTC');
        $dates = [];
        foreach ([$start, $end] as $ymd) {
            $y = intdiv($ymd, 10000);
            $m = intdiv($ymd, 100) % 100;
            $d = $ymd % 100;
            if ($ymd < 10000101 || $ymd > 99991231 || !checkdate($m, $d, $y)) {
                throw new \invalid_parameter_exception('Not a YYYYMMDD date: ' . $ymd);
            }
            $dates[] = (new \DateTimeImmutable('now', $utc))->setDate($y, $m, $d)->setTime(0, 0);
        }
        return (int) $dates[0]->diff($dates[1])->days + 1;
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'    => new external_value(PARAM_BOOL, ''),
            'startymd'   => new external_value(PARAM_INT, ''),
            'endymd'     => new external_value(PARAM_INT, ''),
            'calver'     => new external_value(PARAM_INT, ''),
            'lastsynced' => new external_value(PARAM_INT, ''),
            'settings'   => new external_single_structure([
                'timezone'                  => new external_value(PARAM_TEXT, ''),
                'excludeweekends'           => new external_value(PARAM_INT, ''),
                'excludeholidays'           => new external_value(PARAM_INT, ''),
                'excluderecesses'           => new external_value(PARAM_INT, ''),
                'enablebusinesshours'       => new external_value(PARAM_INT, ''),
                'weekendmask'               => new external_value(PARAM_INT, ''),
                'grading_during_pause_mode' => new external_value(PARAM_ALPHA, ''),
            ]),
            'days' => new external_multiple_structure(new external_single_structure([
                'id'           => new external_value(PARAM_INT, ''),
                'daydate'      => new external_value(PARAM_INT, ''),
                'daytype'      => new external_value(PARAM_ALPHA, ''),
                'note'         => new external_value(PARAM_TEXT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'timemodified' => new external_value(PARAM_INT, ''),
            ])),
            'businesshours' => new external_multiple_structure(new external_single_structure([
                'dayofweek' => new external_value(PARAM_INT, ''),
                'starttime' => new external_value(PARAM_INT, ''),
                'endtime'   => new external_value(PARAM_INT, ''),
            ])),
        ]);
    }
}
