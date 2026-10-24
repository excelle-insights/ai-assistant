<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Client\OpenAIClient;
use ExcelleInsights\AiAssistant\Contracts\KnowledgeRetrieverInterface;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\EnvLoader;
use ExcelleInsights\AiAssistant\Support\SearchHit;
use ExcelleInsights\AiAssistant\Support\TextScore;
use PDO;

/**
 * The trained knowledge base: `{prefix}_knowledge`.
 *
 * Facts a human, a CSV or the assistant's own reviewed answers wrote down —
 * prices, policies, procedures — that the assistant may quote and cite.
 * Retrieval is hybrid like the document retriever: cosine similarity where
 * embeddings exist, `TextScore` keyword coverage everywhere else, blended per
 * `knowledge.retrieval`.
 *
 * Two rules this service exists to enforce:
 *   - No match is `[]`, never "here is something unrelated". Injecting the newest
 *     entry into a prompt turns an honest "I do not know" into a confident wrong
 *     answer, so that behaviour is opt-in (`knowledge.fallback_latest`) and every
 *     row it returns is marked `'fallback' => true`.
 *   - Re-training a fact updates it. Ingest is an UPSERT keyed on a content hash —
 *     a re-imported file never doubles the corpus — bumping `updated_at` and the
 *     entry's version when the text actually changed.
 *
 * Every read and write is scoped to one tenant (column from `tenant.column`) and
 * to the caller's visibility: scope `global` is open to the tenant, `personal` to
 * its author, `role` to the roles it names.
 */
