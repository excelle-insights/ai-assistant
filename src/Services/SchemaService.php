<?php

declare(strict_types=1);

namespace ExcelleInsights\AiWhatsapp\Services;

use PDO;
use ExcelleInsights\AiWhatsapp\Support\EnvLoader;

/**
 * Reads the host database schema (tables + columns + comments) so the AI can
 * understand the domain from the actual system it is attached to — reusable
 * across any MySQL database.
 *
 * Schema metadata (structure) is exposed, never row data.
 */
class SchemaService
{
    private string $prefix;

    public function __construct(private PDO $pdo)
    {
        EnvLoader::load();
        $this->prefix = $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? 'ai_whatsapp';
    }

    public function database(): string
    {
        return (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
    }

    public function tables(): array
    {
        $db = $this->database();
        $stmt = $this->pdo->prepare("SELECT TABLE_NAME AS name, TABLE_COMMENT AS comment FROM information_schema.tables WHERE table_schema = :db AND table_type = 'BASE TABLE' ORDER BY TABLE_NAME");
        $stmt->execute(['db' => $db]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function columns(string $table): array
    {
        $db = $this->database();
        $stmt = $this->pdo->prepare("SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, COLUMN_COMMENT AS comment FROM information_schema.columns WHERE table_schema = :db AND table_name = :t ORDER BY ORDINAL_POSITION");
        $stmt->execute(['db' => $db, 't' => $table]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sync the stored schema (ai_whatsapp_schema) with the live DB, preserving
     * any descriptions the user already added. Returns [added, total].
     */
    public function syncSchema(): array
    {
        $table = $this->prefix . '_schema';
        $added = 0;
        $total = 0;
        foreach ($this->tables() as $t) {
            foreach ($this->columns($t['name']) as $c) {
                $total++;
                $desc = ($c['comment'] ?? '') !== '' ? $c['comment'] : $this->autoDescription($t['name'], $c['name']);
                $stmt = $this->pdo->prepare("INSERT INTO {$table} (table_name, column_name, data_type, description, created_at, updated_at) VALUES (:t, :c, :d, :desc, NOW(), NOW()) ON DUPLICATE KEY UPDATE data_type = VALUES(data_type), updated_at = NOW()");
                $stmt->execute(['t' => $t['name'], 'c' => $c['name'], 'd' => substr($c['type'], 0, 250), 'desc' => $desc]);
                if ($stmt->rowCount() === 1) $added++;
            }
        }
        return ['added' => $added, 'total' => $total];
    }

    /** Generate a human-readable description from a column (and its table) name. */
    public function autoDescription(string $table, string $column): string
    {
        $c = strtolower(trim($column));

        $map = [
            'id' => 'Unique identifier',
            'name' => 'Name',
            'description' => 'Description',
            'details' => 'Details',
            'notes' => 'Notes',
            'title' => 'Title',
            'status' => 'Current status',
            'type' => 'Type',
            'is_active' => 'Whether the record is active',
            'active' => 'Whether the record is active',
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'city' => 'City',
            'country' => 'Country',
            'company_id' => 'Owning company reference',
        ];
        if (isset($map[$c])) return $map[$c];

        if ($c === 'created_at' || $c === 'created') return 'When the record was created';
        if ($c === 'updated_at' || $c === 'updated') return 'When the record was last updated';
        if (str_ends_with($c, '_at')) return 'Timestamp for ' . str_replace('_', ' ', substr($c, 0, -3));
        if (str_ends_with($c, '_date') || str_ends_with($c, 'date')) return 'Date of ' . str_replace('_', ' ', preg_replace('/_?date$/', '', $c));
        if (str_contains($c, 'phone') || str_contains($c, 'mobile') || str_contains($c, 'tel')) return 'Phone number';
        if (str_contains($c, 'email')) return 'Email address';
        if (str_contains($c, 'address') || str_contains($c, 'location')) return 'Physical address / location';
        if (str_contains($c, 'price') || str_contains($c, 'amount') || str_contains($c, 'cost') || str_contains($c, 'fee') || str_contains($c, 'rate')) return 'Amount in local currency';
        if (str_contains($c, 'quantity') || str_contains($c, 'qty')) return 'Quantity';
        if (str_contains($c, 'expiry') || str_contains($c, 'expires')) return 'Expiry date';

        if (str_ends_with($c, '_id')) {
            $ref = substr($c, 0, -3);
            if ($ref === 'user') return 'Reference to the user';
            return 'Reference to ' . str_replace('_', ' ', $ref);
        }

        // Generic fallback: humanize the column name.
        return ucfirst(str_replace('_', ' ', $c));
    }

    /** CSV template (table,column,data_type,description) generated from the live DB. */
    public function schemaTemplate(): string
    {
        $lines = ['table,column,data_type,description'];
        $descriptions = $this->descriptionMap();
        foreach ($this->tables() as $t) {
            foreach ($this->columns($t['name']) as $c) {
                $desc = $descriptions[$t['name'] . '.' . $c['name']] ?? $c['comment'] ?? '';
                $lines[] = '"' . str_replace('"', '""', $t['name']) . '","' . $c['name'] . '","' . $c['type'] . '","' . str_replace('"', '""', $desc) . '"';
            }
        }
        return implode("\n", $lines);
    }

    /** Import descriptions from CSV (table,column,data_type,description). */
    public function importDescriptions(string $csv): array
    {
        $table = $this->prefix . '_schema';
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => []];
        $rows = array_map(fn($r) => str_getcsv($r, ',', '"', '\\'), preg_split('/\r\n|\r|\n/', trim($csv)));
        if (empty($rows)) return $result;

        $header = array_map(fn($h) => strtolower(trim($h)), $rows[0]);
        $iTable = array_search('table', $header);
        $iColumn = array_search('column', $header);
        $iDesc = array_search('description', $header);
        if ($iTable === false || $iColumn === false || $iDesc === false) {
            $result['errors'][] = 'CSV must have "table", "column" and "description" columns';
            return $result;
        }

        foreach (array_slice($rows, 1) as $i => $row) {
            $t = trim($row[$iTable] ?? '');
            $c = trim($row[$iColumn] ?? '');
            $d = trim($row[$iDesc] ?? '');
            if ($t === '' || $c === '') { $result['skipped']++; continue; }
            try {
                $stmt = $this->pdo->prepare("UPDATE {$table} SET description = :d, updated_at = NOW() WHERE table_name = :t AND column_name = :c");
                $stmt->execute(['d' => $d, 't' => $t, 'c' => $c]);
                if ($stmt->rowCount() > 0) $result['updated']++;
                else {
                    // Insert if missing
                    $stmt = $this->pdo->prepare("INSERT INTO {$table} (table_name, column_name, data_type, description, created_at, updated_at) VALUES (:t, :c, '', :d, NOW(), NOW()) ON DUPLICATE KEY UPDATE description = :d2, updated_at = NOW()");
                    $stmt->execute(['t' => $t, 'c' => $c, 'd' => $d, 'd2' => $d]);
                    $result['updated']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = 'Row ' . ($i + 2) . ': ' . $e->getMessage();
            }
        }
        return $result;
    }

    public function listSchema(int $limit = 1000): array
    {
        $table = $this->prefix . '_schema';
        try {
            $stmt = $this->pdo->prepare("SELECT id, table_name, column_name, data_type, description FROM {$table} ORDER BY table_name, column_name LIMIT :lim");
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function deleteSchemaEntry(int $id): bool
    {
        $table = $this->prefix . '_schema';
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /** Search schema for entries matching the query. Returns ["table.column: description", ...]. */
    public function searchSchema(string $query, int $k = 6): array
    {
        $table = $this->prefix . '_schema';
        $like = '%' . $query . '%';
        try {
            $stmt = $this->pdo->prepare("SELECT table_name, column_name, description FROM {$table} WHERE (table_name LIKE :q1 OR column_name LIKE :q2 OR description LIKE :q3) ORDER BY (description IS NOT NULL AND description != '') DESC LIMIT :k");
            $stmt->bindValue(':q1', $like);
            $stmt->bindValue(':q2', $like);
            $stmt->bindValue(':q3', $like);
            $stmt->bindValue(':k', $k, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $label = str_replace('_', ' ', $r['table_name'] . ' ' . $r['column_name']);
            $out[] = $label . ($r['description'] ? ': ' . $r['description'] : '');
        }
        return $out;
    }

    /** Compact list of table names (humanized) for domain awareness. */
    public function domainSummary(int $limit = 40): string
    {
        $names = [];
        foreach ($this->tables() as $t) {
            $names[] = str_replace('_', ' ', $t['name']);
            if (count($names) >= $limit) break;
        }
        return implode(', ', $names);
    }

    private function descriptionMap(): array
    {
        $table = $this->prefix . '_schema';
        $map = [];
        try {
            foreach ($this->pdo->query("SELECT table_name, column_name, description FROM {$table}")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['description'])) $map[$r['table_name'] . '.' . $r['column_name']] = $r['description'];
            }
        } catch (\Throwable $e) {}
        return $map;
    }
}
