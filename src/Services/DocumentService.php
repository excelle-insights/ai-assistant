<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Contracts\ChunkIndexInterface;
use ExcelleInsights\AiAssistant\Contracts\DocumentStoreInterface;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Dto\ExtractedDocument;
use ExcelleInsights\AiAssistant\Dto\UploadedFile;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Extractors\ExtractorRegistry;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\EnvLoader;
use ExcelleInsights\AiAssistant\Support\SearchHit;
use ExcelleInsights\AiAssistant\Support\TextScore;
use ExcelleInsights\AiAssistant\Support\TokenChunker;
use PDO;

/**
 * Upload -> text -> chunks -> embeddings -> retrieval for `{prefix}_documents`
 * and `{prefix}_document_chunks`.
 *
 * The package never owns the bytes: they go to the host's blob storage through
 * DocumentStoreInterface (disk, object store, a host table — anything) and only
 * the returned storage key is persisted here. Formats the package cannot read
 * (a scanned image, a proprietary container) are recorded as `needs_extractor`
 * with the registry's actionable message instead of being indexed as empty text.
 *
 * Retrieval is the same hybrid machinery the knowledge base uses: cosine
 * similarity when embeddings exist, `TextScore` keyword coverage when they do
 * not, blended per `knowledge.retrieval`. Nothing is returned that did not
 * clear `knowledge.min_similarity`, so a question a corpus cannot answer yields
 * no rows and the assistant says it does not know.
 */
final class DocumentService
{
    /** Document lifecycle states, in the order a happy path visits them. */
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_INDEXED = 'indexed';
    public const STATUS_EMPTY = 'empty';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEEDS_EXTRACTOR = 'needs_extractor';

    private ?PDO $pdo;
    private ?LlmClientInterface $llm;
    private ExtractorRegistry $extractors;
    private ?ChunkIndexInterface $index;
    private ?DocumentStoreInterface $store;
    private ?KnowledgeService $knowledge = null;

    private int $chunkTokens;
    private int $chunkOverlap;
    private int $maxChunks;
    private int $maxUploadMb;
    /** @var list<string> */
    private array $allowedMime;
    private string $retrieval;
    private float $minSimilarity;
    private float $vectorWeight;
    private int $topK;
    private int $scanLimit;
    private int $mirrorChars;
    private bool $mirrorToKnowledge;
    /** 'reuse' keeps one indexed document per byte-identical upload. */
    private string $onDuplicate;

    /** @var array<string,bool> */
    private array $columnCache = [];
    private ?string $tenantColumn = null;

    /**
     * @param array<string,mixed> $options chunk_tokens, chunk_overlap, max_chunks,
     *                                     max_upload_mb, allowed_mime, retrieval,
     *                                     min_similarity, vector_weight, top_k,
     *                                     scan_limit, on_duplicate,
     *                                     mirror_to_knowledge, mirror_chars
     */
    public function __construct(
        ?PDO $pdo = null,
        ?LlmClientInterface $llm = null,
        ?ExtractorRegistry $extractors = null,
        ?ChunkIndexInterface $index = null,
        ?DocumentStoreInterface $store = null,
        array $options = [],
    ) {
        EnvLoader::load();
        $this->pdo = $pdo;
        $this->llm = $llm;
        $this->extractors = $extractors ?? ExtractorRegistry::fromConfig();
        $this->index = $index;
        $this->store = $store;

        $this->chunkTokens = max(TokenChunker::MIN_CHUNK_TOKENS, (int) ($options['chunk_tokens'] ?? Config::int('documents.chunk_tokens', 800)));
        $this->chunkOverlap = max(0, (int) ($options['chunk_overlap'] ?? Config::int('documents.chunk_overlap', 100)));
        $this->maxChunks = max(1, (int) ($options['max_chunks'] ?? Config::int('documents.max_chunks', 400)));
        $this->maxUploadMb = max(0, (int) ($options['max_upload_mb'] ?? Config::int('limits.max_upload_mb', 25)));
        $this->allowedMime = array_values(array_map('strval', (array) ($options['allowed_mime'] ?? Config::list('documents.allowed_mime', []))));
        $this->retrieval = self::mode($options['retrieval'] ?? Config::string('knowledge.retrieval', 'hybrid'));
        $this->minSimilarity = max(0.0, (float) ($options['min_similarity'] ?? Config::float('knowledge.min_similarity', 0.2)));
        $this->vectorWeight = min(1.0, max(0.0, (float) ($options['vector_weight'] ?? Config::float('knowledge.vector_weight', 0.6))));
        $this->topK = max(1, (int) ($options['top_k'] ?? Config::int('knowledge.top_k', 5)));
        $this->scanLimit = max(20, (int) ($options['scan_limit'] ?? Config::int('documents.scan_limit', 800)));
        $this->mirrorChars = max(200, (int) ($options['mirror_chars'] ?? Config::int('documents.mirror_chars', 1500)));
        $this->mirrorToKnowledge = (bool) ($options['mirror_to_knowledge'] ?? Config::bool('documents.mirror_to_knowledge', false));
        $this->onDuplicate = ((string) ($options['on_duplicate'] ?? 'reuse')) === 'insert' ? 'insert' : 'reuse';
    }

    // ─── ingest ───────────────────────────────────────────────────────────────

