<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\DomainPack;
use ExcelleInsights\AiAssistant\Support\SchemaHints;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;
use PDO;
use Throwable;

/**
 * What a key column stands for.
 *
 * A CRM stores `staff_id = 12` where a person reads "Peter Otieno". Without a
 * curated pack the assistant can only show the 12. This service finds the other
 * side of that key and remembers it, so the semantic layer can offer
 * `staff_name` next to `staff_id`.
 *
 * Three kinds of evidence, in this order:
 *
 *   1. a declared foreign key — the database says so;
 *   2. the naming convention — `staff_id` points at `staff`, `staffs`,
 *      `staff_member` or `users`;
 *   3. value overlap — sample the key values and see how many exist in the
 *      candidate's own key column. Overlap is the evidence that matters;
 *      convention alone is only a guess.
 *
 * A candidate becomes a relation when the overlap is strong (default 90 % of
 * the sampled values found on the other side). A weaker candidate is stored as
 * *proposed* and is never used until a human approves it.
 *
 * Everything here reads through the host's table and column policy, and every
 * sample is a bounded, read-only SELECT.
 */
final class RelationService
{
    /** Columns that usually hold the readable value of a row, best first. */
    private const LABEL_COLUMNS = [
        'name', 'title', 'full_name', 'display_name', 'fname', 'lname', 'first_name',
        'last_name', 'label', 'username', 'uname', 'user_name', 'code', 'reference',
        'description', 'company_name', 'client_name', 'staff_name', 'officer_name',
    ];

    /** Key columns a lookup target may use. */
    private const TARGET_KEYS = ['id', 'uuid', 'user_id', 'staff_id', 'client_id', 'code', 'reference'];

    private ?PDO $pdo;
    private SchemaService $schema;
    private SchemaPolicy $policy;
    /** @var array<string,mixed> */
    private array $options;

    /** @param array<string,mixed> $options sample_limit, auto_verify_threshold, max_candidates */
    public function __construct(?PDO $pdo, SchemaService $schema, ?SchemaPolicy $policy = null, array $options = [])
    {
        $this->pdo = $pdo;
        $this->schema = $schema;
        $this->policy = $policy ?? new SchemaPolicy();
        $this->options = $options;
    }

    // ─── reads ────────────────────────────────────────────────────────────────

    /**
     * Relations of a company (and the deployment-wide ones), newest first.
     *
     * @param array<string,mixed> $filter status, source_table, limit
     * @return array<int,array<string,mixed>>
     */
    public function list(int $companyId, array $filter = []): array
    {
        if ($this->pdo === null) {
            return [];
        }
        $where = ['company_id' => $companyId];
        if (($filter['company_id'] ?? null) !== null) {
            $where = ['company_id' => (int) $filter['company_id']];
        }
        if (trim((string) ($filter['status'] ?? '')) !== '') {
            $where['status'] = strtolower(trim((string) $filter['status']));
        }
        if (trim((string) ($filter['source_table'] ?? '')) !== '') {
            $where['source_table'] = trim((string) $filter['source_table']);
        }
        $limit = max(1, min(500, (int) ($filter['limit'] ?? 200)));

        try {
            $rows = Db::select($this->pdo, Db::table('relations'), $where, [], [], $limit, '', $this->columns());
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->present($row);
        }

        return $out;
    }

