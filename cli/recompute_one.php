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
 * CLI: recompute the rollup for one (courseid, groupid).
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help'     => false,
        'courseid' => 0,
        'groupid'  => 0,
    ],
    [
        'h' => 'help',
        'c' => 'courseid',
        'g' => 'groupid',
    ]
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help'] || (int) $options['courseid'] <= 0 || (int) $options['groupid'] < 0) {
    echo <<<HELP
Recompute the rollup row for one (courseid, groupid).

Options:
  -h, --help          Show this help.
  -c, --courseid=ID   Course id (required).
  -g, --groupid=ID    Group id (0 or omitted = no-group rollup).

Exits with status 1 when another process holds the tuple's lock and nothing
was recomputed.

Example:
  php blocks/feedback_tracker/cli/recompute_one.php --courseid=2 --groupid=5

HELP;
    exit($options['help'] ? 0 : 1);
}

$courseid = (int) $options['courseid'];
$groupid = (int) $options['groupid'];

mtrace("Recomputing rollup for courseid=$courseid groupid=$groupid ...");
if (!\block_feedback_tracker\local\sla\rollup_service::recompute_group($courseid, $groupid)) {
    cli_error('Not recomputed: another process holds the lock for this rollup. Try again later.');
}
mtrace('Done.');
