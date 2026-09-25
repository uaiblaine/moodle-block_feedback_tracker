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
 * Adhoc task: re-attribute a batch of users' ledger rows to their groups.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\course_access;
use block_feedback_tracker\local\sla\process_memos;
use block_feedback_tracker\local\sla\submission_ledger;

/**
 * Finishes a group change for a batch of users in one course: moves their
 * ledger rows to the group each user reports under now, then re-dates the
 * rows whose governing group override changed.
 *
 * Queued by {@see \block_feedback_tracker\local\sla\observer::group_deleted()}
 * when the deleted group held more users than it handles inside the request,
 * and by the observer's group handlers for one user whose re-dating moves more
 * rows than it writes inside the request. Re-gated on the course at execute
 * time, like the other ledger writers, in case the block went away in between.
 */
class reattribute_users extends \core\task\adhoc_task {
    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_reattribute_users', 'block_feedback_tracker');
    }

    /**
     * Re-attribute and re-date every user in the custom data.
     *
     * Custom data shape: ['courseid' => int, 'userids' => int[]].
     *
     * @return void
     */
    public function execute(): void {
        process_memos::reset();
        $data = (array) $this->get_custom_data();
        $courseid = (int) ($data['courseid'] ?? 0);
        if (!course_access::is_processable($courseid)) {
            return;
        }
        foreach ((array) ($data['userids'] ?? []) as $userid) {
            $userid = (int) $userid;
            if ($userid > 0) {
                submission_ledger::reattribute_user($courseid, $userid);
                submission_ledger::re_resolve_rules_for_group_change($courseid, $userid);
            }
        }
    }
}
