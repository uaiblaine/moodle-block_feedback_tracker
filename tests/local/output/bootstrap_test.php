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
 * Tests for the JS bootstrap config bundle.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

use block_feedback_tracker\local\score\responsiveness_calculator;

/**
 * Pins the reads in bootstrap::config_bundle() and what the i18n bundle carries.
 *
 * For the default-ON toggles, `get_config()` returns the string '0' when a
 * checkbox is switched off, which is falsy in PHP, so a `?: 1` read would make
 * the toggle impossible to turn off. Only an explicit '0' means off; an unset
 * value means on.
 *
 * @covers \block_feedback_tracker\local\output\bootstrap
 */
final class bootstrap_test extends \advanced_testcase {
    /**
     * Drop a mocked string manager, which would otherwise outlive the test
     * with its mocked langconfig strings.
     *
     * @return void
     */
    protected function tearDown(): void {
        global $CFG;
        if (get_string_manager() instanceof \core\tests\mocking_string_manager) {
            unset($CFG->config_php_settings['customstringmanager']);
            get_string_manager(true);
        }
        parent::tearDown();
    }

    /**
     * A default-ON toggle is on when nothing was ever stored.
     *
     * @return void
     */
    public function test_unset_toggles_default_to_on(): void {
        $this->resetAfterTest();
        unset_config('show_peer_context', 'block_feedback_tracker');
        unset_config('show_paused_today_indicator', 'block_feedback_tracker');

        $bundle = bootstrap::config_bundle();

        $this->assertTrue($bundle['show_peer_context']);
        $this->assertTrue($bundle['show_scheduled_pauses']);
    }

    /**
     * Switching the checkbox off stores the string '0', and that must turn the
     * toggle off.
     *
     * @return void
     */
    public function test_explicit_zero_turns_a_toggle_off(): void {
        $this->resetAfterTest();
        set_config('show_peer_context', '0', 'block_feedback_tracker');
        set_config('show_paused_today_indicator', '0', 'block_feedback_tracker');

        $bundle = bootstrap::config_bundle();

        $this->assertFalse($bundle['show_peer_context'], 'A stored "0" must switch the toggle off.');
        $this->assertFalse($bundle['show_scheduled_pauses']);
    }

    /**
     * An explicit on is on.
     *
     * @return void
     */
    public function test_explicit_one_keeps_a_toggle_on(): void {
        $this->resetAfterTest();
        set_config('show_peer_context', '1', 'block_feedback_tracker');
        set_config('show_paused_today_indicator', '1', 'block_feedback_tracker');

        $bundle = bootstrap::config_bundle();

        $this->assertTrue($bundle['show_peer_context']);
        $this->assertTrue($bundle['show_scheduled_pauses']);
    }

    /**
     * The two toggles are independent — switching one off must not drag the
     * other with it.
     *
     * @return void
     */
    public function test_toggles_are_independent(): void {
        $this->resetAfterTest();
        set_config('show_peer_context', '0', 'block_feedback_tracker');
        unset_config('show_paused_today_indicator', 'block_feedback_tracker');

        $bundle = bootstrap::config_bundle();

        $this->assertFalse($bundle['show_peer_context']);
        $this->assertTrue($bundle['show_scheduled_pauses']);
    }

    /**
     * The bundle carries the score thresholds the JS band helper reads, under
     * exactly the keys it expects.
     *
     * @return void
     */
    public function test_score_thresholds_use_the_keys_the_js_reads(): void {
        $this->resetAfterTest();
        unset_config('score_thresholds_band', 'block_feedback_tracker');

        $bundle = bootstrap::config_bundle();

        $this->assertArrayHasKey('score_thresholds', $bundle);
        $this->assertSame(
            ['excellent', 'good', 'regular'],
            array_keys($bundle['score_thresholds']),
            'bandForScore() in amd/src/lib/bands.js reads exactly these keys.'
        );
    }

    /**
     * The five score weights are all present and numeric, since the JS divides
     * by their sum.
     *
     * @return void
     */
    public function test_weights_are_all_present_and_numeric(): void {
        $this->resetAfterTest();

        $weights = bootstrap::config_bundle()['weights'];

        foreach (['compliance', 'median', 'critical', 'pending', 'trend'] as $key) {
            $this->assertArrayHasKey($key, $weights);
            $this->assertIsFloat($weights[$key]);
        }
    }

