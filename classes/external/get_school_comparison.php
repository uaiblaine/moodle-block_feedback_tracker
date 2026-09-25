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
 * External: site-wide comparison benchmarks.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\calendar\calendar;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns the last N days of site-wide stats (median, p10, p90, compliance)
 * for the teacher dashboard's site-benchmarks section
 * (amd/src/components/SchoolComparison.js). Pulls from the daily-aggregated
 * {block_feedback_tracker_site} table, not the live ledger.
 */
class get_school_comparison extends external_api {
    /** Default window length. */
    public const DEFAULT_DAYS = 30;
    /** Maximum allowed window. */
    public const MAX_DAYS = 365;
    /** Cache TTL in seconds. */
    public const CACHE_TTL = 3600;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days' => new external_value(PARAM_INT, 'Window length in days', VALUE_DEFAULT, self::DEFAULT_DAYS),
        ]);
    }

    /**
     * Run.
     *
     * @param int $days
     * @return array
     */
    public static function execute(int $days = self::DEFAULT_DAYS): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['days' => $days]);
        $days = max(1, min((int) $params['days'], self::MAX_DAYS));

        $sysctx = \context_system::instance();
        self::validate_context($sysctx);
        require_capability('block/feedback_tracker:viewschoolcomparison', $sysctx);

        $cache = \cache::make('block_feedback_tracker', 'site_comparison');
        $key = calendar::current_version() . '_' . $days;
        $cached = $cache->get($key);
        if (
            $cached !== false && is_array($cached)
            && isset($cached['lastsynced'])
            && (time() - (int) $cached['lastsynced']) < self::CACHE_TTL
        ) {
            return $cached;
        }

        $tz = calendar::timezone();
        $cutoff = (new \DateTimeImmutable('@' . time()))
            ->setTimezone($tz)
            ->modify("-{$days} day");
        $cutoffymd = (int) $cutoff->format('Ymd');

        $rows = $DB->get_records_select(
            'block_feedback_tracker_site',
            'day >= :cutoff',
            ['cutoff' => $cutoffymd],
            'day ASC',
            'id, day, medianh_eff, medianh_raw, p10h_eff, p90h_eff, compliance_pct_site, numgraded'
        );
        $daysout = [];
        foreach ($rows as $r) {
            $daysout[] = [
                'day'                 => (int) $r->day,
                'medianh_eff'         => $r->medianh_eff !== null ? (float) $r->medianh_eff : null,
                'medianh_raw'         => $r->medianh_raw !== null ? (float) $r->medianh_raw : null,
                'p10h_eff'            => $r->p10h_eff !== null ? (float) $r->p10h_eff : null,
                'p90h_eff'            => $r->p90h_eff !== null ? (float) $r->p90h_eff : null,
                'compliance_pct_site' => $r->compliance_pct_site !== null ? (float) $r->compliance_pct_site : null,
                'numgraded'           => (int) $r->numgraded,
            ];
        }

        $result = [
            'success'    => true,
            'days'       => $daysout,
            'lastsynced' => time(),
        ];
        $cache->set($key, $result);
        return $result;
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'    => new external_value(PARAM_BOOL, ''),
            'lastsynced' => new external_value(PARAM_INT, ''),
            'days'       => new external_multiple_structure(new external_single_structure([
                'day'                 => new external_value(PARAM_INT, ''),
                'medianh_eff'         => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'medianh_raw'         => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'p10h_eff'            => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'p90h_eff'            => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'compliance_pct_site' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'numgraded'           => new external_value(PARAM_INT, ''),
            ])),
        ]);
    }
}