class KnowledgeService implements KnowledgeRetrieverInterface
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    /** Column lengths of {prefix}_knowledge, so a value never overflows its column. */
    private const LIMITS = [
        'title' => 255, 'category' => 100, 'source' => 50, 'status' => 20,
        'scope' => 20, 'scope_key' => 190, 'doc_id' => 36, 'updated_by' => 100,
    ];

    private ?PDO $pdo;
    private ?LlmClientInterface $openAI;
    private string $retrieval;
    private float $minSimilarity;
    private float $vectorWeight;
    private int $topK;
    private bool $fallbackLatest;
    private int $scanLimit;
    private int $dedupeScan;

    /** @var array<string,bool> */
    private array $columnCache = [];
    private ?string $tenantColumn = null;

    /**
     * @param array<string,mixed> $options retrieval, min_similarity, vector_weight,
     *                                     top_k, fallback_latest, scan_limit, dedupe_scan
     */
    public function __construct(?PDO $pdo = null, ?LlmClientInterface $openAI = null, array $options = [])
    {
        EnvLoader::load();
        $this->pdo = $pdo;
        if ($openAI === null) {
            try {
                $this->openAI = new OpenAIClient();
            } catch (\Throwable) {
                $this->openAI = null;
            }
        } else {
            $this->openAI = $openAI;
        }

        $this->retrieval = self::retrievalMode($options['retrieval'] ?? Config::string('knowledge.retrieval', 'hybrid'));
        $this->minSimilarity = max(0.0, (float) ($options['min_similarity'] ?? Config::float('knowledge.min_similarity', 0.2)));
        $this->vectorWeight = min(1.0, max(0.0, (float) ($options['vector_weight'] ?? Config::float('knowledge.vector_weight', 0.6))));
        $this->topK = max(1, (int) ($options['top_k'] ?? Config::int('knowledge.top_k', 5)));
        $this->fallbackLatest = (bool) ($options['fallback_latest'] ?? Config::bool('knowledge.fallback_latest', false));
        $this->scanLimit = max(20, (int) ($options['scan_limit'] ?? Config::int('knowledge.scan_limit', 500)));
        $this->dedupeScan = max(50, (int) ($options['dedupe_scan'] ?? Config::int('knowledge.dedupe_scan', 2000)));
    }

    // ─── retrieval ────────────────────────────────────────────────────────────

    /**
     * Best-matching knowledge for one question, best first. Each row is a
     * SearchHit: a structured record for hosts and citations that also renders as
     * the legacy "[category] title: content" prompt line.
     *
     * @param int                                  $k         0 = configured knowledge.top_k
     * @param UserContext|array<string,mixed>|null $context   viewer, for scope visibility
     * @return list<SearchHit>
     */
    public function search(int $companyId, string $query, int $k = 5, UserContext|array|null $context = null): array
    {
        $needle = trim($query);
        if ($needle === '') {
            return [];
        }

        $limit = max(1, $k > 0 ? $k : $this->topK);
        $candidates = $this->visible($companyId, $context);
        if ($candidates === []) {
            return [];
        }

        $terms = TextScore::tokenize($needle);
        $question = TextScore::normalize($needle);
        $queryVector = $this->retrieval === 'lexical' ? [] : $this->embed($needle);

        $scored = [];
        foreach ($candidates as $row) {
            $lexical = TextScore::keywordScore($terms, [
                'title' => (string) ($row['title'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'tags' => (string) ($row['tags'] ?? ''),
            ], $question);

            $stored = TextScore::vectorOf($row['embedding'] ?? null);
            $vector = $queryVector !== [] && $stored !== null ? TextScore::cosine($queryVector, $stored) : null;

            // No vector means the provider has no embeddings endpoint, so the
            // keyword score stands alone rather than the entry vanishing.
            $score = match ($this->retrieval) {
                'lexical' => $lexical,
                'vector' => TextScore::blend($vector, $lexical, 1.0),
                default => TextScore::blend($vector, $lexical, $this->vectorWeight),
            };

            if ($score < $this->minSimilarity) {
                continue;
            }
            $scored[] = $this->hit($row, round($score, 6), $vector, $lexical, false);
        }

        if ($scored === []) {
            if (!$this->fallbackLatest) {
                // The honest answer: nothing here matches, so the assistant says so.
                return [];
            }
            return array_map(
                fn (array $row): SearchHit => $this->hit($row, 0.0, null, 0.0, true),
                $this->visible($companyId, $context, $limit),
            );
        }

        usort($scored, static function (SearchHit $a, SearchHit $b): int {
            $diff = (float) $b['score'] <=> (float) $a['score'];
            if ($diff !== 0) {
                return $diff;
            }
            $pinned = (int) $b['pinned'] <=> (int) $a['pinned'];
            return $pinned !== 0 ? $pinned : strcmp((string) $a['id'], (string) $b['id']);
        });

        return array_slice($scored, 0, $limit);
    }

    /**
     * The same retrieval as plain arrays, for a citation panel or an admin preview.
     *
     * @param UserContext|array<string,mixed>|null $context
     * @return list<array<string,mixed>>
     */
    public function retrieve(int $companyId, string $query, int $k = 0, UserContext|array|null $context = null): array
    {
        return array_map(
            static fn (SearchHit $hit): array => $hit->toArray(),
            $this->search($companyId, $query, $k, $context),
        );
    }

    // ─── training ─────────────────────────────────────────────────────────────

    /**
     * Train one fact. Idempotent: the same text twice stays one row; changed text
     * under the same title updates that row and bumps its version.
     *
     * @param array<string,mixed> $extra scope, scope_key, doc_id, pinned, status,
     *                                   updated_by, metadata, tags, roles
     * @return int the knowledge row id
     */
    public function ingest(
        int $companyId,
        string $title,
        string $content,
        string $source = 'manual',
        ?string $category = null,
        array $extra = [],
    ): int {
        return $this->ingestRow($companyId, $title, $content, $source, $category, $extra)['id'];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array{id:int,created:bool,changed:bool}
     */
    public function ingestRow(
        int $companyId,
        string $title,
        string $content,
        string $source = 'manual',
        ?string $category = null,
        array $extra = [],
    ): array {
        $title = trim($title);
        $content = trim($content);
        if ($title === '' && $content === '') {
            throw new \InvalidArgumentException('Knowledge needs a title or a content to store.');
        }
        if ($title === '') {
            $title = mb_substr($content, 0, 120);
        }

        $table = $this->table();
        $category = $category === null ? null : self::clip('category', trim($category));
        $hash = self::hash($title, $content);
        $scope = self::slug((string) ($extra['scope'] ?? 'global'));
        $scopeKey = self::clip('scope_key', trim((string) ($extra['scope_key'] ?? '')));

        $identical = null;
        $sameTitle = null;
        foreach ($this->scanForDedupe($table, $companyId) as $row) {
            if ((string) ($row['content_hash'] ?? '') !== '' && hash_equals((string) $row['content_hash'], $hash)) {
                $identical = $row;
                break;
            }
            if ($sameTitle === null
                && TextScore::normalize((string) ($row['title'] ?? '')) === TextScore::normalize($title)
                && self::slug((string) ($row['scope'] ?? 'global')) === $scope
                && trim((string) ($row['scope_key'] ?? '')) === $scopeKey
                && ($category === null || $category === '' || (string) ($row['category'] ?? '') === $category)
            ) {
                $sameTitle = $row;
            }
        }

        if ($identical !== null) {
            return ['id' => (int) $identical['id'], 'created' => false, 'changed' => false];
        }

        $embedding = $this->embed($title . "\n" . $content);
        $version = 1;
        $now = Db::now();

        $payload = [
            $this->tenantColumn() => $companyId,
            'title' => self::clip('title', $title),
            'content' => $content,
            'source' => self::clip('source', $source),
            'status' => self::slug((string) ($extra['status'] ?? self::STATUS_ACTIVE)),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        foreach (['category' => $category, 'scope' => $scope, 'scope_key' => $scopeKey ?: null] as $column => $value) {
            if ($this->hasColumn($table, $column)) {
                $payload[$column] = is_string($value) ? self::clip($column, $value) : $value;
            }
        }
        if ($this->hasColumn($table, 'doc_id')) {
            $payload['doc_id'] = isset($extra['doc_id']) ? self::clip('doc_id', (string) $extra['doc_id']) : null;
        }
        if ($this->hasColumn($table, 'embedding')) {
            $payload['embedding'] = Db::json($embedding);
        }
        if ($this->hasColumn($table, 'content_hash')) {
            $payload['content_hash'] = $hash;
        }
        if ($this->hasColumn($table, 'token_count')) {
            $payload['token_count'] = Db::estimateTokens($title . ' ' . $content);
        }
        if ($this->hasColumn($table, 'updated_by')) {
            $payload['updated_by'] = isset($extra['updated_by']) ? self::clip('updated_by', (string) $extra['updated_by']) : null;
        }
        if ($this->hasColumn($table, 'pinned')) {
            $payload['pinned'] = empty($extra['pinned']) ? 0 : 1;
        }
        if ($this->hasColumn($table, 'metadata_json')) {
            $meta = (array) ($extra['metadata'] ?? []);
            if (isset($extra['tags'])) {
                $meta['tags'] = array_values(array_map('strval', (array) $extra['tags']));
            }
            if (isset($extra['roles'])) {
                $meta['roles'] = array_values(array_map('strval', (array) $extra['roles']));
            }
            $meta['version'] = $version;
            $payload['metadata_json'] = Db::json($meta);
        }

        if ($sameTitle === null) {
            Db::insert($this->connection(), $table, $payload);
            return ['id' => $this->lastId($table, $companyId, $hash), 'created' => true, 'changed' => true];
        }

        // Re-training the same title: update in place, never a second row.
        $version = (int) (Db::decode($sameTitle['metadata_json'] ?? null)['version'] ?? 1) + 1;
        if ($this->hasColumn($table, 'metadata_json')) {
            $meta = (array) (Db::decode($sameTitle['metadata_json'] ?? null) + (array) ($extra['metadata'] ?? []));
            $meta['version'] = $version;
            $payload['metadata_json'] = Db::json($meta);
        }
        if (!isset($extra['status'])) {
            unset($payload['status']); // a deactivated fact stays deactivated until someone re-publishes it
        }
        unset($payload['created_at']);
        Db::updateWhere(
            $this->connection(),
            $table,
            $payload,
            ['id' => (int) $sameTitle['id'], $this->tenantColumn() => $companyId],
            '',
        );
        return ['id' => (int) $sameTitle['id'], 'created' => false, 'changed' => true];
    }

    /**
     * Edit a trained fact in place, scoped to its tenant.
     *
     * @param array<string,mixed> $changes title, content, category, source, status,
     *                                     scope, scope_key, doc_id, pinned, updated_by,
     *                                     metadata, tags
     */
    public function update(int $id, int $tenantId, array $changes): bool
    {
        $table = $this->table();
        if ($id <= 0 || $changes === []) {
            return false;
        }
        $existing = Db::select($this->connection(), $table, ['id' => $id, $this->tenantColumn() => $tenantId], [], [], 1);
        $row = $existing[0] ?? null;
        if ($row === null) {
            return false; // another tenant's id is simply not here
        }

        $values = [];
        foreach (array_keys(self::LIMITS) as $column) {
            if (!array_key_exists($column, $changes) || !$this->hasColumn($table, $column)) {
                continue;
            }
            $raw = $changes[$column];
            if ($raw === null) {
                $values[$column] = null;
                continue;
            }
            $text = trim((string) $raw);
            $values[$column] = match ($column) {
                'scope', 'source', 'status' => self::slug($text) ?: null,
                default => $text === '' ? null : self::clip($column, $text),
            };
        }
        if (isset($values['status'])) {
            $values['status'] = in_array($values['status'], [self::STATUS_ACTIVE, self::STATUS_INACTIVE, 'review', 'archived'], true)
                ? $values['status']
                : self::STATUS_ACTIVE;
        }
        if (array_key_exists('pinned', $changes) && $this->hasColumn($table, 'pinned')) {
            $values['pinned'] = empty($changes['pinned']) ? 0 : 1;
        }

        if (array_key_exists('content', $changes)) {
            $values['content'] = $changes['content'] === null ? '' : trim((string) $changes['content']);
        }

        $textChanged = array_key_exists('title', $changes) || array_key_exists('content', $changes);
        if ($textChanged) {
            $title = (string) ($values['title'] ?? $row['title'] ?? '');
            $content = (string) ($values['content'] ?? $row['content'] ?? '');
            if ($this->hasColumn($table, 'content_hash')) {
                $values['content_hash'] = self::hash($title, $content);
            }
            if ($this->hasColumn($table, 'embedding')) {
                $values['embedding'] = Db::json($this->embed($title . "\n" . $content));
            }
            if ($this->hasColumn($table, 'token_count')) {
                $values['token_count'] = Db::estimateTokens($title . ' ' . $content);
            }
        }
        if (($textChanged || isset($changes['metadata']) || isset($changes['tags'])) && $this->hasColumn($table, 'metadata_json')) {
            $meta = Db::decode($row['metadata_json'] ?? null);
            if (isset($changes['metadata'])) {
                $meta = is_array($changes['metadata']) ? $changes['metadata'] + $meta : $meta;
            }
            if (isset($changes['tags'])) {
                $meta['tags'] = array_values(array_map('strval', (array) $changes['tags']));
            }
            $meta['version'] = (int) ($meta['version'] ?? 1) + ($textChanged ? 1 : 0);
            $values['metadata_json'] = Db::json($meta);
        }

        unset($values['created_at']);
        if ($values === []) {
            return false;
        }
        $values['updated_at'] = Db::now();

        return Db::updateWhere($this->connection(), $table, $values, ['id' => $id, $this->tenantColumn() => $tenantId], '') > 0;
    }

    /** Take a fact out of retrieval without losing its history. */
    public function deactivate(int $id, int $tenantId): bool
    {
        return $this->setStatus($id, $tenantId, self::STATUS_INACTIVE);
    }

    /** Put a fact back in. */
    public function activate(int $id, int $tenantId): bool
    {
        return $this->setStatus($id, $tenantId, self::STATUS_ACTIVE);
    }

    /**
     * Corpus shape, for an admin screen.
     *
     * @return array<string,mixed> total, active, inactive, embedded, tokens,
     *                             by_status, by_category, by_source, last_updated_at
     */
    public function stats(int $tenantId): array
    {
        $table = $this->table();
        $conn = $this->connection();
        $tenant = $this->tenantColumn();
        $out = [
            'total' => 0, 'active' => 0, 'inactive' => 0, 'embedded' => 0, 'tokens' => 0,
            'by_status' => [], 'by_category' => [], 'by_source' => [], 'last_updated_at' => '',
        ];

        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM `' . $table . '` WHERE `' . $tenant . '` = :t');
        $stmt->execute([':t' => $tenantId]);
        $out['total'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        if ($this->hasColumn($table, 'status')) {
            $stmt = $conn->prepare('SELECT `status`, COUNT(*) AS total FROM `' . $table . '` WHERE `' . $tenant . '` = :t GROUP BY `status`');
            $stmt->execute([':t' => $tenantId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $status = (string) ($row['status'] ?? '');
                $out['by_status'][$status] = (int) $row['total'];
            }
            $out['active'] = $out['by_status'][self::STATUS_ACTIVE] ?? 0;
            $out['inactive'] = $out['by_status'][self::STATUS_INACTIVE] ?? 0;
        }

        foreach (['category' => 'by_category', 'source' => 'by_source'] as $column => $key) {
            if (!$this->hasColumn($table, $column)) {
                continue;
            }
            $stmt = $conn->prepare('SELECT `' . $column . '` AS name, COUNT(*) AS total FROM `' . $table . '`'
                . ' WHERE `' . $tenant . '` = :t GROUP BY `' . $column . '` ORDER BY total DESC');
            $stmt->execute([':t' => $tenantId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[$key][(string) ($row['name'] ?? '')] = (int) $row['total'];
            }
        }

        if ($this->hasColumn($table, 'embedding')) {
            $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM `' . $table . '` WHERE `' . $tenant . '` = :t'
                . ' AND `embedding` IS NOT NULL AND `embedding` <> \'\'');
            $stmt->execute([':t' => $tenantId]);
            $out['embedded'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        }
        if ($this->hasColumn($table, 'token_count')) {
            $stmt = $conn->prepare('SELECT COALESCE(SUM(`token_count`), 0) AS total FROM `' . $table . '` WHERE `' . $tenant . '` = :t');
            $stmt->execute([':t' => $tenantId]);
            $out['tokens'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        }
        if ($this->hasColumn($table, 'updated_at')) {
            $stmt = $conn->prepare('SELECT MAX(`updated_at`) AS latest FROM `' . $table . '` WHERE `' . $tenant . '` = :t');
            $stmt->execute([':t' => $tenantId]);
            $out['last_updated_at'] = (string) ($stmt->fetch(PDO::FETCH_ASSOC)['latest'] ?? '');
        }

        return $out;
    }

    /** @return list<array<string,mixed>> newest first; embeddings are never listed */
    public function list(int $companyId, int $limit = 500): array
    {
        $table = $this->table();
        $columns = $this->existing($table, [
            'id', 'title', 'content', 'category', 'source', 'status', 'scope', 'scope_key', 'doc_id',
            'content_hash', 'token_count', 'pinned', 'updated_by', 'metadata_json', 'created_at', 'updated_at',
        ]);
        $rows = Db::select(
            $this->connection(),
            $table,
            [$this->tenantColumn() => $companyId],
            [],
            [['updated_at', 'desc'], ['id', 'desc']],
            max(1, $limit),
            '',
            $columns === [] ? null : $columns,
        );
        return array_map(fn (array $row): array => $this->publicRow($row), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $companyId): ?array
    {
        $rows = Db::select($this->connection(), $this->table(), ['id' => $id, $this->tenantColumn() => $companyId], [], [], 1);
        $row = $rows[0] ?? null;
        return $row === null ? null : $this->publicRow($row);
    }

    /** Delete one entry — only ever its own tenant's row. */
    public function delete(int $id, int $companyId): bool
    {
        $stmt = $this->connection()->prepare(
            'DELETE FROM `' . $this->table() . '` WHERE `id` = :id AND `' . $this->tenantColumn() . '` = :tenant'
        );
        $stmt->execute([':id' => $id, ':tenant' => $companyId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Import knowledge from CSV text (header: category,title,content,scope?).
     * Re-importing the same file updates the rows instead of duplicating them.
     *
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int,errors:list<string>}
     */
    public function importCsv(int $companyId, string $csv, string $source = 'csv'): array
    {
        $result = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => []];
        if (trim($csv) === '') {
            $result['errors'][] = 'CSV is empty';
            return $result;
        }
        $rows = array_map(
            static fn ($r): array => str_getcsv((string) $r, ',', '"', '\\'),
            preg_split('/\r\n|\r|\n/', trim($csv)) ?: [],
        );
        if ($rows === []) {
            $result['errors'][] = 'CSV is empty';
            return $result;
        }

        $header = array_map(static fn ($h): string => strtolower(trim((string) $h)), $rows[0]);
        $idxCat = array_search('category', $header, true);
        $idxTitle = array_search('title', $header, true);
        $idxContent = array_search('content', $header, true);
        $idxScope = array_search('scope', $header, true);
        if ($idxTitle === false || $idxContent === false) {
            $result['errors'][] = 'CSV must have "title" and "content" columns (category is optional)';
            return $result;
        }

        foreach (array_slice($rows, 1) as $i => $row) {
            $title = trim((string) ($row[$idxTitle] ?? ''));
            $content = trim((string) ($row[$idxContent] ?? ''));
            $category = $idxCat !== false ? trim((string) ($row[$idxCat] ?? '')) : '';
            if ($title === '' && $content === '') {
                $result['skipped']++;
                continue;
            }
            try {
                $outcome = $this->ingestRow(
                    $companyId,
                    $title,
                    $content,
                    $source,
                    $category !== '' ? $category : null,
                    $idxScope !== false ? ['scope' => trim((string) ($row[$idxScope] ?? ''))] : [],
                );
                if ($outcome['created']) {
                    $result['inserted']++;
                } elseif ($outcome['changed']) {
                    $result['updated']++;
                } else {
                    $result['unchanged']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = 'Row ' . ($i + 2) . ': ' . $e->getMessage();
            }
        }
        return $result;
    }

    /** Starter file for a host's training screen. */
    public function csvTemplate(): string
    {
        $lines = [
            'category,title,content',
            '"Policies","Returns","Items may be returned within 30 days of delivery with proof of purchase. Perishable goods are not returnable."',
            '"Policies","Discount limit","No discount above 10 percent may be promised to a customer. Route larger requests to a manager."',
            '"Company","What we do","One or two plain sentences describing the organisation, so the assistant never has to guess."',
            '"Company","Working hours","Open Monday to Friday 08:00 to 17:00 and Saturday 08:00 to 12:00. Closed on public holidays."',
            '"Contact","Support channel","Questions arrive on the same channel the customer first used. Reply within one business day."',
            '"Products","Service pricing","One product per row. State the price and the unit together: \'Standard clean, 3 hours, fixed price\'."',
            '"Process","Escalation","Angry customer: apologise, summarise the problem, offer a concrete next step, then hand to a human."',
        ];
        return implode("\n", $lines);
    }

    // ─── internals ────────────────────────────────────────────────────────────

    private function setStatus(int $id, int $tenantId, string $status): bool
    {
        if ($id <= 0) {
            return false;
        }
        return Db::updateWhere(
            $this->connection(),
            $this->table(),
            ['status' => $status, 'updated_at' => Db::now()],
            ['id' => $id, $this->tenantColumn() => $tenantId],
            '',
        ) > 0;
    }

    /** @return int the new row id, looked up by hash when the driver has no id */
    private function lastId(string $table, int $companyId, string $hash): int
    {
        try {
            $id = (int) $this->connection()->lastInsertId();
            if ($id > 0) {
                return $id;
            }
        } catch (\Throwable) {
            // Keyless table: fall through to the hash lookup.
        }
        if ($this->hasColumn($table, 'content_hash')) {
            $rows = Db::select(
                $this->connection(),
                $table,
                [$this->tenantColumn() => $companyId, 'content_hash' => $hash],
                [],
                [['id', 'desc']],
                1,
                '',
                ['id'],
            );
            if (isset($rows[0]['id'])) {
                return (int) $rows[0]['id'];
            }
        }
        return 0;
    }

    /**
     * Rows this tenant owns that the caller may see — the candidate window the
     * ranking happens over. `content_hash` is read for dedupe only.
     *
     * @param UserContext|array<string,mixed>|null $context
     * @return list<array<string,mixed>>
     */
    private function visible(int $companyId, UserContext|array|null $context, int $limit = 0): array
    {
        $table = $this->table();
        $columns = $this->existing($table, [
            'id', 'title', 'content', 'category', 'embedding', 'source', 'status', 'scope', 'scope_key',
            'content_hash', 'token_count', 'pinned', 'updated_by', 'metadata_json', 'created_at', 'updated_at',
        ]);

        $rows = Db::select(
            $this->connection(),
            $table,
            [$this->tenantColumn() => $companyId],
            [],
            [['updated_at', 'desc'], ['id', 'desc']],
            $limit > 0 ? $limit : $this->scanLimit,
            '',
            $columns === [] ? null : $columns,
        );

        $out = [];
        foreach ($rows as $row) {
            if ((string) ($row['status'] ?? self::STATUS_ACTIVE) !== self::STATUS_ACTIVE) {
                continue;
            }
            $meta = Db::decode($row['metadata_json'] ?? null);
            $tags = $meta['tags'] ?? [];
            $row['tags'] = is_array($tags) ? implode(' ', $tags) : (string) $tags;
            $row['metadata'] = $meta;
            if ($this->isVisible($row, $context)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Scope visibility. With no viewer only global entries are visible — exactly
     * the set an install without scopes exposed, so nothing leaks and nothing is
     * hidden by accident.
     *
     * @param array<string,mixed>              $row
     * @param UserContext|array<string,mixed>|null $context
     */
    private function isVisible(array $row, UserContext|array|null $context): bool
    {
        $scope = self::slug((string) ($row['scope'] ?? 'global'));
        if ($scope === 'global') {
            return true;
        }
        if ($context === null) {
            return false;
        }
        $user = $context instanceof UserContext ? $context : UserContext::fromArray($context);
        $key = trim((string) ($row['scope_key'] ?? ''));
        $roles = array_map('strval', $user->roles);

        if ($scope === 'personal') {
            return $key !== '' ? $key === $user->userId : trim((string) ($row['updated_by'] ?? '')) === $user->userId;
        }
        if ($scope === 'role') {
            $named = array_values(array_filter(preg_split('/[,\s|]+/', $key) ?: [], static fn ($v) => (string) $v !== ''));
            return array_map('strval', $named) !== []
                ? array_intersect($named, $roles) !== []
                : array_intersect(array_map('strval', (array) ($row['metadata']['roles'] ?? [])), $roles) !== [];
        }

        $allowed = array_map('strval', (array) ($user->attributes['scopes'] ?? $user->attributes['scope_keys'] ?? []));
        return $key !== '' && in_array($key, $allowed, true);
    }

    /** @param array<string,mixed> $row */
    private function hit(array $row, float $score, ?float $vector, float $lexical, bool $fallback): SearchHit
    {
        $meta = (array) ($row['metadata'] ?? []);
        $data = [
            'id' => (int) ($row['id'] ?? 0),
            'type' => 'knowledge',
            'question' => (string) ($row['title'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'answer' => (string) ($row['content'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'source' => (string) ($row['source'] ?? 'manual'),
            'scope' => (string) ($row['scope'] ?? 'global'),
            'scope_key' => (string) ($row['scope_key'] ?? ''),
            'status' => (string) ($row['status'] ?? self::STATUS_ACTIVE),
            'pinned' => (int) ($row['pinned'] ?? 0),
            'version' => (int) ($meta['version'] ?? 1),
            'score' => $score,
            'vector_score' => $vector,
            'lexical_score' => $lexical,
            'retrieval' => $this->retrieval,
            'fallback' => $fallback,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
        return SearchHit::make($data, SearchHit::render($data));
    }

    /**
     * @return list<array<string,mixed>> the columns dedupe reads: title, hash, scope
     *                                   and metadata — never the content itself
     */
    private function scanForDedupe(string $table, int $companyId): array
    {
        $columns = $this->existing($table, ['id', 'title', 'content_hash', 'category', 'scope', 'scope_key', 'metadata_json']);
        if (!in_array('content_hash', $columns, true)) {
            return []; // install without the dedupe migration: nothing to match on
        }
        return Db::select(
            $this->connection(),
            $table,
            [$this->tenantColumn() => $companyId],
            [],
            [['id', 'desc']],
            $this->dedupeScan,
            '',
            $columns,
        );
    }

    /** @return array<string,mixed> */
    private function publicRow(array $row): array
    {
        $meta = Db::decode($row['metadata_json'] ?? null);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'company_id' => (int) ($row[$this->tenantColumn()] ?? $row['company_id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'source' => (string) ($row['source'] ?? 'manual'),
            'status' => (string) ($row['status'] ?? self::STATUS_ACTIVE),
            'scope' => (string) ($row['scope'] ?? 'global'),
            'scope_key' => (string) ($row['scope_key'] ?? ''),
            'doc_id' => (string) ($row['doc_id'] ?? ''),
            'token_count' => (int) ($row['token_count'] ?? 0),
            'pinned' => (int) ($row['pinned'] ?? 0) === 1,
            'updated_by' => (string) ($row['updated_by'] ?? ''),
            'content_hash' => (string) ($row['content_hash'] ?? ''),
            'version' => (int) ($meta['version'] ?? 1),
            'metadata' => $meta,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function table(): string
    {
        return Db::requireTable($this->connection(), 'knowledge');
    }

    private function connection(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException(
                'No database connection available to the AI assistant. Pass the host PDO to KnowledgeService, '
                . 'or configure the DSN/DB_* variables in .env.'
            );
        }
        return $this->pdo;
    }

    /**
     * The tenant column of this install: `tenant.column` from configuration when
     * the table actually carries it, the package default `company_id` otherwise.
     */
    private function tenantColumn(): string
    {
        if ($this->tenantColumn !== null) {
            return $this->tenantColumn;
        }
        $configured = Config::string('tenant.column', 'company_id');
        if ($configured === '' || $configured === 'company_id') {
            return $this->tenantColumn = 'company_id';
        }
        try {
            Db::identifier($configured);
        } catch (\InvalidArgumentException) {
            return $this->tenantColumn = 'company_id';
        }
        return $this->tenantColumn = $this->hasColumn($this->table(), $configured) ? $configured : 'company_id';
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (isset($this->columnCache[$key])) {
            return $this->columnCache[$key];
        }
        try {
            $this->connection()->prepare('SELECT `' . Db::identifier($column) . '` FROM `' . $table . '` LIMIT 0')->execute();
            return $this->columnCache[$key] = true;
        } catch (\Throwable) {
            return $this->columnCache[$key] = false;
        }
    }

    /**
     * @param string[] $columns
     * @return string[] only the columns this install has, so a host that has not
     *                  run the newest migration still gets its rows
     */
    private function existing(string $table, array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column): bool => $this->hasColumn($table, $column)));
    }

    /** @return list<float> [] = "no embedding", which switches retrieval to lexical scoring */
    private function embed(string $text): array
    {
        if ($this->openAI === null || trim($text) === '') {
            return [];
        }
        try {
            $vector = $this->openAI->embeddings($text);
        } catch (\Throwable) {
            return [];
        }
        return is_array($vector) ? $vector : [];
    }

    /** Identity of a trained fact: the text it carries, not how it arrived. */
    public static function hash(string $title, string $content): string
    {
        return hash('sha256', TextScore::normalize($title) . "\n" . TextScore::normalize($content));
    }

    private static function clip(string $column, string $value): string
    {
        return mb_substr($value, 0, self::LIMITS[$column] ?? 255);
    }

    private static function retrievalMode(mixed $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'vector', 'semantic', 'embedding' => 'vector',
            'lexical', 'keyword', 'text' => 'lexical',
            default => 'hybrid',
        };
    }

    /** A slug for scope/status/source values. */
    private static function slug(string $value): string
    {
        $clean = (string) preg_replace('/[^a-z0-9_]/', '', strtolower(trim($value)));
        return $clean;
    }
}
