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
 * MUC cache definitions for Feedback Flow
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Per-day resolved calendar rule (type, weekend flag, business hours, is_active).
    // Keys are "{calver}_{ymd}" so a calver bump naturally invalidates old entries
    // without an explicit purge.
    'calendar_effective_day' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false,
        'simplevalues' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 200,
    ],

    // Pause windows (site + course + group) that could affect a given courseid.
    // Keys are "{calver}_{courseid}".
    'pause_windows_by_course' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false,
        'simplevalues' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 100,
    ],

    // Block payload from responsiveness_payload::for_course(). Session-scoped; key
    // is "{calver}_{userid}_{courseid}" plus suffixes for the day ruler, the page and
    // the sort. Entries older than its CACHE_TTL, by their lastsynced field, are ignored.
    'responsiveness_payload' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => false,
        'simplevalues' => false,
        'staticacceleration' => true,
    ],

    // Dashboard payloads of get_dashboard and get_insights. Session-scoped; each
    // key starts with its web service's own key version, then calver and userid.
    'dashboard_payload' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => false,
        'simplevalues' => false,
        'staticacceleration' => true,
    ],

    // School-wide comparison stats. Application-scoped; key is "{calver}_{days}".
    'site_comparison' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false,
        'simplevalues' => false,
        'staticacceleration' => true,
    ],
];
