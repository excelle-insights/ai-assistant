<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use PDO;
use ExcelleInsights\AiAssistant\Contracts\TablePolicyInterface;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\CsvReader;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\EnvLoader;
use ExcelleInsights\AiAssistant\Support\SchemaHints;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;
use ExcelleInsights\AiAssistant\Support\SqlDumpParser;
use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * Learns the host's database so the assistant can answer questions about it.
 *
 * Nothing here knows what the application *is*. The service reads structure from
 * information_schema (or from a SQL dump / a second connection the host hands
 * it), stores each column's meaning in {prefix}_schema, and builds the compact
 * "schema card" the model uses to write SQL. Which tables and columns may be
 * seen at all is decided by Support\SchemaPolicy — configuration plus an optional
 * host TablePolicyInterface — never by a list inside this file.
 *
 * The catalog keeps a description_source per column so a description a human
 * wrote (user/import) or the database supplied (comment/dump) is never
 * overwritten by a re-sync's guess.
 */
class SchemaService
{
    /** Sources that a re-sync must not overwrite. */
    private const PROTECTED_SOURCES = ['user', 'import'];

    /** Fallback lookup-table suffixes when the host configures none. */
    private const DEFAULT_LOOKUP_SUFFIXES = [
        '_types', '_categories', '_statuses', '_units', '_levels', '_roles', '_currencies',
        '_priorities', '_conditions', '_frequencies', '_methods', '_departments', '_positions',
        '_industries', '_sources', '_groups', '_classes', '_grades', '_stages', '_channels',
    ];

    private ?PDO $pdo;
    private string $prefix;
    private SchemaPolicy $policy;
    private ?PDO $source;
    private ?string $sourceSchema;
    private array $context;
    /** @var array<string,string[]> */
    private array $catalogColumnCache = [];
    private ?string $schemaTable = null;

    /**
     * @param PDO|null              $pdo     host database; null = catalog/dump only
     * @param SchemaPolicy|array|null $policy policy, or options array (BC-friendly)
     * @param array<string,mixed>   $options source_pdo, host_policy, context, schema
     */
    public function __construct(?PDO $pdo = null, SchemaPolicy|array|null $policy = null, array $options = [])
    {
        EnvLoader::load();
        if (is_array($policy)) {
            $options = array_merge($policy, $options);
            $policy = null;
        }
        $this->prefix = TablePrefix::get();
        $this->pdo = $pdo;
        $this->source = isset($options['source_pdo']) && $options['source_pdo'] instanceof PDO ? $options['source_pdo'] : null;
        $this->sourceSchema = isset($options['schema']) ? (string) $options['schema'] : null;
        $this->context = array_merge(
            ['tenant_id' => Config::get('tenant.id'), 'company_id' => Config::get('tenant.id')],
            (array) ($options['context'] ?? [])
        );
        $hostPolicy = $options['host_policy'] ?? null;
        $this->policy = $policy instanceof SchemaPolicy
            ? $policy
            : new SchemaPolicy(
                (array) Config::get('schema_policy', []),
                $hostPolicy instanceof TablePolicyInterface ? $hostPolicy : null,
                $this->context
            );
    }

    public function policy(): SchemaPolicy
    {
        return $this->policy;
    }

    /** Same service, but with per-request host context (user, role, tenant) applied. */
    public function forContext(array $context): self
    {
        $clone = clone $this;
        $clone->context = array_merge($this->context, $context);
        $clone->policy = $this->policy->withContext($clone->context);
        return $clone;
    }

    // ─── Live structure ───────────────────────────────────────────────────────

