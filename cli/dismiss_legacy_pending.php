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
 * CLI: take the pending rows that lost their response before the cycle model out of every figure.
 *
 * Lists by default and changes nothing without --run: a dismissal cannot be
 * undone from here, and the list is what an administrator checks first.
 * {@see \block_feedback_tracker\local\sla\legacy_dismissal} says which rows
 * qualify and what a dismissal does.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_feedback_tracker\local\sla\legacy_dismissal;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help'     => false,
        'courseid' => 0,
        'before'   => '',
        'run'      => false,
    ],
    [
        'h' => 'help',
        'c' => 'courseid',
    ]
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help']) {
    echo <<<HELP
Take out of every figure the pending submissions that lost their response
before this plugin recorded grading cycles.

Before the cycle model, a student who saved an already-graded submission again
erased its response: the row went back to pending, with its clock running from
that save. Moodle no longer holds the original hand-in time, so the response
cannot be rebuilt. A dismissed row leaves the pending lists, the counts and
every median, without a measurement; if the student saves new work later, it
is tracked again as a new cycle.

Selected: pending rows of the first cycle with no recorded mark, whose attempt
carries a mark older than the hand-in, handed in before the cutoff. The cutoff
defaults to the moment this site was upgraded to the cycle model; a site
installed after that has nothing to dismiss unless --before is given.

Lists the rows and changes nothing unless --run is given.

Options:
  -h, --help          Show this help.
  -c, --courseid=ID   Limit to one course (0 / omitted = every course).
  --before=WHEN       Cutoff: a date (YYYY-MM-DD, server time zone) or a Unix
                      timestamp. Defaults to the cycle-model upgrade.
  --run               Dismiss the listed rows.

Examples:
  php blocks/feedback_tracker/cli/dismiss_legacy_pending.php
  php blocks/feedback_tracker/cli/dismiss_legacy_pending.php --courseid=42
  php blocks/feedback_tracker/cli/dismiss_legacy_pending.php --run

HELP;
    exit(0);
}

$courseid = max(0, (int) $options['courseid']);
$beforeopt = trim((string) $options['before']);
if ($beforeopt === '') {
    $before = legacy_dismissal::cycle_model_time();
    if ($before === null) {
        mtrace('This site never ran the cycle-model upgrade, so no row predates it. Pass --before to choose a cutoff.');
        exit(0);
    }
} else if (ctype_digit($beforeopt)) {
    $before = (int) $beforeopt;
} else {
    try {
        $before = (new \DateTimeImmutable($beforeopt, \core_date::get_server_timezone_object()))->getTimestamp();
    } catch (\Exception $e) {
        cli_error("--before: not a date or a timestamp: $beforeopt");
    }
}

// The fixday and fixhour arguments off: userdate() strips leading zeros by default.
$format = '%Y-%m-%d %H:%M';
$when = fn(int $time): string => userdate($time, $format, 99, false, false);
$rows = legacy_dismissal::candidates($before, $courseid);
mtrace(sprintf(
    '%d row(s) handed in before %s%s:',
    count($rows),
    $when($before),
    $courseid > 0 ? " in courseid=$courseid" : ''
));
foreach ($rows as $r) {
    mtrace(sprintf(
        '  id=%d courseid=%d cmid=%d userid=%d attempt=%d handed in %s, marked %s',
        (int) $r->id,
        (int) $r->courseid,
        (int) $r->cmid,
        (int) $r->userid,
        (int) $r->attemptnumber,
        $when((int) $r->timesubmitted),
        $when((int) $r->timemark)
    ));
}

if (empty($rows)) {
    exit(0);
}
if (!$options['run']) {
    mtrace('Nothing changed. Run again with --run to dismiss these rows.');
    exit(0);
}

$dismissed = legacy_dismissal::dismiss($before, $courseid, (int) get_admin()->id);
mtrace("Dismissed $dismissed row(s). Their rollups recompute on the next drain_queue run.");
exit(0);
