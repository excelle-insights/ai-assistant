<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * A stored document's metadata row, as returned to host UIs and APIs.
 * Status lifecycle: uploaded → extracting → indexed → failed → deleted.
 */
final readonly class DocumentRef
{
    public function __construct(
        public string $id,
        public int $tenantId,
        public string $filename,
        public string $mime,
        public int $size,
        public string $status,
        public ?string $ownerUserId = null,
        public ?string $scope = null,
        public ?string $entityType = null,
        public ?string $entityId = null,
        public int $pages = 0,
        public int $chunks = 0,
        public ?string $language = null,
        public ?\DateTimeInterface $indexedAt = null,
        public array $meta = [],
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: (string) ($row['id'] ?? ''),
            tenantId: (int) ($row['company_id'] ?? 0),
            filename: (string) ($row['filename'] ?? ''),
            mime: (string) ($row['mime'] ?? ''),
            size: (int) ($row['size'] ?? 0),
            status: (string) ($row['status'] ?? 'uploaded'),
            ownerUserId: isset($row['owner_user_id']) ? (string) $row['owner_user_id'] : null,
            scope: $row['scope'] ?? null,
            entityType: $row['entity_type'] ?? null,
            entityId: $row['entity_id'] ?? null,
            pages: (int) ($row['pages'] ?? 0),
            chunks: (int) ($row['chunk_count'] ?? 0),
            language: $row['language'] ?? null,
            indexedAt: !empty($row['indexed_at']) ? new \DateTimeImmutable((string) $row['indexed_at']) : null,
            meta: is_string($row['metadata_json'] ?? null) ? (array) (json_decode((string) $row['metadata_json'], true) ?: []) : (array) ($row['metadata_json'] ?? []),
        );
    }
}
