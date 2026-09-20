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
        $words = $this->tokenize($query);
        $rows = [];

        if (!empty($words)) {
            $clauses = [];
            $params = [];
            foreach ($words as $w) {
                $clauses[] = '(title LIKE ? OR content LIKE ? OR category LIKE ?)';
                $params[] = "%{$w}%"; $params[] = "%{$w}%"; $params[] = "%{$w}%";
            }
            $sql = "SELECT category, title, content FROM {$table} WHERE company_id = ? AND status='active' AND (" . implode(' OR ', $clauses) . ") ORDER BY updated_at DESC LIMIT " . (int)$k;
            array_unshift($params, $companyId);
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $rows = [];
            }
        }

        // Fallback to latest active knowledge if no match
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

    private function tokenize(string $query): array
    {
        $stop = ['what', 'are', 'the', 'you', 'your', 'do', 'does', 'did', 'is', 'a', 'an', 'of', 'for', 'i', 'we', 'our', 'can', 'could', 'how', 'to', 'about', 'with', 'this', 'that', 'me', 'my', 'please', 'hello', 'hi', 'and', 'or', 'in', 'on', 'at', 'from', 'there', 'their', 'them', 'us', 'it', 'its', 'offering', 'offer', 'tell', 'give', 'show', 'list', 'available'];
        $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($query));
        $out = [];
        foreach ($words as $w) {
            $w = trim($w);
            if (strlen($w) <= 2 || in_array($w, $stop, true)) continue;
            $out[] = $w;
            if (str_ends_with($w, 'ies') && strlen($w) > 4) $out[] = substr($w, 0, -3) . 'y';
            elseif (str_ends_with($w, 'es') && strlen($w) > 3) $out[] = substr($w, 0, -2);
            elseif (str_ends_with($w, 's') && strlen($w) > 3) $out[] = substr($w, 0, -1);
        }
        return array_values(array_unique($out));
    }
}
