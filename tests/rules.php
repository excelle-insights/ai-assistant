<?php

/**
 * The rules of a CRM: owned, sourced and verified.
 *
 *   php tests/rules.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Services\KnowledgeService;
use ExcelleInsights\AiAssistant\Services\RuleService;
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
$pdo->exec('CREATE TABLE `ai_assistant_knowledge` (
    id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, title TEXT, content TEXT,
    embedding TEXT, source TEXT DEFAULT "manual", status TEXT DEFAULT "active", created_at TEXT,
    updated_at TEXT, category TEXT, content_hash TEXT, scope TEXT DEFAULT "global", scope_key TEXT,
    doc_id TEXT, token_count INTEGER, metadata_json TEXT, updated_by TEXT, pinned INTEGER DEFAULT 0)');
$pdo->exec('CREATE INDEX `ai_assistant_know_tenant` ON `ai_assistant_knowledge` (company_id)');

$knowledge = new KnowledgeService($pdo);
$rules = new RuleService($knowledge);

// ─── storing a rule ───────────────────────────────────────────────────────────

$payment = $rules->save(7, [
    'module' => 'Payments',
    'purpose' => 'Records every payment received against an invoice.',
    'rule' => 'An invoice is settled once the payments against it cover its total.',
    'operations' => ['create', 'approve'],
    'tables' => ['payments', 'invoices'],
    'key_fields' => ['id', 'amount', 'paid_at'],
    'permissions' => 'Only finance staff approve a payment.',
    'owner' => 'Jane Doe',
    'source_file' => 'modules/payments.php',
], 'admin');

check('save: the rule was stored', ($payment['id'] ?? 0) > 0, json_encode($payment));
same('save: it is a draft until somebody checks it', 'draft', $payment['verification'] ?? '');
same('save: its module is kept', 'Payments', $payment['module'] ?? '');
same('save: its operations are kept', ['create', 'approve'], $payment['operations'] ?? []);
same('save: its tables are kept', ['payments', 'invoices'], $payment['tables'] ?? []);
same('save: its owner is kept', 'Jane Doe', $payment['owner'] ?? '');
same('save: it belongs to the company', 7, $payment['company_id'] ?? 0);
same('save: the person who saved it is remembered', 'admin', $payment['updated_by'] ?? '');
check('save: the text it hands over is plain', !str_contains(strtolower((string) $payment['content']), 'select '), (string) $payment['content']);

$missing = null;
try {
    $rules->save(7, ['purpose' => 'no module at all']);
} catch (Throwable $e) {
    $missing = $e->getMessage();
}
check('save: a rule without a name is refused', $missing !== null && str_contains($missing, 'name'), (string) $missing);

// Saving the same module again updates the row instead of making a second one.
$again = $rules->save(7, [
    'module' => 'Payments',
    'purpose' => 'Records every payment received against an invoice.',
    'rule' => 'An invoice is settled once the payments against it cover its total.',
    'operations' => ['create', 'approve'],
    'tables' => ['payments', 'invoices'],
    'key_fields' => ['id', 'amount', 'paid_at'],
    'permissions' => 'Only finance staff approve a payment.',
    'owner' => 'Jane Doe',
    'source_file' => 'modules/payments.php',
]);
same('save: the same module updates the row', $payment['id'], $again['id']);
same('save: and there is still one rule', 1, count($rules->list(7)));

// ─── reading ──────────────────────────────────────────────────────────────────

$items = $rules->list(7);
same('list: the rule is there', 1, count($items));
same('list: another company sees nothing', 0, count($rules->list(9)));
same('find: another company cannot reach it', null, $rules->find((int) $payment['id'], 9));
check('find: the rule is there', $rules->find((int) $payment['id'], 7) !== null);

// A plain document is not a rule.
$knowledge->ingest(7, 'Opening hours', 'We open Monday to Friday, 08:00 to 17:00.', 'manual', 'Company');
same('list: a document is not a rule', 1, count($rules->list(7)));

// ─── verifying ────────────────────────────────────────────────────────────────

$verified = $rules->verify((int) $payment['id'], 7, ['owner' => 'Jane Doe']);
check('verify: the rule came back', $verified !== null);
same('verify: it is verified now', 'verified', $verified['verification'] ?? '');
same('verify: the date it was checked is kept', date('Y-m-d'), $verified['last_verified'] ?? '');
same('verify: the owner is kept', 'Jane Doe', $verified['owner'] ?? '');

$back = $rules->verify((int) $payment['id'], 7, ['verification' => 'draft']);
same('verify: it can be sent back to draft', 'draft', $back['verification'] ?? '');
$rules->verify((int) $payment['id'], 7, ['owner' => 'Jane Doe']);

// A rule nobody checked waits for a person.
$rules->save(7, [
    'module' => 'Leads',
    'purpose' => 'Every enquiry that has not yet become a booking.',
    'rule' => 'A lead is won when it is converted to a booking.',
    'owner' => 'Jane Doe',
]);

$matches = $rules->match(7, 'when is an invoice settled');
check('match: the rule matches the question', $matches !== [], json_encode($matches));
same('match: it is the right one', 'Payments', $matches[0]['module'] ?? '');

same('match: an unrelated question matches nothing', 0, count($rules->match(7, 'zzzqqq')));
same('match: another company matches nothing', 0, count($rules->match(9, 'when is an invoice settled')));

// ─── a draft only speaks when nothing else answers ────────────────────────────

$leadOnly = $rules->match(7, 'when is a lead won');
same('match: with nothing verified the draft is offered', 1, count($leadOnly));
same('match: and it is the draft', 'Leads', $leadOnly[0]['module'] ?? '');

$mixed = $rules->match(7, 'when is an invoice settled');
same('match: a verified rule outranks a draft', 'Payments', $mixed[0]['module'] ?? '');
same('match: the draft is not offered beside it', 1, count($mixed));

// ─── the prompt ───────────────────────────────────────────────────────────────

$section = $rules->promptSection(7, 'when is an invoice settled', 3);
check('prompt: the section is there', str_contains($section, 'Business rules'), $section);
check('prompt: a verified rule leads', str_contains($section, 'Business rules this CRM follows'), $section);
check('prompt: it carries the rule', str_contains($section, 'cover its total'), $section);
check('prompt: it says the vocabulary still decides', str_contains($section, 'vocabulary still decides'), $section);
check('prompt: the owner is named', str_contains($section, 'owner: Jane Doe'), $section);

$draftSection = $rules->promptSection(7, 'when is a lead won', 3);
check('prompt: an unverified rule says so', str_contains($draftSection, 'nobody has verified'), $draftSection);

// ─── the modules and operations sheet ─────────────────────────────────────────

$template = $rules->sheetTemplate();
check('sheet: the template has the columns', str_starts_with($template, implode(',', RuleService::SHEET_COLUMNS)), $template);
check('sheet: it is a sheet, not prose', str_contains($template, 'module,purpose'), $template);

$sheet = <<<'CSV'
module,purpose,operations,tables,key_fields,business_rules,permissions,owner,source_file,status
"Payments","Records every payment received against an invoice.","create, approve, export","payments, invoices","id, amount, paid_at","An invoice is settled once the payments against it cover its total.","Only finance staff approve a payment.","Jane Doe","modules/payments.php","draft"
"Leads","Every enquiry that has not yet become a booking.","create, qualify","leads","id, name","A lead is won when it is converted to a booking.","Sales staff own their own leads.","Jane Doe","modules/leads.php","verified"
CSV;

$import = $rules->importSheet(11, $sheet, 'admin');
same('sheet: both rows were stored', 2, $import['inserted']);
same('sheet: no errors', [], $import['errors']);
same('sheet: no warnings', [], $import['warnings']);

$paymentRule = null;
$leadRule = null;
foreach ($rules->list(11) as $rule) {
    if ($rule['module'] === 'Payments') {
        $paymentRule = $rule;
    }
    if ($rule['module'] === 'Leads') {
        $leadRule = $rule;
    }
}
check('sheet: the module row is there', $paymentRule !== null);
same('sheet: the module row is a module', 'module', $paymentRule['category'] ?? '');
same('sheet: its operations are read', ['create', 'approve', 'export'], $paymentRule['operations'] ?? []);
same('sheet: its tables are read', ['payments', 'invoices'], $paymentRule['tables'] ?? []);
same('sheet: its key fields are read', ['id', 'amount', 'paid_at'], $paymentRule['key_fields'] ?? []);
same('sheet: its owner is read', 'Jane Doe', $paymentRule['owner'] ?? '');
same('sheet: its source is read', 'modules/payments.php', $paymentRule['source_file'] ?? '');
same('sheet: the status in the sheet is the state', 'draft', $paymentRule['verification'] ?? '');
same('sheet: a verified row is verified', 'verified', $leadRule['verification'] ?? '');
same('sheet: the rules of another company are untouched', 2, count($rules->list(7)));

// Re-importing the same sheet changes nothing.
$second = $rules->importSheet(11, $sheet, 'admin');
same('sheet: re-importing inserts nothing', 0, $second['inserted']);
same('sheet: and reports nothing new', 0, $second['updated']);
same('sheet: the rules are still the two of them', 2, count($rules->list(11)));

// A row the sheet no longer has is dropped; the rules it never owned stay.
$shorter = <<<'CSV'
module,purpose,operations,tables,key_fields,business_rules,permissions,owner,source_file,status
"Payments","Records every payment received against an invoice.","create, approve, export","payments, invoices","id, amount, paid_at","An invoice is settled once the payments against it cover its total.","Only finance staff approve a payment.","Jane Doe","modules/payments.php","draft"
CSV;
$third = $rules->importSheet(11, $shorter, 'admin');
same('sheet: the row that left the sheet is dropped', 1, $third['removed']);
same('sheet: the rules it never owned are still there', 2, count($rules->list(7)));

// A sheet without a module column is refused, not half-imported.
$bad = $rules->importSheet(11, "purpose,operations\n\"Something\",\"create\"", 'admin');
same('sheet: a sheet without a module column is refused', 1, count($bad['errors']));

// ─── forgetting ───────────────────────────────────────────────────────────────

$target = 0;
foreach ($rules->list(7) as $rule) {
    if ($rule['module'] === 'Leads') {
        $target = (int) $rule['id'];
    }
}
check('delete: the rule was found', $target > 0);
check('delete: it was forgotten', $rules->delete($target, 7));
check('delete: it is gone', $rules->find($target, 7) === null);
check('delete: another company cannot delete it', $rules->delete(1, 9) === false);

echo $failed === 0
    ? "rules: {$passed} checks passed\n"
    : "rules: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
