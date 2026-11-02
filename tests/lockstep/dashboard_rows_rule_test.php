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
 * The teacher dashboard's courses table lays out a bounded number of rows.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\lockstep;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The plugin has no JavaScript test runner, so the rule is read from amd/src/views/DashboardView.js. The table's
 * cost grows with the rows it lays out, so a viewer of hundreds of courses waited on every row for a load and for a
 * sort press (docs/performance-audit/PLAN.md, A1). The view hands the table a slice of the sorted list that starts
 * at DASHBOARD_ROWS_STEP rows and grows by that step on each "Show more".
 */
#[CoversNothing]
final class dashboard_rows_rule_test extends \advanced_testcase {
    /** @var int The most rows a step may add; past a hundred the table's cost is back. */
    private const MAX_STEP = 100;

    /**
     * The dashboard view's source.
     *
     * @return string
     */
    private function view_source(): string {
        $path = __DIR__ . '/../../amd/src/views/DashboardView.js';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * The table is given the visible slice, never the whole sorted list.
     */
    public function test_the_table_gets_the_visible_slice(): void {
        $src = $this->view_source();
        $this->assertSame(
            1,
            preg_match('/<\$\{CoursesTable\}\s+rows=\$\{(\w+)\}/', $src, $m),
            'DashboardView.js no longer renders CoursesTable with a rows prop as its first attribute'
        );
        $this->assertSame('visible', $m[1], 'CoursesTable must get the visible slice, not every course');
        $this->assertMatchesRegularExpression(
            '/const visible = useMemo\(\(\) => sorted\.slice\(0, shown\), \[sorted, shown\]\);/',
            $src,
            'The visible rows must be the first shown rows of the sorted list'
        );
    }

    /**
     * The slice starts at the step and grows by it, and the step stays small.
     */
    public function test_the_slice_starts_and_grows_by_a_bounded_step(): void {
        $src = $this->view_source();
        $this->assertSame(1, preg_match('/^const DASHBOARD_ROWS_STEP = (\d+);$/m', $src, $m));
        $step = (int) $m[1];
        $this->assertGreaterThan(0, $step);
        $this->assertLessThanOrEqual(self::MAX_STEP, $step, 'A step this large puts the table\'s cost back');
        $this->assertStringContainsString('const [shown, setShown] = useState(DASHBOARD_ROWS_STEP);', $src);
        $this->assertStringContainsString('setShown(shown + DASHBOARD_ROWS_STEP);', $src);
    }
}
