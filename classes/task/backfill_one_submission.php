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
 * Adhoc task: backfill one sub-chunk of historical assign submissions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\participation;
use block_feedback_tracker\local\sla\submission_ledger;

/**
 * Upserts the ledger rows for a batch of submissions.
 *
 * Queued by `backfill_history` (one task per sub-chunk), by
 * `reconcile_ledger`'s repair sweeps and by the observer's bulk
 * re-derivations. Tasks are independent, so the work spreads across cron
 * workers, and idempotent: `submission_ledger::upsert_for_cm_user_attempt()`
 * re-runs cleanly against existing ledger rows.
 *
 * Re-checks `course_access::is_processable()` at execute time so a block
 * removed between dispatch and execute doesn't get a stray ledger row.
 */
class backfill_one_submission extends \core\task\adhoc_task {
    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_backfill_one_submission', 'block_feedback_tracker');
    }

    /**
     * Process the sub-chunk passed in custom data.
     *
     * Custom data shape:
     *   ['rows' => [
     *       ['cmid' => int, 'userid' => int, 'attemptnumber' => int,
     *        'courseid' => int, 'groupid' => int],
     *       ...
     *   ]]
     *
     * `userid` 0 marks a team submission's container row, in which case
     * `groupid` identifies the team and the row is fanned out per member.
     *
     * @return void
     */
    public function execute(): void {
        $data = (array) $this->get_custom_data();
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $courseid = (int) ($row['courseid'] ?? 0);
            if (!course_access::is_processable($courseid)) {
                continue;
            }
            if ((int) ($row['userid'] ?? 0) === 0) {
                /* A team submission's container row: the work is shared by the
                 * group, so it is fanned out to the members rather than
                 * mirrored as a userless ledger entry. */
                submission_ledger::upsert_for_team_attempt(
                    (int) ($row['cmid'] ?? 0),
                    (int) ($row['groupid'] ?? 0),
                    (int) ($row['attemptnumber'] ?? 0)
                );
                continue;
            }
            /* Re-check participation here, not only when the row was selected:
             * this task may run long after it was queued (a failing adhoc task
             * backs off up to a day), by which time
             * reconcile_ledger::sweep_departed_participants() may have deleted
             * this user's rows, and the upsert would recreate them. The team
             * branch above needs no check: upsert_for_team_attempt() resolves
             * its members through get_enrolled_sql() with onlyactive. */
            if (!participation::is_active_participant($courseid, (int) ($row['userid'] ?? 0))) {
                continue;
            }
            submission_ledger::upsert_for_cm_user_attempt(
                (int) ($row['cmid'] ?? 0),
                (int) ($row['userid'] ?? 0),
                (int) ($row['attemptnumber'] ?? 0)
            );
        }
    }
}
