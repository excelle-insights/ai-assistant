<?php

/**
 * Export checks: the three gates (host permission, column policy, sensitive
 * confirmation), csv/tsv/json production, download tokens, queued jobs,
 * history and purge. SQLite stands in for the host database; nothing else runs.
 *   php tests/exports.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Contracts\ExporterInterface;
use ExcelleInsights\AiAssistant\Contracts\PermissionResolverInterface;
use ExcelleInsights\AiAssistant\Dto\Answer;
use ExcelleInsights\AiAssistant\Dto\Citation;
use ExcelleInsights\AiAssistant\Dto\ExportRequest;
use ExcelleInsights\AiAssistant\Dto\ExportResult;
use ExcelleInsights\AiAssistant\Dto\QueryResult;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Services\AuditService;
use ExcelleInsights\AiAssistant\Services\ExportService;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\CsvWriter;
use ExcelleInsights\AiAssistant\Support\Db;
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

// ─── fixtures ─────────────────────────────────────────────────────────────────
$dir = sys_get_temp_dir() . '/ai-export-tests-' . getmypid();
@mkdir($dir, 0o775, true);

Config::reset();
Config::set('table_prefix', 'ai');
Config::set('exports.directory', $dir);

function sqlite(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE ai_exports (
        id TEXT PRIMARY KEY, company_id INTEGER, user_id TEXT, conversation_id TEXT,
        format TEXT, file_name TEXT, title TEXT, question TEXT,
        columns_json TEXT, filters_json TEXT, plan_json TEXT,
        row_count INTEGER DEFAULT 0, size_bytes INTEGER DEFAULT 0,
        status TEXT DEFAULT "pending", storage_key TEXT, download_token TEXT, error TEXT,
        expires_at TEXT, completed_at TEXT, created_at TEXT, updated_at TEXT,
        approved_by TEXT, approved_at TEXT
    )');
    $pdo->exec('CREATE TABLE ai_audit (
        id INTEGER PRIMARY KEY, company_id INTEGER, user_id TEXT, channel TEXT, event TEXT, intent TEXT,
        correlation_id TEXT, conversation_id TEXT, model TEXT, tokens INTEGER, cost REAL, latency_ms INTEGER,
        query_hash TEXT, sql_text TEXT, question TEXT, record_refs TEXT, status TEXT, error TEXT, ip TEXT,
        detail_json TEXT, created_at TEXT
    )');

    return $pdo;
}

$pdo = sqlite();

final class NoExporterAllowed implements PermissionResolverInterface
{
    public function __construct(private bool $allowed = true, private string $entity = '')
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
        return [];
    }

    public function canAct(UserContext $user, string $action): bool
    {
        return true;
    }

    public function canExport(UserContext $user, string $entity): bool
    {
        return $this->allowed;
    }
}

/** A host xlsx exporter: returns an external handle, proves the request reached it. */
final class FakeXlsx implements ExporterInterface
{
    public ?ExportRequest $seen = null;

    public function format(): string
    {
        return 'xlsx';
    }

    public function export(ExportRequest $request): ExportResult
    {
        $this->seen = $request;

        return ExportResult::external('xlsx', 'sheets://sheet-42', $request->fileName, ['sheet' => 'sheet-42']);
    }
}

$user = new UserContext('u1', 7, 'web', 'thread-1', ['sales']);
$admin = new UserContext('u9', 7, 'web', 'thread-1', ['admin']);
$rows = [
    ['name' => 'Acme', 'amount' => 120.5, 'customer_phone' => '0712345678'],
    ['name' => 'Bolt', 'amount' => 90.0, 'customer_phone' => '0798765432'],
];

$service = fn (array $opts = []): ExportService => new ExportService(
    pdo: $opts['pdo'] ?? $pdo,
    policy: $opts['policy'] ?? new SchemaPolicy(),
    audit: $opts['audit'] ?? new AuditService($pdo),
    permissions: $opts['permissions'] ?? null,
    rowsRunner: $opts['rowsRunner'] ?? null,
);

// ─── formats ──────────────────────────────────────────────────────────────────
$exports = $service();
$formats = $exports->formats();
check('formats: csv and json are always available', in_array('csv', $formats, true) && in_array('json', $formats, true), json_encode($formats));
check('formats: tsv needs opting in', !in_array('tsv', $formats, true), json_encode($formats));
check('formats: xlsx absent without a host exporter', !in_array('xlsx', $formats, true), json_encode($formats));
check('formats: isEnabled csv', $exports->isEnabled('CSV'));
check('formats: isEnabled rejects pdf', !$exports->isEnabled('pdf'));

