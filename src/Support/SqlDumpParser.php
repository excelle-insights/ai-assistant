<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * Parses MySQL `CREATE TABLE` statements out of a SQL dump so a host can teach
 * the assistant a database it has never connected to — upload the .sql file the
 * app already exports, and the catalog (tables, columns, types, comments,
 * relations) is filled from it.
 *
 * Pure static functions: no PDO, no filesystem, no configuration. Everything is
 * quote-aware, so a comment containing ')' or ';' cannot break the parse.
 */
final class SqlDumpParser
{
    /** @return array{tables:array<int,array<string,mixed>>, statements:int, skipped:int} */
    public static function parse(string $sql): array
    {
        $clean = self::stripComments($sql);
        $tables = [];
        $skipped = 0;
        foreach (self::splitStatements($clean) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            if (!preg_match('/^\s*CREATE\s+(?:TEMPORARY\s+|OR\s+REPLACE\s+)?TABLE\b/i', $statement)) {
                $skipped++;
                continue;
            }
            $table = self::parseCreateTable($statement);
            if ($table === null) {
                $skipped++;
                continue;
            }
            $tables[] = $table;
        }

        return ['tables' => $tables, 'statements' => substr_count($clean, ';'), 'skipped' => $skipped];
    }

    /** Strip line comments (double-dash, hash) and C-style block comments, leaving string literals intact. */
    public static function stripComments(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $i = 0;
        $quote = null;
        while ($i < $len) {
            $ch = $sql[$i];
            if ($quote !== null) {
                $out .= $ch;
                if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $out .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($ch === $quote) {
                    // Doubled quote inside a literal is an escaped quote.
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $out .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $quote = null;
                }
                $i++;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $out .= $ch;
                $i++;
                continue;
            }
            if ($ch === '#' || ($ch === '-' && ($sql[$i + 1] ?? '') === '-' && ($sql[$i + 2] ?? ' ') !== '' && preg_match('/^[\s-]/', $sql[$i + 2] ?? ' '))) {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                continue;
            }
            if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                $out .= ' ';
                continue;
            }
            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /** @return string[] */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($quote !== null) {
                $buffer .= $ch;
                if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buffer .= $sql[++$i];
                    continue;
                }
                if ($ch === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $buffer .= $sql[++$i];
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ';') {
                $statements[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }

    /**
     * One `CREATE TABLE …` statement → ['name','comment','engine','columns','foreign_keys'].
     *
     * @return array<string,mixed>|null
     */
    public static function parseCreateTable(string $statement): ?array
    {
        if (!preg_match('/^\s*CREATE\s+(?:TEMPORARY\s+|OR\s+REPLACE\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([`"\[\]]?[\w.$]+[`"\]]?)\s*\(/i', $statement, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $name = self::identifier($m[1][0]);
        $openPos = (int) strpos($statement, '(', (int) $m[0][1] + strlen($m[0][0]) - 1);
        if ($openPos === false) {
            $openPos = (int) strpos($statement, '(');
        }
        $closePos = self::matchingParen($statement, $openPos);
        if ($closePos === null) {
            return null;
        }
        $body = substr($statement, $openPos + 1, $closePos - $openPos - 1);
        $options = substr($statement, $closePos + 1);

        $columns = [];
        $foreignKeys = [];
        $primaryKeys = [];
        foreach (self::splitTopLevel($body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^FOREIGN\s+KEY\b/i', $line) || preg_match('/^CONSTRAINT\b.*FOREIGN\s+KEY/i', $line)) {
                $fk = self::parseForeignKey($line);
                if ($fk !== null) {
                    $foreignKeys[] = $fk;
                }
                continue;
            }
            if (preg_match('/^\s*PRIMARY\s+KEY\s*\((.*)\)/is', $line, $pk)) {
                foreach (self::splitTopLevel($pk[1]) as $member) {
                    $id = self::identifier((string) preg_replace('/\s*\(.*$/', '', $member));
                    if ($id !== '') {
                        $primaryKeys[strtolower($id)] = true;
                    }
                }
                continue;
            }
            if (preg_match('/^(UNIQUE|KEY|INDEX|FULLTEXT|SPATIAL|CHECK|CONSTRAINT|PERIOD)\b/i', $line)) {
                continue;
            }
            $column = self::parseColumnDefinition($line);
            if ($column !== null) {
                $columns[] = $column;
            }
        }
        foreach ($columns as $i => $column) {
            if (isset($primaryKeys[strtolower($column['name'])])) {
                $columns[$i]['primary'] = true;
            }
        }

        return [
            'name' => $name,
            'comment' => self::tableComment($options),
            'engine' => preg_match('/ENGINE\s*=\s*([A-Za-z0-9_]+)/i', $options, $e) ? strtolower($e[1]) : null,
            'columns' => $columns,
            'foreign_keys' => $foreignKeys,
        ];
    }

    /**
     * A single column definition line. Constraint lines return null so callers
     * can tell them apart from a parse failure on a real column.
     *
     * @return array<string,mixed>|null
     */
    public static function parseColumnDefinition(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|FULLTEXT|SPATIAL|CHECK|CONSTRAINT|FOREIGN|PERIOD)\b/i', $line)) {
            return null;
        }
        if (!preg_match('/^(`[^`]+`|"([^"]+)"|\[[^\]]+\]|[\w$]+)\s*(.*)$/s', $line, $m)) {
            return null;
        }
        $name = self::identifier($m[1]);
        if ($name === '') {
            return null;
        }
        $rest = trim($m[3] ?? '');
        if ($rest === '') {
            // "col" with no type — unusable.
            return null;
        }

        $type = '';
        if (preg_match('/^([A-Za-z]+)\s*(\([^)]*\))?\s*((?:unsigned\s*)?(?:zerofill\s*)?)/i', $rest, $t)) {
            $type = strtolower($t[1]) . ($t[2] ?? '') . ' ' . trim($t[3] ?? '');
            $type = trim($type);
        }

        $comment = null;
        if (preg_match("/COMMENT\s*('(?:[^'\\\\]|\\\\.|'')*')/i", $rest, $c)) {
            $comment = self::stringLiteral($c[1]);
        }

        $references = null;
        if (preg_match('/REFERENCES\s+([`"\[]?[\w.$]+[`"\]]?)\s*\(\s*([`"\[]?[\w]+[`"\]]?)/i', $rest, $r)) {
            $references = ['table' => self::identifier($r[1]), 'column' => self::identifier($r[2])];
        }

        $default = null;
        if (preg_match("/DEFAULT\s+('(?:[^'\\\\]|\\\\.|'')*'|[A-Za-z0-9_.+-]+|NULL)/i", $rest, $d)) {
            $raw = $d[1];
            if (str_starts_with($raw, "'")) {
                $default = self::stringLiteral($raw);
            } elseif (strcasecmp($raw, 'null') !== 0) {
                $default = $raw;
            }
        }

        return [
            'name' => $name,
            'type' => $type,
            'nullable' => !preg_match('/\bNOT\s+NULL\b/i', $rest),
            'primary' => (bool) preg_match('/\bPRIMARY\s+KEY\b/i', $rest),
            'auto_increment' => (bool) preg_match('/\bAUTO_INCREMENT\b/i', $rest),
            'unique' => (bool) preg_match('/\bUNIQUE\b/i', $rest),
            'generated' => (bool) preg_match('/\bGENERATED\b|\bAS\s*\(/i', $rest),
            'default' => $default,
            'comment' => $comment,
            'references' => $references,
        ];
    }

    /** @return array{column:string,table:string,column_ref:string,on_delete:?string,on_update:?string}|null */
    public static function parseForeignKey(string $line): ?array
    {
        if (!preg_match('/\(([^)]+)\)\s*REFERENCES\s+([`"\[]?[\w.$]+[`"\]]?)\s*\(\s*([^)]+)\)/i', $line, $m)) {
            return null;
        }
        $column = self::identifier(explode(',', $m[1])[0]);
        $refColumn = self::identifier(trim($m[3], " \t\n\r\0\x0B`\"[]'"));
        return [
            'column' => $column,
            'table' => self::identifier($m[2]),
            'column_ref' => $refColumn,
            'on_delete' => preg_match('/ON\s+DELETE\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION|SET\s+DEFAULT)/i', $line, $o) ? strtoupper(str_replace(' ', '_', $o[1])) : null,
            'on_update' => preg_match('/ON\s+UPDATE\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION|SET\s+DEFAULT)/i', $line, $u) ? strtoupper(str_replace(' ', '_', $u[1])) : null,
        ];
    }

    /** Split a CREATE TABLE body on commas that are neither quoted nor nested. @return string[] */
    public static function splitTopLevel(string $body): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($quote !== null) {
                $buffer .= $ch;
                if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buffer .= $body[++$i];
                    continue;
                }
                if ($ch === $quote) {
                    if ($i + 1 < $len && $body[$i + 1] === $quote) {
                        $buffer .= $body[++$i];
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ')') {
                $depth = max(0, $depth - 1);
                $buffer .= $ch;
                continue;
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }

    /** Position of the parenthesis closing the one at $open, or null. Quote-aware. */
    public static function matchingParen(string $sql, int $open): ?int
    {
        if (($sql[$open] ?? '') !== '(') {
            return null;
        }
        $depth = 0;
        $quote = null;
        $len = strlen($sql);
        for ($i = $open; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($quote !== null) {
                if ($ch === '\\' && $quote !== '`') {
                    $i++;
                    continue;
                }
                if ($ch === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $i++;
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** `db`.`table`, `table`, table, [table] → table (last segment, no quotes). */
    public static function identifier(string $raw): string
    {
        $clean = trim($raw);
        $clean = preg_replace('/\s+/', '', (string) $clean) ?? '';
        if ($clean === '') {
            return '';
        }
        if (str_contains($clean, '.')) {
            $clean = (string) substr($clean, (int) strrpos($clean, '.') + 1);
        }
        return trim($clean, "`\"[]' ");
    }

    /** Unquote a MySQL single-quoted literal including '' and \ escapes. */
    public static function stringLiteral(string $raw): string
    {
        $value = trim($raw);
        if (strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }
        $value = str_replace("''", "'", $value);
        return (string) stripcslashes($value);
    }

    private static function tableComment(string $options): ?string
    {
        if (preg_match("/\bCOMMENT\s*=?\s*('(?:[^'\\\\]|\\\\.|'')*')/i", $options, $m)) {
            $comment = self::stringLiteral($m[1]);
            return $comment === '' ? null : $comment;
        }
        return null;
    }
}
