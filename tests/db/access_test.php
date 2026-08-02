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
 * Tests for the capability declarations.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\db;

/**
 * Every capability used by a web service has a refusal test, enforced by
 * services_coverage_test. `resetdata` is the one that does not: it is used
 * only by pages/reset.php, so no web-service test reaches it, and it guards
 * the irreversible wipe of every ledger, rollup, trend and queue row.
 *
 * These tests pin the declaration rather than a call site — which is the right
 * instrument, because the realistic failure is somebody widening the archetype
 * list or dropping the risk flag, not deleting the require_capability line.
 *
 * @coversNothing
 */
final class access_test extends \advanced_testcase {
    /**
     * Load db/access.php.
     *
     * @return array
     */
    private function capabilities(): array {
        $capabilities = [];
        require(__DIR__ . '/../../db/access.php');
        return $capabilities;
    }

    /**
     * The data-wipe capability is manager-only. Adding editingteacher here
     * would hand every course teacher a site-wide delete — the same archetype
     * breadth that made the pause-window write reachable.
     *
     * @return void
     */
    public function test_resetdata_is_manager_only(): void {
        $caps = $this->capabilities();
        $reset = $caps['block/feedback_tracker:resetdata'] ?? null;

        $this->assertNotNull($reset, 'block/feedback_tracker:resetdata must stay declared.');
        $this->assertSame(
            ['manager'],
            array_keys($reset['archetypes'] ?? []),
            'resetdata wipes every ledger, rollup, trend and queue row; only manager may hold it.'
        );
    }

    /**
     * It is declared as data loss, which is what makes Moodle warn when the
     * capability is granted.
     *
     * @return void
     */
    public function test_resetdata_declares_the_data_loss_risk(): void {
        $caps = $this->capabilities();
        $risk = (int) ($caps['block/feedback_tracker:resetdata']['riskbitmask'] ?? 0);

        $this->assertSame(RISK_DATALOSS, $risk & RISK_DATALOSS, 'resetdata must carry RISK_DATALOSS.');
    }

    /**
     * A user without the capability does not get it by being an editing
     * teacher, and a manager does. This is the mutation check the page gate
     * itself has no other test for.
     *
     * @return void
     */
    public function test_only_managers_can_reset(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_system::instance();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $manager = $this->getDataGenerator()->create_user();
        role_assign(
            (int) $this->getDataGenerator()->create_role(['archetype' => 'manager']),
            $manager->id,
            $context->id
        );
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(
            has_capability('block/feedback_tracker:resetdata', $context, $teacher),
            'An editing teacher must not be able to wipe the plugin data.'
        );
        $this->assertTrue(has_capability('block/feedback_tracker:resetdata', $context, $manager));
    }

    /**
     * Every declared capability has its lang string, which is what an
     * administrator reads when assigning it. A missing one shows the raw
     * identifier in the roles UI.
     *
     * @return void
     */
    public function test_every_capability_has_a_lang_string(): void {
        foreach (array_keys($this->capabilities()) as $capability) {
            $key = str_replace('block/', '', $capability);
            $this->assertTrue(
                get_string_manager()->string_exists($key, 'block_feedback_tracker'),
                "Capability {$capability} has no lang string ({$key})."
            );
        }
    }

    /**
     * Every capability declares a context level and a capability type, so
     * Moodle can place it in the roles UI.
     *
     * @return void
     */
    public function test_every_capability_is_fully_declared(): void {
        foreach ($this->capabilities() as $name => $def) {
            $this->assertArrayHasKey('captype', $def, "{$name} has no captype");
            $this->assertContains($def['captype'], ['read', 'write'], "{$name} has an odd captype");
            $this->assertArrayHasKey('contextlevel', $def, "{$name} has no contextlevel");
        }
    }

    /**
     * Write capabilities that reach beyond a single course are the ones worth
     * watching. managepausewindows is granted to editingteacher on purpose —
     * teachers schedule their own course pauses — and that breadth is exactly
     * why save_pause_window must authorise against the row's stored context
     * rather than the caller's requested scope.
     *
     * @return void
     */
    public function test_managepausewindows_archetypes_are_deliberate(): void {
        $caps = $this->capabilities();
        $archetypes = array_keys($caps['block/feedback_tracker:managepausewindows']['archetypes'] ?? []);

        sort($archetypes);
        $this->assertSame(
            ['editingteacher', 'manager'],
            $archetypes,
            'Widening this beyond editingteacher/manager needs a matching look at save_pause_window.'
        );
    }
}
