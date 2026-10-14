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
 * Keeps a teacher's saved grader filters from hiding the student a grade link names.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\grading;

/**
 * Decides whether a grade link may go straight to mod_assign's grader, and
 * clears what would make the grader open another student.
 *
 * The grader honours `userid` only while that student is in its filtered
 * list: when it is not, mod_assign's grading_navigation.js selects the first
 * student of the list instead. The list is narrowed by the teacher's saved
 * status, workflow and marker filters (global user preferences), by the
 * name-initial filters of that assignment's grading table (its flextable
 * preference) and by the active group.
 *
 * Three levels, so the common case costs nothing:
 *  - {@see self::viewer_has_saved_state()} and {@see self::row_needs_gate()}
 *    decide, without a query, whether a link must pass through
 *    pages/open_grader.php at all;
 *  - {@see self::prepare()}, on that page, answers whether the student is
 *    actually hidden, with core's own table restricted to that one student;
 *  - only then are the filters cleared or the active group changed.
 */
final class grader_filters {
    /** @var string[] The global grader filter preferences mod_assign saves. */
    private const FILTER_PREFS = ['assign_filter', 'assign_workflowfilter', 'assign_markerfilter'];

    /** @var string Prefix of a grading table's flextable preference; the assignment's context id follows. */
    private const TABLE_PREF_PREFIX = 'flextable_mod_assign_grading-';

