<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use Closure;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\SchemaHints;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;
use PDO;
use Throwable;

/**
 * The quality bar of one CRM: the questions it must keep answering.
 *
 * A benchmark is a list of canonical questions with what the answer should
 * look like — the dataset it comes from, the columns it carries, the value it
 * should add up to. It is run on demand from the admin screen and in CI, and
 * it measures six things:
 *
 *   routing      — the answer came from the dataset it should
 *   columns      — the answer carries the fields that were asked for
 *   ids          — the answer shows a name, not a key
 *   money        — the answer sums the money column and formats it as money
 *   permission   — a restricted role sees nothing it may not see
 *   freshness    — the answer reflects the data as it is now
 *
 * The file is JSON, not PHP, so a business person can read it, keep it in the
 * repository and change it when the business changes. Nothing here knows any
 * particular application: the questions, the datasets and the values all come
 * from the host's own CRM.
 */
final class BenchmarkService
{
    /** The file one CRM keeps. */
    public const FILE = 'ai-assistant.benchmark.json';

    /** The share of key columns an answer may carry before it fails. */
    private const ID_CEILING = 0.5;

    /** The checks a case is scored on. */
    public const CHECKS = ['routing', 'columns', 'value', 'ids', 'money', 'permission', 'freshness'];

    private ?PDO $pdo;
    private SchemaService $schema;
    private SchemaPolicy $policy;
    private ?Closure $ask;
    /** @var array<string,mixed> */
    private array $options;

    /**
     * @param callable|null $ask fn (string $question, UserContext $user): array — the answer
     * @param array<string,mixed> $options file, id_ceiling
     */
    public function __construct(
        ?PDO $pdo,
        SchemaService $schema,
        ?SchemaPolicy $policy = null,
        ?callable $ask = null,
        array $options = [],
    ) {
        $this->pdo = $pdo;
        $this->schema = $schema;
        $this->policy = $policy ?? new SchemaPolicy();
        $this->ask = $ask === null ? null : Closure::fromCallable($ask);
        $this->options = $options;
    }

    // ─── the file ─────────────────────────────────────────────────────────────

    /**
     * Read a benchmark file and normalise its cases.
     *
     * @return array{crm:string,questions:array<int,array<string,mixed>>,path:string,error:?string}
     */
    public function read(string $path): array
    {
        $result = ['crm' => '', 'questions' => [], 'path' => $path, 'error' => null];

        if (trim($path) === '') {
            $result['error'] = 'No benchmark file was named.';

            return $result;
        }
        if (!is_file($path)) {
            $result['error'] = 'The benchmark file ' . $path . ' does not exist.';

            return $result;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            $result['error'] = 'The benchmark file is not valid JSON.';

            return $result;
        }

        $raw = $decoded['questions'] ?? $decoded;
        if (!is_array($raw)) {
            $result['error'] = 'The benchmark file holds no questions.';

            return $result;
        }

        $result['crm'] = trim((string) ($decoded['crm'] ?? ''));
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $question = trim((string) ($entry['question'] ?? ''));
            if ($question === '') {
                continue;
            }
            $result['questions'][] = $this->caseFrom($entry);
        }

        if ($result['questions'] === []) {
            $result['error'] = 'The benchmark file holds no questions.';
        }

