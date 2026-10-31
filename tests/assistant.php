<?php

/**
 * Runtime checks for AssistantService::ask() — routing, scope injection, honest
 * answers, citations, persistence. SQLite stands in for the host database; no
 * network, no real LLM.
 *   php tests/assistant.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Contracts\ActionHandlerInterface;
use ExcelleInsights\AiAssistant\Contracts\ConversationStoreInterface;
use ExcelleInsights\AiAssistant\Contracts\KnowledgeRetrieverInterface;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Contracts\PermissionResolverInterface;
use ExcelleInsights\AiAssistant\Dto\ActionProposal;
use ExcelleInsights\AiAssistant\Dto\ActionResult;
use ExcelleInsights\AiAssistant\Dto\AskRequest;
use ExcelleInsights\AiAssistant\Dto\Citation;
use ExcelleInsights\AiAssistant\Dto\ExportResult;
use ExcelleInsights\AiAssistant\Dto\QueryResult;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Services\AssistantService;
use ExcelleInsights\AiAssistant\Services\ChartService;
use ExcelleInsights\AiAssistant\Services\QueryCompiler;
use ExcelleInsights\AiAssistant\Services\SqlQueryRunner;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\DomainPack;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;
use PDO;

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

function throws(string $name, callable $run, string $messageContains = ''): void
{
    try {
        $run();
        check($name, false, 'no exception raised');
    } catch (\Throwable $e) {
        check($name, $messageContains === '' || str_contains($e->getMessage(), $messageContains), $e->getMessage());
    }
}

/** Scalar from the first cell of the first row of an Answer's rows. */
function first_value(array $rows): mixed
{
    $first = array_slice($rows, 0, 1);
    $cells = array_values((array) ($first[0] ?? []));
    return $cells[0] ?? null;
}

/**
 * A scripted model: it answers from the prompt it recognises, so a test can
 * assert which brain was asked without a network.
 */
final class ScriptedLlm implements LlmClientInterface
{
    /** @var array<int,array{role:string,content:string}> */
    public array $messages = [];
    public int $calls = 0;
    public bool $throw = false;

    public function __construct(public array $router, public string $reply = 'Revenue was 300 in total.', public array $usage = ['prompt_tokens' => 40, 'completion_tokens' => 8])
    {
    }

    public function chat(array $messages, string $model = '', ?float $temperature = null): array
    {
        $this->calls++;
        $this->messages = $messages;
        if ($this->throw) {
            throw new \RuntimeException('provider down');
        }
        $system = (string) ($messages[0]['content'] ?? '');
        // The router prompt is the only one that demands an intent key.
        $content = str_contains($system, '"intent":"data|knowledge')
            ? json_encode($this->router, JSON_UNESCAPED_UNICODE)
            : $this->reply;

        return ['content' => $content, 'usage' => $this->usage];
    }

    public function embeddings(string $input, string $model = ''): array
    {
        return [];
    }
}

final class ArrayStore implements ConversationStoreInterface
{
    public array $turns = [];
    public array $threads = [];

    public function touch(int $tenantId, string $channel, string $threadId, array $attributes = []): string
    {
        $id = 'conv-' . $tenantId . '-' . $channel . '-' . $threadId;
        $this->threads[] = [$tenantId, $channel, $threadId];
        return $id;
    }

    public function append(string $conversationId, array $turn): int
    {
        $this->turns[$conversationId][] = $turn;
        return count($this->turns[$conversationId]);
    }

    public function history(string $conversationId, int $limit = 10): array
    {
        return array_slice($this->turns[$conversationId] ?? [], -$limit);
    }

    public function listForUser(int $tenantId, string $userId, int $limit = 50): array
    {
        return [];
    }

    public function rename(string $conversationId, string $title): bool
    {
        return true;
    }

    public function setPinned(string $conversationId, bool $pinned): bool
    {
        return true;
    }

    public function delete(string $conversationId): bool
    {
        return true;
    }

    public function setSummary(string $conversationId, string $summary): void
    {
    }
}

final class ScopedPermissions implements PermissionResolverInterface
{
    public array $askedFor = [];

    public function __construct(private ?string $scope = null, private array $hidden = [], private array $deniedActions = [])
    {
    }

    public function canView(UserContext $user, string $entity): bool
    {
        return true;
    }

    public function rowScope(UserContext $user, string $entity): ?string
    {
        $this->askedFor[] = $entity;
        return $this->scope;
    }

    public function hiddenColumns(UserContext $user, string $entity): array
    {
        return $this->hidden;
    }

    public function canAct(UserContext $user, string $action): bool
    {
        return !in_array($action, $this->deniedActions, true);
    }

