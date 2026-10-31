<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\TextScore;
use PDO;
use Throwable;

/**
 * The calculations this CRM already has, kept by name.
 *
 * A proven report is worth more than an improvised query: it already answers a
 * family of questions the way the business expects. The code scan finds those
 * reports (P2); this library gives them a name, a place and a business
 * description, and lets the assistant reuse them instead of re-deriving them.
 *
 * Two states matter. A query that was harvested from the code is *proposed*:
 * nobody has confirmed it yet, so it does not steer the assistant. A query a
 * person activated is *active*, and its rules reach the router prompt — as
 * rules, never as SQL to run.
 */
final class QueryLibraryService
{
    /** Found by the scan, not yet confirmed by anybody. */
    public const STATUS_PROPOSED = 'proposed';

    /** Confirmed: this is the way the business does it. */
    public const STATUS_ACTIVE = 'active';

    /** Kept for the record, not used any more. */
    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_PROPOSED, self::STATUS_ACTIVE, self::STATUS_RETIRED];

    private ?PDO $pdo;

    /** @var array<string,mixed> */
    private array $options;

    /** @param array<string,mixed> $options match_threshold, max_prompt */
    public function __construct(?PDO $pdo = null, array $options = [])
    {
        $this->pdo = $pdo;
        $this->options = $options;
    }

    // ─── reading ──────────────────────────────────────────────────────────────

    /**
     * The library.
     *
     * @param array<string,mixed> $filter status, module, limit
     * @return array<int,array<string,mixed>>
     */
    public function list(int $companyId, array $filter = []): array
    {
        if ($this->pdo === null) {
            return [];
        }
        $where = ['company_id' => $companyId];
        $status = strtolower(trim((string) ($filter['status'] ?? '')));
        if ($status !== '') {
            $where['status'] = $status;
        }
        $module = trim((string) ($filter['module'] ?? ''));
        if ($module !== '') {
            $where['module'] = $module;
        }
        try {
            $rows = Db::select(
                $this->pdo,
                Db::table('queries'),
                $where,
                [],
                [['status', 'asc'], ['name', 'asc']],
                max(1, (int) ($filter['limit'] ?? 200)),
            );
        } catch (Throwable) {
            return [];
        }

        return array_map(fn (array $row): array => $this->publicRow($row), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $companyId): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        try {
            $rows = Db::select($this->pdo, Db::table('queries'), ['id' => $id, 'company_id' => $companyId], [], [], 1);
        } catch (Throwable) {
            return null;
        }
        $row = $rows[0] ?? null;

        return $row === null ? null : $this->publicRow($row);
    }

    /**
     * The queries that match a question.
     *
     * Only an active query matches: a proposed one has not been confirmed, so
     * it must not shape an answer yet.
     *
     * @return array<int,array<string,mixed>>
     */
    public function match(int $companyId, string $question, int $k = 3): array
    {
        $question = trim($question);
        if ($question === '') {
            return [];
        }
        $terms = TextScore::tokenize($question);
        if ($terms === []) {
            return [];
        }
        $threshold = (float) ($this->options['match_threshold'] ?? Config::float('queries.match_threshold', 0.2));

        $scored = [];
        foreach ($this->list($companyId, ['status' => self::STATUS_ACTIVE]) as $query) {
            $score = TextScore::keywordScore($terms, [
                'name' => (string) ($query['name'] ?? ''),
                'module' => (string) ($query['module'] ?? ''),
                'purpose' => (string) ($query['purpose'] ?? ''),
                'business_description' => (string) ($query['business_description'] ?? ''),
                'description' => (string) ($query['description'] ?? ''),
            ], $question);
            if ($score < $threshold) {
                continue;
            }
            $query['score'] = $score;
            $scored[] = $query;
        }

        usort($scored, static function (array $a, array $b): int {
            $diff = (float) $b['score'] <=> (float) $a['score'];

            return $diff !== 0 ? $diff : strcmp((string) $a['name'], (string) $b['name']);
        });

        return array_slice($scored, 0, max(1, $k));
    }

    // ─── writing ──────────────────────────────────────────────────────────────

    /**
     * Store a query. With an id it updates that row; without one it updates the
     * row of the same name, so saving twice does not create a second copy.
     *
     * @param array<string,mixed> $data name, module, purpose, sql, tables,
     *                                   source_file, description,
     *                                   business_description, status
     * @return array<string,mixed> the stored query
     */
    public function save(int $companyId, array $data, ?string $updatedBy = null): array
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('The query library needs a database.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('A query needs a name — that is how people will find it again.');
        }
        $status = strtolower(trim((string) ($data['status'] ?? self::STATUS_PROPOSED)));
        if (!in_array($status, self::STATUSES, true)) {
            $status = self::STATUS_PROPOSED;
        }
        $tables = array_values(array_map('strval', (array) ($data['tables'] ?? [])));
        $row = [
            'company_id' => $companyId,
            'name' => mb_substr($name, 0, 190),
            'module' => mb_substr(trim((string) ($data['module'] ?? '')), 0, 150),
            'purpose' => mb_substr(trim((string) ($data['purpose'] ?? '')), 0, 250),
            'sql' => trim((string) ($data['sql'] ?? '')),
            'tables' => Db::json($tables),
            'source_file' => mb_substr(trim((string) ($data['source_file'] ?? '')), 0, 400),
            'description' => trim((string) ($data['description'] ?? '')),
            'business_description' => trim((string) ($data['business_description'] ?? '')),
            'status' => $status,
            'meta_json' => Db::json((array) ($data['meta'] ?? [])),
            'updated_by' => $updatedBy !== null ? mb_substr($updatedBy, 0, 100) : null,
            'updated_at' => Db::now(),
            'created_at' => Db::now(),
        ];

        $id = (int) ($data['id'] ?? 0);
        $existing = $id > 0 ? $this->find($id, $companyId) : null;
        if ($existing === null) {
            $existing = $this->findByName($companyId, $row['name']);
        }
        if ($existing !== null) {
            $id = (int) $existing['id'];
            $row['created_at'] = (string) ($existing['created_at'] ?? $row['created_at']);
            Db::updateWhere($this->pdo, Db::table('queries'), $row, ['id' => $id, 'company_id' => $companyId]);
        } else {
            $id = Db::upsert($this->pdo, Db::table('queries'), $row);
        }

        $stored = $this->find($id, $companyId);

        return $stored ?? ['id' => $id] + $row;
    }

    /** Forget a query. */
    public function delete(int $id, int $companyId): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        try {
            return Db::deleteWhere($this->pdo, Db::table('queries'), ['id' => $id, 'company_id' => $companyId]) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Take the queries the code scan found and offer them as proposals.
     *
     * A query that is already in the library is left alone: the person who
     * described it owns that row, and a later scan must not overwrite their
     * wording with a guess.
     *
     * @param array<int,array<string,mixed>> $statements from CodeIndexService::statements()
     * @return array{created:int,skipped:int,items:array<int,array<string,mixed>>}
     */
    public function harvest(int $companyId, array $statements, ?string $updatedBy = null): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'items' => []];
        foreach ($statements as $statement) {
            $sql = trim((string) ($statement['sql'] ?? ''));
            if ($sql === '') {
                continue;
            }
            $name = $this->nameFor($statement);
            if ($name === '') {
                continue;
            }
            if ($this->findByName($companyId, $name) !== null) {
                $result['skipped']++;
                continue;
            }
            try {
                $stored = $this->save($companyId, [
                    'name' => $name,
                    'module' => (string) ($statement['module'] ?? ''),
                    'purpose' => '',
                    'sql' => $sql,
                    'tables' => (array) ($statement['tables'] ?? []),
                    'source_file' => (string) ($statement['file'] ?? ($statement['path'] ?? '')),
                    'description' => trim((string) ($statement['description'] ?? '')),
                    'status' => self::STATUS_PROPOSED,
                    'meta' => [
                        'joins' => array_values((array) ($statement['joins'] ?? [])),
                        'where' => array_values((array) ($statement['where'] ?? [])),
                        'function' => (string) ($statement['function'] ?? ''),
                    ],
                ], $updatedBy);
            } catch (Throwable) {
                continue;
            }
            $result['created']++;
            $result['items'][] = $stored;
        }

        return $result;
    }

    /**
     * A short "this CRM already does it this way" section for the router prompt.
     */
    public function promptSection(int $companyId, string $question, int $k = 3): string
    {
        $matches = $this->match($companyId, $question, $k);
        if ($matches === []) {
            return '';
        }
        $lines = [];
        foreach ($matches as $query) {
            $head = '- ' . $query['name'];
            if ($query['module'] !== '') {
                $head .= ' (' . $query['module'] . ')';
            }
            $lines[] = $head;
            $about = trim((string) ($query['business_description'] !== '' ? $query['business_description'] : $query['purpose']));
            if ($about !== '') {
                $lines[] = '  It calculates: ' . mb_substr($about, 0, 240);
            }
            foreach (array_slice((array) ($query['meta']['where'] ?? []), 0, 4) as $rule) {
                $lines[] = '  Rule: ' . mb_substr(trim((string) $rule), 0, 200);
            }
        }

        return "Named reports this CRM already runs (follow their rules; never run their SQL as it is):\n" . implode("\n", $lines);
    }

    // ─── internals ────────────────────────────────────────────────────────────

    /**
     * The name a harvested statement is offered under.
     *
     * @param array<string,mixed> $statement
     */
    private function nameFor(array $statement): string
    {
        $module = trim((string) ($statement['module'] ?? ''));
        $function = trim((string) ($statement['function'] ?? ''));
        $file = trim((string) ($statement['file'] ?? ($statement['path'] ?? '')));
        if ($module !== '' && $function !== '') {
            return $module . ': ' . $function;
        }
        if ($module !== '') {
            return $module;
        }
        if ($function !== '') {
            return $function;
        }
        if ($file === '') {
            return '';
        }

        return basename($file, '.php');
    }

    /** @return array<string,mixed>|null */
    private function findByName(int $companyId, string $name): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        try {
            $rows = Db::select($this->pdo, Db::table('queries'), ['company_id' => $companyId, 'name' => $name], [], [], 1);
        } catch (Throwable) {
            return null;
        }
        $row = $rows[0] ?? null;

        return $row === null ? null : $this->publicRow($row);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publicRow(array $row): array
    {
        $tables = Db::decode($row['tables'] ?? null);
        $meta = Db::decode($row['meta_json'] ?? null);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'company_id' => (int) ($row['company_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'module' => (string) ($row['module'] ?? ''),
            'purpose' => (string) ($row['purpose'] ?? ''),
            'sql' => (string) ($row['sql'] ?? ''),
            'tables' => is_array($tables) ? array_values(array_map('strval', $tables)) : [],
            'source_file' => (string) ($row['source_file'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'business_description' => (string) ($row['business_description'] ?? ''),
            'status' => (string) ($row['status'] ?? self::STATUS_PROPOSED),
            'meta' => is_array($meta) ? $meta : [],
            'updated_by' => (string) ($row['updated_by'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