Config::set('exports.formats', ['csv', 'json', 'tsv']);
check('formats: tsv once listed', in_array('tsv', $service()->formats(), true), json_encode($service()->formats()));
Config::set('exports.formats', ['csv', 'json']);

$hostXlsx = new FakeXlsx();
$withHost = $service();
$withHost->register('xlsx', $hostXlsx);
check('formats: a registered exporter opts its format in', in_array('xlsx', $withHost->formats(), true), json_encode($withHost->formats()));

$refused = $service()->create($rows, 'pdf', $user, ['title' => 'x']);
check('create: an unenabled format is refused with the list', !$refused->ok && str_contains((string) $refused->error, 'not enabled'), (string) $refused->error);

// ─── the request, without touching storage ────────────────────────────────────
$request = $service()->request($rows, 'csv', $user, ['title' => 'Clients']);
same('request: columns detected from the rows', ['name', 'amount', 'customer_phone'], $request->columns);
same('request: rows kept whole', 2, count($request->rows));
same('request: user carried through', 7, $request->user->tenantId);
check('request: file name built from the title', str_starts_with($request->fileName, 'clients-') && str_ends_with($request->fileName, '.csv'), $request->fileName);

$picked = $service()->request($rows, 'csv', $user, ['title' => 'Clients', 'columns' => ['amount', 'name']]);
same('request: chosen column order wins', ['amount', 'name'], $picked->columns);
same('request: only chosen columns survive', ['amount' => 120.5, 'name' => 'Acme'], $picked->rows[0]);

$missing = $service()->request($rows, 'csv', $user, ['columns' => ['name', 'nickname']]);
same('request: an absent column becomes null', ['name' => 'Acme', 'nickname' => null], $missing->rows[0]);
check('request: an absent column still headers', ($missing->columns[1] ?? '') === 'nickname', json_encode($missing->columns));

Config::set('exports.max_rows', 1);
$cut = $service()->request($rows, 'csv', $user, ['columns' => ['name']]);
same('request: max_rows truncates', 1, count($cut->rows));
same('request: truncation is recorded', true, $cut->options['truncated']);
Config::set('exports.max_rows', 100000);

$fromResult = QueryResult::fromRows($rows, 'SELECT * FROM clients', 'semantic');
$fromQuery = $service()->request($fromResult, 'csv', $user, ['title' => 'q']);
same('request: takes a QueryResult', ['name', 'amount', 'customer_phone'], $fromQuery->columns);

$fromAnswer = new Answer(text: 'ok', intent: 'data', rows: $rows, meta: ['columns' => ['name']]);
$answerRequest = $service()->request($fromAnswer, 'csv', $user, ['title' => 'a']);
same('request: honours the answer columns', ['name'], $answerRequest->columns);
same('request: shapes answer rows to those columns', ['name' => 'Acme'], $answerRequest->rows[0]);

$fromJson = $service()->request(json_encode($rows), 'csv', $user, ['title' => 'j']);
same('request: decodes a json payload', 2, count($fromJson->rows));

$scalarRows = $service()->request([['a' => 1], 'ignore me', ['b' => 2]], 'csv', $user, ['title' => 'mixed']);
same('request: non-array entries are skipped', 2, count($scalarRows->rows));

