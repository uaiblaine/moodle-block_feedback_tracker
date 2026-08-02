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
 * Tests for the get_audit_log external function.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\audit\recompute_log;
use core_external\external_api;

/**
 * The course filter is applied after the rows have already been decoded, so
 * the contract this pins is that `total` and the returned page describe the
 * same set: paging to `total` must yield every match exactly once.
 *
 * @covers \block_feedback_tracker\external\get_audit_log
 */
final class get_audit_log_test extends \advanced_testcase {
    /**
     * Record one audit row for a course at a fixed time.
     *
     * Goes through the production writer so the JSON shape of `details` stays
     * whatever the plugin actually writes.
     *
     * @param int $courseid
     * @param int $timestarted
     * @param int|null $actor
     * @return int Row id.
     */
    private function log_for(int $courseid, int $timestarted, ?int $actor = null): int {
        return recompute_log::record('manual', 1, $actor, ['courseid' => $courseid], $timestarted, $timestarted + 1);
    }

    /**
     * Call the web service and clean the return value.
     *
     * @param array $args Named arguments for execute().
     * @return array
     */
    private function call(array $args = []): array {
        $raw = get_audit_log::execute(
            (int) ($args['page'] ?? 0),
            (int) ($args['perpage'] ?? 50),
            (int) ($args['courseid'] ?? 0),
            (int) ($args['actor'] ?? 0)
        );
        return external_api::clean_returnvalue(get_audit_log::execute_returns(), $raw);
    }

    /**
     * With no filter the pager describes the whole table.
     *
     * @return void
     */
    public function test_unfiltered_total_and_paging(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        for ($i = 0; $i < 7; $i++) {
            $this->log_for(1, $now - ($i * 60));
        }

        $first = $this->call(['perpage' => 3]);
        $this->assertSame(7, $first['total']);
        $this->assertCount(3, $first['entries']);

        $last = $this->call(['page' => 2, 'perpage' => 3]);
        $this->assertSame(7, $last['total']);
        $this->assertCount(1, $last['entries']);
    }

    /**
     * The regression: total must count the filtered set, not the unfiltered
     * one. Reporting 8 while returning 3 makes any client pager render empty
     * pages forever.
     *
     * @return void
     */
    public function test_total_reflects_the_course_filter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        for ($i = 0; $i < 5; $i++) {
            $this->log_for(77, $now - ($i * 60));
        }
        for ($i = 0; $i < 3; $i++) {
            $this->log_for(88, $now - (100 + $i) * 60);
        }

        $result = $this->call(['courseid' => 88, 'perpage' => 50]);

        $this->assertSame(3, $result['total'], 'total must count only the rows matching the course filter.');
        $this->assertCount(3, $result['entries']);
        foreach ($result['entries'] as $e) {
            $this->assertSame(88, (int) $e['details_courseid']);
        }
    }

    /**
     * The matches sit entirely outside the first SQL page window, so the old
     * behaviour returned an empty first page while claiming a non-zero total.
     *
     * @return void
     */
    public function test_matches_outside_the_first_window_are_still_returned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        // Ten newer rows for another course fill the first two pages.
        for ($i = 0; $i < 10; $i++) {
            $this->log_for(77, $now - ($i * 60));
        }
        // Three older rows for the course under test.
        for ($i = 0; $i < 3; $i++) {
            $this->log_for(88, $now - (1000 + $i) * 60);
        }

        $result = $this->call(['courseid' => 88, 'perpage' => 5]);

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['entries'], 'The first page must not be empty when matches exist.');
    }

    /**
     * Paging to total yields every match exactly once, with no duplicates and
     * no empty page in the middle.
     *
     * @return void
     */
    public function test_paging_the_filtered_set_is_exhaustive_and_unique(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        $expected = [];
        // Interleave the two courses so no contiguous SQL window holds all matches.
        for ($i = 0; $i < 14; $i++) {
            $courseid = ($i % 2 === 0) ? 88 : 77;
            $id = $this->log_for($courseid, $now - ($i * 60));
            if ($courseid === 88) {
                $expected[] = $id;
            }
        }
        $this->assertCount(7, $expected);

        $seen = [];
        $perpage = 3;
        $total = null;
        for ($page = 0; $page < 5; $page++) {
            $result = $this->call(['courseid' => 88, 'perpage' => $perpage, 'page' => $page]);
            $total ??= $result['total'];
            foreach ($result['entries'] as $e) {
                $seen[] = (int) $e['id'];
            }
        }

        $this->assertSame(7, $total);
        sort($seen);
        sort($expected);
        $this->assertSame($expected, $seen, 'Paging must return each match exactly once.');
    }

    /**
     * The actor filter still applies, and combines with the course filter.
     *
     * @return void
     */
    public function test_actor_filter_combines_with_course_filter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $now = time();

        $this->log_for(88, $now - 60, (int) $one->id);
        $this->log_for(88, $now - 120, (int) $two->id);
        $this->log_for(77, $now - 180, (int) $one->id);

        $result = $this->call(['courseid' => 88, 'actor' => (int) $one->id]);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['entries']);
        $this->assertSame((int) $one->id, (int) $result['entries'][0]['triggeredbyid']);
    }

    /**
     * A filter matching nothing returns an honest zero rather than a total
     * borrowed from the unfiltered set.
     *
     * @return void
     */
    public function test_no_matches_returns_zero_total(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        for ($i = 0; $i < 4; $i++) {
            $this->log_for(77, $now - ($i * 60));
        }

        $result = $this->call(['courseid' => 999]);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['entries']);
    }

    /**
     * Rows carrying no details at all must not be mistaken for a course match.
     *
     * @return void
     */
    public function test_rows_without_details_never_match_a_course_filter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        recompute_log::record('cron', 3, null, null, $now - 60, $now - 59);
        $this->log_for(88, $now - 120);

        $filtered = $this->call(['courseid' => 88]);
        $this->assertSame(1, $filtered['total']);

        $unfiltered = $this->call();
        $this->assertSame(2, $unfiltered['total']);
    }

    /**
     * The endpoint is admin-only.
     *
     * @return void
     */
    public function test_requires_viewaudit_capability(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        get_audit_log::execute(0, 50, 0, 0);
    }
}