        return $result;
    }

    /**
     * One case, normalised.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function caseFrom(array $entry): array
    {
        $columns = array_values(array_filter(array_map('strval', (array) ($entry['columns'] ?? []))));
        $valueColumn = trim((string) ($entry['value_column'] ?? ''));
        if ($valueColumn === '') {
            $valueColumn = $columns[0] ?? '';
        }

        return [
            'question' => trim((string) ($entry['question'] ?? '')),
            'dataset' => trim((string) ($entry['dataset'] ?? '')),
            'columns' => $columns,
            'value' => array_key_exists('value', $entry) && $entry['value'] !== null ? $entry['value'] : null,
            'value_column' => $valueColumn,
            'money' => (bool) ($entry['money'] ?? false),
            'role' => trim((string) ($entry['role'] ?? '')),
            'roles' => array_values(array_filter(array_map('strval', (array) ($entry['roles'] ?? [])))),
            'forbidden_columns' => array_values(array_filter(array_map('strval', (array) ($entry['forbidden_columns'] ?? [])))),
            'forbidden_datasets' => array_values(array_filter(array_map('strval', (array) ($entry['forbidden_datasets'] ?? [])))),
            'live' => (array) ($entry['live'] ?? []),
        ];
    }

    /**
     * A starter benchmark: one question per table the CRM has.
     *
     * The datasets are known; the values are not, so they are left for a person
     * to fill in once they know what the right answer is.
     *
     * @return array<string,mixed>
     */
    public function template(int $companyId, array $opts = []): array
    {
        $questions = [];
        foreach ($this->schema->catalog(true) as $table => $entry) {
            $label = trim((string) ($entry['label'] ?? '')) ?: SchemaHints::label((string) $table);
            $questions[] = [
                'question' => 'How many ' . strtolower($label) . ' are there?',
                'dataset' => (string) $table,
                'columns' => ['records'],
                'value_column' => 'records',
                'value' => null,
                'money' => false,
            ];
            if (count($questions) >= 50) {
                break;
            }
        }

        return [
            'crm' => trim((string) ($opts['crm'] ?? '')),
            'questions' => $questions,
        ];
    }

    /**
     * Write the benchmark file.
     *
     * @param array<string,mixed> $benchmark
     * @return array{path:string,written:bool,error:?string}
     */
    public function write(array $benchmark, array $opts = []): array
    {
        $directory = trim((string) ($opts['output_dir'] ?? ($this->options['output_dir'] ?? '')));
        $name = trim((string) ($opts['file'] ?? ($this->options['file'] ?? self::FILE)));
        $result = ['path' => '', 'written' => false, 'error' => null];

        if ($directory === '') {
            $result['error'] = 'No output folder is configured, so nothing was written. Set benchmark.output_dir.';

            return $result;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $result['error'] = 'The output folder ' . $directory . ' could not be created.';

            return $result;
        }
        $path = rtrim($directory, '/') . '/' . ($name !== '' ? $name : self::FILE);
        $json = json_encode($benchmark, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($path, $json) === false) {
            $result['error'] = 'The benchmark file could not be written to ' . $path . '.';

            return $result;
        }
        $result['path'] = $path;
        $result['written'] = true;

        return $result;
    }

    // ─── the run ──────────────────────────────────────────────────────────────

    /**
     * Run the benchmark and score it.
     *
     * @param array<string,mixed> $opts file, questions, id_ceiling
     * @return array<string,mixed>
     */
    public function run(int $companyId, array $opts = []): array
    {
        $path = trim((string) ($opts['file'] ?? ($this->options['file'] ?? Config::string('benchmark.file', ''))));
        $file = $this->read($path);

        $report = [
            'crm' => $file['crm'],
            'file' => $path,
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'metrics' => [],
            'cases' => [],
            'errors' => [],
        ];
        if ($file['error'] !== null) {
            $report['errors'][] = $file['error'];

            return $report;
        }

        $ceiling = (float) ($opts['id_ceiling'] ?? ($this->options['id_ceiling'] ?? Config::float('benchmark.id_ceiling', self::ID_CEILING)));
        $questions = (array) ($file['questions'] ?? []);
        $wanted = array_values(array_filter(array_map('strval', (array) ($opts['questions'] ?? []))));
        if ($wanted !== []) {
            $questions = array_values(array_filter(
                $questions,
                static fn (array $case): bool => in_array((string) $case['question'], $wanted, true),
            ));
        }

        foreach ($questions as $case) {
            $report['cases'][] = $this->measure($case, $companyId, $ceiling);
        }

        $report['total'] = count($report['cases']);
        foreach ($report['cases'] as $case) {
            if (($case['passed'] ?? false) === true) {
                $report['passed']++;
            } else {
                $report['failed']++;
            }
        }
        $report['metrics'] = $this->metrics($report['cases']);

        return $report;
    }

    /**
     * Measure one case against the answer the CRM gave.
     *
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    private function measure(array $case, int $companyId, float $ceiling): array
    {
        $result = [
            'question' => (string) $case['question'],
            'expected_dataset' => (string) $case['dataset'],
            'dataset' => '',
            'columns' => [],
            'value' => null,
            'id_rate' => 0.0,
            'checks' => array_fill_keys(self::CHECKS, true),
            'skipped' => [],
            'passed' => false,
            'error' => '',
        ];

        if ($this->ask === null) {
            $result['error'] = 'The benchmark needs an assistant to ask with.';

            return $result;
        }

        $user = new UserContext(
            userId: (string) ($case['role'] ?? '') !== '' ? (string) $case['role'] : 'benchmark',
            tenantId: $companyId,
            roles: array_values((array) ($case['roles'] ?? [])),
            threadId: 'benchmark',
        );

        try {
            $answer = ($this->ask)((string) $case['question'], $user);
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();

            return $result;
        }
        if (!is_array($answer)) {
            $result['error'] = 'The assistant did not answer.';

            return $result;
        }

        $meta = (array) ($answer['meta'] ?? []);
        $columns = array_values(array_map('strval', (array) ($meta['columns'] ?? [])));
        $formats = (array) ($meta['formats'] ?? []);
        $rows = array_values((array) ($answer['rows'] ?? []));
        $dataset = (string) ($meta['dataset'] ?? '');

        $result['dataset'] = $dataset;
        $result['columns'] = $columns;
        $result['intent'] = (string) ($answer['intent'] ?? '');

        $forbiddenColumns = array_values((array) ($case['forbidden_columns'] ?? []));
        $forbiddenDatasets = array_values((array) ($case['forbidden_datasets'] ?? []));
        $isPermissionCase = (string) ($case['role'] ?? '') !== ''
            || $forbiddenColumns !== []
            || $forbiddenDatasets !== [];
        $empty = $columns === [];

        // 1. Routing: the answer came from the dataset it should.
        $expected = (string) ($case['dataset'] ?? '');
        if ($expected === '') {
            $result['checks']['routing'] = true;
        } elseif ($empty && $isPermissionCase) {
            // The role was not allowed an answer, so there is nothing to route.
            $result['skipped'][] = 'routing';
        } else {
            $result['checks']['routing'] = $this->sameName($dataset, $expected);
        }

        // 2. Columns: the answer carries the fields that were asked for.
        $wanted = array_values((array) ($case['columns'] ?? []));
        $visible = array_values(array_diff($wanted, $forbiddenColumns));
        if ($wanted === []) {
            $result['skipped'][] = 'columns';
        } elseif ($empty) {
            if ($isPermissionCase) {
                $result['skipped'][] = 'columns';
            } else {
                $result['checks']['columns'] = false;
            }
        } elseif ($visible === []) {
            $result['skipped'][] = 'columns';
        } else {
            $result['checks']['columns'] = $this->hasAll($columns, $visible);
        }

        // 3. The value: the number the answer adds up to.
        $valueColumn = (string) ($case['value_column'] ?? '');
        $actual = null;
        if ($valueColumn !== '' && $rows !== []) {
            $first = (array) $rows[0];
            $actual = $first[$valueColumn] ?? null;
        }
        $result['value'] = $actual;
        if ($case['value'] === null) {
            $result['skipped'][] = 'value';
        } elseif ($valueColumn !== '' && in_array($valueColumn, $forbiddenColumns, true)) {
            $result['skipped'][] = 'value';
        } elseif ($actual === null && $empty && $isPermissionCase) {
            $result['skipped'][] = 'value';
        } else {
            $result['checks']['value'] = $actual !== null && $this->sameNumber($actual, $case['value']);
        }

        // 4. Ids: the answer shows a name, not a key.
        $keys = array_values(array_filter($columns, fn (string $c): bool => $this->isKey($c)));
        $result['id_rate'] = $columns === [] ? 0.0 : count($keys) / count($columns);
        if ($columns === []) {
            $result['skipped'][] = 'ids';
        } else {
            $result['checks']['ids'] = $result['id_rate'] <= $ceiling;
        }

        // 5. Money: the money column is there and it is money — unless the role
        //    may not see it, in which case it has to stay away.
        if ((bool) ($case['money'] ?? false)) {
            $moneyColumns = array_values(array_filter(
                $wanted,
                fn (string $c): bool => $this->isMoneyColumn($c, $formats)
            ));
            $hidden = array_values(array_intersect($moneyColumns, $forbiddenColumns));
            if ($moneyColumns === []) {
                $result['skipped'][] = 'money';
            } elseif ($hidden !== []) {
                $result['checks']['money'] = !in_array($hidden[0], $columns, true);
            } else {
                $shown = false;
                foreach ($moneyColumns as $column) {
                    if (in_array($column, $columns, true)
                        && strtolower((string) ($formats[$column] ?? '')) === 'money') {
                        $shown = true;
                        break;
                    }
                }
                $result['checks']['money'] = $shown;
            }
        } else {
            $result['skipped'][] = 'money';
        }

        // 6. Permission: the role saw nothing it may not see.
        $leaked = [];
        foreach ($forbiddenColumns as $column) {
            if (in_array($column, $columns, true)) {
                $leaked[] = $column;
            }
        }
        foreach ($forbiddenDatasets as $name) {
            if ($this->sameName($dataset, (string) $name)) {
                $leaked[] = 'dataset:' . $name;
            }
        }
        $result['leaked'] = $leaked;
        $result['checks']['permission'] = $leaked === [];

        // 7. Freshness: the answer reflects the data as it is now.
        $live = (array) ($case['live'] ?? []);
        if ($live === []) {
            $result['skipped'][] = 'freshness';
        } elseif ($actual === null && $empty && $isPermissionCase) {
            $result['skipped'][] = 'freshness';
        } else {
            $now = $this->liveValue($live, $companyId);
            $result['live'] = $now['value'];
            $result['checks']['freshness'] = $now['error'] === ''
                && $actual !== null
                && $this->sameNumber($actual, $now['value']);
            if ($now['error'] !== '') {
                $result['error'] = $now['error'];
            }
        }

        $result['passed'] = !in_array(false, $result['checks'], true);

        return $result;
    }

    /**
     * The score, per metric.
     *
     * @param array<int,array<string,mixed>> $cases
     * @return array<string,array<string,mixed>>
     */
    private function metrics(array $cases): array
    {
        $groups = [
            'routing' => ['routing'],
            'columns' => ['columns', 'value'],
            'ids' => ['ids'],
            'money' => ['money'],
            'permission' => ['permission'],
            'freshness' => ['freshness'],
        ];

        $out = [];
        foreach ($groups as $name => $checks) {
            $total = 0;
            $passed = 0;
            foreach ($cases as $case) {
                $skipped = (array) ($case['skipped'] ?? []);
                foreach ($checks as $check) {
                    if (in_array($check, $skipped, true)) {
                        continue;
                    }
                    $total++;
                    if (($case['checks'][$check] ?? false) === true) {
                        $passed++;
                    }
                }
            }
            $out[$name] = [
                'accuracy' => $total === 0 ? 1.0 : $passed / $total,
                'passed' => $passed,
                'total' => $total,
            ];
        }

        $rates = [];
        foreach ($cases as $case) {
            if (!in_array('ids', (array) ($case['skipped'] ?? []), true)) {
                $rates[] = (float) ($case['id_rate'] ?? 0);
            }
        }
        $out['ids']['rate'] = $rates === [] ? 0.0 : array_sum($rates) / count($rates);

        return $out;
    }

    // ─── internals ────────────────────────────────────────────────────────────

    /**
     * The value the database says right now.
     *
     * @param array<string,mixed> $live table, column, aggregate, where
     * @return array{value:mixed,error:string}
     */
    private function liveValue(array $live, int $companyId): array
    {
        $result = ['value' => null, 'error' => ''];

        if ($this->pdo === null) {
            $result['error'] = 'The benchmark needs a database to check the answer against.';

            return $result;
        }
        $table = trim((string) ($live['table'] ?? ''));
        if ($table === '') {
            $result['error'] = 'The live check names no table.';

            return $result;
        }
        $aggregate = strtolower(trim((string) ($live['aggregate'] ?? 'count')));
        if (!in_array($aggregate, ['count', 'sum', 'avg', 'min', 'max'], true)) {
            $aggregate = 'count';
        }
        $column = trim((string) ($live['column'] ?? '*'));
        if ($aggregate !== 'count' && $column === '') {
            $result['error'] = 'The live check names no column to ' . $aggregate . '.';

            return $result;
        }

        $expression = $aggregate === 'count'
            ? 'COUNT(*)'
            : strtoupper($aggregate) . '(' . Db::identifier($column === '*' ? '' : $column) . ')';

        $bind = [];
        $where = [];
        $tenantColumn = $this->policy->tenantColumn();
        if ($tenantColumn !== '' && $this->hasColumn($table, $tenantColumn)) {
            $where[] = Db::identifier($tenantColumn) . ' = :__tenant';
            $bind[':__tenant'] = $companyId;
        }
        foreach ((array) ($live['where'] ?? []) as $name => $value) {
            $where[] = Db::identifier((string) $name) . ' = :w_' . (string) $name;
            $bind[':w_' . (string) $name] = $value;
        }

        $sql = 'SELECT ' . $expression . ' AS v FROM ' . Db::identifier($table);
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        try {
            $statement = $this->pdo->prepare($sql);
            foreach ($bind as $name => $value) {
                $statement->bindValue((string) $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $statement->execute();
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $result['value'] = $row === false ? null : ($row['v'] ?? null);
        } catch (Throwable $e) {
            $result['error'] = 'The live check failed: ' . $e->getMessage();
        }

        return $result;
    }

    /** Whether the catalogue says the table has the column. */
    private function hasColumn(string $table, string $column): bool
    {
        $catalog = $this->schema->catalog(true);
        foreach ((array) ($catalog[$table]['columns'] ?? []) as $entry) {
            if (strcasecmp((string) ($entry['name'] ?? ''), $column) === 0) {
                return true;
            }
        }

        return false;
    }

    /** A column that holds a key rather than a name. */
    private function isKey(string $column): bool
    {
        $name = strtolower(trim($column));
        if ($name === '' || $name === 'id') {
            return $name !== '';
        }

        return (bool) preg_match('/(_id|_uuid|_code)$/', $name);
    }

    /**
     * Whether a column carries money. The answer's own formats say so when the
     * column is in it; the catalogue says so when the answer never showed it.
     */
    private function isMoneyColumn(string $column, array $formats): bool
    {
        if (strtolower((string) ($formats[$column] ?? '')) === 'money') {
            return true;
        }
        foreach ($this->schema->catalog(true) as $entry) {
            foreach ((array) ($entry['columns'] ?? []) as $col) {
                if (strcasecmp((string) ($col['name'] ?? ''), $column) === 0) {
                    return strtolower((string) ($col['format'] ?? '')) === 'money';
                }
            }
        }

        return false;
    }

    /** @param string[] $columns @param string[] $wanted */
    private function hasAll(array $columns, array $wanted): bool
    {
        foreach ($wanted as $column) {
            if (!in_array($column, $columns, true)) {
                return false;
            }
        }

        return true;
    }

    private function sameName(string $a, string $b): bool
    {
        return strcasecmp(trim($a), trim($b)) === 0;
    }

    private function sameNumber(mixed $actual, mixed $expected): bool
    {
        if (!is_numeric($actual) || !is_numeric($expected)) {
            return false;
        }

        return abs((float) $actual - (float) $expected) < 0.000001;
    }
}
