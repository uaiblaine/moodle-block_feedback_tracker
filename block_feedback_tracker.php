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
 * Block Feedback Flow
 *
 * Documentation: {@link https://moodledev.io/docs/apis/plugintypes/blocks}
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Feedback Flow block class definition.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_feedback_tracker extends block_base {
    /** Vendored Preact + htm bundle (path relative to plugin root). */
    private const VENDOR_BUNDLE = '/blocks/feedback_tracker/js/vendor/bft-vendor-10.29.2-3.1.1.min.js';

    /**
     * Block initialisation
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_feedback_tracker');
    }

    /**
     * Get content
     *
     * On a course page, emits a React mount-point div carrying a light
     * bootstrap payload (empty groups + i18n + config) as JSON. block_app.js
     * fetches the group cards asynchronously in sequential pages after mount;
     * a short no-JS hint is wrapped inside <noscript> for graceful
     * degradation. On the site front page or dashboard the block emits a
     * short hint instead — those surfaces aren't supported in this MVP.
     *
     * @return stdClass
     */
    public function get_content() {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = (object) ['text' => '', 'footer' => ''];

        $courseid = (int) ($this->page->course->id ?? SITEID);
        if ($courseid <= 0 || $courseid === SITEID) {
            $renderer = $this->page->get_renderer('block_feedback_tracker');
            $this->content->text = $renderer->render_from_template(
                'block_feedback_tracker/empty_hint',
                ['message' => get_string('block_addtocoursehint', 'block_feedback_tracker')]
            );
            return $this->content;
        }

        $context = context_course::instance($courseid);
        if (!has_capability('block/feedback_tracker:viewresponsiveness', $context)) {
            return $this->content;
        }

        // The group cards load asynchronously from block_app.js via the
        // get_responsiveness web service (paginated), so the initial payload
        // ships an empty groups array and a light shell — no synchronous
        // rollup read blocks first paint here.
        $groups = [];
        $lastsynced = 0;

        $renderer = $this->page->get_renderer('block_feedback_tracker');
        $ssrhtml = $renderer->render_from_template(
            'block_feedback_tracker/empty_hint',
            ['message' => get_string('block_nojs', 'block_feedback_tracker')]
        );

        // Bootstrap payload for the React tree — empty groups + every
        // localised label + the score-formula config. block_app.js fetches
        // the groups in sequential pages after mount.
        $payload = $this->build_block_payload($courseid, $groups, $lastsynced);
        // JSON_HEX_TAG protects against "</script>" appearing inside a
        // payload string from breaking out of the inline JSON island.
        $payloadjson = json_encode(
            $payload,
            JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($payloadjson === false) {
            $payloadjson = '{}';
        }

        $this->content->text = '<div data-bft-block-root data-courseid="' . $courseid . '">'
            . '<script type="application/json" data-bft-init>' . $payloadjson . '</script>'
            . '<noscript>' . $ssrhtml . '</noscript>'
            . '</div>';

        // Load the Preact bundle into <head> (inhead=true) so its globals
        // are set before any AMD module factory resolves.
        $this->page->requires->js(new \moodle_url(self::VENDOR_BUNDLE), true);
        $this->page->requires->js_call_amd('block_feedback_tracker/block_app', 'init');

        return $this->content;
    }

    /**
     * Assemble the JSON bootstrap consumed by block_app.js. The shared
     * i18n + config bundles live in classes/local/output/bootstrap.php so
     * standalone pages (pending_report.php, teacher_dashboard.php) can
     * autoload them — Moodle doesn't autoload block classes outside the
     * blocks subsystem.
     *
     * @param int $courseid
     * @param array $groups Group payload entries (25-key shape).
     * @param int $lastsynced Unix timestamp of the last rollup compute.
     * @return array
     */
    private function build_block_payload(int $courseid, array $groups, int $lastsynced): array {
        return [
            'courseid' => $courseid,
            'lastsynced' => $lastsynced,
            // Calendar version — the client folds it into its sessionStorage
            // cache key so a calendar-affecting save naturally invalidates any
            // stored pages (mirrors the server's MUC calver keying).
            'calver' => \block_feedback_tracker\local\calendar\calendar::current_version(),
            'groups' => $groups,
            'i18n' => \block_feedback_tracker\local\output\bootstrap::i18n_bundle(),
            'config' => \block_feedback_tracker\local\output\bootstrap::config_bundle(),
        ];
    }

    /**
     * Expose settings.php in the site administration tree.
     *
     * @return bool
     */
    public function has_config() {
        return true;
    }

    /**
     * Where this block can be placed. Course pages are the supported surface;
     * site front and my-dashboard render a short hint.
     *
     * @return array<string, bool>
     */
    public function applicable_formats() {
        return [
            'course-view' => true,
            'site' => false,
            'my' => false,
            'admin' => false,
        ];
    }

    /**
     * Called once by core before every instance of this block type is deleted
     * during plugin uninstall.
     *
     * It is the only signal that separates "the plugin is going away" from
     * "somebody removed the block from a course page". Queuing a week of
     * cleanup tasks during an uninstall would be pointless — the tables are
     * about to be dropped — and on a large site it would mean thousands of
     * adhoc rows nobody will ever run.
     *
     * The flag itself is held by removal_grace, which explains why.
     *
     * @return void
     */
    public function before_delete() {
        \block_feedback_tracker\local\sla\removal_grace::mark_uninstalling();
    }

    /**
     * Arm the delayed discard of this course's measured history.
     *
     * Moodle deletes a block's data here, synchronously — that is the
     * convention, and it fits a block whose data belongs to the instance. This
     * one is a gate: the data belongs to the course, the plugin's tables are
     * not in course backups, and removing a block from a course page is a
     * small act with an irreversible consequence. So the discard is deferred,
     * and the task re-checks at run time whether the block came back.
     *
     * No decision about sibling instances is taken here. This method runs
     * BEFORE the block_instances row is deleted, and the bulk path defers every
     * row deletion until after its loop, so a count taken now is wrong in both
     * directions. The task asks the question when it runs, which is the only
     * moment the answer is stable.
     *
     * @return bool
     */
    public function instance_delete() {
        if (\block_feedback_tracker\local\sla\removal_grace::is_uninstalling()) {
            return true;
        }
        if (!\block_feedback_tracker\local\sla\removal_grace::is_active()) {
            return true;
        }
        $courseid = $this->resolve_course_id();
        if ($courseid <= 0) {
            return true;
        }

        $task = new \block_feedback_tracker\task\discard_course_data();
        $task->set_custom_data(['courseid' => $courseid]);
        $task->set_next_run_time(
            time() + \block_feedback_tracker\local\sla\removal_grace::seconds()
        );
        // The dedupe compares component + class + custom data, so a course
        // whose block is removed twice inside one window keeps one task.
        \core\task\manager::queue_adhoc_task($task, true);
        return true;
    }

    /**
     * The course this instance sat on, or 0 when it did not sit on one.
     *
     * Read from the instance's parent context rather than from $this->page,
     * which is not set on every deletion path (bulk deletes and course
     * teardown never build a page).
     *
     * @return int
     */
    private function resolve_course_id(): int {
        if (empty($this->instance->parentcontextid)) {
            return 0;
        }
        $context = \context::instance_by_id((int) $this->instance->parentcontextid, IGNORE_MISSING);
        if (!$context || $context->contextlevel != CONTEXT_COURSE) {
            return 0;
        }
        return (int) $context->instanceid;
    }
}
