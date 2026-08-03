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
 * Feedback tracker tool page viewed event.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\event;

/**
 * Fired once, server-side, when an administrator opens one of the plugin's
 * tool pages: the academic calendar editor, the audit log viewer, or the
 * data-reset page. The viewed page is in `other['page']`
 * ('calendar' | 'audit' | 'reset').
 *
 * The slug 'manage' is legacy: it names a tools landing page that no longer
 * exists, and it survives only in log rows written before the page was
 * removed. Those rows still have to resolve to something, so the default
 * branch of get_url() sends them to the plugin's admin settings page, which
 * carries the tool links now.
 *
 * Read-only access event with no observer of its own — the standard logstore
 * subscribes to every event, so triggering it is all that is needed for the
 * access to land in the site logs. It is separate from `report_viewed` only
 * because `edulevel` is fixed per class: tool-page views are administrative
 * (LEVEL_OTHER), report views are teaching participation (LEVEL_PARTICIPATING).
 */
class tool_page_viewed extends \core\event\base {
    /**
     * Initialise the event metadata.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        // No 'objecttable': a tool page is not a single DB object.
    }

    /**
     * Localised event name for the site log report.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_tool_page_viewed', 'block_feedback_tracker');
    }

    /**
     * Human-readable description of one occurrence.
     *
     * @return string
     */
    public function get_description(): string {
        $page = isset($this->other['page']) ? (string) $this->other['page'] : '?';
        return "The user with id '{$this->userid}' viewed the feedback tracker '{$page}' tool page.";
    }

    /**
     * Link back to the viewed tool page.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        switch ($this->other['page'] ?? '') {
            case 'calendar':
                return new \moodle_url('/blocks/feedback_tracker/pages/calendar_editor.php');
            case 'audit':
                return new \moodle_url('/blocks/feedback_tracker/pages/audit_log.php');
            case 'reset':
                return new \moodle_url('/blocks/feedback_tracker/pages/reset.php');
            default:
                return new \moodle_url(
                    '/admin/settings.php',
                    ['section' => 'blocksettingfeedback_tracker']
                );
        }
    }
}