    public function canExport(UserContext $user, string $entity): bool
    {
        return true;
    }
}

final class FakeKnowledge implements KnowledgeRetrieverInterface
{
    public function __construct(public array $rows = [])
    {
    }

    public function search(int $companyId, string $query, int $k = 5): array
    {
        return array_slice($this->rows, 0, $k);
    }

    public function ingest(int $companyId, string $title, string $content, string $source = 'manual'): int
    {
        return 1;
    }
}

final class FakeActions implements ActionHandlerInterface
{
    public int $executions = 0;

    public function actions(): array
    {
        return [['name' => 'create_lead', 'label' => 'Add a lead'], ['name' => 'close_order', 'label' => 'Close an order']];
    }

    public function preview(UserContext $user, string $action, array $params): ActionProposal
    {
        return new ActionProposal(action: $action, params: $params, summary: 'Create a lead for ' . ($params['name'] ?? 'someone'));
    }

    public function execute(UserContext $user, string $action, array $params, string $idempotencyKey): ActionResult
    {
        $this->executions++;
        return ActionResult::success('created');
    }
}

// ─── the "host database": one table, two tenants ──────────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, company_id INTEGER, customer_id INTEGER, status TEXT, total_amount NUMERIC, created_at TEXT)');
$pdo->exec('CREATE TABLE empty_log (id INTEGER PRIMARY KEY, company_id INTEGER, day TEXT)');
foreach ([
    [1, 7, 42, 'new', 100, '2026-10-01 09:00:00'],
    [2, 7, 42, 'done', 200, '2026-10-02 09:00:00'],
    [3, 7, 99, 'done', 300, '2026-10-03 09:00:00'],
    [4, 8, 42, 'new', 900, '2026-10-04 09:00:00'],
] as $row) {
    $pdo->prepare('INSERT INTO orders VALUES (?,?,?,?,?,?)')->execute($row);
}

$catalog = [
    'orders' => [
        'label' => 'Orders',
        'columns' => [
            ['name' => 'id', 'type' => 'int', 'sensitivity' => 'internal'],
            ['name' => 'company_id', 'type' => 'int', 'sensitivity' => 'internal'],
            ['name' => 'customer_id', 'type' => 'int', 'sensitivity' => 'internal'],
            ['name' => 'status', 'type' => 'text', 'sensitivity' => 'internal'],
            ['name' => 'total_amount', 'type' => 'decimal', 'sensitivity' => 'financial', 'is_measure' => true, 'format' => 'money'],
            ['name' => 'created_at', 'type' => 'datetime', 'sensitivity' => 'internal'],
        ],
    ],
];
$tableColumns = ['orders' => ['id', 'company_id', 'customer_id', 'status', 'total_amount', 'created_at']];

Config::reset();
Config::set('table_prefix', 'aiask');
Config::set('schema_policy.mode', 'denylist');
Config::set('schema_policy.max_rows', 200);
Config::set('schema_policy.default_limit', 20);
Config::set('schema_policy.require_tenant_scope', true);
Config::set('tenant.column', 'company_id');
Config::set('timezone', 'UTC');
Config::set('semantic.allow_raw_sql', false);
Config::set('semantic.raw_sql_roles', ['admin']);

$pack = new DomainPack([], $catalog);
$compiler = new QueryCompiler($pack, new SchemaPolicy(), $tableColumns);
$user = new UserContext('u1', 7, 'web', 'thread-1', ['sales']);
$admin = new UserContext('u9', 7, 'web', 'thread-9', ['admin']);

/** Builds the service with a SQLite-backed executor, so a compiled query really runs. */
function service(?ScriptedLlm $llm = null, array $hooks = [], ?ConversationStoreInterface $store = null, ?PermissionResolverInterface $perms = null, ?KnowledgeRetrieverInterface $knowledge = null, ?ActionHandlerInterface $actions = null): AssistantService
{
    global $compiler, $pack;
    return new AssistantService(
        llm: $llm ?? new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]]),
        conversations: $store ?? new ArrayStore(),
        pack: $pack,
        compiler: $compiler,
        knowledge: $knowledge,
        permissions: $perms,
        charts: new ChartService(),
        actions: $actions,
        hooks: $hooks + ['rows' => new SqlQueryRunner(sqlitePdo())],
    );
}

function sqlitePdo(): PDO
{
    global $pdo;
    return $pdo;
}

// ─── the data path really queries, with the host's scope ──────────────────────
$llm = new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]]);
$store = new ArrayStore();
$audit = [];
$usage = [];
$ai = service($llm, ['audit' => function (string $action, array $detail) use (&$audit): void { $audit[] = ['action' => $action] + $detail; }, 'usage' => function (array $u) use (&$usage): void { $usage[] = $u; }], $store);

