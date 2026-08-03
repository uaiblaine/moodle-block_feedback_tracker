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
 * Data-retention policy for the SLA ledger.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * The single decision about how long measured history is kept.
 *
 * The ledger has no natural ceiling: a closed measurement is never rewritten,
 * a resubmission after grading opens another one, and a team submission is
 * carried by every member. Left alone the table grows for the life of the
 * site, which is fine until it is not.
 *
 * Retention is off by default, matching this plugin's other destructive
 * switch (`backfill_active`): an upgrade must never start deleting a site's
 * data because a new version happened to ship a policy. Turning it on is an
 * explicit, informed act — it bounds the report's all-time Graded tab, which
 * is an audit surface, to the configured window.
 *
 * Both the pruner and the reconciler read the cutoff from here. They have to
 * agree: the reconciler recreates ledger rows for submissions it cannot find
 * one for, so without a shared boundary it would resurrect every row the
 * pruner deleted, on the next tick, for ever.
 */
final class retention {
    /** Default lifetime of a closed measurement, in days. */
    public const DEFAULT_DAYS = 365;

    /** Floor on the configured window; below this the setting is ignored. */
    public const MIN_DAYS = 30;

    /**
     * The instant before which closed history may be discarded.
     *
     * @param int|null $now Override for tests; defaults to time().
     * @return int|null Epoch seconds, or null when retention is switched off.
     */
    public static function cutoff(?int $now = null): ?int {
        if ((int) (get_config('block_feedback_tracker', 'retention_active') ?: 0) !== 1) {
            return null;
        }
        $days = (int) (get_config('block_feedback_tracker', 'retention_days') ?: self::DEFAULT_DAYS);
        if ($days < self::MIN_DAYS) {
            /* A window shorter than a month would delete work still inside the
             * 30-day statistical window the score and the medians are built
             * from, so the rollup would disagree with its own inputs. Treat a
             * too-small value as a misconfiguration and fall back rather than
             * silently corrupting the figures. */
            $days = self::DEFAULT_DAYS;
        }
        $now = $now ?? time();
        return $now - $days * 86400;
    }

    /**
     * Whether retention is switched on at all.
     *
     * @param int|null $now Override for tests.
     * @return bool
     */
    public static function is_active(?int $now = null): bool {
        return self::cutoff($now) !== null;
    }
}
