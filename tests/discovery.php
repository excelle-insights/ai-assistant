<?php

/**
 * The install: read a CRM, draft its vocabulary, write it down, prove it.
 *
 * A fixture CRM stands in for any application: three tables, a catalog the
 * operator described, one verified relation and one query the code runs. The
 * discovery engine must turn that into a pack and a knowledge file, and then
 * answer a question with real SQL and real rows.
 *
 *   php tests/discovery.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Contracts\CodeScannerInterface;
use ExcelleInsights\AiAssistant\Http\AssistantApi;
use ExcelleInsights\AiAssistant\Services\CodeIndexService;
use ExcelleInsights\AiAssistant\Services\DiscoveryService;
use ExcelleInsights\AiAssistant\Services\KnowledgeService;
use ExcelleInsights\AiAssistant\Services\RelationService;
use ExcelleInsights\AiAssistant\Services\SchemaService;
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
 * A CRM's code, read from a fixture instead of a disk.
 */
final class FixtureScanner implements CodeScannerInterface
{
    public function files(array $paths, array $options = []): array
    {
        return [[
            'path' => '/app/billing/list.php',
            'hash' => 'fixture-hash',
            'size' => 240,
            'mtime' => 1750000000,
            'module' => 'Billing',
        ]];
    }

    public function statements(array $files, array $options = []): array
    {
        return [[
            'path' => '/app/billing/list.php',
            'module' => 'Billing',
            'function' => 'billing_list',
            'sql' => "SELECT p.id, p.amount FROM payments p WHERE p.company_id = 7 AND p.status = 'paid'",
            'tables' => ['payments'],
            'joins' => [],
            'where' => ["p.status = 'paid'"],
        ]];
    }
}

Config::reset();
Config::set('table_prefix', 'ai_assistant');
Config::set('tenant.column', 'company_id');
Config::set('knowledge.retrieval', 'lexical');

// ─── the CRM ──────────────────────────────────────────────────────────────────

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, company_id INTEGER)');
$pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, customer_id INTEGER, amount REAL, status TEXT, company_id INTEGER, issued_at TEXT)');
$pdo->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY, customer_id INTEGER, amount REAL, status TEXT, company_id INTEGER, created_at TEXT)');

$pdo->exec("INSERT INTO customers VALUES (1, 'Acme Ltd', 7)");
$pdo->exec("INSERT INTO invoices VALUES (1, 1, 500.0, 'open', 7, '2026-01-10'), (2, 1, 250.0, 'paid', 7, '2026-02-10')");
$pdo->exec("INSERT INTO payments VALUES (1, 1, 500.0, 'paid', 7, '2026-01-11')");

// The catalogue the operator described.
$pdo->exec('CREATE TABLE ai_assistant_schema ('
    . 'table_name TEXT, column_name TEXT, data_type TEXT, description TEXT, label TEXT, table_label TEXT,'
    . 'sensitivity TEXT, is_measure INTEGER, format TEXT, aliases TEXT, description_source TEXT)');

