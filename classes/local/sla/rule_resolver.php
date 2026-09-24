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
 * Effective open/due/cut-off dates of an assign for one student or one group.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Computes the allowsubmissionsfromdate / duedate / cutoffdate a submission
 * is judged against, as mod_assign applies them.
 *
 * Each date is resolved field by field from the first of: the student's own
 * override, the governing group override, the activity. The governing group
 * override is the one with the lowest sortorder among every group the student
 * belongs to, as {@see \assign::override_exists()} and
 * `mod_assign_cm_info_dynamic()` choose it; a tie, which core never writes,
 * goes to the lowest id. Hidden groups count: core filters them by the viewing
 * user's capabilities, which would make a stored date depend on who triggered
 * the write. The fall-through is per field, as
 * in `mod_assign_cm_info_dynamic()`, which dates the activity for the student:
 * a user override that leaves the due date alone still lets a group override
 * supply it. override_exists() merges whole rows instead, so there the user
 * override's NULLs hide the group override's dates.
 *
 * NULL and 0 mean different things. In {assign_overrides} NULL is "not
 * overridden, inherit", while 0 is a date the override removed:
 * overrideedit.php nulls only the values left equal to the activity's, and
 * {@see \assign::update_effective_access()} applies anything isset(). In
 * {assign} itself 0 means "no such date". The resolved result uses null for
 * "no date" in every case.
 *
 * An extension ({assign_user_flags}.extensionduedate) then replaces the due
 * date for that student, whichever of the three sources supplied it, as the
 * grading table shows it, and moves the resolved cut-off later when it passes
 * it, as {@see \assign::submissions_open()} does; with no cut-off, including
 * one an override removed, it creates none. The open date is never affected.
 * rule_resolver_test::test_an_extension_applies_over_an_override() pins the
 * extension over an override.
 *
 * The per-student resolution runs in SQL ({@see self::joins_sql()},
 * {@see self::date_sql()}) so the ledger writer and the reconciler's
 * rule-drift probe evaluate the very same expressions and cannot disagree
 * about a row.
 */
class rule_resolver {
    /**
     * Rank given to a group override stored without a sortorder, so that it
     * orders after every numbered one. Core always numbers group overrides;
     * rows written by other means can still carry NULL.
     */
    private const UNSORTED_RANK = 2147483647;

    /** Ledger rule column => {assign} / {assign_overrides} date column. */
    private const FIELDS = [
        'timeopens' => 'allowsubmissionsfromdate',
        'timecloses' => 'duedate',
        'timecutoff' => 'cutoffdate',
    ];

    /**
     * Resolve the effective rule for one student on one assign.
     *
     * Reads the live {assign}, {assign_overrides}, {groups_members} and
     * {assign_user_flags} rows in one query. The result depends on the student
     * alone, never on the group the ledger attributes the row to, so every
     * write path stores the same dates for the same student.
     *
     * @param int $assignid The {assign} id.
     * @param int $userid The student.
     * @return array{timeopens:?int, timecloses:?int, timecutoff:?int, hasrule:int}
     */
    public static function resolve_rule(int $assignid, int $userid): array {
        global $DB;

        $select = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $select[] = self::date_sql($key, 'a') . ' AS ' . $key;
        }
        /* The student is joined as a row, not bound twice: the override
         * subqueries name the student id several times, and a named
         * placeholder may appear only once per statement. Moodle never removes
         * a {user} row (deleting an account only flags it), so the join finds
         * the same student the reconciler's probe reads from the ledger. */
        $row = $DB->get_record_sql(
            'SELECT ' . implode(', ', $select) . '
               FROM {assign} a
          LEFT JOIN {user} u ON u.id = :userid
               ' . self::joins_sql('a.id', 'u.id') . '
              WHERE a.id = :assignid',
            ['userid' => $userid, 'assignid' => $assignid]
        );

