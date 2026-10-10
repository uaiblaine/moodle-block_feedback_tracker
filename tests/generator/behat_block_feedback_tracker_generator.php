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
 * Behat data generator for block_feedback_tracker.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_feedback_tracker\local\calendar\calendar;

/**
 * Entities a scenario can create with core's
 * `the following "block_feedback_tracker > <entity>" exist` step.
 */
class behat_block_feedback_tracker_generator extends behat_generator_base {
    /**
     * Get a list of the entities that Behat can create using the generator step.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            // Rows of {block_feedback_tracker_site}, the site-wide daily series.
            'site days' => [
                'singular' => 'site day',
                'datagenerator' => 'site_day',
                'required' => ['daysago'],
            ],
        ];
    }

    /**
     * Insert one {block_feedback_tracker_site} row. The day is given relative to
     * today in the plugin's calendar time zone, the zone
     * get_school_comparison measures its window in, so a scenario's row always
     * falls inside that window. Every figure column is optional.
     *
     * @param array $data daysago, and any of medianh_eff, medianh_raw,
     *                    p10h_eff, p90h_eff, compliance_pct_site, numgraded.
     * @return void
     */
    protected function process_site_day(array $data): void {
        global $DB;
        $day = (int) (new \DateTimeImmutable('@' . time()))
            ->setTimezone(calendar::timezone())
            ->modify('-' . (int) $data['daysago'] . ' day')
            ->format('Ymd');
        $figure = static fn (string $key): ?float => isset($data[$key]) && $data[$key] !== '' ? (float) $data[$key] : null;
        $DB->insert_record('block_feedback_tracker_site', (object) [
            'day' => $day,
            'medianh_eff' => $figure('medianh_eff'),
            'medianh_raw' => $figure('medianh_raw'),
            'p10h_eff' => $figure('p10h_eff'),
            'p90h_eff' => $figure('p90h_eff'),
            'compliance_pct_site' => $figure('compliance_pct_site'),
            'numgraded' => (int) ($data['numgraded'] ?? 0),
            'numgroups' => 0,
            'timemodified' => time(),
        ]);
    }
}