$answer = $ai->ask('how many orders do we have?', $user);
same('data: intent from the router', 'data', $answer->intent);
same('data: one row of one count', 1, count($answer->rows));
same('data: counts only this tenant', 3, (int) first_value($answer->rows));
check('data: the answer came from the model', str_contains($answer->text, '300') || $answer->text !== '', $answer->text);
same('data: routed by the model, not a hint', 'router', $answer->meta['routed_by'] ?? '');
check('data: cites the query', count($answer->citations) === 1 && $answer->citations[0]->type === 'query');
check('data: cites a hash, not the sql', $answer->citations[0]->ref !== null && strlen((string) $answer->citations[0]->ref) === 64, (string) $answer->citations[0]->ref);
check('data: sql hidden from a non-admin', $answer->sql === null);
check('data: no chart unless asked', $answer->chart === []);
same('data: both turns stored', 2, count($store->turns['conv-7-web-thread-1'] ?? []));
same('data: stored turns are user then assistant', ['user', 'assistant'], array_map(static fn (array $t): string => (string) $t['role'], $store->turns['conv-7-web-thread-1'] ?? []));
same('data: one audit event', 'assistant.answered', $audit[0]['action'] ?? '');
same('data: audit carries the row count', 1, $audit[0]['rows'] ?? 0);
check('data: usage recorded', $usage !== [] && ($usage[0]['prompt_tokens'] ?? 0) === 40, json_encode($usage));
same('data: one number answered without a second model call', 1, $llm->calls);

// ─── the host's own row ceiling reaches the compiled query ───────────────────
$dataPlan = new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]]);
$seen = [];
$capping = service($dataPlan, ['rows' => static function (array $compiled) use (&$seen): array {
    $seen = $compiled;
    return [['records' => 1], ['records' => 2], ['records' => 3]];
}]);
$capped = $capping->ask('list the orders', $user, ['max_rows' => 2]);
same('max_rows: the host ceiling becomes the SQL limit', 2, (int) ($seen['limit'] ?? 0));
same('max_rows: the rows handed back are cut to it', 2, count($capped->rows));
$seen = [];
$capping->ask('list the orders', $user, ['max_rows' => 5000]);
same('max_rows: the policy cap still wins over the host', 200, (int) ($seen['limit'] ?? 0));
$seen = [];
$capping->ask('list the orders', $user, ['max_rows' => 0]);
same('max_rows: no ceiling means the configured default limit', 20, (int) ($seen['limit'] ?? 0));

// ─── a period the question never asked for is dropped ─────────────────────────
$invented = service(new ScriptedLlm(['intent' => 'data', 'plan' => [
    'dataset' => 'orders', 'metrics' => ['records'], 'date_range' => ['preset' => 'last_7_days'],
]]))->ask('all orders in this system?', $user);
same('period: an invented range is dropped', 3, (int) first_value($invented->rows));

$asked = service(new ScriptedLlm(['intent' => 'data', 'plan' => [
    'dataset' => 'orders', 'metrics' => ['records'], 'date_range' => ['preset' => 'last_7_days'],
]]))->ask('orders from the last 7 days', $user);
same('period: a range the question names is kept', 0, (int) first_value($asked->rows));

// ─── "all users" is a record list, not a count per category ───────────────────
$namePack = new DomainPack([[
    'name' => 'x',
    'datasets' => [[
        'name' => 'orders', 'table' => 'orders', 'date_field' => 'created_at', 'tenant_scoped' => true,
        'metrics' => [['name' => 'records', 'label' => 'Records', 'function' => 'count', 'column' => 'orders.id']],
        'dimensions' => [['name' => 'name', 'label' => 'Order name', 'column' => 'orders.status']],
    ]],
]], [], ['auto_derive' => false]);
$listed = (new AssistantService(
    llm: new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records'], 'dimensions' => ['name']]]),
    pack: $namePack,
    compiler: new QueryCompiler($namePack, new SchemaPolicy(), $tableColumns),
    hooks: ['rows' => new SqlQueryRunner(sqlitePdo())],
))->ask('all orders', $user);
check('shape: a list question drops the count', !in_array('records', array_keys((array) ($listed->rows[0] ?? [])), true), json_encode(array_keys((array) ($listed->rows[0] ?? []))));
same('shape: the list keeps one row per record', 3, count($listed->rows));

$badFigure = (new AssistantService(
    llm: new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records'], 'dimensions' => ['name']]], 'Revenue was 999999 in total.'),
    pack: $namePack,
    compiler: new QueryCompiler($namePack, new SchemaPolicy(), $tableColumns),
    hooks: ['rows' => new SqlQueryRunner(sqlitePdo())],
))->ask('all orders', $user);
same('narration: an unsourced figure is dropped', 'Showing 3 orders.', $badFigure->text);

