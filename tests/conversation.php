<?php

/**
 * Conversation store checks against an in-memory SQLite database — no MySQL,
 * no network, no LLM (a scripted fake stands in for one).
 *   php tests/conversation.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Services\ConversationStore;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\TablePrefix;

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
 * Mirrors 20261024130000_create_ai_conversations_and_messages plus the seq
 * column from 20261024140000, in the SQLite dialect.
 */
function seedDatabase(PDO $pdo, string $prefix): void
{
    $pdo->exec('CREATE TABLE `' . $prefix . '_conversations` (
        id TEXT PRIMARY KEY, company_id INTEGER DEFAULT 0, user_id TEXT, channel TEXT DEFAULT "web",
        thread_key TEXT, title TEXT, pinned INTEGER DEFAULT 0, shared_token TEXT, context_json TEXT,
        summary TEXT, message_count INTEGER DEFAULT 0, last_activity_at TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE UNIQUE INDEX `' . $prefix . '_conv_thread` ON `' . $prefix . '_conversations` (company_id, channel, thread_key)');
    $pdo->exec('CREATE TABLE `' . $prefix . '_messages` (
        id TEXT PRIMARY KEY, conversation_id TEXT, company_id INTEGER DEFAULT 0, user_id TEXT, seq INTEGER DEFAULT 0,
        role TEXT, content TEXT, content_type TEXT DEFAULT "text", intent TEXT, citations_json TEXT, data_json TEXT,
        chart_json TEXT, sql_hash TEXT, model TEXT, tokens_in INTEGER DEFAULT 0, tokens_out INTEGER DEFAULT 0,
        latency_ms INTEGER, status TEXT DEFAULT "ok", error TEXT, created_at TEXT, updated_at TEXT)');
}

final class ScriptedLlm implements LlmClientInterface
{
    /** @var array<int,array{messages:array,temperature:?float}> */
    public array $calls = [];

    public function chat(array $messages, string $model = '', ?float $temperature = null): array
    {
        $this->calls[] = ['messages' => $messages, 'temperature' => $temperature];
        return ['content' => "- Alice asked about Q3 revenue\n- Answer: 1200, total 4 deals", 'usage' => []];
    }

    public function embeddings(string $input, string $model = ''): array
    {
        return [];
    }
}

Config::reset();
Config::set('table_prefix', 'aitest');
Config::set('limits.memory_turns', 4);
same('prefix: comes from config', 'aitest', TablePrefix::get());

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
seedDatabase($pdo, 'aitest');
$llm = new ScriptedLlm();
$store = new ConversationStore($pdo, $llm, ['history_turns' => 4, 'summarize_after' => 0]);

// ─── touch: one thread, one row ───────────────────────────────────────────────
$conv = $store->touch(7, 'web', 'thread-1');
check('touch: returns an id', strlen($conv) === 36, $conv);
same('touch: same thread reuses the row', $conv, $store->touch(7, 'web', 'thread-1'));
check('touch: second tenant is isolated', $store->touch(8, 'web', 'thread-1') !== $conv);
check('touch: channel is part of the key', $store->touch(7, 'whatsapp', 'thread-1') !== $conv);
$oneShotA = $store->touch(7, 'api', '');
$oneShotB = $store->touch(7, 'api', '');
check('touch: empty thread creates a fresh row each time', $oneShotA !== $oneShotB && $oneShotA !== '' && $oneShotB !== '');
same('touch: channel is normalised', $conv, $store->touch(7, 'Web ', 'thread-1'));

// ─── append + history ─────────────────────────────────────────────────────────
$store->append($conv, ['role' => 'user', 'content' => 'How much did we sell in Q3?']);
$store->append($conv, ['role' => 'assistant', 'content' => 'Q3 revenue was 1200.', 'intent' => 'data', 'model' => 'gpt-4o', 'tokens_in' => 900, 'tokens_out' => 20]);
$store->append($conv, ['role' => 'user', 'content' => 'And in Q4?']);
$store->append($conv, ['role' => 'assistant', 'content' => 'Q4 revenue was 1500.']);

$history = $store->history($conv);
same('history: all four turns', 4, count($history));
same('history: oldest first', ['user', 'assistant', 'user', 'assistant'], array_values(array_map(fn (array $t) => $t['role'], $history)));
same('history: first turn text', 'How much did we sell in Q3?', $history[0]['content']);
same('history: window keeps the newest turns', ['And in Q4?', 'Q4 revenue was 1500.'], array_column(array_slice($store->history($conv, 2), -2), 'content'));
same('append: title from first question', 'How much did we sell in Q3?', (string) $store->getConversation($conv)['title']);
same('append: message count tracked', 4, (int) $store->getConversation($conv)['message_count']);

// Same-second writes must still come back in turn order: that is what seq is for.
$ordering = $store->touch(7, 'web', 'ordering');
foreach (['m1', 'm2', 'm3', 'm4', 'm5'] as $i => $text) {
    $store->append($ordering, ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => $text]);
}
same('seq: exact order inside one second', ['m1', 'm2', 'm3', 'm4', 'm5'], array_column($store->history($ordering, 10), 'content'));
same('seq: numbers are 1-based and dense', [1, 2, 3, 4, 5], array_column($store->transcript($ordering)['messages'], 'seq'));

