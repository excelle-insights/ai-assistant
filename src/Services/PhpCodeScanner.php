<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Contracts\CodeScannerInterface;

/**
 * Reads PHP source and finds the queries inside it.
 *
 * Where the scan starts: the navigation map. A CRM's sidebar already lists its
 * modules and points at the page that handles each one, so the sidebar is the
 * index of the application — it leads to the pages a person actually operates,
 * and from there to the forms, lists, reports and AJAX handlers they include.
 * Only when that map is missing or thin does a bounded directory sweep take
 * over.
 *
 * What comes out is text, never something to run: a statement, the tables it
 * touches, the join conditions and the WHERE fragments that look like business
 * rules. The pack stays the authority on what may be executed.
 */
final class PhpCodeScanner implements CodeScannerInterface
{
    /** Files whose name suggests they are the navigation map. */
    private const NAVIGATION_NAMES = ['sidebar_menu', 'sidebar', 'menu', 'navigation', 'nav', 'routes', 'router', 'navigation_map'];

    /** Directories that are never the CRM's own code. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', '.svn', 'storage', 'cache', 'logs', 'tmp', 'build', 'dist', 'coverage'];

    /** Words that follow FROM/JOIN but are not a table. */
    private const NOT_TABLES = [
        'select', 'dual', 'set', 'values', 'where', 'group', 'order', 'limit', 'having',
        'union', 'insert', 'update', 'delete', 'on', 'using', 'inner', 'left', 'right',
        'full', 'cross', 'natural', 'straight_join', 'from',
    ];

    /** @var array<string,mixed> */
    private array $options;

