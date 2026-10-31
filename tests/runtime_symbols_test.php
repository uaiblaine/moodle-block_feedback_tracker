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
 * Checks that runtime code names only the globals a request has loaded.
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker;

/**
 * Fails when runtime code names a global constant, function or class that a
 * request may not have loaded.
 *
 * The PHPUnit process has loaded far more than a request: every plugin's
 * lib.php (and with mod/assign/feedback/editpdf/lib.php, mod_assign's
 * locallib.php) and the gradebook's constants. A test of code that uses them
 * passes while the browser gets "Undefined constant", as ASSIGN_FILTER_NONE
 * did on the teacher dashboard and GRADE_TYPE_NONE did on a web service. So
 * this test does not ask the running process what is defined. It reads which
 * files lib/setup.php always loads (its top-level require lines, followed
 * through the top-level require lines of those files) and requires every
 * other global a plugin file names to be declared by that file or by a file it
 * requires.
 *
 * The check is per file, not per path: a require in one method covers a use in
 * another. Out of scope: tests/, db/ (run by the installer and upgrader, which
 * load upgradelib), backup/ (loaded by core's backup and restore plans,
 * which load their own libraries first) and settings.php (built inside the
 * admin tree, which loads adminlib).
 *
 * @coversNothing
 */
final class runtime_symbols_test extends \basic_testcase {
    /** @var string[] Tokens after which a name is not a global reference. */
    private const NOT_A_REFERENCE_AFTER = [
        T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_CLASS,
        T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_USE, T_GOTO, T_EXTENDS, T_IMPLEMENTS,
    ];

    /**
     * Every global a runtime file names is loaded on every request, built into
     * PHP, autoloaded, or declared by the file or a file it requires.
     *
     * @return void
     */
    public function test_runtime_code_names_only_loaded_globals(): void {
        $root = dirname(__DIR__);
        $baseline = self::baseline();
        $phpconstants = get_defined_constants(true);
        unset($phpconstants['user']);
        $phpconstants = array_merge(...array_values($phpconstants));
        $phpfunctions = array_flip(get_defined_functions()['internal']);

        $unloaded = [];
        foreach (self::runtime_files($root) as $file) {
            $rel = substr($file, strlen($root) + 1);
            $declared = null;
            foreach (self::references($file) as [$kind, $name, $line]) {
                $key = $kind === 'constant' ? $name : strtolower($name);
                if (isset($baseline[$kind][$key])) {
                    continue;
                }
                if ($kind === 'constant' && array_key_exists($name, $phpconstants)) {
                    continue;
                }
                if ($kind === 'function' && isset($phpfunctions[$key])) {
                    continue;
                }
                if ($kind === 'class' && self::is_autoloaded($name)) {
                    continue;
                }
                $declared ??= self::declared_by($file, true);
                if (!isset($declared[$kind][$key])) {
                    $unloaded[] = "$rel:$line $kind $name";
                }
            }
        }
        $this->assertSame(
            [],
            $unloaded,
            "These globals are not loaded on every request: require the file that declares them where they are used."
        );
    }

    /**
     * The baseline holds what lib/setup.php loads and leaves out the two
     * constants this test exists for: without that, it could see neither.
     *
     * @return void
     */
    public function test_the_baseline_is_what_every_request_loads(): void {
        $baseline = self::baseline();
        $this->assertArrayHasKey('get_string', $baseline['function']);
        $this->assertArrayHasKey('groups_get_activity_groupmode', $baseline['function']);
        $this->assertArrayHasKey('PARAM_INT', $baseline['constant']);
        $this->assertArrayHasKey('moodle_page', $baseline['class']);
        $this->assertArrayNotHasKey('ASSIGN_FILTER_NONE', $baseline['constant']);
        $this->assertArrayNotHasKey('GRADE_TYPE_NONE', $baseline['constant']);
        $this->assertArrayNotHasKey('cli_error', $baseline['function']);
        $this->assertArrayNotHasKey('assign', $baseline['class']);
    }

    /**
     * What lib/setup.php declares, through its top-level require lines.
     *
     * @return array Keys constant, function, class; each a set of names (functions and classes lower-cased).
     */
    private static function baseline(): array {
        global $CFG;
        static $baseline = null;
        if ($baseline === null) {
            $baseline = self::declared_by($CFG->libdir . '/setup.php', false);
        }
        return $baseline;
    }