    /**
     * Index an upload: extract text, chunk it inside the token budget, embed each
     * chunk when the provider supports embeddings, store the rows, return the
     * document row.
     *
     * A type nothing can read is *recorded*, not silently dropped: the row comes
     * back with status `needs_extractor` and the registry's fix message in
     * `error`. Size and MIME guardrails come from configuration and throw, since
     * they are the caller's mistake rather than a storage condition.
     *
     * @param UserContext|array<string,mixed> $user
     * @param array<string,mixed> $meta scope, scope_key, entity_type, entity_id, title,
     *                                  category, language, roles, user_ids, storage_key,
     *                                  on_duplicate, mirror_to_knowledge, extractor_options
     * @return array<string,mixed> the document row
     */
    public function ingest(UploadedFile $file, UserContext|array $user, array $meta = []): array
    {
        $user = $user instanceof UserContext ? $user : UserContext::fromArray($user);
        $tenantId = $user->tenantId;

        $guard = $file->check($this->maxUploadMb, $this->allowedMime);
        if ($guard !== null) {
            throw new \InvalidArgumentException($guard);
        }

        $table = $this->documentTable();
        $this->chunkTable();
        $hash = $file->sha256();
        $existing = $hash === '' ? null : $this->rowBy($table, ['sha256' => $hash, $this->tenantColumn() => $tenantId]);

        if ($existing !== null && (string) ($existing['status'] ?? '') === self::STATUS_INDEXED
            && ($meta['on_duplicate'] ?? $this->onDuplicate) !== 'insert') {
            return $this->publicRow($existing);
        }

        // A previous attempt that produced nothing (unsupported type, extractor
        // crash) is retried in place, so the list never fills up with corpses.
        $retry = $existing !== null && in_array(
            (string) ($existing['status'] ?? ''),
            [self::STATUS_UPLOADED, self::STATUS_EMPTY, self::STATUS_FAILED, self::STATUS_NEEDS_EXTRACTOR],
            true,
        );
        $documentId = $retry ? (string) $existing['id'] : Db::uuid();
        $storageKey = $this->storeBytes($documentId, $file, $tenantId, $meta);

        $row = [
            'id' => $documentId,
            'company_id' => $tenantId,
            'owner_user_id' => mb_substr($user->userId, 0, 100),
            'scope' => self::slug((string) ($meta['scope'] ?? 'global'), 'global'),
            'entity_type' => isset($meta['entity_type']) ? mb_substr((string) $meta['entity_type'], 0, 60) : null,
            'entity_id' => isset($meta['entity_id']) ? mb_substr((string) $meta['entity_id'], 0, 60) : null,
            'filename' => mb_substr($file->name, 0, 255),
            'mime' => mb_substr($file->mime, 0, 100),
            'size' => $file->size,
            'storage_key' => $storageKey,
            'sha256' => $hash,
            'status' => self::STATUS_UPLOADED,
            'pages' => 0,
            'chunk_count' => 0,
            'language' => isset($meta['language']) ? mb_substr((string) $meta['language'], 0, 10) : null,
            'acl_json' => Db::json(self::acl($meta, $user)),
            'metadata_json' => Db::json($this->documentMeta($meta, $file, $user)),
            'error' => null,
            'indexed_at' => null,
            'expires_at' => isset($meta['expires_at']) ? (string) $meta['expires_at'] : null,
            'created_at' => $retry ? (string) ($existing['created_at'] ?? Db::now()) : Db::now(),
            'updated_at' => Db::now(),
        ];

        if ($retry) {
            $row = array_diff_key($row, ['id' => 0, 'created_at' => 0]);
            $row['metadata_json'] = Db::json(
                $this->documentMeta($meta, $file, $user) + Db::decode($existing['metadata_json'] ?? null)
            );
            Db::updateWhere($this->connection(), $table, $row, ['id' => $documentId], '');
        } else {
            Db::insert($this->connection(), $table, $row);
        }

        return $this->indexDocument($documentId, $file, $user, $meta);
    }

    /**
     * Read the stored bytes again and rebuild every chunk — the fix after a host
     * installs an extractor for a file it could not read before.
     *
     * @param array<string,mixed> $meta overrides merged over the stored metadata
     * @return array<string,mixed> the document row
     */
    public function reindex(int $tenantId, string $documentId, array $meta = []): array
    {
        $document = $this->get($documentId, $tenantId);
        if ($document === null) {
            throw new \RuntimeException('Document "' . $documentId . '" does not exist for this tenant.');
        }

        $file = $this->storedFile($document);
        if ($file === null) {
            throw new \RuntimeException(
                'Cannot reindex "' . (string) ($document['filename'] ?? $documentId) . '": the package never stores '
                . 'upload bytes itself, so hand DocumentService a DocumentStoreInterface that can read the storage '
                . 'key, or ingest the file again.'
            );
        }

        $user = new UserContext(
            userId: (string) ($document['owner_user_id'] ?? 'system'),
            tenantId: $tenantId,
            roles: array_values((array) ($document['acl']['roles'] ?? [])),
        );

        $this->dropChunks($tenantId, $documentId);
        return $this->indexDocument($documentId, $file, $user, $meta + (array) ($document['metadata'] ?? []));
    }

    // ─── retrieval ────────────────────────────────────────────────────────────

