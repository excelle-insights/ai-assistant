<?php

/**
 * Contract checks for AssistantApi — the plain-array surface any host puts in
 * front of the package. SQLite stands in for the host database; the model is
 * scripted, so nothing reaches the network.
 *
 *   php tests/api.php
 *
 * What is asserted here is the wire, not the brains: every call answers with
 * `ok` plus a payload or a sentence, uploads accept the shapes a PHP host really
 * has, and the assembly rules (injected services win, missing pieces report
 * themselves instead of crashing) hold.
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Contracts\ActionHandlerInterface;
use ExcelleInsights\AiAssistant\Contracts\DocumentStoreInterface;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Dto\ActionProposal;
use ExcelleInsights\AiAssistant\Dto\ActionResult;
use ExcelleInsights\AiAssistant\Dto\UploadedFile;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Http\AssistantApi;
use ExcelleInsights\AiAssistant\Services\ExportService;
use ExcelleInsights\AiAssistant\Services\SchemaService;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
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

function okAnswer(string $name, array $reply, string $detail = ''): void
{
    check($name, ($reply['ok'] ?? false) === true, ($reply['error'] ?? '') . ' ' . $detail);
}

function badAnswer(string $name, array $reply, string $contains = ''): void
{
    check($name, ($reply['ok'] ?? true) === false, json_encode($reply));
    if ($contains !== '') {
        check($name . ' says why', str_contains(strtolower((string) ($reply['error'] ?? '')), strtolower($contains)), (string) ($reply['error'] ?? ''));
    }
}

function tbl(string $suffix): string
{
    return Db::table($suffix);
}

/** The catalog is a list of columns in database order — look one up by name. */
function columnOf(array $tables, string $table, string $column): array
{
    foreach ((array) ($tables[$table]['columns'] ?? []) as $c) {
        if ((string) ($c['name'] ?? '') === $column) {
            return (array) $c;
        }
    }

    return [];
}

// ─── the host database, with every package table the migrations create ────────

function apiDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec('CREATE TABLE `' . tbl('conversations') . '` (
        id TEXT PRIMARY KEY, company_id INTEGER DEFAULT 0, user_id TEXT, channel TEXT DEFAULT "web",
        thread_key TEXT, title TEXT, pinned INTEGER DEFAULT 0, shared_token TEXT, context_json TEXT,
        summary TEXT, message_count INTEGER DEFAULT 0, last_activity_at TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE UNIQUE INDEX `' . tbl('conv_thread') . '` ON `' . tbl('conversations') . '` (company_id, channel, thread_key)');
    $pdo->exec('CREATE TABLE `' . tbl('messages') . '` (
        id TEXT PRIMARY KEY, conversation_id TEXT, company_id INTEGER DEFAULT 0, user_id TEXT, seq INTEGER DEFAULT 0,
        role TEXT, content TEXT, content_type TEXT DEFAULT "text", intent TEXT, citations_json TEXT, data_json TEXT,
        chart_json TEXT, sql_hash TEXT, model TEXT, tokens_in INTEGER DEFAULT 0, tokens_out INTEGER DEFAULT 0,
        latency_ms INTEGER, status TEXT DEFAULT "ok", error TEXT, created_at TEXT, updated_at TEXT)');

    $pdo->exec('CREATE TABLE `' . tbl('knowledge') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, title TEXT, content TEXT,
        embedding TEXT, source TEXT DEFAULT "manual", status TEXT DEFAULT "active", created_at TEXT,
        updated_at TEXT, category TEXT, content_hash TEXT, scope TEXT DEFAULT "global", scope_key TEXT,
        doc_id TEXT, token_count INTEGER, metadata_json TEXT, updated_by TEXT, pinned INTEGER DEFAULT 0)');

    // Legacy table the old host service still writes to.
    $pdo->exec('CREATE TABLE `' . tbl('training') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 0, question TEXT, answer TEXT,
        created_at TEXT)');

    $pdo->exec('CREATE TABLE `' . tbl('schema') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, table_name TEXT NOT NULL, column_name TEXT NOT NULL,
        data_type TEXT, description TEXT, label TEXT, table_label TEXT, sensitivity TEXT DEFAULT "internal",
        is_measure INTEGER DEFAULT 0, format TEXT, aliases TEXT, description_source TEXT DEFAULT "auto",
        created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE UNIQUE INDEX `' . tbl('schema_col') . '` ON `' . tbl('schema') . '` (table_name, column_name)');

    $pdo->exec('CREATE TABLE `' . tbl('reference') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 0, source_table TEXT, label TEXT,
        summary TEXT, kind TEXT DEFAULT "lookup", row_count INTEGER DEFAULT 0, unit TEXT, values_json TEXT,
        created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE UNIQUE INDEX `' . tbl('ref_source') . '` ON `' . tbl('reference') . '` (company_id, source_table)');

    $pdo->exec('CREATE TABLE `' . tbl('documents') . '` (
        id TEXT PRIMARY KEY, company_id INTEGER DEFAULT 0, owner_user_id TEXT, scope TEXT DEFAULT "global",
        entity_type TEXT, entity_id TEXT, filename TEXT, mime TEXT, size INTEGER DEFAULT 0,
        storage_key TEXT, sha256 TEXT, status TEXT DEFAULT "uploaded", pages INTEGER DEFAULT 0,
        chunk_count INTEGER DEFAULT 0, language TEXT, acl_json TEXT, metadata_json TEXT, error TEXT,
        indexed_at TEXT, expires_at TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE `' . tbl('document_chunks') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, document_id TEXT, company_id INTEGER DEFAULT 0,
        chunk_no INTEGER DEFAULT 0, page_from INTEGER, page_to INTEGER, content TEXT,
        token_count INTEGER DEFAULT 0, embedding TEXT, metadata_json TEXT, created_at TEXT, updated_at TEXT)');

    $pdo->exec('CREATE TABLE `' . tbl('exports') . '` (
        id TEXT PRIMARY KEY, company_id INTEGER DEFAULT 0, user_id TEXT, conversation_id TEXT,
        format TEXT, file_name TEXT, title TEXT, question TEXT, columns_json TEXT, filters_json TEXT,
        plan_json TEXT, row_count INTEGER DEFAULT 0, size_bytes INTEGER DEFAULT 0, status TEXT DEFAULT "pending",
        storage_key TEXT, download_token TEXT UNIQUE, error TEXT, expires_at TEXT, completed_at TEXT,
        approved_by TEXT, approved_at TEXT, created_at TEXT, updated_at TEXT)');

    $pdo->exec('CREATE TABLE `' . tbl('actions') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 0, name TEXT NOT NULL,
        version TEXT DEFAULT "1", label TEXT, description TEXT, permission TEXT,
        requires_confirmation INTEGER DEFAULT 1, definition_json TEXT, enabled INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE `' . tbl('action_runs') . '` (
        id TEXT PRIMARY KEY, company_id INTEGER DEFAULT 0, user_id TEXT, conversation_id TEXT,
        channel TEXT, action TEXT NOT NULL, params_json TEXT, preview_json TEXT,
        status TEXT DEFAULT "proposed", confirmation_token TEXT UNIQUE, token_expires_at TEXT,
        idempotency_key TEXT, result_json TEXT, error TEXT, approved_at TEXT, executed_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE `' . tbl('notification_outbox') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 0, user_id TEXT,
        channel TEXT DEFAULT "email", recipient TEXT, subject TEXT, template TEXT, body TEXT,
        payload_json TEXT, attachments_json TEXT, status TEXT DEFAULT "pending",
        attempts INTEGER DEFAULT 0, error TEXT, next_attempt_at TEXT, sent_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');

    $pdo->exec('CREATE TABLE `' . tbl('audit') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 0, user_id TEXT, channel TEXT,
        event TEXT, intent TEXT, correlation_id TEXT, conversation_id TEXT, model TEXT, tokens INTEGER DEFAULT 0,
        cost REAL DEFAULT 0, latency_ms INTEGER, query_hash TEXT, sql_text TEXT, question TEXT,
        record_refs TEXT, status TEXT, error TEXT, ip TEXT, detail_json TEXT, created_at TEXT)');

    $pdo->exec('CREATE TABLE `' . tbl('usage_daily') . '` (
        company_id INTEGER NOT NULL, stat_date TEXT NOT NULL, user_id TEXT NOT NULL DEFAULT "",
        chats INTEGER DEFAULT 0, tokens_in INTEGER DEFAULT 0, tokens_out INTEGER DEFAULT 0,
        cost REAL DEFAULT 0, documents INTEGER DEFAULT 0, exports INTEGER DEFAULT 0,
        actions INTEGER DEFAULT 0, errors INTEGER DEFAULT 0, unanswered INTEGER DEFAULT 0,
        created_at TEXT, updated_at TEXT, PRIMARY KEY (company_id, stat_date, user_id))');

    $pdo->exec('CREATE TABLE `' . tbl('settings') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 0, scope TEXT DEFAULT "tenant",
        scope_key TEXT, key_name TEXT, value_json TEXT, updated_by TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE UNIQUE INDEX `' . tbl('setting_key') . '` ON `' . tbl('settings') . '` (company_id, scope, scope_key, key_name)');

    // Same shape as 20261024160000_create_ai_rate_limits_table.php, unique key included:
    // Db::upsertIncrements only counts because the database raises a conflict.
    $pdo->exec('CREATE TABLE `' . tbl('rate_limits') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 0, dimension TEXT NOT NULL,
        scope_key TEXT NOT NULL, window TEXT NOT NULL, bucket TEXT NOT NULL, count INTEGER DEFAULT 0,
        expires_at TEXT)');
    $pdo->exec('CREATE UNIQUE INDEX `' . tbl('rl_key') . '` ON `' . tbl('rate_limits') . '` (dimension, scope_key, window, bucket)');

    $pdo->exec('CREATE TABLE `' . tbl('feedback') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 0, message_id TEXT,
        conversation_id TEXT, user_id TEXT, rating TEXT, reason TEXT, comment TEXT, question TEXT,
        answer TEXT, status TEXT DEFAULT "pending", reviewed_by TEXT, reviewed_at TEXT,
        created_at TEXT, updated_at TEXT)');

    $pdo->exec('CREATE TABLE `' . tbl('report_definitions') . '` (
        id TEXT PRIMARY KEY, company_id INTEGER DEFAULT 0, owner_user_id TEXT, name TEXT,
        folder TEXT, description TEXT, definition_json TEXT, visibility TEXT DEFAULT "private",
        visible_to_json TEXT, run_count INTEGER DEFAULT 0, last_run_at TEXT, created_at TEXT, updated_at TEXT)');

    $pdo->exec('CREATE TABLE `' . tbl('schedules') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 0, user_id TEXT, name TEXT,
        type TEXT DEFAULT "report", cron TEXT, timezone TEXT DEFAULT "UTC", report_id TEXT,
        payload_json TEXT, channel TEXT DEFAULT "email", recipient TEXT, format TEXT DEFAULT "pdf",
        threshold_json TEXT, state TEXT DEFAULT "active", next_run_at TEXT, last_run_at TEXT,
        last_status TEXT, last_error TEXT, failure_count INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)');

    // Same shape as 20261025120000_create_ai_config_table.php.
    $pdo->exec('CREATE TABLE `' . tbl('config') . '` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 0,
        schema_policy_json TEXT, domain_json TEXT, features_json TEXT, semantic_json TEXT,
        reference_json TEXT, knowledge_json TEXT, charts_json TEXT, limits_json TEXT,
        channels_json TEXT, exports_json TEXT, documents_json TEXT, operations_json TEXT,
        updated_by TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE UNIQUE INDEX `' . tbl('config_company') . '` ON `' . tbl('config') . '` (company_id)');

    // The host's own table, which is what the assistant is asked about.
    $pdo->exec('CREATE TABLE orders (
        id INTEGER PRIMARY KEY, company_id INTEGER, customer_id INTEGER, status TEXT,
        total_amount REAL, created_at TEXT)');
    foreach ([[1, 7, 11, 'done', 100.0], [2, 7, 12, 'done', 200.0], [3, 9, 13, 'new', 50.0]] as $row) {
        $pdo->prepare('INSERT INTO orders (id, company_id, customer_id, status, total_amount, created_at)
            VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([...$row, '2026-10-01 09:00:00']);
    }

    return $pdo;
}

/** A model that answers from the prompt it recognises. */
final class ApiLlm implements LlmClientInterface
{
    public int $calls = 0;
    /** @var array<int,array{role:string,content:string}> */
    public array $last = [];

    public function __construct(public array $router = ['intent' => 'help'], public string $reply = 'Here is what I found.')
    {
    }

    public function chat(array $messages, string $model = '', ?float $temperature = null): array
    {
        $this->calls++;
        $this->last = $messages;
        $system = (string) ($messages[0]['content'] ?? '');
        $content = str_contains($system, '"intent":"data|knowledge')
            ? json_encode($this->router, JSON_UNESCAPED_UNICODE)
            : $this->reply;

        return ['content' => $content, 'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 5]];
    }

    public function embeddings(string $input, string $model = ''): array
    {
        return [];
    }
}

final class ApiHandler implements ActionHandlerInterface
{
    public int $executions = 0;

    public function actions(): array
    {
        return ['create_lead'];
    }

    public function preview(UserContext $user, string $action, array $params): ActionProposal
    {
        if ($action !== 'create_lead') {
            return ActionProposal::invalid($action, ['this app has no action "' . $action . '"'], $params);
        }

        return new ActionProposal(
            action: $action,
            params: $params,
            changes: [['field' => 'name', 'from' => null, 'to' => (string) ($params['name'] ?? '')]],
            summary: 'Create a lead called ' . (string) ($params['name'] ?? ''),
        );
    }

    public function execute(UserContext $user, string $action, array $params, string $idempotencyKey): ActionResult
    {
        $this->executions++;

        return ActionResult::success('Created lead 42', [['entity' => 'lead', 'id' => 42, 'label' => 'Novu']], ['lead_id' => 42]);
    }
}

/** Upload bytes on the host's disk — what a real application's storage does. */
final class ApiDocumentStore implements DocumentStoreInterface
{
    public function __construct(private string $root)
    {
    }

    public function put(UploadedFile $file, string $storageKey): string
    {
        $path = $this->path($storageKey);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $file->bytes);

        return $storageKey;
    }

    public function read(string $storageKey): ?string
    {
        $path = $this->path($storageKey);

        return is_readable($path) ? (string) file_get_contents($path) : null;
    }

    public function exists(string $storageKey): bool
    {
        return is_file($this->path($storageKey));
    }

    public function delete(string $storageKey): bool
    {
        return $this->exists($storageKey) && unlink($this->path($storageKey));
    }

    public function localPath(string $storageKey): ?string
    {
        return $this->exists($storageKey) ? $this->path($storageKey) : null;
    }

    private function path(string $key): string
    {
        return $this->root . '/' . str_replace('/', '_', trim($key, '/'));
    }
}

function apiUser(array $extra = []): array
{
    return array_merge(['user_id' => 'u1', 'tenant_id' => 7, 'channel' => 'web', 'thread_id' => 'thread-1', 'roles' => ['sales']], $extra);
}

/** Semantic packs and a catalog handed in as config, so SQLite can stand in for MySQL. */
function apiOptions(string $dir): array
{
    return [
        'semantic' => [
            'packs' => [[
                'name' => 'sales',
                'label' => 'Sales',
                'datasets' => [[
                    'name' => 'orders',
                    'table' => 'orders',
                    'label' => 'Orders',
                    'metrics' => [
                        ['name' => 'records', 'function' => 'count', 'column' => '*'],
                        ['name' => 'revenue', 'function' => 'sum', 'column' => 'total_amount'],
                    ],
                    'dimensions' => [['name' => 'status', 'column' => 'status']],
                ]],
            ]],
            'catalog' => [
                'orders' => [
                    'label' => 'Orders',
                    'description' => 'Every order the company took.',
                    'columns' => [
                        ['name' => 'id', 'type' => 'int'],
                        ['name' => 'company_id', 'type' => 'int'],
                        ['name' => 'customer_id', 'type' => 'int'],
                        ['name' => 'status', 'type' => 'text'],
                        ['name' => 'total_amount', 'type' => 'decimal'],
                        ['name' => 'created_at', 'type' => 'datetime'],
                    ],
                ],
            ],
        ],
        'documents' => ['mirror_to_knowledge' => false],
        'exports' => [],
    ];
}

// ─── fixture wiring ──────────────────────────────────────────────────────────

$dir = sys_get_temp_dir() . '/ai-api-exports';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

function bootConfig(string $dir): void
{
    Config::reset();
    Config::set('table_prefix', 'ai');
    Config::set('tenant.column', 'company_id');
    Config::set('timezone', 'UTC');
    Config::set('schema_policy.mode', 'denylist');
    Config::set('schema_policy.require_tenant_scope', true);
    Config::set('semantic.allow_raw_sql', true);
    Config::set('semantic.raw_sql_roles', ['admin']);
    Config::set('exports.formats', ['csv', 'json']);
    Config::set('exports.directory', $dir);
    Config::set('exports.require_confirmation', true);
    Config::set('exports.approval_roles', ['admin']);
    Config::set('features', ['data_qa', 'documents', 'actions', 'charts', 'exports', 'reports']);
    Config::set('knowledge.retrieval', 'lexical');
    Config::set('reports.allowed_datasets', ['orders']);
}

bootConfig($dir);
$store = new ApiDocumentStore($dir . '/uploads');
$noDb = new AssistantApi(null, ['llm' => new ApiLlm()]);

// ─── with no database at all ─────────────────────────────────────────────────

$diag = $noDb->diagnostics();
check('headless: diagnostics answers', ($diag['ok'] ?? false) === true);
same('headless: no connection', false, $diag['database']['connected']);
same('headless: tables unread', null, $diag['database']['tables']['knowledge']);
same('headless: llm ready', true, $diag['llm']['ready']);
same('headless: prefix reported', 'ai', $diag['package']['table_prefix']);
same('headless: nothing invented', [], $diag['database']['missing']);
check('headless: export directory reported', is_string($diag['exports']['directory']), json_encode($diag['exports']));

$caps = $noDb->capabilities(apiUser());
okAnswer('headless: capabilities', $caps);
same('headless: no datasets yet', [], $caps['capabilities']['datasets']);
same('headless: knowledge off without a store', false, $caps['capabilities']['knowledge']);

same('headless: formats are the built-ins', ['csv', 'json'], $noDb->exportFormats()['formats']);

badAnswer('headless: chat needs a user', $noDb->handle('chat', ['question' => 'hello']));
$unknown = $noDb->handle('does.not.exist');
badAnswer('headless: unknown action', $unknown, 'unknown action');
check('headless: unknown action lists the known ones', count((array) ($unknown['actions'] ?? [])) > 20, (string) count($unknown['actions'] ?? []));

$sent = $noDb->answerWhatsApp(['from' => '27820001111', 'text' => 'hello']);
badAnswer('headless: whatsapp needs a sender', $sent, 'whatsapp_sender');

badAnswer('headless: documents need a database', $noDb->listDocuments(7), 'no database connection');

$chart = $noDb->suggestChart([['month' => '2026-09', 'revenue' => 10], ['month' => '2026-10', 'revenue' => 14]]);
okAnswer('headless: chart suggestion is pure logic', $chart);
check('headless: a chart was named', ($chart['chart']['type'] ?? '') !== '', json_encode($chart['chart'] ?? []));

$built = $noDb->buildChart('bar', [['label' => 'a', 'value' => 3]]);
okAnswer('headless: chart build', $built);
same('headless: chart type kept', 'bar', $built['spec']['type'] ?? null);

$nextRuns = $noDb->handle('schedule.create', ['name' => 'Mon', 'cron' => 'not a cron']);
badAnswer('headless: a bad cron is refused', $nextRuns, 'cron');
$runs = $noDb->nextRuns('0 9 * * 1', 3);
okAnswer('headless: next runs', $runs);
same('headless: three runs offered', 3, count($runs['runs']));

try {
    $noDb->service('nope');
    check('service(): unknown name throws', false);
} catch (Throwable $e) {
    check('service(): unknown name throws', str_contains($e->getMessage(), 'Unknown service'), $e->getMessage());
}

// ─── a host database, the way composer update leaves it ──────────────────────

$pdo = apiDatabase();
$llm = new ApiLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]]);
$handler = new ApiHandler();
$sentBox = [];
$api = new AssistantApi($pdo, [
    'llm' => $llm,
    'action_handler' => $handler,
    'document_store' => $store,
    'notifier' => function (array $payload) use (&$sentBox): bool {
        $sentBox[] = $payload;
        return true;
    },
], apiOptions($dir));

$chat = $api->chat('how many orders', apiUser());
okAnswer('chat: answered', $chat);
same('chat: data intent', 'data', $chat['answer']['intent'] ?? null);
same('chat: tenant scope applied', 2, (int) ($chat['answer']['rows'][0]['records'] ?? 0));
check('chat: plain text offered', str_contains((string) ($chat['answer']['plain'] ?? ''), 'records'), (string) ($chat['answer']['plain'] ?? ''));
check('chat: conversation opened', ($chat['answer']['meta']['conversation_id'] ?? null) !== null, json_encode($chat['answer']['meta'] ?? []));

$other = $api->chat('how many orders', apiUser(['tenant_id' => 9]));
okAnswer('chat: another tenant asks', $other);
same('chat: only their rows', 1, (int) ($other['answer']['rows'][0]['records'] ?? 0));

$blank = $api->chat('   ', apiUser());
okAnswer('chat: blank question is help', $blank);
same('chat: blank says what to do', true, str_contains(strtolower((string) $blank['answer']['text']), 'ask me'));

$explained = $api->explain(['dataset' => 'orders', 'metrics' => ['revenue']], apiUser());
okAnswer('explain: compiles', $explained);
check('explain: sql with a bound tenant', str_contains((string) $explained['query']['sql'], 'company_id'), json_encode($explained['query'] ?? []));
$explainSql = (string) $explained['query']['sql'];
check('explain: no value inlined', str_contains($explainSql, ':__tenant') && !preg_match('/company_id`?\s*=\s*\d/', $explainSql), $explainSql);
same('explain: bind carries the tenant', 7, (int) ($explained['query']['bind'][':__tenant'] ?? $explained['query']['bind'][0] ?? 0));

$ran = $api->runQuery(['dataset' => 'orders', 'metrics' => ['revenue']], apiUser());
okAnswer('runQuery: numbers without a model', $ran);
same('runQuery: revenue summed', 300.0, (float) ($ran['result']['rows'][0]['revenue'] ?? 0));

$raw = $api->runRawSql('SELECT id FROM orders WHERE company_id = 7', apiUser(['roles' => ['admin']]));
okAnswer('raw sql: admin reads', $raw);
same('raw sql: rows back', 2, count($raw['result']['rows'] ?? []));
badAnswer('raw sql: writer refused', $api->runRawSql('DELETE FROM orders', apiUser(['roles' => ['admin']])), 'read data');
badAnswer('raw sql: non-admin refused', $api->runRawSql('SELECT id FROM orders', apiUser()), 'not enabled');

// ─── conversations ───────────────────────────────────────────────────────────

$list = $api->listConversations(7, 'u1');
okAnswer('conversations: listed', $list);
same('conversations: one thread', 1, count($list['items'] ?? []));
$threadId = (string) ($list['items'][0]['id'] ?? '');

$fetched = $api->getConversation($threadId);
okAnswer('conversations: fetched', $fetched);
same('conversations: two turns stored', 2, count($fetched['turns'] ?? []));
badAnswer('conversations: unknown id', $api->getConversation('nope'), 'no such conversation');

$renamed = $api->renameConversation($threadId, 'October orders');
okAnswer('conversations: renamed', $renamed);
same('conversations: rename written', true, $renamed['renamed']);
$shared = $api->shareConversation($threadId);
okAnswer('conversations: shared', $shared);
$slice = $api->sharedConversation((string) $shared['token']);
okAnswer('conversations: share link opens', $slice);
$revoked = $api->revokeConversationShare($threadId);
okAnswer('conversations: share revoked', $revoked);
badAnswer('conversations: dead link closed', $api->sharedConversation((string) $shared['token']), 'no longer open');
okAnswer('conversations: searched', $api->searchConversations(7, 'orders'));
okAnswer('conversations: summarised', $api->summarizeConversation($threadId));

// A host whose tables carry decrypted PII can keep those rows out of the store;
// the live answer still carries them.
Config::set('limits.store_rows', 0);
$privacy = $api->chat('how many orders', apiUser(['thread_id' => 'privacy-thread']));
Config::set('limits.store_rows', 50);
okAnswer('rows at rest: live answer keeps its rows', $privacy);
same('rows at rest: rows in the answer', 2, (int) ($privacy['answer']['rows'][0]['records'] ?? 0));
$privacyId = (string) ($privacy['answer']['meta']['conversation_id'] ?? '');
$stored = $api->getConversation($privacyId);
okAnswer('rows at rest: thread readable', $stored);
same('rows at rest: nothing written to the store', 0, count((array) ($stored['turns']['messages'][1]['rows'] ?? [])));

// ─── knowledge ───────────────────────────────────────────────────────────────

$added = $api->addKnowledge(7, ['title' => 'Refunds', 'content' => 'Refunds are 30 days.', 'category' => 'policy']);
okAnswer('knowledge: added', $added);
same('knowledge: created', true, $added['entry']['created']);
badAnswer('knowledge: needs both fields', $api->addKnowledge(7, ['title' => 'Refunds']), 'title and the content');
$again = $api->addKnowledge(7, ['title' => 'Refunds', 'content' => 'Refunds are 30 days.', 'category' => 'policy']);
okAnswer('knowledge: duplicate is an update', $again);
same('knowledge: nothing new made', false, $again['entry']['created']);

$listed = $api->listKnowledge(7);
okAnswer('knowledge: listed', $listed);
same('knowledge: one entry', 1, count($listed['items'] ?? []));
$id = (int) ($listed['items'][0]['id'] ?? 0);
okAnswer('knowledge: fetched', $api->getKnowledge($id, 7));
badAnswer('knowledge: other tenant cannot read', $api->getKnowledge($id, 8), 'no such knowledge');
okAnswer('knowledge: updated', $api->updateKnowledge($id, 7, ['content' => 'Refunds are 14 days now.']));
okAnswer('knowledge: switched off', $api->setActiveKnowledge($id, 7, false));
okAnswer('knowledge: switched on', $api->setActiveKnowledge($id, 7, true));
$searched = $api->searchKnowledge(7, 'refund policy', 3, apiUser());
okAnswer('knowledge: searched', $searched);
same('knowledge: hit found', 1, count($searched['hits'] ?? []));
$stats = $api->knowledgeStats(7);
okAnswer('knowledge: stats', $stats);
same('knowledge: one active', 1, (int) ($stats['stats']['active'] ?? 0));
$template = $api->knowledgeTemplate();
okAnswer('knowledge: template', $template);
check('knowledge: template header', str_contains((string) $template['csv'], 'title'), (string) $template['csv']);

$imported = $api->importKnowledgeCsv(7, "title,content,category\nOpening hours,We open at 08:00,policy\n");
okAnswer('knowledge: csv imported', $imported);
same('knowledge: one row in', 1, (int) ($imported['inserted'] ?? 0));
$deleted = $api->deleteKnowledge($id, 7);
okAnswer('knowledge: deleted', $deleted);
same('knowledge: row gone', true, $deleted['deleted']);

// ─── schema: descriptions, dumps, catalog ────────────────────────────────────

$saved = $api->saveColumnDescription('orders', 'total_amount', 'What the customer paid, in ZAR, including delivery.', [
    'label' => 'Order total', 'sensitivity' => 'financial', 'is_measure' => true, 'format' => 'money',
]);
okAnswer('schema: description saved', $saved);
same('schema: written', true, $saved['saved']);
badAnswer('schema: needs a table', $api->saveColumnDescription('', 'total_amount', 'x'), 'needs the table');

$catalog = $api->schemaCatalog();
okAnswer('schema: catalog read', $catalog);
same('schema: user words win', 'What the customer paid, in ZAR, including delivery.', columnOf($catalog['tables'], 'orders', 'total_amount')['description'] ?? null);
$listSchema = $api->listSchema();
okAnswer('schema: rows listed', $listSchema);
same('schema: one column catalogued', 1, count($listSchema['items'] ?? []));
$tableCard = $api->saveColumnDescription('orders', '', 'One row per order.', ['table_label' => 'Orders']);
okAnswer('schema: table card saved', $tableCard);
same('schema: table card written', true, $tableCard['saved']);
$withCard = $api->schemaCatalog();
same('schema: table card kept', 'One row per order.', $withCard['tables']['orders']['description'] ?? null);
$described = $api->describeTable('orders');
okAnswer('schema: table described', $described);
check('schema: description printed', str_contains((string) $described['description'], 'What the customer paid'), (string) $described['description']);
okAnswer('schema: search', $api->searchSchema('amount'));

$dump = <<<'SQL'
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Customer id',
  `name` varchar(150) NOT NULL COMMENT 'Who the customer is called',
  `phone` varchar(30) DEFAULT NULL COMMENT 'Phone number, shown to staff only',
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) COMMENT='People who buy things';
SQL;
$trained = $api->importSqlDump($dump, ['company_id' => 7]);
okAnswer('schema: dump trained', $trained);
same('schema: table counted', 1, (int) ($trained['schema']['tables'] ?? 0));
check('schema: comments became descriptions', str_contains((string) (columnOf($api->schemaCatalog()['tables'], 'customers', 'name')['description'] ?? ''), 'called'), json_encode($api->schemaCatalog()['tables']['customers'] ?? []));
$fromFile = $api->importSqlDump(['name' => 'schema.sql', 'contents' => $dump], []);
okAnswer('schema: dump as an upload', $fromFile);
badAnswer('schema: empty dump refused', $api->importSqlDump(['name' => 'x.sql', 'contents' => 'UPDATE 1;']), 'create table');

$csvImport = $api->importSchemaDescriptions("table,column,description\norders,status,Where the order is in the flow\n");
okAnswer('schema: descriptions imported', $csvImport);
same('schema: one written', 1, (int) ($csvImport['created'] ?? 0) + (int) ($csvImport['updated'] ?? 0));
$tpl = $api->schemaTemplate();
okAnswer('schema: template offered', $tpl);
$synced = $api->syncSchema();
badAnswer('schema: live sync needs a real server', $synced, 'information_schema');

$clean = $api->validateSql('SELECT id, total_amount, company_id FROM orders WHERE company_id = 7', 7);
okAnswer('schema: clean sql passes', $clean);
check('schema: nothing to fix', ($clean['error'] ?? null) === null, json_encode($clean));
$badSql = $api->validateSql('SELECT password_hash FROM customers WHERE company_id = 7', 7);
check('schema: bad sql named', str_contains((string) ($badSql['error'] ?? ''), 'password_hash'), json_encode($badSql));
okAnswer('schema: reference list', $api->referenceValues(7));
okAnswer('schema: reference forget', $api->forgetReferenceValues(7));

// ─── documents ───────────────────────────────────────────────────────────────

$upload = $api->uploadDocument(['name' => 'warranty.txt', 'contents' => "The warranty is 24 months on all installs.\nCall the office to claim."], apiUser(), [
    'title' => 'Warranty policy', 'category' => 'policy',
]);
okAnswer('documents: uploaded', $upload);
same('documents: indexed', 'indexed', $upload['document']['status'] ?? null);
$docId = (string) ($upload['document']['id'] ?? '');
$listDocs = $api->listDocuments(7);
okAnswer('documents: listed', $listDocs);
same('documents: one file', 1, count($listDocs['items'] ?? []));
okAnswer('documents: fetched', $api->getDocument($docId, 7));
badAnswer('documents: other tenant locked', $api->getDocument($docId, 8), 'no such document');
$chunks = $api->documentChunks($docId, 7);
okAnswer('documents: chunks read', $chunks);
check('documents: chunked', count($chunks['items'] ?? []) >= 1, json_encode($chunks));
$found = $api->searchDocuments(7, 'warranty months');
okAnswer('documents: searched', $found);
same('documents: hit', 1, count($found['hits'] ?? []));
okAnswer('documents: reindexed', $api->reindexDocument(7, $docId));
$base64 = $api->uploadDocument(['name' => 'terms.txt', 'base64' => base64_encode('Terms: pay within 30 days.')], apiUser());
okAnswer('documents: base64 upload', $base64);
badAnswer('documents: nothing uploaded', $api->uploadDocument([], apiUser()), 'nothing was uploaded');
badAnswer('documents: bad array shape', $api->uploadDocument(['title' => 'x'], apiUser()), 'tmp_name');
$gone = $api->deleteDocument($docId, 7);
okAnswer('documents: deleted', $gone);
same('documents: row gone', true, $gone['deleted']);

// ─── exports ─────────────────────────────────────────────────────────────────

$rows = [['id' => 1, 'total_amount' => 100.0], ['id' => 2, 'total_amount' => 200.0]];
$made = $api->createExport($rows, 'csv', apiUser(), ['title' => 'October orders', 'entity' => 'orders']);
okAnswer('export: file made', $made);
same('export: status ready', 'ready', $made['status']);
check('export: id returned', strlen((string) $made['id']) === 36, (string) $made['id']);
check('export: token returned', strlen((string) $made['download_token']) === 32, (string) $made['download_token']);
check('export: size reported', (int) $made['size'] > 0, (string) $made['size']);
$down = $api->download((string) $made['download_token'], apiUser());
okAnswer('export: link works', $down);
check('export: file on disk', is_file((string) $down['file']['path']), json_encode($down['file'] ?? []));
same('export: mime offered', 'text/csv', $down['file']['mime'] ?? null);
check('export: disposition built', str_contains((string) $down['content_disposition'], '.csv'), (string) $down['content_disposition']);
badAnswer('export: other tenant locked', $api->download((string) $made['download_token'], apiUser(['tenant_id' => 9])), 'another company');
badAnswer('export: unknown token', $api->download('nope'), 'expired');
$history = $api->exportHistory(apiUser());
okAnswer('export: history', $history);
same('export: one row', 1, count($history['items'] ?? []));

$phoneRows = [['customer_phone' => '0821112222']];
$sensitive = $api->createExport($phoneRows, 'csv', apiUser(), ['entity' => 'orders']);
okAnswer('export: sensitive held', $sensitive);
same('export: waits for approval', 'awaiting_approval', $sensitive['status']);
$approved = $api->approveExport((string) $sensitive['id'], apiUser(), $phoneRows);
okAnswer('export: requester approves own', $approved);
same('export: ready after approval', 'ready', $approved['status']);
$held = $api->createExport($phoneRows, 'csv', apiUser(), ['entity' => 'orders']);
badAnswer('export: another user cannot approve', $api->approveExport((string) $held['id'], apiUser(['user_id' => 'u2']), $phoneRows), 'only the requester');

$adminExport = $api->createExport([['customer_phone' => '0821112222']], 'csv', apiUser(['roles' => ['admin']]));
okAnswer('export: approver skips the queue', $adminExport);
same('export: straight to ready', 'ready', $adminExport['status']);

$empty = $api->createExport([], 'csv', apiUser());
badAnswer('export: no rows says so', $empty, 'nothing to export');
$wrongFormat = $api->createExport($rows, 'pdf', apiUser());
badAnswer('export: format not wired', $wrongFormat, 'not enabled');
okAnswer('export: queued runner idle', $api->runQueuedExports(2, apiUser()));
okAnswer('export: purge', $api->purgeExpiredExports());
$blocked = $api->createExport([['password_hash' => 'abc']], 'csv', apiUser(), ['entity' => 'orders', 'columns' => ['password_hash']]);
badAnswer('export: blocked column refused', $blocked, 'blocked');

// ─── actions ─────────────────────────────────────────────────────────────────

$preview = $api->previewAction(apiUser(), 'create_lead', ['name' => 'Novu'], ['conversation_id' => $threadId]);
okAnswer('action: proposed', $preview);
same('action: needs confirmation', true, $preview['requires_confirmation'] ?? null);
same('action: summary shown', 'Create a lead called Novu', $preview['proposal']['summary'] ?? null);
check('action: token issued', strlen((string) ($preview['confirm_token'] ?? '')) === 32, json_encode($preview));
check('action: id issued', strlen((string) ($preview['id'] ?? '')) === 36, (string) ($preview['id'] ?? ''));
$confirmed = $api->confirmAction((string) $preview['confirm_token'], apiUser());
okAnswer('action: confirmed', $confirmed);
same('action: executed once', 1, $handler->executions);
same('action: host result kept', 42, $confirmed['result']['data']['lead_id'] ?? null);
$replay = $api->confirmAction((string) $preview['confirm_token'], apiUser());
okAnswer('action: replay is safe', $replay);
same('action: replay marked', true, $replay['already_handled'] ?? false);
same('action: nothing ran twice', 1, $handler->executions);
badAnswer('action: unknown token', $api->confirmAction('nope'), 'not recognised');
badAnswer('action: unregistered name', $api->previewAction(apiUser(), 'delete_everything', []), 'this app has no action');
$catalog = $api->actionCatalog(7);
okAnswer('action: catalog empty until the host fills it', $catalog);
$second = $api->previewAction(apiUser(), 'create_lead', ['name' => 'Second']);
$logged = $api->actionHistory(7, 'u1', 10);
okAnswer('action: history', $logged);
same('action: two runs stored', 2, count($logged['items'] ?? []));
$got = $api->getAction((string) $second['id'], 7);
okAnswer('action: run fetched', $got);
check('action: token never leaves the server', !array_key_exists('confirmation_token', $got['run'] ?? []), json_encode(array_keys((array) ($got['run'] ?? []))));
$cancelled = $api->cancelAction((string) $second['id'], apiUser());
okAnswer('action: cancelled', $cancelled);
okAnswer('action: stale sweep', $api->expireStaleActions());
badAnswer('action: unknown id', $api->getAction('nope', 7), 'no such action');

// ─── outbox ──────────────────────────────────────────────────────────────────

$queued = $api->queueNotification(7, ['channel' => 'email', 'recipient' => 'boss@example.com', 'subject' => 'Weekly', 'body' => 'Numbers attached']);
okAnswer('outbox: queued', $queued);
same('outbox: pending', 'pending', $queued['status'] ?? null);
check('outbox: id returned', (string) ($queued['id'] ?? '') !== '', json_encode($queued));
$many = $api->queueNotifications(7, [
    ['channel' => 'email', 'recipient' => 'a@example.com', 'body' => 'one'],
    ['channel' => 'email', 'recipient' => '', 'body' => 'no address'],
]);
same('outbox: batch keeps the good ones', 1, (int) ($many['queued'] ?? 0));
check('outbox: batch admits the refusal', ($many['ok'] ?? true) === false && str_contains((string) ($many['error'] ?? ''), 'refused'), json_encode($many));
$flush = $api->flushOutbox(10);
okAnswer('outbox: flushed', $flush);
same('outbox: two delivered', 2, count($sentBox));
same('outbox: sent counted by the flush', 2, (int) ($flush['sent'] ?? 0));
same('outbox: nothing left pending', 0, (int) ($flush['pending'] ?? 0));
$counts = $api->outboxCounts(7);
okAnswer('outbox: counts', $counts);
same('outbox: sent counted', 2, (int) ($counts['counts']['sent'] ?? 0));
okAnswer('outbox: history', $api->outboxHistory(7));
badAnswer('outbox: no recipient', $api->queueNotification(7, ['channel' => 'email', 'body' => 'hi']), 'recipient');
$late = $api->queueNotification(7, ['channel' => 'email', 'recipient' => 'later@example.com', 'body' => 'tomorrow', 'scheduled_at' => '2030-01-01 08:00:00']);
okAnswer('outbox: scheduled', $late);
$due = $api->dueNotifications();
okAnswer('outbox: due list', $due);
same('outbox: future job not due', 0, count($due['items'] ?? []));
$cancelledMail = $api->cancelNotification((string) ($late['id'] ?? ''));
okAnswer('outbox: cancelled', $cancelledMail);

// ─── reports & schedules ─────────────────────────────────────────────────────

$created = $api->createReport(['name' => 'October', 'definition' => ['intent' => 'data_qa', 'dataset' => 'orders', 'metrics' => ['revenue'], 'chart' => 'bar']], apiUser());
okAnswer('report: created', $created);
$reportId = (string) ($created['report']['id'] ?? '');
okAnswer('report: listed', $api->listReports(7, null, apiUser()));
okAnswer('report: fetched', $api->getReport($reportId, 7));
badAnswer('report: other tenant locked', $api->getReport($reportId, 8), 'no such report');
$ranReport = $api->runReport($reportId, [], apiUser());
okAnswer('report: runs', $ranReport);
check('report: says a runner is missing', ($ranReport['result']['runner_missing'] ?? false) === true, json_encode($ranReport['result'] ?? []));
okAnswer('report: updated', $api->updateReport($reportId, ['description' => 'Revenue by week'], 7));
$validatedReport = $api->validateReport(['intent' => 'data_qa', 'dataset' => 'nope'], 'Broken');
okAnswer('report: definition validated', $validatedReport);
same('report: unknown dataset caught', false, ($validatedReport['errors'] ?? []) === []);
$sharedReport = $api->shareReport($reportId, 7);
okAnswer('report: share token', $sharedReport);
okAnswer('report: duplicated', $api->duplicateReport($reportId, 7, apiUser()));
okAnswer('report: history empty', $api->reportHistory($reportId, 7));
okAnswer('report: deleted', $api->deleteReport($reportId, 7));

$schedule = $api->createSchedule(['name' => 'Monday revenue', 'cron' => '0 9 * * 1', 'company_id' => 7, 'target' => $reportId, 'channel' => 'email', 'recipient' => 'boss@example.com']);
okAnswer('schedule: created', $schedule);
$scheduleId = $schedule['schedule']['id'];
$ticked = $api->tickSchedules();
okAnswer('schedule: tick finds nothing early', $ticked);
same('schedule: no jobs yet', 0, count($ticked['jobs'] ?? []));
$pdo->prepare('UPDATE `' . tbl('schedules') . '` SET next_run_at = :past WHERE id = :id')
    ->execute(['past' => '2020-01-01 00:00:00', 'id' => $scheduleId]);
$dueNow = $api->dueSchedules();
okAnswer('schedule: due now', $dueNow);
same('schedule: one due', 1, count($dueNow['items'] ?? []));
$marked = $api->markScheduleRun($scheduleId, true);
okAnswer('schedule: run marked', $marked);
same('schedule: still active', 'active', $marked['state']['state'] ?? null);
$row = $pdo->query('SELECT * FROM `' . tbl('schedules') . '` WHERE id = ' . (int) $scheduleId)->fetch(PDO::FETCH_ASSOC);
same('schedule: the mark was really written', 'ok', $row['last_status'] ?? null);
check('schedule: next run re-armed in the row', !empty($row['next_run_at']), json_encode($row['next_run_at'] ?? null));
$api->markScheduleRun($scheduleId, false, 'SMTP refused: mailbox unknown');
$row = $pdo->query('SELECT * FROM `' . tbl('schedules') . '` WHERE id = ' . (int) $scheduleId)->fetch(PDO::FETCH_ASSOC);
same('schedule: a failure keeps the reason for the operator', 'SMTP refused: mailbox unknown', $row['last_error'] ?? null);
same('schedule: failure counted', 1, (int) ($row['failure_count'] ?? 0));
same('schedule: one failure does not disable', 'active', $row['state'] ?? null);
okAnswer('schedule: paused', $api->pauseSchedule($scheduleId, 7));
okAnswer('schedule: resumed', $api->resumeSchedule($scheduleId, 7));
okAnswer('schedule: updated', $api->updateSchedule($scheduleId, ['name' => 'Monday'], 7));
okAnswer('schedule: deleted', $api->deleteSchedule($scheduleId, 7));

// ─── feedback, usage, limits, audit, settings ────────────────────────────────

$fb = $api->submitFeedback(['question' => 'how many orders', 'answer' => '2'], 'down', 'wrong month', 'It counted last year', apiUser());
okAnswer('feedback: submitted', $fb);
$fbList = $api->feedbackList(7);
okAnswer('feedback: listed', $fbList);
same('feedback: one waiting', 1, count($fbList['items'] ?? []));
$fbId = (int) ($fbList['items'][0]['id'] ?? 0);
okAnswer('feedback: reviewed', $api->reviewFeedback($fbId, 'noted', 'u9'));
$agg = $api->feedbackSummary(7, '2026-10-01', '2026-10-31');
okAnswer('feedback: aggregated', $agg);
$promoted = $api->feedbackToKnowledge($fbId);
okAnswer('feedback: promoted to knowledge', $promoted);
same('feedback: written into knowledge', true, $promoted['result']['ok'] ?? false);

$today = $api->usageToday(7);
okAnswer('usage: today', $today);
check('usage: chats counted', (int) ($today['usage']['chats'] ?? 0) >= 1, json_encode($today['usage'] ?? []));
okAnswer('usage: range', $api->usageRange(7, '2026-10-01', '2026-10-31'));
okAnswer('usage: by user', $api->usageByUser(7, '2026-10-01', '2026-10-31'));
okAnswer('usage: top questions', $api->topQuestions(7, '2026-10-01', '2026-10-31'));

$limit = $api->checkLimit('chats', '7:u1', 'hour', 5);
okAnswer('limit: checked', $limit);
same('limit: allowed', true, $limit['limit']['allowed'] ?? null);
$spent = $api->consumeLimit('chats', '7:u1', 'hour', 1);
okAnswer('limit: consumed', $spent);
same('limit: now blocked', false, $spent['limit']['allowed'] ?? null);

// A host runs a new AssistantApi per request, so an hour window counted in
// process memory would never block anyone. {prefix}_rate_limits is what makes it.
$reopened = (new AssistantApi($pdo, ['llm' => new ApiLlm()], apiOptions($dir)))->checkLimit('chats', '7:u1', 'hour', 1);
same('limit: the hour bucket survived a fresh instance', false, $reopened['limit']['allowed'] ?? null);
$someoneElse = (new AssistantApi($pdo, ['llm' => new ApiLlm()], apiOptions($dir)))->checkLimit('chats', '7:u2', 'hour', 1);
same('limit: another key gets its own bucket', true, $someoneElse['limit']['allowed'] ?? null);

$trail = $api->auditTrail(['company_id' => 7], 50);
okAnswer('audit: trail read', $trail);
check('audit: events recorded', count($trail['items'] ?? []) > 0, (string) count($trail['items'] ?? []));
okAnswer('audit: summary', $api->auditSummary(['company_id' => 7]));
$trace = $api->recordAudit(['company_id' => 7, 'user_id' => 'u1'], 'host.button_pressed', ['detail' => ['x' => 1]]);
okAnswer('audit: host event written', $trace);
check('audit: trace id returned', strlen((string) $trace['trace_id']) > 8, (string) $trace['trace_id']);
$csv = $api->auditCsv(['company_id' => 7]);
okAnswer('audit: csv', $csv);
check('audit: csv has a header', str_contains((string) $csv['csv'], 'event'), substr((string) $csv['csv'], 0, 80));
okAnswer('audit: chain verified', $api->auditVerify());

$put = $api->putSetting('assistant_name', 'Insights', ['company_id' => 7], 'u1');
okAnswer('settings: saved', $put);
same('settings: written', true, $put['saved']);
$gotSetting = $api->getSetting('assistant_name', null, ['company_id' => 7]);
okAnswer('settings: read back', $gotSetting);
same('settings: value survived', 'Insights', $gotSetting['value']);
okAnswer('settings: listed', $api->settingsList(['company_id' => 7]));
$effective = $api->effectiveConfig(7, null);
okAnswer('settings: effective config', $effective);
check('settings: overrides merged', ($effective['config']['assistant_name'] ?? null) === 'Insights', json_encode($effective['config'] ?? []));
okAnswer('settings: forgotten', $api->forgetSetting('assistant_name', ['company_id' => 7]));

// ─── settings stored in the database ({prefix}_config) ────────────────────────

$cfgDesc = $api->handle('config.describe', ['company_id' => 7]);
okAnswer('config: described', $cfgDesc);
check('config: lists editable settings', ($cfgDesc['editable'] ?? 0) > 50, (string) ($cfgDesc['editable'] ?? 0));
check('config: groups into sections', count($cfgDesc['sections'] ?? []) >= 10, (string) count($cfgDesc['sections'] ?? []));
$modeListed = false;
foreach ($cfgDesc['sections'] ?? [] as $section) {
    foreach ($section['settings'] ?? [] as $item) {
        if (($item['path'] ?? '') === 'schema_policy.mode') {
            $modeListed = $item['options'] === ['denylist', 'allowlist', 'all'];
        }
    }
}
check('config: policy mode offers denylist/allowlist/all', $modeListed, json_encode($cfgDesc['sections'][0] ?? []));

$cfgSave = $api->handle('config.save', ['company_id' => 7, 'settings' => [
    'charts' => ['max_rows' => '42'],
    'schema_policy.mode' => 'sometimes',
    'nope' => 1,
]]);
okAnswer('config: save answered', $cfgSave);
same('config: nested input flattened and saved', ['charts.max_rows'], $cfgSave['saved'] ?? null);
check('config: invalid enum rejected', isset($cfgSave['errors']['schema_policy.mode']), json_encode($cfgSave['errors'] ?? []));
same('config: unknown path rejected', ['nope'], $cfgSave['unknown'] ?? null);

$cfgGet = $api->handle('config.get', ['company_id' => 7, 'path' => 'charts.max_rows']);
same('config: value read back', 42, $cfgGet['setting']['value'] ?? null);
same('config: source is database', 'database', $cfgGet['setting']['source'] ?? null);

$cfgForgot = $api->handle('config.forget', ['company_id' => 7, 'paths' => ['charts.max_rows']]);
same('config: override cleared', ['charts.max_rows'], $cfgForgot['cleared'] ?? null);
same('config: cleared path is no longer overridden', false, $api->handle('config.get', ['company_id' => 7, 'path' => 'charts.max_rows'])['setting']['overridden'] ?? true);

// ─── whatsapp ────────────────────────────────────────────────────────────────

$webhook = [
    'entry' => [[
        'id' => '1561360440689390',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => '27820001111', 'profile' => ['name' => 'Novu']]],
                'metadata' => ['display_phone_number' => '27100000000'],
                'messages' => [['from' => '27820001111', 'id' => 'wamid.IN1', 'type' => 'text', 'text' => ['body' => 'how many orders']]],
            ],
        ]],
    ]],
];
$inbound = $api->inboundWhatsApp($webhook);
okAnswer('whatsapp: webhook read', $inbound);
same('whatsapp: one message', 1, count($inbound['messages'] ?? []));
same('whatsapp: text unwrapped', 'how many orders', $inbound['messages'][0]['text'] ?? null);

