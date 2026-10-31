<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Dto\QueryPlan;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\DomainPack;
use ExcelleInsights\AiAssistant\Support\SchemaHints;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;
use ExcelleInsights\AiAssistant\Support\TextScore;
use PDO;
use Throwable;

/**
 * Installs a CRM: reads the database, reads the code, drafts the semantic
 * layer, writes it down — and then proves the answer works.
 *
 * A hand-written pack takes a week per application and goes stale the day
 * after. This service does the first half of that work by machine: it reads
 * what the database holds, reads the queries the application already runs, and
 * turns both into a named vocabulary — a dataset per table, a metric per
 * measure, a name next to every key that a verified relation explains.
 *
 * What it writes is two JSON files, not PHP:
 *
 *   ai-assistant.packs.json      — the semantic layer
 *   ai-assistant.knowledge.json   — the modules, rules and glossary found
 *
 * The host commits them. The packs file is read back like any other pack
 * (`semantic.pack_file`), the knowledge file like any other knowledge import.
 * A second run re-derives only what changed: the catalog, the code index and
 * the knowledge entries live in the package's own tables, so the run starts
 * from what it already knows.
 *
 * Nothing here is about any particular application. The tables, the modules
 * and the questions all come from the host's own database and code.
 */
final class DiscoveryService
{
    /** The two files a run writes. */
    public const PACK_FILE = 'ai-assistant.packs.json';
    public const KNOWLEDGE_FILE = 'ai-assistant.knowledge.json';

    /** Words that mean "sum the money", not "count the rows". */
    private const MONEY_WORDS = ['total', 'amount', 'revenue', 'sum', 'money', 'value', 'cost', 'price', 'paid', 'collected', 'balance', 'totalvalue'];

    private ?PDO $pdo;
    private SchemaService $schema;
    private RelationService $relations;
    private CodeIndexService $code;
    private KnowledgeService $knowledge;
    private SchemaPolicy $policy;
    /** @var array<string,mixed> */
    private array $options;

    /**
     * @param array<string,mixed> $options output_dir, pack_file, knowledge_file,
     *                                     questions, max_datasets, max_dimensions
     */
    public function __construct(
        ?PDO $pdo,
        SchemaService $schema,
        RelationService $relations,
        CodeIndexService $code,
        KnowledgeService $knowledge,
        ?SchemaPolicy $policy = null,
        array $options = [],
    ) {
        $this->pdo = $pdo;
        $this->schema = $schema;
        $this->relations = $relations;
        $this->code = $code;
        $this->knowledge = $knowledge;
        $this->policy = $policy ?? new SchemaPolicy();
        $this->options = $options;
    }

    // ─── the run ──────────────────────────────────────────────────────────────

    /**
     * Read the CRM, draft its vocabulary, write it down and test it.
     *
     * @param array<string,mixed> $opts prune, code, relations, questions, files
     * @return array<string,mixed>
     */
    public function run(int $companyId, array $opts = []): array
    {
        $report = [
            'company_id' => $companyId,
            'steps' => [],
            'files' => [],
            'selftest' => [],
            'errors' => [],
        ];

        // 1. The database: which tables and columns exist.
        try {
            $report['steps']['schema'] = $this->schema->syncSchema([
                'prune' => (bool) ($opts['prune'] ?? false),
            ]);
        } catch (Throwable $e) {
            $report['steps']['schema'] = ['error' => $e->getMessage()];
            $report['errors'][] = 'The schema could not be read: ' . $e->getMessage();
        }

        // 2. The code: which queries this CRM already runs.
        try {
            $report['steps']['code'] = $this->code->scan($companyId, (array) ($opts['code'] ?? []));
        } catch (Throwable $e) {
            $report['steps']['code'] = ['error' => $e->getMessage()];
            $report['errors'][] = 'The code could not be scanned: ' . $e->getMessage();
        }

        // 3. The relations: what a key column stands for.
        try {
            $report['steps']['relations'] = $this->relations->discover($companyId, (array) ($opts['relations'] ?? []));
        } catch (Throwable $e) {
            $report['steps']['relations'] = ['error' => $e->getMessage()];
            $report['errors'][] = 'The relations could not be read: ' . $e->getMessage();
        }

        // 4. The semantic layer and the knowledge, drafted from what was read.
        $pack = $this->draftPack($companyId);
        $entries = $this->draftKnowledge($companyId);
        $report['steps']['pack'] = [
            'datasets' => count((array) ($pack['datasets'] ?? [])),
            'entities' => count((array) ($pack['entities'] ?? [])),
        ];
        $report['steps']['knowledge'] = ['entries' => count($entries)];

        // 5. The two files.
        $report['files'] = $this->writeFiles($pack, $entries, (array) ($opts['files'] ?? []));

        // 6. The proof: the canonical questions, answered against the draft.
        $report['selftest'] = $this->selfTest($companyId, $pack, (array) ($opts['questions'] ?? []));

        return $report;
    }

