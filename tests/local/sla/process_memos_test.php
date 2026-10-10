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
 * Tests for the reset of every static memo.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\calendar\business_hours_lookup;
use block_feedback_tracker\local\calendar\day_rule_resolver;
use block_feedback_tracker\local\calendar\pause_lookup;
use block_feedback_tracker\local\score\peer_stats;

/**
 * A memo left out of {@see process_memos::reset()} leaks one cron task's
 * decisions into every later task of the same process, and nothing else would
 * notice. The first test fills every memo and checks the reset empties it; the
 * second fails when a static property or a memo-reset method appears in
 * classes/ without being listed in the first.
 *
 * @covers \block_feedback_tracker\local\sla\process_memos
 */
final class process_memos_test extends \basic_testcase {
    /**
     * Every static memo in classes/, by class, as property names. The three
     * calendar lookups are reached through a delegate (DELEGATES).
     */
    private const MEMOS = [
        course_access::class => ['memo', 'allmemo'],
        dashboard_scope::class => ['coursememo'],
        group_access::class => ['memo'],
        group_resolver::class => ['memo'],
        submission_ledger::class => ['hasallocationtable', 'skipsubmittermemo'],
        business_hours_lookup::class => ['memo'],
        day_rule_resolver::class => ['memo'],
        pause_lookup::class => ['memo'],
        peer_stats::class => ['cache'],
    ];

    /**
     * Classes whose memo reset only resets other classes, with those classes.
     */
    private const DELEGATES = [
        academic_time::class => [business_hours_lookup::class, day_rule_resolver::class, pause_lookup::class],
    ];

    /**
     * Static properties that are not memos, each with why the reset leaves it.
     */
    private const NOT_MEMOS = [
        removal_grace::class . '::uninstalling' => 'Not a cached answer: it records that this request uninstalls the plugin.',
    ];

    /**
     * The reset puts every memo back to its declared default.
     *
     * @return void
     */
    public function test_reset_empties_every_memo(): void {
        foreach (self::MEMOS as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $property->setValue(null, $this->sentinel($property));
            }
        }

        process_memos::reset();

        foreach (self::MEMOS as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->assertSame($property->getDefaultValue(), $property->getValue(), "$class::\$$name survived the reset.");
            }
        }
    }

    /**
     * Every static property in classes/ is either a memo the first test fills
     * and checks, or listed as not being one; every class offering a memo
     * reset is among the memos.
     *
     * @return void
     */
    public function test_no_static_state_is_left_out_of_the_reset(): void {
        $listed = [];
        foreach (self::MEMOS as $class => $names) {
            foreach ($names as $name) {
                $listed[$class . '::' . $name] = true;
            }
        }

        $found = [];
        $resetters = [];
        foreach ($this->plugin_classes() as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->getName() === $class) {
                    $found[] = $class . '::' . $property->getName();
                }
            }
            foreach (['reset_memo', 'reset_memos'] as $method) {
                if ($reflection->hasMethod($method) && $reflection->getMethod($method)->isStatic()) {
                    $resetters[] = $class;
                }
            }
        }

        $this->assertContains(course_access::class . '::memo', $found, 'Precondition: the scan finds the memos.');
        foreach ($found as $property) {
            $this->assertTrue(
                isset($listed[$property]) || isset(self::NOT_MEMOS[$property]),
                "$property is static state the reset does not know about: add it to process_memos::reset() and MEMOS."
            );
        }
        foreach ($resetters as $class) {
            $this->assertTrue(
                isset(self::MEMOS[$class]) || isset(self::DELEGATES[$class]),
                "$class offers a memo reset that no test checks."
            );
        }
        foreach (self::DELEGATES as $delegate => $classes) {
            foreach ($classes as $class) {
                $this->assertArrayHasKey($class, self::MEMOS, "$delegate resets $class, whose memos no test fills.");
            }
        }
    }

    /**
     * A value for a memo property that differs from its default.
     *
     * @param \ReflectionProperty $property
     * @return mixed
     */
    private function sentinel(\ReflectionProperty $property) {
        $type = $property->getType();
        if ($type instanceof \ReflectionNamedType && $type->getName() === 'bool') {
            return !$property->getDefaultValue();
        }
        return ['sentinel' => true];
    }

    /**
     * Every class the plugin defines under classes/.
     *
     * @return string[] Fully qualified names.
     */
    private function plugin_classes(): array {
        $root = realpath(__DIR__ . '/../../../classes');
        $this->assertNotFalse($root);
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'block_feedback_tracker\\' . str_replace('/', '\\', $relative);
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }
        sort($classes);
        return $classes;
    }
}