    public function database(): string
    {
        $schema = $this->activeSchema();
        if ($schema !== null) {
            return $schema;
        }
        $pdo = $this->connection();
        return (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }

    /** Every base table of the connected database, with its comment. */
    public function tables(): array
    {
        $pdo = $this->connection();
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME AS name, TABLE_COMMENT AS comment, CREATE_TIME AS created'
            . ' FROM information_schema.tables'
            . ' WHERE table_schema = :db AND table_type = \'BASE TABLE\' ORDER BY TABLE_NAME'
        );
        $stmt->execute(['db' => $this->database()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Columns of one table: name, type, comment (+ nullable/key/default). */
    public function columns(string $table): array
    {
        $pdo = $this->connection();
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, COLUMN_COMMENT AS comment,'
            . ' IS_NULLABLE AS nullable, COLUMN_KEY AS k, COLUMN_DEFAULT AS default_value, EXTRA AS extra,'
            . ' DATA_TYPE AS data_type, ORDINAL_POSITION AS pos'
            . ' FROM information_schema.columns WHERE table_schema = :db AND table_name = :t ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(['db' => $this->database(), 't' => $table]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Tables the policy lets this assistant see, live database minus own/config/skipped. */
    public function queryableTables(): array
    {
        $out = [];
        foreach ($this->tables() as $t) {
            $name = (string) $t['name'];
            if (!$this->policy->isTableAllowed($name, (string) ($t['comment'] ?? ''))) {
                continue;
            }
            $out[] = $name;
        }
        return $out;
    }

    /** @return string[] all table names in the catalog (structure known) */
    public function catalogTables(): array
    {
        $rows = $this->catalogRows();
        $names = [];
        foreach ($rows as $r) {
            $names[] = (string) $r['table_name'];
        }
        return array_values(array_unique($names));
    }

    public function hasColumn(string $table, string $column): bool
    {
        foreach ($this->columns($table) as $c) {
            if (strcasecmp((string) $c['name'], $column) === 0) {
                return true;
            }
        }
        return false;
    }

    public function tenantColumn(): string
    {
        return $this->policy->tenantColumn();
    }

    /** Tenant column only when the table actually has one. */
    public function tenantColumnOf(string $table): ?string
    {
        $column = $this->policy->tenantColumn();
        try {
            return $this->hasColumn($table, $column) ? $column : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Foreign keys of a table (live database). @return array<int,array<string,?string>> */
    public function foreignKeys(string $table): array
    {
        if ($this->connection() === null) {
            return [];
        }
        $stmt = $this->connection()->prepare(
            'SELECT COLUMN_NAME AS column_name, REFERENCED_TABLE_NAME AS referenced_table,'
            . ' REFERENCED_COLUMN_NAME AS referenced_column'
            . ' FROM information_schema.key_column_usage'
            . ' WHERE table_schema = :db AND table_name = :t AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $stmt->execute(['db' => $this->database(), 't' => $table]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'column' => (string) $r['column_name'],
                'table' => (string) $r['referenced_table'],
                'referenced_column' => (string) ($r['referenced_column'] ?? 'id'),
            ];
        }
        return $out;
    }

    // ─── Catalog sync ─────────────────────────────────────────────────────────

    /**
     * Mirror the live (or attached-source) structure into {prefix}_schema,
     * preserving descriptions a human or a dump already supplied.
     *
     * @param array{tables?:string[],prune?:bool,sample_rows?:int} $opts
     * @return array{added:int,updated:int,unchanged:int,total:int,pruned:int,tables:int}
     */
    public function syncSchema(array $opts = []): array
    {
        $table = $this->ensureSchemaTable();
        $wanted = array_map(static fn ($t): string => strtolower(trim((string) $t)), (array) ($opts['tables'] ?? []));
        $prune = (bool) ($opts['prune'] ?? Config::bool('schema.prune_stale', false));
        $sampleRows = max(0, (int) ($opts['sample_rows'] ?? Config::int('schema.sample_rows', 25)));

        $existing = $this->catalogIndex();
        $stats = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'total' => 0, 'pruned' => 0, 'tables' => 0];
        $seen = [];

        foreach ($this->tables() as $t) {
            $name = (string) $t['name'];
            if ($wanted !== [] && !in_array(strtolower($name), $wanted, true)) {
                continue;
            }
            if (!$this->policy->isTableAllowed($name, (string) ($t['comment'] ?? ''))) {
                continue;
            }
            $stats['tables']++;
            $comment = trim((string) ($t['comment'] ?? ''));
            $seen[$name . '.*'] = true;
            $this->writeCatalogRow(
                $table,
                $existing,
                $stats,
                $name,
                '',
                '',
                $comment !== '' ? $comment : SchemaHints::label($name),
                ['table_label' => SchemaHints::label($name)],
                $comment !== '' ? 'comment' : 'auto'
            );

            $foreign = [];
            foreach ($this->foreignKeys($name) as $fk) {
                $foreign[$fk['column']] = $fk['table'];
            }

            $liveColumns = $this->columns($name);
            $samples = $sampleRows > 0 ? $this->sampleValues($name, $liveColumns, $sampleRows) : [];
            foreach ($liveColumns as $c) {
                $column = (string) $c['name'];
                if (!$this->policy->isColumnAllowed($name, $column)) {
                    continue;
                }
                $stats['total']++;
                $seen[$name . '.' . $column] = true;
                $dbComment = trim((string) ($c['comment'] ?? ''));
                $auto = SchemaHints::autoDescription($name, $column, $foreign[$column] ?? null);
                $values = $samples[$column] ?? [];
                $hint = $values === [] ? '' : SchemaHints::valueHint($column, $values);
                $dataType = (string) ($c['data_type'] ?? '');
                $format = SchemaHints::format($column, $dataType);
                if ($hint !== '' && SchemaHints::dateFormatHint($values) !== '' && $format === 'text') {
                    $format = str_contains(SchemaHints::dateFormatHint($values), 'HH') ? 'datetime' : 'date';
                }
                // Value hints are observations of the data, not guesses, so they
                // survive auto_describe = false — a new table with no comments
                // still tells the model its dates and enum values.
                $guess = Config::bool('schema.auto_describe', true) ? $auto : '';
                $this->writeCatalogRow(
                    $table,
                    $existing,
                    $stats,
                    $name,
                    $column,
                    (string) ($c['type'] ?? ''),
                    $dbComment !== '' ? $dbComment : trim($guess . $hint),
                    [
                        'label' => SchemaHints::label($column),
                        'table_label' => SchemaHints::label($name),
                        'sensitivity' => SchemaHints::looksEncrypted($values) ? 'secret' : SchemaHints::sensitivity($column, $dataType, $this->policy),
                        'is_measure' => SchemaHints::isMeasure($column, $dataType) ? 1 : 0,
                        'format' => $format,
                    ],
                    $dbComment !== '' ? 'comment' : 'auto'
                );
            }
        }

        if ($prune) {
            $stats['pruned'] = $this->pruneStale($table, $seen);
        }

        return $stats;
    }

    /**
     * Train the catalog from a SQL dump the host uploaded — no connection to that
     * database needed. Column/table COMMENTs become descriptions.
     *
     * @param array{tables?:string[],strip_prefix?:string,company_id?:int,prune?:bool} $opts
     * @return array{added:int,updated:int,unchanged:int,total:int,tables:int,skipped:int,unknown?:string}
     */
    public function importFromSqlDump(string $sql, array $opts = []): array
    {
        $parsed = SqlDumpParser::parse($sql);
        if ($parsed['tables'] === []) {
            return ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'total' => 0, 'tables' => 0,
                'skipped' => $parsed['skipped'], 'unknown' => 'No CREATE TABLE statement found in the file'];
        }

        $strip = strtolower(trim((string) ($opts['strip_prefix'] ?? '')));
        $wanted = array_map('strtolower', array_map('strval', (array) ($opts['tables'] ?? [])));
        $table = $this->ensureSchemaTable();
        $existing = $this->catalogIndex();
        $stats = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'total' => 0, 'tables' => 0, 'skipped' => 0];

        foreach ($parsed['tables'] as $def) {
            $name = (string) $def['name'];
            if ($strip !== '' && str_starts_with(strtolower($name), $strip)) {
                $name = substr($name, strlen($strip));
            }
            if ($wanted !== [] && !in_array(strtolower($name), $wanted, true)) {
                continue;
            }
            if ($this->policy->isOwnTable($name)) {
                continue;
            }
            $stats['tables']++;
            $foreign = [];
            foreach ($def['foreign_keys'] as $fk) {
                $foreign[$fk['column']] = $fk['table'];
            }
            $comment = (string) ($def['comment'] ?? '');
            $this->writeCatalogRow(
                $table, $existing, $stats, $name, '', '',
                $comment !== '' ? $comment : SchemaHints::label($name),
                ['table_label' => SchemaHints::label($name), 'description_source' => 'dump'],
                'dump'
            );
            foreach ($def['columns'] as $col) {
                $column = (string) $col['name'];
                if (!$this->policy->isColumnAllowed($name, $column)) {
                    $stats['skipped']++;
                    continue;
                }
                $stats['total']++;
                $colComment = trim((string) ($col['comment'] ?? ''));
                $auto = SchemaHints::autoDescription($name, $column, $foreign[$column] ?? ($col['references']['table'] ?? null));
                $dataType = (string) $col['type'];
                $this->writeCatalogRow(
                    $table, $existing, $stats, $name, $column, $dataType,
                    $colComment !== '' ? $colComment : (Config::bool('schema.auto_describe', true) ? $auto : ''),
                    [
                        'label' => SchemaHints::label($column),
                        'table_label' => SchemaHints::label($name),
                        'sensitivity' => SchemaHints::sensitivity($column, $dataType, $this->policy),
                        'is_measure' => SchemaHints::isMeasure($column, $dataType) ? 1 : 0,
                        'format' => SchemaHints::format($column, $dataType),
                    ],
                    $colComment !== '' ? 'dump' : 'auto'
                );
            }
        }

        if (($opts['prune'] ?? false) === true) {
            $seen = [];
            foreach ($parsed['tables'] as $def) {
                $seen[(string) $def['name'] . '.*'] = true;
                foreach ($def['columns'] as $col) {
                    $seen[(string) $def['name'] . '.' . (string) $col['name']] = true;
                }
            }
            $stats['pruned'] = $this->pruneStale($table, $seen);
        }

        return $stats;
    }

    /**
     * Train from a second live connection (host attached an uploaded database).
     * Structure only — no row of the other database is ever copied.
     */
    public function ingestFromConnection(PDO $source, array $opts = []): array
    {
        $previous = $this->source;
        $this->source = $source;
        try {
            return $this->syncSchema($opts);
        } finally {
            $this->source = $previous;
        }
    }

    // ─── Catalog reads ────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> every raw catalog row, policy untouched */
    public function catalogRows(?string $table = null): array
    {
        $name = $this->schemaTable();
        try {
            if ($table === null) {
                $stmt = $this->connection()->prepare(
                    'SELECT table_name, column_name, data_type, description, label, table_label,'
                    . ' sensitivity, is_measure, format, aliases, description_source'
                    . ' FROM `' . $name . '` ORDER BY table_name, column_name'
                );
                $stmt->execute();
            } else {
                $stmt = $this->connection()->prepare(
                    'SELECT table_name, column_name, data_type, description, label, table_label,'
                    . ' sensitivity, is_measure, format, aliases, description_source'
                    . ' FROM `' . $name . '` WHERE table_name = :t ORDER BY column_name'
                );
                $stmt->execute(['t' => $table]);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The catalog as a structure the rest of the package can reason about:
     * table → label/description/columns.
     *
     * @return array<string,array{label:string,description:?string,columns:array<int,array<string,mixed>>}>
     */
    public function catalog(bool $policyFiltered = true): array
    {
        $out = [];
        foreach ($this->catalogRows() as $r) {
            $tableName = (string) $r['table_name'];
            $column = (string) $r['column_name'];
            if (!isset($out[$tableName])) {
                $out[$tableName] = ['label' => '', 'description' => null, 'columns' => []];
            }
            if ($column === '') {
                $out[$tableName]['label'] = (string) ($r['table_label'] ?? $r['label'] ?? '') ?: SchemaHints::label($tableName);
                $out[$tableName]['description'] = ($r['description'] ?? '') === '' ? null : (string) $r['description'];
                continue;
            }
            if ($policyFiltered && !$this->policy->isColumnAllowed($tableName, $column)) {
                continue;
            }
            $out[$tableName]['columns'][] = [
                'name' => $column,
                'type' => (string) ($r['data_type'] ?? ''),
                'description' => (string) ($r['description'] ?? ''),
                'label' => (string) ($r['label'] ?? '') !== '' ? (string) $r['label'] : SchemaHints::label($column),
                'sensitivity' => (string) ($r['sensitivity'] ?? 'internal'),
                'is_measure' => (int) ($r['is_measure'] ?? 0) === 1,
                'format' => (string) ($r['format'] ?? SchemaHints::format($column, (string) ($r['data_type'] ?? ''))),
                'aliases' => array_values(array_filter(array_map('trim', preg_split('/[,;|]/', (string) ($r['aliases'] ?? '')) ?? []), fn ($v) => $v !== '')),
                'source' => (string) ($r['description_source'] ?? 'auto'),
            ];
        }
        if ($policyFiltered) {
            foreach (array_keys($out) as $tableName) {
                if (!$this->policy->isTableAllowed($tableName)) {
                    unset($out[$tableName]);
                }
            }
        }
        return $out;
    }

    /** table.column => description, user words winning over guesses. */
    public function descriptionMap(): array
    {
        $map = [];
        foreach ($this->catalogRows() as $r) {
            $column = (string) $r['column_name'];
            $desc = trim((string) ($r['description'] ?? ''));
            if ($column === '' || $desc === '') {
                continue;
            }
            $map[$r['table_name'] . '.' . $column] = $desc;
        }
        return $map;
    }

    /** Persist a description a human wrote — protected from every later re-sync. */
    public function saveDescription(string $tableName, string $column, ?string $description, array $meta = []): bool
    {
        $table = $this->ensureSchemaTable();
        $row = [
            'table_name' => $tableName,
            'column_name' => $column,
            'data_type' => (string) ($meta['data_type'] ?? ''),
            'description' => $description,
            'description_source' => 'user',
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ];
        foreach (['label', 'table_label', 'sensitivity', 'format', 'aliases'] as $key) {
            if (array_key_exists($key, $meta)) {
                $row[$key] = is_array($meta[$key]) ? implode(', ', $meta[$key]) : $meta[$key];
            }
        }
        if (array_key_exists('is_measure', $meta)) {
            $row['is_measure'] = !empty($meta['is_measure']) ? 1 : 0;
        }
        // A host that has not run the newest migrations has no metadata columns yet.
        $row = array_intersect_key($row, array_flip($this->catalogColumns($table)));
        if (($row['data_type'] ?? '') === '') {
            // Never blank a type we already know.
            if ($this->existingType($tableName, $column) !== null) {
                unset($row['data_type']);
            }
        }
        $update = array_values(array_diff(array_keys($row), ['table_name', 'column_name', 'created_at', 'data_type']));
        Db::upsert($this->connection(), $table, $row, $update);
        return true;
    }

    private function existingType(string $tableName, string $column): ?string
    {
        $stmt = $this->connection()->prepare('SELECT data_type FROM `' . $this->schemaTable() . '` WHERE table_name = :t AND column_name = :c LIMIT 1');
        $stmt->execute(['t' => $tableName, 'c' => $column]);
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null || $value === '') ? null : (string) $value;
    }

    /** @return array<int,array<string,mixed>> */
    public function listSchema(int $limit = 1000): array
    {
        $table = $this->schemaTable();
        try {
            $stmt = $this->connection()->prepare(
                'SELECT id, table_name, column_name, data_type, description, label, table_label,'
                . ' sensitivity, is_measure, format, aliases, description_source, updated_at'
                . ' FROM `' . $table . '` ORDER BY table_name, column_name LIMIT :lim'
            );
            $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    public function deleteSchemaEntry(int $id): bool
    {
        $stmt = $this->connection()->prepare('DELETE FROM `' . $this->ensureSchemaTable() . '` WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /** Search the catalog for entries matching the query: "table.column: description". */
    public function searchSchema(string $query, int $k = 6): array
    {
        $words = $this->tokenize($query);
        if ($words === []) {
            return [];
        }
        $table = $this->schemaTable();
        $searchColumns = ['table_name', 'column_name', 'description', 'label', 'aliases'];
        $available = $this->catalogColumns($table);
        $searchColumns = array_values(array_filter($searchColumns, fn (string $c): bool => in_array($c, $available, true)));
        if ($searchColumns === []) {
            return [];
        }

        $parts = [];
        $bind = [];
        $i = 0;
        foreach ($this->tokenize($query) as $w) {
            $group = [];
            foreach ($searchColumns as $col) {
                $group[] = '`' . $col . '` LIKE :p' . $i;
                $bind[':p' . $i] = '%' . Db::escapeLike($w) . '%';
                $i++;
            }
            $parts[] = '(' . implode(' OR ', $group) . ')';
        }
        if ($parts === []) {
            return [];
        }
        $select = array_values(array_intersect(array_merge(['table_name', 'column_name', 'description'], $searchColumns), $available));
        $sql = 'SELECT `' . implode('`, `', $select) . '` FROM `' . $table . '` WHERE ' . implode(' OR ', $parts) . ' LIMIT 300';
        try {
            $stmt = $this->connection()->prepare($sql);
            $stmt->execute($bind);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }

        $scored = [];
        foreach ($rows as $r) {
            $tableName = (string) $r['table_name'];
            $column = (string) $r['column_name'];
            if (!$this->policy->isTableAllowed($tableName)) {
                continue;
            }
            if ($column !== '' && !$this->policy->isColumnAllowed($tableName, $column)) {
                continue;
            }
            $score = 0;
            $haystacks = [
                'table' => strtolower($tableName),
                'column' => strtolower($column),
                'description' => strtolower((string) ($r['description'] ?? '')),
                'label' => strtolower((string) ($r['label'] ?? '')),
                'aliases' => strtolower((string) ($r['aliases'] ?? '')),
            ];
            foreach ($words as $w) {
                if (str_contains($haystacks['table'], $w)) {
                    $score += 3;
                }
                if (str_contains($haystacks['column'], $w)) {
                    $score += 2;
                }
                if (str_contains($haystacks['description'], $w)) {
                    $score += 2;
                }
                if (str_contains($haystacks['label'], $w)) {
                    $score += 1;
                }
                if (str_contains($haystacks['aliases'], $w)) {
                    $score += 2;
                }
            }
            if ($score <= 0) {
                continue;
            }
            $scored[] = ['s' => $score, 't' => $tableName, 'c' => $column, 'd' => (string) ($r['description'] ?? '')];
        }
        usort($scored, fn (array $a, array $b): int => $b['s'] <=> $a['s']);

        $out = [];
        foreach (array_slice($scored, 0, max(1, $k)) as $r) {
            $label = trim(str_replace('_', ' ', $r['t'] . ' ' . $r['c']));
            $out[] = $label . ($r['d'] !== '' ? ': ' . $r['d'] : '');
        }
        return $out;
    }

    // ─── Import / export of the catalog itself ────────────────────────────────

    /** CSV the admin fills in: one line per column, description pre-filled with what is known. */
    public function schemaTemplate(): string
    {
        $header = ['table', 'column', 'data_type', 'description', 'label', 'sensitivity', 'is_measure', 'format', 'aliases'];
        $lines = [implode(',', $header)];
        $known = [];
        foreach ($this->catalogRows() as $r) {
            $known[$r['table_name'] . '.' . $r['column_name']] = $r;
        }
        foreach ($this->structureForTemplate() as $tableName => $columns) {
            foreach ($columns as $c) {
                $column = (string) $c['name'];
                if ($column === '' || !$this->policy->isColumnAllowed($tableName, $column)) {
                    continue;
                }
                $row = $known[$tableName . '.' . $column] ?? [];
                $desc = trim((string) ($row['description'] ?? ''));
                if ($desc === '') {
                    $desc = trim((string) ($c['comment'] ?? ''));
                }
                if ($desc === '') {
                    $desc = SchemaHints::autoDescription($tableName, $column);
                }
                $lines[] = implode(',', array_map(
                    fn (string $v): string => '"' . str_replace('"', '""', $v) . '"',
                    [
                        $tableName,
                        $column,
                        (string) ($c['type'] ?? ''),
                        $desc,
                        (string) ($row['label'] ?? '') !== '' ? (string) $row['label'] : SchemaHints::label($column),
                        (string) ($row['sensitivity'] ?? SchemaHints::sensitivity($column, (string) ($c['data_type'] ?? ''), $this->policy)),
                        (string) ((int) ($row['is_measure'] ?? (SchemaHints::isMeasure($column, (string) ($c['data_type'] ?? '')) ? 1 : 0))),
                        (string) ($row['format'] ?? SchemaHints::format($column, (string) ($c['data_type'] ?? ''))),
                        (string) ($row['aliases'] ?? ''),
                    ]
                ));
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Structure a template or a description form is built from: the live database
     * when `information_schema` can be read, otherwise exactly what the catalog
     * already knows. A host whose server hides its metadata still gets a fillable
     * CSV instead of an SQL error.
     *
     * @return array<string,array<int,array<string,mixed>>> table => columns
     */
    private function structureForTemplate(): array
    {
        $live = [];
        try {
            foreach ($this->tables() as $t) {
                $tableName = (string) ($t['name'] ?? '');
                if ($tableName === '' || !$this->policy->isTableAllowed($tableName)) {
                    continue;
                }
                $live[$tableName] = $this->columns($tableName);
            }
        } catch (\Throwable) {
            $live = [];
        }
        if ($live !== []) {
            return $live;
        }

        $fromCatalog = [];
        foreach ($this->catalogRows() as $row) {
            $tableName = (string) ($row['table_name'] ?? '');
            $column = (string) ($row['column_name'] ?? '');
            if ($tableName === '' || $column === '' || !$this->policy->isTableAllowed($tableName)) {
                continue;
            }
            $fromCatalog[$tableName][] = [
                'name' => $column,
                'type' => (string) ($row['data_type'] ?? ''),
                'data_type' => (string) ($row['data_type'] ?? ''),
                'comment' => (string) ($row['description'] ?? ''),
            ];
        }
        return $fromCatalog;
    }

    /**
     * Import descriptions written by the admin. table,column,data_type,description
     * (legacy) plus optional label/sensitivity/is_measure/format/aliases. A row with
     * an empty column is read as a table-level label/description.
     *
     * @return array{updated:int,created:int,skipped:int,errors:array<int,string>}
     */
    public function importDescriptions(string $csv, array $opts = []): array
    {
        $result = ['updated' => 0, 'created' => 0, 'skipped' => 0, 'errors' => []];
        $rows = CsvReader::parse($csv);
        if ($rows === []) {
            $result['errors'][] = 'Empty file';
            return $result;
        }
        $header = CsvReader::header($rows[0]);
        $iTable = CsvReader::column($header, ['table', 'table_name', 'tablename']);
        $iColumn = CsvReader::column($header, ['column', 'column_name', 'field']);
        $iDesc = CsvReader::column($header, ['description', 'meaning', 'comment']);
        if ($iTable === null || $iColumn === null || $iDesc === null) {
            $result['errors'][] = 'CSV needs "table", "column" and "description" headings';
            return $result;
        }
        $iType = CsvReader::column($header, ['data_type', 'type']);
        $iLabel = CsvReader::column($header, ['label']);
        $iTableLabel = CsvReader::column($header, ['table_label']);
        $iSens = CsvReader::column($header, ['sensitivity']);
        $iMeasure = CsvReader::column($header, ['is_measure', 'measure']);
        $iFormat = CsvReader::column($header, ['format']);
        $iAliases = CsvReader::column($header, ['aliases', 'alias']);

        $source = (string) ($opts['description_source'] ?? 'import');
        $existing = $this->catalogIndex();
        $table = $this->ensureSchemaTable();
        $stats = ['added' => 0, 'updated' => 0, 'unchanged' => 0];
        $cell = static fn (array $row, ?int $i): string => $i === null ? '' : trim((string) ($row[$i] ?? ''));

        foreach (array_slice($rows, 1) as $n => $row) {
            $row = array_map('strval', (array) $row);
            $tableName = $cell($row, $iTable);
            $column = $cell($row, $iColumn);
            $desc = $cell($row, $iDesc);
            if ($tableName === '' || $desc === '') {
                $result['skipped']++;
                continue;
            }
            try {
                if ($this->policy->isOwnTable($tableName) || in_array(strtolower($tableName), $this->policy->blockedTables(), true)) {
                    $result['skipped']++;
                    continue;
                }
                $existed = isset($existing[$tableName . '.' . $column]);

                if ($column === '') {
                    $this->writeCatalogRow(
                        $table, $existing, $stats, $tableName, '', '', $desc,
                        ['table_label' => $cell($row, $iTableLabel) !== '' ? $cell($row, $iTableLabel) : SchemaHints::label($tableName)],
                        $source
                    );
                } else {
                    if (!$this->policy->isColumnAllowed($tableName, $column)) {
                        $result['skipped']++;
                        continue;
                    }
                    $this->writeCatalogRow(
                        $table, $existing, $stats, $tableName, $column, $cell($row, $iType), $desc,
                        [
                            'label' => $cell($row, $iLabel) !== '' ? $cell($row, $iLabel) : SchemaHints::label($column),
                            'table_label' => SchemaHints::label($tableName),
                            'sensitivity' => $cell($row, $iSens) !== '' ? strtolower($cell($row, $iSens)) : SchemaHints::sensitivity($column, '', $this->policy),
                            'is_measure' => in_array(strtolower($cell($row, $iMeasure)), ['1', 'true', 'yes'], true) ? 1 : 0,
                            'format' => $cell($row, $iFormat),
                            'aliases' => $cell($row, $iAliases),
                        ],
                        $source
                    );
                }

                if ($existed) {
                    $result['updated']++;
                } else {
                    $result['created']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = 'Row ' . ($n + 2) . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    // ─── Reference values & metrics ───────────────────────────────────────────

    /**
     * Quote real values from small lookup tables. Which tables count as lookups is
     * configuration: reference.tables (explicit) or reference.auto_suffixes, and
     * only tables the policy allows are ever read.
     */
    public function extractReferenceValues(int $companyId, array $opts = []): array
    {
        if (!Config::bool('reference.enabled', true)) {
            return [];
        }
        $maxTables = max(1, Config::int('reference.max_tables', 60));
        $explicit = array_map('strtolower', Config::list('reference.tables', []));
        $suffixes = array_map(
            static fn (string $s): string => '_' . ltrim(strtolower(trim($s)), '_'),
            Config::list('reference.auto_suffixes', self::DEFAULT_LOOKUP_SUFFIXES)
        );
        $nameColumns = array_map('strtolower', Config::list('reference.name_columns', ['name', 'title', 'label']));
        $priceColumns = array_map('strtolower', Config::list('reference.price_columns', ['default_amount', 'price', 'amount', 'cost', 'rate', 'unit_price', 'selling_price']));

        $results = [];
        $count = 0;
        foreach ($this->candidateTables($opts['tables'] ?? null) as $tableName) {
            if ($explicit !== []) {
                if (!in_array(strtolower($tableName), $explicit, true)) {
                    continue;
                }
            } else {
                $matched = false;
                foreach ($suffixes as $suffix) {
                    if ($suffix !== '' && str_ends_with(strtolower($tableName), $suffix)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }
            if (++$count > $maxTables) {
                break;
            }
            $cols = array_map(static fn (array $c): string => strtolower((string) $c['name']), $this->columns($tableName));
            $nameCol = null;
            foreach ($nameColumns as $candidate) {
                if (in_array($candidate, $cols, true) && $this->policy->isColumnAllowed($tableName, $candidate)) {
                    $nameCol = $candidate;
                    break;
                }
            }
            if ($nameCol === null) {
                continue;
            }
            $priceCol = null;
            foreach ($priceColumns as $candidate) {
                if (in_array($candidate, $cols, true) && $this->policy->isColumnAllowed($tableName, $candidate)) {
                    $priceCol = $candidate;
                    break;
                }
            }

            $values = $this->lookupValues($tableName, $nameCol, $priceCol, $companyId);
            if ($values === []) {
                continue;
            }
            $label = $this->lookupLabel($tableName);
            $currency = Config::string('domain.currency', '');
            $summaryParts = [];
            foreach ($values as $v) {
                $summaryParts[] = $v['value'] !== null && $v['value'] !== ''
                    ? $v['name'] . ' (' . ($currency !== '' ? $currency . ' ' : '') . $v['value'] . ')'
                    : $v['name'];
            }
            $this->upsertReference($companyId, $tableName, $label, implode(', ', $summaryParts), 'lookup', [
                'values_json' => $values,
                'row_count' => count($values),
                'unit' => $currency !== '' ? $currency : null,
            ]);
            $results[] = ['table' => $tableName, 'label' => $label, 'count' => count($values)];
        }
        return $results;
    }

    /**
     * Row counts (and configured aggregates) per queryable table — the generic
     * replacement for the counts this package used to hardcode for one product.
     */
    public function extractMetrics(int $companyId, array $opts = []): array
    {
        $results = [];
        $currency = Config::string('domain.currency', '');

        foreach ((array) Config::get('metrics.custom', []) as $metric) {
            if (!is_array($metric) || empty($metric['table'])) {
                continue;
            }
            $tableName = (string) $metric['table'];
            if (!$this->policy->isTableAllowed($tableName)) {
                continue;
            }
            $value = $this->aggregateMetric($tableName, $metric, $companyId);
            if ($value === null) {
                continue;
            }
            $label = (string) ($metric['label'] ?? SchemaHints::label((string) ($metric['key'] ?? $tableName)));
            $this->upsertReference($companyId, 'metric:' . (string) ($metric['key'] ?? $tableName), $label, (string) $value, 'metric', [
                'unit' => ($metric['agg'] ?? 'count') === 'count' ? null : ($currency !== '' ? $currency : null),
            ]);
            $results[] = ['label' => $label, 'value' => $value, 'key' => (string) ($metric['key'] ?? $tableName)];
        }

        if (!Config::bool('metrics.auto', true)) {
            return $results;
        }

        $maxTables = max(1, Config::int('metrics.auto_max_tables', 40));
        $count = 0;
        foreach ($this->candidateTables($opts['tables'] ?? null) as $tableName) {
            if (++$count > $maxTables) {
                break;
            }
            $value = $this->aggregateMetric($tableName, ['agg' => 'count'], $companyId);
            if ($value === null) {
                continue;
            }
            $label = SchemaHints::label($this->singularize($tableName));
            $this->upsertReference($companyId, 'count:' . $tableName, $label, (string) $value, 'metric', ['row_count' => (int) $value]);
            $results[] = ['label' => $label, 'value' => $value, 'key' => 'count:' . $tableName];
        }

        return $results;
    }

    /** Back-compatible name for the old hardcoded counts method; now generic. */
    public function extractCounts(int $companyId): array
    {
        return $this->extractMetrics($companyId);
    }

    /** Write/refresh one {prefix}_reference row; kind keeps lookups and metrics apart. */
    public function upsertReference(int $companyId, string $sourceTable, string $label, string $summary, string $kind = 'lookup', array $extra = []): int
    {
        $table = Db::table('reference');
        $insert = [
            'company_id' => $companyId,
            'source_table' => $sourceTable,
            'kind' => $kind,
            'label' => $label,
            'summary' => mb_substr($summary, 0, 60000),
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ];
        $update = ['label', 'summary', 'updated_at'];
        foreach (['values_json', 'row_count', 'unit'] as $key) {
            if (array_key_exists($key, $extra)) {
                $insert[$key] = $key === 'values_json' ? Db::json((array) $extra[$key]) : $extra[$key];
                $update[] = $key;
            }
        }
        $columns = Db::columnsOf($this->connection(), $table);
        $insert = array_intersect_key($insert, array_flip($columns));
        $update = array_values(array_intersect($update, $columns));
        return Db::upsert($this->connection(), $table, $insert, $update);
    }

    public function searchReference(int $companyId, string $query, int $k = 5): array
    {
        $words = $this->tokenize($query);
        if ($words === []) {
            return [];
        }
        $table = Db::table('reference');
        $parts = [];
        $bind = [':cid' => $companyId];
        $i = 0;
        foreach ($words as $w) {
            $like = '%' . Db::escapeLike($w) . '%';
            $group = [];
            foreach (['source_table', 'label', 'summary'] as $col) {
                $group[] = '`' . $col . '` LIKE :p' . $i;
                $bind[':p' . $i] = $like;
                $i++;
            }
            $parts[] = '(' . implode(' OR ', $group) . ')';
        }
        try {
            $stmt = $this->connection()->prepare(
                'SELECT label, summary, kind FROM `' . $table . '` WHERE company_id = :cid AND (' . implode(' OR ', $parts) . ') LIMIT :lim'
            );
            $stmt->bindValue(':lim', max(1, $k), PDO::PARAM_INT);
            $stmt->execute($bind);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = (($r['label'] ?? '') !== '' ? $r['label'] . ': ' : '') . (string) ($r['summary'] ?? '');
        }
        return $out;
    }

    /** Every reference summary for the tenant — the model matches these semantically. */
    public function allReference(int $companyId, int $limit = 60): array
    {
        $table = Db::table('reference');
        try {
            $stmt = $this->connection()->prepare(
                'SELECT label, summary, kind FROM `' . $table . '` WHERE company_id = :cid ORDER BY kind, label LIMIT :lim'
            );
            $stmt->bindValue(':cid', $companyId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', max(1, $limit > 0 ? $limit : Config::int('reference.max_tables', 60)), PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = (($r['label'] ?? '') !== '' ? $r['label'] . ': ' : '') . (string) ($r['summary'] ?? '');
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function listReference(int $companyId, ?string $kind = null): array
    {
        $table = Db::table('reference');
        $columns = Db::columnsOf($this->connection(), $table);
        $select = array_values(array_intersect(
            ['id', 'company_id', 'source_table', 'kind', 'label', 'summary', 'values_json', 'row_count', 'unit', 'updated_at'],
            $columns
        ));
        $where = ['company_id' => $companyId];
        if ($kind !== null && in_array('kind', $columns, true)) {
            $where['kind'] = $kind;
        }
        try {
            return Db::select($this->connection(), $table, $where, [], [['label', 'asc']], 500, '', $select === [] ? null : $select);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Drop stored reference summaries (e.g. before a re-extract, or on data removal). */
    public function forgetReference(int $companyId, ?string $kind = null): int
    {
        $table = Db::table('reference');
        if ($kind === null) {
            $stmt = $this->connection()->prepare('DELETE FROM `' . $table . '` WHERE company_id = :cid');
            $stmt->execute(['cid' => $companyId]);
        } else {
            $stmt = $this->connection()->prepare('DELETE FROM `' . $table . '` WHERE company_id = :cid AND `kind` = :kind');
            $stmt->execute(['cid' => $companyId, 'kind' => $kind]);
        }
        return $stmt->rowCount();
    }

    // ─── Prompt context ───────────────────────────────────────────────────────

    /**
     * The schema card handed to the model for data questions: policy-allowed
     * tables, safe columns, the user's own wording for each column.
     *
     * @param array{focus?:string,include?:string[],exclude?:string[],max_tables?:int,max_chars?:int,relations?:bool} $opts
     */
    public function sqlSchemaContext(array $opts = []): string
    {
        $catalog = $this->catalog();
        $maxChars = (int) ($opts['max_chars'] ?? Config::int('schema.context_max_chars', 14000));
        $maxTables = (int) ($opts['max_tables'] ?? Config::int('schema.context_max_tables', 60));
        $include = array_map('strtolower', array_map('strval', (array) ($opts['include'] ?? [])));
        $exclude = array_map('strtolower', array_map('strval', (array) ($opts['exclude'] ?? [])));

        $names = array_keys($catalog);
        $focus = trim((string) ($opts['focus'] ?? ''));
        if ($focus !== '') {
            $names = $this->rankTables($names, $focus);
        } else {
            sort($names);
        }

        $blocks = [];
        $used = 0;
        $relations = [];
        foreach ($names as $tableName) {
            if (count($blocks) >= $maxTables) {
                break;
            }
            if ($exclude !== [] && in_array(strtolower($tableName), $exclude, true)) {
                continue;
            }
            if ($include !== [] && !in_array(strtolower($tableName), $include, true)) {
                continue;
            }
            $line = $this->describeCatalogTable($catalog[$tableName], $tableName);
            if ($line === '') {
                continue;
            }
            if ($used + strlen($line) > $maxChars) {
                continue;
            }
            $blocks[] = $line;
            $used += strlen($line) + 1;
            foreach ($catalog[$tableName]['columns'] as $col) {
                if (str_ends_with((string) $col['name'], '_id') && (string) $col['name'] !== 'id') {
                    $target = $this->joinTarget((string) $col['name'], $catalog);
                    if ($target !== null) {
                        $relations[] = $tableName . '.' . $col['name'] . ' -> ' . $target;
                    }
                }
            }
        }

        $out = implode("\n", $blocks);
        if (($opts['relations'] ?? true) && $relations !== []) {
            $out .= "\n\nJoins: " . implode('; ', array_slice(array_unique($relations), 0, 40));
        }
        $tenant = $this->policy->tenantColumn();
        if ($out !== '' && $tenant !== '') {
            $out .= "\nOnly rows belonging to the current '" . $tenant . "' are visible to this user.";
        }
        return $out;
    }

    /** One table rendered as a schema card line. */
    public function describeCatalogTable(array $entry, string $tableName): string
    {
        $cols = [];
        foreach ($entry['columns'] as $col) {
            $name = (string) $col['name'];
            if (!$this->policy->isColumnAllowed($tableName, $name)) {
                continue;
            }
            $type = (string) $col['type'];
            $desc = trim((string) $col['description']);
            $part = $name . ($type !== '' ? ':' . (string) preg_replace('/\(.*$/', '', $type) : '');
            if ($desc !== '' && strcasecmp($desc, SchemaHints::label($name)) !== 0) {
                $part .= ' "' . str_replace('"', "'", $desc) . '"';
            }
            $aliases = (array) ($col['aliases'] ?? []);
            if ($aliases !== []) {
                $part .= ' aka ' . implode('/', $aliases);
            }
            if (!empty($col['is_measure'])) {
                $part .= ' #measure';
            } elseif (($col['format'] ?? '') === 'money') {
                $part .= ' #money';
            }
            $sensitivity = (string) ($col['sensitivity'] ?? 'internal');
            if ($sensitivity === 'pii') {
                $part .= ' [pii:masked]';
            } elseif ($sensitivity === 'secret') {
                continue;
            }
            $cols[] = $part;
        }
        if ($cols === []) {
            return '';
        }
        $label = trim((string) ($entry['label'] ?? ''));
        $tableDesc = trim((string) ($entry['description'] ?? ''));
        $head = $tableName;
        if ($label !== '' && strtolower($label) !== strtolower(str_replace('_', ' ', $tableName))) {
            $head .= ' — ' . $label;
        }
        if ($tableDesc !== '') {
            $head .= ' (' . str_replace('"', "'", $tableDesc) . ')';
        }
        return $head . ': ' . implode(', ', $cols);
    }

    /** Human-readable card for one table, live structure + catalog wording. */
    public function describeTable(string $tableName, int $maxColumns = 0): string
    {
        $catalog = $this->catalog(false);
        $entry = $catalog[$tableName] ?? null;
        if ($entry !== null) {
            $cols = $entry['columns'];
            if ($maxColumns > 0) {
                $cols = array_slice($cols, 0, $maxColumns);
            }
            return $this->describeCatalogTable(['label' => $entry['label'], 'description' => $entry['description'], 'columns' => $cols], $tableName);
        }
        $out = [];
        try {
            $columns = $this->columns($tableName);
        } catch (\Throwable) {
            $columns = [];
        }
        foreach ($columns as $c) {
            $name = (string) $c['name'];
            if (!$this->policy->isColumnAllowed($tableName, $name)) {
                continue;
            }
            $desc = trim((string) ($c['comment'] ?? '')) ?: SchemaHints::autoDescription($tableName, $name);
            $out[] = $name . ':' . (string) preg_replace('/\(.*$/', '', (string) $c['type']) . ' "' . $desc . '"';
            if ($maxColumns > 0 && count($out) >= $maxColumns) {
                break;
            }
        }
        if ($out === []) {
            return $tableName . ': not catalogued yet — sync the schema or write a description for its columns.';
        }
        return $tableName . ': ' . implode(', ', $out);
    }

    /** Table names a human would recognise, for "what can you tell me about?" answers. */
    public function domainSummary(int $limit = 40): string
    {
        $catalog = $this->catalog();
        $names = [];
        if ($catalog !== []) {
            foreach (array_keys($catalog) as $tableName) {
                $names[] = SchemaHints::label($tableName);
                if (count($names) >= $limit) {
                    break;
                }
            }
            return implode(', ', $names);
        }
        foreach ($this->queryableTables() as $tableName) {
            $names[] = SchemaHints::label($tableName);
            if (count($names) >= $limit) {
                break;
            }
        }
        return implode(', ', $names);
    }

    // ─── SQL safety ───────────────────────────────────────────────────────────

    /** Statement-level guards. Checked outside string literals. */
    private const FORBIDDEN_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'GRANT', 'REVOKE',
        'RENAME', 'HANDLER', 'LOCK', 'UNLOCK', 'LOAD', 'OUTFILE', 'DUMPFILE',
        'SLEEP', 'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK', 'INTO', 'LOAD_FILE', 'information_schema',
    ];

    /** Error string, or null when the statement may run. */
    public function validateSql(string $sql, ?int $tenantId = null): ?string
    {
        $sql = $this->normaliseSql($sql);
        if ($sql === '') {
            return 'Empty query';
        }
        if (!preg_match('/^(SELECT|WITH)\b/i', $sql)) {
            return 'Only SELECT queries are allowed';
        }
        // Keywords are checked outside string literals so VALUES-like text is safe.
        $bare = self::withoutStringLiterals($sql);
        if (str_contains($bare, ';')) {
            return 'Multiple statements are not allowed';
        }
        foreach (self::FORBIDDEN_KEYWORDS as $kw) {
            if (preg_match('/(?<![`A-Za-z0-9_])' . preg_quote($kw, '/') . '(?![`A-Za-z0-9_])/i', $bare) === 1) {
                return 'Forbidden keyword: ' . $kw;
            }
        }

        $policyError = $this->policy->checkSql($sql);
        if ($policyError !== null) {
            return $policyError;
        }

        $tables = $this->policy->tablesInSql($sql);
        if ($tables === []) {
            return 'No table found in query';
        }
        foreach ($tables as $tableName) {
            if (!$this->policy->isTableAllowed($tableName)) {
                return "Table '{$tableName}' is not allowed by this application's policy";
            }
        }

        $tenantTables = [];
        foreach ($tables as $tableName) {
            if ($this->tableHasTenantColumn($tableName)) {
                $tenantTables[] = $tableName;
            }
        }
        return $this->tenantScopeCheck($bare, $tenantTables, $tenantId);
    }

    /**
     * Tenancy must survive a generated query: every table that carries the tenant
     * column has to be filtered by it, and not by somebody else's value.
     *
     * @param string[] $tenantTables tables (that the query uses) having the tenant column
     */
    public function tenantScopeCheck(string $sqlWithoutLiterals, array $tenantTables, ?int $tenantId = null): ?string
    {
        if ($tenantTables === [] || !$this->policy->requireTenantScope()) {
            return null;
        }
        $tenant = $this->policy->tenantColumn();
        foreach ($tenantTables as $tableName) {
            if (preg_match('/(?<![`A-Za-z0-9_])' . preg_quote($tenant, '/') . '(?![`A-Za-z0-9_])/i', $sqlWithoutLiterals) !== 1) {
                return "Query must filter by '" . $tenant . "'";
            }
            if ($tenantId !== null && $this->tenantLiteralConflict($sqlWithoutLiterals, $tenant, $tenantId)) {
                return 'Query filters by a different ' . $tenant . ' than the current user';
            }
        }
        return null;
    }

    /** @return array<int,array<string,mixed>> rows, policy-cleaned and capped */
    public function runReadOnly(string $sql, ?int $tenantId = null): array
    {
        return $this->runReadOnlyDetailed($sql, $tenantId)['rows'];
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,columns:string[],truncated:bool,limit:int,sql:string}
     */
    public function runReadOnlyDetailed(string $sql, ?int $tenantId = null): array
    {
        $error = $this->validateSql($sql, $tenantId);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
        $maxRows = $this->policy->maxRows();
        $finalSql = $this->applyLimit($sql, $this->policy->defaultLimit(), $maxRows);
        $limit = $this->limitOf($finalSql);
        $pdo = $this->connection();
        $stmt = $pdo->prepare($finalSql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $truncated = $limit > 0 && count($rows) >= $limit;
        if (count($rows) > $maxRows) {
            $rows = array_slice($rows, 0, $maxRows);
            $truncated = true;
        }
        $clean = [];
        foreach ($rows as $row) {
            $clean[] = $this->policy->maskRow($this->policy->stripRow($row));
        }
        $columns = array_keys($clean[0] ?? []);
        if ($columns === []) {
            $meta = $stmt->getColumnMeta();
            $columns = is_array($meta) ? array_map('strval', (array) ($meta['columns'] ?? [])) : [];
        }
        return [
            'rows' => $clean,
            'columns' => $columns,
            'truncated' => $truncated,
            'limit' => $limit,
            'sql' => $finalSql,
        ];
    }

    /**
     * Force/normalise the LIMIT clause so a generated query cannot dump a table.
     * `LIMIT a, b` (offset, row count) is capped on b, not on a.
     */
    public function applyLimit(string $sql, int $default, int $max): string
    {
        $default = max(1, $default);
        $max = max($default, $max);
        $trimmed = rtrim(trim($sql), "; \t\n\r");
        if (preg_match('/\bLIMIT\s+(\d+)\s*,\s*(\d+)\s*$/i', $trimmed, $m) === 1) {
            $capped = min((int) $m[2] > 0 ? (int) $m[2] : $default, $max);
            return (string) preg_replace('/\bLIMIT\s+\d+\s*,\s*\d+\s*$/i', 'LIMIT ' . (int) $m[1] . ', ' . $capped, $trimmed);
        }
        if (preg_match('/\bLIMIT\s+(\d+)(\s+OFFSET\s+(\d+))?\s*$/i', $trimmed, $m) === 1) {
            $capped = min((int) $m[1] > 0 ? (int) $m[1] : $default, $max);
            $offset = isset($m[3]) && $m[3] !== '' ? ' OFFSET ' . (int) $m[3] : '';
            return (string) preg_replace('/\bLIMIT\s+\d+(\s+OFFSET\s+\d+)?\s*$/i', 'LIMIT ' . $capped . $offset, $trimmed);
        }
        if (preg_match('/\bLIMIT\s+\d+/i', $trimmed) === 1) {
            // A LIMIT inside a subquery: still bound the outer statement.
            return $trimmed . ' LIMIT ' . $default;
        }
        return $trimmed . ' LIMIT ' . $default;
    }

    /** Replace quoted literals with spaces so keyword checks ignore their content. */
    public static function withoutStringLiterals(string $sql): string
    {
        return (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/s", ' ', $sql);
    }

    private function limitOf(string $sql): int
    {
        if (preg_match('/\bLIMIT\s+(\d+)\s*,\s*(\d+)\s*$/i', $sql, $m) === 1) {
            return (int) $m[2];
        }
        return preg_match('/\bLIMIT\s+(\d+)(?!\s*,)/i', $sql, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * The string every safety check runs against: comments removed by a
     * quote-aware scanner (so '-- inside a literal cannot hide a keyword),
     * whitespace collapsed, trailing semicolon dropped. Execution still uses
     * the caller's original SQL.
     */
    private function normaliseSql(string $sql): string
    {
        $clean = SqlDumpParser::stripComments(trim($sql));
        $clean = trim($clean, " \t\n\r\0\x0B;");
        return trim((string) preg_replace('/\s+/', ' ', $clean));
    }

    /** @return array<int,string> */
    public function tablesUsedInSql(string $sql): array
    {
        return $this->policy->tablesInSql($sql);
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    /**
     * Whether this service can reach a database at all. A catalog-only install
     * (no host PDO) is a legitimate setup, but reads must say so instead of
     * answering with an empty list.
     */
    public function connected(): bool
    {
        return $this->pdo !== null || $this->source !== null;
    }

    /**
     * A handful of values per short text column, so a description can state the
     * format or encoding the application actually writes — dates stored as
     * "31-03-2025", encrypted names, state enums. One query per table.
     *
     * @param array<int,array<string,mixed>> $columns information_schema rows
     * @return array<string,string[]>
     */
    private function sampleValues(string $table, array $columns, int $limit): array
    {
        $wanted = [];
        foreach ($columns as $c) {
            $type = strtolower((string) ($c['data_type'] ?? ''));
            if (in_array($type, ['char', 'varchar'], true)) {
                $wanted[] = (string) ($c['name'] ?? '');
            }
        }
        $wanted = array_values(array_filter($wanted));
        if ($wanted === []) {
            return [];
        }
        try {
            $sql = 'SELECT ' . implode(', ', array_map(static fn (string $c): string => '`' . Db::identifier($c) . '`', $wanted))
                . ' FROM `' . Db::identifier($table) . '` LIMIT ' . max(1, min(200, $limit));
            $rows = $this->connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            foreach ($wanted as $column) {
                $value = $row[$column] ?? null;
                if (is_scalar($value) && (string) $value !== '') {
                    $out[$column][] = (string) $value;
                }
            }
        }
        // A skewed table (99% NOTPAID) hides the other states from a row sample;
        // ask the database for the distinct values of enum-like columns instead.
        foreach ($wanted as $column) {
            $distinct = array_unique($out[$column] ?? []);
            if (count($distinct) >= 2 || !SchemaHints::isEnumColumn($column)) {
                continue;
            }
            try {
                $sql = 'SELECT DISTINCT `' . Db::identifier($column) . '` AS v FROM `' . Db::identifier($table) . '`'
                    . " WHERE `" . Db::identifier($column) . "` IS NOT NULL AND `" . Db::identifier($column) . "` <> '' LIMIT 12";
                $values = $this->connection()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
            } catch (\Throwable) {
                continue;
            }
            foreach ($values as $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $out[$column][] = (string) $value;
                }
            }
        }
        return $out;
    }

    private function connection(): PDO
    {
        if ($this->source !== null) {
            return $this->source;
        }
        if ($this->pdo === null) {
            throw new \RuntimeException(
                'No database connection available to the AI assistant. Pass the host PDO to the service, '
                . 'or configure the DSN/DB_* variables in .env.'
            );
        }
        return $this->pdo;
    }

    private function activeSchema(): ?string
    {
        return $this->sourceSchema;
    }

    private function schemaTable(): string
    {
        if ($this->schemaTable !== null) {
            return $this->schemaTable;
        }
        return $this->schemaTable = Db::table('schema');
    }

    /** Write paths call this so a host that forgot the migrations gets told. */
    private function ensureSchemaTable(): string
    {
        return Db::requireTable($this->connection(), 'schema');
    }

    /** table.column => catalog row, for change detection. */
    private function catalogIndex(): array
    {
        $index = [];
        try {
            foreach ($this->catalogRows() as $r) {
                $index[$r['table_name'] . '.' . $r['column_name']] = $r;
            }
        } catch (\Throwable) {
            return [];
        }
        return $index;
    }

    /**
     * Insert or refresh one catalog row. A description a human wrote, imported or
     * took from a dump is never replaced by an automatic guess.
     *
     * @param array<string,mixed> $meta
     */
    private function writeCatalogRow(
        string $table,
        array &$existing,
        array &$stats,
        string $tableName,
        string $column,
        string $dataType,
        ?string $description,
        array $meta,
        string $descriptionSource
    ): void {
        $key = $tableName . '.' . $column;
        $old = $existing[$key] ?? null;
        $oldDescription = $old === null ? '' : trim((string) ($old['description'] ?? ''));
        $oldSource = $old === null ? '' : strtolower(trim((string) ($old['description_source'] ?? '')));
        // A human's wording (typed in the admin UI or imported) outranks a guess.
        // A fresh human import still replaces an earlier human import.
        $incomingFromHuman = in_array($descriptionSource, self::PROTECTED_SOURCES, true);
        $keepOldText = $oldDescription !== '' && !$incomingFromHuman && in_array($oldSource, self::PROTECTED_SOURCES, true);

        $finalDescription = $keepOldText ? $oldDescription : $description;
        $finalSource = $keepOldText ? $oldSource : $descriptionSource;
        if (($finalDescription === null || trim($finalDescription) === '') && $oldDescription !== '') {
            $finalDescription = $oldDescription;
            $finalSource = $oldSource;
        }

        $insert = [
            'table_name' => $tableName,
            'column_name' => $column,
            'data_type' => mb_substr($dataType, 0, 250),
            'description' => $finalDescription,
            'description_source' => $finalSource,
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ];
        $columns = $this->catalogColumns($table);
        foreach (['label', 'table_label', 'sensitivity', 'is_measure', 'format', 'aliases'] as $field) {
            if (!array_key_exists($field, $meta) || !in_array($field, $columns, true)) {
                continue;
            }
            $value = $meta[$field];
            // A curated row keeps its labels, formats and measure flags too: a
            // sync that rewrote "Address" back to "Li address" every night is
            // not a sync, it is a regression. A detected secret still wins.
            if ($keepOldText && $old !== null) {
                $before = $old[$field] ?? null;
                $beforeText = is_scalar($before) ? trim((string) $before) : '';
                $escalatesSecret = $field === 'sensitivity' && (string) $value === 'secret' && $beforeText !== 'secret';
                if ($beforeText !== '' && !$escalatesSecret) {
                    $value = $before;
                }
            }
            $insert[$field] = $value;
        }
        $insert = array_intersect_key($insert, array_flip($columns));
        if (!array_key_exists('data_type', $insert) || $insert['data_type'] === '') {
            $knownType = $old === null ? null : (string) ($old['data_type'] ?? '');
            if ($knownType !== '') {
                $insert['data_type'] = $knownType;
            } else {
                unset($insert['data_type']);
            }
        }

        $changed = $old === null;
        if ($old !== null) {
            foreach ($insert as $field => $value) {
                if (in_array($field, ['table_name', 'column_name', 'created_at', 'updated_at'], true)) {
                    continue;
                }
                $before = $old[$field] ?? null;
                if ($field === 'is_measure') {
                    $changed |= (int) $before !== (int) $value;
                    continue;
                }
                $changed |= trim((string) $before) !== trim((string) $value);
            }
        }
        if (!$changed) {
            $stats['unchanged'] = ($stats['unchanged'] ?? 0) + 1;
            return;
        }
        $wasThere = $old !== null;
        $update = array_values(array_diff(array_keys($insert), ['table_name', 'column_name', 'created_at']));
        Db::upsert($this->connection(), $table, $insert, $update);
        $existing[$key] = $insert;
        if ($wasThere) {
            $stats['updated']++;
        } else {
            $stats['added']++;
        }
    }

    /** @return string[] */
    private function catalogColumns(string $table): array
    {
        if (isset($this->catalogColumnCache[$table])) {
            return $this->catalogColumnCache[$table];
        }
        return $this->catalogColumnCache[$table] = Db::columnsOf($this->connection(), $table);
    }

    /** Delete catalog rows for structure that no longer exists. */
    private function pruneStale(string $table, array $seen): int
    {
        if ($seen === []) {
            return 0;
        }
        $deleted = 0;
        try {
            $rows = $this->connection()
                ->query('SELECT id, table_name, column_name FROM `' . $table . '`')
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return 0;
        }
        $ids = [];
        foreach ($rows as $r) {
            $key = $r['table_name'] . '.' . $r['column_name'];
            if (isset($seen[$key]) || isset($seen[$r['table_name'] . '.*'])) {
                continue;
            }
            $ids[] = (int) $r['id'];
        }
        foreach (array_chunk($ids, 200) as $chunk) {
            $params = [];
            $bind = [];
            foreach ($chunk as $i => $id) {
                $params[] = ':id' . $i;
                $bind[':id' . $i] = $id;
            }
            $stmt = $this->connection()->prepare('DELETE FROM `' . $table . '` WHERE id IN (' . implode(',', $params) . ')');
            $stmt->execute($bind);
            $deleted += $stmt->rowCount();
        }
        return $deleted;
    }

    /** @return string[] live tables minus own/config-skipped, policy-filtered */
    private function candidateTables(?array $only): array
    {
        $skip = array_map('strtolower', Config::list('schema.skip_tables', []));
        $out = [];
        foreach ($this->tables() as $t) {
            $name = (string) $t['name'];
            if ($only !== null && $only !== [] && !in_array(strtolower($name), array_map('strtolower', array_map('strval', $only)), true)) {
                continue;
            }
            if (in_array(strtolower($name), $skip, true)) {
                continue;
            }
            if (!$this->policy->isTableAllowed($name, (string) ($t['comment'] ?? ''))) {
                continue;
            }
            $out[] = $name;
        }
        return $out;
    }

    /** @return array<int,array{name:string,value:?string}> */
    private function lookupValues(string $tableName, string $nameCol, ?string $priceCol, int $companyId): array
    {
        $limit = max(1, Config::int('reference.max_values', 50));
        $conditions = ['`' . Db::identifier($nameCol) . '` IS NOT NULL', "`" . Db::identifier($nameCol) . "` <> ''"];
        $bind = [];
        if ($this->tableHasTenantColumn($tableName)) {
            $tenant = Db::identifier($this->policy->tenantColumn());
            $conditions[] = '(`' . $tenant . '` = :cid OR `' . $tenant . '` IS NULL)';
            $bind[':cid'] = $companyId;
        }
        if ($this->tableHasColumn($tableName, 'is_active')) {
            $conditions[] = '(is_active IS NULL OR is_active = 1)';
        }
        $select = '`' . Db::identifier($nameCol) . '` AS name';
        if ($priceCol !== null) {
            $select .= ', `' . Db::identifier($priceCol) . '` AS value';
        }
        $sql = 'SELECT ' . $select . ' FROM `' . Db::identifier($tableName) . '` WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY `' . Db::identifier($nameCol) . '` ASC LIMIT ' . $limit;
        try {
            $stmt = $this->connection()->prepare($sql);
            $stmt->execute($bind);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) ($this->policy->maskRow($r)['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $value = $priceCol === null ? null : (isset($r['value']) && $r['value'] !== '' ? (string) $r['value'] : null);
            $out[] = ['name' => $name, 'value' => $value];
        }
        return $out;
    }

    /** count / sum / avg over one table, tenant-scoped when the column exists. */
    private function aggregateMetric(string $tableName, array $metric, int $companyId): ?string
    {
        $agg = strtolower((string) ($metric['agg'] ?? 'count'));
        if (!in_array($agg, ['count', 'sum', 'avg', 'min', 'max'], true)) {
            $agg = 'count';
        }
        $column = '*';
        if ($agg !== 'count') {
            $requested = strtolower(trim((string) ($metric['column'] ?? '')));
            if ($requested === '' || !$this->tableHasColumn($tableName, $requested) || !$this->policy->isColumnAllowed($tableName, $requested)) {
                return null;
            }
            $column = Db::identifier($requested);
        }
        $conditions = [];
        $bind = [];
        if (($metric['tenant'] ?? true) !== false && $this->tableHasTenantColumn($tableName)) {
            $tenant = Db::identifier($this->policy->tenantColumn());
            $conditions[] = '`' . $tenant . '` = :cid';
            $bind[':cid'] = $companyId;
        }
        if (!empty($metric['where']) && is_string($metric['where'])) {
            // Authored by the host's admin in config, never by a user or a model.
            $conditions[] = '(' . trim($metric['where']) . ')';
        }
        $sql = 'SELECT ' . $agg . '(' . $column . ') FROM `' . Db::identifier($tableName) . '`'
            . ($conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '');
        try {
            $stmt = $this->connection()->prepare($sql);
            $stmt->execute($bind);
            $value = $stmt->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
        if ($value === false || $value === null) {
            return $agg === 'count' ? '0' : null;
        }
        return $agg === 'count' ? (string) (int) $value : number_format((float) $value, 2, '.', '');
    }

    private function tableHasTenantColumn(string $tableName): bool
    {
        return $this->tableHasColumn($tableName, $this->policy->tenantColumn());
    }

    private function tableHasColumn(string $tableName, string $column): bool
    {
        try {
            return in_array(strtolower($column), Db::columnsOf($this->connection(), Db::identifier($tableName)), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Infer a join target from `*_id` naming when no FK metadata exists. */
    private function joinTarget(string $columnName, array $catalog): ?string
    {
        $base = (string) preg_replace('/_id$/', '', $columnName);
        if ($base === '') {
            return null;
        }
        foreach (array_keys($catalog) as $tableName) {
            if (strcasecmp($tableName, $base) === 0) {
                return $tableName;
            }
            if ($this->singularize($tableName) === strtolower($base)) {
                return $tableName;
            }
        }
        return null;
    }

    private function singularize(string $name): string
    {
        $n = strtolower(trim($name));
        if (str_ends_with($n, 'ies') && strlen($n) > 4) {
            return substr($n, 0, -3) . 'y';
        }
        if (str_ends_with($n, 'ses') || str_ends_with($n, 'xes')) {
            return (string) substr($n, 0, -2);
        }
        if (str_ends_with($n, 's') && !str_ends_with($n, 'ss')) {
            return substr($n, 0, -1);
        }
        return $n;
    }

    private function lookupLabel(string $tableName): string
    {
        $label = $tableName;
        foreach (array_map(
            static fn (string $s): string => '_' . ltrim(strtolower(trim($s)), '_'),
            Config::list('reference.auto_suffixes', self::DEFAULT_LOOKUP_SUFFIXES)
        ) as $suffix) {
            if ($suffix !== '' && str_ends_with(strtolower($label), $suffix)) {
                $label = substr($label, 0, -strlen($suffix));
                break;
            }
        }
        return SchemaHints::label($label);
    }

    /** @return string[] tables ordered by how well they match a question */
    private function rankTables(array $names, string $focus): array
    {
        $words = $this->tokenize($focus);
        $catalog = $this->catalog(false);
        $scored = [];
        foreach ($names as $tableName) {
            $score = 0;
            $haystack = strtolower($tableName . ' ' . SchemaHints::label($tableName)
                . ' ' . (string) ($catalog[$tableName]['label'] ?? '')
                . ' ' . (string) ($catalog[$tableName]['description'] ?? ''));
            foreach ($catalog[$tableName]['columns'] ?? [] as $col) {
                $haystack .= ' ' . strtolower((string) $col['name'] . ' ' . $col['label'] . ' ' . $col['description'] . ' ' . implode(' ', (array) $col['aliases']));
            }
            foreach ($words as $w) {
                if (str_contains($haystack, $w)) {
                    $score += 1;
                }
            }
            $scored[$tableName] = $score;
        }
        uksort($scored, static fn ($a, $b) => ($scored[$b] <=> $scored[$a]) ?: strcmp((string) $a, (string) $b));
        return array_keys($scored);
    }

    private function tenantLiteralConflict(string $sql, string $tenant, int $tenantId): bool
    {
        if (preg_match_all('/' . preg_quote($tenant, '/') . '\s*=\s*(\d+)/i', $sql, $m) !== false) {
            foreach ($m[1] as $literal) {
                if ((int) $literal === $tenantId) {
                    return false;
                }
            }
            return $m[1] !== [];
        }
        return false;
    }

    /** @return string[] */
    private function tokenize(string $query): array
    {
        $stop = ['what', 'are', 'the', 'you', 'your', 'do', 'does', 'did', 'is', 'a', 'an', 'of', 'for', 'i',
            'we', 'our', 'can', 'could', 'how', 'to', 'about', 'with', 'this', 'that', 'me', 'my', 'please',
            'hello', 'hi', 'and', 'or', 'in', 'on', 'at', 'from', 'there', 'their', 'them', 'us', 'it', 'its',
            'offering', 'offer', 'tell', 'give', 'show', 'list', 'available', 'much', 'many', 'were', 'was',
            'have', 'has', 'any', 'get', 'want', 'need', 'which', 'who', 'when', 'where', 'why'];
        $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($query)) ?: [];
        $out = [];
        foreach ($words as $w) {
            $w = trim($w);
            if (strlen($w) <= 2 || in_array($w, $stop, true)) {
                continue;
            }
            $out[] = $w;
            if (str_ends_with($w, 'ies') && strlen($w) > 4) {
                $out[] = substr($w, 0, -3) . 'y';
            } elseif (str_ends_with($w, 'es') && strlen($w) > 3) {
                $out[] = substr($w, 0, -2);
            } elseif (str_ends_with($w, 's') && strlen($w) > 3) {
                $out[] = substr($w, 0, -1);
            }
        }
        return array_values(array_unique($out));
    }
}
