<?php

/**
 * The quality bar: the questions one CRM must keep answering.
 *
 * A fixture CRM with a catalogue, a pack and a scripted model stands in for
 * any application. The benchmark must score the answers: the right dataset,
 * the right columns, the right value, a name instead of a key, money that is
 * money, a restricted role that sees nothing it may not, and an answer that
 * reflects the data as it is now.
 *
 *   php tests/benchmark.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Contracts\PermissionResolverInterface;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Http\AssistantApi;
use ExcelleInsights\AiAssistant\Services\AssistantService;
use ExcelleInsights\AiAssistant\Services\BenchmarkService;
use ExcelleInsights\AiAssistant\Services\QueryCompiler;
use ExcelleInsights\AiAssistant\Services\SchemaService;
use ExcelleInsights\AiAssistant\Services\SqlQueryRunner;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\DomainPack;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL  {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

function same(string $name, mixed $expected, mixed $actual): void
{
    check($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/**
 * A scripted model: it answers from the question it is given, so a benchmark
 * can be run without a network — and can be made to route wrongly on purpose.
 */
final class BenchmarkLlm implements LlmClientInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $plans;

    /** @param array<string,array<string,mixed>> $plans question => the router's reply */
    public function __construct(array $plans)
    {
        $this->plans = $plans;
    }

    public function chat(array $messages, string $model = '', ?float $temperature = null): array
    {
        $system = (string) ($messages[0]['content'] ?? '');
        if (!str_contains($system, '"intent":"data|knowledge')) {
            return ['content' => 'Here is the answer.', 'usage' => []];
        }
        $question = '';
        foreach ($messages as $message) {
            if ((string) ($message['role'] ?? '') === 'user') {
                $question = (string) ($message['content'] ?? '');
            }
        }
        $plan = $this->plans[$question] ?? ['intent' => 'knowledge', 'plan' => []];

        return ['content' => json_encode($plan, JSON_UNESCAPED_UNICODE), 'usage' => []];
    }

    public function embeddings(string $input, string $model = ''): array
    {
        return [];
    }
}

/**
 * A host that hides one column from one role.
 */
final class RolePermissions implements PermissionResolverInterface
{
    public function __construct(private array $hidden = [])
    {
    }

    public function canView(UserContext $user, string $entity): bool
    {
        return true;
    }

    public function rowScope(UserContext $user, string $entity): ?string
    {
        return null;
    }

    public function hiddenColumns(UserContext $user, string $entity): array
    {
        return $this->hidden[$user->userId] ?? [];
    }

    public function canAct(UserContext $user, string $action): bool
    {
        return true;
    }

    public function canExport(UserContext $user, string $entity): bool
    {
        return true;
    }
}

Config::reset();
Config::set('table_prefix', 'aibench');
Config::set('tenant.column', 'company_id');
Config::set('schema_policy.mode', 'denylist');
Config::set('schema_policy.require_tenant_scope', true);
Config::set('timezone', 'UTC');

// ─── the CRM ──────────────────────────────────────────────────────────────────

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, company_id INTEGER, customer_id INTEGER, status TEXT, total_amount NUMERIC, created_at TEXT)');
$pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT)');
$pdo->exec("INSERT INTO orders VALUES (1, 7, 42, 'new', 100, '2026-10-01'), (2, 7, 42, 'done', 200, '2026-10-02'), (3, 7, 99, 'done', 300, '2026-10-03'), (4, 8, 42, 'new', 900, '2026-10-04')");
$pdo->exec("INSERT INTO customers VALUES (1, 7, 'Acme Ltd'), (2, 8, 'Other Ltd')");

$pdo->exec('CREATE TABLE aibench_schema ('
    . 'table_name TEXT, column_name TEXT, data_type TEXT, description TEXT, label TEXT, table_label TEXT,'
    . 'sensitivity TEXT, is_measure INTEGER, format TEXT, aliases TEXT, description_source TEXT)');
