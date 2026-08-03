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
 * Capability definitions for Feedback Flow
 *
 * Documentation: {@link https://moodledev.io/docs/apis/subsystems/access}
 *
 * @package    block_feedback_tracker
 * @category   access
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    'block/feedback_tracker:addinstance' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:manageblocks',
    ],

    'block/feedback_tracker:myaddinstance' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/my:manageblocks',
    ],

    'block/feedback_tracker:viewresponsiveness' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    'block/feedback_tracker:viewdashboard' => [
        // Cross-course dashboard. Granted at COURSE level (and inherited
        // from category/system) because editing teachers, who hold this
        // archetype, are role-assigned at course context — a system-only
        // contextlevel here would mismatch the archetype grant and confuse
        // the role-definition UI. Pages/teacher_dashboard.php uses
        // get_user_capability_course() to enumerate exactly the courses
        // the caller is entitled to.
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    'block/feedback_tracker:viewalldata' => [
        // Full-site dashboard view: holders see every course and group on the
        // teacher dashboard regardless of their own enrolments — the same scope
        // a site admin gets through the enable_admin_view_all setting. Granted
        // at SYSTEM context with no archetype so it is strictly opt-in: assign
        // it to the role (coordinator, head of department, …) that should see
        // everything. dashboard_scope checks it with doanything suppressed, so
        // it never auto-applies to a plain site admin.
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],

    'block/feedback_tracker:viewschoolcomparison' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_PREVENT,
        ],
    ],

    'block/feedback_tracker:managecalendar' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'riskbitmask' => RISK_CONFIG,
    ],

    'block/feedback_tracker:managepausewindows' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    'block/feedback_tracker:resetdata' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'riskbitmask' => RISK_DATALOSS,
    ],

    /* Removing the block from many courses at once, and optionally discarding
     * their measured history. Separate from :resetdata (which wipes the whole
     * site) and from :managecalendar (configuration, not destruction) so that
     * being able to edit term dates never implies being able to clear a
     * semester's worth of courses. */
    'block/feedback_tracker:bulkmanageblocks' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'riskbitmask' => RISK_DATALOSS,
    ],

    'block/feedback_tracker:viewaudit' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
