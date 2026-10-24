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
    /** @var array<int,array<string,mixed>> */
    private array $packs;
    /** @var array<string,array<string,mixed>> */
    private array $catalog;
    private int $maxDimensions;
    private int $maxMetrics;

    private const AGGREGATES = ['sum', 'avg', 'min', 'max', 'count', 'count_distinct'];

    /**
     * @param array<int,array<string,mixed>> $packs   host-authored packs
     * @param array<string,array<string,mixed>> $catalog SchemaService::catalog() output
     * @param array<string,mixed> $options auto_derive, max_dimensions, max_datasets
     */
    public function __construct(array $packs = [], array $catalog = [], array $options = [])
    {
        $this->packs = array_values(array_filter($packs, 'is_array'));
        $this->catalog = $catalog;
        $this->maxDimensions = max(1, (int) ($options['max_dimensions'] ?? 14));
        $this->maxMetrics = max(1, (int) ($options['max_metrics'] ?? 12));
        $autoDerive = (bool) ($options['auto_derive'] ?? Config::bool('semantic.auto_derive', true));

        foreach ($this->packs as $pack) {
            $this->ingestPack($pack);
        }
        if ($autoDerive) {
            $this->deriveFromCatalog((int) ($options['max_datasets'] ?? 80));
        }
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

    /** @return array<int,string> dataset names, for capability panels and error text */
    public function datasetNames(): array
    {
        return array_map('strval', array_keys($this->datasets));
    }

    public function hasDatasets(): bool
    {
        return $this->datasets !== [];
    }

    /** @return array<string,mixed>|null */
    public function dataset(string $name): ?array
    {
        return $this->datasets[self::key($name)] ?? null;
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
        foreach ((array) $entry['dimensions'] as $dimension) {
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
        return array_values(array_map(static fn (array $m): string => (string) $m['name'], (array) ($entry['metrics'] ?? [])));
    }

    /** Every dimension name of a dataset. @return string[] */
    public function dimensionNames(string $dataset): array
    {
        $entry = $this->dataset($dataset);
        return array_values(array_map(static fn (array $d): string => (string) $d['name'], (array) ($entry['dimensions'] ?? [])));
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
    public function vocabulary(int $maxChars = 5000, int $maxDatasets = 40): string
    {
        $lines = [];
        $used = 0;
        foreach (array_slice($this->listDatasets(), 0, $maxDatasets) as $dataset) {
            $metrics = [];
            foreach ((array) $dataset['metrics'] as $metric) {
                $metrics[] = (string) $metric['name'];
            }
            $dimensions = [];
            foreach ((array) $dataset['dimensions'] as $dimension) {
                $dimensions[] = (string) $dimension['name'];
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
            if ((string) $dataset['date_field'] !== '') {
                $line .= ' date[' . (string) $dataset['date_field'] . ']';
            }
            $description = trim((string) ($dataset['description'] ?? ''));
            if ($description !== '') {
                $line .= ' — ' . $description;
            }
            $used += strlen($line) + 1;
            if ($used > $maxChars) {
                $lines[] = '… (more datasets available; ask for a narrower report)';
                break;
            }
            $lines[] = $line;
        }

        $out = [];
        if ($lines !== []) {
            $out[] = 'Available datasets (use these names exactly):';
            $out = array_merge($out, $lines);
        }
        if ($this->entities !== []) {
            $names = [];
            foreach ($this->entities as $entity) {
                $names[] = $entity['name'] . '[' . implode(', ', array_slice((array) $entity['fields'], 0, 8)) . ']';
            }
            $out[] = 'Records you can look up: ' . implode('; ', $names);
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

    /** @return array<string,mixed> JSON-safe description for the admin UI */
    public function describe(): array
    {
        return [
            'datasets' => $this->listDatasets(),
            'entities' => array_values($this->entities),
            'actions' => array_values($this->actions),
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
    }

    /**
     * One catalog table becomes one dataset: measures sum, descriptive columns
     * slice, the first date column windows the question.
     */
    private function deriveFromCatalog(int $maxDatasets): void
    {
        $added = 0;
        foreach ($this->catalog as $tableName => $entry) {
            if (isset($this->datasets[self::key((string) $tableName)]) || $added >= $maxDatasets) {
                continue;
            }
            $columns = (array) ($entry['columns'] ?? []);
            if ($columns === []) {
                continue;
            }
            $metrics = [['name' => 'records', 'label' => 'Records', 'function' => 'count', 'column' => '*', 'format' => 'number', 'aliases' => ['count', 'total count']]];
            $dimensions = [];
            $dateField = '';
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
                            break 2;
                        }
                    }
                }
            }
            $this->datasets[self::key((string) $tableName)] = [
                'key' => self::key((string) $tableName),
                'name' => (string) $tableName,
                'pack' => 'auto',
                'table' => (string) $tableName,
                'label' => (string) ($entry['label'] ?? SchemaHints::label((string) $tableName)),
                'description' => (string) ($entry['description'] ?? ''),
                'date_field' => $dateField,
                'metrics' => $metrics,
                'dimensions' => $dimensions,
                'joins' => [],
                'tenant_scoped' => true,
                'default_order' => '',
                'source' => 'auto',
            ];
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

        return [
            'key' => self::key($name),
            'name' => $name,
            'pack' => $packName,
            'table' => $table,
            'label' => trim((string) ($raw['label'] ?? SchemaHints::label($name))),
            'description' => trim((string) ($raw['description'] ?? '')),
            'date_field' => trim((string) ($raw['date_field'] ?? $raw['date'] ?? '')),
            'metrics' => $metrics,
            'dimensions' => $dimensions,
            'joins' => array_values(array_filter((array) ($raw['joins'] ?? []), 'is_array')),
            'tenant_scoped' => (bool) ($raw['tenant_scoped'] ?? true),
            'default_order' => trim((string) ($raw['default_order'] ?? '')),
            'source' => 'pack',
        ];
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
            'via' => trim((string) ($raw['via'] ?? '')),
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

    /** @param mixed $aliases @return string[] */
    private static function aliasList(mixed $aliases, string $fallback): array
    {
        $out = [self::key($fallback)];
        foreach ((array) $aliases as $alias) {
            if (is_string($alias) && trim($alias) !== '') {
                $out[] = self::key($alias);
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