$catalogRows = [
    ['orders', '', '', 'What was sold', 'Orders', 'Orders', 'internal', 0, 'text', '', 'auto'],
    ['orders', 'id', 'int', '', 'Order id', 'Orders', 'internal', 0, 'number', '', 'auto'],
    ['orders', 'company_id', 'int', '', 'Company', 'Orders', 'internal', 0, 'number', '', 'auto'],
    ['orders', 'customer_id', 'int', '', 'Customer', 'Orders', 'internal', 0, 'number', '', 'auto'],
    ['orders', 'status', 'varchar', '', 'Status', 'Orders', 'internal', 0, 'text', '', 'auto'],
    ['orders', 'total_amount', 'decimal', '', 'Total amount', 'Orders', 'internal', 1, 'money', '', 'auto'],
    ['orders', 'created_at', 'datetime', '', 'Created', 'Orders', 'internal', 0, 'datetime', '', 'auto'],
    ['customers', '', '', 'The people who buy', 'Customers', 'Customers', 'internal', 0, 'text', '', 'auto'],
    ['customers', 'id', 'int', '', 'Customer id', 'Customers', 'internal', 0, 'number', '', 'auto'],
    ['customers', 'company_id', 'int', '', 'Company', 'Customers', 'internal', 0, 'number', '', 'auto'],
    ['customers', 'name', 'varchar', 'The full name', 'Name', 'Customers', 'internal', 0, 'text', '', 'auto'],
];
$insert = $pdo->prepare(
    'INSERT INTO aibench_schema (table_name, column_name, data_type, description, label, table_label,'
    . 'sensitivity, is_measure, format, aliases, description_source) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
);
foreach ($catalogRows as $row) {
    $insert->execute($row);
}

$policy = new SchemaPolicy();
$schema = new SchemaService($pdo, $policy);
$catalog = $schema->catalog(true);
check('catalog: the tables were read', count($catalog) === 2, implode(', ', array_keys($catalog)));

$tableColumns = [];
foreach ($catalog as $table => $entry) {
    $names = [];
    foreach ((array) ($entry['columns'] ?? []) as $column) {
        $names[] = (string) $column['name'];
    }
    $tableColumns[(string) $table] = $names;
}

$pack = new DomainPack([], $catalog);
$compiler = new QueryCompiler($pack, $policy, $tableColumns);

// ─── the assistant ────────────────────────────────────────────────────────────

