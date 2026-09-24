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
 * Admin setting for three ordered numeric cutoffs.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\admin;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * A text setting holding three comma-separated cutoffs, which must be numbers,
 * in a fixed order and inside a range.
 *
 * The score bands are tested from the highest cutoff down
 * ({@see \block_feedback_tracker\local\score\responsiveness_calculator::band_for()})
 * and the wait-time buckets from the lowest up
 * ({@see \block_feedback_tracker\local\sla\bucket::for_effective()}), so a value
 * saved out of order would put every score or submission on the site in the
 * wrong band. The order is strict: two equal cutoffs would leave a band no
 * value can reach. The parsers stay tolerant of whatever was stored before this
 * check existed.
 */
class thresholds_setting extends \admin_setting_configtext {
    /** Each cutoff must be larger than the one before it. */
    public const ASCENDING = 'ascending';

    /** Each cutoff must be smaller than the one before it. */
    public const DESCENDING = 'descending';

    /** @var string Required order, self::ASCENDING or self::DESCENDING. */
    protected string $order;

    /** @var float Lowest accepted cutoff. */
    protected float $min;

    /** @var float|null Highest accepted cutoff, or null for no upper bound. */
    protected ?float $max;

    /**
     * Constructor.
     *
     * @param string $name Setting name, 'block_feedback_tracker/<key>'.
     * @param string $visiblename Localised name.
     * @param string $description Localised description.
     * @param string $defaultsetting Default value, three comma-separated numbers.
     * @param string $order self::ASCENDING or self::DESCENDING.
     * @param float $min Lowest accepted cutoff.
     * @param float|null $max Highest accepted cutoff, or null for no upper bound.
     */
    public function __construct(
        $name,
        $visiblename,
        $description,
        $defaultsetting,
        string $order,
        float $min = 0.0,
        ?float $max = null
    ) {
        $this->order = $order === self::DESCENDING ? self::DESCENDING : self::ASCENDING;
        $this->min = $min;
        $this->max = $max;
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_TEXT);
    }

    /**
     * Accept exactly three numbers, each inside the range and each strictly
     * after the previous one in the required order.
     *
     * @param string $data Submitted value.
     * @return true|string True when valid, otherwise the error message.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }

        // The shipped default is the example. It is read from the property, not
        // get_defaultsetting(), which builds the whole admin tree.
        $formaterror = get_string('settings_thresholds_error_format', 'block_feedback_tracker', (string) $this->defaultsetting);
        $parts = array_map('trim', explode(',', (string) $data));
        if (count($parts) !== 3) {
            return $formaterror;
        }
        $values = [];
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                return $formaterror;
            }
            $values[] = (float) $part;
        }

        foreach ($values as $value) {
            if ($this->max !== null && ($value < $this->min || $value > $this->max)) {
                return get_string('settings_thresholds_error_range', 'block_feedback_tracker', (object) [
                    'min' => $this->number($this->min),
                    'max' => $this->number($this->max),
                ]);
            }
            if ($this->max === null && $value < $this->min) {
                return get_string('settings_thresholds_error_min', 'block_feedback_tracker', $this->number($this->min));
            }
        }

        foreach ([1, 2] as $i) {
            if ($this->order === self::ASCENDING && !($values[$i] > $values[$i - 1])) {
                return get_string('settings_thresholds_error_ascending', 'block_feedback_tracker');
            }
            if ($this->order === self::DESCENDING && !($values[$i] < $values[$i - 1])) {
                return get_string('settings_thresholds_error_descending', 'block_feedback_tracker');
            }
        }
        return true;
    }

    /**
     * A bound as the admin would type it: no trailing zeros, the site's decimal
     * separator.
     *
     * @param float $value Bound to format.
     * @return string
     */
    private function number(float $value): string {
        return format_float($value, 2, true, true);
    }
}