$catalogRows = [
    ['customers', '', '', 'The people who buy', 'Customers', 'Customers', 'internal', 0, 'text', '', 'auto'],
    ['customers', 'id', 'int', '', 'Customer id', 'Customers', 'internal', 0, 'number', '', 'auto'],
    ['customers', 'name', 'varchar', 'The full name', 'Name', 'Customers', 'internal', 0, 'text', '', 'auto'],
    ['customers', 'company_id', 'int', '', 'Company', 'Customers', 'internal', 0, 'number', '', 'auto'],
    ['invoices', '', '', 'What the customer owes', 'Invoices', 'Invoices', 'internal', 0, 'text', '', 'auto'],
    ['invoices', 'id', 'int', '', 'Invoice id', 'Invoices', 'internal', 0, 'number', '', 'auto'],
    ['invoices', 'customer_id', 'int', '', 'Customer', 'Invoices', 'internal', 0, 'number', '', 'auto'],
    ['invoices', 'amount', 'decimal', '', 'Amount', 'Invoices', 'internal', 1, 'money', '', 'auto'],
    ['invoices', 'status', 'varchar', '', 'Status', 'Invoices', 'internal', 0, 'text', '', 'auto'],
    ['invoices', 'company_id', 'int', '', 'Company', 'Invoices', 'internal', 0, 'number', '', 'auto'],
    ['invoices', 'issued_at', 'date', '', 'Issued', 'Invoices', 'internal', 0, 'date', '', 'auto'],
    ['payments', '', '', 'What was paid', 'Payments', 'Payments', 'internal', 0, 'text', '', 'auto'],
    ['payments', 'id', 'int', '', 'Payment id', 'Payments', 'internal', 0, 'number', '', 'auto'],
    ['payments', 'customer_id', 'int', '', 'Customer', 'Payments', 'internal', 0, 'number', '', 'auto'],
    ['payments', 'amount', 'decimal', '', 'Amount', 'Payments', 'internal', 1, 'money', '', 'auto'],
    ['payments', 'status', 'varchar', '', 'Status', 'Payments', 'internal', 0, 'text', '', 'auto'],
    ['payments', 'company_id', 'int', '', 'Company', 'Payments', 'internal', 0, 'number', '', 'auto'],
    ['payments', 'created_at', 'datetime', '', 'Created', 'Payments', 'internal', 0, 'datetime', '', 'auto'],
];
$insert = $pdo->prepare(
    'INSERT INTO ai_assistant_schema (table_name, column_name, data_type, description, label, table_label,'
    . 'sensitivity, is_measure, format, aliases, description_source) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
);
foreach ($catalogRows as $row) {
    $insert->execute($row);
}

// The relations that were reviewed.
$pdo->exec('CREATE TABLE ai_assistant_relations ('
    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL DEFAULT 0, source_table TEXT,'
    . 'source_column TEXT, target_table TEXT, target_column TEXT, label_column TEXT, method TEXT,'
    . 'confidence REAL DEFAULT 0, status TEXT DEFAULT \'proposed\', meta_json TEXT, updated_by TEXT,'
    . 'created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE UNIQUE INDEX ai_assistant_relation_source ON ai_assistant_relations (company_id, source_table, source_column)');
$pdo->exec("INSERT INTO ai_assistant_relations (company_id, source_table, source_column, target_table, target_column,"
    . " label_column, method, confidence, status) VALUES (0, 'invoices', 'customer_id', 'customers', 'id', 'name',"
    . " 'convention', 1.0, 'verified')");

// The package's own tables.
$pdo->exec('CREATE TABLE ai_assistant_knowledge ('
    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, title TEXT, content TEXT,'
    . 'embedding TEXT, source TEXT DEFAULT "manual", status TEXT DEFAULT "active", created_at TEXT,'
    . 'updated_at TEXT, category TEXT, content_hash TEXT, scope TEXT DEFAULT "global", scope_key TEXT,'
    . 'doc_id TEXT, token_count INTEGER, metadata_json TEXT, updated_by TEXT, pinned INTEGER DEFAULT 0)');
$pdo->exec('CREATE INDEX ai_assistant_know_tenant ON ai_assistant_knowledge (company_id)');

$pdo->exec('CREATE TABLE ai_assistant_code_files ('
    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL DEFAULT 0, path TEXT,'
    . 'hash TEXT DEFAULT "", size INTEGER DEFAULT 0, mtime INTEGER DEFAULT 0, module TEXT DEFAULT "",'
    . 'statements INTEGER DEFAULT 0, meta_json TEXT, updated_by TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE UNIQUE INDEX ai_assistant_code_file_path ON ai_assistant_code_files (company_id, path)');

// ─── the services ─────────────────────────────────────────────────────────────

