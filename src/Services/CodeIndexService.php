<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Contracts\CodeScannerInterface;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
use PDO;
use Throwable;

/**
 * The CRM's own code, read once and remembered.
 *
 * A CRM already contains the answers to "how is a paid invoice decided" and
 * "which tables does the payments report join" — in PHP, not in a document.
 * This service reads that code, keeps the queries it finds in the knowledge
 * base and hands the ones that match a question to the router.
 *
 * The scan is incremental. Every file gets a fingerprint (content hash, size,
 * modification time) in `{prefix}_code_files`; a second scan reads the
 * fingerprint first and only parses the files that changed. Deleted files lose
 * their entries instead of leaving orphans. The knowledge base deduplicates by
 * content hash, so the same statement cannot be stored twice.
 *
 * What is stored is *text*. Extracted SQL is never executed: the pack stays
 * the authority on what may run, and the host's table and column policy still
 * applies to every answer that uses what was learned.
 */
final class CodeIndexService
{
    private ?PDO $pdo;
    private ?KnowledgeService $knowledge;
    private CodeScannerInterface $scanner;
    /** @var array<string,mixed> */
    private array $options;

    /** @param array<string,mixed> $options paths, max_files, max_size, max_depth */
    public function __construct(
        ?PDO $pdo,
        ?KnowledgeService $knowledge,
        ?CodeScannerInterface $scanner = null,
        array $options = [],
    ) {
        $this->pdo = $pdo;
        $this->knowledge = $knowledge;
        $this->scanner = $scanner ?? new PhpCodeScanner($options);
        $this->options = $options;
    }

    /**
     * Read the code and store what changed.
     *
     * @param array<string,mixed> $opts paths, max_files, max_size, max_depth
     * @return array{scanned:int,files:int,changed:int,unchanged:int,statements:int,stored:int,removed:int,paths:array<int,string>,error:string}
     */
    public function scan(int $companyId, array $opts = []): array
    {
        $result = [
            'scanned' => 0, 'files' => 0, 'changed' => 0, 'unchanged' => 0,
            'statements' => 0, 'stored' => 0, 'removed' => 0, 'paths' => [], 'error' => '',
        ];
        if ($this->pdo === null || $this->knowledge === null) {
            $result['error'] = 'The code index needs a database and a knowledge store.';

            return $result;
        }
        $paths = $this->paths($opts);
        $result['paths'] = $paths;
        if ($paths === []) {
            $result['error'] = 'No code paths to scan. Set code.scan_paths to the folders that hold the application.';

            return $result;
        }

        $options = $this->options($opts);
        $files = $this->scanner->files($paths, $options);
        $result['files'] = count($files);
        if ($files === []) {
            $result['error'] = 'No PHP files were found in the configured paths.';

            return $result;
        }

        $known = $this->fingerprints($companyId);
        $changed = [];
        $seen = [];
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $seen[$path] = true;
            if (($known[$path]['hash'] ?? '') === (string) ($file['hash'] ?? '')) {
                $result['unchanged']++;
                continue;
            }
            $changed[] = $file;
        }
        $result['changed'] = count($changed);

        $statements = $changed === [] ? [] : $this->scanner->statements($changed, $options);
        $result['statements'] = count($statements);

        $byFile = [];
        foreach ($statements as $statement) {
            $byFile[(string) ($statement['path'] ?? '')][] = $statement;
        }

        foreach ($changed as $file) {
            $path = (string) $file['path'];
            $own = $byFile[$path] ?? [];
            foreach ($own as $statement) {
                if ($this->store($companyId, $statement)) {
                    $result['stored']++;
                }
            }
            $this->remember($companyId, $file, count($own));
        }

        // A file that is gone loses its entries instead of leaving orphans.
        $result['removed'] = $this->forgetMissing($companyId, $seen);

