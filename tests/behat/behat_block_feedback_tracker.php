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
 * Behat page-name resolvers for block_feedback_tracker.
 *
 * Behat's "I am on the ... page" steps consult resolvers on per-component
 * classes. Without this class, scenarios that say
 * `I am on the "block_feedback_tracker > Teacher dashboard" page` fail with
 * "Step definition not found" — there's no built-in resolver for our
 * plugin-owned pages.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Resolves "block_feedback_tracker > <page name>" identifiers to URLs.
 */
class behat_block_feedback_tracker extends behat_base {
    /**
     * Standalone pages with no associated entity. Used by steps shaped like
     * `I am on the "block_feedback_tracker > Teacher dashboard" page`.
     *
     * @param string $page The page name appearing after the " > " in the step.
     * @return moodle_url
     * @throws Exception when the page name isn't recognised.
     */
    protected function resolve_page_url(string $page): moodle_url {
        switch ($page) {
            case 'Teacher dashboard':
                return new moodle_url('/blocks/feedback_tracker/pages/teacher_dashboard.php');
            case 'Manage':
                return new moodle_url('/blocks/feedback_tracker/manage.php');
            case 'Audit log':
                return new moodle_url('/blocks/feedback_tracker/pages/audit_log.php');
            case 'Reset':
                return new moodle_url('/blocks/feedback_tracker/pages/reset.php');
            case 'Score simulator':
                return new moodle_url('/blocks/feedback_tracker/pages/score_simulator.php');
            case 'Calendar editor':
                return new moodle_url('/blocks/feedback_tracker/pages/calendar_editor.php');
        }
        throw new Exception("Unrecognised block_feedback_tracker page: '{$page}'.");
    }

    /**
     * Pages tied to a specific entity (typically a course). Used by steps
     * like `I am on the "Course 1" "block_feedback_tracker > Pending report" page`.
     *
     * @param string $type       The page type after the " > " in the step.
     * @param string $identifier The entity identifier — for course-bound
     *                            pages, the course shortname / fullname /
     *                            idnumber.
     * @return moodle_url
     * @throws Exception when the page type isn't recognised.
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch ($type) {
            case 'Pending report':
                return new moodle_url(
                    '/blocks/feedback_tracker/pages/pending_report.php',
                    ['courseid' => $this->get_course_id($identifier)]
                );
            case 'Group drilldown':
                // Course-bound: the page takes a required courseid.
                return new moodle_url(
                    '/blocks/feedback_tracker/pages/group_drilldown.php',
                    ['courseid' => $this->get_course_id($identifier)]
                );
        }
        throw new Exception("Unrecognised block_feedback_tracker page instance type: '{$type}'.");
    }

    /**
     * Look up a course id from any of its identifying fields.
     *
     * @param string $identifier
     * @return int
     */
    protected function get_course_id(string $identifier): int {
        global $DB;
        return (int) $DB->get_field_select(
            'course',
            'id',
            'shortname = :shortname OR fullname = :fullname OR idnumber = :idnumber',
            [
                'shortname' => $identifier,
                'fullname'  => $identifier,
                'idnumber'  => $identifier,
            ],
            MUST_EXIST
        );
    }
}
