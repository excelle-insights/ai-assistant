<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

use ExcelleInsights\AiAssistant\Services\SchemaService;

/**
 * The semantic layer: the small, named vocabulary the assistant is allowed to
 * ask questions about — datasets, metrics, dimensions, entities and write
 * actions — instead of the raw table-and-column dump.
 *
 * A host declares it in its own config (no vendor edits):
 *
 *   return [ 'semantic' => [ 'packs' => [[
 *       'name' => 'sales',
 *       'datasets' => [[
 *           'name' => 'orders', 'table' => 'orders', 'label' => 'Orders',
 *           'date_field' => 'created_at',
 *           'metrics' => [['name' => 'revenue', 'function' => 'sum', 'column' => 'total_amount', 'format' => 'money']],
 *           'dimensions' => [['name' => 'status', 'column' => 'status']],
 *           'joins' => [['table' => 'customers', 'on' => ['orders.customer_id' => 'customers.id']]],
 *       ]],
 *       'entities' => [['name' => 'order', 'table' => 'orders', 'title_column' => 'reference']],
 *       'actions' => [['name' => 'create_lead', 'label' => 'Add a lead', 'handler' => 'crm', 'fields' => [...]],],
 *   ]] } ];
 *
 * or hands the same shape to DomainPack::load() / Config::load(), or points at a
 * JSON file with AI_ASSISTANT_SEMANTIC_PACK.
 *
 * Anything a host does not declare is derived from the schema catalog the
 * operator has already described, so the assistant works on a new application
 * with zero pack authoring — just less precisely. Pack definitions always win
 * over derived ones, and derived entries never expose a blocked or PII column.
 */
final class DomainPack
{
    /** @var array<string,array<string,mixed>> keyed by normalised dataset name */
    private array $datasets = [];
    /** @var array<string,array<string,mixed>> */
    private array $entities = [];
    /** @var array<string,array<string,mixed>> */
    private array $actions = [];
    /** @var array<int,array<string,mixed>> the reports this CRM already has */
    private array $reports = [];
    /** @var array<string,true> tables a pack dataset already answers for */
    private array $packTables = [];
    /** @var array<int,array<string,mixed>> */
    private array $packs;
    /** @var array<string,array<string,mixed>> */
    private array $catalog;
    private int $maxDimensions;
    private int $maxMetrics;
    /** @var array<string,array<string,mixed>> source table.column => relation */
    private array $relations = [];

    private const AGGREGATES = ['sum', 'avg', 'min', 'max', 'count', 'count_distinct'];

    /**
     * @param array<int,array<string,mixed>> $packs   host-authored packs
     * @param array<string,array<string,mixed>> $catalog SchemaService::catalog() output
     * @param array<string,mixed> $options auto_derive, max_dimensions, max_datasets,
     *        relations (approved key-to-name relations), allowed_tables
     */
    public function __construct(array $packs = [], array $catalog = [], array $options = [])
    {
        $this->packs = array_values(array_filter($packs, 'is_array'));
        $this->catalog = $catalog;
        $this->maxDimensions = max(1, (int) ($options['max_dimensions'] ?? Config::int('semantic.max_dimensions', 14)));
        $this->maxMetrics = max(1, (int) ($options['max_metrics'] ?? 12));
        $autoDerive = (bool) ($options['auto_derive'] ?? Config::bool('semantic.auto_derive', true));

        foreach ($this->packs as $pack) {
            $this->ingestPack($pack);
        }
        if ($autoDerive) {
            $this->deriveFromCatalog((int) ($options['max_datasets'] ?? Config::int('semantic.max_datasets', 80)));
        }
        $this->applyRelations(
            (array) ($options['relations'] ?? []),
            array_values(array_filter(array_map('strval', (array) ($options['allowed_tables'] ?? [])))),
        );
    }