        return $result;
    }

    /**
     * The files the index knows about.
     *
     * @return array<int,array<string,mixed>>
     */
    public function files(int $companyId): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $rows = Db::select($this->pdo, Db::table('code_files'), ['company_id' => $companyId], [], [['module', 'asc'], ['path', 'asc']], 500);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'path' => (string) ($row['path'] ?? ''),
                'module' => (string) ($row['module'] ?? ''),
                'hash' => (string) ($row['hash'] ?? ''),
                'size' => (int) ($row['size'] ?? 0),
                'mtime' => (int) ($row['mtime'] ?? 0),
                'statements' => (int) ($row['statements'] ?? 0),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The queries the CRM already runs that match a question.
     *
     * @return array<int,array<string,mixed>>
     */
    public function knownQueries(int $companyId, string $question, int $k = 5): array
    {
        if ($this->knowledge === null || trim($question) === '') {
            return [];
        }
        try {
            $hits = $this->knowledge->retrieve($companyId, $question, max(5, $k * 4));
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($hits as $hit) {
            if ((string) ($hit['source'] ?? '') !== 'code') {
                continue;
            }
            $meta = (array) ($hit['metadata'] ?? []);
            $out[] = [
                'title' => (string) ($hit['title'] ?? ''),
                'file' => (string) ($meta['file'] ?? ''),
                'module' => (string) ($meta['module'] ?? ''),
                'function' => (string) ($meta['function'] ?? ''),
                'tables' => array_values((array) ($meta['tables'] ?? [])),
                'joins' => array_values((array) ($meta['joins'] ?? [])),
                'where' => array_values((array) ($meta['where'] ?? [])),
                'score' => (float) ($hit['score'] ?? 0),
                'sql' => (string) ($meta['sql'] ?? ''),
            ];
            if (count($out) >= $k) {
                break;
            }
        }

        return $out;
    }

    /**
     * A short "the CRM already has this query" section for the router prompt.
     *
     * The rules matter more than the names: "p.status = 'paid'" is how the
     * application itself decides that a payment is paid, which is exactly what
     * a description cannot say.
     */
    public function promptSection(int $companyId, string $question, int $k = 3): string
    {
        $queries = $this->knownQueries($companyId, $question, $k);
        if ($queries === []) {
            return '';
        }
        $lines = [];
        foreach ($queries as $query) {
            $head = '- ' . $query['title'];
            if ($query['tables'] !== []) {
                $head .= ' (' . implode(', ', array_slice($query['tables'], 0, 6)) . ')';
            }
            if ($query['file'] !== '') {
                $head .= ' — ' . $query['file'];
            }
            $lines[] = $head;
            foreach (array_slice($query['where'], 0, 4) as $rule) {
                $lines[] = '  Rule: ' . mb_substr(trim((string) $rule), 0, 200);
            }
            foreach (array_slice($query['joins'], 0, 3) as $join) {
                $lines[] = '  Join: ' . mb_substr(trim((string) $join), 0, 200);
            }
        }

        return "Queries this CRM already runs (read them; never run them as they are):\n" . implode("\n", $lines);
    }

    // ─── internals ────────────────────────────────────────────────────────────

    /**
     * Store one statement as knowledge.
     *
     * @param array<string,mixed> $statement
     */
    private function store(int $companyId, array $statement): bool
    {
        if ($this->knowledge === null) {
            return false;
        }
        $sql = trim((string) ($statement['sql'] ?? ''));
        if ($sql === '') {
            return false;
        }
        $module = trim((string) ($statement['module'] ?? ''));
        $function = trim((string) ($statement['function'] ?? ''));
        $tables = array_values((array) ($statement['tables'] ?? []));
        $title = $module !== '' ? $module . ': ' . ($function !== '' ? $function : 'query') : ($function !== '' ? $function : 'query');

        $lines = [$sql];
        if ($tables !== []) {
            $lines[] = 'Tables: ' . implode(', ', $tables);
        }
        foreach ((array) ($statement['joins'] ?? []) as $join) {
            $lines[] = 'Join: ' . $join;
        }
        foreach ((array) ($statement['where'] ?? []) as $where) {
            $lines[] = 'Rule: ' . $where;
        }

        try {
            $this->knowledge->ingestRow(
                $companyId,
                $title,
                implode("\n", $lines),
                'code',
                'query',
                [
                    // Global scope, not a custom one: a code fact is a fact about
                    // the application, not about a person or a role, so it must be
                    // retrievable without a viewer context.
                    'scope' => 'global',
                    'scope_key' => (string) ($statement['path'] ?? ''),
                    'metadata' => [
                        'file' => (string) ($statement['path'] ?? ''),
                        'module' => $module,
                        'function' => $function,
                        'tables' => $tables,
                        'joins' => array_values((array) ($statement['joins'] ?? [])),
                        'where' => array_values((array) ($statement['where'] ?? [])),
                        'sql' => $sql,
                    ],
                ],
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * The fingerprints of the files already read.
     *
     * @return array<string,array<string,mixed>>
     */
    private function fingerprints(int $companyId): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $rows = Db::select($this->pdo, Db::table('code_files'), ['company_id' => $companyId], [], [], 5000, '', ['path', 'hash', 'module', 'statements']);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(string) ($row['path'] ?? '')] = $row;
        }

        return $out;
    }

    /** @param array<string,mixed> $file */
    private function remember(int $companyId, array $file, int $statements): void
    {
        if ($this->pdo === null) {
            return;
        }
        $row = [
            'company_id' => $companyId,
            'path' => (string) ($file['path'] ?? ''),
            'hash' => (string) ($file['hash'] ?? ''),
            'size' => (int) ($file['size'] ?? 0),
            'mtime' => (int) ($file['mtime'] ?? 0),
            'module' => (string) ($file['module'] ?? ''),
            'statements' => $statements,
            'meta_json' => Db::json(['scanned_at' => Db::now()]),
            'updated_at' => Db::now(),
            'created_at' => Db::now(),
        ];
        try {
            Db::upsert(
                $this->pdo,
                Db::table('code_files'),
                $row,
                ['hash', 'size', 'mtime', 'module', 'statements', 'meta_json', 'updated_at'],
            );
        } catch (Throwable) {
            // The index is a convenience; a missing table must not stop a scan.
        }
    }

    /**
     * Drop the entries of files that no longer exist.
     *
     * @param array<string,true> $seen
     */
    private function forgetMissing(int $companyId, array $seen): int
    {
        if ($this->pdo === null || $this->knowledge === null) {
            return 0;
        }
        foreach ($this->fingerprints($companyId) as $path => $row) {
            if (isset($seen[$path])) {
                continue;
            }
            try {
                Db::deleteWhere($this->pdo, Db::table('code_files'), ['id' => (int) ($row['id'] ?? 0)]);
            } catch (Throwable) {
                // ignore
            }
        }
        $removed = 0;
        foreach ($this->codeEntries($companyId) as $entry) {
            $path = (string) ($entry['scope_key'] ?? '');
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            try {
                if ($this->knowledge->deactivate((int) ($entry['id'] ?? 0), $companyId)) {
                    $removed++;
                }
            } catch (Throwable) {
                // ignore
            }
        }

        return $removed;
    }

    /**
     * The knowledge entries that came from the code.
     *
     * @return array<int,array<string,mixed>>
     */
    private function codeEntries(int $companyId): array
    {
        if ($this->knowledge === null) {
            return [];
        }
        try {
            $rows = $this->knowledge->list($companyId, 5000);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if ((string) ($row['source'] ?? '') !== 'code') {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * The folders to read.
     *
     * A tenant's own setting wins over the deployment-wide option: one company
     * may point the scan at a different part of the application.
     *
     * @param array<string,mixed> $opts
     * @return string[]
     */
    private function paths(array $opts): array
    {
        $paths = (array) ($opts['paths'] ?? []);
        if ($paths === []) {
            $paths = Config::list('code.scan_paths', []);
        }
        if ($paths === []) {
            $paths = (array) ($this->options['paths'] ?? []);
        }
        $out = [];
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            $real = realpath($path);
            $out[] = $real !== false ? $real : $path;
        }

        return array_values(array_unique($out));
    }

    /**
     * The scan options: the call, then the tenant's setting, then the
     * deployment default.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function options(array $opts): array
    {
        $options = $this->options;
        foreach ([
            'max_files' => Config::int('code.max_files', 800),
            'max_size' => Config::int('code.max_size', 1048576),
            'max_depth' => Config::int('code.max_depth', 8),
        ] as $key => $value) {
            if (!array_key_exists($key, $options) || (int) $options[$key] <= 0) {
                $options[$key] = $value;
            }
        }
        $navigation = Config::list('code.navigation', []);
        if ($navigation !== [] && empty($options['navigation'])) {
            $options['navigation'] = $navigation;
        }

        return $opts + $options;
    }
}