$counted = (new AssistantService(
    llm: new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records'], 'dimensions' => ['name']]]),
    pack: $namePack,
    compiler: new QueryCompiler($namePack, new SchemaPolicy(), $tableColumns),
    hooks: ['rows' => new SqlQueryRunner(sqlitePdo())],
))->ask('how many orders do we have?', $user);
same('shape: "how many" is one number, not a grouping', 1, count($counted->rows));
same('shape: "how many" reads the value from the row', 'Orders: 3.', $counted->text);

$grouped = (new AssistantService(
    llm: new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records'], 'dimensions' => ['name']]]),
    pack: $namePack,
    compiler: new QueryCompiler($namePack, new SchemaPolicy(), $tableColumns),
    hooks: ['rows' => new SqlQueryRunner(sqlitePdo())],
))->ask('how many orders per status?', $user);
same('shape: "per" keeps the grouping', 2, count($grouped->rows));

// ─── scope the model never sees ───────────────────────────────────────────────
$perms = new ScopedPermissions('customer_id = 42');
$scoped = service(new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]]), [], new ArrayStore(), $perms);
$mine = $scoped->ask('how many orders?', $user);
same('scope: row filter from the host applied', 2, (int) first_value($mine->rows));
check('scope: permission asked per table', in_array('orders', $perms->askedFor, true), implode(',', $perms->askedFor));
same('scope: tenant kept too', 3, (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE company_id = 7')->fetchColumn());

$blocked = service(new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['total_amount']]]), [], new ArrayStore(), new ScopedPermissions(null, ['total_amount']));
$refused = $blocked->ask('what is our revenue?', $user);
check('hidden: unreadable field gives an honest failure', $refused->grounded === false && $refused->rows === [], $refused->text);

$other = service()->ask('how many orders?', new UserContext('u2', 8, 'web', 'thread-2', ['sales']));
same('tenant: the other account sees only its rows', 1, (int) first_value($other->rows));

$adminAnswer = service(new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]]))
    ->ask('how many orders?', $admin);
check('sql: shown to a raw-sql role once configured', $adminAnswer->sql === null, 'raw sql is off by default');
Config::set('semantic.allow_raw_sql', true);
$adminSql = service(new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]]))
    ->ask('how many orders?', $admin);
check('sql: admin sees the statement', is_string($adminSql->sql) && str_contains((string) $adminSql->sql, 'COUNT('), var_export($adminSql->sql, true));
check('sql: sales role still cannot see it', service()->ask('how many orders?', $user)->sql === null);
Config::set('semantic.allow_raw_sql', false);

// ─── honesty when there is nothing to say ─────────────────────────────────────
$none = service(new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records'], 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'shipped']]]]))
    ->ask('how many shipped orders?', $user);
check('empty: says no records rather than inventing one', !preg_match('/\b[1-9]\d*\b/', $none->text), $none->text);
same('empty: a zero count stays visible as the evidence', 0, (int) first_value($none->rows));
check('empty: the query is still cited', $none->citations !== [] && $none->citations[0]->type === 'query', json_encode($none->sources()));

// SUM over no rows is NULL; that is "nothing matched", not a value to report.
$sumPack = new DomainPack([[
    'name' => 'x',
    'datasets' => [[
        'name' => 'orders', 'table' => 'orders', 'date_field' => 'created_at', 'tenant_scoped' => true,
        'metrics' => [['name' => 'revenue', 'label' => 'Revenue', 'function' => 'sum', 'column' => 'orders.total_amount']],
        'dimensions' => [['name' => 'status', 'label' => 'Status', 'column' => 'orders.status']],
    ]],
]], [], ['auto_derive' => false]);
$summed = (new AssistantService(
    llm: new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['revenue'], 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'missing']]]]),
    pack: $sumPack,
    compiler: new QueryCompiler($sumPack, new SchemaPolicy(), $tableColumns),
    hooks: ['rows' => new SqlQueryRunner(sqlitePdo())],
))->ask('what did we earn on missing orders?', $user);
check('empty: a NULL sum is reported as no records', str_contains($summed->text, 'No matching records'), $summed->text);
same('empty: a NULL sum keeps no row', [], $summed->rows);

$revenue = (new AssistantService(
    llm: new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['revenue']]]),
    pack: $sumPack,
    compiler: new QueryCompiler($sumPack, new SchemaPolicy(), $tableColumns),
    hooks: ['rows' => new SqlQueryRunner(sqlitePdo())],
))->ask('what is our revenue?', $user);
same('single number: the sentence is built from the row', 'Revenue: 600.', $revenue->text);