$policy = new SchemaPolicy();
$schema = new SchemaService($pdo, $policy);
$knowledge = new KnowledgeService($pdo, null);
$relations = new RelationService($pdo, $schema, $policy);
$code = new CodeIndexService($pdo, $knowledge, new FixtureScanner());
$discovery = new DiscoveryService($pdo, $schema, $relations, $code, $knowledge, $policy);

// The code is read first: the modules and the rules come from it.
$scan = $code->scan(7, ['paths' => ['/app']]);
check('scan: the query was indexed', ($scan['stored'] ?? 0) >= 1, json_encode($scan));

// ─── the drafted semantic layer ───────────────────────────────────────────────

$pack = $discovery->draftPack(7);

same('pack: one dataset per table', 3, count($pack['datasets'] ?? []));
same('pack: one entity per table', 3, count($pack['entities'] ?? []));

$datasets = [];
foreach ($pack['datasets'] as $dataset) {
    $datasets[(string) $dataset['name']] = $dataset;
}
check('pack: the tables are named', isset($datasets['invoices'], $datasets['customers'], $datasets['payments']), implode(', ', array_keys($datasets)));

$invoices = $datasets['invoices'] ?? [];
$metrics = [];
foreach ($invoices['metrics'] as $metric) {
    $metrics[(string) $metric['name']] = $metric;
}
check('pack: a row count is always there', isset($metrics['records']), implode(', ', array_keys($metrics)));
same('pack: the row count counts rows', 'count', $metrics['records']['function'] ?? '');
check('pack: the money column became a metric', isset($metrics['amount']), implode(', ', array_keys($metrics)));
same('pack: the money metric is summed', 'sum', $metrics['amount']['function'] ?? '');
same('pack: the money metric keeps its format', 'money', $metrics['amount']['format'] ?? '');
same('pack: the date column windows the dataset', 'issued_at', $invoices['date_field'] ?? '');

$dimensions = [];
foreach ($invoices['dimensions'] as $dimension) {
    $dimensions[(string) $dimension['name']] = $dimension;
}
check('pack: the key kept its own column', isset($dimensions['customer_id']), implode(', ', array_keys($dimensions)));
check('pack: the name behind the key is there', isset($dimensions['customer_name']), implode(', ', array_keys($dimensions)));
same('pack: the name comes from the joined table', 'customers.name', $dimensions['customer_name']['column'] ?? '');

$joins = [];
foreach ($invoices['joins'] as $join) {
    $joins[(string) $join['table']] = $join;
}
check('pack: the join was declared', isset($joins['customers']), implode(', ', array_keys($joins)));
same('pack: the join is on the key', 'invoices.customer_id', array_key_first((array) ($joins['customers']['on'] ?? [])));

// A table with nothing to measure has no money metric.
$customers = $datasets['customers'] ?? [];
same('pack: a table without measures has one metric', 1, count($customers['metrics'] ?? []));

// The entity knows which column holds the title.
$entities = [];
foreach ($pack['entities'] as $entity) {
    $entities[(string) $entity['name']] = $entity;
}
same('pack: the entity knows its title column', 'name', $entities['customers']['title_column'] ?? '');
same('pack: the entity can be searched by name', ['name'], $entities['customers']['search_columns'] ?? []);

// ─── the drafted knowledge ────────────────────────────────────────────────────

$entries = $discovery->draftKnowledge(7);

$titles = array_column($entries, 'title');
check('knowledge: the module was found', in_array('Module: Billing', $titles, true), implode(', ', $titles));
check('knowledge: the rule in the code was found', in_array('Rule: p.status = paid', $titles, true), implode(', ', $titles));
check('knowledge: the glossary was written', in_array('Glossary: invoices', $titles, true), implode(', ', $titles));

