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
 * Daily-trend recompute service.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\calendar;

/**
 * Per-(course, group, day) row writer for {block_feedback_tracker_trend},
 * which feeds the sparkline and the other daily series. Called by the daily
 * `recompute_trend` task to materialise yesterday's rows, and by
 * cli/backfill_trends.php.
 */
class trend_service {
    /**
     * Recompute / upsert one trend row.
     *
     * @param int $daydate YYYYMMDD in platform tz.
     * @param int $courseid
     * @param int $groupid
     * @return void
     */
    public static function recompute_for_day(int $daydate, int $courseid, int $groupid): void {
        global $DB;
        [$start, $end] = self::day_bounds($daydate);

        $rows = $DB->get_records_select(
            'block_feedback_tracker_sub',
            'courseid = :courseid AND groupid = :groupid'
                . ' AND timegraded IS NOT NULL AND timegraded >= :start AND timegraded < :end'
                . ' AND submissionstatus = :substatus',
            [
                'courseid' => $courseid,
                'groupid'  => $groupid,
                'start'    => $start,
                'end'      => $end,
                'substatus' => submission_status::SUBMITTED,
            ],
            '',
            'id, effectivehours, waitinghours'
        );

        $count = count($rows);
        $effs = array_map(static fn($r) => (float) ($r->effectivehours ?? 0.0), $rows);
        $raws = array_map(static fn($r) => (float) ($r->waitinghours ?? 0.0), $rows);

        $slagoal = (float) (get_config('block_feedback_tracker', 'sla_goal_hours') ?: 24);
        $compliant = 0;
        foreach ($effs as $h) {
            if ($h <= $slagoal) {
                $compliant++;
            }
        }

        $existing = $DB->get_record(
            'block_feedback_tracker_trend',
            ['courseid' => $courseid, 'groupid' => $groupid, 'day' => $daydate],
            'id'
        );
        $record = (object) [
            'courseid'       => $courseid,
            'groupid'        => $groupid,
            'day'            => $daydate,
            'medianh_eff'    => $count ? stats::median($effs) : null,
            'medianh_raw'    => $count ? stats::median($raws) : null,
            'numgraded'      => $count,
            'compliance_pct' => $count ? round(100.0 * $compliant / $count, 2) : null,
            'timemodified'   => time(),
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('block_feedback_tracker_trend', $record);
        } else {
            $DB->insert_record('block_feedback_tracker_trend', $record);
        }
    }

    /**
     * Recompute every (course, group) that had at least one grading on the
     * given day. Returns the number of (course, group) rows written.
     *
     * @param int $daydate YYYYMMDD.
     * @return int
     */
    public static function recompute_day(int $daydate): int {
        global $DB;
        [$start, $end] = self::day_bounds($daydate);

        $rs = $DB->get_recordset_sql(
            "SELECT DISTINCT courseid, groupid
               FROM {block_feedback_tracker_sub}
              WHERE timegraded IS NOT NULL AND timegraded >= :start AND timegraded < :end
                AND submissionstatus = :substatus",
            ['start' => $start, 'end' => $end, 'substatus' => submission_status::SUBMITTED]
        );
        $count = 0;
        foreach ($rs as $t) {
            self::recompute_for_day($daydate, (int) $t->courseid, (int) $t->groupid);
            $count++;
        }
        $rs->close();
        return $count;
    }

    /**
     * Recompute yesterday's trend rows. Returns the count of rows written.
     *
     * @param int|null $now Override "now"; defaults to time().
     * @return int
     */
    public static function recompute_yesterday(?int $now = null): int {
        $now = $now ?? time();
        $tz = calendar::timezone();
        $yesterday = (new \DateTimeImmutable('@' . $now))
            ->setTimezone($tz)
            ->modify('-1 day');
        $daydate = (int) $yesterday->format('Ymd');
        return self::recompute_day($daydate);
    }

    /**
     * Backfill the last $days days of trend rows in one pass. Useful right
     * after install / reset to populate the sparkline immediately.
     *
     * @param int $days Number of days to backfill (today minus 1 to today minus $days).
     * @param int|null $now Override "now" for tests; defaults to time().
     * @return int Total rows written.
     */
    public static function recompute_last_n_days(int $days, ?int $now = null): int {
        $now = $now ?? time();
        $tz = calendar::timezone();
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0, 0);
        $total = 0;
        for ($i = 1; $i <= $days; $i++) {
            $ymd = (int) $today->modify("-{$i} days")->format('Ymd');
            $total += self::recompute_day($ymd);
        }
        return $total;
    }

    /**
     * Convert a YYYYMMDD to (start_ts, end_ts) for the day in the platform tz.
     *
     * @param int $daydate
     * @return array{0:int, 1:int}
     */
    private static function day_bounds(int $daydate): array {
        $year = (int) substr((string) $daydate, 0, 4);
        $month = (int) substr((string) $daydate, 4, 2);
        $day = (int) substr((string) $daydate, 6, 2);
        $tz = calendar::timezone();
        $start = (new \DateTimeImmutable(
            sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day),
            $tz
        ))->getTimestamp();
        return [$start, $start + 86400];
    }
}
