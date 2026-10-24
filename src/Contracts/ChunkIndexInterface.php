<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

/**
 * Vector storage for document/knowledge chunks.
 *
 * Default implementation keeps embeddings as JSON in {prefix}_document_chunks
 * and ranks in PHP, which needs nothing from the host. A host with pgvector,
 * Qdrant or a managed index registers its own implementation instead, as
 * `services['chunk_index']` on AssistantApi.
 */
interface ChunkIndexInterface
{
    /** @param array<int,array{id:string,text:string,embedding:array<float>,meta:array}> $chunks */
    public function upsert(int $tenantId, array $chunks): void;

    public function deleteDocument(int $tenantId, string $documentId): void;

    /**
     * @return array<int,array{id:string,text:string,score:float,meta:array,document_id?:string}>
     */
    public function query(int $tenantId, array $embedding, int $k = 6, array $filters = []): array;
}
