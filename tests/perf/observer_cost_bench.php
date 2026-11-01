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
 * Benchmark of what each fan-out event handler costs inside the request.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker;

use block_feedback_tracker\local\calendar\observer as calendar_observer;
use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\observer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/blocks/feedback_tracker/lib.php');

/**
 * Seeds N rows behind each handler that walks a set (the site's rollup tuples,
 * an activity's ledger or submissions, a deleted group's rows), calls the handler
 * with the event core would pass it, and reports the statements, the time and
 * the adhoc tasks it queued, as a table on STDERR.
 *
 * Not a test: the file name does not end in `_test.php`, so the plugin's
 * testsuite never collects it and CI never pays for the 100,000-row seeds. Run
 * it by path, on a stack whose PHPUnit site is initialised:
 *
 *     mdl phpunit m503b blocks/feedback_tracker/tests/perf/observer_cost_bench.php
 *
 * Statements are `perf_get_reads()` + `perf_get_writes()`. On PostgreSQL a
 * recordset counts 3 reads (DECLARE, FETCH, CLOSE) and on MariaDB 1, so
 * compare counts within one database family only. Times depend on the
 * machine; compare them only between runs on the same stack.
 */
#[CoversNothing]
final class observer_cost_bench extends \advanced_testcase {
    /** Rows per insert_records() call, so a 100,000-row seed never sits in memory whole. */
    private const SEED_CHUNK = 5000;

    /** @var array Result rows collected across the cases, printed once at the end. */
    private static array $results = [];

    /** @var \stdClass|null Course of the current case. */
    private ?\stdClass $course = null;

    /** @var \stdClass|null Assign course module of the current case. */
    private ?\stdClass $cm = null;

    /**
     * Every case with each N it is measured at.
     *
     * The per-user handler is measured once: its cost depends on one
     * student, not on a set.
     *
     * @return array
     */
    public static function cases(): array {
        $cases = [];
        $sets = [
            'calendar_day' => [100, 1000, 10000, 100000],
            'calendar_day_again' => [100, 1000, 10000, 100000],
            'pause_site' => [100, 1000, 10000, 100000],
            'pause_course' => [100, 1000, 10000, 100000],
            'settings_invalidate_rollups' => [100, 1000, 10000, 100000],
            'course_module_updated' => [100, 1000, 10000, 100000],
            'identities_revealed' => [100, 1000, 10000, 100000],
            'group_deleted' => [100, 1000, 10000, 100000],
            'group_member_added' => [1],
        ];
        foreach ($sets as $case => $sizes) {
            foreach ($sizes as $n) {
                $cases[$case . ' N=' . $n] = [$case, $n];
            }
        }
        return $cases;
    }

    /**
     * Seed, run and record one case.
     *
     * @param string $case Key of {@see self::cases()}.
     * @param int $n Rows behind the handler.
     * @return void
     */
    #[DataProvider('cases')]
    public function test_cost(string $case, int $n): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker')->seed_default_platform_calendar();

        $run = $this->prepare($case, $n);
        $this->analyze_seeded_tables();

        course_access::reset_memo();
        $tasks = $DB->count_records('task_adhoc');
        $reads = $DB->perf_get_reads();
        $writes = $DB->perf_get_writes();
        $started = hrtime(true);
        $run();
        $ms = (hrtime(true) - $started) / 1e6;
        $reads = $DB->perf_get_reads() - $reads;
        $writes = $DB->perf_get_writes() - $writes;

        /* The bulk handlers stop at 20,000 rows with a debugging notice; it is
         * part of the result, not a failure of the run. */
        $ceiling = false;
        foreach ($this->getDebuggingMessages() as $debug) {
            $ceiling = $ceiling || str_contains($debug->message, 'ceiling');
        }
        $this->resetDebugging();
        $queued = $DB->count_records('task_adhoc') - $tasks;
        [$bgstatements, $bgms] = $this->run_background();
        $rederived = $this->rederived_rows();

