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
 * Tests for the peer benchmark statistics.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\score;

/**
 * Builds the "how does this group compare" benchmark. Two things carry real
 * consequence: a group is excluded from its own comparison, and the benchmark
 * is suppressed entirely below a minimum sample — otherwise a teacher is being
 * compared against themselves, or against one other group presented as a
 * department norm.
 *
 * @covers \block_feedback_tracker\local\score\peer_stats
 */
final class peer_stats_test extends \advanced_testcase {
    /**
     * Fetch the plugin generator.
     *
     * @return \block_feedback_tracker_generator
     */
    private function generator(): \block_feedback_tracker_generator {
        return $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
    }

    /**
     * Seed rollup rows with the given scores, one group each.
     *
     * @param array $scores Score per group, in order.
     * @param float|null $hours Median effective hours to store on each row.
     * @return array Group ids, aligned with $scores.
     */
    private function seed_groups(array $scores, ?float $hours = 10.0): array {
        peer_stats::reset_memo();
        $ids = [];
        foreach ($scores as $i => $score) {
            $groupid = 500 + $i;
            $this->generator()->create_rollup_row([
                'courseid' => 7000 + $i,
                'groupid' => $groupid,
                'responsiveness_score' => $score,
                'median_eff_h' => $hours,
            ]);
            $ids[] = $groupid;
        }
        peer_stats::reset_memo();
        return $ids;
    }

    /**
     * Below the minimum sample the benchmark is withheld rather than computed
     * from too few peers.
     *
     * @return void
     */
    public function test_small_sample_yields_no_benchmark(): void {
        $this->resetAfterTest();
        $this->seed_groups([80.0, 90.0]);

        $result = peer_stats::for_exclusion(0);

        $this->assertNull($result['department_score']);
        $this->assertNull($result['department_hours']);
        $this->assertNull($result['top10_score']);
        $this->assertNull($result['top10_hours']);
    }

    /**
     * An empty rollup table is the same no-benchmark case, not an error.
     *
     * @return void
     */
    public function test_no_rows_yields_no_benchmark(): void {
        $this->resetAfterTest();
        peer_stats::reset_memo();

        $this->assertNull(peer_stats::for_exclusion(0)['department_score']);
    }

    /**
     * With enough peers the department figure is the median score.
     *
     * @return void
     */
    public function test_department_score_is_the_median(): void {
        $this->resetAfterTest();
        $this->seed_groups([10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0]);

        $result = peer_stats::for_exclusion(0);

        $this->assertNotNull($result['department_score']);
        $this->assertSame(40.0, $result['department_score']);
    }

    /**
     * A group is excluded from the benchmark it is measured against —
     * otherwise the comparison partly reflects the group itself.
     *
     * @return void
     */
    public function test_excluded_group_does_not_shape_its_own_benchmark(): void {
        $this->resetAfterTest();
        // Six peers at 50 plus one outlier; excluding the outlier must move
        // the median off it.
        $ids = $this->seed_groups([50.0, 50.0, 50.0, 50.0, 50.0, 50.0, 100.0]);
        $outlier = end($ids);

        $withall = peer_stats::for_exclusion(0);
        peer_stats::reset_memo();
        $without = peer_stats::for_exclusion($outlier);

        $this->assertSame(50.0, $without['department_score']);
        $this->assertNotNull($withall['department_score']);
    }

    /**
     * Excluding a group can drop the sample below the minimum, and the
     * benchmark is withheld rather than computed from the remainder.
     *
     * @return void
     */
    public function test_exclusion_can_take_the_sample_below_the_minimum(): void {
        $this->resetAfterTest();
        $ids = $this->seed_groups([10.0, 20.0, 30.0]);

        $result = peer_stats::for_exclusion($ids[0]);

        $this->assertNull(
            $result['department_score'],
            'Removing the group under comparison must not leave a benchmark built on too few peers.'
        );
    }

    /**
     * The two "top 10%" figures run in opposite directions, because a high
     * score is good but high hours are bad. Pointing both the same way is the
     * obvious mistake and would praise the slowest groups.
     *
     * @return void
     */
    public function test_top10_uses_opposite_ends_for_score_and_hours(): void {
        $this->resetAfterTest();
        peer_stats::reset_memo();
        foreach ([10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0] as $i => $score) {
            $this->generator()->create_rollup_row([
                'courseid' => 7100 + $i,
                'groupid' => 600 + $i,
                'responsiveness_score' => $score,
                // Hours run opposite to score: the best scorer waits least.
                'median_eff_h' => 80.0 - $score,
            ]);
        }
        peer_stats::reset_memo();

        $result = peer_stats::for_exclusion(0);

        $this->assertGreaterThan(
            $result['department_score'],
            $result['top10_score'],
            'Top-10% score is the high end.'
        );
        $this->assertLessThan(
            $result['department_hours'],
            $result['top10_hours'],
            'Top-10% hours is the LOW end — fewer hours is better.'
        );
    }

    /**
     * Rows without a median-hours value are skipped for the hours benchmark
     * without dragging it toward zero.
     *
     * @return void
     */
    public function test_null_hours_are_excluded_not_counted_as_zero(): void {
        $this->resetAfterTest();
        peer_stats::reset_memo();
        foreach ([10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0] as $i => $score) {
            $this->generator()->create_rollup_row([
                'courseid' => 7200 + $i,
                'groupid' => 700 + $i,
                'responsiveness_score' => $score,
                'median_eff_h' => $i < 2 ? null : 20.0,
            ]);
        }
        peer_stats::reset_memo();

        $result = peer_stats::for_exclusion(0);

        $this->assertSame(
            20.0,
            $result['department_hours'],
            'A null median must be absent from the sample, not counted as 0.'
        );
    }

    /**
     * The result is memoised per exclusion id, and reset_memo() clears it.
     *
     * @return void
     */
    public function test_memo_is_per_exclusion_and_resettable(): void {
        $this->resetAfterTest();
        $this->seed_groups([10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0]);

        $first = peer_stats::for_exclusion(0);
        $this->generator()->create_rollup_row([
            'courseid' => 7999,
            'groupid' => 999,
            'responsiveness_score' => 100.0,
            'median_eff_h' => 1.0,
        ]);

        $this->assertSame($first, peer_stats::for_exclusion(0), 'The memo should still hold.');

        peer_stats::reset_memo();
        $this->assertNotSame(
            $first['department_score'],
            peer_stats::for_exclusion(0)['department_score']
        );
    }
}