// ─── file names cannot escape the export directory ────────────────────────────
same('name: traversal neutralised', true, !str_contains(ExportService::safeFileName('../../../../etc/passwd', 'csv'), '/'));
check('name: traversal keeps a csv extension', str_ends_with(ExportService::safeFileName('../../etc/passwd', 'csv'), '.csv'), ExportService::safeFileName('../../etc/passwd', 'csv'));
check('name: spaces and punctuation become dashes', str_contains(ExportService::safeFileName('My Report 2026!', 'csv'), '-'), ExportService::safeFileName('My Report 2026!', 'csv'));
same('name: an existing extension is replaced', 'clients.json', ExportService::safeFileName('clients.csv', 'json'));
same('name: empty falls back to the prefix', 'ai-export.csv', ExportService::safeFileName('   ', 'csv'));
check('name: generated name carries a timestamp', preg_match('/^revenue-by-month-\d{8}-\d{6}-[0-9a-f]{6}\.csv$/', ExportService::fileName('Revenue, by month!', 'csv')) === 1, ExportService::fileName('Revenue, by month!', 'csv'));
check('name: two exports in the same second get different files', ExportService::fileName('same title', 'csv') !== ExportService::fileName('same title', 'csv'));
same('mime: csv', 'text/csv', ExportService::mime('CSV'));
same('mime: xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ExportService::mime('xlsx'));
same('mime: unknown', 'application/octet-stream', ExportService::mime('unknown'));

// ─── the gates ────────────────────────────────────────────────────────────────
Config::set('schema_policy.blocked_columns', ['internal_note']);
Config::set('schema_policy.masked_columns', ['customer_phone']);

$gated = $service()->prepare($service()->request($rows, 'csv', $user, ['title' => 'Clients', 'entity' => 'clients', 'columns' => ['name', 'customer_phone', 'internal_note']]));
same('gate: a blocked column is dropped', ['name', 'customer_phone'], $gated['request']->columns);
same('gate: the dropped column is reported', ['internal_note'], $gated['dropped']);
same('gate: a masked column stays but hides its value', str_repeat('*', 8), $gated['request']->rows[0]['customer_phone']);
same('gate: masking is reported', ['customer_phone'], $gated['masked']);
check('gate: a phone number counts as pii', in_array('customer_phone', $gated['sensitive'], true), json_encode($gated['sensitive']));
same('gate: nothing forbidden without a resolver', null, $gated['forbidden']);

$blocked = $service()->request($rows, 'csv', $user, ['columns' => ['internal_note']]);
$allGone = $service()->prepare($blocked);
same('gate: every column can be blocked', [], $allGone['request']->columns);

$deny = $service(['permissions' => new NoExporterAllowed(false)]);
$denied = $deny->prepare($deny->request($rows, 'csv', $user, ['entity' => 'clients']));
check('gate: canExport false forbids the file', is_string($denied['forbidden']) && str_contains((string) $denied['forbidden'], 'clients'), (string) $denied['forbidden']);

$denyStore = sqlite();
$forbidden = $service(['pdo' => $denyStore, 'audit' => new AuditService($denyStore), 'permissions' => new NoExporterAllowed(false)])
    ->create($rows, 'csv', $user, ['title' => 'Clients', 'entity' => 'clients']);
check('create: a forbidden export never writes', !$forbidden->ok && $forbidden->location === null, json_encode($forbidden));
$refusals = $denyStore->query("SELECT event FROM ai_audit WHERE event = 'ai.export_refused'")->fetchAll(PDO::FETCH_COLUMN);
check('create: the refusal is audited', $refusals !== [], json_encode($refusals));

$nothing = $service()->create([], 'csv', $user, ['title' => 'Empty']);
check('create: no rows says so', !$nothing->ok && str_contains((string) $nothing->error, 'nothing to export'), (string) $nothing->error);

$allBlocked = $service()->create($rows, 'csv', $user, ['title' => 'Only blocked', 'columns' => ['internal_note']]);
check('create: blocked columns give their own reason', !$allBlocked->ok && str_contains((string) $allBlocked->error, 'blocked for your role'), (string) $allBlocked->error);

Config::set('schema_policy.blocked_columns', []);
Config::set('schema_policy.masked_columns', []);

// ─── a ready file, and the link to it ─────────────────────────────────────────
$plain = [['name' => 'Acme', 'amount' => 120.5], ['name' => 'Bolt', 'amount' => 90]];
$store = sqlite();
$svc = $service(['pdo' => $store, 'audit' => new AuditService($store)]);
$ready = $svc->create($plain, 'csv', $user, ['title' => 'Clients', 'question' => 'top clients', 'entity' => 'clients', 'applied_filters' => ['period' => 'last 30 days']]);

check('create: the csv lands in the export directory', $ready->ok && is_string($ready->location) && is_file($ready->location), json_encode($ready));
same('create: bytes are not held in memory', null, $ready->bytes);
same('create: rows counted', 2, $ready->meta['rows']);
same('create: mime offered', 'text/csv', $ready->meta['mime']);
same('create: link lifetime', 1440, $ready->meta['expires_in_minutes']);
$body = (string) file_get_contents((string) $ready->location);
same('csv: starts with a BOM', CsvWriter::BOM, substr($body, 0, 3));
same('csv: header line', CsvWriter::BOM . "name,amount\r\n", substr($body, 0, 16));
check('csv: numbers keep their spelling', str_contains($body, '120.5'), $body);
same('csv: one record per row', 4, count(explode("\r\n", $body)));

$record = $store->query('SELECT * FROM ai_exports')->fetch(PDO::FETCH_ASSOC);
same('record: ready', 'ready', $record['status']);
same('record: row count stored', 2, (int) $record['row_count']);
check('record: size stored', (int) $record['size_bytes'] > 0, json_encode($record['size_bytes']));
same('record: storage key is the path', $ready->location, $record['storage_key']);
same('record: company recorded', 7, (int) $record['company_id']);
same('record: requester recorded', 'u1', $record['user_id']);
same('record: filters kept', '{"period":"last 30 days"}', $record['filters_json']);
same('record: no plan needed for an inline export', null, $record['plan_json']);
check('record: a token was minted', is_string($record['download_token']) && strlen((string) $record['download_token']) === 32, (string) $record['download_token']);
check('record: expiry set', !empty($record['expires_at']) && strtotime((string) $record['expires_at']) > time(), (string) $record['expires_at']);

$token = (string) $record['download_token'];
$found = $svc->resolve($token, $user);
check('resolve: the token finds the file', $found !== null && $found['path'] === $ready->location, json_encode($found));
same('resolve: name offered', $record['file_name'], $found['file_name'] ?? null);
same('resolve: size offered', (int) $record['size_bytes'], $found['size'] ?? null);
check('resolve: another company is refused', $svc->resolve($token, new UserContext('u2', 8, 'web')) === null);
check('resolve: an unknown token is nothing', $svc->resolve(str_repeat('a', 32), $user) === null);
check('resolve: no token, nothing', $svc->resolve('', $user) === null);
$svc->resolve($token);
check('resolve: still readable without claiming a user', is_array($svc->resolve($token)));

$store->exec("UPDATE ai_exports SET expires_at = '2020-01-01 00:00:00'");
check('resolve: an expired link is dead', $svc->resolve($token, $user) === null);
$store->exec("UPDATE ai_exports SET expires_at = '2999-01-01 00:00:00', status = 'queued'");
check('resolve: a job that is not ready has no file', $svc->resolve($token, $user) === null);

$json = $svc->create($plain, 'json', $user, ['title' => 'Clients', 'question' => 'top clients', 'entity' => 'clients', 'applied_filters' => ['period' => 'last 30 days']]);
check('json: file produced', $json->ok && is_file((string) $json->location), (string) $json->error);
$payload = json_decode((string) file_get_contents((string) $json->location), true);
same('json: rows and provenance', 2, count($payload['rows'] ?? []));
same('json: columns listed', ['name', 'amount'], $payload['columns'] ?? null);
same('json: question kept', 'top clients', $payload['question'] ?? null);
same('json: filters kept', ['period' => 'last 30 days'], $payload['applied_filters'] ?? null);
same('json: not truncated', false, $payload['truncated'] ?? null);
check('json: generated_at present', !empty($payload['generated_at']));

Config::set('exports.formats', ['csv', 'json', 'tsv']);
$tabbed = $svc->create($plain, 'tsv', $user, ['title' => 'Clients', 'entity' => 'clients']);
check('tsv: file produced with a tsv name', $tabbed->ok && str_ends_with((string) $tabbed->location, '.tsv'), (string) $tabbed->location);
check('tsv: tab separated', str_contains((string) file_get_contents((string) $tabbed->location), "name\tamount"), (string) file_get_contents((string) $tabbed->location));
Config::set('exports.formats', ['csv', 'json']);

$hostStore = sqlite();
$hostSvc = $service(['pdo' => $hostStore, 'audit' => new AuditService($hostStore)]);
$hostSvc->register('xlsx', new FakeXlsx());
$external = $hostSvc->create($plain, 'xlsx', $user, ['title' => 'Clients', 'entity' => 'clients']);
check('host exporter: result is the external handle', $external->ok && $external->location === 'sheets://sheet-42', json_encode($external));
$hostRow = $hostStore->query('SELECT * FROM ai_exports')->fetch(PDO::FETCH_ASSOC);
same('host exporter: marked ready with no local file', 'ready', $hostRow['status']);
same('host exporter: no storage key', null, $hostRow['storage_key']);

$events = $store->query("SELECT event FROM ai_audit")->fetchAll(PDO::FETCH_COLUMN);
check('audit: ready exports are logged', in_array('ai.export_ready', $events, true), json_encode($events));

// ─── sensitive exports wait for a confirmation ────────────────────────────────
Config::set('schema_policy.masked_columns', ['customer_phone']);
$dir2 = $dir . '-approval';
@mkdir($dir2, 0o775, true);
Config::set('exports.directory', $dir2);
$holdStore = sqlite();
$held = $service(['pdo' => $holdStore, 'audit' => new AuditService($holdStore)])
    ->create($rows, 'csv', $user, ['title' => 'Leads', 'entity' => 'leads', 'plan' => ['dataset' => 'leads']]);

check('approval: no bytes are returned either', $held->bytes === null && !is_file((string) $held->location), (string) $held->location);
same('approval: the job waits', 'awaiting_approval', $held->meta['status'] ?? null);
check('approval: the handle points at the job', str_starts_with((string) $held->location, 'export://'), (string) $held->location);
check('approval: the sensitive columns are named', in_array('customer_phone', (array) ($held->meta['sensitive_columns'] ?? []), true), json_encode($held->meta));
check('approval: the message explains why', str_contains(strtolower((string) ($held->meta['message'] ?? '')), 'approval'), (string) ($held->meta['message'] ?? ''));
same('approval: no file in the directory', [], glob($dir2 . '/*'));
$heldRow = $holdStore->query('SELECT * FROM ai_exports')->fetch(PDO::FETCH_ASSOC);
same('approval: stored as waiting', 'awaiting_approval', $heldRow['status']);
same('approval: the plan is kept for the re-run', '{"dataset":"leads"}', $heldRow['plan_json']);
check('approval: nothing approved yet', $heldRow['approved_by'] === null && $heldRow['approved_at'] === null, json_encode([$heldRow['approved_by'], $heldRow['approved_at']]));

$heldId = (string) $heldRow['id'];
$wrongOwner = $service(['pdo' => $holdStore, 'audit' => new AuditService($holdStore)])
    ->approve($heldId, new UserContext('u5', 8, 'web', 'thread-5', ['sales']));
check('approval: another company cannot approve', !$wrongOwner->ok && str_contains((string) $wrongOwner->error, 'requester or an admin'), (string) $wrongOwner->error);

$adminApproves = $service(['pdo' => $holdStore, 'audit' => new AuditService($holdStore)])->approve('missing-id', $admin);
check('approval: an unknown job is refused', !$adminApproves->ok && str_contains((string) $adminApproves->error, 'No export'), (string) $adminApproves->error);

$noRunner = $service(['pdo' => $holdStore, 'audit' => new AuditService($holdStore)]);
$stuck = $noRunner->approve($heldId, $user);
check('approval: without a runner the rows cannot come back', !$stuck->ok && str_contains((string) $stuck->error, 'runner'), (string) $stuck->error);

$withRunner = $service(['pdo' => $holdStore, 'audit' => new AuditService($holdStore), 'rowsRunner' => fn (ExportRequest $r): array => $rows]);
$go = $withRunner->approve($heldId, $user);
check('approval: the requester confirms and the file is made', $go->ok && is_file((string) $go->location), json_encode($go));
$approvedRow = $holdStore->query('SELECT * FROM ai_exports')->fetch(PDO::FETCH_ASSOC);
same('approval: now ready', 'ready', $approvedRow['status']);
same('approval: who approved recorded', 'u1', $approvedRow['approved_by']);
check('approval: when approved', !empty($approvedRow['approved_at']));
$approvedToken = (string) $approvedRow['download_token'];
check('approval: the original link now serves the file', is_array($withRunner->resolve($approvedToken, $user)));
$maskedBody = (string) file_get_contents((string) $go->location);
check('approval: the file went through the gates', !str_contains($maskedBody, '0712345678'), $maskedBody);
$again = $withRunner->approve($heldId, $user);
check('approval: a finished job cannot be approved twice', !$again->ok && str_contains((string) $again->error, 'awaiting approval'), (string) $again->error);

$adminStore = sqlite();
$adminRun = $service(['pdo' => $adminStore, 'audit' => new AuditService($adminStore)])->create($rows, 'csv', $admin, ['title' => 'Leads', 'entity' => 'leads']);
check('approval: an approver exports without waiting', $adminRun->ok, (string) $adminRun->error);
Config::set('exports.require_confirmation', false);
$openRun = $service(['pdo' => sqlite(), 'audit' => new AuditService($adminStore)])->create($rows, 'csv', $user, ['title' => 'Leads', 'entity' => 'leads']);
check('approval: the gate is configurable', $openRun->ok, (string) $openRun->error);
Config::set('exports.require_confirmation', true);

Config::set('schema_policy.masked_columns', []);

// ─── large exports queue ──────────────────────────────────────────────────────
$dir3 = $dir . '-queue';
@mkdir($dir3, 0o775, true);
Config::set('exports.directory', $dir3);
Config::set('exports.async_above_rows', 1);
$queueStore = sqlite();
$queued = $service(['pdo' => $queueStore, 'audit' => new AuditService($queueStore)])
    ->create($plain, 'csv', $user, ['title' => 'Big', 'entity' => 'clients', 'plan' => ['dataset' => 'clients']]);
same('queue: status', 'queued', $queued->meta['status'] ?? null);
check('queue: nothing written yet', glob($dir3 . '/*') === [], json_encode(glob($dir3 . '/*')));
check('queue: the message says it is background work', str_contains(strtolower((string) ($queued->meta['message'] ?? '')), 'queued'), (string) ($queued->meta['message'] ?? ''));

$noRunnerAgain = $service(['pdo' => $queueStore, 'audit' => new AuditService($queueStore)])->runQueued();
check('queue: a worker with no runner fails the job, not the request', $noRunnerAgain !== [] && !$noRunnerAgain[0]->ok, json_encode($noRunnerAgain[0] ?? null));
$failedRow = $queueStore->query('SELECT status, error FROM ai_exports')->fetch(PDO::FETCH_ASSOC);
same('queue: job marked failed', 'failed', $failedRow['status']);

$queueStore->exec("UPDATE ai_exports SET status = 'queued'");
$worker = $service(['pdo' => $queueStore, 'audit' => new AuditService($queueStore), 'rowsRunner' => fn (ExportRequest $r): array => $plain]);
$done = $worker->runQueued();
check('queue: the worker produces the file', $done !== [] && $done[0]->ok && is_file((string) $done[0]->location), json_encode($done[0] ?? null));
$queuedRow = $queueStore->query('SELECT * FROM ai_exports')->fetch(PDO::FETCH_ASSOC);
same('queue: now ready', 'ready', $queuedRow['status']);
check('queue: the link works', is_array($worker->resolve((string) $queuedRow['download_token'], $user)));
check('queue: an empty run finds nothing left', $service(['pdo' => $queueStore, 'rowsRunner' => fn (): array => $plain])->runQueued() === []);

Config::set('exports.async_above_rows', 5000);

// ─── history and retention ────────────────────────────────────────────────────
$histStore = sqlite();
$hist = $service(['pdo' => $histStore, 'audit' => new AuditService($histStore)]);
$first = $hist->create($plain, 'csv', $user, ['title' => 'One', 'entity' => 'clients']);
$histStore->exec("UPDATE ai_exports SET created_at = '2026-01-01 08:00:00' WHERE title = 'One'");
$second = $hist->create($plain, 'json', $user, ['title' => 'Two', 'entity' => 'clients']);
$mine = $hist->history($user);
same('history: both exports listed', 2, count($mine));
same('history: newest first', 'Two', $mine[0]['title'] ?? null);
check('history: statuses present', ($mine[0]['status'] ?? '') === 'ready' && ($mine[1]['status'] ?? '') === 'ready', json_encode(array_column($mine, 'status')));
check('history: sizes recorded', (int) $mine[0]['size_bytes'] > 0, json_encode($mine[0]));
same('history: another company sees nothing', 0, count($hist->history(new UserContext('u3', 9, 'web'))));
same('history: no database, no rows', 0, count((new ExportService())->history($user)));

// one expired job, then purge
$histStore->exec("UPDATE ai_exports SET expires_at = '2020-01-01 00:00:00' WHERE format = 'json'");
$goneFile = (string) $second->location;
$purged = $hist->purgeExpired();
same('purge: the expired record is gone', 1, $purged[0]);
same('purge: its file is deleted too', 1, $purged[1]);
check('purge: the file no longer exists', !file_exists($goneFile), $goneFile);
same('purge: the live export survives', 1, count($hist->history($user)));
same('purge: nothing left to clean', 0, $hist->purgeExpired()[0]);
$histStore->exec("UPDATE ai_exports SET expires_at = '2020-01-01 00:00:00'");
$missingFile = (string) $first->location;
same('purge: a row whose file is already gone still counts as deleted', 1, $hist->purgeExpired()[0]);

Config::reset();
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);