        self::$results[] = [
            'case' => $case,
            'n' => $n,
            'reads' => $reads,
            'writes' => $writes,
            'ms' => $ms,
            'tasks' => $queued,
            'ceiling' => $ceiling,
            'bgstatements' => $bgstatements,
            'bgms' => $bgms,
            'rederived' => $rederived,
        ];
        $this->assertGreaterThan(0, $reads + $writes, 'The handler must have done something, or the seed missed it.');
    }

    /**
     * Print the collected table.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void {
        global $DB;
        parent::tearDownAfterClass();
        if (!self::$results) {
            return;
        }
        $lines = [
            '',
            'observer_cost_bench on ' . $DB->get_dbfamily() . ', Moodle ' . $GLOBALS['CFG']->release,
            '| Case | N | Reads | Writes | Statements | ms | Tasks queued | Hit the 20,000-row ceiling '
                . '| Background statements | Background ms | Rows queued for re-derivation |',
            '|---|---:|---:|---:|---:|---:|---:|---|---:|---:|---:|',
        ];
        foreach (self::$results as $r) {
            $lines[] = sprintf(
                '| %s | %d | %d | %d | %d | %.1f | %d | %s | %s | %s | %s |',
                $r['case'],
                $r['n'],
                $r['reads'],
                $r['writes'],
                $r['reads'] + $r['writes'],
                $r['ms'],
                $r['tasks'],
                $r['ceiling'] ? 'yes' : '',
                $r['bgstatements'] === null ? '' : (string) $r['bgstatements'],
                $r['bgms'] === null ? '' : sprintf('%.1f', $r['bgms']),
                $r['rederived'] === null ? '' : (string) $r['rederived']
            );
        }
        fwrite(STDERR, implode("\n", $lines) . "\n");
    }

    /**
     * Refresh the planner's statistics of the seeded tables on PostgreSQL.
     *
     * The seed is written inside the test's transaction, where autovacuum
     * never sees it, so the planner would plan for an empty table and scan
     * all N rows per point lookup that a site answers through an index.
     * ANALYZE is allowed inside a transaction; MariaDB keeps its statistics
     * as it writes.
     *
     * @return void
     */
    private function analyze_seeded_tables(): void {
        global $DB;
        if ($DB->get_dbfamily() !== 'postgres') {
            return;
        }
        foreach (['block_feedback_tracker_sub', 'block_feedback_tracker_group', 'assign_submission'] as $table) {
            $DB->execute('ANALYZE {' . $table . '}');
        }
    }

    /**
     * Run the background tasks that took over a walk from the request, and
     * measure them: the work they do is the work the request no longer does.
     *
     * Only the walks are run ({@see \block_feedback_tracker\task\requeue_rollups},
     * {@see \block_feedback_tracker\task\rederive_activity},
     * {@see \block_feedback_tracker\task\reattribute_users}); the backfill
     * tasks they queue did their work in the background before this change too.
     *
     * @return array [statements, ms], both null when no walk was queued.
     */
    private function run_background(): array {
        global $DB;
        $classnames = [
            '\\' . \block_feedback_tracker\task\requeue_rollups::class,
            '\\' . \block_feedback_tracker\task\rederive_activity::class,
            '\\' . \block_feedback_tracker\task\reattribute_users::class,
        ];
        [$insql, $params] = $DB->get_in_or_equal($classnames);
        if (!$DB->record_exists_select('task_adhoc', "classname $insql", $params)) {
            return [null, null];
        }
        $before = $DB->perf_get_reads() + $DB->perf_get_writes();
        $started = hrtime(true);
        ob_start();
        foreach ($classnames as $classname) {
            $this->runAdhocTasks($classname);
        }
        ob_end_clean();
        $ms = (hrtime(true) - $started) / 1e6;
        return [$DB->perf_get_reads() + $DB->perf_get_writes() - $before, $ms];
    }

    /**
     * Rows the queued backfill tasks will re-derive: the coverage of a walk,
     * which the request-side handlers capped at 20,000.
     *
     * @return int|null Null when no backfill task is queued.
     */
    private function rederived_rows(): ?int {
        global $DB;
        $rows = 0;
        $tasks = $DB->get_recordset(
            'task_adhoc',
            ['classname' => '\\' . \block_feedback_tracker\task\backfill_one_submission::class]
        );
        $found = false;
        foreach ($tasks as $task) {
            $found = true;
            $rows += count((array) (json_decode((string) $task->customdata)->rows ?? []));
        }
        $tasks->close();
        return $found ? $rows : null;
    }

    /**
     * Seed a case and return the call to measure.
     *
     * @param string $case Key of {@see self::cases()}.
     * @param int $n Rows behind the handler.
     * @return callable
     */
    private function prepare(string $case, int $n): callable {
        $syscontext = \context_system::instance();
        switch ($case) {
            case 'calendar_day':
                $this->seed_rollups($n, null);
                $event = \block_feedback_tracker\event\cal_day_updated::create(
                    ['context' => $syscontext, 'other' => ['daytype' => 'holiday']]
                );
                return fn() => calendar_observer::day_updated($event);

            case 'calendar_day_again':
                // A second edit right after the first: every tuple is already queued.
                $this->seed_rollups($n, null);
                $event = \block_feedback_tracker\event\cal_day_updated::create(
                    ['context' => $syscontext, 'other' => ['daytype' => 'holiday']]
                );
                calendar_observer::day_updated($event);
                return fn() => calendar_observer::day_updated($event);

            case 'pause_site':
                $this->seed_rollups($n, null);
                $event = \block_feedback_tracker\event\cal_pause_updated::create(
                    ['context' => $syscontext, 'other' => ['scopelevel' => 'site', 'scopeid' => 0]]
                );
                return fn() => calendar_observer::pause_updated($event);

            case 'pause_course':
                $this->setup_assign();
                $this->seed_rollups($n, (int) $this->course->id);
                $event = \block_feedback_tracker\event\cal_pause_updated::create([
                    'context' => $syscontext,
                    'other' => ['scopelevel' => 'course', 'scopeid' => (int) $this->course->id],
                ]);
                return fn() => calendar_observer::pause_updated($event);

            case 'settings_invalidate_rollups':
                $this->seed_rollups($n, null);
                return fn() => block_feedback_tracker_invalidate_rollups();

            case 'course_module_updated':
                $this->setup_assign();
                $this->seed_ledger($n, 0);
                $event = \core\event\course_module_updated::create([
                    'courseid' => (int) $this->course->id,
                    'context' => \context_module::instance((int) $this->cm->id),
                    'objectid' => (int) $this->cm->id,
                    'other' => ['modulename' => 'assign', 'instanceid' => (int) $this->cm->instance, 'name' => 'Bench'],
                ]);
                return fn() => observer::course_module_updated($event);

            case 'identities_revealed':
                $this->setup_assign();
                $this->seed_submissions($n);
                $context = \context_module::instance((int) $this->cm->id);
                $event = \mod_assign\event\identities_revealed::create_from_assign(
                    new \assign($context, $this->cm, $this->course)
                );
                return fn() => observer::identities_revealed($event);

            case 'group_deleted':
                $this->setup_assign();
                $groupid = (int) $this->getDataGenerator()->create_group(['courseid' => $this->course->id])->id;
                $this->seed_ledger($n, $groupid);
                $event = \core\event\group_deleted::create([
                    'context' => \context_course::instance((int) $this->course->id),
                    'objectid' => $groupid,
                ]);
                return fn() => observer::group_deleted($event);

            case 'group_member_added':
                $this->setup_assign();
                $groupid = (int) $this->getDataGenerator()->create_group(['courseid' => $this->course->id])->id;
                $this->seed_ledger(1, 0);
                $event = \core\event\group_member_added::create([
                    'context' => \context_course::instance((int) $this->course->id),
                    'objectid' => $groupid,
                    'relateduserid' => self::first_userid(),
                    'other' => ['component' => '', 'itemid' => 0],
                ]);
                return fn() => observer::group_membership_changed($event);
        }
        throw new \coding_exception('Unknown case ' . $case);
    }

    /**
     * Create a tracked course with one assign activity.
     *
     * @return void
     */
    private function setup_assign(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $this->course = $generator->create_tracked_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('assign', (int) $assign->id, (int) $this->course->id, false, MUST_EXIST);
    }

    /**
     * The first of the synthetic student ids the seeds use (they need no user row).
     *
     * @return int
     */
    private static function first_userid(): int {
        return 100001;
    }

    /**
     * Insert N rows built one at a time, {@see self::SEED_CHUNK} per call.
     *
     * @param string $table
     * @param int $n
     * @param callable $build Takes the row's index from 0, returns the row as an array.
     * @return void
     */
    private static function insert_chunked(string $table, int $n, callable $build): void {
        global $DB;
        for ($from = 0; $from < $n; $from += self::SEED_CHUNK) {
            $rows = [];
            for ($i = $from; $i < min($n, $from + self::SEED_CHUNK); $i++) {
                $rows[] = $build($i);
            }
            $DB->insert_records($table, $rows);
        }
    }

    /**
     * Insert N rollup tuples, all in one course when one is given.
     *
     * @param int $n
     * @param int|null $courseid Null spreads them over synthetic courses, 100 tuples each.
     * @return void
     */
    private function seed_rollups(int $n, ?int $courseid): void {
        $now = time();
        self::insert_chunked('block_feedback_tracker_group', $n, fn(int $i) => [
            'courseid' => $courseid ?? 500000 + intdiv($i, 100),
            'groupid' => $courseid === null ? $i % 100 : $i,
            'pending' => 0,
            'critical' => 0,
            'overgoal' => 0,
            'numgraded30d' => 0,
            'compliance_pct' => 100.0,
            'median_eff_h' => 0.0,
            'responsiveness_score' => 100.0,
            'score_band' => 'excellent',
            'timerecomputed' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Insert N current ledger rows on the case's assign, one per synthetic student.
     *
     * @param int $n
     * @param int $groupid Reporting group of every row.
     * @return void
     */
    private function seed_ledger(int $n, int $groupid): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $templateid = $generator->create_ledger_row([
            'courseid' => (int) $this->course->id,
            'cmid' => (int) $this->cm->id,
            'iteminstance' => (int) $this->cm->instance,
            'groupid' => $groupid,
        ]);
        $template = (array) $DB->get_record('block_feedback_tracker_sub', ['id' => $templateid]);
        $DB->delete_records('block_feedback_tracker_sub', ['id' => $templateid]);
        unset($template['id']);
        self::insert_chunked('block_feedback_tracker_sub', $n, fn(int $i) => ['userid' => self::first_userid() + $i] + $template);
    }

    /**
     * Insert N submitted assign_submission rows, one per synthetic student.
     *
     * @param int $n
     * @return void
     */
    private function seed_submissions(int $n): void {
        $now = time();
        $assignid = (int) $this->cm->instance;
        self::insert_chunked('assign_submission', $n, fn(int $i) => [
            'assignment' => $assignid,
            'userid' => self::first_userid() + $i,
            'attemptnumber' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'status' => 'submitted',
            'groupid' => 0,
            'latest' => 1,
        ]);
    }
}