$sentWa = [];
$waApi = new AssistantApi($pdo, [
    'llm' => new ApiLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]], 'You have 2 orders in total.'),
    'whatsapp_sender' => function (string $to, string $body, array $meta) use (&$sentWa): array {
        $sentWa[] = ['to' => $to, 'body' => $body, 'meta' => $meta];
        return ['messages' => [['id' => 'wamid.OUT1']]];
    },
], apiOptions($dir));
$answered = $waApi->answerWhatsApp($webhook, ['tenant_id' => 7, 'user_id' => 'wa-1']);
okAnswer('whatsapp: answered and sent', $answered);
same('whatsapp: one reply', 1, count($answered['replies'] ?? []));
same('whatsapp: sent to the customer', '27820001111', $sentWa[0]['to'] ?? null);
check('whatsapp: body carries the answer', str_contains((string) ($sentWa[0]['body'] ?? ''), 'orders'), (string) ($sentWa[0]['body'] ?? ''));
check('whatsapp: the reply knows the number it answered', (int) ($answered['replies'][0]['answer']['rows'][0]['records'] ?? 0) === 2, json_encode($answered['replies'][0]['answer']['rows'] ?? []));
same('whatsapp: external id kept', 'wamid.OUT1', $answered['replies'][0]['result']['external_id'] ?? null);
same('whatsapp: send reported ok', true, $answered['replies'][0]['result']['ok'] ?? null);
$redelivered = $waApi->answerWhatsApp($webhook, ['tenant_id' => 7, 'user_id' => 'wa-1']);
okAnswer('whatsapp: redelivery handled', $redelivered);
same('whatsapp: nothing sent twice', 1, count($sentWa));

