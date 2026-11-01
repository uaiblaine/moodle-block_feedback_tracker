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
 * Event observer registrations.
 *
 * Assign, gradebook, course, enrolment, user and group events go to the SLA
 * observer, and the plugin's three calendar events to the calendar observer.
 * The SLA observer keeps the ledger in step, dispatching bulk re-derivations
 * as adhoc tasks; rollups are recomputed out of band. Why each event matters
 * is documented on its handler in {@see \block_feedback_tracker\local\sla\observer}.
 *
 * Every handler runs inside the request of whoever caused the event, so its
 * cost may depend on one person's rows or on the groups it touches, never on
 * how many rows an activity, a course, a group or the site holds: a walk over
 * those is queued as an adhoc task. A handler added here follows that
 * contract, set out in the plugin's CLAUDE.md ("Observer cost contract"), and
 * a bulk one gets a case in tests/local/sla/observer_cost_test.php or
 * tests/local/calendar/observer_cost_test.php.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    // Submission state changes.
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_changed',
    ],
    [
        'eventname' => '\mod_assign\event\submission_status_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_changed',
    ],
    [
        'eventname' => '\assignsubmission_onlinetext\event\submission_created',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_changed',
    ],
    [
        'eventname' => '\assignsubmission_file\event\submission_created',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_changed',
    ],
    /* Edits to an existing submission. With submissiondrafts on, saving fires
     * no assessable_submitted, so without these the ledger keeps a stale
     * hand-in time. Do not register \mod_assign\event\submission_created or
     * \mod_assign\event\submission_updated: both are abstract base classes
     * that core never instantiates. */
    [
        'eventname' => '\assignsubmission_onlinetext\event\submission_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_changed',
    ],
    [
        'eventname' => '\assignsubmission_file\event\submission_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_changed',
    ],
    [
        'eventname' => '\mod_assign\event\submission_removed',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_changed',
    ],
    [
        'eventname' => '\mod_assign\event\submission_duplicated',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_changed',
    ],

    // Grading.
    [
        'eventname' => '\mod_assign\event\submission_graded',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_graded',
    ],
    /* Grades entered in the gradebook, which fire no assign event. While a
     * gradebook override or lock stands, assign::grading_disabled() also stops
     * submission_graded for that student. grade_deleted is deliberately not
     * registered; see observer::gradebook_changed(). */
    [
        'eventname' => '\core\event\user_graded',
        'callback' => '\block_feedback_tracker\local\sla\observer::gradebook_changed',
    ],
    /* Marking workflow: the release is recorded nowhere else. See
     * observer::workflow_state_changed(). */
    [
        'eventname' => '\mod_assign\event\workflow_state_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::workflow_state_changed',
    ],
    /* Marker allocation, which core stores no timestamp for. Moodle 5.3 fires
     * marker_added and marker_removed per slot change (an optional slot
     * included) and no longer triggers marker_updated, which only the
     * deprecated assign::update_allocated_markers() still sends. See
     * observer::marker_changed(). */
    [
        'eventname' => '\mod_assign\event\marker_added',
        'callback' => '\block_feedback_tracker\local\sla\observer::marker_changed',
    ],
    [
        'eventname' => '\mod_assign\event\marker_removed',
        'callback' => '\block_feedback_tracker\local\sla\observer::marker_changed',
    ],
    [
        'eventname' => '\mod_assign\event\marker_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::marker_changed',
    ],
    /* A marker's own mark or workflow state (Moodle 5.3, update_mark()). The
     * per-marker slot table records each marker's first "Marking completed".
     * marker_enabled_updated is not observed: ticking an optional slot with no
     * marker changes no slot row, and naming or unticking its marker fires
     * marker_added / marker_removed. */
    [
        'eventname' => '\mod_assign\event\submission_marked',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_marked',
    ],
    /* Blind marking suppresses submission_graded until identities are revealed.
     * See observer::identities_revealed(). */
    [
        'eventname' => '\mod_assign\event\identities_revealed',
        'callback' => '\block_feedback_tracker\local\sla\observer::identities_revealed',
    ],

    /* No assign override or extension event is observed. The dates a row
     * stores are resolved by the writer while its cycle is open and kept from
     * the moment it closes, so they are the dates in force when the teacher
     * answered; an open cycle takes the new dates at its next write. See
     * submission_ledger::build_and_store(). */

    // Course / cm lifecycle.
    /* An assign's settings save: markingworkflow, markingallocation and
     * teamsubmission change what stored rows mean, which no reconciler sweep
     * detects. See observer::course_module_updated(). */
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::course_module_updated',
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\block_feedback_tracker\local\sla\observer::course_module_deleted',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\block_feedback_tracker\local\sla\observer::course_deleted',
    ],

    /* Participant lifecycle, cleanup only: rows for someone who left, or whose
     * account is gone, are a response owed to nobody. The reconciler's
     * departed-participant sweep removes them too, but visits a limited number
     * of courses per two-hourly run; these remove them at once. */
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\block_feedback_tracker\local\sla\observer::enrolment_changed',
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => '\block_feedback_tracker\local\sla\observer::user_deleted',
    ],

    // Group membership / lifecycle.
    [
        'eventname' => '\core\event\group_member_added',
        'callback' => '\block_feedback_tracker\local\sla\observer::group_membership_changed',
    ],
    [
        'eventname' => '\core\event\group_member_removed',
        'callback' => '\block_feedback_tracker\local\sla\observer::group_membership_changed',
    ],
    [
        'eventname' => '\core\event\group_deleted',
        'callback' => '\block_feedback_tracker\local\sla\observer::group_deleted',
    ],

    // Plugin custom calendar events.
    [
        'eventname' => '\block_feedback_tracker\event\cal_day_updated',
        'callback' => '\block_feedback_tracker\local\calendar\observer::day_updated',
    ],
    [
        'eventname' => '\block_feedback_tracker\event\cal_hours_updated',
        'callback' => '\block_feedback_tracker\local\calendar\observer::hours_updated',
    ],
    [
        'eventname' => '\block_feedback_tracker\event\cal_pause_updated',
        'callback' => '\block_feedback_tracker\local\calendar\observer::pause_updated',
    ],
];
