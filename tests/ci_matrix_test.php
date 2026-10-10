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
 * Drift detector between the supported Moodle branches and the CI jobs.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker;

/**
 * version.php declares the Moodle branches the plugin supports, and
 * .github/workflows/ci.yml runs one job per branch. Nothing else ties the two
 * together, so a widened range could ship with a branch no job has ever run.
 *
 * .github is left out of the release archive, so on a site installed from it
 * there is nothing to compare and the test is skipped. On a MOODLE_XX_STABLE
 * branch of this repository ci.yml names no branch (the workflow detects it
 * from the branch name), and the range must then be that one branch.
 *
 * @coversNothing
 */
final class ci_matrix_test extends \basic_testcase {
    /**
     * The branches of $plugin->supported and the branches ci.yml runs are the
     * same set.
     *
     * @return void
     */
    public function test_ci_jobs_cover_exactly_the_supported_branches(): void {
        $workflow = __DIR__ . '/../.github/workflows/ci.yml';
        if (!is_readable($workflow)) {
            $this->markTestSkipped('No .github/workflows/ci.yml: installed from a release archive.');
        }
        [$min, $max] = $this->supported_range();
        $supported = self::branches_between($min, $max);

        preg_match_all(
            '/^\s*moodle-core-branch:\s*[\'"]?MOODLE_(\d+)_STABLE[\'"]?\s*$/m',
            (string) file_get_contents($workflow),
            $matches
        );
        $jobs = array_map('intval', $matches[1]);
        sort($jobs);

        if ($jobs === []) {
            $this->assertSame($min, $max, 'ci.yml names no branch, so it runs one job; version.php supports several.');
            return;
        }
        $this->assertSame($supported, $jobs, 'ci.yml must run one job per branch in $plugin->supported, and no other.');
    }

    /**
     * The branch list a range expands to, which skips the numbers no Moodle
     * release took.
     *
     * @return void
     */
    public function test_a_range_expands_to_real_branches(): void {
        $this->assertSame([405, 500, 501, 502], self::branches_between(405, 502));
        $this->assertSame([501], self::branches_between(501, 501));
        $this->assertSame([403, 404, 405, 500], self::branches_between(403, 500));
    }

    /**
     * $plugin->supported of version.php.
     *
     * @return int[] The lowest and highest branch.
     */
    private function supported_range(): array {
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');
        $this->assertIsArray($plugin->supported ?? null, 'version.php must declare $plugin->supported.');
        $this->assertCount(2, $plugin->supported);
        return array_map('intval', array_values($plugin->supported));
    }

    /**
     * Every Moodle branch number from $min to $max. Moodle 4 ended at 4.5, so
     * 405 is followed by 500; from 5.0 on, each minor release is the next number.
     *
     * @param int $min
     * @param int $max
     * @return int[]
     */
    private static function branches_between(int $min, int $max): array {
        $branches = [];
        for ($branch = $min; $branch <= $max; $branch++) {
            $minor = $branch % 100;
            $major = intdiv($branch, 100);
            if ($minor <= ($major === 4 ? 5 : 9)) {
                $branches[] = $branch;
            }
        }
        return $branches;
    }
}
