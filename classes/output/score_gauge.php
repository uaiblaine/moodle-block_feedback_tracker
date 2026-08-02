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
 * Score-gauge renderable.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\output;

/**
 * SVG ring gauge for the Academic Responsiveness Score (0-100), color-coded
 * by band (excellent / good / regular / critical). Rendered via Mustache;
 * see templates/score_gauge.mustache.
 */
class score_gauge implements \renderable, \templatable {
    /** Band → primary stroke / chip colour. Kept in lockstep with amd/src/lib/bands.js::BAND_COLOURS. */
    public const BAND_COLOURS = [
        'excellent' => '#047857',
        'good'      => '#0e7490',
        'regular'   => '#b45309',
        'critical'  => '#be4b25',
        'pending'   => '#475569',
        'nodata'    => '#94a3b8',
    ];

    /** @var float|null The responsiveness score. */
    public ?float $score;

    /** @var string|null The responsiveness band. */
    public ?string $band;

    /** @var int The gauge circle size. */
    public int $size;

    /**
     * Constructor for the score gauge.
     *
     * @param float|null $score Score 0..100, or null when there is no data.
     * @param string|null $band One of self::BAND_COLOURS keys.
     * @param int $size Pixel size for the SVG (square).
     */
    public function __construct(
        ?float $score,
        ?string $band,
        int $size = 100
    ) {
        $this->score = $score;
        $this->band = $band;
        $this->size = $size;
    }

    /**
     * Returns the colour for the configured band.
     *
     * @return string
     */
    public function colour(): string {
        $key = $this->band ?? 'pending';
        return self::BAND_COLOURS[$key] ?? self::BAND_COLOURS['pending'];
    }

    /**
     * Build the Mustache template context.
     *
     * @param \renderer_base $output Required by the `\templatable` interface
     *                                contract; not used here because the gauge
     *                                computes its own geometry.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $size = $this->size;
        $r = $size / 2 - 6;
        $cx = $size / 2;
        $circumference = 2 * M_PI * $r;
        $score = $this->score;
        $arc = $score !== null ? max(0.0, min(100.0, $score)) / 100.0 * $circumference : 0.0;
        $label = $score !== null ? (string) (int) round($score) : '—';
        return [
            'size'          => $size,
            'cx'            => $cx,
            'r'             => round($r, 2),
            'arc'           => round($arc, 2),
            'circumference' => round($circumference, 2),
            'colour'        => $this->colour(),
            'label'         => $label,
            // Localised so assistive tech is not read English on a pt_br site.
            'arialabel'     => get_string('gauge_aria', 'block_feedback_tracker', $label),
            'texty'         => round($cx + $size * 0.07, 2),
            'fontsize'      => (int) round($size * 0.28),
        ];
    }
}
