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
 * Tests for pause_lookup.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Scoping rules: site applies everywhere, course matches courseid, group
 * matches groupid AND must belong to courseid.
 *
 * @covers \block_feedback_tracker\local\calendar\pause_lookup
 */
final class pause_lookup_test extends \advanced_testcase {
    public function test_site_pause_applies_to_any_course(): void {
        $this->resetAfterTest();
        set_config('calver', '1', 'block_feedback_tracker');
        $this->insert_pause('site', 0, 1000, 2000);

        $rows = pause_lookup::for_course_group(7, 0, 500, 2500);

        $this->assertCount(1, $rows);
        $this->assertSame('site', reset($rows)->scopelevel);
    }

    public function test_course_pause_matches_courseid_only(): void {
        $this->resetAfterTest();
        set_config('calver', '1', 'block_feedback_tracker');
        $this->insert_pause('course', 100, 1000, 2000);

        $matching = pause_lookup::for_course_group(100, 0, 500, 2500);
        pause_lookup::reset_memo();
        $other = pause_lookup::for_course_group(200, 0, 500, 2500);

        $this->assertCount(1, $matching);
        $this->assertSame([], $other);
    }

    public function test_group_pause_matches_groupid_and_course(): void {
        $this->resetAfterTest();
        set_config('calver', '1', 'block_feedback_tracker');

        $course = $this->getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->insert_pause('group', (int) $group1->id, 1000, 2000);

        $forgroup1 = pause_lookup::for_course_group((int) $course->id, (int) $group1->id, 500, 2500);
        pause_lookup::reset_memo();
        $forgroup2 = pause_lookup::for_course_group((int) $course->id, (int) $group2->id, 500, 2500);

        $this->assertCount(1, $forgroup1);
        $this->assertSame([], $forgroup2);
    }

    public function test_group_pause_filtered_when_group_belongs_to_other_course(): void {
        $this->resetAfterTest();
        set_config('calver', '1', 'block_feedback_tracker');

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $groupincourse1 = $this->getDataGenerator()->create_group(['courseid' => $course1->id]);
        $this->insert_pause('group', (int) $groupincourse1->id, 1000, 2000);

        $fromcourse2 = pause_lookup::for_course_group((int) $course2->id, (int) $groupincourse1->id, 500, 2500);

        $this->assertSame([], $fromcourse2);
    }

    public function test_time_range_overlap_required(): void {
        $this->resetAfterTest();
        set_config('calver', '1', 'block_feedback_tracker');
        $this->insert_pause('site', 0, 1000, 2000);

        // Range entirely before the pause.
        $before = pause_lookup::for_course_group(1, 0, 100, 500);
        pause_lookup::reset_memo();
        // Range entirely after the pause.
        $after = pause_lookup::for_course_group(1, 0, 2500, 3000);
        pause_lookup::reset_memo();
        // Range overlapping the pause.
        $overlap = pause_lookup::for_course_group(1, 0, 1500, 1700);

        $this->assertSame([], $before);
        $this->assertSame([], $after);
        $this->assertCount(1, $overlap);
    }

    public function test_open_ended_pause_extends_to_infinity(): void {
        $this->resetAfterTest();
        set_config('calver', '1', 'block_feedback_tracker');
        $this->insert_pause('site', 0, 1000, null);

        $rows = pause_lookup::for_course_group(1, 0, 5000, 10000);

        $this->assertCount(1, $rows);
    }

    public function test_memo_caches_per_course(): void {
        $this->resetAfterTest();
        set_config('calver', '1', 'block_feedback_tracker');
        $this->insert_pause('site', 0, 1000, 2000);

        $a = pause_lookup::for_course_group(1, 0, 500, 2500);
        // A second pause must not appear: insert_pause() resets only the static
        // memo, so the result comes from MUC under the unchanged calver.
        $this->insert_pause('site', 0, 1100, 1900);
        $b = pause_lookup::for_course_group(1, 0, 500, 2500);

        $this->assertCount(count($a), $b);
    }

    /**
     * Insert a pause window row and reset the static memo (not the MUC cache).
     *
     * @param string $scopelevel The scope level (site, course, group).
     * @param int $scopeid The ID corresponding to the scope.
     * @param int $tsstart The starting timestamp of the pause.
     * @param int|null $tsend The ending timestamp of the pause; null for open-ended.
     * @return void
     */
    private function insert_pause(string $scopelevel, int $scopeid, int $tsstart, ?int $tsend): void {
        global $DB;
        $now = time();
        $DB->insert_record('block_feedback_tracker_cpause', (object) [
            'scopelevel'   => $scopelevel,
            'scopeid'      => $scopeid,
            'contextid'    => \context_system::instance()->id,
            'reason'       => 'test',
            'timestart'    => $tsstart,
            'timeend'      => $tsend,
            'note'         => null,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
        pause_lookup::reset_memo();
    }
}