    /**
     * The bundle ships the weights the groups are scored with, so the score
     * simulator starts from the live formula. A weight stored as zero is the
     * case that tells them apart: the scoring read keeps it, which drops the
     * term, while a `?: default` read would bring the default back.
     *
     * @return void
     */
    public function test_weights_are_the_scoring_weights(): void {
        $this->resetAfterTest();
        set_config('weight_trend', '0', 'block_feedback_tracker');

        $scoring = responsiveness_calculator::load_weights();
        $this->assertSame(0.0, $scoring['trend'], 'Precondition: the scoring formula drops a zero-weighted term.');

        $weights = bootstrap::config_bundle()['weights'];

        $this->assertSame(0.0, $weights['trend'], 'A stored 0 must reach the simulator as 0, not as its default.');
        $this->assertSame($scoring, $weights);
    }

    /**
     * With nothing stored, the bundle carries the default weights.
     *
     * @return void
     */
    public function test_unset_weights_are_the_defaults(): void {
        $this->resetAfterTest();
        foreach (['compliance', 'median', 'critical', 'pending', 'trend'] as $key) {
            unset_config('weight_' . $key, 'block_feedback_tracker');
        }

        $weights = bootstrap::config_bundle()['weights'];

        $this->assertSame(responsiveness_calculator::DEFAULT_WEIGHT_COMPLIANCE, $weights['compliance']);
        $this->assertSame(responsiveness_calculator::DEFAULT_WEIGHT_MEDIAN, $weights['median']);
        $this->assertSame(responsiveness_calculator::DEFAULT_WEIGHT_CRITICAL, $weights['critical']);
        $this->assertSame(responsiveness_calculator::DEFAULT_WEIGHT_PENDING, $weights['pending']);
        $this->assertSame(responsiveness_calculator::DEFAULT_WEIGHT_TREND, $weights['trend']);
    }

    /**
     * The thousands separator reaches the JS, since the count formatter is fed
     * from here rather than reading langconfig itself.
     *
     * @return void
     */
    public function test_bundle_carries_the_thousands_separator(): void {
        $this->resetAfterTest();

        $bundle = bootstrap::config_bundle();

        $this->assertArrayHasKey('thousandssep', $bundle);
        $this->assertNotSame('', (string) $bundle['thousandssep']);
    }

    /**
     * The decimal separator reaches the JS from the active language, so the
     * hours and days formatters write fractions as format_float() does.
     *
     * @return void
     */
    public function test_bundle_carries_the_language_decimal_separator(): void {
        $this->resetAfterTest();
        $strings = $this->get_mocked_string_manager();
        $strings->mock_string('decsep', 'langconfig', ',');

        $bundle = bootstrap::config_bundle();

        $this->assertSame(',', $bundle['decsep']);
    }

    /**
     * The date locale is the language pack's own locale as a BCP 47 tag.
     *
     * @return void
     */
    public function test_date_locale_is_the_language_locale(): void {
        $this->resetAfterTest();
        $this->assertSame(
            'en_AU.UTF-8',
            get_string('locale', 'langconfig'),
            'Precondition: the English pack declares the en_AU locale.'
        );

        $this->assertSame('en-AU', bootstrap::config_bundle()['locale']);

        $strings = $this->get_mocked_string_manager();
        $strings->mock_string('locale', 'langconfig', 'pt_BR.UTF-8');
        $this->assertSame('pt-BR', bootstrap::config_bundle()['locale']);
    }

    /**
     * A locale that is not a usable tag, such as a Windows locale name, falls
     * back to the page's html lang value, 'en' for the English pack.
     *
     * @return void
     */
    public function test_unusable_locale_falls_back_to_the_html_lang(): void {
        $this->resetAfterTest();
        $strings = $this->get_mocked_string_manager();
        $strings->mock_string('locale', 'langconfig', 'English_Australia.1252');

        $this->assertSame('en', bootstrap::config_bundle()['locale']);
    }

    /**
     * The time zone is the user's Moodle time zone, not the server's, so a
     * date near midnight falls on the day userdate() gives it.
     *
     * @return void
     */
    public function test_timezone_is_the_users_moodle_timezone(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['timezone' => 'Asia/Tokyo']);
        $this->setUser($user);
        $this->assertNotSame(
            'Asia/Tokyo',
            \core_date::get_server_timezone(),
            'Precondition: the user time zone must differ from the server one.'
        );

