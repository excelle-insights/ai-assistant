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

    /** Tables the AI may run read-only queries against. */
    private const ALLOWED_TABLES = [
        'service_types', 'service_rules', 'asset_types', 'usage_units', 'industries',
        'customer_types', 'inventory_categories', 'expense_categories', 'owner_categories',
        'business_types', 'company_business_types', 'job_services', 'job_consumables', 'job_parts',
        'vehicle_makes', 'vehicle_models', 'vehicle_generations', 'vehicle_engines',
        'work_orders', 'service_records', 'assets', 'users', 'employee_records',
        'branches', 'departments', 'hr_departments', 'hr_job_titles', 'suppliers',
        'inventory_items', 'job_drafts', 'job_update_log', 'hr_leave_types',
    ];

    /** Columns the AI is never allowed to select (PII / secrets). */
    private const BLOCKED_COLUMNS = [
        'password', 'password_hash', 'token', 'secret', 'api_key', 'ciphertext', 'encrypted',
        'national_id', 'id_number', 'passport', 'kra_pin', 'credit_card', 'bank_account',
        'otp', 'verification_code', 'phone', 'email', 'phone_hash', 'access_token', 'refresh_token',
    ];

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
        $words = $this->tokenize($query);
        if (empty($words)) return [];

        $table = $this->prefix . '_schema';
        $clauses = [];
        $params = [];
        foreach ($words as $w) {
            $clauses[] = '(table_name LIKE ? OR column_name LIKE ? OR description LIKE ?)';
            $params[] = "%{$w}%"; $params[] = "%{$w}%"; $params[] = "%{$w}%";
        }
        $sql = "SELECT table_name, column_name, description FROM {$table} WHERE " . implode(' OR ', $clauses) . ' LIMIT 200';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        $scored = [];
        foreach ($rows as $r) {
            $score = 0;
            foreach ($words as $w) {
                if (str_contains(strtolower($r['table_name']), $w)) $score += 3;
                if (str_contains(strtolower($r['column_name']), $w)) $score += 1;
                if (str_contains(strtolower((string)$r['description']), $w)) $score += 2;
            }
            if ($score > 0) $scored[] = ['s' => $score, 't' => $r['table_name'], 'c' => $r['column_name'], 'd' => $r['description']];
        }
        usort($scored, fn($a, $b) => $b['s'] <=> $a['s']);
        $scored = array_slice($scored, 0, $k);

        $out = [];
        foreach ($scored as $r) {
            $label = str_replace('_', ' ', $r['t'] . ' ' . $r['c']);
            $out[] = $label . ($r['d'] ? ': ' . $r['d'] : '');
        }
        return $out;
    }

    /**
     * Extract actual values from small "lookup" tables (service_types, asset_types, ...)
     * so the AI can quote real data, not just column names. Stores one summary row per table.
     */
    public function extractReferenceValues(int $companyId): array
    {
        $table = $this->prefix . '_reference';
        $suffixes = ['_types', '_categories', '_statuses', '_units', '_levels', '_roles', '_currencies', '_priorities', '_conditions', '_frequencies', '_methods', '_departments', '_positions', '_industries', '_sources', '_groups', '_classes', '_grades'];
        $results = [];
        foreach ($this->tables() as $t) {
            $name = $t['name'];
            $isLookup = false;
            foreach ($suffixes as $s) {
                if (str_ends_with($name, $s)) { $isLookup = true; break; }
            }
            if (!$isLookup) continue;

            $cols = $this->columns($name);
            $hasName = false;
            $hasCompany = false;
            foreach ($cols as $c) {
                if (in_array($c['name'], ['name', 'title', 'label'], true)) $hasName = true;
                if ($c['name'] === 'company_id') $hasCompany = true;
            }
            if (!$hasName) continue;

            $values = $this->extractLookupValues($name, $companyId, $hasCompany);
            if (empty($values)) continue;

            $label = $this->lookupLabel($name);
            $summary = implode(', ', $values);
            $this->pdo->prepare("INSERT INTO {$table} (company_id, source_table, label, summary, created_at, updated_at) VALUES (:cid, :t, :l, :s, NOW(), NOW()) ON DUPLICATE KEY UPDATE label = VALUES(label), summary = VALUES(summary), updated_at = NOW()")
                ->execute(['cid' => $companyId, 't' => $name, 'l' => $label, 's' => $summary]);
            $results[] = ['table' => $name, 'label' => $label, 'count' => count($values)];
        }
        return $results;
    }

    public function searchReference(int $companyId, string $query, int $k = 5): array
    {
        $words = $this->tokenize($query);
        if (empty($words)) return [];
        $table = $this->prefix . '_reference';
        $clauses = [];
        $params = [];
        foreach ($words as $w) {
            $clauses[] = '(source_table LIKE ? OR label LIKE ? OR summary LIKE ?)';
            $params[] = "%{$w}%"; $params[] = "%{$w}%"; $params[] = "%{$w}%";
        }
        $sql = "SELECT label, summary FROM {$table} WHERE company_id = ? AND (" . implode(' OR ', $clauses) . ') LIMIT ' . (int)$k;
        array_unshift($params, $companyId);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) $out[] = ($r['label'] ? $r['label'] . ': ' : '') . $r['summary'];
        return $out;
    }

    public function listReference(int $companyId): array
    {
        $table = $this->prefix . '_reference';
        try {
            $stmt = $this->pdo->prepare("SELECT id, company_id, source_table, label, summary FROM {$table} WHERE company_id = :cid ORDER BY label");
            $stmt->execute(['cid' => $companyId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function extractLookupValues(string $table, int $companyId, bool $hasCompany): array
    {
        $nameCol = null;
        foreach (['name', 'title', 'label'] as $c) {
            foreach ($this->columns($table) as $col) {
                if ($col['name'] === $c) { $nameCol = $c; break 2; }
            }
        }
        if (!$nameCol) return [];

        $priceCol = null;
        foreach ($this->columns($table) as $col) {
            if (in_array(strtolower($col['name']), ['default_amount', 'price', 'amount', 'cost', 'rate', 'unit_price', 'selling_price'], true)) {
                $priceCol = $col['name'];
                break;
            }
        }

        $conds = [];
        if ($hasCompany) $conds[] = '(company_id = ' . (int)$companyId . ' OR company_id IS NULL)';
        $conds[] = "(`{$nameCol}` IS NOT NULL AND `{$nameCol}` != '')";
        $select = "`{$nameCol}` AS v";
        if ($priceCol) $select .= ", `{$priceCol}` AS p";
        $sql = "SELECT {$select} FROM `{$table}` WHERE " . implode(' AND ', $conds) . " ORDER BY `{$nameCol}` ASC LIMIT 50";
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $name = (string)$r['v'];
            if ($priceCol && isset($r['p']) && $r['p'] !== null && $r['p'] !== '') {
                $out[] = $name . ' (KSh ' . $r['p'] . ')';
            } else {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** Store common counts (employees, mechanics, vehicles, work orders, services). */
    public function extractCounts(int $companyId): array
    {
        $table = $this->prefix . '_reference';
        $counts = [
            ['source' => 'employees', 'label' => 'Employees', 'sql' => "SELECT COUNT(*) FROM employee_records WHERE is_active = 1"],
            ['source' => 'mechanics', 'label' => 'Mechanics', 'sql' => "SELECT COUNT(*) FROM employee_records WHERE job_title LIKE '%mechanic%' AND is_active = 1"],
            ['source' => 'work_orders', 'label' => 'Work orders', 'sql' => "SELECT COUNT(*) FROM work_orders"],
            ['source' => 'assets', 'label' => 'Vehicles', 'sql' => "SELECT COUNT(*) FROM assets WHERE is_active = 1"],
            ['source' => 'service_types', 'label' => 'Services', 'sql' => "SELECT COUNT(*) FROM service_types WHERE is_active = 1"],
        ];
        $results = [];
        foreach ($counts as $c) {
            try {
                $n = (int)$this->pdo->query($c['sql'])->fetchColumn();
            } catch (\Throwable $e) {
                continue;
            }
            $this->pdo->prepare("INSERT INTO {$table} (company_id, source_table, label, summary, created_at, updated_at) VALUES (:cid, :t, :l, :s, NOW(), NOW()) ON DUPLICATE KEY UPDATE label = VALUES(label), summary = VALUES(summary), updated_at = NOW()")
                ->execute(['cid' => $companyId, 't' => $c['source'], 'l' => $c['label'], 's' => (string)$n]);
            $results[] = ['label' => $c['label'], 'count' => $n];
        }
        return $results;
    }

    private function lookupLabel(string $table): string
    {
        $t = $table;
        $suffixes = ['_types', '_categories', '_statuses', '_units', '_levels', '_roles', '_currencies', '_priorities', '_conditions', '_frequencies', '_methods', '_departments', '_positions', '_industries', '_sources', '_groups', '_classes', '_grades'];
        foreach ($suffixes as $s) {
            if (str_ends_with($t, $s)) { $t = substr($t, 0, -strlen($s)); break; }
        }
        return ucfirst(str_replace('_', ' ', $t));
    }

    /** Compact schema (allowed tables + safe columns) for SQL generation. */
    public function sqlSchemaContext(): string
    {
        $out = [];
        foreach ($this->tables() as $t) {
            if (!in_array(strtolower($t['name']), self::ALLOWED_TABLES, true)) continue;
            $cols = [];
            foreach ($this->columns($t['name']) as $c) {
                if (in_array(strtolower($c['name']), self::BLOCKED_COLUMNS, true)) continue;
                $desc = $this->autoDescription($t['name'], $c['name']);
                $cols[] = $c['name'] . ($desc !== '' && $desc !== ucfirst(str_replace('_', ' ', $c['name'])) ? '=' . $desc : '');
            }
            $out[] = $t['name'] . ': ' . implode(', ', $cols);
        }
        return implode("\n", $out);
    }

    /** Validate a generated query is safe. Returns an error string, or null if OK. */
    public function validateSql(string $sql): ?string
    {
        $sql = rtrim(trim($sql), ";\n\r ");
        if ($sql === '') return 'Empty query';
        if (!preg_match('/^SELECT\b/i', $sql)) return 'Only SELECT queries are allowed';
        if (str_contains($sql, ';')) return 'Multiple statements not allowed';

        $forbidden = ['DROP','DELETE','UPDATE','INSERT','ALTER','CREATE','TRUNCATE','GRANT','REVOKE','LOAD','INTO OUTFILE','INTO DUMPFILE','SLEEP','BENCHMARK','RENAME','REPLACE','HANDLER','LOCK','UNLOCK','CALL','EXECUTE','PREPARE','SET'];
        foreach ($forbidden as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $sql)) return "Forbidden keyword: $kw";
        }

        foreach ($this->tablesUsedInSql($sql) as $t) {
            if (!in_array(strtolower($t), self::ALLOWED_TABLES, true)) return "Table '$t' is not allowed";
        }

        foreach (self::BLOCKED_COLUMNS as $c) {
            if (preg_match('/\b' . preg_quote($c, '/') . '\b/i', $sql)) return "Column '$c' is not allowed";
        }

        return null;
    }

    public function runReadOnly(string $sql): array
    {
        $sql = rtrim(trim($sql), ";\n\r ");
        if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) $sql .= ' LIMIT 20';
        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Defence in depth: strip any sensitive columns from the result.
        foreach ($rows as &$row) {
            foreach (self::BLOCKED_COLUMNS as $c) {
                unset($row[$c]);
            }
        }
        unset($row);
        return $rows;
    }

    private function tablesUsedInSql(string $sql): array
    {
        $tables = [];
        if (preg_match_all('/\b(?:FROM|JOIN)\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            foreach ($m[1] as $t) $tables[] = $t;
        }
        return $tables;
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