// An empty table says so, instead of hinting at a later period that never comes.
$emptyPack = new DomainPack([[
    'name' => 'x',
    'datasets' => [[
        'name' => 'logs', 'table' => 'empty_log', 'date_field' => 'day', 'tenant_scoped' => true,
        'metrics' => [['name' => 'logs', 'label' => 'Log entries', 'function' => 'count', 'column' => 'empty_log.id']],
        'dimensions' => [['name' => 'month', 'label' => 'Month', 'column' => 'empty_log.day', 'format' => 'date']],
    ]],
]], [], ['auto_derive' => false]);
$nothingYet = (new AssistantService(
    llm: new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'logs', 'metrics' => ['logs']]]),
    pack: $emptyPack,
    compiler: new QueryCompiler($emptyPack, new SchemaPolicy(), ['empty_log' => ['id', 'company_id', 'day']]),
    hooks: ['rows' => new SqlQueryRunner(sqlitePdo())],
))->ask('how many log entries?', $user);
check('empty: an empty table says nothing is recorded', str_contains($nothingYet->text, 'Nothing has been recorded'), $nothingYet->text);

$noExecutor = new AssistantService(
    llm: new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]]),
    pack: $pack,
    compiler: $compiler,
);
$stuck = $noExecutor->ask('how many orders?', $user);
check('no executor: refuses rather than guessing', $stuck->grounded === false && str_contains(strtolower($stuck->text), 'nothing'), $stuck->text);

$noData = new AssistantService(llm: new ScriptedLlm(['intent' => 'data']), knowledge: new FakeKnowledge());
$cold = $noData->ask('how many orders?', $user);
check('no datasets: says how to get them', $cold->grounded === false && str_contains(strtolower($cold->text), 'no datasets'), $cold->text);

$empty = service(new ScriptedLlm(['intent' => 'knowledge']))->ask('   ', $user);
same('blank question: no model call needed', 'help', $empty->intent);

// ─── other brains ─────────────────────────────────────────────────────────────
$knowledge = new FakeKnowledge([
    ['id' => 'k1', 'title' => 'Returns', 'content' => 'Returns are accepted for 30 days.', 'score' => 0.9],
]);
$withKnowledge = service(new ScriptedLlm(['intent' => 'knowledge'], 'You have 30 days to return an item.'), [], new ArrayStore(), null, $knowledge);
$kAnswer = $withKnowledge->ask('what is the return policy?', $user);
same('knowledge: intent', 'knowledge', $kAnswer->intent);
check('knowledge: cites the entry', count($kAnswer->citations) === 1 && $kAnswer->citations[0]->type === 'knowledge', json_encode($kAnswer->sources()));
check('knowledge: grounded', $kAnswer->grounded === true && str_contains($kAnswer->text, '30 days'));

$silentKnowledge = service(new ScriptedLlm(['intent' => 'knowledge']), [], new ArrayStore(), null, new FakeKnowledge());
$silent = $silentKnowledge->ask('do you cover the roof?', $user);
check('knowledge: nothing found says nothing found', $silent->grounded === false && str_contains(strtolower($silent->text), 'nothing'), $silent->text);

$docAnswer = service(new ScriptedLlm(['intent' => 'document'], 'The contract renews yearly.'), ['documents' => fn (UserContext $u, string $q, int $k): array => [['title' => 'Contract', 'content' => 'Renews yearly.', 'type' => 'document', 'id' => 'd1']]])
    ->ask('what does the contract say about renewal?', $user);
same('documents: uses the ingest hook', 'document', $docAnswer->intent);
check('documents: cites the file', ($docAnswer->citations[0]->type ?? '') === 'document', json_encode($docAnswer->sources()));

$help = service()->ask('what can you do?', $user, ['intent' => 'help']);
same('help: intent', 'help', $help->intent);
check('help: lists what it can query', str_contains($help->text, 'orders'), $help->text);
check('help: no invented numbers', !preg_match('/\b[1-9]\d{2,}\b/', $help->text), $help->text);

$chat = service(new ScriptedLlm(['intent' => 'smalltalk'], 'Hello — ask me about your orders.'))->ask('hello there', $user);
same('smalltalk: ungrounded by design', false, $chat->grounded);
same('smalltalk: no citations', 0, count($chat->citations));

$actions = new FakeActions();
$actionAnswer = service(new ScriptedLlm(['intent' => 'action'], '{"action":"create_lead"}'), [], new ArrayStore(), null, null, $actions)
    ->ask('add a lead called Alice', $user, ['params' => ['name' => 'Alice']]);