// ─── rich payloads survive the round trip ─────────────────────────────────────
$store->append($conv, [
    'role' => 'assistant',
    'content' => 'Details below.',
    'citations' => [new ExcelleInsights\AiAssistant\Dto\Citation('query', 'orders 2026-07 to 2026-09', 'q-1')],
    'chart' => ['type' => 'bar', 'series' => [['name' => 'revenue', 'data' => [1200, 1500]]]],
    'rows' => [['quarter' => 'Q3', 'revenue' => 1200], ['quarter' => 'Q4', 'revenue' => 1500]],
    'applied_filters' => ['date_range' => '2026-07-01..2026-09-30'],
    'sql' => 'SELECT 1',
]);
$last = $store->transcript($conv)['messages'][count($store->transcript($conv)['messages']) - 1];
same('payload: citation round trip', 'query', $last['citations'][0]['type'] ?? '');
same('payload: chart round trip', 'bar', $last['chart']['type'] ?? '');
same('payload: rows round trip', 2, count($last['rows']));
same('payload: filters round trip', '2026-07-01..2026-09-30', $last['applied_filters']['date_range'] ?? '');
check('payload: sql never persisted in clear text', !isset($last['sql']) && !str_contains((string) json_encode($store->transcript($conv)), 'SELECT 1'));

// ─── memory ───────────────────────────────────────────────────────────────────
$memoryConv = $store->touch(7, 'web', 'with-context', ['context' => ['page' => 'customer/42'], 'user_id' => 'u-9']);
$store->append($memoryConv, ['role' => 'user', 'content' => 'hi']);
$memory = $store->memory($memoryConv);
same('memory: turns present', 1, count($memory['turns']));
same('memory: page context carried', 'customer/42', $memory['context']['page'] ?? '');
same('memory: no summary yet', '', $memory['summary']);
check('memory: unknown conversation is empty', $store->memory('nope') === ['summary' => '', 'turns' => [], 'context' => []]);

// ─── summarize ────────────────────────────────────────────────────────────────
$big = $store->touch(7, 'web', 'long-thread');
for ($i = 1; $i <= 8; $i++) {
    $store->append($big, ['role' => $i % 2 === 1 ? 'user' : 'assistant', 'content' => 'Turn ' . $i]);
}
$summary = $store->summarize($big);
check('summarize: writes a summary', str_contains($summary, 'Q3 revenue'), $summary);
same('summarize: summary persisted', $summary, (string) $store->getConversation($big)['summary']);
same('summarize: memory now leads with the summary', $summary, $store->memory($big)['summary']);
check('summarize: folds only the older turns', str_contains($llm->calls[0]['messages'][1]['content'], 'Turn 1') && !str_contains($llm->calls[0]['messages'][1]['content'], 'Turn 8'));
same('summarize: nothing happens without an LLM', $summary, (new ConversationStore($pdo, null, ['history_turns' => 2]))->summarize($big));

// ─── listing, search, tenant isolation ────────────────────────────────────────
$store->append($conv, ['role' => 'user', 'content' => 'Please export the invoice list']);
$other = $store->touch(99, 'web', 'other-tenant');
$store->append($other, ['role' => 'user', 'content' => 'secret invoice list']);

