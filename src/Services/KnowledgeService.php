<?php

declare(strict_types=1);

namespace ExcelleInsights\AiWhatsapp\Services;

use PDO;
use ExcelleInsights\AiWhatsapp\Client\OpenAIClient;
use ExcelleInsights\AiWhatsapp\Support\EnvLoader;

class KnowledgeService
{
    private string $prefix;

    public function __construct(private PDO $pdo, private ?OpenAIClient $openAI = null)
    {
        EnvLoader::load();
        $this->prefix = $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? 'ai_whatsapp';
        if (!$openAI) {
            try { $this->openAI = new OpenAIClient(); } catch (\Throwable $e) { $this->openAI = null; }
        }
    }

    public function search(int $companyId, string $query, int $k = 5): array
    {
        // Simple FTS fallback — no vector required. If embeddings exist, order by LIKE relevance.
        $table = $this->prefix . '_knowledge';
        $stmt = $this->pdo->prepare("SELECT title, content FROM {$table} WHERE company_id = :cid AND status='active' AND (title LIKE :q OR content LIKE :q) ORDER BY updated_at DESC LIMIT :k");
        $like = '%' . $query . '%';
        $stmt->bindValue(':cid', $companyId, \PDO::PARAM_INT);
        $stmt->bindValue(':q', $like);
        $stmt->bindValue(':k', $k, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        // Fallback to latest knowledge if no match
        if (empty($rows)) {
            $stmt = $this->pdo->prepare("SELECT title, content FROM {$table} WHERE company_id = :cid AND status='active' ORDER BY updated_at DESC LIMIT :k");
            $stmt->bindValue(':cid', $companyId, \PDO::PARAM_INT);
            $stmt->bindValue(':k', $k, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        $chunks = [];
        foreach ($rows as $r) $chunks[] = ($r['title'] ? $r['title'] . ": " : "") . $r['content'];
        return $chunks;
    }

    public function ingest(int $companyId, string $title, string $content, string $source = 'manual'): int
    {
        $table = $this->prefix . '_knowledge';
        $embedding = null;
        if ($this->openAI) {
            try { $embedding = json_encode($this->openAI->embeddings($title . "\n" . $content)); } catch (\Throwable $e) { $embedding = null; }
        }
        $stmt = $this->pdo->prepare("INSERT INTO {$table} (company_id, title, content, embedding, source, status, created_at, updated_at) VALUES (:cid, :title, :content, :emb, :src, 'active', NOW(), NOW())");
        $stmt->execute(['cid' => $companyId, 'title' => $title, 'content' => $content, 'emb' => $embedding, 'src' => $source]);
        return (int)$this->pdo->lastInsertId();
    }
}
