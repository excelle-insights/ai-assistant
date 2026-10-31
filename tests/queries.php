<?php

/**
 * The query library: the calculations this CRM already has, by name.
 *
 *   php tests/queries.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Services\QueryLibraryService;
use ExcelleInsights\AiAssistant\Support\Config;

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
Config::set('tenant.column', 'company_id');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE `ai_assistant_queries` (
    id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL DEFAULT 0, name TEXT,
    module TEXT DEFAULT "", purpose TEXT DEFAULT "", sql TEXT, tables TEXT, source_file TEXT DEFAULT "",
    description TEXT, business_description TEXT, status TEXT DEFAULT "proposed", meta_json TEXT,
    updated_by TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE UNIQUE INDEX `ai_assistant_query_name` ON `ai_assistant_queries` (company_id, name)');

$library = new QueryLibraryService($pdo);

// ─── storing ──────────────────────────────────────────────────────────────────

$stored = $library->save(7, [
    'name' => 'Payments collected per staff',
    'module' => 'Payments',
    'purpose' => 'What each staff member collected',
    'business_description' => 'The total amount collected by each staff member, counting only paid payments.',
    'sql' => "SELECT staff_id, SUM(amount) FROM payments WHERE status = 'paid' GROUP BY staff_id",
    'tables' => ['payments'],
    'status' => QueryLibraryService::STATUS_ACTIVE,
], 'admin');

check('save: the query was stored', ($stored['id'] ?? 0) > 0, json_encode($stored));
same('save: its status is kept', 'active', $stored['status'] ?? '');
same('save: its tables are kept', ['payments'], $stored['tables'] ?? []);
same('save: it is owned by the company', 7, $stored['company_id'] ?? 0);
same('save: the person who saved it is remembered', 'admin', $stored['updated_by'] ?? '');

// Saving the same name again updates the row instead of making a second one.
$again = $library->save(7, [
    'name' => 'Payments collected per staff',
    'business_description' => 'The total collected by each staff member, counting only paid payments.',
    'status' => QueryLibraryService::STATUS_ACTIVE,
]);
same('save: the same name updates the row', $stored['id'], $again['id']);
same('save: and there is still one row', 1, count($library->list(7)));

$missing = null;
try {
    $library->save(7, ['purpose' => 'no name at all']);
} catch (Throwable $e) {
    $missing = $e->getMessage();
}
check('save: a query without a name is refused', $missing !== null && str_contains($missing, 'name'), (string) $missing);

// ─── reading ──────────────────────────────────────────────────────────────────

$items = $library->list(7);
same('list: the library holds the query', 1, count($items));
same('list: another company sees nothing', 0, count($library->list(9)));

$found = $library->find((int) $stored['id'], 7);
check('find: the query is there', $found !== null);
same('find: another company cannot reach it', null, $library->find((int) $stored['id'], 9));

// ─── matching a question ──────────────────────────────────────────────────────

$matches = $library->match(7, 'how much did each staff member collect');
check('match: the stored report matches the question', $matches !== [], json_encode($matches));
same('match: it is the right one', 'Payments collected per staff', $matches[0]['name'] ?? '');

same('match: an unrelated question matches nothing', 0, count($library->match(7, 'zzzqqq')));
same('match: another company matches nothing', 0, count($library->match(9, 'how much did each staff member collect')));

$section = $library->promptSection(7, 'how much did each staff member collect', 2);
check('prompt: the section is there', str_contains($section, 'Named reports this CRM already runs'), $section);
check('prompt: it carries the business description', str_contains($section, 'It calculates:'), $section);
check('prompt: it says the SQL is not to be run', str_contains($section, 'never run their SQL'), $section);

// ─── a proposal does not steer ────────────────────────────────────────────────

$library->save(7, [
    'name' => 'Leads per officer proposal',
    'business_description' => 'How many leads each officer got.',
    'status' => QueryLibraryService::STATUS_PROPOSED,
]);
same('match: a proposed query does not match', 0, count($library->match(7, 'how many leads per officer')));
same('list: it is still in the library', 2, count($library->list(7)));
same('list: it can be filtered by status', 1, count($library->list(7, ['status' => QueryLibraryService::STATUS_PROPOSED])));

// ─── harvesting from the code ─────────────────────────────────────────────────

$statements = [
    [
        'sql' => 'SELECT id FROM leads WHERE stage = "won"',
        'file' => '/app/admin/leads/list.php',
        'module' => 'Leads',
        'function' => 'leads_list',
        'tables' => ['leads'],
        'joins' => [],
        'where' => ['stage = "won"'],
    ],
];

$harvest = $library->harvest(7, $statements, 'scan');
same('harvest: the query was proposed', 1, $harvest['created']);
same('harvest: it is a proposal', QueryLibraryService::STATUS_PROPOSED, $harvest['items'][0]['status'] ?? '');
same('harvest: it keeps the tables it touches', ['leads'], $harvest['items'][0]['tables'] ?? []);

$second = $library->harvest(7, $statements, 'scan');
same('harvest: the same query is not offered twice', 0, $second['created']);
same('harvest: and it is counted as skipped', 1, $second['skipped']);

// A query somebody described is never overwritten by a later scan.
$library->save(7, [
    'name' => 'Leads: won',
    'business_description' => 'The leads that were won.',
    'status' => QueryLibraryService::STATUS_ACTIVE,
]);
$again = $library->harvest(7, $statements, 'scan');
same('harvest: a described query is left alone', 0, $again['created']);
same('harvest: and it is counted as skipped', 1, $again['skipped']);

// ─── forgetting ───────────────────────────────────────────────────────────────

$listed = $library->list(7);
$target = 0;
foreach ($listed as $query) {
    if ($query['name'] === 'Leads per officer proposal') {
        $target = (int) $query['id'];
    }
}
check('delete: the proposal was found', $target > 0);
check('delete: it was forgotten', $library->delete($target, 7));
check('delete: it is gone', $library->find($target, 7) === null);
check('delete: another company cannot delete it', $library->delete(1, 9) === false);

echo $failed === 0
    ? "queries: {$passed} checks passed\n"
    : "queries: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