// ─── handle(): the single-endpoint shape ─────────────────────────────────────

$viaHandle = $api->handle('chat', ['question' => 'how many orders', 'user' => apiUser()]);
okAnswer('handle: chat', $viaHandle);
same('handle: same answer', $chat['answer']['rows'], $viaHandle['answer']['rows']);
okAnswer('handle: capabilities', $api->handle('capabilities', ['user' => apiUser()]));
okAnswer('handle: diagnostics', $api->handle('diagnostics'));
okAnswer('handle: query.run', $api->handle('query.run', ['plan' => ['dataset' => 'orders', 'metrics' => ['records']], 'user' => apiUser()]));
okAnswer('handle: export.create', $api->handle('export.create', ['data' => $rows, 'format' => 'csv', 'user' => apiUser(), 'meta' => ['title' => 'Handled']]));
okAnswer('handle: action.preview', $api->handle('action.preview', ['action' => 'create_lead', 'params' => ['name' => 'Handled'], 'user' => apiUser()]));
okAnswer('handle: outbox.queue', $api->handle('outbox.queue', ['tenant_id' => 7, 'message' => ['channel' => 'email', 'recipient' => 'h@example.com', 'body' => 'x']]));
okAnswer('handle: outbox.counts', $api->handle('outbox.counts', ['tenant_id' => 7]));
okAnswer('handle: knowledge.add', $api->handle('knowledge.add', ['tenant_id' => 7, 'title' => 'Handled', 'content' => 'It works']));
okAnswer('handle: knowledge.list', $api->handle('knowledge.list', ['tenant_id' => 7]));
okAnswer('handle: schema.list', $api->handle('schema.list'));
okAnswer('handle: schema.describe', $api->handle('schema.describe', ['table' => 'orders', 'column' => 'status', 'description' => 'Where the order is']));
okAnswer('handle: document.list', $api->handle('document.list', ['tenant_id' => 7]));
okAnswer('handle: report.list', $api->handle('report.list', ['tenant_id' => 7]));
okAnswer('handle: schedule.tick', $api->handle('schedule.tick'));
okAnswer('handle: feedback.submit', $api->handle('feedback.submit', ['target' => ['answer' => 'ok'], 'rating' => 'up', 'user' => apiUser()]));
okAnswer('handle: usage.today', $api->handle('usage.today', ['tenant_id' => 7]));
okAnswer('handle: limit.check', $api->handle('limit.check', ['dimension' => 'chats', 'key' => '7', 'window' => 'day']));
okAnswer('handle: audit.query', $api->handle('audit.query', ['filter' => ['company_id' => 7]]));
okAnswer('handle: settings.put', $api->handle('settings.put', ['key' => 'k', 'value' => 'v', 'scope' => ['company_id' => 7]]));
okAnswer('handle: conversation.list', $api->handle('conversation.list', ['tenant_id' => 7, 'user_id' => 'u1']));
okAnswer('handle: whatsapp.inbound', $api->handle('whatsapp.inbound', ['payload' => $webhook]));
okAnswer('handle: chart.build', $api->handle('chart.build', ['type' => 'line', 'data' => [['label' => 'a', 'value' => 1]]]));
$noUser = $api->handle('export.create', ['data' => $rows, 'format' => 'csv']);
badAnswer('handle: needs a user where one is required', $noUser, 'user');