$llm = new BenchmarkLlm([
    'How many orders are there?' => ['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]],
    'What is the total amount of orders?' => ['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['total_amount']]],
    'Show the customer key of every order' => ['intent' => 'data', 'plan' => ['dataset' => 'orders', 'dimensions' => ['customer_id']]],
    'How many customers are there?' => ['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]],
    'What is the total amount for the guest?' => ['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['total_amount']]],
]);

$permissions = new RolePermissions(['guest' => ['total_amount']]);

$assistant = new AssistantService(
    llm: $llm,
    pack: $pack,
    compiler: $compiler,
    permissions: $permissions,
    hooks: ['rows' => new SqlQueryRunner($pdo)],
);

$benchmark = new BenchmarkService(
    $pdo,
    $schema,
    $policy,
    fn (string $question, UserContext $user): array => $assistant->ask($question, $user)->toArray(),
);

// ─── the benchmark file ───────────────────────────────────────────────────────

$dir = sys_get_temp_dir() . '/ai-assistant-benchmark-' . getmypid();
@mkdir($dir, 0777, true);

register_shutdown_function(static function () use ($dir): void {
    @unlink($dir . '/' . BenchmarkService::FILE);
    @rmdir($dir);
});

$file = [
    'crm' => 'fixture',
    'questions' => [
        [
            'question' => 'How many orders are there?',
            'dataset' => 'orders',
            'columns' => ['records'],
            'value_column' => 'records',
            'value' => 3,
            'live' => ['table' => 'orders', 'aggregate' => 'count'],
        ],
        [
            'question' => 'What is the total amount of orders?',
            'dataset' => 'orders',
            'columns' => ['total_amount'],
            'value_column' => 'total_amount',
            'value' => 600,
            'money' => true,
            'live' => ['table' => 'orders', 'column' => 'total_amount', 'aggregate' => 'sum'],
        ],
        [
            'question' => 'Show the customer key of every order',
            'dataset' => 'orders',
            'columns' => ['customer_id'],
        ],
        [
            'question' => 'How many customers are there?',
            'dataset' => 'customers',
            'columns' => ['records'],
        ],
        [
            'question' => 'What is the total amount for the guest?',
            'dataset' => 'orders',
            'columns' => ['total_amount'],
            'value_column' => 'total_amount',
            'money' => true,
            'role' => 'guest',
            'forbidden_columns' => ['total_amount'],
        ],
    ],
];

$written = $benchmark->write($file, ['output_dir' => $dir]);
check('write: the file was written', $written['written'] === true, (string) $written['error']);
check('write: it is where it said', is_file($written['path']));
same('write: nothing failed', null, $written['error']);

$read = $benchmark->read($written['path']);
same('read: the crm is named', 'fixture', $read['crm']);
same('read: every question came back', 5, count($read['questions']));
same('read: nothing failed', null, $read['error']);

$missing = $benchmark->read($dir . '/nothing.json');
check('read: a missing file is reported', $missing['error'] !== null, (string) $missing['error']);

// ─── the run ──────────────────────────────────────────────────────────────────

$report = $benchmark->run(7, ['file' => $written['path']]);

same('run: every case was measured', 5, $report['total']);
same('run: three cases pass', 3, $report['passed']);
same('run: two cases fail', 2, $report['failed']);

$cases = [];
foreach ($report['cases'] as $case) {
    $cases[(string) $case['question']] = $case;
}

$counts = $cases['How many orders are there?'] ?? [];
check('counts: the routing is right', ($counts['checks']['routing'] ?? false) === true, json_encode($counts));
check('counts: the columns are there', ($counts['checks']['columns'] ?? false) === true, json_encode($counts));
check('counts: the value is right', ($counts['checks']['value'] ?? false) === true, json_encode($counts));
check('counts: no key is shown', ($counts['checks']['ids'] ?? false) === true, json_encode($counts));
check('counts: the answer is fresh', ($counts['checks']['freshness'] ?? false) === true, json_encode($counts));
same('counts: the dataset is named', 'orders', $counts['dataset'] ?? '');
same('counts: the value came back', 3, (int) ($counts['value'] ?? 0));
check('counts: the case passes', ($counts['passed'] ?? false) === true, json_encode($counts));

$money = $cases['What is the total amount of orders?'] ?? [];
check('money: the money column is there', ($money['checks']['columns'] ?? false) === true, json_encode($money));
check('money: it is money', ($money['checks']['money'] ?? false) === true, json_encode($money));
same('money: the sum came back', 600.0, (float) ($money['value'] ?? 0));
check('money: the case passes', ($money['passed'] ?? false) === true, json_encode($money));

$keys = $cases['Show the customer key of every order'] ?? [];
check('keys: a key is shown', ($keys['checks']['ids'] ?? false) === false, json_encode($keys));
check('keys: the case fails', ($keys['passed'] ?? false) === false, json_encode($keys));
check('keys: the rate is reported', (float) ($keys['id_rate'] ?? 0) > 0, json_encode($keys));

$wrong = $cases['How many customers are there?'] ?? [];
check('routing: the wrong dataset is caught', ($wrong['checks']['routing'] ?? false) === false, json_encode($wrong));
check('routing: the case fails', ($wrong['passed'] ?? false) === false, json_encode($wrong));

$guest = $cases['What is the total amount for the guest?'] ?? [];
check('permission: nothing forbidden was shown', ($guest['checks']['permission'] ?? false) === true, json_encode($guest));
same('permission: nothing leaked', [], $guest['leaked'] ?? ['x']);
check('permission: the money column stayed away', ($guest['checks']['money'] ?? false) === true, json_encode($guest));
check('permission: the case passes', ($guest['passed'] ?? false) === true, json_encode($guest));

// ─── the score ────────────────────────────────────────────────────────────────

$metrics = $report['metrics'] ?? [];
check('metrics: routing is scored', isset($metrics['routing']['accuracy']), json_encode($metrics));
same('metrics: routing is three in four', 0.75, (float) ($metrics['routing']['accuracy'] ?? 0));
same('metrics: columns are right', 1.0, (float) ($metrics['columns']['accuracy'] ?? 0));
same('metrics: permission is right', 1.0, (float) ($metrics['permission']['accuracy'] ?? 0));
same('metrics: freshness is right', 1.0, (float) ($metrics['freshness']['accuracy'] ?? 0));
check('metrics: the id rate is reported', (float) ($metrics['ids']['rate'] ?? 0) > 0, json_encode($metrics));

// ─── a run without a file ─────────────────────────────────────────────────────

$none = $benchmark->run(7, ['file' => $dir . '/nothing.json']);
check('run: a missing file is reported', $none['errors'] !== [], json_encode($none));
same('run: nothing was measured', 0, $none['total']);

// ─── only some questions ──────────────────────────────────────────────────────

$some = $benchmark->run(7, ['file' => $written['path'], 'questions' => ['How many orders are there?']]);
same('run: only the asked question was measured', 1, $some['total']);
check('run: it passed', $some['failed'] === 0, json_encode($some));

// ─── the starter file ─────────────────────────────────────────────────────────

$template = $benchmark->template(7, ['crm' => 'fixture']);
same('template: one question per table', 2, count($template['questions']));
same('template: the crm is named', 'fixture', $template['crm']);
check('template: the dataset is known', ($template['questions'][0]['dataset'] ?? '') !== '', json_encode($template));
check(
    'template: the value is left to a person',
    array_key_exists('value', $template['questions'][0]) && $template['questions'][0]['value'] === null,
    json_encode($template)
);

// ─── the surface a host calls ─────────────────────────────────────────────────

$api = new AssistantApi($pdo, ['llm' => $llm, 'permissions' => $permissions], ['settings' => ['enabled' => false]]);

$apiTemplate = $api->handle('benchmark.template', ['tenant_id' => 7]);
check('api: the template answers', ($apiTemplate['ok'] ?? false) === true, (string) ($apiTemplate['error'] ?? ''));
same('api: it brings the questions', 2, count($apiTemplate['benchmark']['questions'] ?? []));

$apiWrite = $api->handle('benchmark.write', [
    'tenant_id' => 7,
    'benchmark' => $file,
    'options' => ['output_dir' => $dir],
]);
check('api: the file is written', ($apiWrite['written'] ?? false) === true, (string) ($apiWrite['error'] ?? ''));

$apiRead = $api->handle('benchmark.read', ['path' => $written['path']]);
check('api: the file is read', ($apiRead['ok'] ?? false) === true, (string) ($apiRead['error'] ?? ''));
same('api: it brings the questions back', 5, count($apiRead['questions'] ?? []));

$apiRun = $api->handle('benchmark.run', ['tenant_id' => 7, 'options' => ['file' => $written['path']]]);
check('api: the run answers', ($apiRun['ok'] ?? false) === true, (string) ($apiRun['error'] ?? ''));
same('api: every case was measured', 5, (int) ($apiRun['benchmark']['total'] ?? 0));
check('api: the metrics are scored', isset($apiRun['benchmark']['metrics']['routing']['accuracy']), json_encode($apiRun['benchmark'] ?? []));

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