    /**
     * Hybrid search over the tenant's indexed chunks, best match first.
     *
     * @param array<string,mixed> $filters document_id, document_ids, entity_type,
     *                                     entity_id, scope, status, k, and viewer
     *                                     (UserContext or an array with
     *                                     user_id/roles) to apply the ACL
     * @return list<SearchHit> rows with document_id, chunk_no, content, title,
     *                         source, score, page and section when they are known
     */
    public function search(int $tenantId, string $query, int $k = 5, array $filters = []): array
    {
        $needle = trim($query);
        if ($needle === '') {
            return [];
        }

        $limit = max(1, (int) ($filters['k'] ?? ($k > 0 ? $k : $this->topK)));
        $terms = TextScore::tokenize($needle);
        $question = TextScore::normalize($needle);
        $queryVector = $this->retrieval === 'lexical' ? [] : $this->embed($needle);

        $scored = [];
        foreach ($this->candidateRows($tenantId, $filters) as $row) {
            $lexical = TextScore::keywordScore($terms, [
                'title' => (string) ($row['title'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
            ], $question);

            $stored = TextScore::vectorOf($row['embedding'] ?? null);
            $vector = $queryVector !== [] && $stored !== null ? TextScore::cosine($queryVector, $stored) : null;

            // A missing vector means "this provider has no embeddings", so the
            // keyword score stands alone instead of the hit silently disappearing.
            $score = match ($this->retrieval) {
                'lexical' => $lexical,
                'vector' => TextScore::blend($vector, $lexical, 1.0),
                default => TextScore::blend($vector, $lexical, $this->vectorWeight),
            };

            if ($score < $this->minSimilarity) {
                continue;
            }
            $row['lexical_score'] = $lexical;
            $row['vector_score'] = $vector;
            $scored[] = $this->hit($row, round($score, 6));
        }

        foreach ($this->indexHits($tenantId, $queryVector, $filters) as $extra) {
            $scored[] = $extra;
        }

        usort($scored, static function (SearchHit $a, SearchHit $b): int {
            $diff = (float) $b['score'] <=> (float) $a['score'];
            return $diff !== 0 ? $diff : strcmp((string) $a['id'], (string) $b['id']);
        });

        return array_slice($scored, 0, $limit);
    }

    /**
     * @return list<array<string,mixed>> newest-first document rows, no chunk text
     */
    public function list(int $tenantId, int $limit = 100): array
    {
        $table = $this->documentTable();
        $columns = $this->existing($table, [
            'id', 'company_id', 'owner_user_id', 'scope', 'entity_type', 'entity_id', 'filename',
            'mime', 'size', 'status', 'pages', 'chunk_count', 'language', 'storage_key', 'sha256',
            'acl_json', 'metadata_json', 'indexed_at', 'expires_at', 'created_at', 'updated_at', 'error',
        ]);

        $rows = Db::select(
            $this->connection(),
            $table,
            [$this->tenantColumn() => $tenantId],
            [],
            [['created_at', 'desc'], ['id', 'desc']],
            max(1, $limit),
            '',
            $columns === [] ? null : $columns
        );
        return array_map(fn (array $row): array => $this->publicRow($row), $rows);
    }

    /** @return array<string,mixed>|null the document row; chunk text is not here */
    public function get(string $documentId, int $tenantId): ?array
    {
        if ($documentId === '') {
            return null;
        }
        $rows = Db::select(
            $this->connection(),
            $this->documentTable(),
            ['id' => $documentId, $this->tenantColumn() => $tenantId],
            [],
            [],
            1,
        );
        $row = $rows[0] ?? null;
        return $row === null ? null : $this->publicRow($row);
    }

    /**
     * Chunk text of one document, in order: the indexed text a citation or a
     * viewer renders. (The uploaded bytes themselves are never in the database.)
     *
     * @return list<array<string,mixed>>
     */
    public function chunks(string $documentId, int $tenantId, int $limit = 200): array
    {
        if ($documentId === '') {
            return [];
        }
        $table = $this->chunkTable();
        $columns = $this->existing($table, [
            'id', 'document_id', 'chunk_no', 'page_from', 'page_to', 'content', 'token_count', 'metadata_json',
        ]);
        $rows = Db::select(
            $this->connection(),
            $table,
            ['document_id' => $documentId, $this->chunkTenantColumn() => $tenantId],
            [],
            [['chunk_no', 'asc']],
            max(1, $limit),
            '',
            $columns,
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) ($row['id'] ?? ''),
            'document_id' => (string) ($row['document_id'] ?? ''),
            'chunk_no' => (int) ($row['chunk_no'] ?? 0),
            'content' => (string) ($row['content'] ?? ''),
            'token_count' => (int) ($row['token_count'] ?? 0),
            'page' => isset($row['page_from']) && $row['page_from'] !== null ? (int) $row['page_from'] : null,
            'section' => Db::decode($row['metadata_json'] ?? null)['section'] ?? null,
        ], $rows);
    }

    /**
     * Delete a document, its chunks, its vector-index entries and — only when the
     * host handed us a DocumentStoreInterface — its blob.
     */
    public function delete(string $documentId, int $tenantId): bool
    {
        $document = $this->get($documentId, $tenantId);
        if ($document === null) {
            return false;
        }

        $conn = $this->connection();
        $conn
            ->prepare('DELETE FROM `' . $this->chunkTable() . '` WHERE `document_id` = :id AND `' . $this->chunkTenantColumn() . '` = :tenant')
            ->execute([':id' => $documentId, ':tenant' => $tenantId]);
        $conn
            ->prepare('DELETE FROM `' . $this->documentTable() . '` WHERE `id` = :id AND `' . $this->tenantColumn() . '` = :tenant')
            ->execute([':id' => $documentId, ':tenant' => $tenantId]);

        try {
            $this->index?->deleteDocument($tenantId, $documentId);
        } catch (\Throwable) {
            // A remote index cleans itself up; the package's own rows are gone.
        }

        $key = trim((string) ($document['storage_key'] ?? ''));
        if ($this->store !== null && $key !== '') {
            try {
                $this->store->delete($key);
            } catch (\Throwable) {
                // The host store is the authority on its own blobs.
            }
        }
        return true;
    }