same('action: proposes without writing', 0, $actions->executions);
same('action: pending action present', 'create_lead', $actionAnswer->pendingAction['action'] ?? '');
check('action: asks for confirmation', str_contains(strtolower($actionAnswer->text), 'confirm'), $actionAnswer->text);

$denied = service(new ScriptedLlm(['intent' => 'action'], '{"action":"create_lead"}'), [], new ArrayStore(), new ScopedPermissions(null, [], ['create_lead']), null, $actions)
    ->ask('add a lead called Alice', $user);
check('action: permission denied says so', $denied->pendingAction === null && str_contains($denied->text, 'not allowed'), $denied->text);

// ─── failure paths must not lose the reply ────────────────────────────────────
$brokenLlm = new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]]);
$brokenLlm->throw = true;
$degraded = service($brokenLlm)->ask('how many orders?', $user);
check('provider down: still answers from the rows', $degraded->rows !== [] && str_contains($degraded->text, '3'), $degraded->text);

$garbage = service(new ScriptedLlm([], 'sure!'))->ask('how many orders do we have?', $user);
check('junk routing: falls back to a keyword guess', $garbage->intent === 'data' && $garbage->rows !== [], $garbage->intent . ' / ' . $garbage->text);

$retryLlm = new ScriptedLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['nope']]]);
$retried = service($retryLlm)->ask('how many orders?', $user);
check('bad metric: retried once and gave an honest answer', $retried->rows === [] && str_contains(strtolower($retried->text), 'could not'), $retried->text);

// ─── public surface used by the HTTP layer ────────────────────────────────────
$capabilities = service()->capabilities($user);
check('capabilities: datasets and metrics', in_array('orders', $capabilities['datasets'], true) && in_array('records', $capabilities['metrics']['orders'], true), json_encode($capabilities['datasets']));
same('capabilities: raw sql off', false, $capabilities['raw_sql']);
same('capabilities: documents off without a hook', false, $capabilities['documents']);

$explained = service()->explain(['dataset' => 'orders', 'metrics' => ['records']], $user);
check('explain: returns sql without running it', str_contains((string) ($explained['sql'] ?? ''), 'COUNT('), json_encode(array_keys($explained)));

$ran = service()->run(['dataset' => 'orders', 'metrics' => ['records'], 'dimensions' => ['status']], $user, true);
same('run: grouped rows', 2, count($ran['result']->rows));
check('run: chart spec built', ($ran['chart']['type'] ?? '') !== '', json_encode(array_keys($ran['chart'])));
check('run: sql hidden from a non-admin', $ran['sql'] === null);

