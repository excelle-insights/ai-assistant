<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use PDO;

class TrainingService
{
    public function __construct(private PDO $pdo, private KnowledgeService $knowledge) {}

    public function ingest(int $companyId, string $title, string $content, string $source = 'manual', ?string $category = null): int
    {
        return $this->knowledge->ingest($companyId, $title, $content, $source, $category);
    }

    public function importFile(int $companyId, string $filePath, string $source = 'file'): int
    {
        $content = file_get_contents($filePath) ?: '';
        // Naive chunk on double newline; for PDF/DOCX host should parse before call
        $chunks = preg_split('/\n\s*\n/', $content);
        $ids = 0;
        foreach (array_slice($chunks, 0, 20) as $i => $chunk) {
            $chunk = trim($chunk);
            if (strlen($chunk) < 20) continue;
            $this->knowledge->ingest($companyId, basename($filePath) . " #$i", $chunk, $source);
            $ids++;
        }
        return $ids;
    }
}