    // ─── internals: indexing ──────────────────────────────────────────────────

    /**
     * Extract -> chunk -> embed -> persist for a document row that already exists.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed> the document row
     */
    private function indexDocument(string $documentId, UploadedFile $file, UserContext $user, array $meta): array
    {
        $table = $this->documentTable();
        $tenantId = $user->tenantId;

        try {
            $extracted = $this->extractors->extract($file, (array) ($meta['extractor_options'] ?? []));
        } catch (\DomainException $e) {
            Db::updateWhere(
                $this->connection(),
                $table,
                ['status' => self::STATUS_NEEDS_EXTRACTOR, 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => Db::now()],
                ['id' => $documentId],
                '',
            );
            return $this->get($documentId, $tenantId) ?? ['id' => $documentId, 'status' => self::STATUS_NEEDS_EXTRACTOR];
        } catch (\Throwable $e) {
            Db::updateWhere(
                $this->connection(),
                $table,
                ['status' => self::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => Db::now()],
                ['id' => $documentId],
                '',
            );
            return $this->get($documentId, $tenantId) ?? ['id' => $documentId, 'status' => self::STATUS_FAILED];
        }

        $text = TokenChunker::normalise($extracted->text);
        if (trim($text) === '') {
            Db::updateWhere(
                $this->connection(),
                $table,
                ['status' => self::STATUS_EMPTY, 'chunk_count' => 0, 'error' => 'No text could be read from this file.', 'updated_at' => Db::now()],
                ['id' => $documentId],
                '',
            );
            $this->mirror($documentId, $tenantId, $file, $extracted, $meta);
            return $this->get($documentId, $tenantId) ?? ['id' => $documentId, 'status' => self::STATUS_EMPTY];
        }

        $payload = $this->chunkPayload($documentId, $tenantId, $file, $extracted, $text, $meta);
        if ($payload['rows'] !== []) {
            $conn = $this->connection();
            $owned = !$conn->inTransaction();
            if ($owned) {
                $conn->beginTransaction();
            }
            try {
                $conn->prepare('DELETE FROM `' . $this->chunkTable() . '` WHERE `document_id` = :id')->execute([':id' => $documentId]);
                foreach ($payload['rows'] as $values) {
                    Db::insert($conn, $this->chunkTable(), $values);
                }
                if ($owned) {
                    $conn->commit();
                }
            } catch (\Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                Db::updateWhere(
                    $conn,
                    $table,
                    ['status' => self::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => Db::now()],
                    ['id' => $documentId],
                    '',
                );
                throw new \RuntimeException('Could not store the chunks of "' . $file->name . '": ' . $e->getMessage(), 0, $e);
            }
        }

        if ($this->index !== null && $payload['index'] !== []) {
            try {
                $this->index->upsert($tenantId, $payload['index']);
            } catch (\Throwable) {
                // The JSON embeddings are stored in the row itself, so retrieval works anyway.
            }
        }

        $update = [
            'status' => self::STATUS_INDEXED,
            'chunk_count' => count($payload['rows']),
            'pages' => max((int) $extracted->pageCount, count($extracted->pages)),
            'language' => mb_substr((string) ($meta['language'] ?? $extracted->language ?? $extracted->detectLanguage()), 0, 10),
            'metadata_json' => Db::json(
                $this->documentMeta($meta, $file, $user) + [
                    'extractor' => (string) ($extracted->meta['extractor'] ?? ''),
                    'truncated' => count($payload['rows']) >= $this->maxChunks,
                ]
            ),
            'error' => null,
            'indexed_at' => Db::now(),
            'updated_at' => Db::now(),
        ];
        Db::updateWhere($this->connection(), $table, $update, ['id' => $documentId], '');
        $this->mirror($documentId, $tenantId, $file, $extracted, $meta);

        return $this->get($documentId, $tenantId) ?? $update;
    }