        $this->assertSame('Asia/Tokyo', bootstrap::config_bundle()['timezone']);
    }

    /**
     * The sparkline's accessible name travels in the i18n bundle; the chart
     * has no other source for it.
     *
     * @return void
     */
    public function test_i18n_bundle_carries_the_sparkline_name(): void {
        $this->resetAfterTest();

        $this->assertSame(
            get_string('sparkline_aria', 'block_feedback_tracker'),
            bootstrap::i18n_bundle()['sparkline_aria'] ?? null
        );
    }

    /**
     * The simulator gauge's accessible name travels in the simulator bundle
     * with its placeholder unfilled: the gauge substitutes the live score.
     *
     * @return void
     */
    public function test_simulator_bundle_carries_the_gauge_name_template(): void {
        $this->resetAfterTest();

        $template = bootstrap::simulator_i18n()['gauge_aria'] ?? null;

        $this->assertIsString($template);
        $this->assertStringContainsString('{$a}', $template);
        $this->assertSame(
            get_string('gauge_aria', 'block_feedback_tracker', 42),
            str_replace('{$a}', '42', $template)
        );
    }

    /**
     * The dashboard i18n keys of the site-benchmarks section.
     *
     * @param array $bundle A dashboard_i18n() result.
     * @return string[]
     */
    private function comparison_keys(array $bundle): array {
        return array_values(array_filter(
            array_keys($bundle),
            static fn (string $key): bool => str_starts_with($key, 'dashboard_comparison_')
        ));
    }

    /**
     * A manager holds block/feedback_tracker:viewschoolcomparison at system
     * context by default: the bundle offers the site benchmarks and ships
     * their strings. Prohibiting the capability on the same role withdraws
     * both, so the flag follows the capability rather than the role.
     *
     * @return void
     */
    public function test_school_comparison_follows_the_capability_for_a_manager(): void {
        global $DB;
        $this->resetAfterTest();
        $sysctx = \context_system::instance();
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        $manager = $this->getDataGenerator()->create_user();
        role_assign($managerroleid, (int) $manager->id, $sysctx->id);
        $this->setUser($manager);
        $this->assertTrue(
            has_capability('block/feedback_tracker:viewschoolcomparison', $sysctx),
            'Precondition: the manager archetype holds the capability.'
        );

        $this->assertTrue(bootstrap::config_bundle()['school_comparison']);
        $strings = bootstrap::dashboard_i18n();
        $this->assertSame(
            get_string('dashboard_comparison_title', 'block_feedback_tracker'),
            $strings['dashboard_comparison_title'] ?? null
        );
        $this->assertStringContainsString('{$a}', $strings['dashboard_comparison_caption'] ?? '');
        $this->assertCount(16, $this->comparison_keys($strings));

        assign_capability('block/feedback_tracker:viewschoolcomparison', CAP_PROHIBIT, $managerroleid, $sysctx->id, true);

        $this->assertFalse(bootstrap::config_bundle()['school_comparison']);
        $strings = bootstrap::dashboard_i18n();
        $this->assertSame([], $this->comparison_keys($strings));
        // Control: the rest of the overlay is still built for this user.
        $this->assertArrayHasKey('dashboard_courses_title', $strings);
    }

    /**
     * An editing teacher holds the dashboard through a course role, which does
     * not reach the system context the web service checks: no section and no
     * strings. A system role granting the capability then turns both on.
     *
     * @return void
     */
    public function test_school_comparison_is_withheld_from_an_editing_teacher(): void {
        global $DB;
        $this->resetAfterTest();
        $sysctx = \context_system::instance();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $this->assertTrue(
            has_capability('block/feedback_tracker:viewdashboard', \context_course::instance($course->id)),
            'Precondition: the teacher is a dashboard viewer.'
        );
        $this->assertFalse(has_capability('block/feedback_tracker:viewschoolcomparison', $sysctx));

        $this->assertFalse(bootstrap::config_bundle()['school_comparison']);
        $strings = bootstrap::dashboard_i18n();
        $this->assertSame([], $this->comparison_keys($strings));
        // Control: the rest of the overlay is still built for this user.
        $this->assertArrayHasKey('dashboard_courses_title', $strings);

        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, (int) $teacher->id, $sysctx->id);

        $this->assertTrue(bootstrap::config_bundle()['school_comparison']);
        $this->assertCount(16, $this->comparison_keys(bootstrap::dashboard_i18n()));
    }
}
