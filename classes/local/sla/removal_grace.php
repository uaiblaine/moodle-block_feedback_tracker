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
 * Grace period applied before a removed block's data is discarded.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * How long a course's measured history survives after the block is removed.
 *
 * Moodle's own convention is to delete a block's data immediately, in
 * `instance_delete()`. That fits a block whose data belongs to the instance —
 * `block_html` deleting its own files. It does not fit this one: the block is
 * a gate, the data belongs to the course, and removing a block from a course
 * page is a small, easily-mistaken act with a large, irreversible consequence.
 * The plugin's tables are not in course backups either, so there is nothing to
 * restore from. Hence a grace period.
 *
 * By default the window follows the site's own recycle-bin retention, because
 * that is the period during which an administrator is already used to being
 * able to undo things. The longest enabled window wins, floored at the
 * plugin's own setting: being more careful with a year of measurement than the
 * site is with a deleted course is the safe direction.
 *
 * A recycle bin configured to never expire (`expiry <= 0`, which
 * `tool_recyclebin`'s cleanup tasks read as "keep forever") is deliberately
 * NOT treated as an infinite grace. That would silently disable the cleanup
 * altogether — the failure mode this whole feature exists to prevent.
 */
final class removal_grace {
    /** Fallback window when nothing is configured: one week. */
    public const DEFAULT_SECONDS = 604800;

    /** Floor on the configured window. Below this, an accident is unrecoverable. */
    public const MIN_SECONDS = 3600;

    /**
     * Seconds a removed block's data is kept before it may be discarded.
     *
     * @return int
     */
    public static function seconds(): int {
        $explicit = (int) (get_config('block_feedback_tracker', 'removal_grace_seconds')
            ?: self::DEFAULT_SECONDS);
        if ($explicit < self::MIN_SECONDS) {
            $explicit = self::MIN_SECONDS;
        }

        if ((int) (get_config('block_feedback_tracker', 'removal_grace_follow_recyclebin') ?: 0) !== 1) {
            return $explicit;
        }

        $windows = [$explicit];
        foreach (['course', 'category'] as $bin) {
            if ((int) (get_config('tool_recyclebin', $bin . 'binenable') ?: 0) !== 1) {
                continue;
            }
            $expiry = (int) (get_config('tool_recyclebin', $bin . 'binexpiry') ?: 0);
            if ($expiry > 0) {
                $windows[] = $expiry;
            }
        }
        return max($windows);
    }

    /**
     * Whether the delayed cleanup runs at all.
     *
     * Off by default: an upgrade must never start discarding a site's history
     * because a new version shipped a policy. While off, removing the block
     * simply stops the course being processed, which is the behaviour every
     * existing installation already has.
     *
     * @return bool
     */
    public static function is_active(): bool {
        return (int) (get_config('block_feedback_tracker', 'removal_cleanup_active') ?: 0) === 1;
    }
}
