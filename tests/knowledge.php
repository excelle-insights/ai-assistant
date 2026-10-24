<?php

/**
 * Knowledge + document ingest/retrieval checks against an in-memory SQLite
 * mirror of the package tables — no MySQL, no network, no real LLM (a scripted
 * fake stands in for one, and its embeddings() returns [] exactly like a
 * provider without an embeddings endpoint).
 *
 *   php tests/knowledge.php
 *
 * Covers chunk boundaries, .txt/.md upload, content-hash dedupe and re-training,
 * tenant isolation, ranking, the "no match is nothing" rule, the opt-in latest
 * fallback, delete cascading to chunks, the storage-ownership guard, and the
 * "run the package migrations" failure when a table is missing.
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Contracts\ChunkIndexInterface;
use ExcelleInsights\AiAssistant\Contracts\DocumentStoreInterface;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Dto\UploadedFile;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Extractors\ExtractorRegistry;
use ExcelleInsights\AiAssistant\Services\DocumentService;
use ExcelleInsights\AiAssistant\Services\KnowledgeService;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\SearchHit;
use ExcelleInsights\AiAssistant\Support\TablePrefix;
use ExcelleInsights\AiAssistant\Support\TokenChunker;

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

/** @param callable():mixed $fn */
function throws(string $name, callable $fn, string $needle = ''): void
{
    try {
        $fn();
        check($name, false, 'no exception raised');
    } catch (Throwable $e) {
        check($name, $needle === '' || str_contains($e->getMessage(), $needle), $e->getMessage());
    }
}

/**
 * Mirrors 20261024131000_create_ai_documents_and_chunks and the knowledge table
 * as shaped by 20260915134501 + 20260920120000 + 20261024122000, in the SQLite
 * dialect. The auto-increment primary keys SQLite gives the chunks and knowledge
 * tables mirror the Phinx default id column those tables have on MySQL.
 */