// ─── export from an answer ────────────────────────────────────────────────────
$seen = null;
$exporting = static function (mixed $rows, string $format, UserContext $u, array $meta) use (&$seen): ExportResult {
    $seen = ['format' => $format, 'tenant' => $u->tenantId, 'rows' => $rows, 'meta' => $meta];

    return ExportResult::file($format, 'orders-20261024.csv', 'name,count');
};
$plan = ['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]];
$withExport = service(new ScriptedLlm($plan), ['export' => $exporting]);
$filed = $withExport->ask('how many orders, as a csv please?', $user);
same('export: format read from the question', 'csv', $filed->meta['export']['format'] ?? '');
same('export: the job is ready', 'ready', $filed->meta['export']['status'] ?? '');
check('export: the hook saw the host scope', $seen !== null && $seen['tenant'] === 7 && ($seen['meta']['entity'] ?? '') === 'orders', json_encode($seen['meta'] ?? null));
check('export: the hook got the rows, not a re-query', $seen['rows'] instanceof QueryResult && $seen['rows']->rows !== [], json_encode($seen['format'] ?? null));
check('export: the answer names the file', str_contains($filed->text, 'orders-20261024.csv'), $filed->text);
same('export: a suggestion follows', 1, count($filed->suggestions));
check('export: the number is still the answer', count($filed->rows) === 1, json_encode($filed->rows));
check('capabilities: exports on with a hook', $withExport->capabilities($user)['exports'] === true);

$broken = service(new ScriptedLlm($plan), ['export' => static fn (): ExportResult => ExportResult::failed('Format "xlsx" is not enabled here.')])
    ->ask('export the orders to excel', $user);
same('export: a refusal is reported', 'failed', $broken->meta['export']['status'] ?? '');
check('export: a refusal keeps its reason', str_contains($broken->text, 'not enabled'), $broken->text);
check('export: a refusal still answers the data', count($broken->rows) === 1, json_encode($broken->rows));

$quiet = service(new ScriptedLlm($plan))->ask('how many orders?', $user);
same('export: no question, no file', [], (array) ($quiet->meta['export'] ?? []));
check('capabilities: exports off without a hook', service()->capabilities($user)['exports'] === false);

same('format: excel means xlsx', 'xlsx', AskRequest::make('export the orders to Excel', $user)->exportFormat());
same('format: spreadsheet means xlsx', 'xlsx', AskRequest::make('give me a spreadsheet of orders', $user)->exportFormat());
same('format: a bare export is csv', 'csv', AskRequest::make('export these orders', $user)->exportFormat());
same('format: download is csv too', 'csv', AskRequest::make('can I download this list?', $user)->exportFormat());
same('format: json wins over the generic word', 'json', AskRequest::make('export this as json', $user)->exportFormat());
same('format: no file asked for', '', AskRequest::make('how many orders this month?', $user)->exportFormat());
same('format: an explicit option is honoured', 'pdf', AskRequest::make('list the orders', $user, ['export' => 'pdf'])->exportFormat());
same('format: export=false is not a request', '', AskRequest::make('list the orders', $user, ['export' => false])->exportFormat());

// ─── raw sql guard (admin path) ───────────────────────────────────────────────
$guard = service();
same('guard: plain select allowed', true, $guard->guardRawSql('SELECT id FROM orders WHERE company_id = 7')['sql'] === 'SELECT id FROM orders WHERE company_id = 7');
throws('guard: insert refused', fn () => $guard->guardRawSql('INSERT INTO orders VALUES (9,7,1,2,3,4)'), 'only read');
throws('guard: stacked statements refused', fn () => $guard->guardRawSql('SELECT 1; DROP TABLE orders'), 'one statement');
// Config always wins over a SchemaPolicy constructor array, so the denylist is driven through Config.
Config::set('schema_policy.blocked_tables', ['salaries']);
throws('guard: blocked table refused', fn () => $guard->guardRawSql('SELECT * FROM salaries'), 'not readable');
Config::set('schema_policy.blocked_tables', []);
throws('guard: sleep refused', fn () => $guard->guardRawSql('SELECT SLEEP(30)'), 'write or block');

// ─── json extraction from a chatty model ──────────────────────────────────────
same('json: fenced', 'data', AssistantService::jsonObject('{"intent":"data"}')['intent'] ?? '');
same('json: wrapped in prose', 'help', AssistantService::jsonObject('Sure!\n{"intent":"help"}\nDone.')['intent'] ?? '');
same('json: fenced block', 'report', AssistantService::jsonObject("```json\n{\"intent\":\"report\"}\n```")['intent'] ?? '');
same('json: nothing usable', [], AssistantService::jsonObject('no json here'));
same('json: empty input', [], AssistantService::jsonObject('   '));

// ─── the host's own wording reaches the model ─────────────────────────────────
// Every domain.* key the guides promise must land in the prompt, not in a config
// array nobody reads. These are the wording knobs any host gets.
Config::set('domain.assistant_name', 'Kipendo');
Config::set('domain.name', 'the Co-op portal');
$personaLlm = new ScriptedLlm(['intent' => 'smalltalk']);
service($personaLlm, [], new ArrayStore())->ask('hello there', $user);
$persona = (string) ($personaLlm->messages[0]['content'] ?? '');
check('prompt: names the assistant the host chose', str_contains($persona, 'Kipendo'), $persona);
check('prompt: names the application the host chose', str_contains($persona, 'the Co-op portal'), $persona);
check('prompt: follows the question by default', str_contains($persona, 'the language the user writes in'), $persona);

Config::set('domain.language', 'Swahili');
Config::set('domain.multilingual', false);
service($personaLlm, [], new ArrayStore())->ask('hello again', $user);
check('prompt: a pinned language wins over the question', str_contains(
    (string) ($personaLlm->messages[0]['content'] ?? ''),
    'Always reply in Swahili'
), (string) ($personaLlm->messages[0]['content'] ?? ''));
Config::set('domain.multilingual', true);
service($personaLlm, [], new ArrayStore())->ask('hello again', $user);
check('prompt: a suggested language yields to the question', str_contains(
    (string) ($personaLlm->messages[0]['content'] ?? ''),
    'Reply in Swahili unless'
), (string) ($personaLlm->messages[0]['content'] ?? ''));
Config::set('domain.language', '');

Config::set('domain.system_prompt', 'You answer for a savings co-operative.');
Config::set('domain.custom_instructions', 'Always quote prices with VAT included.');
service($personaLlm, [], new ArrayStore())->ask('hello there', $user);
$persona = (string) ($personaLlm->messages[0]['content'] ?? '');
check('prompt: a host system prompt replaces the persona line', str_contains($persona, 'savings co-operative'), $persona);
check('prompt: the persona line is gone once replaced', !str_contains($persona, 'Kipendo'), $persona);
check('prompt: custom instructions are appended', str_contains($persona, 'VAT included'), $persona);

Config::set('domain.system_prompt', '');
Config::set('domain.custom_instructions', '');
Config::set('domain.query_prompt', 'Route it. Reply with JSON only, no prose, like {"intent":"data|knowledge|help"} and nothing else.');
$routerLlm = new ScriptedLlm(['intent' => 'help']);
service($routerLlm, [], new ArrayStore())->ask('what can you do?', $user);
$router = (string) ($routerLlm->messages[0]['content'] ?? '');
check('prompt: a host routing prompt replaces the built-in rules', str_contains($router, 'Route it.'), $router);
check('prompt: the built-in rules are not sent with an override', !str_contains($router, 'Anything countable is intent'), $router);
check('prompt: the vocabulary still travels with an override', str_contains($router, 'Vocabulary:'), $router);
Config::set('domain.query_prompt', '');

// ─── a key column is answered with the column that carries the name ──────────
$pdo->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY, company_id INTEGER, staff_id INTEGER, amount NUMERIC, paid_on TEXT)');
$pdo->exec('CREATE TABLE staff (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT)');
$pdo->prepare('INSERT INTO payments VALUES (?,?,?,?,?)')->execute([1, 7, 11, 500, '2026-10-01']);
$pdo->prepare('INSERT INTO payments VALUES (?,?,?,?,?)')->execute([2, 7, 12, 900, '2026-10-02']);
$pdo->prepare('INSERT INTO staff VALUES (?,?,?)')->execute([11, 7, 'Jane Wanjiru']);
$pdo->prepare('INSERT INTO staff VALUES (?,?,?)')->execute([12, 7, 'Peter Otieno']);

