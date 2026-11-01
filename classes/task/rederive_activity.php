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
 * Adhoc task: re-derive every row of one assign activity.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\process_memos;

/**
 * Queued by the two events that change what an activity's rows mean
 * ({@see \block_feedback_tracker\local\sla\observer::course_module_updated()},
 * {@see \block_feedback_tracker\local\sla\observer::identities_revealed()}),
 * which used to read up to 20,000 rows and queue the backfill tasks inside the
 * teacher's request, and to leave every row past the 20,000th out.
 *
 * The task walks the activity in windows of ids, so it has no ceiling, and
 * hands the rows to {@see backfill_one_submission} in tasks of
 * {@see self::ROWS_PER_TASK}. It is queued with core's duplicate check, so
 * consecutive saves of one activity share one walk. A save made while the
 * walk runs finds it queued and adds nothing, which loses nothing: the walk
 * chooses rows, not settings, and each backfill task reads the activity's
 * settings when it runs. The one setting the walk reads, `teamsubmission`,
 * cannot change once the activity has submissions (mod/assign/mod_form.php
 * freezes it).
 */
class rederive_activity extends \core\task\adhoc_task {
    /** Walk the activity's ledger rows: a settings save changes what they mean. */
    public const SOURCE_LEDGER = 'ledger';

    /** Walk the activity's submissions: revealing identities shows gradings the ledger never saw. */
    public const SOURCE_SUBMISSIONS = 'submissions';

    /** Rows read per query while walking. Protected so a test can walk in small windows. */
    protected const PAGE = 1000;

    /** Rows per backfill task, as the observers queued them. */
    private const ROWS_PER_TASK = 50;

    /**
     * Queue the walk of one activity, once.
     *
     * @param int $cmid The assign course module.
     * @param string $source One of self::SOURCE_*.
     * @return void
     */
    public static function queue(int $cmid, string $source): void {
        $task = new self();
        $task->set_custom_data(['cmid' => $cmid, 'source' => $source]);
        try {
            \core\task\manager::queue_adhoc_task($task, true);
        } catch (\Throwable $e) {
            // An observer must never abort the action that triggered it.
            debugging('block_feedback_tracker: could not queue the re-derivation of cmid ' . $cmid . ': ' . $e->getMessage());
        }
    }

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_rederive_activity', 'block_feedback_tracker');
    }

    /**
     * Walk the activity and queue its rows for the backfill writer.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;
        process_memos::reset();
        $data = $this->get_custom_data();
        $cmid = (int) ($data->cmid ?? 0);
        $source = (string) ($data->source ?? self::SOURCE_LEDGER);
        $activity = $DB->get_record_sql(
            "SELECT cm.course AS courseid, a.id AS assignid, a.teamsubmission
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {assign} a ON a.id = cm.instance AND a.course = cm.course
              WHERE cm.id = :cmid",
            ['modname' => 'assign', 'cmid' => $cmid]
        );
        // The activity or the course may have gone, or the block left the course, since the event.
        if (!$activity || !course_access::is_processable((int) $activity->courseid)) {
            return;
        }
        $courseid = (int) $activity->courseid;

        $buffer = [];
        $seen = [];
        $rows = 0;
        $after = 0;
        do {
            $page = $source === self::SOURCE_SUBMISSIONS
                ? $this->submission_page((int) $activity->assignid, $after)
                : $this->ledger_page($cmid, $after);
            foreach ($page as $row) {
                $after = (int) $row->id;
                $rows++;
                [$key, $descriptor] = self::descriptor($row, $source, (int) $activity->teamsubmission === 1, $cmid, $courseid);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $buffer[] = $descriptor;
                if (count($buffer) >= self::ROWS_PER_TASK) {
                    self::queue_backfill($buffer);
                    $buffer = [];
                }
            }
        } while (count($page) === static::PAGE);
        if ($buffer) {
            self::queue_backfill($buffer);
        }
        mtrace("rederive_activity: cmid {$cmid} ({$source}), {$rows} row(s), " . count($seen) . ' re-derivation(s) queued.');
    }

    /**
     * The next window of the activity's ledger rows.
     *
     * @param int $cmid
     * @param int $after The last id read.
     * @return array
     */
    protected function ledger_page(int $cmid, int $after): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT id, userid, attemptnumber, teamgroupid
               FROM {block_feedback_tracker_sub}
              WHERE cmid = :cmid AND id > :after
           ORDER BY id ASC",
            ['cmid' => $cmid, 'after' => $after],
            0,
            static::PAGE
        ));
    }

    /**
     * The next window of the activity's student submissions.
     *
     * @param int $assignid
     * @param int $after The last id read.
     * @return array
     */
    protected function submission_page(int $assignid, int $after): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT id, userid, attemptnumber
               FROM {assign_submission}
              WHERE assignment = :assignid AND userid > 0 AND id > :after
           ORDER BY id ASC",
            ['assignid' => $assignid, 'after' => $after],
            0,
            static::PAGE
        ));
    }

    /**
     * The backfill descriptor for one row, with the key that de-duplicates it.
     *
     * A ledger row of a team activity becomes one descriptor per (group,
     * attempt), not per member: each team descriptor already fans out to the
     * whole group. The route follows the live `teamsubmission` flag, never the
     * stored `teamgroupid`: mod_assign's default team is group 0, so a team row
     * for it looks exactly like an individual row. Every other row becomes one
     * descriptor per (student, attempt), whatever cycles the ledger holds.
     *
     * @param \stdClass $row A ledger or submission row.
     * @param string $source One of self::SOURCE_*.
     * @param bool $team Whether the activity takes team submissions now.
     * @param int $cmid
     * @param int $courseid
     * @return array [key, descriptor]
     */
    private static function descriptor(\stdClass $row, string $source, bool $team, int $cmid, int $courseid): array {
        $attempt = (int) $row->attemptnumber;
        if ($source === self::SOURCE_LEDGER && $team) {
            $groupid = (int) $row->teamgroupid;
            return ['t' . $groupid . ':' . $attempt, [
                'cmid' => $cmid,
                'userid' => 0,
                'groupid' => $groupid,
                'attemptnumber' => $attempt,
                'courseid' => $courseid,
            ]];
        }
        $userid = (int) $row->userid;
        return ['u' . $userid . ':' . $attempt, [
            'cmid' => $cmid,
            'userid' => $userid,
            'attemptnumber' => $attempt,
            'courseid' => $courseid,
        ]];
    }

    /**
     * Queue one backfill task. Its rows are unique to this walk, so core's
     * duplicate check would never match, and before Moodle 5.3 it would scan
     * {task_adhoc} for each task.
     *
     * @param array $rows Descriptors for backfill_one_submission.
     * @return void
     */
    private static function queue_backfill(array $rows): void {
        $task = new backfill_one_submission();
        $task->set_custom_data(['rows' => $rows]);
        \core\task\manager::queue_adhoc_task($task);
    }
}