    /**
     * Chunk the extraction and embed each piece.
     *
     * @param array<string,mixed> $meta
     * @return array{rows:list<array<string,mixed>>,index:list<array<string,mixed>>}
     */
    private function chunkPayload(string $documentId, int $tenantId, UploadedFile $file, ExtractedDocument $extracted, string $text, array $meta): array
    {
        $chunks = array_slice(TokenChunker::chunk($text, $this->chunkTokens, $this->chunkOverlap), 0, $this->maxChunks);
        $headings = self::headings($text);
        $lineStarts = self::lineOffsets($text);

        $rows = [];
        $index = [];
        foreach ($chunks as $chunk) {
            $no = (int) $chunk['index'];
            $content = (string) $chunk['text'];
            $page = self::pageFor($content, $extracted);
            $chunkMeta = array_filter([
                'section' => self::sectionFor($headings, (int) $chunk['start_offset']),
                'line_from' => self::lineFor($lineStarts, (int) $chunk['start_offset']),
                'line_to' => self::lineFor($lineStarts, (int) $chunk['end_offset']),
                'start_offset' => (int) $chunk['start_offset'],
                'end_offset' => (int) $chunk['end_offset'],
                'filename' => $file->name,
                'extractor' => (string) ($extracted->meta['extractor'] ?? ''),
            ], static fn ($value): bool => $value !== null && $value !== '');

            $vector = $this->embed($content);
            $rows[] = [
                'document_id' => $documentId,
                $this->chunkTenantColumn() => $tenantId,
                'chunk_no' => $no,
                'page_from' => $page,
                'page_to' => $page,
                'content' => $content,
                'token_count' => (int) $chunk['token_count'],
                'embedding' => Db::json($vector),
                'metadata_json' => Db::json($chunkMeta),
                'created_at' => Db::now(),
                'updated_at' => Db::now(),
            ];

            if ($vector !== []) {
                $index[] = [
                    'id' => $documentId . ':' . $no,
                    'text' => $content,
                    'embedding' => $vector,
                    'meta' => $chunkMeta + ['document_id' => $documentId, 'chunk_no' => $no, 'page' => $page],
                ];
            }
        }

        return ['rows' => $rows, 'index' => $index];
    }

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    private function documentMeta(array $meta, UploadedFile $file, UserContext $user): array
    {
        return array_filter([
            'title' => isset($meta['title']) ? mb_substr((string) $meta['title'], 0, 255) : self::titleOf($file->name),
            'category' => isset($meta['category']) ? mb_substr((string) $meta['category'], 0, 100) : null,
            'source' => (string) ($meta['source'] ?? 'upload'),
            'uploaded_by' => $user->userId,
            'labels' => (array) ($meta['labels'] ?? []),
            'thread_id' => (string) ($meta['thread_id'] ?? $user->threadId),
            'scope_key' => isset($meta['scope_key']) ? mb_substr((string) $meta['scope_key'], 0, 190) : null,
        ], static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    private static function acl(array $meta, UserContext $user): array
    {
        $roles = array_values(array_unique(array_map('strval', (array) ($meta['roles'] ?? []))));
        $users = array_values(array_unique(array_map('strval', (array) ($meta['user_ids'] ?? []))));
        if ($roles === [] && (string) ($meta['scope'] ?? 'global') === 'role') {
            $roles = array_values(array_map('strval', $user->roles));
        }
        return array_filter(['roles' => $roles, 'users' => $users], static fn (array $v): bool => $v !== []);
    }

    /** Bytes go to the host store; only the key is persisted. */
    private function storeBytes(string $documentId, UploadedFile $file, int $tenantId, array $meta): ?string
    {
        if (isset($meta['storage_key']) && trim((string) $meta['storage_key']) !== '') {
            return mb_substr(trim((string) $meta['storage_key']), 0, 255);
        }
        if ($this->store === null) {
            return $file->storageKey !== '' ? $file->storageKey : null;
        }
        $key = sprintf('documents/%d/%s/%s', $tenantId, $documentId, self::safeName($file->name));
        $stored = $this->store->put($file, $key);
        return mb_substr(trim((string) $stored) !== '' ? (string) $stored : $key, 0, 255);
    }

    /** Read the original upload back through the host store, for reindex(). */
    private function storedFile(array $document): ?UploadedFile
    {
        $key = trim((string) ($document['storage_key'] ?? ''));
        if ($key === '' || $this->store === null) {
            return null;
        }
        $name = (string) ($document['filename'] ?? 'document.bin');
        $mime = (string) ($document['mime'] ?? '');

        try {
            $path = $this->store->localPath($key);
            if ($path !== null && is_readable($path)) {
                return UploadedFile::fromPath($path);
            }
        } catch (\Throwable) {
            $path = null;
        }

        try {
            $bytes = $this->store->read($key);
        } catch (\Throwable) {
            $bytes = null;
        }
        return $bytes === null ? null : UploadedFile::fromRaw($bytes, $name, $mime);
    }

    /** Publish a short, citable summary into {prefix}_knowledge so the knowledge path finds the file too. */
    private function mirror(string $documentId, int $tenantId, UploadedFile $file, ExtractedDocument $extracted, array $meta): void
    {
        if (!($meta['mirror_to_knowledge'] ?? $this->mirrorToKnowledge) || $extracted->isEmpty()) {
            return;
        }
        try {
            $this->knowledge ??= new KnowledgeService($this->connection(), $this->llm);
            $this->knowledge->ingest(
                $tenantId,
                (string) ($meta['title'] ?? self::titleOf($file->name)),
                trim(mb_substr($extracted->text, 0, $this->mirrorChars)),
                'document',
                isset($meta['category']) ? (string) $meta['category'] : null,
                [
                    'scope' => (string) ($meta['scope'] ?? 'global'),
                    'scope_key' => (string) ($meta['scope_key'] ?? ''),
                    'doc_id' => $documentId,
                    'metadata' => ['document_id' => $documentId, 'filename' => $file->name, 'mirrored' => true],
                ],
            );
        } catch (\Throwable) {
            // Documents stay indexed even when the mirror table is missing or refuses.
        }
    }

    // ─── internals: retrieval ─────────────────────────────────────────────────

    /**
     * Chunks of this tenant, already ACL- and filter-scoped and capped by the
     * scan budget, joined with the fields a citation needs.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function candidateRows(int $tenantId, array $filters): array
    {
        $alias = ['c' => $this->chunkTable(), 'd' => $this->documentTable()];
        $conditions = [
            ['c.`' . $this->chunkTenantColumn() . '`', $tenantId],
            ['d.`status`', self::slug((string) ($filters['status'] ?? self::STATUS_INDEXED), self::STATUS_INDEXED)],
        ];

        $documentIds = [];
        if (isset($filters['document_id']) && trim((string) $filters['document_id']) !== '') {
            $documentIds[] = trim((string) $filters['document_id']);
        }
        foreach ((array) ($filters['document_ids'] ?? []) as $id) {
            if (trim((string) $id) !== '') {
                $documentIds[] = trim((string) $id);
            }
        }
        if ($documentIds !== []) {
            $conditions[] = ['c.`document_id`', array_values(array_unique($documentIds))];
        }
        foreach (['entity_type' => 60, 'entity_id' => 60, 'scope' => 20] as $column => $limit) {
            if (isset($filters[$column]) && trim((string) $filters[$column]) !== '') {
                $conditions[] = ['d.`' . $column . '`', mb_substr(trim((string) $filters[$column]), 0, $limit)];
            }
        }
        if (isset($filters['owner_user_id']) && trim((string) $filters['owner_user_id']) !== '') {
            $conditions[] = ['d.`owner_user_id`', trim((string) $filters['owner_user_id'])];
        }

        $where = [];
        $bind = [];
        foreach ($conditions as $index => [$fragment, $value]) {
            [$table, $column] = self::splitFragment($fragment);
            $param = ':f' . $index;
            if (is_array($value)) {
                $params = [];
                foreach (array_values($value) as $i => $item) {
                    $params[] = $param . '_' . $i;
                    $bind[$param . '_' . $i] = $item;
                }
                $where[] = '`' . $table . '`.`' . $column . '` IN (' . implode(',', $params) . ')';
                continue;
            }
            $where[] = '`' . $table . '`.`' . $column . '` = ' . $param;
            $bind[$param] = $value;
        }

        $viewer = $filters['viewer'] ?? null;
        if ($viewer !== null) {
            [$clause, $aclBind] = $this->aclSql($viewer);
            if ($clause !== '') {
                $where[] = '(' . $clause . ')';
                $bind += $aclBind;
            }
        }

        $columns = [
            'c.`id` AS chunk_id', 'c.`document_id`', 'c.`chunk_no`', 'c.`page_from`', 'c.`page_to`',
            'c.`content`', 'c.`token_count`', 'c.`embedding`', 'c.`metadata_json` AS chunk_meta',
            'd.`filename`', 'd.`mime`', 'd.`scope`', 'd.`owner_user_id`', 'd.`entity_type`',
            'd.`entity_id`', 'd.`metadata_json` AS doc_meta', 'd.`acl_json`',
        ];
        foreach ($columns as $i => $column) {
            [$table, $name] = self::splitFragment($column);
            if (!$this->hasColumn($alias[$table], $name)) {
                unset($columns[$i]);
            }
        }

        $sql = 'SELECT ' . implode(', ', $columns)
            . ' FROM `' . $alias['c'] . '` c INNER JOIN `' . $alias['d'] . '` d ON d.`id` = c.`document_id`'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY c.`created_at` DESC, c.`document_id` ASC, c.`chunk_no` ASC'
            . ' LIMIT ' . $this->scanLimit;

        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $index => $row) {
            $docMeta = Db::decode($row['doc_meta'] ?? null);
            $rows[$index]['title'] = (string) ($docMeta['title'] ?? self::titleOf((string) ($row['filename'] ?? '')));
            $rows[$index]['category'] = (string) ($docMeta['category'] ?? '');
        }
        return array_values($rows);
    }

    /**
     * Results from a host vector index that the row scan did not already cover —
     * pgvector/Qdrant answers with its own ranking, so its score is taken as-is.
     *
     * @param array<string,mixed> $filters
     * @return list<SearchHit>
     */
    private function indexHits(int $tenantId, array $queryVector, array $filters): array
    {
        if ($this->index === null || $queryVector === []) {
            return [];
        }
        try {
            $hits = $this->index->query($tenantId, $queryVector, max(1, intdiv($this->scanLimit, 2)), $filters);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($hits as $hit) {
            $id = (string) ($hit['id'] ?? '');
            $meta = (array) ($hit['meta'] ?? []);
            $documentId = (string) ($meta['document_id'] ?? ($hit['document_id'] ?? $id));
            if ($id === '' || (isset($filters['document_id']) && $documentId !== (string) $filters['document_id'])) {
                continue;
            }
            $score = round(max(0.0, min(1.0, (float) ($hit['score'] ?? 0.0))), 6);
            if ($score < $this->minSimilarity) {
                continue;
            }
            $content = (string) ($hit['text'] ?? '');
            $out[] = SearchHit::make([
                'id' => $id,
                'type' => 'document',
                'document_id' => $documentId,
                'chunk_no' => (int) ($meta['chunk_no'] ?? 0),
                'content' => $content,
                'title' => (string) ($meta['title'] ?? $meta['filename'] ?? $documentId),
                'source' => (string) ($meta['filename'] ?? $documentId),
                'score' => $score,
                'vector_score' => $score,
                'lexical_score' => null,
                'page' => isset($meta['page']) && $meta['page'] !== null ? (int) $meta['page'] : null,
                'section' => $meta['section'] ?? null,
                'retrieval' => $this->retrieval,
                'via' => 'chunk_index',
            ], $content);
        }
        return $out;
    }

    /** @param array<string,mixed> $row joined chunk + document row @return SearchHit */
    private function hit(array $row, float $score): SearchHit
    {
        $chunkMeta = Db::decode($row['chunk_meta'] ?? null);
        $documentId = (string) ($row['document_id'] ?? '');
        $filename = (string) ($row['filename'] ?? '');
        $content = (string) ($row['content'] ?? '');
        $title = (string) ($row['title'] ?? self::titleOf($filename));

        return SearchHit::make([
            'id' => $documentId . ':' . (int) ($row['chunk_no'] ?? 0),
            'type' => 'document',
            'document_id' => $documentId,
            'chunk_id' => (string) ($row['chunk_id'] ?? ''),
            'chunk_no' => (int) ($row['chunk_no'] ?? 0),
            'content' => $content,
            'title' => $title,
            'filename' => $filename,
            'source' => $filename !== '' ? $filename : $title,
            'category' => (string) ($row['category'] ?? ''),
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'entity_id' => (string) ($row['entity_id'] ?? ''),
            'score' => $score,
            'vector_score' => $row['vector_score'] ?? null,
            'lexical_score' => $row['lexical_score'] ?? null,
            'page' => isset($row['page_from']) && $row['page_from'] !== null ? (int) $row['page_from'] : null,
            'section' => $chunkMeta['section'] ?? null,
            'line_from' => $chunkMeta['line_from'] ?? null,
            'token_count' => (int) ($row['token_count'] ?? 0),
            'retrieval' => $this->retrieval,
        ], $content);
    }

    /**
     * Visibility of a document to whoever is asking: `global` is open to the
     * tenant, `personal` to its owner, `role` to the roles named in its acl. With
     * no viewer, only global documents are searched.
     *
     * @param UserContext|array<string,mixed> $viewer
     * @return array{0:string,1:array<string,mixed>}
     */
    private function aclSql(UserContext|array $viewer): array
    {
        $user = $viewer instanceof UserContext ? $viewer : UserContext::fromArray($viewer);
        $bind = [':acl_global' => 'global', ':acl_personal' => 'personal', ':acl_user' => $user->userId];
        $clauses = [
            'd.`scope` = :acl_global',
            '(d.`scope` = :acl_personal AND d.`owner_user_id` = :acl_user)',
        ];

        $roles = array_values(array_filter(array_map('strval', $user->roles), static fn (string $r): bool => $r !== ''));
        if ($roles !== []) {
            $likes = [];
            foreach ($roles as $i => $role) {
                $likes[] = 'd.`acl_json` LIKE :acl_role' . $i;
                // The stored role list is JSON text; LIKE is how the package reads
                // it without a database-specific JSON operator.
                $bind[':acl_role' . $i] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $role) . '%';
            }
            $clauses[] = '(d.`scope` = :acl_scope_role AND (' . implode(' OR ', $likes) . '))';
            $bind[':acl_scope_role'] = 'role';
        }

        return [implode(' OR ', $clauses), $bind];
    }

    /** @return array<string,mixed> */
    private function publicRow(array $row): array
    {
        $meta = Db::decode($row['metadata_json'] ?? null);
        return [
            'id' => (string) ($row['id'] ?? ''),
            'company_id' => (int) ($row[$this->tenantColumn()] ?? $row['company_id'] ?? 0),
            'owner_user_id' => (string) ($row['owner_user_id'] ?? ''),
            'scope' => (string) ($row['scope'] ?? 'global'),
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'entity_id' => (string) ($row['entity_id'] ?? ''),
            'filename' => (string) ($row['filename'] ?? ''),
            'title' => (string) ($meta['title'] ?? self::titleOf((string) ($row['filename'] ?? ''))),
            'category' => (string) ($meta['category'] ?? ''),
            'mime' => (string) ($row['mime'] ?? ''),
            'size' => (int) ($row['size'] ?? 0),
            'status' => (string) ($row['status'] ?? self::STATUS_UPLOADED),
            'pages' => (int) ($row['pages'] ?? 0),
            'chunk_count' => (int) ($row['chunk_count'] ?? 0),
            'language' => (string) ($row['language'] ?? ''),
            'storage_key' => (string) ($row['storage_key'] ?? ''),
            'sha256' => (string) ($row['sha256'] ?? ''),
            'indexed_at' => (string) ($row['indexed_at'] ?? ''),
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'error' => (string) ($row['error'] ?? ''),
            'metadata' => $meta,
            'acl' => Db::decode($row['acl_json'] ?? null),
        ];
    }

    // ─── internals: text structure ────────────────────────────────────────────

    /** @return list<array{offset:int,title:string}> markdown headings, in source order */
    private static function headings(string $src): array
    {
        $out = [];
        $offset = 0;
        foreach (explode("\n", $src) as $line) {
            $trimmed = trim((string) $line);
            if (preg_match('/^#{1,6}\s+(.+?)\s*#*$/u', $trimmed, $m) === 1) {
                $out[] = ['offset' => $offset, 'title' => mb_substr(trim($m[1]), 0, 200)];
            }
            $offset += strlen($line) + 1;
        }
        return $out;
    }

    /** @return list<int> byte offset of every line start, for line numbers */
    private static function lineOffsets(string $src): array
    {
        $offsets = [0];
        $offset = 0;
        foreach (explode("\n", $src) as $line) {
            $offset += strlen($line) + 1;
            $offsets[] = $offset;
        }
        return $offsets;
    }

    /** @param list<array{offset:int,title:string}> $headings */
    private static function sectionFor(array $headings, int $start): ?string
    {
        $current = null;
        foreach ($headings as $heading) {
            if ((int) $heading['offset'] > $start) {
                break;
            }
            $current = (string) $heading['title'];
        }
        return $current;
    }

    /** @param list<int> $lineStarts */
    private static function lineFor(array $lineStarts, int $start): int
    {
        $line = 1;
        foreach ($lineStarts as $index => $offset) {
            if ($offset > $start) {
                break;
            }
            $line = $index + 1;
        }
        return $line;
    }

    /**
     * Which page a chunk came from, located through the extractor's page map.
     * Single-page text resolves to page 1; a chunk that straddles a page break
     * reports the page it starts on.
     */
    private static function pageFor(string $chunk, ExtractedDocument $extracted): ?int
    {
        if ($extracted->pageCount > 1 && $extracted->pages !== []) {
            foreach ([mb_substr($chunk, 0, 60), mb_substr($chunk, -60)] as $needle) {
                $needle = trim((string) $needle);
                if ($needle === '') {
                    continue;
                }
                foreach ($extracted->pages as $page) {
                    $text = TokenChunker::normalise((string) ($page['text'] ?? ''));
                    if ($text !== '' && str_contains($text, $needle)) {
                        return (int) ($page['page'] ?? 0) ?: 1;
                    }
                }
            }
            return null;
        }
        return $extracted->pageCount > 0 || $extracted->pages !== [] ? 1 : null;
    }

    // ─── internals: schema plumbing ───────────────────────────────────────────

    private function documentTable(): string
    {
        return Db::requireTable($this->connection(), 'documents');
    }

    private function chunkTable(): string
    {
        return Db::requireTable($this->connection(), 'document_chunks');
    }

    private function connection(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException(
                'No database connection available to the AI assistant. Pass the host PDO to DocumentService, '
                . 'or configure the DSN/DB_* variables in .env.'
            );
        }
        return $this->pdo;
    }

    /** Configured tenant column, or the package default when the table lacks it. */
    private function tenantColumn(): string
    {
        if ($this->tenantColumn !== null) {
            return $this->tenantColumn;
        }
        $configured = Config::string('tenant.column', 'company_id');
        if ($configured === '') {
            return $this->tenantColumn = 'company_id';
        }
        try {
            Db::identifier($configured);
        } catch (\InvalidArgumentException) {
            return $this->tenantColumn = 'company_id';
        }
        return $this->tenantColumn = $configured === 'company_id' || $this->hasColumn($this->documentTable(), $configured)
            ? $configured
            : 'company_id';
    }

    /** The chunk table keeps its own copy of the tenant id for scoped scans. */
    private function chunkTenantColumn(): string
    {
        $column = $this->tenantColumn();
        return $this->hasColumn($this->chunkTable(), $column) ? $column : 'company_id';
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
     * @return string[] only the columns this install actually has, so a host that
     *                 has not run the newest migration still gets its rows
     */
    private function existing(string $table, array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column): bool => $this->hasColumn($table, $column)));
    }

    /** @param array<string,mixed> $where @return array<string,mixed>|null */
    private function rowBy(string $table, array $where): ?array
    {
        $conditions = [];
        $bind = [];
        $i = 0;
        foreach ($where as $column => $value) {
            $conditions[] = '`' . Db::identifier((string) $column) . '` = :v' . $i;
            $bind[':v' . $i++] = $value;
        }
        $stmt = $this->connection()->prepare('SELECT * FROM `' . $table . '` WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1');
        $stmt->execute($bind);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function dropChunks(int $tenantId, string $documentId): void
    {
        $this->connection()
            ->prepare('DELETE FROM `' . $this->chunkTable() . '` WHERE `document_id` = :id AND `' . $this->chunkTenantColumn() . '` = :tenant')
            ->execute([':id' => $documentId, ':tenant' => $tenantId]);
        try {
            $this->index?->deleteDocument($tenantId, $documentId);
        } catch (\Throwable) {
            // Rebuilt by the upsert that follows.
        }
    }

    /** `d.`entity_type`` or `` AS alias` → [table alias, bare column name]. */
    private static function splitFragment(string $fragment): array
    {
        $name = trim((string) preg_replace('/\s+AS\s+\w+$/i', '', $fragment));
        $parts = explode('.', $name, 2);
        $alias = Db::identifier(str_replace('`', '', $parts[0]));
        $column = Db::identifier(str_replace('`', '', $parts[1] ?? $parts[0]));
        return [$alias, $column];
    }

    /** @return list<float> [] = "no embedding", which switches retrieval to lexical scoring */
    private function embed(string $text): array
    {
        if ($this->llm === null || trim($text) === '') {
            return [];
        }
        try {
            $vector = $this->llm->embeddings($text);
        } catch (\Throwable) {
            return [];
        }
        return is_array($vector) ? $vector : [];
    }

    private static function mode(mixed $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'vector', 'semantic', 'embedding' => 'vector',
            'lexical', 'keyword', 'text' => 'lexical',
            default => 'hybrid',
        };
    }

    private static function titleOf(string $filename): string
    {
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        $clean = trim((string) preg_replace('/[_\-]+/', ' ', $base));
        return $clean !== '' ? $clean : $filename;
    }

    private static function safeName(string $name): string
    {
        $clean = trim((string) preg_replace('/[^\w.\-]+/', '_', $name), '_');
        return $clean !== '' ? mb_substr($clean, 0, 120) : 'document';
    }

    private static function slug(string $value, string $fallback): string
    {
        $clean = (string) preg_replace('/[^a-z0-9_]/', '', strtolower(trim($value)));
        return $clean === '' ? $fallback : $clean;
    }
}