    /**
     * Build from configuration, optionally deriving from a live schema catalog.
     *
     * @param array<string,mixed> $options
     */
    public static function load(?SchemaService $schema = null, array $options = []): self
    {
        $packs = self::configuredPacks($options['packs'] ?? null);
        $catalog = $options['catalog'] ?? null;
        if (!is_array($catalog)) {
            $catalog = $schema !== null ? $schema->catalog(false) : [];
        }
        return new self($packs, $catalog, $options + ['auto_derive' => (bool) ($options['auto_derive'] ?? Config::bool('semantic.auto_derive', true))]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function configuredPacks(mixed $override = null): array
    {
        if ($override === null) {
            $inline = Config::get('semantic.packs', []);
            $packs = is_array($inline) ? array_values(array_filter($inline, 'is_array')) : [];
        } else {
            $packs = is_array($override) ? array_values(array_filter($override, 'is_array')) : [];
        }

        $file = Config::string('semantic.pack_file', '');
        if ($file !== '' && is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                // A file may hold one pack, a list of packs, or the whole config.
                $decoded = $decoded['semantic']['packs'] ?? $decoded;
                $packs = array_merge($packs, array_values(array_filter((array) $decoded, 'is_array')));
            }
        }
        return $packs;
    }

    /** @return array<string,array<string,mixed>> normalised name => dataset */
    public function datasets(): array
    {
        return $this->datasets;
    }

    /** @return array<int,array<string,mixed>> */
    public function listDatasets(): array
    {
        return array_values($this->datasets);
    }

    /**
     * The reports this CRM already has.
     *
     * @return array<int,array<string,mixed>>
     */
    public function reports(): array
    {
        return $this->reports;
    }

    /** @return array<int,string> dataset names, for capability panels and error text */
    public function datasetNames(): array
    {
        return array_map('strval', array_keys($this->datasets));
    }

    public function hasDatasets(): bool
    {
        return $this->datasets !== [];
    }

    /**
     * The user's word may be a synonym a host declared ("projects" for the
     * listings dataset) or the human label, so both resolve to the same entry.
     *
     * @return array<string,mixed>|null
     */
    public function dataset(string $name): ?array
    {
        $key = self::key($name);
        if (isset($this->datasets[$key])) {
            return $this->datasets[$key];
        }
        foreach ($this->datasets as $dataset) {
            if (self::key((string) ($dataset['label'] ?? '')) === $key) {
                return $dataset;
            }
            foreach ((array) ($dataset['aliases'] ?? []) as $alias) {
                if (self::key((string) $alias) === $key) {
                    return $dataset;
                }
            }
        }

        return null;
    }

    public function tableOf(string $name): string
    {
        $dataset = $this->dataset($name);
        return (string) ($dataset['table'] ?? '');
    }

    /** @return array<string,mixed>|null */
    public function metric(string $dataset, string $name): ?array
    {
        $entry = $this->dataset($dataset);
        if ($entry === null) {
            return null;
        }
        $wanted = self::key($name);
        foreach ((array) $entry['metrics'] as $metric) {
            if (self::key((string) $metric['name']) === $wanted || self::key((string) ($metric['label'] ?? '')) === $wanted) {
                return $metric;
            }
            foreach ((array) ($metric['aliases'] ?? []) as $alias) {
                if (self::key((string) $alias) === $wanted) {
                    return $metric;
                }
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public function dimension(string $dataset, string $name): ?array
    {
        $entry = $this->dataset($dataset);
        if ($entry === null) {
            return null;
        }
        $wanted = self::key($name);
        foreach (array_merge((array) $entry['dimensions'], (array) ($entry['periods'] ?? [])) as $dimension) {
            if (!is_array($dimension)) {
                continue;
            }
            if (self::key((string) $dimension['name']) === $wanted || self::key((string) ($dimension['label'] ?? '')) === $wanted) {
                return $dimension;
            }
            foreach ((array) ($dimension['aliases'] ?? []) as $alias) {
                if (self::key((string) $alias) === $wanted) {
                    return $dimension;
                }
            }
        }
        return null;
    }

    /** Every metric name of a dataset, for error messages. @return string[] */
    public function metricNames(string $dataset): array
    {
        $entry = $this->dataset($dataset);
        if ($entry === null) {
            return [];
        }
        return array_values(array_map(static fn (array $m): string => (string) $m['name'], (array) ($entry['metrics'] ?? [])));
    }

    /** Every dimension name of a dataset. @return string[] */
    public function dimensionNames(string $dataset): array
    {
        $entry = $this->dataset($dataset);
        if ($entry === null) {
            return [];
        }
        $names = array_map(static fn (array $d): string => (string) $d['name'], (array) ($entry['dimensions'] ?? []));
        foreach ((array) ($entry['periods'] ?? []) as $period) {
            if (is_array($period)) {
                $names[] = (string) ($period['name'] ?? '');
            }
        }
        return array_values(array_filter($names));
    }

    /** True when a dimension holds a raw key ("user_id") instead of a value a person reads. */
    public static function isIdDimension(array $dimension): bool
    {
        if (SchemaHints::isReferenceColumn((string) ($dimension['name'] ?? ''))) {
            return true;
        }
        if (SchemaHints::isReferenceColumn((string) ($dimension['column'] ?? ''))) {
            return true;
        }
        return preg_match('/\bids?\b/i', (string) ($dimension['label'] ?? '')) === 1;
    }

    /**
     * The display column that belongs to a key column: "officer" resolves to
     * "officer_name" when the dataset declares one. Null when the dataset
     * carries the key and nothing else.
     */
    public function displaySibling(string $dataset, string $name): ?string
    {
        $entry = $this->dataset($dataset);
        if ($entry === null) {
            return null;
        }
        $base = (string) preg_replace('/_(id|uuid)$/i', '', $name);
        foreach ([$base . '_name', $base . '_label', $base . '_title', $name . '_name'] as $candidate) {
            foreach ((array) ($entry['dimensions'] ?? []) as $dimension) {
                if (!is_array($dimension)) {
                    continue;
                }
                if (self::key((string) ($dimension['name'] ?? '')) !== self::key($candidate)) {
                    continue;
                }
                if (self::isIdDimension($dimension)) {
                    continue;
                }
                return (string) $dimension['name'];
            }
        }
        return null;
    }

    /** @return array<string,array<string,mixed>> */
    public function entities(): array
    {
        return $this->entities;
    }

    /** @return array<string,mixed>|null */
    public function entity(string $name): ?array
    {
        return $this->entities[self::key($name)] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public function actions(): array
    {
        return $this->actions;
    }

    /** @return array<string,mixed>|null */
    public function action(string $name): ?array
    {
        return $this->actions[self::key($name)] ?? null;
    }

    /**
     * The block a model sees before it chooses a query plan: names only, never
     * free-form column dumps. Kept short on purpose — a plan that invents a
     * metric is rejected by QueryCompiler, so precision here beats coverage.
     */
    public function vocabulary(int $maxChars = 12000, int $maxDatasets = 80): string
    {
        $budget = max(120, $maxChars);
        $datasets = $this->listDatasets();
        $detailed = array_slice($datasets, 0, max(0, $maxDatasets));
        $rest = array_slice($datasets, count($detailed));

        // With many datasets, hold back space for the name-only index so no
        // table disappears from the prompt just because the first ones are wordy.
        $reserve = count($datasets) > 12 ? min(7000, max(600, (int) ($budget * 0.4))) : 0;
        $detailBudget = max(120, $budget - $reserve);

        $lines = [];
        $used = 0;
        foreach ($detailed as $dataset) {
            $line = $this->vocabularyLine($dataset);
            if ($used + strlen($line) + 1 > $detailBudget) {
                $rest[] = $dataset;
                continue;
            }
            $lines[] = $line;
            $used += strlen($line) + 1;
        }
        $compactBudget = $reserve > 0 ? $reserve : $budget - $used;
        $lines = array_merge($lines, $this->compactVocabularyLines($rest, $compactBudget));

        $out = [];
        if ($lines !== []) {
            $out[] = 'Available datasets (use these names exactly):';
            $out = array_merge($out, $lines);
        }
        if ($this->entities !== []) {
            // Curated records only: one line per auto-derived table would dwarf
            // the dataset list without helping a lookup question.
            $curated = array_values(array_filter(
                $this->entities,
                static fn (array $entity): bool => (string) ($entity['source'] ?? '') === 'pack'
            ));
            $entities = $curated !== [] ? $curated : array_slice(array_values($this->entities), 0, 12);
            $names = [];
            foreach ($entities as $entity) {
                $names[] = $entity['name'] . '[' . implode(', ', array_slice((array) $entity['fields'], 0, 8)) . ']';
            }
            $line = 'Records you can look up: ' . implode('; ', $names);
            $out[] = strlen($line) > 1600 ? rtrim(substr($line, 0, 1599)) . '…' : $line;
        }
        if ($this->actions !== []) {
            $names = [];
            foreach ($this->actions as $action) {
                $names[] = $action['name'] . '("' . $action['label'] . '", fields: ' . implode('/', array_keys((array) $action['fields'])) . ')';
            }
            $out[] = 'Actions (always confirmed by the user first): ' . implode('; ', $names);
        }
        return implode("\n", $out);
    }

    /** @param array<string,mixed> $dataset */
    private function vocabularyLine(array $dataset): string
    {
        $metrics = [];
        foreach ((array) $dataset['metrics'] as $metric) {
            $name = (string) ($metric['name'] ?? '');
            $aliases = array_values(array_filter(
                array_map('strval', (array) ($metric['aliases'] ?? [])),
                static fn (string $alias): bool => $alias !== '' && self::key($alias) !== self::key($name)
            ));
            // A measure is often called something else in a question ("quiet
            // days", "collections"); the alias is what lets the router find it.
            $metrics[] = $aliases === []
                ? $name
                : $name . '(' . implode('|', array_slice($aliases, 0, 2)) . ')';
        }
        $dimensions = [];
        foreach ((array) $dataset['dimensions'] as $dimension) {
            $name = (string) $dimension['name'];
            $aliases = array_values(array_filter(
                array_map('strval', (array) ($dimension['aliases'] ?? [])),
                static fn (string $alias): bool => $alias !== '' && self::key($alias) !== self::key($name)
            ));
            // The label of a key column ("Staff id") and of the column that
            // carries the name ("Sales person") tells the router which one to
            // pick; without it the model reaches for the key.
            if (self::wantsLabel($dimension)) {
                array_unshift($aliases, (string) ($dimension['label'] ?? ''));
            }
            // Showing a couple of aliases lets the router map a user's word
            // ("amount", "date") to the column that actually holds it.
            $dimensions[] = $aliases === []
                ? $name
                : $name . '(' . implode('|', array_slice($aliases, 0, 3)) . ')';
        }
        $line = $dataset['name'];
        $label = (string) ($dataset['label'] ?? '');
        if ($label !== '' && strcasecmp($label, (string) $dataset['name']) !== 0) {
            $line .= ' (' . $label . ')';
        }
        $line .= ': metrics[' . implode(', ', $metrics) . ']';
        if ($dimensions !== []) {
            $line .= ' dimensions[' . implode(', ', $dimensions) . ']';
        }
        $periods = [];
        foreach ((array) ($dataset['periods'] ?? []) as $period) {
            if (is_array($period)) {
                $periods[] = (string) ($period['name'] ?? '');
            }
        }
        if ($periods !== []) {
            // The dataset's own date, broken into parts. A question about a
            // period groups by these, never by the raw date column.
            $line .= ' periods[' . implode('|', $periods) . ']';
        }
        if ((string) $dataset['date_field'] !== '') {
            $line .= ' date[' . (string) $dataset['date_field'] . ']';
        }
        $aliases = self::displayAliases($dataset);
        if ($aliases !== []) {
            $line .= ' also[' . implode(', ', $aliases) . ']';
        }
        $description = trim(preg_replace('/\s+/', ' ', (string) ($dataset['description'] ?? '')) ?? '');
        if ($description !== '') {
            // One paragraph is enough to route; the full text lives in the UI.
            if (strlen($description) > 220) {
                $description = rtrim(substr($description, 0, 219)) . '…';
            }
            $line .= ' — ' . $description;
        }
        return $line;
    }

    /**
     * A label earns a slot in the vocabulary when it carries the difference
     * between a key and the value that replaces it.
     *
     * @param array<string,mixed> $dimension
     */
    private static function wantsLabel(array $dimension): bool
    {
        $name = (string) ($dimension['name'] ?? '');
        $label = (string) ($dimension['label'] ?? '');
        if ($label === '' || strcasecmp($label, $name) === 0) {
            return false;
        }
        if (self::isIdDimension($dimension)) {
            return true;
        }
        return preg_match('/_(name|label|title)$/i', $name) === 1;
    }

    /**
     * Datasets that did not fit the detailed budget, kept as name(aliases) so a
     * question about them still reaches the right dataset.
     *
     * @param array<int,array<string,mixed>> $datasets
     * @return array<int,string>
     */
    private function compactVocabularyLines(array $datasets, int $budget): array
    {
        $entries = [];
        foreach ($datasets as $dataset) {
            $entry = (string) $dataset['name'];
            $aliases = self::displayAliases($dataset);
            if ($aliases !== []) {
                $entry .= '(' . implode(', ', array_slice($aliases, 0, 6)) . ')';
            }
            $entries[] = $entry;
        }
        if ($entries === []) {
            return [];
        }
        $joined = 'Also available: ' . implode(', ', $entries);
        if ($budget <= 0) {
            return [];
        }
        if (strlen($joined) > $budget) {
            $joined = rtrim(substr($joined, 0, max(0, $budget - 1))) . '…';
        }
        $lines = [];
        while ($joined !== '') {
            if (strlen($joined) <= 180) {
                $lines[] = $joined;
                break;
            }
            $cut = strrpos(substr($joined, 0, 180), ' ');
            if ($cut === false || $cut < 60) {
                $cut = 180;
            }
            $lines[] = rtrim(substr($joined, 0, $cut), " ,") . ',';
            $joined = ltrim(substr($joined, $cut), " ,");
        }
        return $lines;
    }

    /** @param array<string,mixed> $dataset @return array<int,string> */
    private static function displayAliases(array $dataset): array
    {
        return array_slice(array_values(array_filter(array_map('strval', (array) ($dataset['aliases'] ?? [])))), 0, 12);
    }

    /** @return array<string,mixed> JSON-safe description for the admin UI */
    public function describe(): array
    {
        return [
            'datasets' => $this->listDatasets(),
            'entities' => array_values($this->entities),
            'actions' => array_values($this->actions),
            'relations' => array_values($this->relations),
            'packs' => count($this->packs),
        ];
    }

    /** @param array<string,mixed> $pack */
    private function ingestPack(array $pack): void
    {
        $packName = self::key((string) ($pack['name'] ?? 'pack'));
        foreach ((array) ($pack['datasets'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $dataset = $this->normaliseDataset($raw, $packName);
            if ($dataset !== null) {
                $this->datasets[$dataset['key']] = $dataset;
                $this->packTables[self::key((string) $dataset['table'])] = true;
            }
        }
        foreach ((array) ($pack['entities'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $entity = $this->normaliseEntity($raw);
            if ($entity !== null) {
                $this->entities[$entity['key']] = $entity;
            }
        }
        foreach ((array) ($pack['actions'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $action = $this->normaliseAction($raw);
            if ($action !== null) {
                $this->actions[$action['key']] = $action;
            }
        }
        foreach ((array) ($pack['reports'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $report = $this->normaliseReport($raw);
            if ($report !== null) {
                $this->reports[] = $report;
            }
        }
    }

    /**
     * A report the CRM already has.
     *
     * A CRM that ships twenty report screens wants the assistant to answer
     * "the collections report" with the same numbers the screen shows. The
     * pack names the screen, the question it answers and the dataset behind
     * it; the assistant then routes to that dataset instead of guessing.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    private function normaliseReport(array $raw): ?array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'label' => trim((string) ($raw['label'] ?? $name)),
            'dataset' => trim((string) ($raw['dataset'] ?? '')),
            'module' => trim((string) ($raw['module'] ?? '')),
            'question' => trim((string) ($raw['question'] ?? '')),
            'description' => trim((string) ($raw['description'] ?? '')),
            'screen' => trim((string) ($raw['screen'] ?? '')),
            'aliases' => array_values(array_map('strval', (array) ($raw['aliases'] ?? []))),
            // The shape of the screen, when it has one: the dataset it reads
            // and the columns it shows. A screen with a fixed shape is answered
            // with that shape, not with a query rebuilt from the question.
            'plan' => (array) ($raw['plan'] ?? []),
        ];
    }

    /**
     * The periods a dataset's own date can be broken into.
     *
     * "Payment totals by month" is a question about time, not about a column:
     * every dataset that keeps a date gets year, quarter, month, week and day
     * for free, so the answer can group by a period instead of listing rows.
     *
     * A column that stores text dates ("25-08-2026") is read with the format
     * the dataset knows, because MySQL cannot read it any other way.
     *
     * @param array<string,mixed> $dataset
     * @return array<int,array<string,mixed>>
     */
    private function periodsFor(array $dataset): array
    {
        $field = trim((string) ($dataset['date_field'] ?? ''));
        if ($field === '') {
            return [];
        }
        $table = trim((string) ($dataset['table'] ?? ''));
        $format = trim((string) ($dataset['date_format'] ?? ''));
        $column = $table === '' ? $field : '`' . $table . '`.`' . $field . '`';
        $base = $format === '' ? $column : "STR_TO_DATE(" . $column . ", '" . str_replace("'", "''", $format) . "')";

        $shapes = [
            'year' => ['Year', 'DATE_FORMAT(' . $base . ", '%Y')", ['year', 'yr', 'annually', 'the year']],
            'quarter' => ['Quarter', "CONCAT(DATE_FORMAT(" . $base . ", '%Y'), '-Q', QUARTER(" . $base . '))', ['quarter', 'quarterly', 'the quarter']],
            'month' => ['Month', "DATE_FORMAT(" . $base . ", '%Y-%m')", ['month', 'monthly', 'the month']],
            'week' => ['Week', "DATE_FORMAT(" . $base . ", '%x-W%v')", ['week', 'weekly', 'the week']],
            'day' => ['Day', "DATE_FORMAT(" . $base . ", '%Y-%m-%d')", ['day', 'daily', 'the day']],
        ];

        $existing = [];
        foreach ((array) ($dataset['dimensions'] ?? []) as $dimension) {
            if (is_array($dimension)) {
                $existing[] = self::key((string) ($dimension['name'] ?? ''));
            }
        }

        $periods = [];
        foreach ($shapes as $name => [$label, $expr, $aliases]) {
            if (in_array(self::key($name), $existing, true)) {
                // A pack that declares its own month keeps its own month.
                continue;
            }
            $periods[] = [
                'name' => $name,
                'label' => $label,
                'column' => $field,
                'expr' => $expr,
                'format' => 'text',
                'aliases' => $aliases,
                'description' => 'The ' . strtolower($label) . ' of ' . $field . '.',
            ];
        }

        return $periods;
    }

    /**
     * One catalog table becomes one dataset: measures sum, descriptive columns
     * slice, the first date column windows the question.
     */
    private function deriveFromCatalog(int $maxDatasets): void
    {
        $added = 0;
        foreach ($this->catalog as $tableName => $entry) {
            if (
                isset($this->datasets[self::key((string) $tableName)])
                || isset($this->packTables[self::key((string) $tableName)])
                || $added >= $maxDatasets
            ) {
                continue;
            }
            $columns = (array) ($entry['columns'] ?? []);
            if ($columns === []) {
                continue;
            }
            $metrics = [['name' => 'records', 'label' => 'Records', 'function' => 'count', 'column' => '*', 'format' => 'number', 'aliases' => ['count', 'total count']]];
            $dimensions = [];
            $dateField = '';
            $dateFormat = '';
            foreach ($columns as $column) {
                $name = (string) ($column['name'] ?? '');
                // Secrets never enter the vocabulary; PII stays usable because the
                // policy masks it in every result set.
                if ($name === '' || (string) ($column['sensitivity'] ?? '') === 'secret') {
                    continue;
                }
                $format = (string) ($column['format'] ?? SchemaHints::format($name, (string) ($column['type'] ?? '')));
                if ($dateField === '' && in_array($format, ['date', 'datetime'], true)) {
                    $dateField = $name;
                    $dateFormat = (string) ($column['date_format'] ?? '');
                }
                if (!empty($column['is_measure'])) {
                    if (count($metrics) < $this->maxMetrics) {
                        $metrics[] = [
                            'name' => $name,
                            'label' => (string) ($column['label'] ?? SchemaHints::label($name)),
                            'function' => $format === 'money' ? 'sum' : 'avg',
                            'column' => $name,
                            'format' => $format,
                            'aliases' => array_values(array_map([self::class, 'key'], (array) ($column['aliases'] ?? []))),
                            'description' => (string) ($column['description'] ?? ''),
                        ];
                    }
                    continue;
                }
                if ($format === 'json' || count($dimensions) >= $this->maxDimensions) {
                    continue;
                }
                $dimensions[] = [
                    'name' => $name,
                    'label' => (string) ($column['label'] ?? SchemaHints::label($name)),
                    'column' => $name,
                    'expr' => '',
                    'format' => $format,
                    'aliases' => array_values(array_map([self::class, 'key'], (array) ($column['aliases'] ?? []))),
                    'description' => (string) ($column['description'] ?? ''),
                ];
            }
            if ($dateField === '') {
                foreach (['created_at', 'date', 'issued_at'] as $candidate) {
                    foreach ($columns as $column) {
                        if (strcasecmp((string) ($column['name'] ?? ''), $candidate) === 0) {
                            $dateField = $candidate;
                            $dateFormat = (string) ($column['date_format'] ?? '');
                            break 2;
                        }
                    }
                }
            }
            $dataset = [
                'key' => self::key((string) $tableName),
                'name' => (string) $tableName,
                'pack' => 'auto',
                'table' => (string) $tableName,
                'label' => (string) ($entry['label'] ?? SchemaHints::label((string) $tableName)),
                'description' => (string) ($entry['description'] ?? ''),
                'date_field' => $dateField,
                'date_format' => $dateFormat,
                'metrics' => $metrics,
                'dimensions' => $dimensions,
                'joins' => [],
                'relations' => [],
                'tenant_scoped' => true,
                'default_order' => '',
                'source' => 'auto',
            ];
            $dataset['periods'] = $this->periodsFor($dataset);
            $this->datasets[self::key((string) $tableName)] = $dataset;
            $this->entities[self::key((string) $tableName)] = [
                'key' => self::key((string) $tableName),
                'name' => (string) $tableName,
                'table' => (string) $tableName,
                'label' => (string) ($entry['label'] ?? SchemaHints::label((string) $tableName)),
                'title_column' => self::firstColumn($columns, ['name', 'title', 'label', 'full_name', 'reference']),
                'fields' => array_values(array_map(static fn (array $c): string => (string) ($c['name'] ?? ''), array_slice($columns, 0, 12))),
                'search_columns' => array_values(array_filter(
                    array_map(static fn (array $c): string => (string) ($c['name'] ?? ''), $columns),
                    static fn (string $c): bool => in_array($c, ['name', 'title', 'reference', 'email', 'phone'], true)
                )),
                'source' => 'auto',
            ];
            $added++;
        }
    }

    /**
     * Approved relations turn key columns into readable names: a dataset that
     * carries `staff_id` gains a `staff_name` sibling, joined from the table
     * the key points at.
     *
     * A curated pack always wins: a dataset that already declares the name
     * keeps its own column and expression.
     *
     * @param array<int,array<string,mixed>> $relations
     * @param string[]                       $allowedTables tables the host allows us to read
     */
    private function applyRelations(array $relations, array $allowedTables): void
    {
        if ($relations === []) {
            return;
        }
        $allowed = array_map(static fn (string $t): string => strtolower(trim($t)), $allowedTables);
        $ordered = array_values(array_filter($relations, 'is_array'));
        usort($ordered, static fn (array $a, array $b): int => (float) ($b['confidence'] ?? 0) <=> (float) ($a['confidence'] ?? 0));

        $added = 0;
        foreach ($ordered as $relation) {
            $sourceTable = trim((string) ($relation['source_table'] ?? ''));
            $sourceColumn = trim((string) ($relation['source_column'] ?? ''));
            $targetTable = trim((string) ($relation['target_table'] ?? ''));
            $targetColumn = trim((string) ($relation['target_column'] ?? 'id')) ?: 'id';
            $labelColumn = trim((string) ($relation['label_column'] ?? ''));
            if ($sourceTable === '' || $sourceColumn === '' || $targetTable === '' || $labelColumn === '') {
                continue;
            }
            if ($allowed !== [] && !in_array(strtolower($targetTable), $allowed, true)) {
                continue;
            }
            $base = SchemaHints::referenceBase($sourceColumn);
            if ($base === '') {
                continue;
            }
            $dataset = $this->datasetForTable($sourceTable);
            if ($dataset === null) {
                continue;
            }
            $key = $dataset['key'];
            if (count((array) ($dataset['relations'] ?? [])) >= 6) {
                continue;
            }

            $name = $base . '_name';
            if ($this->hasDimension($dataset, $name)) {
                continue;
            }
            $alias = $this->joinAliasFor($dataset, $targetTable);
            $prefix = $alias ?? $targetTable;
            if ($this->hasDimensionColumn($dataset, $prefix . '.' . $labelColumn)) {
                continue;
            }

            if ($alias === null) {
                $dataset['joins'][] = [
                    'table' => $targetTable,
                    'alias' => $targetTable,
                    'on' => [$dataset['table'] . '.' . $sourceColumn => $targetTable . '.' . $targetColumn],
                    'type' => 'left',
                ];
            }

            $dataset['dimensions'][] = [
                'name' => $name,
                'label' => $this->relationLabel($targetTable) . ' name',
                'column' => $prefix . '.' . $labelColumn,
                'expr' => '',
                'format' => 'text',
                'via' => $prefix,
                'aliases' => [],
                'description' => 'The name behind ' . $dataset['table'] . '.' . $sourceColumn . '.',
            ];
            $dataset['relations'][] = [
                'name' => $name,
                'source' => $dataset['table'] . '.' . $sourceColumn,
                'target' => $targetTable . '.' . $labelColumn,
                'method' => (string) ($relation['method'] ?? ''),
                'confidence' => (float) ($relation['confidence'] ?? 0),
            ];
            $this->relations[$dataset['table'] . '.' . $sourceColumn] = $relation;
            $this->datasets[$key] = $dataset;
            $added++;
        }
    }

    /** The dataset that reads from a table, if there is one. @return array<string,mixed>|null */
    private function datasetForTable(string $table): ?array
    {
        $wanted = strtolower(trim($table));
        foreach ($this->datasets as $dataset) {
            if (strtolower((string) ($dataset['table'] ?? '')) === $wanted) {
                return $dataset;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $dataset */
    private function hasDimension(array $dataset, string $name): bool
    {
        foreach ((array) ($dataset['dimensions'] ?? []) as $dimension) {
            if (is_array($dimension) && self::key((string) ($dimension['name'] ?? '')) === self::key($name)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $dataset */
    private function hasDimensionColumn(array $dataset, string $column): bool
    {
        foreach ((array) ($dataset['dimensions'] ?? []) as $dimension) {
            if (is_array($dimension) && strcasecmp((string) ($dimension['column'] ?? ''), $column) === 0) {
                return true;
            }
        }
        return false;
    }

    /** The alias a dataset already joins the target table under, if it does. */
    private function joinAliasFor(array $dataset, string $table): ?string
    {
        $wanted = self::key($table);
        foreach ((array) ($dataset['joins'] ?? []) as $join) {
            if (!is_array($join)) {
                continue;
            }
            if (self::key((string) ($join['table'] ?? '')) === $wanted) {
                return (string) ($join['alias'] ?? $join['table']);
            }
        }
        return null;
    }

    /** The readable name of the table a key points at. */
    private function relationLabel(string $table): string
    {
        foreach ($this->catalog as $name => $entry) {
            if (strcasecmp((string) $name, $table) === 0) {
                $label = trim((string) ($entry['label'] ?? ''));
                if ($label !== '') {
                    return $label;
                }
            }
        }
        return SchemaHints::label($table);
    }

    /**
     * The relations a dataset leans on, for the admin and the answer's audit
     * trail: every name in an answer is traceable to the relation that made it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function relationNotes(string $dataset): array
    {
        $entry = $this->dataset($dataset);
        if ($entry === null) {
            return [];
        }
        return array_values((array) ($entry['relations'] ?? []));
    }

    /** @param array<string,mixed> $raw @return array<string,mixed>|null */
    private function normaliseDataset(array $raw, string $packName): ?array
    {
        $name = trim((string) ($raw['name'] ?? $raw['table'] ?? ''));
        $table = trim((string) ($raw['table'] ?? $name));
        if ($name === '' || $table === '') {
            return null;
        }
        $metrics = [];
        foreach ((array) ($raw['metrics'] ?? []) as $metric) {
            $normalised = $this->normaliseMetric(is_array($metric) ? $metric : ['name' => $metric]);
            if ($normalised !== null) {
                $metrics[] = $normalised;
            }
        }
        $dimensions = [];
        foreach ((array) ($raw['dimensions'] ?? []) as $dimension) {
            $normalised = $this->normaliseDimension(is_array($dimension) ? $dimension : ['name' => $dimension]);
            if ($normalised !== null) {
                $dimensions[] = $normalised;
            }
        }
        if ($metrics === []) {
            $metrics[] = ['name' => 'records', 'label' => 'Records', 'function' => 'count', 'column' => '*', 'format' => 'number', 'aliases' => ['records'], 'description' => ''];
        }

        $dataset = [
            'key' => self::key($name),
            'name' => $name,
            'pack' => $packName,
            'table' => $table,
            'label' => trim((string) ($raw['label'] ?? SchemaHints::label($name))),
            'description' => trim((string) ($raw['description'] ?? '')),
            'aliases' => array_values(array_filter(array_map(
                static fn ($alias): string => trim((string) $alias),
                (array) ($raw['aliases'] ?? $raw['synonyms'] ?? [])
            ), static fn (string $alias): bool => $alias !== '')),
            'date_field' => trim((string) ($raw['date_field'] ?? $raw['date'] ?? '')),
            // Legacy hosts often keep dates as strings ("01-04-2025"). The compiler
            // wraps the date field in STR_TO_DATE with this format so period filters
            // and default ordering compare chronologically.
            'date_format' => trim((string) ($raw['date_format'] ?? '')),
            'metrics' => $metrics,
            'dimensions' => $dimensions,
            'joins' => array_values(array_filter((array) ($raw['joins'] ?? []), 'is_array')),
            'relations' => [],
            'tenant_scoped' => (bool) ($raw['tenant_scoped'] ?? true),
            'default_order' => trim((string) ($raw['default_order'] ?? '')),
            'source' => 'pack',
        ];
        $dataset['periods'] = $this->periodsFor($dataset);

        return $dataset;
    }

    /** @param array<string,mixed> $raw @return array<string,mixed>|null */
    private function normaliseMetric(array $raw): ?array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $function = strtolower(trim((string) ($raw['function'] ?? $raw['agg'] ?? 'sum')));
        if (!in_array($function, self::AGGREGATES, true)) {
            $function = ($raw['expr'] ?? null) !== null && $raw['expr'] !== '' ? 'expr' : 'sum';
        }
        $column = trim((string) ($raw['column'] ?? ($function === 'expr' ? '' : $name)));
        if ($function !== 'expr' && $column === '') {
            $column = $name;
        }
        return [
            'name' => $name,
            'label' => trim((string) ($raw['label'] ?? SchemaHints::label($name))),
            'function' => $function,
            'column' => $column,
            'expr' => trim((string) ($raw['expr'] ?? '')),
            'format' => strtolower(trim((string) ($raw['format'] ?? 'number'))),
            'aliases' => self::aliasList($raw['aliases'] ?? null, $name),
            'description' => trim((string) ($raw['description'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $raw @return array<string,mixed>|null */
    private function normaliseDimension(array $raw): ?array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        return [
            'name' => $name,
            'label' => trim((string) ($raw['label'] ?? SchemaHints::label($name))),
            'column' => trim((string) ($raw['column'] ?? (isset($raw['expr']) && $raw['expr'] !== '' ? '' : $name))),
            'expr' => trim((string) ($raw['expr'] ?? '')),
            'format' => strtolower(trim((string) ($raw['format'] ?? 'text'))),
            'via' => self::viaList($raw['via'] ?? ''),
            'aliases' => self::aliasList($raw['aliases'] ?? null, $name),
            'description' => trim((string) ($raw['description'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $raw @return array<string,mixed>|null */
    private function normaliseEntity(array $raw): ?array
    {
        $name = trim((string) ($raw['name'] ?? $raw['table'] ?? ''));
        $table = trim((string) ($raw['table'] ?? $name));
        if ($name === '' || $table === '') {
            return null;
        }
        $fields = array_values(array_filter(array_map('strval', (array) ($raw['fields'] ?? [])), fn (string $f) => $f !== ''));
        return [
            'key' => self::key($name),
            'name' => $name,
            'table' => $table,
            'label' => trim((string) ($raw['label'] ?? SchemaHints::label($name))),
            'title_column' => trim((string) ($raw['title_column'] ?? '')),
            'fields' => $fields,
            'search_columns' => array_values(array_filter(array_map('strval', (array) ($raw['search_columns'] ?? [])), fn (string $f) => $f !== '')),
            'source' => 'pack',
        ];
    }

    /** @param array<string,mixed> $raw @return array<string,mixed>|null */
    private function normaliseAction(array $raw): ?array
    {
        $name = self::key((string) ($raw['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $fields = [];
        foreach ((array) ($raw['fields'] ?? []) as $key => $field) {
            if (is_string($key) && is_array($field)) {
                $fields[$key] = $field + ['label' => SchemaHints::label($key), 'required' => false];
            } elseif (is_string($field)) {
                $fields[$field] = ['label' => SchemaHints::label($field), 'required' => false];
            }
        }
        return [
            'key' => $name,
            'name' => (string) ($raw['name'] ?? $name),
            'label' => trim((string) ($raw['label'] ?? SchemaHints::label($name))),
            'handler' => trim((string) ($raw['handler'] ?? $name)),
            'description' => trim((string) ($raw['description'] ?? '')),
            'fields' => $fields,
            'roles' => array_values(array_filter(array_map('strval', (array) ($raw['roles'] ?? [])))),
            'entity' => trim((string) ($raw['entity'] ?? '')),
            'source' => 'pack',
        ];
    }

    /**
     * The words a field answers to, as they were written.
     *
     * The text is kept: a model's "quiet days" has to match the pack's
     * "quiet_days", and a prompt that shows "quietdays" reads as gibberish.
     * Lookups normalise both sides, so the wording stays free.
     *
     * @param mixed $aliases @return string[]
     */
    private static function aliasList(mixed $aliases, string $fallback): array
    {
        $out = [];
        $seen = [];
        foreach (array_merge([$fallback], (array) $aliases) as $alias) {
            if (!is_string($alias)) {
                continue;
            }
            $clean = trim($alias);
            $key = self::key($clean);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $clean;
        }
        return $out;
    }

    /**
     * The tables a field is reached through. A list is a chain: a client
     * behind a payment record needs both joins, in order.
     *
     * @return string[]
     */
    private static function viaList(mixed $via): array
    {
        $out = [];
        foreach ((array) $via as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return array_values(array_unique($out));
    }

    /** @param array<int,array<string,mixed>> $columns @return string */
    private static function firstColumn(array $columns, array $preferred): string
    {
        foreach ($preferred as $name) {
            foreach ($columns as $column) {
                if (strcasecmp((string) ($column['name'] ?? ''), $name) === 0) {
                    return $name;
                }
            }
        }
        return '';
    }

    /** Normalised lookup key: a model's "Total Revenue" matches the pack's "total_revenue". */
    public static function key(string $name): string
    {
        $clean = strtolower(trim($name));
        $clean = (string) preg_replace('/[^a-z0-9]+/', '', $clean);
        return $clean === '' ? '' : $clean;
    }
}
