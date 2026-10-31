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
 * Backup structure step of the block: the course's ledger and marker slots.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Writes every ledger cycle of the course with its marker slots nested
 * under it, ids as they are on the source site. The restore maps them once
 * the activities exist ({@see restore_feedback_tracker_block_structure_step}).
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_feedback_tracker_block_structure_step extends backup_block_structure_step {
    /**
     * Ledger columns written to the backup: every column except the id.
     *
     * Kept in step with `block_feedback_tracker_sub` in db/install.xml, in its
     * order; `backup_restore_test` fails when they part.
     */
    private const SUB_FIELDS = [
        'courseid', 'groupid', 'cmid', 'iteminstance', 'userid', 'attemptnumber', 'cycle',
        'submissionstatus', 'timesubmitted', 'timegraded', 'timemarked', 'timereleased',
        'timeclosed', 'closedsource', 'timedismissed', 'islatest', 'iscurrent', 'gradestate',
        'teamgroupid', 'timeallocated', 'timeallocmarker', 'allocmarkerid', 'allocsource',
        'allocattrib', 'queuehours', 'allochours', 'allocdays', 'allocbucket', 'timeopens',
        'timecloses', 'timecutoff', 'hasrule', 'waitinghours', 'effectivehours', 'effectivedays',
        'effectiveasof', 'effectivecalver', 'slabucket', 'timecreated', 'timemodified',
    ];

    /**
     * Marker-slot columns written to the backup: every column except the id.
     *
     * Kept in step with `block_feedback_tracker_alloc` in db/install.xml, in
     * its order; `backup_restore_test` fails when they part.
     */
    private const ALLOC_FIELDS = [
        'subid', 'courseid', 'cmid', 'userid', 'marker', 'position', 'optional',
        'timeallocated', 'allocsource', 'timemarked', 'markhours', 'markdays',
        'timeremoved', 'timecreated', 'timemodified',
    ];

    /**
     * The block element with the course's cycles and their slots.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $root = new backup_nested_element('feedback_tracker', ['id'], null);
        $subs = new backup_nested_element('subs');
        $sub = new backup_nested_element('sub', ['id'], self::SUB_FIELDS);
        $allocs = new backup_nested_element('allocs');
        $alloc = new backup_nested_element('alloc', ['id'], self::ALLOC_FIELDS);

        $root->add_child($subs);
        $subs->add_child($sub);
        $sub->add_child($allocs);
        $allocs->add_child($alloc);

        $root->set_source_array([(object) ['id' => $this->task->get_blockid()]]);
        $sub->set_source_table(
            'block_feedback_tracker_sub',
            ['courseid' => backup::VAR_COURSEID],
            'id ASC'
        );
        $alloc->set_source_table(
            'block_feedback_tracker_alloc',
            ['subid' => backup::VAR_PARENTID],
            'id ASC'
        );

        /* Annotated so a marker who has since left the course still travels
         * with the backup; students are enrolled users and travel anyway. */
        $sub->annotate_ids('user', 'userid');
        $sub->annotate_ids('user', 'allocmarkerid');
        $alloc->annotate_ids('user', 'userid');
        $alloc->annotate_ids('user', 'marker');

        return $this->prepare_block_structure($root);
    }
}
