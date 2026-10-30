<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Dto\QueryPlan;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\DateRange;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\DomainPack;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;

/**
 * QueryPlan → parameterised SQL. This is the safe data path.
 *
 * Nothing here arrives as SQL from the language model: the model picks names out
 * of the domain pack (dataset / metric / dimension / date field) and every name
 * is resolved against the pack before it reaches the string. Identifiers go
 * through Db::identifier, every value through a bound parameter, so a badly
 * prompted model still cannot inject anything.
 *
 * Scope comes from the host, never from the model: the tenant column and id from
 * config/policy, row scope and per-user hidden columns from
 * PermissionResolverInterface.
 *
 * An unknown name is rejected with the valid ones listed, which is what makes a
 * retry converge instead of wander.
 */
final class QueryCompiler
{
    /** Aggregations a pack metric may use. */
    private const AGGREGATES = ['sum', 'avg', 'min', 'max', 'count', 'count_distinct'];

    /** Functions a pack expression may use — everything else is refused. */
    private const ALLOWED_FUNCTIONS = [
        'ABS', 'AES_DECRYPT', 'AVG', 'CEIL', 'CEILING', 'COALESCE', 'CONCAT', 'CONCAT_WS', 'COUNT', 'CURDATE', 'CURRENT_DATE',
        'DATE', 'DATE_FORMAT',
        'DAY', 'DAYOFWEEK', 'DISTINCT', 'EXTRACT', 'FLOOR', 'FROM_BASE64', 'GREATEST', 'IF', 'IFNULL', 'LEAST', 'LEFT',
        'LENGTH', 'LOWER', 'MAX', 'MIN', 'MONTH', 'NOW', 'NULLIF', 'QUARTER', 'REPLACE', 'RIGHT', 'ROUND',
        'STR_TO_DATE', 'SUBSTRING', 'SUM', 'TIMESTAMPDIFF', 'TRIM', 'UPPER', 'WEEK', 'YEAR',
    ];

    /** Words that may appear in an expression besides identifiers and functions. */
    private const ALLOWED_WORDS = [
        'and', 'or', 'not', 'null', 'is', 'in', 'as', 'case', 'when', 'then', 'else', 'end',
        'distinct', 'interval', 'day', 'days', 'month', 'months', 'year', 'years', 'hour',
        'minute', 'second', 'between', 'like', 'asc', 'desc',
    ];

    private DomainPack $pack;
    private SchemaPolicy $policy;
    /** @var array<string,string[]> table => column names, from the schema catalog */
    private array $columns;
    private int $defaultLimit;
    private int $maxRows;

