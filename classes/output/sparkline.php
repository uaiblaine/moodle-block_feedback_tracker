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
 * Sparkline renderable.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\output;

/**
 * Compact SVG line chart for the 30-day median-effective-hours trend.
 *
 * Renders via Mustache; see templates/sparkline.mustache (and the JS port
 * amd/src/components/Sparkline.js — keep all three in lockstep). Null values
 * mean "no graded submissions that day" — those days are skipped in the
 * polyline (a gapless line through the days that have data).
 *
 * The vertical axis reads as speed: fewer effective hours (faster turnaround)
 * render HIGHER. When a goal is supplied it drives the "desired-speed zone":
 * a light-green band anchored at the TOP spanning effective hours 0 → goal,
 * with a solid green baseline at 0 hours (top edge) and a dotted green line at
 * the goal (the minimum-desired-speed boundary). A slowdown pushes the line
 * down, below the goal line.
 */
class sparkline implements \renderable, \templatable {
    /** Minimum width (user units) at which the zone text label is drawn. */
    private const ZONE_LABEL_MIN_WIDTH = 110;

    /** @var array<int, float|null> Ordered (oldest → newest) values. */
    public array $values;

    /** @var float|null Optional goal-line value. */
    public ?float $goal;

    /** @var int SVG width in px. */
    public int $width;

    /** @var int SVG height in px. */
    public int $height;

    /**
     * Constructor for sparkline.
     *
     * @param array $values Ordered (oldest → newest).
     * @param float|null $goal Optional goal-line value (e.g. sla_goal_hours).
     * @param int $width SVG width in px.
     * @param int $height SVG height in px.
     */
    public function __construct(
        array $values,
        ?float $goal = null,
        int $width = 120,
        int $height = 30
    ) {
        $this->values = $values;
        $this->goal = $goal;
        $this->width = $width;
        $this->height = $height;
    }

    /**
     * Build the Mustache template context.
     *
     * @param \renderer_base $output Required by the `\templatable` interface
     *                                contract; not used here because the
     *                                sparkline geometry is precomputed.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $valid = array_filter($this->values, static fn($v) => $v !== null);
        $haszone = $this->goal !== null && (float) $this->goal > 0.0;

        $min = 0.0;
        $max = !empty($valid) ? (float) max($valid) : 0.0;
        if ($haszone) {
            $max = max($max, (float) $this->goal);
        }
        if ($max <= $min) {
            $max = $min + 1.0;
        }

        $n = count($this->values);
        $stride = $n > 1 ? $this->width / ($n - 1) : 0;
        $points = [];
        foreach ($this->values as $i => $v) {
            if ($v === null) {
                continue;
            }
            $x = round($i * $stride, 2);
            // Inverted (speed) axis: fewer hours → smaller y → higher up.
            $y = round((($v - $min) / ($max - $min)) * $this->height, 2);
            $points[] = $x . ',' . $y;
        }

        $zoney = $haszone
            ? round((((float) $this->goal - $min) / ($max - $min)) * $this->height, 2)
            : null;
        $showlabel = $haszone && $this->width >= self::ZONE_LABEL_MIN_WIDTH;
        $zonelabel = $haszone
            ? get_string('sparkline_zone_label', 'block_feedback_tracker', (int) round((float) $this->goal))
            : '';

        return [
            'empty'      => empty($points),
            'width'      => $this->width,
            'height'     => $this->height,
            'polyline'   => implode(' ', $points),
            'haszone'    => $haszone,
            'zoney'      => $zoney,
            'zoneheight' => $haszone ? $zoney : null,
            'showlabel'  => $showlabel,
            'zonelabel'  => $zonelabel,
            /* Localised, and it names the window the sparkline actually draws.
             * The previous hard-coded label said 30 days while the card
             * renders 14, so assistive tech was told the wrong period. */
            'arialabel'  => get_string('sparkline_aria', 'block_feedback_tracker'),
        ];
    }
}