    // ─── the drafted semantic layer ───────────────────────────────────────────

    /**
     * The vocabulary this CRM would have if somebody wrote it by hand.
     *
     * One dataset per table, one metric per measure, one dimension per
     * descriptive column, and — for every key a verified relation explains —
     * the name behind it, joined in.
     *
     * @return array<string,mixed>
     */
    public function draftPack(int $companyId): array
    {
        $catalog = $this->schema->catalog(true);
        $relations = $this->relations->approved($companyId);
        $maxDatasets = max(1, (int) ($this->options['max_datasets'] ?? Config::int('semantic.max_datasets', 80)));
        $maxDimensions = max(1, (int) ($this->options['max_dimensions'] ?? Config::int('semantic.max_dimensions', 14)));

        // The relations, grouped by the table that carries the key.
        $bySource = [];
        foreach ($relations as $relation) {
            $source = strtolower(trim((string) ($relation['source_table'] ?? '')));
            if ($source === '') {
                continue;
            }
            $bySource[$source][] = $relation;
        }

        $datasets = [];
        $entities = [];

        foreach ($catalog as $table => $entry) {
            if (count($datasets) >= $maxDatasets) {
                break;
            }
            $tableName = (string) $table;
            $columns = array_values((array) ($entry['columns'] ?? []));
            if ($columns === []) {
                continue;
            }

            $label = trim((string) ($entry['label'] ?? '')) ?: SchemaHints::label($tableName);
            $metrics = [[
                'name' => 'records',
                'label' => 'Records',
                'function' => 'count',
                'column' => '*',
                'format' => 'number',
                'aliases' => ['count', 'total count'],
            ]];
            $dimensions = [];
            $dateField = '';

            foreach ($columns as $column) {
                $name = (string) ($column['name'] ?? '');
                if ($name === '' || (string) ($column['sensitivity'] ?? '') === 'secret') {
                    continue;
                }
                $format = (string) ($column['format'] ?? SchemaHints::format($name, (string) ($column['type'] ?? '')));
                if ($dateField === '' && in_array($format, ['date', 'datetime'], true)) {
                    $dateField = $name;
                }
                if (!empty($column['is_measure'])) {
                    if (count($metrics) < 12) {
                        $metrics[] = [
                            'name' => $name,
                            'label' => (string) ($column['label'] ?? SchemaHints::label($name)),
                            'function' => $format === 'money' ? 'sum' : 'avg',
                            'column' => $name,
                            'format' => $format,
                            'aliases' => array_values(array_map([DomainPack::class, 'key'], (array) ($column['aliases'] ?? []))),
                            'description' => (string) ($column['description'] ?? ''),
                        ];
                    }
                    continue;
                }
                if ($format === 'json' || count($dimensions) >= $maxDimensions) {
                    continue;
                }
                $dimensions[] = [
                    'name' => $name,
                    'label' => (string) ($column['label'] ?? SchemaHints::label($name)),
                    'column' => $name,
                    'format' => $format,
                    'aliases' => array_values(array_map([DomainPack::class, 'key'], (array) ($column['aliases'] ?? []))),
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

            // A key column nobody described still gains the name behind it,
            // because a verified relation says what it points at.
            $joins = [];
            $names = [];
            foreach ((array) ($entry['columns'] ?? []) as $column) {
                $names[] = (string) ($column['name'] ?? '');
            }
            foreach ($bySource[strtolower($tableName)] ?? [] as $relation) {
                $sourceColumn = trim((string) ($relation['source_column'] ?? ''));
                $target = trim((string) ($relation['target_table'] ?? ''));
                $labelColumn = trim((string) ($relation['label_column'] ?? ''));
                $targetColumn = trim((string) ($relation['target_column'] ?? 'id')) ?: 'id';
                if ($sourceColumn === '' || $target === '' || $labelColumn === '') {
                    continue;
                }
                if (strcasecmp($target, $tableName) === 0) {
                    continue;
                }
                $base = SchemaHints::referenceBase($sourceColumn);
                if ($base === '') {
                    continue;
                }
                $name = $base . '_name';
                if (in_array($name, array_column($dimensions, 'name'), true)) {
                    continue;
                }
                $joins[] = [
                    'table' => $target,
                    'alias' => $target,
                    'on' => [$tableName . '.' . $sourceColumn => $target . '.' . $targetColumn],
                    'type' => 'left',
                ];
                $dimensions[] = [
                    'name' => $name,
                    'label' => SchemaHints::label($target) . ' name',
                    'column' => $target . '.' . $labelColumn,
                    'format' => 'text',
                    'aliases' => [],
                    'description' => 'The name behind ' . $tableName . '.' . $sourceColumn . '.',
                ];
            }

            $datasets[] = [
                'name' => $tableName,
                'table' => $tableName,
                'label' => $label,
                'description' => trim((string) ($entry['description'] ?? '')),
                'aliases' => $this->aliasesFor($tableName, $label),
                'date_field' => $dateField,
                'metrics' => $metrics,
                'dimensions' => $dimensions,
                'joins' => $joins,
                'tenant_scoped' => true,
                'default_order' => '',
            ];

            $entities[] = [
                'name' => $tableName,
                'table' => $tableName,
                'label' => $label,
                'title_column' => $this->firstOf($names, ['name', 'title', 'label', 'full_name', 'reference']),
                'fields' => array_slice(array_values(array_filter($names)), 0, 12),
                'search_columns' => array_values(array_intersect($names, ['name', 'title', 'reference', 'email', 'phone'])),
            ];
        }

        return [
            'name' => 'crm',
            'label' => 'CRM',
            'datasets' => $datasets,
            'entities' => $entities,
        ];
    }

    /** The words a person might use for this table. */
    private function aliasesFor(string $table, string $label): array
    {
        $out = [];
        foreach ([$table, $label, str_replace('_', ' ', $table)] as $candidate) {
            $clean = trim((string) $candidate);
            if ($clean !== '') {
                $out[] = $clean;
            }
        }
        return array_values(array_unique($out));
    }

    /** @param string[] $names */
    private function firstOf(array $names, array $preferred): string
    {
        foreach ($preferred as $candidate) {
            foreach ($names as $name) {
                if (strcasecmp($name, $candidate) === 0) {
                    return $name;
                }
            }
        }
        return '';
    }

    // ─── the drafted knowledge ───────────────────────────────────────────────

    /**
     * The modules, the rules and the glossary this CRM's own code shows.
     *
     * Every rule found in code is a *draft*: the code says what it does, but
     * only a person can say whether that is the business rule. The rules carry
     * the metadata the rule base expects, so a drafted rule shows up in the
     * rules screen waiting for an owner.
     *
     * @return array<int,array<string,mixed>>
     */
    public function draftKnowledge(int $companyId): array
    {
        $entries = [];

        // 1. The modules: the folders the application is built from.
        $modules = [];
        foreach ($this->code->files($companyId) as $file) {
            $module = trim((string) ($file['module'] ?? ''));
            if ($module === '') {
                continue;
            }
            if (!isset($modules[$module])) {
                $modules[$module] = ['files' => 0, 'statements' => 0, 'tables' => []];
            }
            $modules[$module]['files']++;
            $modules[$module]['statements'] += (int) ($file['statements'] ?? 0);
        }
        foreach ($modules as $module => $info) {
            $entries[] = [
                'title' => 'Module: ' . $module,
                'content' => 'Module: ' . $module . "\n"
                    . 'Files: ' . $info['files'] . "\n"
                    . 'Queries found in the code: ' . $info['statements'],
                'category' => RuleService::CATEGORY_MODULE,
                'metadata' => [
                    'module' => $module,
                    'operation' => '',
                    'purpose' => '',
                    'rule' => '',
                    'tables' => [],
                    'verification' => RuleService::VERIFICATION_DRAFT,
                    'source' => 'code',
                ],
            ];
        }

        // 2. The rules: the conditions the code applies.
        foreach ($this->code->statements($companyId, 300) as $statement) {
            foreach ($this->rulesFrom($statement) as $rule) {
                $entries[] = $rule;
            }
        }

        // 3. The glossary: what the columns mean.
        foreach ($this->schema->catalog(true) as $table => $entry) {
            $lines = [];
            foreach ((array) ($entry['columns'] ?? []) as $column) {
                $name = (string) ($column['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $description = trim((string) ($column['description'] ?? ''));
                $lines[] = $description !== ''
                    ? $name . ': ' . $description
                    : $name . ': ' . (string) ($column['label'] ?? SchemaHints::label($name));
            }
            if ($lines === []) {
                continue;
            }
            $entries[] = [
                'title' => 'Glossary: ' . (string) $table,
                'content' => 'Table: ' . (string) $table . "\n" . implode("\n", $lines),
                'category' => RuleService::CATEGORY_GLOSSARY,
                'metadata' => [
                    'module' => '',
                    'operation' => '',
                    'rule' => '',
                    'tables' => [(string) $table],
                    'verification' => RuleService::VERIFICATION_DRAFT,
                    'source' => 'schema',
                ],
            ];
        }

        return $entries;
    }

    /**
     * The rules one query carries: the conditions it filters on.
     *
     * @param array<string,mixed> $statement
     * @return array<int,array<string,mixed>>
     */
    private function rulesFrom(array $statement): array
    {
        $out = [];
        $tables = array_values(array_filter(array_map('strval', (array) ($statement['tables'] ?? []))));
        $module = trim((string) ($statement['module'] ?? ''));
        $file = trim((string) ($statement['file'] ?? ''));

        foreach ((array) ($statement['where'] ?? []) as $predicate) {
            $text = trim((string) $predicate);
            if ($text === '') {
                continue;
            }
            if (preg_match('/([a-z0-9_.]+)\s*=\s*[\'"]([^\'"]{1,60})[\'"]/i', $text, $m) !== 1) {
                continue;
            }
            $column = trim($m[1], '`');
            $value = trim($m[2]);
            if ($column === '' || $value === '') {
                continue;
            }
            $out[] = [
                'title' => 'Rule: ' . $column . ' = ' . $value,
                'content' => 'Module: ' . ($module !== '' ? $module : 'this application') . "\n"
                    . 'Rule: a row counts when ' . $column . ' is "' . $value . '".' . "\n"
                    . 'Found in: ' . ($file !== '' ? $file : 'the application code')
                    . ($tables === [] ? '' : "\nTables: " . implode(', ', $tables)),
                'category' => RuleService::CATEGORY_RULE,
                'metadata' => [
                    'module' => $module,
                    'operation' => '',
                    'purpose' => '',
                    'rule' => 'a row counts when ' . $column . ' is "' . $value . '"',
                    'tables' => $tables,
                    'source_file' => $file,
                    'verification' => RuleService::VERIFICATION_DRAFT,
                    'source' => 'code',
                ],
            ];
        }

        return $out;
    }

    // ─── the two files ───────────────────────────────────────────────────────

    /**
     * Write the semantic layer and the knowledge down as JSON.
     *
     * @param array<string,mixed> $pack
     * @param array<int,array<string,mixed>> $entries
     * @param array<string,mixed> $opts output_dir, pack_file, knowledge_file
     * @return array<string,mixed>
     */
    public function writeFiles(array $pack, array $entries, array $opts = []): array
    {
        $directory = trim((string) ($opts['output_dir'] ?? ($this->options['output_dir'] ?? Config::string('discovery.output_dir', ''))));
        $packName = trim((string) ($opts['pack_file'] ?? ($this->options['pack_file'] ?? self::PACK_FILE)));
        $knowledgeName = trim((string) ($opts['knowledge_file'] ?? ($this->options['knowledge_file'] ?? self::KNOWLEDGE_FILE)));

        $result = ['output_dir' => $directory, 'pack' => '', 'knowledge' => '', 'written' => false, 'error' => ''];

        if ($directory === '') {
            $result['error'] = 'No output folder is configured, so nothing was written. Set discovery.output_dir.';
            return $result;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $result['error'] = 'The output folder ' . $directory . ' could not be created.';
            return $result;
        }
        if (!is_writable($directory)) {
            $result['error'] = 'The output folder ' . $directory . ' is not writable.';
            return $result;
        }

        $packPath = rtrim($directory, '/') . '/' . ($packName !== '' ? $packName : self::PACK_FILE);
        $knowledgePath = rtrim($directory, '/') . '/' . ($knowledgeName !== '' ? $knowledgeName : self::KNOWLEDGE_FILE);

        $packJson = json_encode([$pack], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $knowledgeJson = json_encode(['entries' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($packJson === false || @file_put_contents($packPath, $packJson) === false) {
            $result['error'] = 'The pack file could not be written to ' . $packPath . '.';
            return $result;
        }
        if ($knowledgeJson === false || @file_put_contents($knowledgePath, $knowledgeJson) === false) {
            $result['error'] = 'The knowledge file could not be written to ' . $knowledgePath . '.';
            return $result;
        }

        $result['pack'] = $packPath;
        $result['knowledge'] = $knowledgePath;
        $result['written'] = true;

        return $result;
    }

    /**
     * Load a knowledge file into the base — the other half of writing it down.
     *
     * @return array{loaded:int,created:int,updated:int,unchanged:int,errors:list<string>}
     */
    public function importKnowledgeFile(int $companyId, string $path, ?string $updatedBy = null): array
    {
        $result = ['loaded' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []];
        if ($this->knowledge === null) {
            $result['errors'][] = 'The knowledge base needs a database.';
            return $result;
        }
        if (trim($path) === '' || !is_file($path)) {
            $result['errors'][] = 'The file ' . $path . ' does not exist.';
            return $result;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            $result['errors'][] = 'The file is not valid JSON.';
            return $result;
        }
        $entries = $decoded['entries'] ?? $decoded;
        if (!is_array($entries)) {
            $result['errors'][] = 'The file holds no entries.';
            return $result;
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $title = trim((string) ($entry['title'] ?? ''));
            $content = trim((string) ($entry['content'] ?? ''));
            if ($title === '' && $content === '') {
                continue;
            }
            $result['loaded']++;
            try {
                $outcome = $this->knowledge->ingestRow(
                    $companyId,
                    $title,
                    $content,
                    'file',
                    trim((string) ($entry['category'] ?? '')) ?: null,
                    [
                        'scope' => (string) ($entry['scope'] ?? 'global'),
                        'scope_key' => (string) ($entry['scope_key'] ?? ''),
                        'updated_by' => $updatedBy,
                        'metadata' => (array) ($entry['metadata'] ?? []),
                    ],
                );
                if ((bool) ($outcome['created'] ?? false)) {
                    $result['created']++;
                } elseif ((bool) ($outcome['changed'] ?? false)) {
                    $result['updated']++;
                } else {
                    $result['unchanged']++;
                }
            } catch (Throwable $e) {
                $result['errors'][] = $title . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    // ─── the proof ───────────────────────────────────────────────────────────

    /**
     * Answer the canonical questions against the drafted pack and show the SQL.
     *
     * The questions are the host's own when it configured some; otherwise one
     * per dataset, so every table is proved at least once.
     *
     * @param array<string,mixed> $pack the drafted semantic layer
     * @param string[] $questions
     * @return array<int,array<string,mixed>>
     */
    public function selfTest(int $companyId, array $pack, array $questions = []): array
    {
        $catalog = $this->schema->catalog(true);
        $columns = [];
        foreach ($catalog as $table => $entry) {
            $names = [];
            foreach ((array) ($entry['columns'] ?? []) as $column) {
                $name = trim((string) ($column['name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            $columns[(string) $table] = $names;
        }

        $domain = new DomainPack([$pack], $catalog, ['auto_derive' => false]);
        $compiler = new QueryCompiler($domain, $this->policy, $columns);
        $runner = $this->pdo !== null ? new SqlQueryRunner($this->pdo) : null;

        $questions = array_values(array_filter(array_map(
            static fn ($q): string => trim((string) $q),
            $questions,
        ), static fn (string $q): bool => $q !== ''));
        if ($questions === []) {
            $questions = array_values(array_filter(array_map(
                static fn ($q): string => trim((string) $q),
                Config::list('discovery.questions', []),
            ), static fn (string $q): bool => $q !== ''));
        }
        if ($questions === []) {
            $questions = $this->canonicalQuestions($domain);
        }

        $out = [];
        foreach ($questions as $question) {
            $out[] = $this->answerOne($question, $domain, $compiler, $runner, $companyId);
        }

        return $out;
    }

    /** One question per dataset, so every table is proved at least once. @return string[] */
    private function canonicalQuestions(DomainPack $pack): array
    {
        $questions = [];
        foreach ($pack->listDatasets() as $dataset) {
            $label = trim((string) ($dataset['label'] ?? '')) ?: (string) ($dataset['name'] ?? '');
            if ($label === '') {
                continue;
            }
            $questions[] = 'How many ' . strtolower($label) . ' are there?';
            if (count($questions) >= 20) {
                break;
            }
        }
        return $questions;
    }

    /**
     * Answer one question the way the assistant would: find the dataset, build a
     * plan, compile it, run it — and show the SQL that came out.
     *
     * @return array<string,mixed>
     */
    private function answerOne(
        string $question,
        DomainPack $pack,
        QueryCompiler $compiler,
        ?SqlQueryRunner $runner,
        int $companyId,
    ): array {
        $result = [
            'question' => $question,
            'dataset' => '',
            'metric' => '',
            'dimensions' => [],
            'sql' => '',
            'rows' => [],
            'row_count' => 0,
            'status' => 'unrouted',
            'error' => '',
        ];

        $dataset = $this->route($question, $pack);
        if ($dataset === null) {
            $result['error'] = 'No dataset matches this question.';
            return $result;
        }
        $result['dataset'] = (string) ($dataset['name'] ?? '');

        $metric = $this->metricFor($question, $dataset);
        $result['metric'] = $metric;
        $dimension = $this->dimensionFor($question, $dataset);
        $result['dimensions'] = $dimension === null ? [] : [$dimension];

        $plan = new QueryPlan(
            dataset: (string) ($dataset['name'] ?? ''),
            metrics: [$metric],
            dimensions: $dimension === null ? [] : [$dimension],
        );

        try {
            $compiled = $compiler->compile($plan, [
                'tenant_id' => $companyId,
                'tenant_column' => $this->policy->tenantColumn(),
            ]);
            $result['sql'] = (string) ($compiled['sql'] ?? '');
            if ($runner === null) {
                $result['status'] = 'compiled';
                return $result;
            }
            $query = $runner($compiled, null);
            $result['rows'] = array_slice($query->rows, 0, 5);
            $result['row_count'] = count($query->rows);
            $result['status'] = 'ok';
        } catch (Throwable $e) {
            $result['status'] = 'failed';
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /** The dataset a question is about. @return array<string,mixed>|null */
    private function route(string $question, DomainPack $pack): ?array
    {
        $terms = TextScore::tokenize($question);
        if ($terms === []) {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach ($pack->listDatasets() as $dataset) {
            $haystack = strtolower(implode(' ', [
                (string) ($dataset['name'] ?? ''),
                (string) ($dataset['label'] ?? ''),
                (string) ($dataset['table'] ?? ''),
                implode(' ', (array) ($dataset['aliases'] ?? [])),
            ]));
            if ($haystack === '') {
                continue;
            }
            $score = 0;
            foreach ($terms as $term) {
                if (str_contains($haystack, $term)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $dataset;
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    /**
     * The metric a question asks for: the money when it asks for money, the
     * row count otherwise.
     *
     * @param array<string,mixed> $dataset
     */
    private function metricFor(string $question, array $dataset): string
    {
        $words = self::words($question);
        $money = false;
        foreach ($words as $word) {
            if (in_array($word, self::MONEY_WORDS, true)) {
                $money = true;
                break;
            }
        }
        if ($money) {
            foreach ((array) ($dataset['metrics'] ?? []) as $metric) {
                if ((string) ($metric['format'] ?? '') === 'money') {
                    return (string) ($metric['name'] ?? '');
                }
            }
        }
        foreach ((array) ($dataset['metrics'] ?? []) as $metric) {
            if ((string) ($metric['name'] ?? '') === 'records') {
                return 'records';
            }
        }
        $first = (array) ($dataset['metrics'] ?? [])[0] ?? [];

        return (string) ($first['name'] ?? 'records');
    }

    /**
     * The field a question groups by, when it names one.
     *
     * @param array<string,mixed> $dataset
     */
    private function dimensionFor(string $question, array $dataset): ?string
    {
        $words = self::words($question);
        if ($words === []) {
            return null;
        }
        foreach ((array) ($dataset['dimensions'] ?? []) as $dimension) {
            $name = strtolower((string) ($dimension['name'] ?? ''));
            $label = strtolower((string) ($dimension['label'] ?? ''));
            foreach ($words as $word) {
                if (($name !== '' && str_contains($name, $word)) || ($label !== '' && str_contains($label, $word))) {
                    return (string) ($dimension['name'] ?? '');
                }
            }
        }

        return null;
    }

    /** @return string[] */
    private static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', strtolower($text)) ?: [];
        $out = [];
        foreach ($words as $word) {
            $word = trim((string) $word);
            if ($word !== '' && strlen($word) > 2) {
                $out[] = $word;
            }
        }
        return array_values(array_unique($out));
    }
}