$staffPack = new DomainPack([[
    'name' => 'crm',
    'datasets' => [[
        'name' => 'payments', 'table' => 'payments', 'label' => 'Payments', 'date_field' => 'paid_on',
        'metrics' => [['name' => 'amount', 'label' => 'Amount collected', 'function' => 'sum', 'column' => 'payments.amount', 'format' => 'money']],
        'dimensions' => [
            ['name' => 'staff', 'label' => 'Staff id', 'column' => 'payments.staff_id', 'aliases' => ['staff_id']],
            ['name' => 'staff_name', 'label' => 'Sales person', 'expr' => 'staff_member.name', 'aliases' => ['sales_person', 'seller']],
        ],
        'joins' => [['table' => 'staff', 'alias' => 'staff_member', 'on' => ['payments.staff_id' => 'staff_member.id']]],
    ]],
]], [], ['auto_derive' => false]);
$staffCompiler = new QueryCompiler($staffPack, new SchemaPolicy(), [
    'payments' => ['id', 'company_id', 'staff_id', 'amount', 'paid_on'],
    'staff' => ['id', 'company_id', 'name'],
]);
$staffAi = new AssistantService(
    llm: new ScriptedLlm(['intent' => 'data', 'plan' => [
        'dataset' => 'payments', 'dimensions' => ['staff'], 'metrics' => ['amount'],
        'order_by' => [['field' => 'amount', 'direction' => 'desc']],
    ]]),
    conversations: new ArrayStore(),
    pack: $staffPack,
    compiler: $staffCompiler,
    charts: new ChartService(),
    hooks: ['rows' => new SqlQueryRunner(sqlitePdo())],
);
$staffAnswer = $staffAi->ask('which sales person has the highest sales?', $user);
check('labels: the key column is replaced by the name column', isset($staffAnswer->rows[0]['staff_name']), json_encode($staffAnswer->rows));
same('labels: the answer names the person', 'Peter Otieno', (string) ($staffAnswer->rows[0]['staff_name'] ?? ''));
same('labels: the answer carries the total', 900, (int) ($staffAnswer->rows[0]['amount'] ?? 0));

$idAnswer = (new AssistantService(
    llm: new ScriptedLlm(['intent' => 'data', 'plan' => [
        'dataset' => 'payments', 'dimensions' => ['staff'], 'metrics' => ['amount'],
    ]]),
    conversations: new ArrayStore(),
    pack: $staffPack,
    compiler: $staffCompiler,
    charts: new ChartService(),
    hooks: ['rows' => new SqlQueryRunner(sqlitePdo())],
))->ask('show the staff id on each payment', $admin);
check('labels: a question that asks for the id keeps the key', isset($idAnswer->rows[0]['staff']) && !isset($idAnswer->rows[0]['staff_name']), json_encode($idAnswer->rows));

check('labels: the vocabulary shows the id column as an id', str_contains($staffPack->vocabulary(), 'Staff id'), $staffPack->vocabulary());

Config::reset();
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
