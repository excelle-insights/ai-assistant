<?php

/**
 * The CRM's own code becomes an index.
 *
 * A fixture application is written to a temporary folder: a navigation map, a
 * page with a query and a file it includes. The scan must find the modules
 * from the map, remember the queries as knowledge, skip the files that did not
 * change on a second scan and forget the ones that are gone.
 *
 *   php tests/code.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Services\AssistantService;
use ExcelleInsights\AiAssistant\Services\CodeIndexService;
use ExcelleInsights\AiAssistant\Services\KnowledgeService;
use ExcelleInsights\AiAssistant\Services\PhpCodeScanner;
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

/**
 * A scripted model: it keeps the prompt it was given, so a test can read what
 * the router was told.
 */
final class CodeLlm implements LlmClientInterface
{
    /** @var array<int,array{role:string,content:string}> */
    public array $messages = [];

    public function chat(array $messages, string $model = '', ?float $temperature = null): array
    {
        $this->messages = $messages;
        $system = (string) ($messages[0]['content'] ?? '');
        $content = str_contains($system, '"intent":"data|knowledge')
            ? '{"intent":"knowledge","plan":{}}'
            : 'Payments were counted.';

        return ['content' => $content, 'usage' => []];
    }

    public function embeddings(string $input, string $model = ''): array
    {
        return [];
    }
}

Config::reset();
Config::set('table_prefix', 'ai_assistant');
Config::set('tenant.column', 'company_id');
Config::set('knowledge.retrieval', 'lexical');
Config::set('knowledge.min_similarity', 0.05);

// ─── the fixture application ──────────────────────────────────────────────────

$app = sys_get_temp_dir() . '/ai-assistant-code-fixture-' . getmypid();
@mkdir($app . '/admin/menu', 0777, true);
@mkdir($app . '/admin/payments', 0777, true);
@mkdir($app . '/admin/leads', 0777, true);

file_put_contents($app . '/admin/menu/sidebar_menu.php', <<<'PHP'
<?php
$menu = [];
$menu['Payments'] = 'admin/payments/index.php';
$menu['Leads'] = 'admin/leads/list.php';
PHP);

file_put_contents($app . '/admin/payments/index.php', <<<'PHP'
<?php
require_once __DIR__ . '/totals.php';

function payments_overview(): array
{
    $sql = "SELECT p.id, p.amount FROM payments p WHERE p.company_id = 7 AND p.status = 'paid'";
    return $sql;
}
PHP);

file_put_contents($app . '/admin/payments/totals.php', <<<'PHP'
<?php
function payment_totals(): string
{
    return "SELECT SUM(p.amount) AS total FROM payments p LEFT JOIN staff s ON s.id = p.staff_id WHERE p.status = 'paid'";
}
PHP);

file_put_contents($app . '/admin/leads/list.php', <<<'PHP'
<?php
function leads_list(): string
{
    return "SELECT l.id, l.title FROM leads l WHERE l.company_id = 7";
}
PHP);

register_shutdown_function(static function () use ($app): void {
    foreach (['admin/menu/sidebar_menu.php', 'admin/payments/index.php', 'admin/payments/totals.php', 'admin/leads/list.php'] as $file) {
        @unlink($app . '/' . $file);
    }
    @rmdir($app . '/admin/menu');
    @rmdir($app . '/admin/payments');
    @rmdir($app . '/admin/leads');
    @rmdir($app . '/admin');
    @rmdir($app);
});

// ─── the database ─────────────────────────────────────────────────────────────

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE `ai_assistant_knowledge` (
    id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, title TEXT, content TEXT,
    embedding TEXT, source TEXT DEFAULT "manual", status TEXT DEFAULT "active", created_at TEXT,
    updated_at TEXT, category TEXT, content_hash TEXT, scope TEXT DEFAULT "global", scope_key TEXT,
    doc_id TEXT, token_count INTEGER, metadata_json TEXT, updated_by TEXT, pinned INTEGER DEFAULT 0)');
$pdo->exec('CREATE INDEX `ai_assistant_know_tenant` ON `ai_assistant_knowledge` (company_id)');

