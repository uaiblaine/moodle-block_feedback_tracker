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
 * Wires assign / group / course events to the SLA observer, and the three
 * plugin custom events to the calendar observer. Observers are lightweight:
 * they upsert one ledger row plus enqueue one dirty-queue entry; the rollup
 * recompute happens out-of-band.
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
    /* The *_updated twins. Without them a student editing an existing
     * submission is invisible whenever submissiondrafts is on (no
     * assessable_submitted fires), so the ledger keeps a stale hand-in time.
     * NOTE: do not register \mod_assign\event\submission_created or
     * \mod_assign\event\submission_updated — both are abstract base classes
     * that core never instantiates, so an observer on them catches nothing. */
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
    /* The gradebook, which mod_assign never learns about. A grade typed
     * straight into the grader report reaches the student without any assign
     * event firing at all, and once it is an override mod_assign stops firing
     * submission_graded for that student for ever. Registered alone: its twin
     * grade_deleted has nothing to do under the earliest-wins rule, and fires
     * unreliably besides. The callback exits early on an already-closed cycle,
     * which is the branch the ordinary grading path takes. */
    [
        'eventname' => '\core\event\user_graded',
        'callback' => '\block_feedback_tracker\local\sla\observer::gradebook_changed',
    ],
    /* Marking workflow. The release transition is the only signal that a grade
     * became visible to the student, and mod_assign persists no timestamp for
     * it, so an unobserved release is unrecoverable. */
    [
        'eventname' => '\mod_assign\event\workflow_state_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::workflow_state_changed',
    ],
    /* Marking allocation. Only the batch "Set allocated marker" operation
     * fires this on 4.5 and 5.1 — the grading form and quick grading write
     * assign_user_flags.allocatedmarker with no event — so coverage is partial
     * by construction. Core stores no allocation timestamp either, which is
     * why the moment has to be captured here or lost. */
    [
        'eventname' => '\mod_assign\event\marker_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::marker_changed',
    ],
    /* Blind marking suppresses submission_graded outright: gradebook_item_update()
     * returns false before doing anything, so every grading on the activity is
     * invisible until identities are revealed. This is that moment. */
    [
        'eventname' => '\mod_assign\event\identities_revealed',
        'callback' => '\block_feedback_tracker\local\sla\observer::identities_revealed',
    ],

    // Group overrides on assign.
    [
        'eventname' => '\mod_assign\event\group_override_created',
        'callback' => '\block_feedback_tracker\local\sla\observer::override_changed',
    ],
    [
        'eventname' => '\mod_assign\event\group_override_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::override_changed',
    ],
    [
        'eventname' => '\mod_assign\event\group_override_deleted',
        'callback' => '\block_feedback_tracker\local\sla\observer::override_changed',
    ],
    /* User-level overrides and extensions. Both move the dates one student is
     * judged against, and neither is visible in any other signal — the
     * reconciler's rule-drift sweep compares against assign_user_flags and the
     * activity's own dates, so it cannot see an {assign_overrides} row at all. */
    [
        'eventname' => '\mod_assign\event\user_override_created',
        'callback' => '\block_feedback_tracker\local\sla\observer::user_rule_changed',
    ],
    [
        'eventname' => '\mod_assign\event\user_override_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::user_rule_changed',
    ],
    [
        'eventname' => '\mod_assign\event\user_override_deleted',
        'callback' => '\block_feedback_tracker\local\sla\observer::user_rule_changed',
    ],
    [
        'eventname' => '\mod_assign\event\extension_granted',
        'callback' => '\block_feedback_tracker\local\sla\observer::user_rule_changed',
    ],

    // Course / cm lifecycle.
    /* An assign's settings save. markingworkflow, markingallocation and
     * teamsubmission change the MEANING of rows already written rather than
     * any value in them, so no reconciler sweep can detect the drift — the
     * divergence sweep keys on the mark and the rule sweep keys on dates, and
     * both find the stored rows entirely consistent with a definition that no
     * longer applies. */
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

    /* Participant lifecycle. Both are cleanup: rows for someone who left, or
     * whose account is gone, are a response owed to nobody. The reconciler's
     * departed-participant sweep covers the same ground but visits one course
     * per tick on a two-hourly task, so these exist to collapse a latency
     * measured in days on a large site down to the request that caused it. */
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
