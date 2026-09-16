<?php

declare(strict_types=1);

namespace ExcelleInsights\AiWhatsapp\Contracts;

interface KnowledgeRetrieverInterface
{
    public function search(int $companyId, string $query, int $k = 5): array;
    public function ingest(int $companyId, string $title, string $content, string $source = 'manual'): int;
}