    /**
     * The plugin's PHP files that run outside the installer, the upgrader and the admin tree.
     *
     * @param string $root The plugin root.
     * @return string[] Absolute paths.
     */
    private static function runtime_files(string $root): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $rel = substr($file->getPathname(), strlen($root) + 1);
            if (
                $file->getExtension() !== 'php'
                || preg_match('~^(tests|db|backup|lang|vendor|node_modules)/~', $rel)
                || in_array($rel, ['settings.php', 'version.php'], true)
            ) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files);
        return $files;
    }

    /**
     * The global constants, functions and classes a file names.
     *
     * Constants are recognised by their upper-case spelling; class names are
     * resolved through the file's namespace and use lines. A function called
     * without a namespace in a namespaced file is reported under its global
     * name, where PHP falls back to.
     *
     * @param string $file
     * @return array List of [kind, name, line].
     */
    private static function references(string $file): array {
        $tokens = array_values(array_filter(
            token_get_all(file_get_contents($file)),
            fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $namespace = '';
        $uses = [];
        $found = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if ($token[0] === T_NAMESPACE && is_array($next)) {
                $namespace = $next[1];
                continue;
            }
            if ($token[0] === T_USE && is_array($next) && in_array($next[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($next[1], '\\');
                $after = $tokens[$i + 2] ?? null;
                $alias = is_array($after) && $after[0] === T_AS ? $tokens[$i + 3][1] : substr(strrchr('\\' . $name, '\\'), 1);
                $uses[strtolower($alias)] = $name;
                continue;
            }
            if (!in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            $previousid = is_array($previous) ? $previous[0] : $previous;
            if (in_array($previousid, self::NOT_A_REFERENCE_AFTER, true)) {
                continue;
            }
            $name = ltrim($token[1], '\\');
            $nextid = is_array($next) ? $next[0] : $next;
            if ($previousid === T_NEW || $previousid === T_INSTANCEOF || $nextid === T_DOUBLE_COLON) {
                $class = self::resolve_class($token, $name, $namespace, $uses);
                if (!in_array(strtolower(substr(strrchr('\\' . $class, '\\'), 1)), ['self', 'static', 'parent'], true)) {
                    $found[] = ['class', $class, $token[2]];
                }
            } else if ($nextid === '(' && $token[0] !== T_NAME_QUALIFIED) {
                $found[] = ['function', $name, $token[2]];
            } else if ($token[0] === T_STRING && preg_match('/^[A-Z][A-Z0-9_]+$/', $name)) {
                $found[] = ['constant', $name, $token[2]];
            }
        }
        return $found;
    }

    /**
     * The fully qualified name a class token stands for.
     *
     * @param array $token
     * @param string $name The token text without a leading backslash.
     * @param string $namespace The file's namespace.
     * @param array $uses Lower-cased alias => imported name.
     * @return string
     */
    private static function resolve_class(array $token, string $name, string $namespace, array $uses): string {
        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            return $name;
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($uses[$first])) {
            return $uses[$first] . substr($name, strlen($first));
        }
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * Whether a class is built into PHP or comes from an autoloaded file.
     *
     * The class may already be loaded in this process for another reason, so
     * the answer is the file it lives in, not whether it exists.
     *
     * @param string $class
     * @return bool
     */
    private static function is_autoloaded(string $class): bool {
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
            return false;
        }
        $reflection = new \ReflectionClass($class);
        if ($reflection->isInternal()) {
            return true;
        }
        $path = str_replace('\\', '/', (string) $reflection->getFileName());
        return str_contains($path, '/classes/') || str_contains($path, '/vendor/');
    }

    /**
     * What a file declares, with what its require lines bring in.
     *
     * Only top-level declarations and require lines count (Moodle writes them
     * in column 0), so a function's own require does not widen the baseline.
     * The file under test is the exception: there a require anywhere counts.
     *
     * @param string $file
     * @param bool $anyrequire Whether an indented require line of this file counts.
     * @return array Keys constant, function, class; each a set of names (functions and classes lower-cased).
     */
    private static function declared_by(string $file, bool $anyrequire): array {
        $declared = ['constant' => [], 'function' => [], 'class' => []];
        $seen = [];
        $pending = [[$file, $anyrequire]];
        while ($pending) {
            [$current, $any] = array_pop($pending);
            $real = realpath($current);
            if ($real === false || isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;
            $source = file_get_contents($real);
            preg_match_all('~\bdefine\(\s*[\'"](\w+)[\'"]~', $source, $m);
            $declared['constant'] += array_fill_keys($m[1], true);
            preg_match_all('~^function\s+&?\s*(\w+)\s*\(~m', $source, $m);
            $declared['function'] += array_fill_keys(array_map('strtolower', $m[1]), true);
            preg_match_all('~^(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)~m', $source, $m);
            $declared['class'] += array_fill_keys(array_map('strtolower', $m[1]), true);
            foreach (self::required_files($real, $source, $any) as $required) {
                $pending[] = [$required, false];
            }
        }
        return $declared;
    }

    /**
     * The files a source's require and include lines name, when the path is
     * written from $CFG->libdir, $CFG->dirroot or __DIR__ and a literal.
     *
     * @param string $file The file the source was read from.
     * @param string $source
     * @param bool $anyindent Whether an indented line counts.
     * @return string[]
     */
    private static function required_files(string $file, string $source, bool $anyindent): array {
        global $CFG;
        $bases = ['$CFG->libdir' => $CFG->libdir, '$CFG->dirroot' => $CFG->dirroot, '__DIR__' => dirname($file)];
        $indent = $anyindent ? '[ \t]*' : '';
        $pattern = '~^' . $indent . '(?:require|include)(?:_once)?\s*\(?\s*'
            . '(?:(\$CFG->libdir|\$CFG->dirroot|__DIR__)\s*\.\s*\'([^\']+)\'|"(\$CFG->libdir|\$CFG->dirroot)([^"$]+)")~m';
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
        $files = [];
        foreach ($matches as $match) {
            $quoted = ($match[1] ?? '') === '';
            $files[] = $bases[$quoted ? $match[3] : $match[1]] . ($quoted ? $match[4] : $match[2]);
        }
        return $files;
    }
}
