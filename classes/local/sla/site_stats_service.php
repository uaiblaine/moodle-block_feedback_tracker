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
 * Site-wide daily stats writer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\calendar;

/**
 * Computes one row in {block_feedback_tracker_site} per day, aggregating
 * every (course, group)'s gradings into site-wide median, p10 (top 10%
 * fastest), p90, and a compliance %. Used by the dashboard's "school
 * comparison" overlay.
 */
class site_stats_service {
    /**
     * Recompute / upsert the site row for one day.
     *
     * @param int $daydate YYYYMMDD.
     * @return void
     */
    public static function recompute_for_day(int $daydate): void {
        global $DB;
        [$start, $end] = self::day_bounds($daydate);

        /* Streamed, not materialised. This reads every graded submission on the
         * site for one day, and the previous get_records_select() held all of
         * them as objects while ALSO building the two float arrays below — on
         * the one query in this plugin with no course scope to bound it, in a
         * daily cron task.
         *
         * The array of record objects goes away on both drivers. The result set
         * behind it only goes away on PostgreSQL, which streams through a
         * server-side cursor; the mysqli driver takes MYSQLI_STORE_RESULT
         * deliberately, because MYSQLI_USE_RESULT would block writes on the
         * tables it holds open. So this is a large win on PostgreSQL and a
         * smaller one on MariaDB — worth knowing before sizing a site from it.
         *
         * The float arrays stay: a median needs every value, and computing it in
         * SQL means percentile_cont on PostgreSQL with no portable MariaDB twin,
         * which the cross-DB rule would require guarding and providing a
         * fallback for. Two arrays of floats is a fraction of what the record
         * objects cost, and trend_service::recompute_day() already reads its
         * analogous set this way. */
        $rs = $DB->get_recordset_select(
            'block_feedback_tracker_sub',
            'timegraded IS NOT NULL AND timegraded >= :start AND timegraded < :end'
                . ' AND submissionstatus = :substatus',
            ['start' => $start, 'end' => $end, 'substatus' => submission_status::SUBMITTED],
            '',
            'courseid, groupid, effectivehours, waitinghours'
        );
        $count = 0;
        $effs = [];
        $raws = [];
        $tuples = [];
        $compliant = 0;
        $slagoal = (float) (get_config('block_feedback_tracker', 'sla_goal_hours') ?: 24);
        foreach ($rs as $r) {
            $count++;
            $eff = (float) ($r->effectivehours ?? 0.0);
            $raw = (float) ($r->waitinghours ?? 0.0);
            $effs[] = $eff;
            $raws[] = $raw;
            $tuples[(int) $r->courseid . ':' . (int) $r->groupid] = true;
            if ($eff <= $slagoal) {
                $compliant++;
            }
        }
        $rs->close();

        $existing = $DB->get_record(
            'block_feedback_tracker_site',
            ['day' => $daydate],
            'id'
        );
        $record = (object) [
            'day'                 => $daydate,
            'medianh_eff'         => $count ? stats::median($effs) : null,
            'medianh_raw'         => $count ? stats::median($raws) : null,
            'p10h_eff'            => $count ? stats::percentile($effs, 10.0) : null,
            'p90h_eff'            => $count ? stats::percentile($effs, 90.0) : null,
            'compliance_pct_site' => $count ? round(100.0 * $compliant / $count, 2) : null,
            'numgraded'           => $count,
            'numgroups'           => count($tuples),
            'timemodified'        => time(),
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('block_feedback_tracker_site', $record);
        } else {
            $DB->insert_record('block_feedback_tracker_site', $record);
        }
    }

    /**
     * Recompute yesterday's site row.
     *
     * @param int|null $now Override "now" for tests; defaults to time().
     * @return void
     */
    public static function recompute_yesterday(?int $now = null): void {
        $now = $now ?? time();
        $tz = calendar::timezone();
        $yesterday = (new \DateTimeImmutable('@' . $now))
            ->setTimezone($tz)
            ->modify('-1 day');
        $daydate = (int) $yesterday->format('Ymd');
        self::recompute_for_day($daydate);
    }

    /**
     * Get the bounds (start and end timestamps) for a given day.
     *
     * @param int $daydate YYYYMMDD.
     * @return array{0:int,1:int}
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