        $rule = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $rule[$key] = $row ? self::date_or_null($row->{$key}) : null;
        }
        return self::with_hasrule($rule);
    }

    /**
     * The three LEFT JOINs a per-student resolution reads, for splicing into
     * a query that already has the activity and the student in scope.
     *
     * Adds the aliases `ruo` (the student's override), `rgo` (the governing
     * group override) and `ruf` (the student's flags row), each of them at
     * most one row: several user override or flags rows for one student are
     * not something core writes, and the lowest id is taken rather than
     * multiplying the caller's rows. The subqueries use aliases starting with
     * `rr`, which the caller must not use.
     *
     * @param string $assignid SQL expression for the {assign} id, e.g. `a.id`. A
     *                         column, never a placeholder: it appears several times.
     * @param string $userid SQL expression for the student id, e.g. `l.userid`. A
     *                       column, never a placeholder, for the same reason.
     * @return string SQL.
     */
    public static function joins_sql(string $assignid, string $userid): string {
        $rank = self::UNSORTED_RANK;
        return "LEFT JOIN {assign_overrides} ruo
                       ON ruo.id = (SELECT MIN(rru.id)
                                      FROM {assign_overrides} rru
                                     WHERE rru.assignid = $assignid
                                       AND rru.userid = $userid)
                LEFT JOIN {assign_overrides} rgo
                       ON rgo.id = (SELECT MIN(rrg.id)
                                      FROM {assign_overrides} rrg
                                      JOIN {groups_members} rrgm ON rrgm.groupid = rrg.groupid
                                     WHERE rrg.assignid = $assignid
                                       AND rrgm.userid = $userid
                                       AND COALESCE(rrg.sortorder, $rank) = (
                                           SELECT MIN(COALESCE(rrs.sortorder, $rank))
                                             FROM {assign_overrides} rrs
                                             JOIN {groups_members} rrsm ON rrsm.groupid = rrs.groupid
                                            WHERE rrs.assignid = $assignid
                                              AND rrsm.userid = $userid))
                LEFT JOIN {assign_user_flags} ruf
                       ON ruf.id = (SELECT MIN(rrf.id)
                                      FROM {assign_user_flags} rrf
                                     WHERE rrf.assignment = $assignid
                                       AND rrf.userid = $userid)";
    }

    /**
     * SQL for one effective date over the aliases {@see self::joins_sql()} adds.
     *
     * Evaluates to the date, or to 0 when the student has none; the expression
     * is never NULL. Compare a stored value with `COALESCE(stored, 0)`.
     *
     * @param string $key 'timeopens', 'timecloses' or 'timecutoff'.
     * @param string $assign Alias of the {assign} row in the caller's query.
     * @return string SQL expression.
     * @throws \coding_exception For an unknown key.
     */
    public static function date_sql(string $key, string $assign): string {
        if (!isset(self::FIELDS[$key])) {
            throw new \coding_exception('Unknown rule column: ' . $key);
        }
        $column = self::FIELDS[$key];
        $base = "COALESCE(ruo.$column, rgo.$column, $assign.$column)";
        switch ($key) {
            case 'timecloses':
                return "CASE WHEN COALESCE(ruf.extensionduedate, 0) > 0
                             THEN ruf.extensionduedate
                             ELSE $base END";
            case 'timecutoff':
                return "CASE WHEN $base > 0 AND COALESCE(ruf.extensionduedate, 0) > $base
                             THEN ruf.extensionduedate
                             ELSE $base END";
            default:
                return $base;
        }
    }

    /**
     * Pure merge of one optional override row over the assign defaults, with no
     * DB access and no extension. Used by the activity-schedule catalog, which
     * resolves a group's schedule rather than a student's: it batch-loads the
     * group overrides itself and resolves many (assign, group) pairs in memory
     * rather than one query per pair.
     *
     * Follows the NULL-versus-0 rule of the class: an override value of NULL
     * keeps the activity's date, 0 removes it.
     *
     * @param \stdClass $assign An {assign} row (id, allowsubmissionsfromdate,
     *                          duedate, cutoffdate).
     * @param \stdClass|null $override A single override row, or null.
     * @return array{timeopens:?int, timecloses:?int, timecutoff:?int, hasrule:int}
     */
    public static function merge_override(\stdClass $assign, ?\stdClass $override): array {
        $rule = [];
        foreach (self::FIELDS as $key => $column) {
            $raw = $assign->{$column} ?? null;
            if ($override !== null && isset($override->{$column})) {
                $raw = $override->{$column};
            }
            $rule[$key] = self::date_or_null($raw);
        }
        return self::with_hasrule($rule);
    }

    /**
     * A stored date column as a timestamp, or null for "no date": NULL, an
     * empty string and 0 (in either type) all mean none.
     *
     * @param mixed $raw
     * @return int|null
     */
    private static function date_or_null($raw): ?int {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = (int) $raw;
        return $value === 0 ? null : $value;
    }

    /**
     * Add the `hasrule` flag: 1 when any of the three dates is set.
     *
     * @param array $rule The three resolved dates keyed by ledger column.
     * @return array{timeopens:?int, timecloses:?int, timecutoff:?int, hasrule:int}
     */
    private static function with_hasrule(array $rule): array {
        $rule['hasrule'] = ($rule['timeopens'] ?? $rule['timecloses'] ?? $rule['timecutoff']) !== null ? 1 : 0;
        return $rule;
    }
}