$pdo->exec('CREATE TABLE `ai_assistant_code_files` (
    id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL DEFAULT 0, path TEXT,
    hash TEXT DEFAULT "", size INTEGER DEFAULT 0, mtime INTEGER DEFAULT 0, module TEXT DEFAULT "",
    statements INTEGER DEFAULT 0, meta_json TEXT, updated_by TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE UNIQUE INDEX `ai_assistant_code_file_path` ON `ai_assistant_code_files` (company_id, path)');

$knowledge = new KnowledgeService($pdo, null);
$index = new CodeIndexService($pdo, $knowledge, new PhpCodeScanner());

// ─── the scan ────────────────────────────────────────────────────────────────

$scan = $index->scan(7, ['paths' => [$app]]);

same('scan: no error', '', $scan['error']);
check('scan: the application was read', $scan['files'] >= 3, 'files ' . $scan['files']);
check('scan: statements were found', $scan['statements'] >= 3, 'statements ' . $scan['statements']);
same('scan: everything was stored', $scan['statements'], $scan['stored']);

// The navigation map is the index: the modules come from it, not from a guess.
$modules = [];
foreach ($index->files(7) as $file) {
    $modules[$file['path']] = $file['module'];
}
$names = array_values(array_filter($modules));
check('scan: the modules came from the navigation map', in_array('Payments', $names, true), implode(', ', $names));
check('scan: the included file was read too', count($modules) >= 3, count($modules) . ' files');

// The statements became knowledge, each with a citation back to its file.
$entries = $knowledge->list(7, 100);
$fromCode = array_values(array_filter($entries, static fn (array $row): bool => $row['source'] === 'code'));
check('knowledge: the queries were stored', count($fromCode) >= 3, count($fromCode) . ' entries');
check(
    'knowledge: each entry names its file',
    (bool) array_filter($fromCode, static fn (array $row): bool => ($row['metadata']['file'] ?? '') !== ''),
);
check(
    'knowledge: the tables were kept',
    (bool) array_filter($fromCode, static fn (array $row): bool => in_array('payments', (array) ($row['metadata']['tables'] ?? []), true)),
);

// ─── the second scan ──────────────────────────────────────────────────────────

$again = $index->scan(7, ['paths' => [$app]]);

same('rescan: nothing changed', 0, $again['changed']);
check('rescan: the unchanged files were skipped', $again['unchanged'] >= 3, 'unchanged ' . $again['unchanged']);
same('rescan: nothing was stored twice', 0, $again['stored']);

// A file that changed is read again.
file_put_contents($app . '/admin/leads/list.php', <<<'PHP'
<?php
function leads_list(): string
{
    return "SELECT l.id, l.title, l.amount FROM leads l WHERE l.company_id = 7 AND l.stage = 'won'";
}
PHP);

$third = $index->scan(7, ['paths' => [$app]]);
same('rescan: the changed file was read', 1, $third['changed']);

// ─── a file that is gone ──────────────────────────────────────────────────────

unlink($app . '/admin/leads/list.php');

$fourth = $index->scan(7, ['paths' => [$app]]);
check('rescan: the deleted file was forgotten', $fourth['removed'] >= 1, 'removed ' . $fourth['removed']);
$paths = array_column($index->files(7), 'path');
check('rescan: the deleted file left the index', !in_array($app . '/admin/leads/list.php', $paths, true));

// ─── what the router gets ─────────────────────────────────────────────────────

$queries = $index->knownQueries(7, 'payments totals by staff', 5);
check('queries: the matching query was found', $queries !== [], 'nothing matched');
if ($queries !== []) {
    $first = $queries[0];
    check('queries: it names the tables it touches', $first['tables'] !== [], implode(', ', $first['tables']));
    check('queries: it cites the file it came from', str_ends_with((string) $first['file'], '.php'), (string) $first['file']);
}

$section = $index->promptSection(7, 'payments totals by staff', 3);
check('prompt: the section is there', str_contains($section, 'Queries this CRM already runs'), $section);
check('prompt: it says the queries are not to be run', str_contains($section, 'never run them'), $section);
check('prompt: it carries the rule the application uses', str_contains($section, 'Rule:'), $section);
same('prompt: nothing is offered for an unrelated question', '', $index->promptSection(7, 'zzzqqq', 3));

// ─── a scan without paths ─────────────────────────────────────────────────────

$empty = $index->scan(7, ['paths' => []]);
check('scan: a scan without paths says so', str_contains((string) $empty['error'], 'code.scan_paths'), (string) $empty['error']);

// ─── what the router is told ──────────────────────────────────────────────────

$llm = new CodeLlm();
$assistant = new AssistantService(
    llm: $llm,
    knowledge: $knowledge,
    code: $index,
);
$assistant->ask('payments totals by staff', new UserContext(tenantId: 7, userId: 'u1'));

$prompt = (string) ($llm->messages[0]['content'] ?? '');
check('router: the code section is in the prompt', str_contains($prompt, 'Queries this CRM already runs'), $prompt);
check('router: it says the queries are read, not run', str_contains($prompt, 'never run them'), $prompt);

echo $failed === 0
    ? "code: {$passed} checks passed\n"
    : "code: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
