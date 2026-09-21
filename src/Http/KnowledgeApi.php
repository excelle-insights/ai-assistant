<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Http;

use PDO;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Services\KnowledgeService;
use ExcelleInsights\AiAssistant\Services\SchemaService;
use ExcelleInsights\AiAssistant\Services\TrainingService;
use ExcelleInsights\AiAssistant\Support\EnvLoader;

/**
 * Framework-agnostic training API.
 *
 * No routing, no request/response objects, no auth — plain arrays in/out —
 * so ANY application (Laravel, Slim, raw PHP, …) exposes training endpoints
 * in ~5 lines per route (see docs/03-knowledge-training.md §6). Auth,
 * tenancy and JSON encoding stay in the host.
 *
 * All methods return plain arrays; lists are `['items' => [...]]`,
 * imports are `['inserted' => n, 'skipped' => n, 'errors' => [...]]`.
 */
class KnowledgeApi
{
    private KnowledgeService $knowledge;
    private TrainingService $training;
    private SchemaService $schema;

    public function __construct(
        private PDO $pdo,
        ?LlmClientInterface $llm = null
    ) {
        EnvLoader::load();
        $this->knowledge = new KnowledgeService($this->pdo, $llm);
        $this->training  = new TrainingService($this->pdo, $this->knowledge);
        $this->schema    = new SchemaService($this->pdo);
    }

    // ── Knowledge ──────────────────────────────────────────────────────────

    public function listKnowledge(int $tenantId, int $limit = 500): array
    {
        return ['items' => $this->knowledge->list($tenantId, $limit)];
    }

    public function addKnowledge(int $tenantId, string $title, string $content, ?string $category = null, string $source = 'manual'): array
    {
        return ['id' => $this->training->ingest($tenantId, $title, $content, $source, $category)];
    }

    public function importCsv(int $tenantId, string $csv, string $source = 'csv'): array
    {
        return $this->knowledge->importCsv($tenantId, $csv, $source);
    }

    public function deleteKnowledge(int $id, int $tenantId): array
    {
        return ['deleted' => $this->knowledge->delete($id, $tenantId)];
    }

    public function csvTemplate(): array
    {
        return ['csv' => $this->knowledge->csvTemplate(), 'filename' => 'ai-knowledge-template.csv'];
    }

    // ── Schema learning (descriptions + lookup values) ─────────────────────

    public function syncSchema(int $tenantId): array
    {
        return [
            'schema'    => $this->schema->syncSchema(),
            'reference' => $this->schema->extractReferenceValues($tenantId),
            'counts'    => $this->schema->extractCounts($tenantId),
        ];
    }

    public function listSchema(int $limit = 1000): array
    {
        return ['items' => $this->schema->listSchema($limit)];
    }

    public function schemaTemplate(): array
    {
        return ['csv' => $this->schema->schemaTemplate(), 'filename' => 'ai-schema-template.csv'];
    }

    public function importSchemaDescriptions(string $csv): array
    {
        return $this->schema->importDescriptions($csv);
    }

    public function deleteSchemaEntry(int $id): array
    {
        return ['deleted' => $this->schema->deleteSchemaEntry($id)];
    }
}