$byTitle = [];
foreach ($entries as $entry) {
    $byTitle[(string) $entry['title']] = $entry;
}
same('knowledge: the module is a module', 'module', $byTitle['Module: Billing']['category'] ?? '');
same('knowledge: the rule is a rule', 'rule', $byTitle['Rule: p.status = paid']['category'] ?? '');
same('knowledge: a drafted rule waits for a person', 'draft', $byTitle['Rule: p.status = paid']['metadata']['verification'] ?? '');
check(
    'knowledge: the rule says what it means',
    str_contains((string) ($byTitle['Rule: p.status = paid']['metadata']['rule'] ?? ''), 'p.status'),
    (string) ($byTitle['Rule: p.status = paid']['metadata']['rule'] ?? ''),
);
same('knowledge: the rule cites its file', '/app/billing/list.php', $byTitle['Rule: p.status = paid']['metadata']['source_file'] ?? '');
same('knowledge: the glossary is a glossary', 'glossary', $byTitle['Glossary: invoices']['category'] ?? '');
check(
    'knowledge: the glossary explains the columns',
    str_contains((string) ($byTitle['Glossary: invoices']['content'] ?? ''), 'customer_id'),
    (string) ($byTitle['Glossary: invoices']['content'] ?? ''),
);

// ─── the two files ────────────────────────────────────────────────────────────

$out = sys_get_temp_dir() . '/ai-assistant-discovery-' . getmypid();
@mkdir($out, 0777, true);

register_shutdown_function(static function () use ($out): void {
    @unlink($out . '/' . DiscoveryService::PACK_FILE);
    @unlink($out . '/' . DiscoveryService::KNOWLEDGE_FILE);
    @rmdir($out);
});

$written = $discovery->writeFiles($pack, $entries, ['output_dir' => $out]);

check('files: the pack was written', $written['written'] === true, (string) ($written['error'] ?? ''));
check('files: the pack file is there', is_file($out . '/' . DiscoveryService::PACK_FILE));
check('files: the knowledge file is there', is_file($out . '/' . DiscoveryService::KNOWLEDGE_FILE));

$packFile = json_decode((string) file_get_contents($out . '/' . DiscoveryService::PACK_FILE), true);
check('files: the pack file holds one pack', is_array($packFile) && count($packFile) === 1, json_encode($packFile));
same('files: the pack file holds the datasets', 3, count($packFile[0]['datasets'] ?? []));

$knowledgeFile = json_decode((string) file_get_contents($out . '/' . DiscoveryService::KNOWLEDGE_FILE), true);
check('files: the knowledge file holds the entries', count($knowledgeFile['entries'] ?? []) === count($entries));

// The pack file is read back like any other pack.
Config::set('semantic.pack_file', $out . '/' . DiscoveryService::PACK_FILE);
$configured = DomainPack::configuredPacks();
same('files: the pack file is read back', 1, count($configured));
same('files: it brings its datasets', 3, count($configured[0]['datasets'] ?? []));

$reloaded = new DomainPack($configured, $schema->catalog(true), ['auto_derive' => false]);
same('files: the semantic layer reads it back', 3, count($reloaded->listDatasets()));
check('files: the name column survived', $reloaded->dimension('invoices', 'customer_name') !== null);

// ─── loading the knowledge back ───────────────────────────────────────────────

$imported = $discovery->importKnowledgeFile(7, $out . '/' . DiscoveryService::KNOWLEDGE_FILE, 'installer');

same('import: every entry was read', count($entries), $imported['loaded']);
same('import: they were new', count($entries), $imported['created']);
same('import: nothing failed', [], $imported['errors']);

// A second import changes nothing: the file is the same file.
$again = $discovery->importKnowledgeFile(7, $out . '/' . DiscoveryService::KNOWLEDGE_FILE, 'installer');
same('import: a second run creates nothing', 0, $again['created']);
same('import: a second run changes nothing', 0, $again['updated']);
same('import: a second run is all unchanged', count($entries), $again['unchanged']);

// A file that is not there is an error, not a crash.
$missing = $discovery->importKnowledgeFile(7, $out . '/nothing.json');
check('import: a missing file is reported', $missing['errors'] !== [], json_encode($missing));