$hits = $store->search(7, 'invoice list');
check('search: finds the matching tenant message', count($hits) >= 1, (string) count($hits));
check('search: other tenants never match', !in_array('secret invoice list', array_column($hits, 'content'), true));
same('search: empty term returns nothing', [], $store->search(7, '   '));
$hits99 = $store->search(99, 'invoice list');
same('search: scoped to the tenant', 1, count($hits99));

$list = $store->listForUser(7, '');
$keys = array_column($list, 'thread_key');
check('list: tenant threads only', !in_array('other-tenant', $keys, true) && in_array('thread-1', $keys, true));
foreach ($list as $row) {
    if ($row['thread_key'] === 'thread-1' && $row['channel'] === 'web') {
        same('list: message count reported', 6, $row['message_count']);
    }
}
$store->setPinned($oneShotA, true);
$pinnedFirst = $store->listForUser(7, '');
same('list: pinned sorts first', $oneShotA, $pinnedFirst[0]['id'] ?? '');
check('list: never exposes the share token', !isset($pinnedFirst[0]['shared_token']));
same('list: scoped by user', 1, count(array_filter($store->listForUser(7, 'u-9'), fn (array $r) => $r['thread_key'] === 'with-context')));

// ─── rename / share / delete ──────────────────────────────────────────────────
check('rename: applies', $store->rename($conv, 'Quarterly revenue'));
same('rename: stored', 'Quarterly revenue', (string) $store->getConversation($conv)['title']);
check('rename: blank refused', !$store->rename($conv, '   '));
check('rename: unknown thread refuses', !$store->rename('does-not-exist', 'x'));

$token = $store->share($conv);
same('share: 32 hex chars', 1, preg_match('/^[a-f0-9]{32}$/', $token));
$shared = $store->findByShare($token);
check('share: token resolves to the transcript', $shared !== null && count($shared['messages']) === 6);
same('share: lookup with a bad token', null, $store->findByShare('nope'));
same('share: lookup with a blank token', null, $store->findByShare(''));
$store->revokeShare($conv);
same('share: revoked', null, $store->findByShare($token));

check('append: to an unknown thread is refused', $store->append('missing', ['role' => 'user', 'content' => 'x']) === 0);
$roles = $store->touch(7, 'web', 'roles');
same('append: returns the turn number', 1, $store->append($roles, ['role' => 'USER', 'content' => 'first']));
same('append: second turn numbered', 2, $store->append($roles, ['role' => 'AGENT', 'content' => 'injected']));
same('append: third turn numbered', 3, $store->append($roles, ['role' => 'tool', 'content' => 'scratch']));
$stored = $pdo->query('SELECT role, content FROM aitest_messages WHERE conversation_id = ' . $pdo->quote($roles) . ' ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC);
same('append: unknown role normalised to user', 'user', (string) $stored[1]['role']);
same('append: content kept verbatim', 'injected', (string) $stored[1]['content']);
$store->append($roles, ['role' => 'assistant', 'content' => 'fourth']);
same('history: non-chat roles are skipped', ['first', 'injected', 'fourth'], array_column($store->history($roles, 10), 'content'));
same('transcript: keeps every role', 4, count($store->transcript($roles)['messages']));

check('delete: removes the thread', $store->delete($conv));
same('delete: gone', null, $store->getConversation($conv));
same('delete: messages gone too', 0, (int) $pdo->query('SELECT COUNT(*) FROM aitest_messages WHERE conversation_id = ' . $pdo->quote($conv))->fetchColumn());
check('delete: unknown thread', !$store->delete('nope'));

// ─── UserContext convenience ──────────────────────────────────────────────────
$user = new UserContext('u-1', 7, 'web', 'thread-u1');
$byUser = $store->touchForUser($user);
same('touchForUser: stable per user thread', $byUser, $store->touchForUser($user));
same('touchForUser: records the host user id', 'u-1', (string) $store->getConversation($byUser)['user_id']);

// ─── missing tables fail with the fix, not an empty result ────────────────────
$bare = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    (new ConversationStore($bare))->touch(1, 'web', 'x');
    check('guard: missing table raises', false, 'no exception');
} catch (Throwable $e) {
    check('guard: missing table raises a migration hint', str_contains($e->getMessage(), 'migrations'), $e->getMessage());
}
Config::reset();

echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
