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
 * The course report applies only the latest answer of each request kind.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\lockstep;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The plugin has no JavaScript test runner, so the rules are read from amd/src/views/PendingReportView.js. Answers
 * arrive in any order: an answer for a filter, page, class or mode the viewer has left painted its rows under the
 * new one (docs/performance-audit/PLAN.md, B3). Each fetch takes a ticket and sets state only while it is the
 * latest; drafts are not fetched in graded mode (B6); a mode change drops the old mode's rows, whose columns the new
 * mode does not have.
 */
#[CoversNothing]
final class report_fetch_rules_test extends \advanced_testcase {
    /**
     * The report view's source.
     *
     * @return string
     */
    private function view_source(): string {
        $path = __DIR__ . '/../../amd/src/views/PendingReportView.js';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * The body of a `const <name> = ...{` function or handler, up to its closing line at the view's indent.
     *
     * @param string $src
     * @param string $opening The declaration line, without its indent.
     * @return string
     */
    private function body(string $src, string $opening): string {
        $start = strpos($src, "\n    " . $opening . "\n");
        $this->assertNotFalse($start, "PendingReportView.js no longer declares: {$opening}");
        $end = strpos($src, "\n    };\n", $start);
        $this->assertNotFalse($end);
        return substr($src, $start, $end - $start);
    }

    /**
     * Each fetch, its ticket and the first state it sets from an answer.
     *
     * @return array
     */
    public static function fetch_provider(): array {
        return [
            'table page' => ['const fetchPage = async() => {', 'pageTicket', 'setSubmissions('],
            'drafts' => ['const fetchDrafts = async() => {', 'draftsTicket', 'setDrafts('],
            'academic days' => ['const fetchAcademic = async() => {', 'acaTicket', 'setAcaDays('],
        ];
    }

    /**
     * A fetch drops an answer that is no longer the latest, before it sets any state from it.
     *
     * @param string $opening
     * @param string $ticket
     * @param string $setter
     */
    #[DataProvider('fetch_provider')]
    public function test_a_superseded_answer_is_dropped(string $opening, string $ticket, string $setter): void {
        $body = $this->body($this->view_source(), $opening);
        $this->assertStringContainsString("{$ticket}.current += 1;\n        const ticket = {$ticket}.current;", $body);
        $await = strpos($body, 'await ');
        $guard = strpos($body, "if (ticket !== {$ticket}.current) {\n                return;");
        $set = strpos($body, $setter);
        $this->assertNotFalse($await);
        $this->assertNotFalse($guard, "The answer is applied without checking {$ticket}");
        $this->assertNotFalse($set);
        $this->assertTrue($await < $guard && $guard < $set, 'The ticket is checked between the answer and its first use');
        $this->assertStringContainsString("if (ticket === {$ticket}.current) {", $body, 'The error path checks the ticket too');
    }

    /**
     * Drafts show in pending mode only, so graded mode fetches none.
     */
    public function test_graded_mode_fetches_no_drafts(): void {
        $src = $this->view_source();
        $this->assertMatchesRegularExpression(
            "/if \\(mode === 'graded' \\|\\| draftsGroup\\.current === groupid\\) \\{\\s+return;\\s+\\}\\s+"
                . "fetchDrafts\\(\\);\\s+\\}, \\[groupid, mode\\]\\);/",
            $src
        );
    }

    /**
     * A mode change drops the rows of the mode left and shows the skeleton until the new mode's page lands.
     */
    public function test_a_mode_change_drops_the_old_rows(): void {
        $body = $this->body($this->view_source(), 'const handleModeChange = (m) => {');
        $this->assertStringContainsString('setSubmissions([]);', $body);
        $this->assertStringContainsString('setTotal(0);', $body);
        $this->assertStringContainsString('setLoading(true);', $body);
    }
}
