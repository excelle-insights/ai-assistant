<?php

/**
 * Key-to-name relations: what a key column stands for.
 *
 * The service reads a real (SQLite) catalog and real rows, proposes relations
 * from the naming convention and the values themselves, and the semantic layer
 * turns an approved relation into a readable name column.
 *
 *   php tests/relations.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Dto\QueryPlan;
use ExcelleInsights\AiAssistant\Services\QueryCompiler;
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

Config::reset();
Config::set('table_prefix', 'ai_assistant');
Config::set('schema_policy.mode', 'denylist');
Config::set('schema_policy.blocked_columns', ['secret_code']);
Config::set('schema_policy.masked_columns', ['phone']);
Config::set('tenant.column', 'company_id');
Config::set('relations.auto_verify', true);
Config::set('relations.auto_verify_threshold', 0.9);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE staff (id INTEGER PRIMARY KEY, name TEXT, phone TEXT)');
$pdo->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY, staff_id INTEGER, amount REAL, company_id INTEGER, created_at TEXT)');
$pdo->exec('CREATE TABLE leads (id INTEGER PRIMARY KEY, officer_id INTEGER, title TEXT, company_id INTEGER)');
$pdo->exec('CREATE TABLE officers (id INTEGER PRIMARY KEY, full_name TEXT)');
$pdo->exec('CREATE TABLE agents (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec("INSERT INTO staff VALUES (1, 'Peter Otieno', '0700'), (2, 'Amina Yusuf', '0701')");
$pdo->exec("INSERT INTO payments VALUES (1, 1, 900.0, 7, '2026-01-05'), (2, 2, 300.0, 7, '2026-02-05')");
$pdo->exec("INSERT INTO officers VALUES (1, 'Madina Khalid')");
$pdo->exec("INSERT INTO leads VALUES (1, 1, 'Water plot', 7)");
$pdo->exec("INSERT INTO agents VALUES (1, 'Dipa Suthar')");

// The catalogue the schema service reads.
$pdo->exec('CREATE TABLE ai_assistant_schema ('
    . 'table_name TEXT, column_name TEXT, data_type TEXT, description TEXT, label TEXT, table_label TEXT,'
    . 'sensitivity TEXT, is_measure INTEGER, format TEXT, aliases TEXT, description_source TEXT)');

$catalogRows = [
    ['staff', '', '', 'The people who collect', 'Staff', 'Staff', 'internal', 0, 'text', '', 'auto'],
    ['staff', 'id', 'int', '', 'Staff id', 'Staff', 'internal', 0, 'number', '', 'auto'],
    ['staff', 'name', 'varchar', 'Full name', 'Name', 'Staff', 'internal', 0, 'text', '', 'auto'],
    ['staff', 'phone', 'varchar', '', 'Phone', 'Staff', 'pii', 0, 'text', '', 'auto'],
    ['payments', '', '', '', 'Payments', 'Payments', 'internal', 0, 'text', '', 'auto'],
    ['payments', 'id', 'int', '', 'Payment id', 'Payments', 'internal', 0, 'number', '', 'auto'],
    ['payments', 'staff_id', 'int', '', 'Staff id', 'Payments', 'internal', 0, 'number', '', 'auto'],
    ['payments', 'amount', 'decimal', '', 'Amount', 'Payments', 'internal', 1, 'money', '', 'auto'],
    ['payments', 'company_id', 'int', '', 'Company', 'Payments', 'internal', 0, 'number', '', 'auto'],
    ['payments', 'created_at', 'datetime', '', 'Created', 'Payments', 'internal', 0, 'datetime', '', 'auto'],
    ['leads', '', '', '', 'Leads', 'Leads', 'internal', 0, 'text', '', 'auto'],
    ['leads', 'id', 'int', '', 'Lead id', 'Leads', 'internal', 0, 'number', '', 'auto'],
    ['leads', 'officer_id', 'int', 'Officer (user id)', 'Officer (user id)', 'Leads', 'internal', 0, 'number', '', 'auto'],
    ['leads', 'title', 'varchar', '', 'Title', 'Leads', 'internal', 0, 'text', '', 'auto'],
    ['leads', 'company_id', 'int', '', 'Company', 'Leads', 'internal', 0, 'number', '', 'auto'],
    ['officers', '', '', '', 'Officers', 'Officers', 'internal', 0, 'text', '', 'auto'],
    ['officers', 'id', 'int', '', 'Officer id', 'Officers', 'internal', 0, 'number', '', 'auto'],
    ['officers', 'full_name', 'varchar', '', 'Full name', 'Officers', 'internal', 0, 'text', '', 'auto'],
    ['agents', '', '', '', 'Agents', 'Agents', 'internal', 0, 'text', '', 'auto'],
    ['agents', 'id', 'int', '', 'Agent id', 'Agents', 'internal', 0, 'number', '', 'auto'],
    ['agents', 'name', 'varchar', '', 'Name', 'Agents', 'internal', 0, 'text', '', 'auto'],
];
$insert = $pdo->prepare(
    'INSERT INTO ai_assistant_schema (table_name, column_name, data_type, description, label, table_label,'
    . 'sensitivity, is_measure, format, aliases, description_source) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
);
foreach ($catalogRows as $row) {
    $insert->execute($row);
}

// The table the relations live in.
$pdo->exec('CREATE TABLE ai_assistant_relations ('
    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL DEFAULT 0, source_table TEXT,'
    . 'source_column TEXT, target_table TEXT, target_column TEXT, label_column TEXT, method TEXT,'
    . 'confidence REAL DEFAULT 0, status TEXT DEFAULT \'proposed\', meta_json TEXT, updated_by TEXT,'
    . 'created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE UNIQUE INDEX ai_assistant_relation_source ON ai_assistant_relations (company_id, source_table, source_column)');

$policy = new SchemaPolicy();
$schema = new SchemaService($pdo, $policy);
$relations = new RelationService($pdo, $schema, $policy);

// ─── discovery ───────────────────────────────────────────────────────────────

$discovery = $relations->discover(7);

check('discovery: the key column was scanned', $discovery['scanned'] >= 2, 'scanned ' . $discovery['scanned']);
check('discovery: a relation was proposed', $discovery['relations'] !== [], 'none found');

$proposed = [];
foreach ($discovery['relations'] as $relation) {
    $proposed[$relation['source_table'] . '.' . $relation['source_column']] = $relation;
}

check('discovery: staff_id points at the staff table', isset($proposed['payments.staff_id']), implode(', ', array_keys($proposed)));
same('discovery: the staff relation is verified', 'verified', $proposed['payments.staff_id']['status'] ?? '');
same('discovery: the staff relation names the staff table', 'staff', $proposed['payments.staff_id']['target_table'] ?? '');
same('discovery: the staff relation reads the name column', 'name', $proposed['payments.staff_id']['label_column'] ?? '');
check(
    'discovery: the staff relation is certain',
    (float) ($proposed['payments.staff_id']['confidence'] ?? 0) >= 0.99,
    'confidence ' . var_export($proposed['payments.staff_id']['confidence'] ?? 0, true)
);
same('discovery: the method is the naming convention', 'convention', $proposed['payments.staff_id']['method'] ?? '');

check('discovery: officer_id points at the officers table', isset($proposed['leads.officer_id']), implode(', ', array_keys($proposed)));
same('discovery: the officer relation reads the full name', 'full_name', $proposed['leads.officer_id']['label_column'] ?? '');

// A key whose values exist nowhere near a candidate is not a relation.
check('discovery: an unconnected key is not a relation', !isset($proposed['payments.company_id']), implode(', ', array_keys($proposed)));

// The masked phone column is never the label.
foreach ($discovery['relations'] as $relation) {
    check('discovery: no masked column is used as a label', $relation['label_column'] !== 'phone', $relation['label_column']);
}

// ─── review ──────────────────────────────────────────────────────────────────

$list = $relations->list(7);
check('review: the relations are listed', $list !== [], 'empty');

$id = 0;
foreach ($list as $row) {
    if ($row['source_table'] === 'payments' && $row['source_column'] === 'staff_id') {
        $id = (int) $row['id'];
    }
}
check('review: the staff relation has an id', $id > 0, 'id ' . $id);

$rejected = $relations->reject(7, $id);
same('review: a rejected relation is marked rejected', 'rejected', $rejected['status'] ?? '');
$approvedNow = $relations->approved(7);
$stillApproved = false;
foreach ($approvedNow as $row) {
    if ($row['source_table'] === 'payments' && $row['source_column'] === 'staff_id') {
        $stillApproved = true;
    }
}
check('review: a rejected relation is not approved', !$stillApproved, 'still approved');

$relations->approve(7, $id);
$approved = $relations->approved(7);
check('review: an approved relation comes back', $approved !== [], 'empty');
$approvedKey = '';
foreach ($approved as $row) {
    if ($row['source_table'] === 'payments' && $row['source_column'] === 'staff_id') {
        $approvedKey = $row['source_table'] . '.' . $row['source_column'];
    }
}
same('review: the approved relation is the right one', 'payments.staff_id', $approvedKey);

// A rejected relation never comes back on its own.
$relations->reject(7, $id);
$relations->discover(7);
$after = $relations->list(7, ['status' => 'verified']);
$stillVerified = false;
foreach ($after as $row) {
    if ($row['source_table'] === 'payments' && $row['source_column'] === 'staff_id') {
        $stillVerified = true;
    }
}
check('review: a rejected relation is not revived by a new scan', !$stillVerified, 'came back');

// ─── the semantic layer ──────────────────────────────────────────────────────

$relations->approve(7, $id);
$pack = new DomainPack([], $schema->catalog(true), [
    'relations' => $relations->approved(7),
]);

$dataset = $pack->dataset('payments');
check('pack: the dataset exists', $dataset !== null);
$names = [];
foreach ((array) ($dataset['dimensions'] ?? []) as $dimension) {
    $names[] = (string) $dimension['name'];
}
check('pack: the name column was added', in_array('staff_name', $names, true), implode(', ', $names));
check('pack: the key column is still there', in_array('staff_id', $names, true), implode(', ', $names));

$joinTables = [];
foreach ((array) ($dataset['joins'] ?? []) as $join) {
    $joinTables[] = (string) ($join['table'] ?? '');
}
check('pack: the join was declared', in_array('staff', $joinTables, true), implode(', ', $joinTables));

$notes = $pack->relationNotes('payments');
check('pack: the relation is traceable', $notes !== [], 'no notes');
same('pack: the note names the source', 'payments.staff_id', $notes[0]['source'] ?? '');

// A curated pack keeps its own name column.
$curated = new DomainPack([[
    'name' => 'sales',
    'datasets' => [[
        'name' => 'payments',
        'table' => 'payments',
        'metrics' => [['name' => 'amount', 'function' => 'sum', 'column' => 'amount', 'format' => 'money']],
        'dimensions' => [['name' => 'staff_name', 'label' => 'Sales person', 'column' => 'staff_id']],
    ]],
]], $schema->catalog(true), ['relations' => $relations->approved(7)]);
$curatedDimension = $curated->dimension('payments', 'staff_name');
same('pack: a curated column wins', 'Sales person', $curatedDimension['label'] ?? '');
same('pack: a curated column keeps its own expression', 'staff_id', $curatedDimension['column'] ?? '');

// ─── the compiled query ──────────────────────────────────────────────────────

$columns = [];
foreach ($schema->catalog(true) as $table => $entry) {
    $list2 = [];
    foreach ((array) ($entry['columns'] ?? []) as $column) {
        $list2[] = (string) $column['name'];
    }
    $columns[(string) $table] = $list2;
}
$compiler = new QueryCompiler($pack, $policy, $columns);
$plan = QueryPlan::fromArray([
    'dataset' => 'payments',
    'dimensions' => ['staff_name'],
    'metrics' => ['amount'],
]);
$compiled = $compiler->compile($plan, []);
check('query: the name column is joined', str_contains($compiled['sql'], 'LEFT JOIN `staff`'), $compiled['sql']);
check('query: the name column is selected', str_contains($compiled['sql'], '`staff`.`name`'), $compiled['sql']);
check('query: the join is on the key', str_contains($compiled['sql'], '`payments`.`staff_id` = `staff`.`id`'), $compiled['sql']);

$rows = $pdo->query($compiled['sql'])->fetchAll(PDO::FETCH_ASSOC);
check('query: the answer carries the name', ($rows[0]['staff_name'] ?? '') === 'Peter Otieno', json_encode($rows));

// ─── a relation the policy does not allow is not used ────────────────────────

Config::set('schema_policy.mode', 'allowlist');
Config::set('schema_policy.allowed_tables', ['payments']);
$strict = new SchemaPolicy();
$strictRelations = new RelationService($pdo, $schema, $strict);
check('policy: a blocked target table is not approved', $strictRelations->approved(7) === [], 'still approved');
Config::set('schema_policy.mode', 'denylist');

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