    /**
     * Whether the current user carries any saved state that can narrow a
     * grader's student list: a status, workflow or marker filter, a name
     * initial on any assignment's grading table, or a named active group in
     * this session.
     *
     * Reads only the preferences already loaded for the session and the
     * session itself, so the dashboards can compute it on every load.
     *
     * @return bool
     */
    public static function viewer_has_saved_state(): bool {
        global $SESSION;
        $prefs = get_user_preferences() ?: [];
        foreach (self::FILTER_PREFS as $name) {
            if (self::filter_is_set($name, $prefs[$name] ?? '')) {
                return true;
            }
        }
        foreach ($prefs as $name => $value) {
            if (str_starts_with((string) $name, self::TABLE_PREF_PREFIX) && self::has_initials((string) $value)) {
                return true;
            }
        }
        // Shape set by _group_verify_activegroup(): [courseid][groupmode][groupingid] => groupid.
        foreach ((array) ($SESSION->activegroup ?? []) as $modes) {
            foreach ((array) $modes as $groupings) {
                foreach ((array) $groupings as $groupid) {
                    if ((int) $groupid > 0) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Whether a listed submission's grade link must pass through the gate
     * even for a viewer with no saved state: in a group-mode activity, a
     * viewer without moodle/site:accessallgroups starts the grader on one of
     * their groups (core's default), which can leave the student out.
     *
     * The row needs the activity's and the course's group-mode columns; the
     * mode is resolved by core's groups_get_activity_groupmode(), and the
     * capability read uses the access data already loaded, so a row costs no
     * query once its module context is preloaded.
     *
     * @param int $courseid Course of the submission.
     * @param int $cmgroupmode course_modules.groupmode.
     * @param int $coursegroupmode course.groupmode.
     * @param int $coursegroupmodeforce course.groupmodeforce.
     * @param \context_module $context The activity's context.
     * @return bool
     */
    public static function row_needs_gate(
        int $courseid,
        int $cmgroupmode,
        int $coursegroupmode,
        int $coursegroupmodeforce,
        \context_module $context
    ): bool {
        $mode = groups_get_activity_groupmode(
            (object) ['course' => $courseid, 'groupmode' => $cmgroupmode],
            (object) ['id' => $courseid, 'groupmode' => $coursegroupmode, 'groupmodeforce' => $coursegroupmodeforce]
        );
        return (int) $mode !== NOGROUPS && !has_capability('moodle/site:accessallgroups', $context);
    }

    /**
     * Build the grader URL for one student and, when the current user's
     * saved state would hide that student there, clear it.
     *
     * Saved filters are cleared only when the grader would not list the
     * student with them; a filter that keeps the student listed is left alone.
     * When the active group leaves the student out, the URL carries a `group`
     * parameter that the grader applies itself (all participants when the
     * viewer may see them, otherwise a group the two share). One imprecision is
     * accepted: when the group already hides the student, the saved filters
     * are cleared without telling whether they alone would have, because
     * core's check reads the active group too.
     *
     * The current user must be able to view the assignment's grades, as the
     * grader requires; without a valid sesskey nothing is changed and the
     * plain grader URL is returned. The check is fastest under a request whose
     * `userid` parameter is this student: see {@see self::student_listed()}.
     *
     * @param \assign $assign The assignment.
     * @param int $userid The student.
     * @param bool $sesskeyvalid Whether the request carried the user's sesskey.
     * @return array With 'url' (\moodle_url) and 'cleared' (bool, whether anything was changed).
     * @throws \required_capability_exception When the user cannot view the assignment's grades.
     */
    public static function prepare(\assign $assign, int $userid, bool $sesskeyvalid): array {
        $assign->require_view_grades();
        $url = new \moodle_url('/mod/assign/view.php', [
            'id' => $assign->get_course_module()->id,
            'action' => 'grader',
            'userid' => $userid,
        ]);
        if (!$sesskeyvalid) {
            return ['url' => $url, 'cleared' => false];
        }
        $group = self::group_showing($assign, $userid);
        $clearfilters = false;
        if (self::assignment_has_saved_filters($assign)) {
            $clearfilters = $group !== null || !self::student_listed($assign, $userid);
        }
        if (($group === null && !$clearfilters) || $assign->get_participant($userid) === null) {
            return ['url' => $url, 'cleared' => false];
        }
        if ($clearfilters) {
            self::clear_filters($assign);
        }
        if ($group !== null) {
            $url->param('group', $group);
        }
        return ['url' => $url, 'cleared' => true];
    }

    /**
     * Whether the current user's saved filters can narrow this assignment's
     * grader: a global status, workflow or marker filter, or a name initial on
     * this assignment's grading table.
     *
     * @param \assign $assign
     * @return bool
     */
    public static function assignment_has_saved_filters(\assign $assign): bool {
        foreach (self::FILTER_PREFS as $name) {
            if (self::filter_is_set($name, get_user_preferences($name, ''))) {
                return true;
            }
        }
        return self::has_initials((string) get_user_preferences(self::table_pref($assign), ''));
    }

    /**
     * Whether the grader would list the student under the current user's
     * saved filters and active group.
     *
     * Asks core's grading table, so every filter it applies is honoured.
     * The table reads the request's `userid` parameter and, when it is set,
     * restricts itself to that student: the check then costs the same for
     * any class size, where the whole-class table grows with it. That is why
     * pages/open_grader.php names its parameter `userid`; under another
     * request the answer is the same, only slower.
     *
     * @param \assign $assign
     * @param int $userid
     * @return bool
     */
    public static function student_listed(\assign $assign, int $userid): bool {
        return $assign->is_userid_filtered($userid);
    }

    /**
     * Remove the current user's status, workflow and marker filters and this
     * assignment's name-initial filters. The table's sort order and collapsed
     * columns are kept.
     *
     * @param \assign $assign
     * @return void
     */
    public static function clear_filters(\assign $assign): void {
        foreach (self::FILTER_PREFS as $name) {
            unset_user_preference($name);
        }
        $name = self::table_pref($assign);
        $prefs = json_decode((string) get_user_preferences($name, ''), true);
        if (is_array($prefs) && (!empty($prefs['i_first']) || !empty($prefs['i_last']))) {
            $prefs['i_first'] = '';
            $prefs['i_last'] = '';
            set_user_preference($name, json_encode($prefs));
        }
    }

    /**
     * The group the grader must switch to so it lists the student, or null
     * when the current active group already does (or no group the viewer may
     * select does).
     *
     * @param \assign $assign
     * @param int $userid
     * @return int|null 0 for all participants, a group id, or null for no change.
     */
    public static function group_showing(\assign $assign, int $userid): ?int {
        $cm = $assign->get_course_module();
        $mode = (int) groups_get_activity_groupmode($cm);
        if ($mode === NOGROUPS) {
            return null;
        }
        $active = (int) groups_get_activity_group($cm);
        if ($active === 0 || groups_is_member($active, $userid)) {
            return null;
        }
        // The two cases where core's group menu offers all participants.
        if ($mode === VISIBLEGROUPS || has_capability('moodle/site:accessallgroups', $assign->get_context())) {
            return 0;
        }
        $studentgroups = groups_get_all_groups($cm->course, $userid, $cm->groupingid, 'g.id');
        foreach (groups_get_activity_allowed_groups($cm) as $groupid => $unused) {
            if (isset($studentgroups[$groupid])) {
                return (int) $groupid;
            }
        }
        return null;
    }

    /**
     * Whether a stored filter preference narrows the list. The status
     * filter's "No filter" option is stored as 'none'.
     *
     * @param string $name Preference name.
     * @param mixed $value Stored value.
     * @return bool
     */
    private static function filter_is_set(string $name, $value): bool {
        $value = (string) $value;
        if ($value === '') {
            return false;
        }
        return !($name === 'assign_filter' && $value === ASSIGN_FILTER_NONE);
    }

    /**
     * Whether a flextable preference, as flexible_table stores it (JSON),
     * carries a first- or last-name initial.
     *
     * @param string $json
     * @return bool
     */
    private static function has_initials(string $json): bool {
        if ($json === '') {
            return false;
        }
        $prefs = json_decode($json, true);
        return is_array($prefs) && (!empty($prefs['i_first']) || !empty($prefs['i_last']));
    }

    /**
     * Name of this assignment's grading-table preference; the table's
     * uniqueid is set in {@see \assign_grading_table::__construct()}.
     *
     * @param \assign $assign
     * @return string
     */
    private static function table_pref(\assign $assign): string {
        return self::TABLE_PREF_PREFIX . $assign->get_context()->id;
    }
}