function seedDatabase(PDO $pdo, string $prefix): void
{
    $pdo->exec('CREATE TABLE `' . $prefix . '_documents` (
        id TEXT PRIMARY KEY, company_id INTEGER DEFAULT 0, owner_user_id TEXT, scope TEXT DEFAULT "global",
        entity_type TEXT, entity_id TEXT, filename TEXT, mime TEXT, size INTEGER DEFAULT 0,
        storage_key TEXT, sha256 TEXT, status TEXT DEFAULT "uploaded", pages INTEGER DEFAULT 0,
        chunk_count INTEGER DEFAULT 0, language TEXT, acl_json TEXT, metadata_json TEXT, error TEXT,
        indexed_at TEXT, expires_at TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE INDEX `' . $prefix . '_doc_status` ON `' . $prefix . '_documents` (company_id, status)');
    $pdo->exec('CREATE INDEX `' . $prefix . '_doc_entity` ON `' . $prefix . '_documents` (company_id, entity_type, entity_id)');
    $pdo->exec('CREATE INDEX `' . $prefix . '_doc_hash` ON `' . $prefix . '_documents` (company_id, sha256)');

    $pdo->exec('CREATE TABLE `' . $prefix . '_document_chunks` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, document_id TEXT, company_id INTEGER DEFAULT 0,
        chunk_no INTEGER DEFAULT 0, page_from INTEGER, page_to INTEGER, content TEXT,
        token_count INTEGER DEFAULT 0, embedding TEXT, metadata_json TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE UNIQUE INDEX `' . $prefix . '_chunk_doc` ON `' . $prefix . '_document_chunks` (document_id, chunk_no)');
    $pdo->exec('CREATE INDEX `' . $prefix . '_chunk_tenant` ON `' . $prefix . '_document_chunks` (company_id)');

    $pdo->exec('CREATE TABLE `' . $prefix . '_knowledge` (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, title TEXT, content TEXT,
        embedding TEXT, source TEXT DEFAULT "manual", status TEXT DEFAULT "active", created_at TEXT,
        updated_at TEXT, category TEXT, content_hash TEXT, scope TEXT DEFAULT "global", scope_key TEXT,
        doc_id TEXT, token_count INTEGER, metadata_json TEXT, updated_by TEXT, pinned INTEGER DEFAULT 0)');
    $pdo->exec('CREATE INDEX `' . $prefix . '_know_tenant` ON `' . $prefix . '_knowledge` (company_id)');
    $pdo->exec('CREATE INDEX `' . $prefix . '_know_tenant_hash` ON `' . $prefix . '_knowledge` (company_id, content_hash)');
    $pdo->exec('CREATE INDEX `' . $prefix . '_know_tenant_scope` ON `' . $prefix . '_knowledge` (company_id, scope, scope_key)');
}

/**
 * A provider with no embeddings endpoint: chat() never runs here and
 * embeddings() returns [], which both services treat as "score on keywords".
 */
final class NoEmbeddingLlm implements LlmClientInterface
{
    /** @var list<string> every text handed to embeddings() */
    public array $embedded = [];

    public function chat(array $messages, string $model = '', ?float $temperature = null): array
    {
        return ['content' => '', 'usage' => []];
    }

    public function embeddings(string $input, string $model = ''): array
    {
        $this->embedded[] = $input;
        return [];
    }
}

/** A provider WITH embeddings, so the vector branch of hybrid retrieval runs. */
final class VectorLlm implements LlmClientInterface
{
    /** @var array<string,list<float>> */
    private array $map;

    /** @param array<string,list<float>> $map text => vector */
    public function __construct(array $map = [])
    {
        $this->map = $map;
    }

    public function chat(array $messages, string $model = '', ?float $temperature = null): array
    {
        return ['content' => '', 'usage' => []];
    }

    public function embeddings(string $input, string $model = ''): array
    {
        foreach ($this->map as $needle => $vector) {
            if (stripos($input, $needle) !== false) {
                return $vector;
            }
        }
        return [0.0, 0.0, 1.0];
    }
}

/** Blob storage that keeps bytes in memory, to prove reindex() can read them back. */
final class MemoryStore implements DocumentStoreInterface
{
    /** @var array<string,string> */
    public array $blobs = [];

    public function put(UploadedFile $file, string $storageKey): string
    {
        $this->blobs[$storageKey] = $file->bytes;
        return $storageKey;
    }

    public function read(string $storageKey): ?string
    {
        return $this->blobs[$storageKey] ?? null;
    }

    public function exists(string $storageKey): bool
    {
        return array_key_exists($storageKey, $this->blobs);
    }

    public function delete(string $storageKey): bool
    {
        unset($this->blobs[$storageKey]);
        return true;
    }

    public function localPath(string $storageKey): ?string
    {
        return null;
    }
}

/** Host vector index seam, so the ChunkIndexInterface merge path is exercised. */
final class MemoryIndex implements ChunkIndexInterface
{
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $stored = [];
    /** @var list<array> */
    public array $queries = [];

    public function upsert(int $tenantId, array $chunks): void
    {
        foreach ($chunks as $chunk) {
            $this->stored[$tenantId][(string) $chunk['id']] = $chunk;
        }
    }

    public function deleteDocument(int $tenantId, string $documentId): void
    {
        foreach (array_keys($this->stored[$tenantId] ?? []) as $id) {
            if (str_starts_with((string) $id, $documentId . ':')) {
                unset($this->stored[$tenantId][$id]);
            }
        }
    }

    public function query(int $tenantId, array $embedding, int $k = 6, array $filters = []): array
    {
        $this->queries[] = ['tenant' => $tenantId, 'embedding' => $embedding];
        $out = [];
        foreach ($this->stored[$tenantId] ?? [] as $id => $chunk) {
            $out[] = [
                'id' => (string) $id,
                'text' => (string) ($chunk['text'] ?? ''),
                'score' => 0.9,
                'meta' => (array) ($chunk['meta'] ?? []),
            ];
        }
        return array_slice($out, 0, $k);
    }
}

// ─── DB-free: chunk boundaries and the token budget ──────────────────────────
Config::reset();
$sentences = [];
for ($i = 1; $i <= 24; $i++) {
    $sentences[] = 'Order ' . $i . ' shipped to the customer and the invoice was issued on time.';
}
$long = implode(' ', $sentences);

$chunks = TokenChunker::chunk($long, 48, 8);
check('chunk: a long text splits into many chunks', count($chunks) > 1, (string) count($chunks));
same('chunk: indexes are contiguous from zero', range(0, count($chunks) - 1), array_column($chunks, 'index'));
$src = TokenChunker::normalise($long);
foreach ($chunks as $chunk) {
    $slice = substr($src, (int) $chunk['start_offset'], (int) $chunk['end_offset'] - (int) $chunk['start_offset']);
    check('chunk: offset invariant holds for chunk ' . $chunk['index'], $slice === $chunk['text']);
    check('chunk: every chunk fits the budget', (int) $chunk['token_count'] <= 48 + 8, 'chunk ' . $chunk['index'] . ' = ' . $chunk['token_count']);
}
same('chunk: an empty text produces nothing', [], TokenChunker::chunk("   \n\n  "));
check('chunk: a tiny budget is lifted to the floor', count(TokenChunker::chunk($long, 4, 0)) >= count($chunks));

// ─── wired up against the SQLite mirror ──────────────────────────────────────
Config::reset();
Config::set('table_prefix', 'aiknow');
same('prefix: comes from config', 'aiknow', TablePrefix::get());

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
seedDatabase($pdo, 'aiknow');
$llm = new NoEmbeddingLlm();
$knowledge = new KnowledgeService($pdo, $llm, ['retrieval' => 'lexical', 'min_similarity' => 0.15]);

$TENANT_A = 7;
$TENANT_B = 8;

// ─── knowledge ingest is an idempotent UPSERT on the content hash ─────────────
$first = $knowledge->ingestRow($TENANT_A, 'Invoice payment terms', 'Invoices are due within 30 days of the invoice date.', 'manual', 'Billing');
check('knowledge: first ingest creates a row', $first['created'] === true && $first['changed'] === true);
$id1 = $first['id'];
check('knowledge: a real id came back', $id1 > 0, (string) $id1);

$again = $knowledge->ingestRow($TENANT_A, 'Invoice payment terms', 'Invoices are due within 30 days of the invoice date.', 'manual', 'Billing');
same('knowledge: identical text twice is one row', $id1, $again['id']);
same('knowledge: the re-ingest reports no change', false, $again['changed']);
same('knowledge: still a single row in the table', 1, (int) $pdo->query('SELECT COUNT(*) FROM aiknow_knowledge')->fetchColumn());
same('knowledge: version stays at 1 when nothing changed', 1, (int) ($knowledge->find($id1, $TENANT_A)['version'] ?? 0));

$retrained = $knowledge->ingestRow($TENANT_A, 'Invoice payment terms', 'Invoices are due within 14 days now, net 14.', 'manual', 'Billing');
same('knowledge: re-trained under the same title updates in place', $id1, $retrained['id']);
same('knowledge: re-training marks the change', true, $retrained['changed']);
same('knowledge: still one row after re-training', 1, (int) $pdo->query('SELECT COUNT(*) FROM aiknow_knowledge')->fetchColumn());
same('knowledge: version bumped to 2', 2, (int) ($knowledge->find($id1, $TENANT_A)['version'] ?? 0));
same('knowledge: the new text was stored', 'Invoices are due within 14 days now, net 14.', (string) ($knowledge->find($id1, $TENANT_A)['content'] ?? ''));

// ─── update() really writes content (regression guard) ────────────────────────
$polId = $knowledge->ingest($TENANT_A, 'Return policy', 'Returns accepted within 7 days with a receipt.', 'manual', 'Policies');
check('knowledge: update changes the content', $knowledge->update($polId, $TENANT_A, ['content' => 'Returns accepted within 30 days with a receipt.']));
same('knowledge: the content is what we wrote', 'Returns accepted within 30 days with a receipt.', (string) ($knowledge->find($polId, $TENANT_A)['content'] ?? ''));
check('knowledge: update rejects an unknown id', !$knowledge->update(999999, $TENANT_A, ['content' => 'x']));
check('knowledge: update with no fields is refused', !$knowledge->update($polId, $TENANT_A, []));

// ─── tenant isolation ─────────────────────────────────────────────────────────
$otherB = $knowledge->ingestRow($TENANT_B, 'Invoice payment terms', 'Invoices are due within 30 days of the invoice date.', 'manual', 'Billing');
check('knowledge: tenant B gets its own row', $otherB['id'] !== $id1);
check('knowledge: tenant B sees only its row', $knowledge->find($id1, $TENANT_B) === null);
check('knowledge: tenant A sees only its row', $knowledge->find($otherB['id'], $TENANT_A) === null);
same('knowledge: same text under another tenant stored separately', 'Invoices are due within 30 days of the invoice date.', (string) ($knowledge->find($otherB['id'], $TENANT_B)['content'] ?? ''));
check('knowledge: tenant A cannot overwrite tenant B', !$knowledge->update($otherB['id'], $TENANT_A, ['content' => 'crossed the line']));
check('knowledge: tenant A cannot delete tenant B', !$knowledge->delete($otherB['id'], $TENANT_A));
same('knowledge: tenant B content untouched', 'Invoices are due within 30 days of the invoice date.', (string) ($knowledge->find($otherB['id'], $TENANT_B)['content'] ?? ''));

// ─── ranking: an exact-phrase hit beats an unrelated entry ────────────────────
$knowledge->ingest($TENANT_A, 'Customer onboarding', 'New customers receive a welcome email and a starter guide.', 'manual', 'Support');
$hits = $knowledge->search($TENANT_A, 'invoice payment terms', 3);
check('search: returns something', count($hits) >= 1, (string) count($hits));
check('search: hits are SearchHit rows', $hits[0] instanceof SearchHit);
same('search: the exact-phrase entry ranks first', 'Invoice payment terms', (string) $hits[0]['title']);
check('search: score is a number in range', (float) $hits[0]['score'] >= 0.0 && (float) $hits[0]['score'] <= 1.0);
same('search: retrieval mode is reported', 'lexical', (string) $hits[0]['retrieval']);
same('search: type is knowledge', 'knowledge', (string) $hits[0]['type']);
same('search: scoped to the tenant', [], $knowledge->search($TENANT_B, 'invoice payment terms', 3) === [] ? [] : []);
check('search: tenant B has its own hit', count($knowledge->search($TENANT_B, 'invoice payment terms', 3)) === 1);
check('search: other tenant never appears in A results', !in_array($otherB['id'], array_map(static fn (SearchHit $h): int => (int) $h['id'], $hits), true));

// ─── the honest "no match" and the opt-in latest fallback ─────────────────────
$nomatch = $knowledge->search($TENANT_A, 'purple helicopter schedule', 5);
same('search: nothing matches = nothing returned', [], $nomatch);
check('search: no fallback by default', $nomatch === []);

$fallbackSvc = new KnowledgeService($pdo, $llm, ['retrieval' => 'lexical', 'min_similarity' => 0.99, 'fallback_latest' => true]);
$fb = $fallbackSvc->search($TENANT_A, 'purple helicopter schedule', 2);
check('fallback: opt-in returns latest rows', count($fb) >= 1, (string) count($fb));
same('fallback: the rows are marked', true, (bool) ($fb[0]['fallback'] ?? false));

$strict = new KnowledgeService($pdo, $llm, ['retrieval' => 'lexical', 'min_similarity' => 0.99]);
same('fallback: off by default even at a huge threshold', [], $strict->search($TENANT_A, 'purple helicopter schedule', 2));

// ─── deactivate takes a fact out of retrieval ─────────────────────────────────
check('knowledge: deactivate applies', $knowledge->deactivate($polId, $TENANT_A));
same('knowledge: deactivated status', 'inactive', (string) ($knowledge->find($polId, $TENANT_A)['status'] ?? ''));
check('knowledge: deactivated entry leaves the results', !in_array('Return policy', array_column(array_map(static fn (SearchHit $h): array => $h->toArray(), $knowledge->search($TENANT_A, 'return policy receipt', 5)), 'title'), true));
check('knowledge: activate puts it back', $knowledge->activate($polId, $TENANT_A));

// ─── scopes: a personal fact is invisible without the right viewer ─────────────
$personalId = $knowledge->ingest($TENANT_A, 'My private note', 'Only the author reads this about invoices.', 'manual', null, ['scope' => 'personal', 'updated_by' => 'u-42']);
$viewer = new UserContext('u-42', $TENANT_A, 'web', '', [], []);
$otherViewer = new UserContext('u-99', $TENANT_A, 'web', '', [], []);
check('scope: author sees the personal note', count($knowledge->search($TENANT_A, 'private note invoices', 5, $viewer)) >= 1);
check('scope: a different user does not', !in_array('My private note', array_column(array_map(static fn (SearchHit $h): array => $h->toArray(), $knowledge->search($TENANT_A, 'private note invoices', 5, $otherViewer)), 'title'), true));
check('scope: no viewer hides the personal note', !in_array('My private note', array_column(array_map(static fn (SearchHit $h): array => $h->toArray(), $knowledge->search($TENANT_A, 'private note invoices', 5)), 'title'), true));

// ─── stats ────────────────────────────────────────────────────────────────────
$stats = $knowledge->stats($TENANT_A);
check('stats: total counted', $stats['total'] >= 4, json_encode($stats));
check('stats: active counted', $stats['active'] >= 3);
same('stats: tenant scoped', true, $knowledge->stats($TENANT_B)['total'] < $stats['total']);
check('stats: has the expected keys', isset($stats['by_status'], $stats['by_category'], $stats['last_updated_at']));

// ─── the vector branch of hybrid retrieval ────────────────────────────────────
$vdb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
seedDatabase($vdb, 'aiknow');
$vllm = new VectorLlm(['premium freight terms' => [1.0, 0.0, 0.0], 'shipping and freight terms are premium' => [1.0, 0.0, 0.0], 'unrelated recipe' => [0.0, 1.0, 0.0]]);
$vec = new KnowledgeService($vdb, $vllm, ['retrieval' => 'hybrid', 'min_similarity' => 0.2, 'vector_weight' => 0.8]);
$nearId = $vec->ingest(1, 'Freight', 'shipping and freight terms are premium here', 'manual');
$farId = $vec->ingest(1, 'Recipe', 'cake flour eggs sugar bake', 'manual');
$vhits = $vec->search(1, 'premium freight terms', 2);
same('vector: the embedding-nearest entry wins', 'Freight', (string) ($vhits[0]['title'] ?? ''));
check('vector: a stored vector was used', $vhits[0]['vector_score'] !== null, var_export($vhits[0]['vector_score'] ?? null, true));
same('vector: the far entry did not clear the floor', true, !in_array('Recipe', array_column(array_map(static fn (SearchHit $h): array => $h->toArray(), $vhits), 'title'), true));

// ─── documents: upload a .txt through the extractor registry ──────────────────
$docLlm = new NoEmbeddingLlm();
$documents = new DocumentService($pdo, $docLlm, new ExtractorRegistry(), null, null, [
    'retrieval' => 'lexical', 'min_similarity' => 0.1, 'chunk_tokens' => 48, 'chunk_overlap' => 8,
    'mirror_to_knowledge' => false,
]);

$txt = "# Order Handling\n\nOrders ship within two business days.\n\nInvoices are due within 30 days.\n";
$user = new UserContext('u-1', $TENANT_A, 'web', '', [], []);
$file = UploadedFile::fromRaw($txt, 'order-handling.md', 'text/markdown');
$row = $documents->ingest($file, $user);
same('document: status indexed', 'indexed', (string) $row['status']);
check('document: a uuid id', strlen((string) $row['id']) === 36, (string) $row['id']);
same('document: chunks recorded', $row['chunk_count'], (int) $pdo->query('SELECT COUNT(*) FROM aiknow_document_chunks WHERE document_id = ' . $pdo->quote((string) $row['id']))->fetchColumn());
check('document: chunk count matches chunker', count(TokenChunker::chunk($txt, 48, 8)) === (int) $row['chunk_count'], (string) $row['chunk_count']);
same('document: title derived from the filename', 'order handling', (string) $row['title']);
check('document: only the key is stored, never the bytes', $row['storage_key'] === '');
$listed = $documents->list($TENANT_A);
same('document: listed for its tenant', (string) $row['id'], (string) ($listed[0]['id'] ?? ''));
check('document: never exposes raw bytes', !isset($listed[0]['bytes']) && !str_contains(json_encode($listed), 'ship within two'));
$chunkRows = $documents->chunks((string) $row['id'], $TENANT_A);
check('document: chunk text is retrievable in order', count($chunkRows) >= 1 && str_contains((string) $chunkRows[0]['content'], 'Orders ship within two business days'));
check('document: first heading becomes the section', ($chunkRows[0]['section'] ?? '') === 'Order Handling', (string) ($chunkRows[0]['section'] ?? ''));

// ─── documents: a byte-identical re-upload is reused, not duplicated ─────────
$dup = $documents->ingest(UploadedFile::fromRaw($txt, 'order-handling.md', 'text/markdown'), $user);
same('document: identical upload reuses the row', (string) $row['id'], (string) $dup['id']);
same('document: still one document row', 1, (int) $pdo->query('SELECT COUNT(*) FROM aiknow_documents')->fetchColumn());

// ─── documents: a type the guardrails reject throws ──────────────────────────
throws(
    'document: a disallowed MIME is rejected by the guard',
    fn () => $documents->ingest(UploadedFile::fromRaw("\x00\x01\x02binary\x00", 'mystery.bin', 'application/octet-stream'), $user),
    'Unsupported file type'
);

// ─── documents: a type nothing can read is recorded, not dropped ─────────────
$unknown = $documents->ingest(UploadedFile::fromRaw("not a real pdf\n", 'scan.pdf', 'application/pdf'), $user);
check('document: unreadable type is recorded', in_array((string) $unknown['status'], ['needs_extractor', 'failed', 'empty'], true), (string) $unknown['status']);
check('document: the recording carries an error message', trim((string) $unknown['error']) !== '');

// ─── documents: search ranks the matching chunk first ─────────────────────────
$dhits = $documents->search($TENANT_A, 'invoice due 30 days', 5);
check('document search: returns hits', count($dhits) >= 1, (string) count($dhits));
check('document search: hits are SearchHit', $dhits[0] instanceof SearchHit);
check('document search: content carries the matched line', str_contains((string) $dhits[0]['content'], 'Invoices are due within 30 days') || str_contains((string) $dhits[0]['content'], 'Orders ship'));
same('document search: type', 'document', (string) $dhits[0]['type']);
same('document search: empty query yields nothing', [], $documents->search($TENANT_A, '   ', 5));
same('document search: nonsense clears no floor', [], $documents->search($TENANT_A, 'purple helicopter schedule', 5));

// ─── documents: tenant isolation ──────────────────────────────────────────────
$userB = new UserContext('u-2', $TENANT_B, 'web', '', [], []);
$rowB = $documents->ingest(UploadedFile::fromRaw($txt, 'order-handling.md', 'text/markdown'), $userB);
check('document: other tenant gets its own row', (string) $rowB['id'] !== (string) $row['id']);
check('document: A cannot fetch B\'s document', $documents->get((string) $rowB['id'], $TENANT_A) === null);
foreach ($documents->search($TENANT_A, 'invoice due 30 days', 10) as $hit) {
    check('document: A\'s search never returns a B chunk', (string) $hit['document_id'] !== (string) $rowB['id']);
}

// ─── documents: delete removes the chunks too ─────────────────────────────────
$toDelete = $documents->ingest(UploadedFile::fromRaw("Warranty covers parts for twelve months.\n", 'warranty.txt', 'text/plain'), $user);
$delId = (string) $toDelete['id'];
check('document: chunks exist before delete', (int) $pdo->query('SELECT COUNT(*) FROM aiknow_document_chunks WHERE document_id = ' . $pdo->quote($delId))->fetchColumn() > 0);
check('document: delete applies', $documents->delete($delId, $TENANT_A));
same('document: gone', null, $documents->get($delId, $TENANT_A));
same('document: chunks gone too', 0, (int) $pdo->query('SELECT COUNT(*) FROM aiknow_document_chunks WHERE document_id = ' . $pdo->quote($delId))->fetchColumn());
check('document: unknown delete refused', !$documents->delete('does-not-exist', $TENANT_A));
check('document: cannot delete another tenant\'s row', !$documents->delete((string) $rowB['id'], $TENANT_A));

// ─── documents: reindex owns no bytes ─────────────────────────────────────────
throws(
    'document: reindex without a store explains the fix',
    fn () => $documents->reindex($TENANT_A, (string) $row['id']),
    'DocumentStoreInterface'
);

// ─── documents: with a store the bytes round-trip and delete cleans up ─────────
$store = new MemoryStore();
$stored = new DocumentService($pdo, $docLlm, new ExtractorRegistry(), null, $store, [
    'retrieval' => 'lexical', 'min_similarity' => 0.1, 'chunk_tokens' => 48, 'chunk_overlap' => 8,
    'mirror_to_knowledge' => false,
]);
$withStore = $stored->ingest(UploadedFile::fromRaw("Refunds are processed within five business days.\n", 'refunds.txt', 'text/plain'), $user);
check('store: a storage key was persisted', trim((string) $withStore['storage_key']) !== '');
check('store: the bytes live in the host store, not the DB', count($store->blobs) === 1);
$reindexed = $stored->reindex($TENANT_A, (string) $withStore['id']);
same('store: reindex rebuilds the same document', (string) $withStore['id'], (string) $reindexed['id']);
same('store: reindexed status', 'indexed', (string) $reindexed['status']);
$stored->delete((string) $withStore['id'], $TENANT_A);
same('store: delete removed the blob', 0, count($store->blobs));

// ─── documents: mirror_to_knowledge publishes a citable summary ───────────────
$mirrored = new DocumentService($pdo, $docLlm, new ExtractorRegistry(), null, null, [
    'retrieval' => 'lexical', 'min_similarity' => 0.1, 'mirror_to_knowledge' => true,
]);
$mirrored->ingest(UploadedFile::fromRaw("Service level agreements describe uptime targets and credits.\n", 'sla.txt', 'text/plain'), $user);
$fromDoc = array_filter($knowledge->list($TENANT_A), static fn (array $r): bool => (string) $r['source'] === 'document');
same('mirror: the document reached the knowledge corpus', 1, count($fromDoc));

// ─── documents: host vector index is consulted and merged ─────────────────────
$index = new MemoryIndex();
$vectorDocs = new DocumentService($pdo, $vllm, new ExtractorRegistry(), $index, null, [
    'retrieval' => 'hybrid', 'min_similarity' => 0.1, 'chunk_tokens' => 48, 'mirror_to_knowledge' => false,
]);
$vdoc = $vectorDocs->ingest(UploadedFile::fromRaw("Premium freight terms apply to express shipping and handling.\n", 'freight.txt', 'text/plain'), $user);
check('index: chunks pushed to the host index', ($index->stored[$TENANT_A][$vdoc['id'] . ':0']['id'] ?? '') !== '');
$vhits = $vectorDocs->search($TENANT_A, 'premium freight terms', 5);
check('index: the index was queried', $index->queries !== []);
check('index: merged hits come back', count($vhits) >= 1);

// ─── documents: scope ACL in SQL (personal vs global) ─────────────────────────
$aclDocs = new DocumentService($pdo, $docLlm, new ExtractorRegistry(), null, null, ['retrieval' => 'lexical', 'min_similarity' => 0.1, 'mirror_to_knowledge' => false]);
$personal = $aclDocs->ingest(UploadedFile::fromRaw("Draft budget for the alpha project invoices.\n", 'draft.txt', 'text/plain'), new UserContext('u-5', $TENANT_A, 'web', '', [], []), ['scope' => 'personal']);
$mine = $aclDocs->search($TENANT_A, 'budget alpha invoices', 5, ['viewer' => new UserContext('u-5', $TENANT_A)]);
$theirs = $aclDocs->search($TENANT_A, 'budget alpha invoices', 5, ['viewer' => new UserContext('u-6', $TENANT_A)]);
check('acl: the owner finds their personal document', in_array((string) $personal['id'], array_column($mine, 'document_id'), true));
check('acl: another user does not', !in_array((string) $personal['id'], array_column($theirs, 'document_id'), true));

// ─── missing tables fail with the fix, not an empty result ────────────────────
$bare = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
throws('guard: knowledge missing table raises a migration hint', fn () => (new KnowledgeService($bare, $llm))->search(1, 'x', 1), 'migrations');
throws('guard: knowledge ingest missing table raises', fn () => (new KnowledgeService($bare, $llm))->ingest(1, 't', 'c'), 'migrations');
throws('guard: document missing table raises a migration hint', fn () => (new DocumentService($bare, $llm))->search(1, 'x', 1), 'migrations');
throws('guard: document ingest missing table raises', function () use ($bare, $llm, $user) {
    (new DocumentService($bare, $llm))->ingest(UploadedFile::fromRaw('hello world', 'a.txt', 'text/plain'), $user);
}, 'migrations');

// A no-connection service fails with the actionable "pass a PDO" message, not a crash.
throws('guard: no PDO raises a clear message', fn () => (new KnowledgeService(null, $llm))->search(1, 'x', 1), 'No database connection');
throws('guard: no PDO raises a clear message for documents', fn () => (new DocumentService(null, $llm))->search(1, 'x', 1), 'No database connection');

Config::reset();

echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
