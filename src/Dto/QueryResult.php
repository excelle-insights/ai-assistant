<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * Rows plus the provenance needed to show "which filters produced this" and to
 * let the user drill into records.
 */
final readonly class QueryResult
{
    /**
     * @param string[] $columns
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $appliedFilters
     */
    public function __construct(
        public array $columns = [],
        public array $rows = [],
        public ?string $sql = null,
        public array $appliedFilters = [],
        public bool $truncated = false,
        public string $source = 'semantic',
        public array $recordRefs = [],
    ) {
    }

    public static function fromRows(array $rows, ?string $sql = null, string $source = 'semantic'): self
    {
        $columns = [];
        foreach (array_slice($rows, 0, 5) as $row) {
            foreach (array_keys($row) as $key) {
                if (is_string($key) && !in_array($key, $columns, true)) {
                    $columns[] = $key;
                }
            }
        }
        return new self(columns: $columns, rows: array_values($rows), sql: $sql, source: $source);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * Compact text form for the prompt: one line per row.
     *
     * @param array<string,string> $labels  column key => readable name
     * @param array<string,string> $formats column key => money, number, percent…
     */
    public function asPromptText(int $maxRows = 60, array $labels = [], array $formats = []): string
    {
        if ($this->rows === []) {
            return '(no rows)';
        }
        if (count($this->rows) === 1 && count($this->rows[0]) === 1) {
            // reset() needs a reference, and a readonly property cannot be taken
            // by reference: copy the row first.
            $first = $this->rows[0];
            $value = reset($first);
            return is_scalar($value) ? (string) $value : json_encode($value);
        }
        $lines = [];
        foreach (array_slice($this->rows, 0, $maxRows) as $row) {
            $parts = [];
            foreach ($row as $key => $value) {
                $name = (string) ($labels[$key] ?? $key);
                $parts[] = $name . '=' . self::scalar($value, (string) ($formats[$key] ?? ''));
            }
            $lines[] = implode(' ', $parts);
        }
        if (count($this->rows) > $maxRows) {
            $lines[] = '… (' . (count($this->rows) - $maxRows) . ' more rows)';
        }
        return implode("\n", $lines);
    }

    private static function scalar(mixed $value, string $format = ''): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            $kind = strtolower(trim($format));
            if (in_array($kind, ['money', 'currency', 'number', 'count'], true)) {
                $decimals = (in_array($kind, ['money', 'currency'], true) && floor((float) $value) !== (float) $value) ? 2 : 0;
                return number_format((float) $value, $decimals);
            }
            return (string) $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