// ─── assembly rules ──────────────────────────────────────────────────────────

$withOwn = new AssistantApi($pdo, ['llm' => new ApiLlm(), 'export' => new ExportService(null, new ExcelleInsights\AiAssistant\Support\SchemaPolicy())]);
$noPdoExport = $withOwn->createExport($rows, 'csv', apiUser());
okAnswer('inject: the host service is used', $noPdoExport);
check('inject: no record without a database', ($noPdoExport['id'] ?? '') === '', json_encode($noPdoExport));

$wrongType = new AssistantApi($pdo, ['export' => new stdClass()]);
// The array surface reports the mistake; the object surface refuses to build.
badAnswer('inject: wrong type is reported', $wrongType->exportFormats(), 'ExportService');
try {
    $wrongType->service('export');
    check('inject: wrong type is refused', false, 'nothing threw');
} catch (Throwable $e) {
    check('inject: wrong type is refused', str_contains($e->getMessage(), 'ExportService'), $e->getMessage());
}

$schemaApi = new AssistantApi($pdo, ['llm' => new ApiLlm(), 'schema' => new SchemaService(null)]);
badAnswer('inject: a headless schema service reports itself', $schemaApi->listSchema(), 'no database');

$serviceCheck = $api->service('export');
check('service(): hands back the live one', $serviceCheck instanceof ExportService, get_debug_type($serviceCheck));
$assistantCheck = $api->service('assistant');
check('service(): builds the runtime', $assistantCheck instanceof ExcelleInsights\AiAssistant\Services\AssistantService, get_debug_type($assistantCheck));