    /**
     * @param array<string,mixed> $options default_limit, max_rows
     * @param array<string,string[]> $tableColumns real column names per table, taken from
     *        the schema catalog; empty means nothing can be verified, so only the
     *        pack's own names are trusted.
     */
    public function __construct(DomainPack $pack, ?SchemaPolicy $policy = null, array $tableColumns = [], array $options = [])
    {
        $this->pack = $pack;
        $this->policy = $policy ?? new SchemaPolicy();
        $this->columns = [];
        foreach ($tableColumns as $table => $names) {
            $list = [];
            foreach ((array) $names as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $list[] = strtolower(trim($name));
                }
            }
            $this->columns[DomainPack::key((string) $table)] = $list;
        }
        $this->defaultLimit = max(1, (int) ($options['default_limit'] ?? $this->policy->defaultLimit()));
        $this->maxRows = max(1, (int) ($options['max_rows'] ?? $this->policy->maxRows()));
    }

    /** Column names of a table as the catalog knows them. @return string[] */
    public function columnsOf(string $table): array
    {
        return $this->columns[DomainPack::key($table)] ?? [];
    }

    public function hasColumn(string $table, string $column): bool
    {
        $key = DomainPack::key($table);
        if (!isset($this->columns[$key])) {
            return false;
        }
        return in_array(strtolower(trim($column)), $this->columns[$key], true);
    }

    public function pack(): DomainPack
    {
        return $this->pack;
    }

    public function maxRows(): int
    {
        return $this->maxRows;
    }

    /**
     * Compile a plan.
     *
     * @param array{tenant_id?:int|null,tenant_column?:string,row_scope?:array<string,string>,hidden_columns?:array<string,array<int,string>>,limit?:int} $scope
     * @return array{sql:string,bind:array<string,mixed>,tables:string[],columns:string[],limit:int,aggregate:bool,dataset:string,date_field:string,applied_filters:array<string,mixed>}
     * @throws \InvalidArgumentException when the plan names something that does not exist
     */
    public function compile(QueryPlan $plan, array $scope = []): array
    {
        $dataset = $this->resolveDataset($plan);
        $datasetName = (string) $dataset['name'];
        $table = Db::identifier((string) $dataset['table']);
        if (!$this->policy->isTableAllowed($table)) {
            throw new \InvalidArgumentException('The table "' . $table . '" is not readable by the assistant (table policy).');
        }

        $hidden = $this->hiddenFor($table, $scope);
        $bind = [];
        $joins = $this->joinsFor($dataset, $plan, $table);

        $aggregate = $plan->isAggregate();
        $select = [];
        $groupBy = [];
        $columns = [];

        if ($aggregate) {
            foreach ($plan->dimensions as $raw) {
                $dimension = $this->dimension($datasetName, (string) $raw, $hidden);
                $expression = $this->dimensionSql($dimension, $table, $joins);
                $alias = Db::identifier($this->alias((string) $dimension['name']));
                $select[] = $expression . ' AS `' . $alias . '`';
                $groupBy[] = $expression;
                $columns[] = $alias;
            }
        }

        foreach ($plan->metrics as $raw) {
            $metric = $this->metric($datasetName, (string) $raw, $hidden);
            $alias = Db::identifier($this->alias((string) $metric['name']));
            if ((string) $metric['function'] === 'expr') {
                $select[] = $this->expressionSql((string) $metric['expr'], $table, $joins, $hidden) . ' AS `' . $alias . '`';
            } else {
                $column = (string) $metric['column'] === '*' ? '*' : $this->columnSql((string) $metric['column'], $table, $joins, $hidden);
                $select[] = self::aggregateSql((string) $metric['function'], $column) . ' AS `' . $alias . '`';
            }
            $columns[] = $alias;
        }

        // Detail question (no grouping): read the fields the plan names, or the
        // dataset's own fields when it names none.
        if (!$aggregate && $select === []) {
            $dimensions = $this->dimensionsOf($dataset);
            $wanted = array_merge($plan->dimensions, array_column($plan->orderBy, 'field'), array_column($plan->filters, 'field'));
            $chosen = [];
            foreach ($wanted as $raw) {
                foreach ($dimensions as $dimension) {
                    if (self::same((string) $dimension['name'], (string) $raw) || self::same((string) ($dimension['label'] ?? ''), (string) $raw)) {
                        $chosen[(string) $dimension['name']] = $dimension;
                    }
                }
            }
            if ($chosen === []) {
                $chosen = array_slice($dimensions, 0, 12, true);
            }
            foreach ($chosen as $dimension) {
                $alias = Db::identifier($this->alias((string) $dimension['name']));
                $select[] = $this->dimensionSql($dimension, $table, $joins) . ' AS `' . $alias . '`';
                $columns[] = $alias;
            }
        }

        if ($select === []) {
            throw new \InvalidArgumentException('The query selected nothing. Ask for a metric (for example "how many") or name the fields to read.');
        }

        $where = [];
        $period = $this->dateFilter($dataset, $plan, $table, $joins, $hidden, $bind);
        $where = array_merge($where, array_values($period['sql']));
        $where = array_merge($where, $this->valueFilters($datasetName, $plan, $table, $joins, $hidden, $bind));

        $tenantColumn = $this->tenantFilter($table, $scope, $dataset, $bind);
        if ($tenantColumn !== '') {
            $where[] = '`' . $table . '`.`' . $tenantColumn . '` = :__tenant';
        }
        $rowScope = $this->rowScope($table, $scope, $hidden);
        if ($rowScope !== '') {
            $where[] = '(' . $rowScope . ')';
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM `' . $table . '`';
        foreach ($joins as $join) {
            $sql .= ' ' . $join['sql'];
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        if ($groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $groupBy);
        }
        $order = $this->orderBy($dataset, $plan, $table, $joins, $hidden, $columns, $aggregate);
        if ($order !== '') {
            $sql .= ' ORDER BY ' . $order;
        }
        $limit = $this->limit($plan, $scope);
        $sql .= ' LIMIT ' . $limit;

        return [
            'sql' => $sql,
            'bind' => $bind,
            'tables' => array_values(array_unique(array_merge([$table], array_column($joins, 'table')))),
            'columns' => array_values(array_unique($columns)),
            'limit' => $limit,
            'aggregate' => $aggregate,
            'dataset' => $datasetName,
            'date_field' => (string) ($dataset['date_field'] ?? ''),
            'applied_filters' => $this->appliedFilters($datasetName, $plan, $period['labels'], $tenantColumn, $rowScope !== ''),
        ];
    }

    // ─── resolution ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function resolveDataset(QueryPlan $plan): array
    {
        $wanted = $plan->dataset !== '' ? $plan->dataset : $plan->entity;
        $dataset = $wanted !== '' ? $this->pack->dataset($wanted) : null;
        if ($dataset === null && $plan->entity !== '') {
            $entity = $this->pack->entity($plan->entity);
            if ($entity !== null) {
                $dataset = $this->pack->dataset((string) $entity['table']);
            }
        }
        if ($dataset !== null) {
            return $dataset;
        }
        $names = $this->pack->datasetNames();
        throw new \InvalidArgumentException(
            'Unknown dataset "' . $wanted . '". Available: '
            . ($names === [] ? '(none yet — sync the schema first)' : implode(', ', array_slice($names, 0, 25))) . '.'
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function dimensionsOf(array $dataset): array
    {
        return array_values(array_filter((array) ($dataset['dimensions'] ?? []), 'is_array'));
    }

    /**
     * @param string[] $hidden
     * @return array<string,mixed>
     */
    private function dimension(string $datasetName, string $wanted, array $hidden): array
    {
        $found = $this->pack->dimension($datasetName, $wanted);
        if ($found === null) {
            throw new \InvalidArgumentException(
                'Unknown field "' . $wanted . '" on ' . $datasetName . '. Try: '
                . implode(', ', array_slice($this->pack->dimensionNames($datasetName), 0, 20)) . '.'
            );
        }
        $this->guardHidden((string) ($found['column'] ?? ''), $hidden, $wanted);
        return $found;
    }

    /**
     * @param string[] $hidden
     * @return array<string,mixed>
     */
    private function metric(string $datasetName, string $wanted, array $hidden): array
    {
        $found = $this->pack->metric($datasetName, $wanted);
        if ($found === null && DomainPack::key($wanted) === 'count') {
            $found = $this->pack->metric($datasetName, 'records');
        }
        if ($found === null) {
            throw new \InvalidArgumentException(
                'Unknown metric "' . $wanted . '" on ' . $datasetName . '. Try: '
                . implode(', ', array_slice($this->pack->metricNames($datasetName), 0, 20)) . '.'
            );
        }
        if ((string) ($found['function'] ?? '') !== 'expr' && (string) ($found['column'] ?? '') !== '*') {
            $this->guardHidden((string) $found['column'], $hidden, $wanted);
        }
        return $found;
    }

    // ─── fragments ────────────────────────────────────────────────────────────

    /**
     * Only the joins the plan actually touches. A pack column written
     * "customers.name", or a dimension declaring "via: customers", pulls one in.
     *
     * @param array<string,mixed> $dataset
     * @return array<int,array{table:string,alias:string,sql:string}>
     */
    private function joinsFor(array $dataset, QueryPlan $plan, string $table): array
    {
        $declared = [];
        foreach ((array) ($dataset['joins'] ?? []) as $join) {
            if (!is_array($join) || trim((string) ($join['table'] ?? '')) === '') {
                continue;
            }
            $tableAlias = Db::identifier((string) ($join['alias'] ?? $join['table']));
            $entry = [
                'table' => Db::identifier((string) $join['table']),
                'alias' => $tableAlias,
                'on' => (array) ($join['on'] ?? []),
                'type' => strtolower((string) ($join['type'] ?? 'left')) === 'inner' ? 'INNER' : 'LEFT',
            ];
            $declared[DomainPack::key((string) $join['table'])] = $entry;
            // Packs may alias a join ("listing.li_county" -> listing_info). A
            // column prefix resolves by either the table name or the alias.
            $aliasKey = DomainPack::key($tableAlias);
            if ($aliasKey !== '' && !isset($declared[$aliasKey])) {
                $declared[$aliasKey] = $entry;
            }
        }
        if ($declared === []) {
            return [];
        }

        $wanted = array_merge($plan->dimensions, $plan->metrics, array_column($plan->filters, 'field'), array_column($plan->orderBy, 'field'));
        $needed = [];
        foreach ($this->referencedTables($dataset, $wanted) as $key) {
            if (isset($declared[$key]) && $key !== DomainPack::key($table)) {
                $needed[$key] = $declared[$key];
            }
        }
        foreach ($this->dimensionsOf($dataset) as $dimension) {
            $via = DomainPack::key((string) ($dimension['via'] ?? ''));
            if ($via !== '' && $via !== DomainPack::key($table) && isset($declared[$via])) {
                foreach ($wanted as $name) {
                    if (self::same((string) $dimension['name'], (string) $name) || self::same((string) ($dimension['label'] ?? ''), (string) $name)) {
                        $needed[$via] = $declared[$via];
                    }
                }
            }
        }

        $out = [];
        $seen = [];
        foreach ($needed as $key => $join) {
            $aliasKey = DomainPack::key($join['alias']);
            if (isset($seen[$aliasKey])) {
                continue;
            }
            $seen[$aliasKey] = true;
            if (!$this->policy->isTableAllowed($join['table'])) {
                throw new \InvalidArgumentException('The joined table "' . $join['table'] . '" is not readable (table policy).');
            }
            $conditions = [];
            foreach ($join['on'] as $local => $remote) {
                $conditions[] = '`' . $this->qualify((string) $local, $table) . '` = `' . $join['alias'] . '`.`' . Db::identifier(self::columnOf((string) $remote)) . '`';
            }
            if ($conditions === []) {
                continue;
            }
            $out[$key] = [
                'table' => $join['table'],
                'alias' => $join['alias'],
                'sql' => $join['type'] . ' JOIN `' . $join['table'] . '` AS `' . $join['alias'] . '` ON ' . implode(' AND ', $conditions),
            ];
        }
        return array_values($out);
    }

    /**
     * @param array<string,mixed> $dataset
     * @param array<int,mixed> $names
     * @return string[] join keys those names reference
     */
    private function referencedTables(array $dataset, array $names): array
    {
        $keys = [];
        foreach ($names as $raw) {
            $dimension = $this->pack->dimension((string) $dataset['name'], (string) $raw);
            $metric = $this->pack->metric((string) $dataset['name'], (string) $raw);
            foreach ([$dimension, $metric] as $entry) {
                if ($entry === null) {
                    continue;
                }
                foreach (['column', 'expr'] as $field) {
                    $text = (string) ($entry[$field] ?? '');
                    if ($text !== '' && str_contains($text, '.')) {
                        if (preg_match_all('/`?([a-z0-9_]+)`?\s*\.\s*`?[a-z0-9_]+`?/i', $text, $matches) > 0) {
                            foreach ($matches[1] as $prefix) {
                                $keys[] = DomainPack::key((string) $prefix);
                            }
                        }
                    }
                }
            }
            $via = DomainPack::key((string) ($dimension['via'] ?? ''));
            if ($via !== '') {
                $keys[] = $via;
            }
        }
        return array_values(array_unique($keys));
    }

    /** @param array<int,array{table:string,alias:string}> $joins */
    private function dimensionSql(array $dimension, string $table, array $joins): string
    {
        $expr = trim((string) ($dimension['expr'] ?? ''));
        if ($expr !== '') {
            return $this->expressionSql($expr, $table, $joins, []);
        }
        return $this->columnSql((string) ($dimension['column'] ?? $dimension['name']), $table, $joins, []);
    }

    /**
     * @param array<int,array{table:string,alias:string}> $joins
     * @param string[] $hidden
     */
    private function columnSql(string $reference, string $table, array $joins, array $hidden): string
    {
        $this->guardHidden($reference, $hidden, $reference);
        $clean = str_replace('`', '', trim($reference));
        if (!str_contains($clean, '.')) {
            return '`' . $table . '`.`' . Db::identifier($clean) . '`';
        }
        [$prefix, $column] = preg_split('/\s*\.\s*/', $clean, 2) ?: [$table, $clean];
        $column = Db::identifier((string) $column);
        $prefixKey = DomainPack::key((string) $prefix);
        if ($prefixKey === DomainPack::key($table)) {
            return '`' . $table . '`.`' . $column . '`';
        }
        foreach ($joins as $join) {
            if (DomainPack::key($join['alias']) === $prefixKey || DomainPack::key($join['table']) === $prefixKey) {
                $this->guardHidden($column, $hidden, $reference);
                return '`' . $join['alias'] . '`.`' . $column . '`';
            }
        }
        throw new \InvalidArgumentException('Field "' . $reference . '" needs a join this dataset does not declare.');
    }

    /**
     * Legacy hosts frequently store dates as VARCHAR ("01-04-2025"). When the
     * dataset declares a date_format, the raw column is wrapped in STR_TO_DATE
     * so period filters and default ordering compare chronologically.
     *
     * @param array<int,array{table:string,alias:string}> $joins
     * @param string[] $hidden
     */
    private function dateColumnSql(array $dataset, string $reference, string $table, array $joins, array $hidden): string
    {
        $column = $this->columnSql($reference, $table, $joins, $hidden);
        $format = trim((string) ($dataset['date_format'] ?? ''));
        if ($format === '') {
            return $column;
        }
        if (preg_match('/^[%a-zA-Z0-9: _-]+$/', $format) !== 1) {
            throw new \InvalidArgumentException('Unsafe date format in the domain pack: "' . $format . '".');
        }
        return 'STR_TO_DATE(' . $column . ", '" . str_replace("'", "''", $format) . "')";
    }

    /**
     * Pack-authored expression, e.g. DATE_FORMAT(orders.created_at, '%Y-%m').
     * Literals pass through untouched; every identifier is checked against the
     * tables in this query, only whitelisted functions are allowed, and a bare
     * column that exists in two joined tables is refused rather than guessed.
     *
     * @param array<int,array{table:string,alias:string}> $joins
     * @param string[] $hidden
     */
    private function expressionSql(string $expression, string $table, array $joins, array $hidden): string
    {
        $trimmed = trim($expression);
        if ($trimmed === '' || str_contains($trimmed, ';') || preg_match('/(--|#|\/\*)/', $trimmed) === 1) {
            throw new \InvalidArgumentException('Unsafe expression in the domain pack: "' . $expression . '".');
        }
        $upper = strtoupper($trimmed);
        foreach (['UNION', 'INTO OUTFILE', 'DUMPFILE', 'SLEEP(', 'BENCHMARK(', 'LOAD_FILE', 'INFORMATION_SCHEMA', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'GRANT', '@@'] as $banned) {
            if (str_contains($upper, $banned)) {
                throw new \InvalidArgumentException('Unsafe expression in the domain pack: "' . $expression . '".');
            }
        }

        // Function names are checked on the literal-stripped copy, so a date
        // format string can never look like a call.
        $bare = self::withoutLiterals($trimmed);
        if (preg_match_all('/`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*\(/', $bare, $calls) > 0) {
            foreach ($calls[1] as $name) {
                if (!in_array(strtoupper((string) $name), self::ALLOWED_FUNCTIONS, true)) {
                    throw new \InvalidArgumentException('Function "' . $name . '" is not allowed in a domain pack expression.');
                }
            }
        }
        $withoutCalls = (string) preg_replace('/`?[a-zA-Z_][a-zA-Z0-9_]*`?\s*\(/', ' ', $bare);
        // Session variables ("@host_key") are host-owned settings, not columns.
        $withoutCalls = (string) preg_replace('/@[a-zA-Z_][a-zA-Z0-9_]*/', ' ', $withoutCalls);
        $known = $this->knownColumns($table, $joins);
        $tableKeys = array_merge(array_column($known, 'table'), [DomainPack::key($table)]);
        foreach ($joins as $join) {
            $tableKeys[] = DomainPack::key((string) ($join['alias'] ?? ''));
        }
        $tableKeys = array_values(array_unique(array_filter($tableKeys)));

        // A qualified column ("users.user_fname") is written by the pack author,
        // who may read a column the catalog hides (blocked or masked PII). The
        // qualifier must be real; the column is the host's responsibility.
        if (preg_match_all('/`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*\.\s*`?[a-zA-Z_][a-zA-Z0-9_]*`?/', $withoutCalls, $matches) > 0) {
            foreach ($matches[1] as $qualifier) {
                if ($known !== [] && !in_array(DomainPack::key((string) $qualifier), $tableKeys, true)) {
                    throw new \InvalidArgumentException('The pack expression "' . $expression . '" references unknown table "' . $qualifier . '".');
                }
            }
        }
        $unqualified = (string) preg_replace('/`?[a-zA-Z_][a-zA-Z0-9_]*`?\s*\.\s*`?[a-zA-Z_][a-zA-Z0-9_]*`?/', ' ', $withoutCalls);
        if (preg_match_all('/[a-zA-Z_][a-zA-Z0-9_]*/', $unqualified, $words) > 0) {
            foreach ($words[0] as $word) {
                $lower = strtolower((string) $word);
                if (in_array($lower, self::ALLOWED_WORDS, true)) {
                    continue;
                }
                $isTable = in_array(DomainPack::key($lower), $tableKeys, true);
                $isColumn = in_array($lower, array_column($known, 'column'), true);
                // With no schema attached nothing is verifiable; the pack itself
                // is the authority and the identifier test is skipped.
                if ($known !== [] && !$isTable && !$isColumn) {
                    throw new \InvalidArgumentException('The pack expression "' . $expression . '" references unknown "' . $word . '".');
                }
            }
        }

        foreach ($hidden as $column) {
            if ($column !== '' && preg_match('/(?<![\w`])' . preg_quote(self::columnOf($column), '/') . '(?![\w`])/i', $bare) === 1) {
                throw new \InvalidArgumentException('That field is not readable for this user.');
            }
        }

        if ($joins !== [] && $known !== []) {
            $counts = [];
            foreach ($known as $entry) {
                $counts[$entry['column']] = ($counts[$entry['column']] ?? 0) + 1;
            }
            foreach ($counts as $column => $times) {
                if ($times > 1 && preg_match('/(?<![\w.`])' . preg_quote((string) $column, '/') . '(?![\w`(])/i', $bare) === 1) {
                    throw new \InvalidArgumentException('Column "' . $column . '" is ambiguous in this pack expression — qualify it with its table.');
                }
            }
        }

        return '(' . $trimmed . ')';
    }

    /**
     * Columns of the tables in this query, as a pack author writes them.
     *
     * @param array<int,array{table:string,alias:string}> $joins
     * @return array<int,array{table:string,column:string}>
     */
    private function knownColumns(string $table, array $joins): array
    {
        $out = [];
        $sources = array_merge([$table], array_column($joins, 'table'));
        foreach ($sources as $index => $candidate) {
            $alias = $index === 0
                ? DomainPack::key($table)
                : DomainPack::key((string) ($joins[$index - 1]['alias'] ?? $candidate));
            $realTable = DomainPack::key($candidate);
            foreach ($this->columns[$realTable] ?? $this->columns[$alias] ?? [] as $name) {
                if ($name !== '') {
                    $out[] = ['table' => $alias, 'column' => $name];
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $dataset
     * @param array<int,array{table:string,alias:string}> $joins
     * @param string[] $hidden
     * @param array<string,mixed> $bind
     * @return array{sql:array<string,string>,labels:array<string,string>}
     */
    private function dateFilter(array $dataset, QueryPlan $plan, string $table, array $joins, array $hidden, array &$bind): array
    {
        $range = (array) $plan->dateRange;
        if ($range === []) {
            return ['sql' => [], 'labels' => []];
        }
        $field = trim((string) ($plan->raw['date_field'] ?? $dataset['date_field'] ?? ''));
        if ($field === '') {
            // Nothing to window on: keep the label so the answer can state that
            // the period was not applied, instead of pretending it was.
            return ['sql' => [], 'labels' => ['period' => trim(implode(' ', array_filter($range)))]];
        }
        $resolved = DateRange::resolve($range, self::timezone());
        $column = $this->dateColumnSql($dataset, $field, $table, $joins, $hidden);
        $sql = [];
        if (!empty($resolved['from'])) {
            $bind[':from'] = (string) $resolved['from'];
            $sql['from'] = $column . ' >= :from';
        }
        if (!empty($resolved['to'])) {
            $bind[':to'] = (string) $resolved['to'];
            $sql['to'] = $column . ' <= :to';
        }
        return ['sql' => $sql, 'labels' => ['period' => (string) ($resolved['label'] ?? '')]];
    }

    /**
     * @param array<int,array{table:string,alias:string}> $joins
     * @param string[] $hidden
     * @param array<string,mixed> $bind
     * @return string[]
     */
    private function valueFilters(string $datasetName, QueryPlan $plan, string $table, array $joins, array $hidden, array &$bind): array
    {
        $out = [];
        $index = 0;
        foreach ($plan->filters as $filter) {
            $field = (string) ($filter['field'] ?? '');
            $operator = strtolower(trim((string) ($filter['operator'] ?? '=')));
            $value = $filter['value'] ?? null;
            $dimension = $this->dimension($datasetName, $field, $hidden);
            $column = trim((string) ($dimension['expr'] ?? '')) !== ''
                ? $this->dimensionSql($dimension, $table, $joins)
                : $this->columnSql((string) ($dimension['column'] ?? $field), $table, $joins, $hidden);
            $param = ':f' . $index++;

            if ($operator === 'is_null' || $operator === 'not_null') {
                $out[] = $column . ($operator === 'is_null' ? ' IS NULL' : ' IS NOT NULL');
                continue;
            }
            if ($operator === 'between') {
                $pair = array_values((array) $value);
                if (count($pair) !== 2) {
                    throw new \InvalidArgumentException('Filter "' . $field . '" with operator between needs exactly two values.');
                }
                $bind[$param . '_a'] = $pair[0];
                $bind[$param . '_b'] = $pair[1];
                $out[] = $column . ' BETWEEN ' . $param . '_a AND ' . $param . '_b';
                continue;
            }
            if ($operator === 'in' || $operator === 'not_in') {
                $items = array_values(array_filter((array) $value, static fn ($v): bool => $v !== null && $v !== ''));
                if ($items === []) {
                    continue;
                }
                $params = [];
                foreach ($items as $position => $item) {
                    $name = $param . '_' . $position;
                    $bind[$name] = $item;
                    $params[] = $name;
                }
                $out[] = $column . ($operator === 'in' ? ' IN (' : ' NOT IN (') . implode(', ', $params) . ')';
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            if ($operator === 'like' || $operator === 'contains') {
                $bind[$param] = '%' . Db::escapeLike((string) $value) . '%';
                $out[] = $column . ' LIKE ' . $param;
                continue;
            }
            if (!in_array($operator, ['=', '!=', '>', '>=', '<', '<='], true)) {
                throw new \InvalidArgumentException('Unsupported filter operator "' . $operator . '" on "' . $field . '".');
            }
            $bind[$param] = $value;
            $out[] = $column . ' ' . $operator . ' ' . $param;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $dataset
     * @param array<string,mixed> $bind
     */
    private function tenantFilter(string $table, array $scope, array $dataset, array &$bind): string
    {
        if (!array_key_exists('tenant_id', $scope) || (bool) ($dataset['tenant_scoped'] ?? true) === false) {
            return '';
        }
        $column = trim((string) ($scope['tenant_column'] ?? $this->policy->tenantColumn()));
        if ($column === '') {
            return '';
        }
        if ($this->columns !== [] && !$this->hasColumn($table, $column)) {
            return '';
        }
        $tenantId = (int) $scope['tenant_id'];
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('No tenant is set for this request, so no business data may be read.');
        }
        $bind[':__tenant'] = $tenantId;
        return Db::identifier($column);
    }

    /**
     * Host row scope (PermissionResolverInterface), verbatim after a syntactic
     * once-over: it is the host's own SQL, but a comment or a second statement
     * must not survive into the query. Bare columns get qualified against the
     * dataset table so a join cannot widen it.
     *
     * @param array<string,mixed> $scope
     * @param string[] $hidden
     */
    private function rowScope(string $table, array $scope, array $hidden): string
    {
        $fragments = (array) ($scope['row_scope'] ?? []);
        $fragment = trim((string) ($fragments[$table] ?? ''));
        if ($fragment === '') {
            foreach ($fragments as $key => $candidate) {
                if (DomainPack::key((string) $key) === DomainPack::key($table) && trim((string) $candidate) !== '') {
                    $fragment = trim((string) $candidate);
                }
            }
        }
        if ($fragment === '') {
            return '';
        }
        if (str_contains($fragment, ';') || preg_match('/(--|#|\/\*|\bUNION\b|\bINFORMATION_SCHEMA\b|\bDROP\b|\bDELETE\b|\bINSERT\b|\bUPDATE\b)/i', $fragment) === 1) {
            throw new \InvalidArgumentException('The host supplied an unusable row-scope fragment for "' . $table . '".');
        }
        foreach ($hidden as $column) {
            if ($column !== '' && preg_match('/(?<![\w.`])' . preg_quote(self::columnOf($column), '/') . '(?![\w`])/i', $fragment) === 1) {
                throw new \InvalidArgumentException('The row scope for "' . $table . '" uses a column this user may not read.');
            }
        }
        return (string) preg_replace_callback(
            '/(?<![\w.`])`?([a-z][a-z0-9_]*)`?(?=\s*(=|!=|<>|>=|<=|>|<|\bIN\b|\bLIKE\b|\bBETWEEN\b|\bIS\b))/i',
            static fn (array $m): string => '`' . $table . '`.`' . Db::identifier((string) $m[1]) . '`',
            $fragment
        );
    }

    /**
     * @param array<string,mixed> $dataset
     * @param array<string,string> $periodLabels
     * @return array<string,mixed>
     */
    private function appliedFilters(string $datasetName, QueryPlan $plan, array $periodLabels, string $tenantColumn, bool $hasRowScope): array
    {
        $labels = ['dataset' => $datasetName] + array_filter($periodLabels, fn (string $v): bool => $v !== '');
        foreach ($plan->filters as $filter) {
            $field = (string) ($filter['field'] ?? '');
            $operator = (string) ($filter['operator'] ?? '=');
            $value = $filter['value'] ?? null;
            $dimension = $this->pack->dimension($datasetName, $field);
            $label = (string) ($dimension['label'] ?? $field);
            $display = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            $labels[$field] = trim($label . ' ' . ($operator === '=' ? '' : $operator . ' ') . $display);
        }
        if ($tenantColumn !== '') {
            $labels['tenant'] = 'scoped by ' . $tenantColumn;
        }
        if ($hasRowScope) {
            $labels['row_scope'] = 'restricted by your permissions';
        }
        return $labels;
    }

    /**
     * @param array<string,mixed> $dataset
     * @param array<int,array{table:string,alias:string}> $joins
     * @param string[] $hidden
     * @param string[] $columns
     * @return string the ORDER BY body ('' when there is nothing to order by)
     */
    private function orderBy(array $dataset, QueryPlan $plan, string $table, array $joins, array $hidden, array $columns, bool $aggregate): string
    {
        $datasetName = (string) $dataset['name'];
        $clauses = [];
        $dimensionAliases = array_map(fn (string $raw): string => $this->alias($raw), $plan->dimensions);

        foreach ($plan->orderBy as $clause) {
            $field = (string) ($clause['field'] ?? '');
            $direction = strtolower((string) ($clause['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            if ($field === '') {
                continue;
            }
            $alias = $this->alias($field);
            if (in_array($alias, $columns, true)) {
                $clauses[] = '`' . Db::identifier($alias) . '` ' . $direction;
                continue;
            }
            if ($aggregate && $this->pack->metric($datasetName, $field) !== null) {
                $metric = $this->metric($datasetName, $field, $hidden);
                $clauses[] = '`' . Db::identifier($this->alias((string) $metric['name'])) . '` ' . $direction;
                continue;
            }
            $dimension = $this->dimension($datasetName, $field, $hidden);
            // Grouped by something else: ordering by a column that is neither
            // grouped nor aggregated is not valid SQL, so it is dropped.
            if ($aggregate && !in_array($alias, $dimensionAliases, true)) {
                continue;
            }
            $clauses[] = ($aggregate ? '`' . Db::identifier($alias) . '`' : $this->dimensionSql($dimension, $table, $joins)) . ' ' . $direction;
        }

        if ($clauses !== []) {
            return implode(', ', $clauses);
        }

        if ($aggregate) {
            // Biggest first: the first column that is not a grouping dimension.
            foreach ($columns as $column) {
                if (!in_array($column, $dimensionAliases, true)) {
                    return '`' . Db::identifier($column) . '` DESC';
                }
            }
            return '';
        }

        $dateField = trim((string) ($dataset['date_field'] ?? ''));
        if ($dateField !== '' && ($this->columns === [] || $this->hasColumn($table, $dateField))) {
            return $this->dateColumnSql($dataset, $dateField, $table, $joins, $hidden) . ' DESC';
        }
        return $columns === [] ? '' : '`' . Db::identifier($columns[0]) . '` ASC';
    }

    /** @param array<string,mixed> $scope */
    private function limit(QueryPlan $plan, array $scope): int
    {
        $requested = $plan->limit > 0 ? $plan->limit : (int) ($scope['limit'] ?? 0);
        if ($requested <= 0) {
            $requested = $this->defaultLimit;
        }
        return max(1, min($requested, $this->maxRows));
    }

    /**
     * Columns this query must not touch: the host's per-user list plus the
     * policy's blocked set (never sent to a model, never readable here).
     *
     * @param array<string,mixed> $scope
     * @return string[]
     */
    private function hiddenFor(string $table, array $scope): array
    {
        $configured = array_map('strval', (array) ($scope['hidden_columns'][$table] ?? []));
        $names = array_merge($configured, array_map('strval', $this->policy->blockedColumns()));
        return array_values(array_unique(array_filter(array_map(self::columnOf(...), $names), fn (string $c): bool => $c !== '')));
    }

    /** @param string[] $hidden */
    private function guardHidden(string $column, array $hidden, string $label): void
    {
        if ($column === '' || $hidden === []) {
            return;
        }
        $bare = self::columnOf($column);
        foreach ($hidden as $hiddenColumn) {
            if (strcasecmp($bare, $hiddenColumn) === 0) {
                throw new \InvalidArgumentException('"' . $label . '" is not readable for this user.');
            }
        }
    }

    /** Output column name for a metric or dimension. */
    private function alias(string $name): string
    {
        $clean = (string) preg_replace('/[^a-z0-9_]/i', '_', trim($name));
        $clean = (string) preg_replace('/_+/', '_', $clean);
        $clean = trim($clean, '_');
        if ($clean === '' || preg_match('/^[a-z]/i', $clean) !== 1) {
            $clean = 'value_' . $clean;
        }
        return strtolower(substr($clean, 0, 60));
    }

    private static function aggregateSql(string $function, string $column): string
    {
        return match ($function) {
            'count' => 'COUNT(' . $column . ')',
            'count_distinct' => 'COUNT(DISTINCT ' . $column . ')',
            'avg' => 'ROUND(AVG(' . $column . '), 2)',
            'sum' => 'ROUND(SUM(' . $column . '), 2)',
            'min' => 'MIN(' . $column . ')',
            'max' => 'MAX(' . $column . ')',
            default => throw new \InvalidArgumentException('Unsupported aggregate "' . $function . '".'),
        };
    }

    /** @return string[] aggregates a pack may declare */
    public static function aggregates(): array
    {
        return self::AGGREGATES;
    }

    /** Quote-aware literal removal, so an identifier scan sees no string bodies. */
    private static function withoutLiterals(string $sql): string
    {
        return (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", ' ', $sql);
    }

    private static function same(string $name, string $wanted): bool
    {
        return DomainPack::key($name) === DomainPack::key($wanted);
    }

    /** "customers.name" → "name". */
    private static function columnOf(string $reference): string
    {
        $clean = str_replace('`', '', trim($reference));
        if ($clean === '') {
            return '';
        }
        $parts = preg_split('/\s*\.\s*/', $clean) ?: [];
        return trim((string) end($parts));
    }

    /** "orders.customer_id" → "orders`.`customer_id"; a bare name gets the base table. */
    private function qualify(string $reference, string $table): string
    {
        $clean = str_replace('`', '', trim($reference));
        if ($clean === '') {
            throw new \InvalidArgumentException('A join condition is empty.');
        }
        if (!str_contains($clean, '.')) {
            return $table . '`.`' . Db::identifier($clean);
        }
        [$prefix, $column] = preg_split('/\s*\.\s*/', $clean, 2) ?: [$table, $clean];
        return Db::identifier((string) $prefix) . '`.`' . Db::identifier((string) $column);
    }

    private static function timezone(): ?\DateTimeZone
    {
        $name = Config::string('timezone', 'UTC');
        try {
            return new \DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
