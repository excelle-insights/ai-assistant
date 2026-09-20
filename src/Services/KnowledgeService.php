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
        $table = $this->prefix . '_knowledge';
        $like = '%' . $query . '%';
        $rows = [];

        try {
            $stmt = $this->pdo->prepare("SELECT category, title, content FROM {$table} WHERE company_id = :cid AND status='active' AND (title LIKE :q1 OR content LIKE :q2) ORDER BY updated_at DESC LIMIT :k");
            $stmt->bindValue(':cid', $companyId, PDO::PARAM_INT);
            $stmt->bindValue(':q1', $like);
            $stmt->bindValue(':q2', $like);
            $stmt->bindValue(':k', $k, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        // Fallback to latest active knowledge if no textual match
        if (empty($rows)) {
            try {
                $stmt = $this->pdo->prepare("SELECT category, title, content FROM {$table} WHERE company_id = :cid AND status='active' ORDER BY updated_at DESC LIMIT :k");
                $stmt->bindValue(':cid', $companyId, PDO::PARAM_INT);
                $stmt->bindValue(':k', $k, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $rows = [];
            }
        }

        $chunks = [];
        foreach ($rows as $r) {
            $head = ($r['category'] ? '[' . $r['category'] . '] ' : '') . ($r['title'] ? $r['title'] : '');
            $chunks[] = ($head ? $head . ": " : "") . $r['content'];
        }
        return $chunks;
    }

    public function ingest(int $companyId, string $title, string $content, string $source = 'manual', ?string $category = null): int
    {
        $table = $this->prefix . '_knowledge';
        $embedding = null;
        if ($this->openAI) {
            try { $embedding = json_encode($this->openAI->embeddings($title . "\n" . $content)); } catch (\Throwable $e) { $embedding = null; }
        }
        $stmt = $this->pdo->prepare("INSERT INTO {$table} (company_id, category, title, content, embedding, source, status, created_at, updated_at) VALUES (:cid, :cat, :title, :content, :emb, :src, 'active', NOW(), NOW())");
        $stmt->execute(['cid' => $companyId, 'cat' => $category, 'title' => $title, 'content' => $content, 'emb' => $embedding, 'src' => $source]);
        return (int)$this->pdo->lastInsertId();
    }

    public function list(int $companyId, int $limit = 500): array
    {
        $table = $this->prefix . '_knowledge';
        $stmt = $this->pdo->prepare("SELECT id, company_id, category, title, content, source, status, created_at, updated_at FROM {$table} WHERE company_id = :cid ORDER BY category, updated_at DESC LIMIT :lim");
        $stmt->bindValue(':cid', $companyId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(int $id, int $companyId): bool
    {
        $table = $this->prefix . '_knowledge';
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE id = :id AND company_id = :cid");
        $stmt->execute(['id' => $id, 'cid' => $companyId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Import knowledge from CSV text (header: category,title,content).
     * Returns ['inserted' => int, 'skipped' => int, 'errors' => []].
     */
    public function importCsv(int $companyId, string $csv, string $source = 'csv'): array
    {
        $result = ['inserted' => 0, 'skipped' => 0, 'errors' => []];
        $rows = array_map(fn($r) => str_getcsv($r, ',', '"', '\\'), preg_split('/\r\n|\r|\n/', trim($csv)));
        if (empty($rows)) {
            $result['errors'][] = 'CSV is empty';
            return $result;
        }

        // Normalize header
        $header = array_map(function ($h) { return strtolower(trim($h)); }, $rows[0]);
        $idxCat = array_search('category', $header);
        $idxTitle = array_search('title', $header);
        $idxContent = array_search('content', $header);
        if ($idxTitle === false || $idxContent === false) {
            $result['errors'][] = 'CSV must have "title" and "content" columns (category is optional)';
            return $result;
        }

        foreach (array_slice($rows, 1) as $i => $row) {
            $title = trim($row[$idxTitle] ?? '');
            $content = trim($row[$idxContent] ?? '');
            $category = $idxCat !== false ? trim($row[$idxCat] ?? '') : '';
            if ($title === '' || $content === '') {
                $result['skipped']++;
                continue;
            }
            try {
                $this->ingest($companyId, $title, $content, $source, $category ?: null);
                $result['inserted']++;
            } catch (\Throwable $e) {
                $result['errors'][] = 'Row ' . ($i + 2) . ': ' . $e->getMessage();
            }
        }
        return $result;
    }

    public function csvTemplate(): string
    {
        $lines = [
            'category,title,content',
            '"Working Hours","Opening hours","We are open Monday to Friday 8:00am - 5:00pm and Saturday 8:00am - 12:00pm."',
            '"Company","Our mission","To provide reliable, high-quality services to our customers."',
            '"Company","Our vision","To become the most trusted provider in our industry."',
            '"Company","Core values","Integrity, excellence, customer focus and teamwork."',
            '"Company","About us","A brief introduction to the company and what it does."',
            '"Contact","Location","We are located along XYZ Road, Nairobi, Kenya."',
            '"Contact","Phone number","You can reach us on +254 7XX XXX XXX."',
        ];
        return implode("\n", $lines);
    }
}