    /** @param array<string,mixed> $options max_files, max_size, max_depth */
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * @param string[]            $paths
     * @param array<string,mixed> $options
     * @return array<int,array{path:string,hash:string,size:int,mtime:int,module:string}>
     */
    public function files(array $paths, array $options = []): array
    {
        $options = $options + $this->options;
        $maxFiles = max(1, (int) ($options['max_files'] ?? 800));
        $maxSize = max(1024, (int) ($options['max_size'] ?? 1048576));
        $maxDepth = max(1, (int) ($options['max_depth'] ?? 8));

        $roots = [];
        foreach ($paths as $path) {
            $real = realpath((string) $path);
            if ($real !== false && is_dir($real)) {
                $roots[] = $real;
            }
        }
        if ($roots === []) {
            return [];
        }

        $modules = $this->navigationModules($roots, $maxDepth, (array) ($options['navigation'] ?? []));
        $byPath = [];
        foreach ($modules as $module) {
            foreach ($module['files'] as $file) {
                if (!isset($byPath[$file])) {
                    $byPath[$file] = $module['name'];
                }
            }
        }

        // The map is the index; the sweep fills what it missed. A CRM that
        // splits its menu into a file per section has many navigation files,
        // and a page the menu never names is still code the assistant should
        // be able to read.
        foreach ($roots as $root) {
            foreach ($this->sweep($root, $maxDepth, $maxFiles, $maxSize) as $file) {
                if (!isset($byPath[$file])) {
                    $byPath[$file] = '';
                }
            }
        }

        $out = [];
        foreach ($byPath as $file => $module) {
            if (count($out) >= $maxFiles) {
                break;
            }
            $info = @stat($file);
            if ($info === false || (int) $info['size'] > $maxSize) {
                continue;
            }
            $hash = @hash_file('sha256', $file);
            if ($hash === false) {
                continue;
            }
            $out[] = [
                'path' => $file,
                'hash' => $hash,
                'size' => (int) $info['size'],
                'mtime' => (int) $info['mtime'],
                'module' => (string) $module,
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $files
     * @param array<string,mixed>            $options
     * @return array<int,array<string,mixed>>
     */
    public function statements(array $files, array $options = []): array
    {
        $out = [];
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '' || !is_file($path)) {
                continue;
            }
            $code = @file_get_contents($path);
            if ($code === false || $code === '') {
                continue;
            }
            $module = (string) ($file['module'] ?? '');
            foreach ($this->parse((string) $code, $path, $module) as $statement) {
                $out[] = $statement;
            }
        }

        return $out;
    }

    // ─── the navigation map ──────────────────────────────────────────────────

    /**
     * Modules as the sidebar shows them, and the files each one reaches.
     *
     * @param string[] $roots
     * @param string[] $named navigation files the host named explicitly
     * @return array<int,array{name:string,files:array<int,string>}>
     */
    private function navigationModules(array $roots, int $maxDepth, array $named = []): array
    {
        $modules = [];
        foreach ($roots as $root) {
            foreach ($this->navigationFiles($root, $maxDepth, $named) as $nav) {
                foreach ($this->modulesIn($nav, $root) as $module) {
                    $modules[] = $module;
                }
            }
        }

        return $modules;
    }

    /**
     * The navigation file(s): the ones whose name says "menu", "sidebar" or
     * "routes", or the ones the host named.
     *
     * @param string[] $named
     * @return string[]
     */
    private function navigationFiles(string $root, int $maxDepth, array $named = []): array
    {
        $found = [];
        foreach ($named as $file) {
            $real = realpath((string) $file);
            if ($real !== false && is_file($real)) {
                $found[] = $real;
            }
        }
        $this->walk($root, $maxDepth, function (string $file) use (&$found): void {
            $name = strtolower(basename($file));
            $name = (string) preg_replace('/\.php$/', '', $name);
            foreach (self::NAVIGATION_NAMES as $candidate) {
                if (str_contains($name, $candidate)) {
                    $found[] = $file;
                    return;
                }
            }
        });

        return array_slice(array_values(array_unique($found)), 0, 40);
    }

    /**
     * Read one navigation file: every page it points at, the label in front of
     * it, and the files that page includes.
     *
     * @return array<int,array{name:string,files:array<int,string>}>
     */
    private function modulesIn(string $nav, string $root): array
    {
        $code = @file_get_contents($nav);
        if ($code === false || $code === '') {
            return [];
        }
        $literals = $this->literals((string) $code);
        $modules = [];
        $pendingLabel = '';
        foreach ($literals as $literal) {
            $text = trim($literal);
            if ($text === '') {
                continue;
            }
            if ($this->looksLikePage($text)) {
                $page = $this->resolvePage($text, $nav, $root);
                if ($page === null) {
                    continue;
                }
                $name = $pendingLabel !== '' ? $pendingLabel : $this->labelFor($page);
                $files = [$page];
                foreach ($this->includesOf($page, $root) as $included) {
                    $files[] = $included;
                }
                $modules[] = ['name' => $name, 'files' => array_values(array_unique($files))];
                $pendingLabel = '';
                continue;
            }
            if ($this->looksLikeLabel($text)) {
                $pendingLabel = $text;
            }
        }

        return $modules;
    }

    /** A literal that names a page of the application. */
    private function looksLikePage(string $text): bool
    {
        if (strlen($text) > 200 || str_contains($text, ' ')) {
            return false;
        }
        if (str_starts_with($text, 'http') || str_contains($text, '://')) {
            return false;
        }
        return str_ends_with(strtolower($text), '.php');
    }

    /**
     * A literal that reads like a menu entry.
     *
     * A single word is a label too — "Payments" is a module name, not a table
     * reference — so the test is not the word count but the shape: a label has
     * no path, no file extension and no identifier punctuation.
     */
    private function looksLikeLabel(string $text): bool
    {
        $length = strlen($text);
        if ($length < 2 || $length > 60) {
            return false;
        }
        if (str_contains($text, '/') || str_contains($text, '\\') || str_contains($text, '.')) {
            return false;
        }
        if (str_contains($text, '_')) {
            return false;
        }

        return preg_match('/[a-z]/i', $text) === 1;
    }

    /**
     * The file a menu entry points at.
     */
    private function resolvePage(string $target, string $nav, string $root): ?string
    {
        $target = str_replace('\\', '/', $target);
        $candidates = [
            $root . '/' . ltrim($target, '/'),
            dirname($nav) . '/' . ltrim($target, '/'),
            dirname($nav) . '/' . basename($target),
            $root . '/admin/' . basename($target),
        ];
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real) && str_ends_with(strtolower($real), '.php')) {
                return $real;
            }
        }

        // Fall back to a unique basename match inside the root.
        $base = basename($target);
        $matches = [];
        $this->walk($root, 8, function (string $file) use (&$matches, $base): void {
            if (basename($file) === $base) {
                $matches[] = $file;
            }
        });
        if (count($matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    /**
     * The files a page pulls in: the forms, lists and handlers it uses.
     *
     * @return string[]
     */
    private function includesOf(string $page, string $root): array
    {
        $code = @file_get_contents($page);
        if ($code === false || $code === '') {
            return [];
        }
        $out = [];
        foreach ($this->includeTargets((string) $code) as $target) {
            foreach ([dirname($page) . '/' . $target, $root . '/' . ltrim($target, '/')] as $candidate) {
                $real = realpath($candidate);
                if ($real !== false && is_file($real) && str_ends_with(strtolower($real), '.php')) {
                    $out[] = $real;
                    break;
                }
            }
        }

        return array_values(array_unique($out));
    }

    // ─── SQL extraction ──────────────────────────────────────────────────────

    /**
     * The token that carries the text inside an interpolated heredoc.
     *
     * PHP has named it more than once (T_ENCAPSED_AND_QUOTED, then
     * T_ENCAPSED_AND_WHITESPACE), and a constant that does not exist is a
     * fatal error, so it is looked up at runtime.
     *
     * @return int[]
     */
    private static function heredocTextTokens(): array
    {
        static $tokens = null;
        if ($tokens !== null) {
            return $tokens;
        }
        $tokens = [];
        foreach (['T_ENCAPSED_AND_WHITESPACE', 'T_ENCAPSED_AND_QUOTED'] as $name) {
            if (defined($name)) {
                $tokens[] = (int) constant($name);
            }
        }
        if ($tokens === []) {
            $tokens[] = 268;
        }

        return $tokens;
    }

    /**
     * Every query in one file, with the function it sits in.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parse(string $code, string $path, string $module): array
    {
        $statements = [];
        foreach ($this->sqlLiterals($code) as $literal) {
            $sql = trim($literal['sql']);
            if (!$this->looksLikeSql($sql)) {
                continue;
            }
            $statements[] = [
                'path' => $path,
                'module' => $module,
                'function' => (string) $literal['function'],
                'sql' => $sql,
                'tables' => $this->tablesIn($sql),
                'joins' => $this->joinsIn($sql),
                'where' => $this->whereIn($sql),
            ];
        }

        return $statements;
    }

    /**
     * The string literals in a file that look like SQL, with the function each
     * one sits in.
     *
     * @return array<int,array{sql:string,function:string}>
     */
    public function sqlLiterals(string $code): array
    {
        $tokens = @token_get_all($code);
        if ($tokens === []) {
            return [];
        }
        $out = [];
        $function = '';
        $depth = 0;
        $functionDepth = 0;
        $heredoc = null;

        foreach ($tokens as $token) {
            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                    if ($function !== '' && $depth < $functionDepth) {
                        $function = '';
                    }
                }
                continue;
            }
            [$id, $text] = $token;

            if ($id === T_FUNCTION) {
                // The name follows the keyword, sometimes after "&".
                $function = '__anonymous__';
                continue;
            }
            if ($id === T_STRING && $function === '__anonymous__') {
                $function = $text;
                $functionDepth = $depth + 1;
                continue;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = ['sql' => $this->unquote($text), 'function' => $function];
                continue;
            }
            if ($id === T_START_HEREDOC) {
                $heredoc = ['sql' => '', 'function' => $function, 'dynamic' => false];
                continue;
            }
            if ($heredoc !== null && in_array($id, self::heredocTextTokens(), true)) {
                $heredoc['sql'] .= $text;
                continue;
            }
            if ($heredoc !== null && $id === T_VARIABLE) {
                $heredoc['dynamic'] = true;
                continue;
            }
            if ($heredoc !== null && $id === T_END_HEREDOC) {
                if (!$heredoc['dynamic']) {
                    $out[] = ['sql' => $heredoc['sql'], 'function' => $heredoc['function']];
                }
                $heredoc = null;
                continue;
            }
        }

        return $out;
    }

    /** The tables a statement touches. @return string[] */
    public function tablesIn(string $sql): array
    {
        $out = [];
        if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $sql, $matches) > 0) {
            foreach ($matches[1] as $table) {
                $name = strtolower(trim($table));
                if ($name === '' || in_array($name, self::NOT_TABLES, true)) {
                    continue;
                }
                $out[$name] = true;
            }
        }

        return array_keys($out);
    }

    /** The join conditions of a statement. @return string[] */
    public function joinsIn(string $sql): array
    {
        $out = [];
        $pattern = '/\bJOIN\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+(?:ON\s+(.+?))?(?=\bWHERE\b|\bGROUP\b|\bORDER\b|\bLIMIT\b|\bHAVING\b|$)/is';
        if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $condition = trim((string) ($match[2] ?? ''));
                if ($condition === '') {
                    continue;
                }
                $out[] = strtolower((string) $match[1]) . ' ON ' . $this->tidy($condition);
            }
        }

        return array_values(array_unique($out));
    }

    /** The WHERE fragments of a statement — the rules that decide a row. @return string[] */
    public function whereIn(string $sql): array
    {
        $out = [];
        $pattern = '/\bWHERE\b(.{0,300}?)(?=\bGROUP\b|\bORDER\b|\bLIMIT\b|\bHAVING\b|\bUNION\b|$)/is';
        if (preg_match_all($pattern, $sql, $matches) > 0) {
            foreach ($matches[1] as $fragment) {
                $clean = $this->tidy($fragment);
                if ($clean !== '') {
                    $out[] = $clean;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** One line, no noise, for a rule the assistant reads. */
    private function tidy(string $text): string
    {
        $clean = (string) preg_replace('/\s+/', ' ', trim($text));
        $clean = (string) preg_replace('/\s*,\s*/', ', ', $clean);

        return trim($clean, " ,");
    }

    private function looksLikeSql(string $sql): bool
    {
        if (strlen($sql) < 12) {
            return false;
        }
        if (preg_match('/^(SELECT|INSERT\s+INTO|INSERT|UPDATE|DELETE\s+FROM|REPLACE\s+INTO|REPLACE|WITH)\b/i', ltrim($sql)) !== 1) {
            return false;
        }

        return str_contains(strtolower($sql), ' ') && !str_contains($sql, '{');
    }

    /** Strip the quotes from a string literal. */
    private function unquote(string $literal): string
    {
        $text = trim($literal);
        $length = strlen($text);
        if ($length >= 2) {
            $first = $text[0];
            $last = $text[$length - 1];
            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
                $text = substr($text, 1, -1);
                if ($first === "'") {
                    return str_replace(["\\'", '\\\\'], ["'", '\\'], $text);
                }
                return (string) preg_replace('/\\\\(["\\\\$])/', '$1', $text);
            }
        }

        return $text;
    }

    // ─── file walking ────────────────────────────────────────────────────────

    /**
     * Every PHP file under a root, bounded.
     *
     * @return string[]
     */
    private function sweep(string $root, int $maxDepth, int $maxFiles, int $maxSize): array
    {
        $out = [];
        $this->walk($root, $maxDepth, function (string $file) use (&$out, $maxFiles, $maxSize): void {
            if (count($out) >= $maxFiles) {
                return;
            }
            $size = @filesize($file);
            if ($size === false || $size > $maxSize) {
                return;
            }
            $out[] = $file;
        }, true);

        return $out;
    }

    /**
     * @param callable(string):void $visit
     */
    private function walk(string $root, int $maxDepth, callable $visit, bool $phpOnly = false): void
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $queue = [[$root, 0]];
        while ($queue !== []) {
            [$dir, $depth] = array_shift($queue);
            if ($depth > $maxDepth) {
                continue;
            }
            $entries = @scandir($dir);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($path)) {
                    if (in_array(strtolower($entry), self::SKIP_DIRS, true)) {
                        continue;
                    }
                    $queue[] = [$path, $depth + 1];
                    continue;
                }
                if (!str_ends_with(strtolower($entry), '.php')) {
                    continue;
                }
                $visit($path);
            }
        }
    }

    // ─── small helpers ───────────────────────────────────────────────────────

    /**
     * The string literals of a file, in order.
     *
     * @return string[]
     */
    public function literals(string $code): array
    {
        $out = [];
        $tokens = @token_get_all($code);
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = $this->unquote((string) $token[1]);
            }
        }

        return $out;
    }

    /**
     * The paths a file includes or requires.
     *
     * @return string[]
     */
    public function includeTargets(string $code): array
    {
        $out = [];
        $tokens = @token_get_all($code);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if (!in_array($token[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                continue;
            }
            for ($j = $i + 1; $j < min($i + 5, $count); $j++) {
                $next = $tokens[$j];
                if (is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $out[] = $this->unquote((string) $next[1]);
                    break;
                }
            }
        }

        return $out;
    }

    /** The name a page shows in the menu, when the map did not say. */
    private function labelFor(string $page): string
    {
        $name = basename($page, '.php');
        $name = (string) preg_replace('/[^a-zA-Z0-9]+/', ' ', $name);

        return ucfirst(trim($name));
    }
}
