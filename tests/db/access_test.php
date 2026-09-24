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
 * Pins the capability declarations in db/access.php.
 *
 * Capability-gated web services get refusal tests through
 * services_coverage_test. `resetdata` is checked only by pages/reset.php and
 * guards the irreversible wipe of every ledger, rollup, trend and queue row, so
 * its declaration is asserted here: the likely regression is a widened
 * archetype list or a dropped risk flag, not a deleted require_capability().
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
     * The site-wide data-wipe capability has the manager archetype and no other.
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
     * The resetdata capability carries RISK_DATALOSS, which makes the roles UI
     * flag it as a data-loss risk.
     *
     * @return void
     */
    public function test_resetdata_declares_the_data_loss_risk(): void {
        $caps = $this->capabilities();
        $risk = (int) ($caps['block/feedback_tracker:resetdata']['riskbitmask'] ?? 0);

        $this->assertSame(RISK_DATALOSS, $risk & RISK_DATALOSS, 'resetdata must carry RISK_DATALOSS.');
    }

    /**
     * At system context, where pages/reset.php checks it, a course editing
     * teacher does not hold resetdata and a system manager does.
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
     * Every declared capability has its lang string. For a missing one,
     * get_capability_string() falls through to get_string(), which raises a
     * debugging notice and shows the [[identifier]] placeholder in the roles UI.
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
     * The managepausewindows capability is granted to editingteacher on purpose
     * (teachers schedule their own course pauses). That breadth is why
     * save_pause_window and delete_pause_window check it in the context an
     * existing row belongs to, not just the scope the caller names; widening the
     * archetypes needs the same review.
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