    /**
     * The relations the semantic layer may use: verified ones only, and only
     * where the host's table policy allows the target table.
     *
     * The deployment-wide relations (company 0) apply to everyone; a company's
     * own relation replaces one that says the same thing.
     *
     * @return array<int,array<string,mixed>>
     */
    public function approved(int $companyId): array
    {
        $rows = $this->list(0, ['status' => 'verified', 'limit' => 500]);
        if ($companyId > 0) {
            $rows = array_merge($rows, $this->list($companyId, ['status' => 'verified', 'limit' => 500]));
        }
        $byKey = [];
        foreach ($rows as $row) {
            $key = strtolower((string) ($row['source_table'] ?? '') . '.' . (string) ($row['source_column'] ?? ''));
            $byKey[$key] = $row;
        }

        $out = [];
        foreach ($byKey as $row) {
            $target = (string) ($row['target_table'] ?? '');
            if ($target === '' || !$this->policy->isTableAllowed($target)) {
                continue;
            }
            $label = (string) ($row['label_column'] ?? '');
            if ($label !== '' && !$this->policy->isColumnAllowed($target, $label)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    // ─── writes ───────────────────────────────────────────────────────────────

    /**
     * Look for relations and record what was found.
     *
     * @param array<string,mixed> $opts tables, limit, sample, auto_verify
     * @return array{scanned:int,proposed:int,verified:int,skipped:int,relations:array<int,array<string,mixed>>}
     */
    public function discover(int $companyId, array $opts = []): array
    {
        $stats = ['scanned' => 0, 'proposed' => 0, 'verified' => 0, 'skipped' => 0, 'relations' => []];
        if ($this->pdo === null) {
            return $stats;
        }
        $catalog = $this->schema->catalog(true);
        if ($catalog === []) {
            return $stats;
        }
        $wanted = array_values(array_filter(array_map(
            static fn ($t): string => strtolower(trim((string) $t)),
            (array) ($opts['tables'] ?? [])
        )));
        $sample = max(2, min(50, (int) ($opts['sample'] ?? $this->sampleLimit())));
        $autoVerify = (bool) ($opts['auto_verify'] ?? $this->autoVerify());
        $threshold = $this->threshold();

        foreach ($catalog as $table => $entry) {
            $tableName = (string) $table;
            if ($wanted !== [] && !in_array(strtolower($tableName), $wanted, true)) {
                continue;
            }
            foreach ((array) ($entry['columns'] ?? []) as $column) {
                $name = (string) ($column['name'] ?? '');
                if (!$this->isLookupColumn($name, (string) ($column['label'] ?? ''))) {
                    continue;
                }
                $stats['scanned']++;
                $candidates = $this->candidates($tableName, $name, $catalog);
                $best = null;
                foreach ($candidates as $candidate) {
                    $scored = $this->score($tableName, $name, $candidate, $catalog, $sample);
                    if ($scored === null) {
                        continue;
                    }
                    if ($best === null || $scored['confidence'] > $best['confidence']) {
                        $best = $scored;
                    }
                }
                if ($best === null) {
                    $stats['skipped']++;
                    continue;
                }
                $existing = $this->existing($companyId, $tableName, $name);
                if (($existing['status'] ?? '') === 'rejected') {
                    // A human said no. It never comes back on its own.
                    $stats['skipped']++;
                    continue;
                }
                $verified = $autoVerify && $best['confidence'] >= $threshold;
                $status = $verified ? 'verified' : 'proposed';
                $stats[$verified ? 'verified' : 'proposed']++;
                $saved = $this->save($companyId, $best + ['status' => $status]);
                if ($saved !== null) {
                    $stats['relations'][] = $saved;
                }
            }
        }

        return $stats;
    }

    /** Accept a proposed relation; it joins the semantic layer from now on. */
    public function approve(int $companyId, int $id): ?array
    {
        return $this->setStatus($companyId, $id, 'verified');
    }

    /** Refuse a relation. It never comes back, not even on the next scan. */
    public function reject(int $companyId, int $id): ?array
    {
        return $this->setStatus($companyId, $id, 'rejected');
    }

    /** Drop a relation entirely, so a later scan may propose it again. */
    public function forget(int $companyId, int $id): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        try {
            return Db::updateWhere($this->pdo, Db::table('relations'), ['status' => 'rejected'], [
                'id' => $id,
                'company_id' => $companyId,
            ]) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** Write a relation a human declares. @param array<string,mixed> $relation */
    public function save(int $companyId, array $relation): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        $sourceTable = trim((string) ($relation['source_table'] ?? ''));
        $sourceColumn = trim((string) ($relation['source_column'] ?? ''));
        $targetTable = trim((string) ($relation['target_table'] ?? ''));
        if ($sourceTable === '' || $sourceColumn === '' || $targetTable === '') {
            return null;
        }
        $existing = $this->existing($companyId, $sourceTable, $sourceColumn);
        if (($existing['status'] ?? '') === 'rejected') {
            // A relation a human refused stays refused.
            return $existing;
        }
        $row = [
            'company_id' => $companyId,
            'source_table' => $sourceTable,
            'source_column' => $sourceColumn,
            'target_table' => $targetTable,
            'target_column' => trim((string) ($relation['target_column'] ?? 'id')) ?: 'id',
            'label_column' => trim((string) ($relation['label_column'] ?? '')),
            'method' => trim((string) ($relation['method'] ?? 'manual')) ?: 'manual',
            'confidence' => round((float) ($relation['confidence'] ?? 1), 4),
            'status' => trim((string) ($relation['status'] ?? 'proposed')) ?: 'proposed',
            'meta_json' => Db::json((array) ($relation['meta'] ?? [])),
            'updated_by' => trim((string) ($relation['updated_by'] ?? '')) ?: null,
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ];
        $columns = $this->columns();
        $row = array_intersect_key($row, array_flip($columns));

        try {
            Db::upsert(
                $this->pdo,
                Db::table('relations'),
                $row,
                [
                    'target_table', 'target_column', 'label_column', 'method',
                    'confidence', 'status', 'meta_json', 'updated_by', 'updated_at',
                ]
            );
        } catch (Throwable) {
            return null;
        }

        return $this->present($row);
    }

    // ─── discovery internals ──────────────────────────────────────────────────

    /**
     * Candidate target tables for one key column, most plausible first.
     *
     * @param array<string,mixed> $catalog
     * @return array<int,array{table:string,column:string,method:string}>
     */
    private function candidates(string $table, string $column, array $catalog): array
    {
        $out = [];
        $seen = [];

        // 1. The database's own foreign keys.
        foreach ($this->foreignKeys($table) as $fk) {
            if (strcasecmp((string) $fk['column'], $column) !== 0) {
                continue;
            }
            $target = (string) $fk['table'];
            $key = strtolower($target . '.' . (string) $fk['referenced_column']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'table' => $target,
                'column' => (string) ($fk['referenced_column'] ?? 'id'),
                'method' => 'foreign_key',
            ];
        }

        // 2. The naming convention.
        $base = self::baseName($column);
        foreach ($this->conventionTargets($base, $catalog) as $target) {
            $key = strtolower($target . '.id');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['table' => $target, 'column' => 'id', 'method' => 'convention'];
        }

        return $out;
    }

    /**
     * Tables whose name looks like the thing the key points at: "staff" for
     * `staff_id`, "users" for `user_id`, "staff_member" for `staff_id`.
     *
     * @param array<string,mixed> $catalog
     * @return string[]
     */
    private function conventionTargets(string $base, array $catalog): array
    {
        if ($base === '') {
            return [];
        }
        $wanted = [$base, $base . 's', $base . 'es', $base . '_member', $base . 's_member', $base . '_table'];
        if ($base === 'user') {
            $wanted[] = 'users';
            $wanted[] = 'user_accounts';
            $wanted[] = 'staff';
            $wanted[] = 'staff_member';
        }
        if ($base === 'staff') {
            $wanted[] = 'staff_member';
            $wanted[] = 'staffs';
            $wanted[] = 'employees';
            $wanted[] = 'users';
        }
        if ($base === 'client' || $base === 'customer') {
            $wanted[] = 'clients';
            $wanted[] = 'customers';
            $wanted[] = 'client';
            $wanted[] = 'customer';
        }
        if ($base === 'officer') {
            $wanted[] = 'officers';
            $wanted[] = 'staff_member';
            $wanted[] = 'users';
        }

        $out = [];
        foreach (array_keys($catalog) as $table) {
            $name = (string) $table;
            $key = strtolower($name);
            if (in_array($key, array_map('strtolower', $wanted), true)) {
                $out[] = $name;
                continue;
            }
            // "staff_member" answers "staff_id".
            if ($key !== strtolower($base) && str_ends_with($key, '_' . strtolower($base))) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Score one candidate: how many of the source's key values exist on the
     * other side, and which column there holds the readable value.
     *
     * @param array<string,mixed> $catalog
     * @return array<string,mixed>|null
     */
    private function score(string $table, string $column, array $candidate, array $catalog, int $sample): ?array
    {
        $target = (string) $candidate['table'];
        $targetColumn = (string) ($candidate['column'] ?? 'id');
        if (strcasecmp($target, $table) === 0) {
            return null;
        }
        if (!$this->policy->isTableAllowed($target)) {
            return null;
        }
        $targetColumns = $this->columnNames($catalog, $target);
        if ($targetColumns === [] || !$this->hasColumn($targetColumns, $targetColumn)) {
            return null;
        }

        $values = $this->sampleValues($table, $column, $sample);
        if ($values === []) {
            return null;
        }
        $found = $this->countMatches($values, $target, $targetColumn);
        if ($found === 0) {
            return null;
        }
        $confidence = round($found / count($values), 4);

        $label = $this->labelColumn($target, $targetColumns);
        if ($label === '') {
            return null;
        }

        return [
            'source_table' => $table,
            'source_column' => $column,
            'target_table' => $target,
            'target_column' => $targetColumn,
            'label_column' => $label,
            'method' => (string) $candidate['method'],
            'confidence' => $confidence,
            'meta' => [
                'sampled' => count($values),
                'matched' => $found,
            ],
        ];
    }

    /**
     * The column on the target table that carries the readable value: a name, a
     * title, a username. Masked and secret columns never qualify, and a column
     * whose values are encrypted at rest is skipped in favour of the next one.
     *
     * @param string[] $columns
     */
    private function labelColumn(string $table, array $columns): string
    {
        $ordered = [];
        foreach (self::LABEL_COLUMNS as $preferred) {
            foreach ($columns as $column) {
                if (strcasecmp($column, $preferred) === 0) {
                    $ordered[] = $column;
                }
            }
        }
        foreach ($columns as $column) {
            if (!in_array($column, $ordered, true) && $this->isLabelish($column)) {
                $ordered[] = $column;
            }
        }

        foreach ($ordered as $column) {
            if (!$this->policy->isColumnAllowed($table, $column)) {
                continue;
            }
            if ($this->policy->isColumnMasked($column)) {
                continue;
            }
            if ($this->looksEncrypted($table, $column)) {
                continue;
            }
            return $column;
        }

        return '';
    }

    /** A column that plausibly holds a readable value. */
    private function isLabelish(string $column): bool
    {
        $c = strtolower(trim($column));
        if ($c === '' || SchemaHints::isReferenceColumn($c)) {
            return false;
        }
        if (str_ends_with($c, '_at') || str_ends_with($c, '_date') || str_ends_with($c, '_id')) {
            return false;
        }
        return (bool) preg_match('/(name|title|label|code|subject|description|text|notes?)$/', $c);
    }

    /** True when the column holds ciphertext rather than a name. */
    private function looksEncrypted(string $table, string $column): bool
    {
        $values = $this->sampleValues($table, $column, 5);
        return $values !== [] && SchemaHints::looksEncrypted($values);
    }

    /**
     * Distinct key values of one column, bounded and read-only.
     *
     * @return string[]
     */
    private function sampleValues(string $table, string $column, int $limit): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $sql = 'SELECT DISTINCT `' . Db::identifier($column) . '` AS v FROM `' . Db::identifier($table) . '`'
                . ' WHERE `' . Db::identifier($column) . '` IS NOT NULL LIMIT ' . max(1, $limit);
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row['v'] ?? ''));
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * How many of the sampled values exist in the target's key column.
     *
     * @param string[] $values
     */
    private function countMatches(array $values, string $target, string $targetColumn): int
    {
        if ($this->pdo === null) {
            return 0;
        }
        try {
            $placeholders = [];
            $bind = [];
            foreach (array_values($values) as $index => $value) {
                $param = ':v' . $index;
                $placeholders[] = $param;
                $bind[$param] = $value;
            }
            if ($placeholders === []) {
                return 0;
            }
            $sql = 'SELECT COUNT(DISTINCT `' . Db::identifier($targetColumn) . '`) FROM `' . Db::identifier($target) . '`'
                . ' WHERE `' . Db::identifier($targetColumn) . '` IN (' . implode(', ', $placeholders) . ')';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bind);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<int,array<string,string>> */
    private function foreignKeys(string $table): array
    {
        try {
            return $this->schema->foreignKeys($table);
        } catch (Throwable) {
            return [];
        }
    }

    /** A key column worth looking up: not the row's own primary key. */
    private function isLookupColumn(string $column, string $label = ''): bool
    {
        $c = strtolower(trim($column));
        if ($c === '') {
            return false;
        }
        if (!SchemaHints::isReferenceColumn($c)) {
            // A column the catalogue calls an id ("Officer (user id)") is a key too.
            if (trim($label) === '' || preg_match('/\bids?\b/i', $label) !== 1) {
                return false;
            }
        }

        return $c !== 'id' && $c !== 'uuid';
    }

    /** "staff_id" → "staff", "client_uuid" → "client". */
    public static function baseName(string $column): string
    {
        return SchemaHints::referenceBase($column);
    }

    /**
     * @param array<string,mixed> $catalog
     * @return string[]
     */
    private function columnNames(array $catalog, string $table): array
    {
        $entry = $catalog[$table] ?? null;
        if (!is_array($entry)) {
            foreach ($catalog as $name => $candidate) {
                if (strcasecmp((string) $name, $table) === 0) {
                    $entry = $candidate;
                    break;
                }
            }
        }
        if (!is_array($entry)) {
            return [];
        }
        $out = [];
        foreach ((array) ($entry['columns'] ?? []) as $column) {
            $name = trim((string) ($column['name'] ?? ''));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** @param string[] $columns */
    private function hasColumn(array $columns, string $column): bool
    {
        foreach ($columns as $name) {
            if (strcasecmp($name, $column) === 0) {
                return true;
            }
        }
        return false;
    }

    private function setStatus(int $companyId, int $id, string $status): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        try {
            $changed = Db::updateWhere($this->pdo, Db::table('relations'), ['status' => $status], [
                'id' => $id,
                'company_id' => $companyId,
            ]);
        } catch (Throwable) {
            return null;
        }
        if ($changed === 0) {
            return null;
        }
        $rows = Db::select($this->pdo, Db::table('relations'), ['id' => $id, 'company_id' => $companyId], [], [], 1, '', $this->columns());

        return $rows === [] ? null : $this->present($rows[0]);
    }

    /** The relation already stored for one key column, if any. */
    private function existing(int $companyId, string $table, string $column): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        try {
            $rows = Db::select(
                $this->pdo,
                Db::table('relations'),
                [
                    'company_id' => $companyId,
                    'source_table' => $table,
                    'source_column' => $column,
                ],
                [],
                [],
                1,
                '',
                $this->columns()
            );
        } catch (Throwable) {
            return null;
        }

        return $rows === [] ? null : $this->present($rows[0]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function present(array $row): array
    {
        $meta = Db::decode($row['meta_json'] ?? null);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'company_id' => (int) ($row['company_id'] ?? 0),
            'source_table' => (string) ($row['source_table'] ?? ''),
            'source_column' => (string) ($row['source_column'] ?? ''),
            'target_table' => (string) ($row['target_table'] ?? ''),
            'target_column' => (string) ($row['target_column'] ?? ''),
            'label_column' => (string) ($row['label_column'] ?? ''),
            'method' => (string) ($row['method'] ?? ''),
            'confidence' => (float) ($row['confidence'] ?? 0),
            'status' => (string) ($row['status'] ?? 'proposed'),
            'meta' => $meta,
            'updated_by' => (string) ($row['updated_by'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** @return string[] */
    private function columns(): array
    {
        return [
            'id', 'company_id', 'source_table', 'source_column', 'target_table', 'target_column',
            'label_column', 'method', 'confidence', 'status', 'meta_json', 'updated_by', 'created_at', 'updated_at',
        ];
    }

    private function sampleLimit(): int
    {
        return (int) ($this->options['sample_limit'] ?? Config::int('relations.sample_limit', 20));
    }

    private function threshold(): float
    {
        $value = (float) ($this->options['auto_verify_threshold'] ?? Config::float('relations.auto_verify_threshold', 0.9));

        return max(0.5, min(1.0, $value));
    }

    private function autoVerify(): bool
    {
        return (bool) ($this->options['auto_verify'] ?? Config::bool('relations.auto_verify', true));
    }

    /**
     * The relation as the semantic layer wants it: source table and column,
     * the table the key points at, the column to show, and the join condition.
     *
     * @param array<string,mixed> $relation
     * @return array<string,mixed>
     */
    public static function toRelation(array $relation): array
    {
        $sourceTable = (string) ($relation['source_table'] ?? '');
        $sourceColumn = (string) ($relation['source_column'] ?? '');
        $targetTable = (string) ($relation['target_table'] ?? '');
        $targetColumn = (string) ($relation['target_column'] ?? 'id');
        $labelColumn = (string) ($relation['label_column'] ?? '');

        return [
            'source_table' => $sourceTable,
            'source_column' => $sourceColumn,
            'target_table' => $targetTable,
            'target_column' => $targetColumn,
            'label_column' => $labelColumn,
            'method' => (string) ($relation['method'] ?? ''),
            'confidence' => (float) ($relation['confidence'] ?? 0),
            'on' => [$sourceTable . '.' . $sourceColumn => $targetTable . '.' . $targetColumn],
            'key' => DomainPack::key($sourceTable . '.' . $sourceColumn),
        ];
    }
}