$brokenLlm = new AssistantApi($pdo, ['llm' => new class implements LlmClientInterface {
    public function chat(array $messages, string $model = '', ?float $temperature = null): array
    {
        throw new RuntimeException('provider is down');
    }

    public function embeddings(string $input, string $model = ''): array
    {
        return [];
    }
}], apiOptions($dir));
$degraded = $brokenLlm->chat('how many orders', apiUser());
okAnswer('broken model: still answers', $degraded);
same('broken model: read the question itself', 'guessed', $degraded['answer']['meta']['routed_by'] ?? null);
same('broken model: still answers from real rows', true, $degraded['answer']['grounded'] ?? null);
check('broken model: says where the number came from', stripos((string) $degraded['answer']['text'], 'orders') !== false, (string) $degraded['answer']['text']);

$noRows = new AssistantApi(null, ['llm' => new ApiLlm(['intent' => 'data', 'plan' => ['dataset' => 'orders', 'metrics' => ['records']]])], apiOptions($dir));
$noConn = $noRows->runRawSql('SELECT id FROM orders', apiUser(['roles' => ['admin']]));
badAnswer('no connection: raw sql says so', $noConn, 'no database connection');

$diagAgain = $api->diagnostics();
okAnswer('diagnostics: with a database', $diagAgain);
same('diagnostics: connected', true, $diagAgain['database']['connected']);
same('diagnostics: nothing missing', [], $diagAgain['database']['missing']);
same('diagnostics: llm key reported as a flag', 'unset', $diagAgain['llm']['api_key']);
same('diagnostics: handler wired', true, $diagAgain['wiring']['action_handler']);
same('diagnostics: notifier wired', true, $diagAgain['wiring']['notifier']);
same('diagnostics: sender wired', false, $diagAgain['wiring']['whatsapp_sender']);
check('diagnostics: prefix shown', ($diagAgain['package']['table_prefix'] ?? '') === 'ai', json_encode($diagAgain['package']));
check('diagnostics: no secret leaked', !str_contains(json_encode($diagAgain), 'sk-'), json_encode($diagAgain['llm']));

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
