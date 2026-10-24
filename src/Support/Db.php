<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

use PDO;

/**
 * Small SQL helpers shared by the package's services: own-table naming, uuids,
 * timestamps, JSON columns, upserts, and a clear error when a host has installed
 * the package but not yet run its migrations.
 *
 * Every method that builds SQL quotes identifiers only from a whitelist
 * ([A-Za-z0-9_]+) and binds every value — no string interpolation of user data.
 */
final class Db
{
    public static function table(string $suffix): string
    {
        return TablePrefix::get() . '_' . self::identifier($suffix);
    }

    /** Fail early with the actual fix instead of a silent empty result set. */
    public static function requireTable(PDO $pdo, string $suffix): string
    {
        $table = self::table($suffix);
        self::identifier($table);
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM `' . $table . '` LIMIT 0');
            $stmt->execute();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'AI assistant table "' . $table . '" is missing. Run the package migrations against this database: '
                . 'php vendor/excelle-insights/ai-assistant/migrate.php '
                . '(or point your own phinx at that package\'s phinx.php with -c)',
                0,
                $e
            );
        }
        return $table;
    }

    public static function tableExists(PDO $pdo, string $suffix): bool
    {
        try {
            $pdo->prepare('SELECT 1 FROM `' . self::table($suffix) . '` LIMIT 0')->execute();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** RFC 4122 v4 uuid, no dependency. */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function json(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? null : $encoded;
    }

    public static function decode(?string $value, array $default = []): array
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * Insert a row, refresh it when one already exists. $insert is column => value;
     * $update is the column list to refresh on conflict (default: all non-key columns).
     *
     * MySQL's `ON DUPLICATE KEY UPDATE` and SQLite's `ON CONFLICT DO UPDATE` are the
     * same intent written two ways; the host's driver decides which one is emitted,
     * so the package's own tables behave identically in a MySQL install and in a
     * SQLite test.
     *
     * @param array<string,mixed> $insert
     * @param string[] $update
     */
    public static function upsert(PDO $pdo, string $table, array $insert, array $update = [], array $skipOnUpdate = ['created_at']): int
    {
        $table = self::identifier($table);
        $columns = array_keys($insert);
        foreach ($columns as $column) {
            self::identifier((string) $column);
        }
        if ($columns === []) {
            throw new \InvalidArgumentException(
                'Nothing to write to "' . $table . '": every column was dropped, which usually means that table '
                . 'has no columns this code expected — run the package migrations.'
            );
        }

        $placeholders = [];
        $bind = [];
        foreach ($insert as $column => $value) {
            $param = ':' . $column;
            $placeholders[] = $param;
            $bind[$param] = $value;
        }

        $updateColumns = $update !== [] ? $update : array_values(array_diff($columns, $skipOnUpdate));
        $clean = [];
        foreach ($updateColumns as $column) {
            $column = self::identifier((string) $column);
            if (!in_array($column, $columns, true)) {
                throw new \InvalidArgumentException("Cannot update '{$column}' — it is not part of the insert payload.");
            }
            $clean[] = $column;
        }
        if ($clean === []) {
            $clean[] = self::identifier((string) $columns[0]);
        }

        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', $placeholders) . ')'
            . self::conflictUpdate($pdo, $clean);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        return (int) $pdo->lastInsertId();
    }

    /** The "…and refresh these columns" tail, in the dialect the connection speaks. */
    private static function conflictUpdate(PDO $pdo, array $columns): string
    {
        if (self::driver($pdo) === 'sqlite') {
            return ' ON CONFLICT DO UPDATE SET ' . implode(', ', array_map(
                static fn (string $c): string => "`{$c}` = excluded.`{$c}`",
                $columns
            ));
        }

        return ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
            static fn (string $c): string => "`{$c}` = VALUES(`{$c}`)",
            $columns
        ));
    }

    /**
     * Add to numeric columns when the row exists, insert it when it does not — the
     * counter shape usage rollups and rate limits need. `$increment` columns are
     * summed; the rest are set from the payload.
     *
     * @param array<string,mixed> $insert
     * @param string[] $increment
     */
    public static function upsertIncrements(PDO $pdo, string $table, array $insert, array $increment, array $set = []): void
    {
        $table = self::identifier($table);
        $columns = array_keys($insert);
        foreach ($columns as $column) {
            self::identifier((string) $column);
        }
        $sqlite = self::driver($pdo) === 'sqlite';
        $bind = [];
        foreach ($insert as $column => $value) {
            $bind[':' . $column] = $value;
        }

        $assignments = [];
        foreach ($increment as $column) {
            $column = self::identifier((string) $column);
            $assignments[] = $sqlite
                ? "`{$column}` = `{$column}` + excluded.`{$column}`"
                : "`{$column}` = `{$column}` + VALUES(`{$column}`)";
        }
        foreach ($set as $column) {
            $column = self::identifier((string) $column);
            $assignments[] = $sqlite ? "`{$column}` = excluded.`{$column}`" : "`{$column}` = VALUES(`{$column}`)";
        }
        if ($assignments === []) {
            return;
        }

        $names = array_map(static fn ($column): string => (string) $column, $columns);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $names) . '`) VALUES ('
            . implode(',', array_map(static fn (string $c): string => ':' . $c, $names)) . ')'
            . ($sqlite ? ' ON CONFLICT DO UPDATE SET ' : ' ON DUPLICATE KEY UPDATE ') . implode(', ', $assignments);

        $pdo->prepare($sql)->execute($bind);
    }

    /** Which dialect this connection speaks. */
    public static function driver(PDO $pdo): string
    {
        try {
            return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable) {
            return 'mysql';
        }
    }

    /** Plain INSERT returning the new id (0 for a keyless table). */
    public static function insert(PDO $pdo, string $table, array $values): int
    {
        $table = self::identifier($table);
        $columns = array_keys($values);
        foreach ($columns as $column) {
            self::identifier($column);
        }
        $placeholders = [];
        $bind = [];
        foreach ($values as $column => $value) {
            $param = ':' . $column;
            $placeholders[] = $param;
            $bind[$param] = $value;
        }
        $stmt = $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', $placeholders) . ')');
        $stmt->execute($bind);
        return (int) $pdo->lastInsertId();
    }

    /** UPDATE ... WHERE col = ? AND col2 = ? ; returns affected rows. */
    public static function updateWhere(PDO $pdo, string $table, array $values, array $where, string $updatedColumn = 'updated_at'): int
    {
        $table = self::identifier($table);
        $set = [];
        $bind = [];
        foreach ($values as $column => $value) {
            $column = self::identifier($column);
            $set[] = "`{$column}` = :s_{$column}";
            $bind[':s_' . $column] = $value;
        }
        if ($updatedColumn !== '' && in_array($updatedColumn, self::columnsOf($pdo, $table), true)) {
            $set[] = '`' . $updatedColumn . '` = :__now';
            $bind[':__now'] = self::now();
        }
        $conditions = [];
        $i = 0;
        foreach ($where as $column => $value) {
            $column = self::identifier($column);
            $param = ':w_' . $i++;
            $conditions[] = "`{$column}` = {$param}";
            $bind[$param] = $value;
        }
        if ($set === [] || $conditions === []) {
            return 0;
        }
        $stmt = $pdo->prepare('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $conditions));
        $stmt->execute($bind);
        return $stmt->rowCount();
    }

    /**
     * SELECT rows with equality filters, optional LIKE filters, ordering and limit.
     * Keeps the tenancy scoping honest: callers always pass company_id in $where.
     *
     * @param array<string,mixed> $where        column => value (equality)
     * @param array<string,string> $like        column => bare term (no wildcards)
     * @param array<int,array{0:string,1:string}> $orderBy [column, 'asc'|'desc']
     * @return array<int,array<string,mixed>>
     */
    public static function select(
        PDO $pdo,
        string $table,
        array $where = [],
        array $like = [],
        array $orderBy = [],
        int $limit = 100,
        string $offset = '',
        ?array $columns = null,
    ): array {
        $table = self::identifier($table);
        $select = $columns === null ? '*' : '`' . implode('`,`', array_map(self::identifier(...), $columns)) . '`';
        $conditions = [];
        $bind = [];
        $i = 0;
        foreach ($where as $column => $value) {
            $column = self::identifier($column);
            if ($value === null) {
                $conditions[] = "`{$column}` IS NULL";
                continue;
            }
            if (is_array($value)) {
                $params = [];
                foreach (array_values($value) as $item) {
                    $param = ':w_' . $i++;
                    $params[] = $param;
                    $bind[$param] = $item;
                }
                $conditions[] = "`{$column}` IN (" . implode(',', $params) . ')';
                continue;
            }
            $param = ':w_' . $i++;
            $conditions[] = "`{$column}` = {$param}";
            $bind[$param] = $value;
        }
        foreach ($like as $column => $term) {
            $column = self::identifier($column);
            $param = ':l_' . $i++;
            $conditions[] = "`{$column}` LIKE {$param}";
            $bind[$param] = '%' . self::escapeLike((string) $term) . '%';
        }
        $sql = 'SELECT ' . $select . ' FROM `' . $table . '`';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $order = [];
        foreach ($orderBy as [$column, $direction]) {
            $column = self::identifier((string) $column);
            $order[] = "`{$column}` " . (strtolower((string) $direction) === 'desc' ? 'DESC' : 'ASC');
        }
        if ($order !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $order);
        }
        $sql .= ' LIMIT ' . max(1, (int) $limit);
        if ($offset !== '' && preg_match('/^\d+$/', $offset)) {
            $sql .= ' OFFSET ' . $offset;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return string[] column names of a table (cached per request) */
    public static function columnsOf(PDO $pdo, string $table): array
    {
        static $cache = [];
        $table = self::identifier($table);
        $key = spl_object_id($pdo) . ':' . $table;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $names = [];
        try {
            if (self::driver($pdo) === 'sqlite') {
                // SQLite has no information_schema; PRAGMA is its own metadata read.
                foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $names[] = strtolower((string) ($row['name'] ?? ''));
                }
            } else {
                $schema = self::driver($pdo) === 'pgsql' ? 'current_schema()' : 'DATABASE()';
                $rows = $pdo->query(
                    'SELECT `table_name`, `column_name` FROM information_schema.`columns`'
                    . " WHERE `table_schema` = {$schema} AND `table_name` = " . $pdo->quote($table)
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $names[] = strtolower((string) ($row['column_name'] ?? ''));
                }
            }
        } catch (\Throwable) {
            return $cache[$key] = [];
        }

        return $cache[$key] = array_values(array_filter($names, static fn (string $n): bool => $n !== ''));
    }

    /** Refuse anything that is not a bare identifier — the only safe way into SQL. */
    public static function identifier(string $name): string
    {
        $clean = trim($name);
        if ($clean === '' || !preg_match('/^[A-Za-z0-9_]+$/', $clean)) {
            throw new \InvalidArgumentException('Unsafe SQL identifier: "' . $name . '"');
        }
        return $clean;
    }

    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /** Approximate token count — good enough to size prompts and chunk budgets. */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(str_word_count($text) / 0.75);
    }
}