// ─── the proof ────────────────────────────────────────────────────────────────

$selftest = $discovery->selfTest(7, $pack);

same('selftest: every dataset was proved', 3, count($selftest));

$answers = [];
foreach ($selftest as $answer) {
    $answers[(string) $answer['dataset']] = $answer;
}
check('selftest: the questions were answered', ($answers['invoices']['status'] ?? '') === 'ok', json_encode($answers['invoices'] ?? []));
same('selftest: the invoice question counts rows', 'records', $answers['invoices']['metric'] ?? '');
check('selftest: the SQL names the table', str_contains((string) ($answers['invoices']['sql'] ?? ''), 'FROM `invoices`'), (string) ($answers['invoices']['sql'] ?? ''));
check(
    'selftest: the SQL is scoped to the tenant',
    str_contains((string) ($answers['invoices']['sql'] ?? ''), '`company_id` = :__tenant'),
    (string) ($answers['invoices']['sql'] ?? ''),
);
same('selftest: the rows came back', 2, (int) ($answers['invoices']['rows'][0]['records'] ?? 0));

// A question about money sums the money.
$money = $discovery->selfTest(7, $pack, ['What is the total amount of invoices?']);
same('selftest: the money question sums', 'amount', $money[0]['metric'] ?? '');
check('selftest: the SQL sums', str_contains((string) ($money[0]['sql'] ?? ''), 'SUM('), (string) ($money[0]['sql'] ?? ''));
same('selftest: the money is right', 750.0, (float) ($money[0]['rows'][0]['amount'] ?? 0));

// A question that names no table is not answered by guessing.
$none = $discovery->selfTest(7, $pack, ['zzzqqq']);
same('selftest: an unrelated question is not routed', 'unrouted', $none[0]['status'] ?? '');

// ─── the whole run ────────────────────────────────────────────────────────────

$report = $discovery->run(7, [
    'code' => ['paths' => ['/app']],
    'files' => ['output_dir' => $out],
    'questions' => ['How many invoices are there?'],
]);

check('run: the code was read', ($report['steps']['code']['files'] ?? 0) >= 1, json_encode($report['steps']['code'] ?? []));
same('run: nothing was read twice', 0, $report['steps']['code']['stored'] ?? -1);
same('run: the pack was drafted', 3, $report['steps']['pack']['datasets'] ?? 0);
same('run: the knowledge was drafted', count($entries), $report['steps']['knowledge']['entries'] ?? 0);
check('run: the files were written', $report['files']['written'] === true, (string) ($report['files']['error'] ?? ''));
same('run: the questions were proved', 1, count($report['selftest'] ?? []));

// A step that fails is recorded, not fatal: the schema cannot be synced on
// SQLite, but the rest of the run still finishes.
check('run: the failed step is recorded', $report['errors'] !== [], json_encode($report['errors']));
check('run: the run still finished', ($report['steps']['pack']['datasets'] ?? 0) === 3);

// ─── the surface a host calls ─────────────────────────────────────────────────

$api = new AssistantApi($pdo, ['discovery' => $discovery], ['settings' => ['enabled' => false]]);
$draft = $api->handle('discover.draft', ['tenant_id' => 7]);
check('api: the draft answers', ($draft['ok'] ?? false) === true, (string) ($draft['error'] ?? ''));
same('api: it brings the pack', 3, count($draft['pack']['datasets'] ?? []));
check('api: it brings the knowledge', count($draft['knowledge'] ?? []) === count($entries));

$importedThroughApi = $api->handle('discover.knowledge-import', [
    'tenant_id' => 7,
    'path' => $out . '/' . DiscoveryService::KNOWLEDGE_FILE,
]);
check('api: the knowledge file imports', ($importedThroughApi['ok'] ?? false) === true, (string) ($importedThroughApi['error'] ?? ''));

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
