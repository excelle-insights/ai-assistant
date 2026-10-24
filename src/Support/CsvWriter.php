<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * RFC 4180 CSV writer that streams to a file handle.
 *
 * Streaming (one row at a time, never the whole table in memory) is the point:
 * a host exporting 200k rows must not hit memory_limit. `line()` is pure and
 * static so the quoting rules can be unit-tested without touching the disk.
 *
 * Quoting rules implemented here:
 *   - a field is quoted when it contains the delimiter, a double quote, CR or LF
 *   - double quotes inside a field are doubled
 *   - leading/trailing whitespace is quoted, otherwise it is not round-trippable
 *   - a digit string with a leading zero is quoted so spreadsheet readers treat
 *     it as text and keep "007"
 *   - nothing is ever cast: numbers keep their literal spelling, nulls are empty
 */
final class CsvWriter
{
    private const STRING_QUOTE_IF_NEEDED = 1;
    private const STRING_FORCE_QUOTE = 2;

    /** UTF-8 BOM as raw bytes — "\ufeff" is not an escape in a double-quoted string. */
    public const BOM = "\xEF\xBB\xBF";

    /** @var array<int,resource> */
    private array $stack = [];

    /** @var resource|null */
    private $handle = null;

    private string $path = '';
    private int $rows = 0;
    private int $bytes = 0;
    private bool $finished = false;

    /**
     * @param string      $delimiter single byte; ',' by default, "\t" for TSV
     * @param string      $newline   "\r\n" is the RFC 4180 record separator
     * @param bool        $bom       UTF-8 BOM so Excel opens accented text correctly
     * @param int         $quoting   self::STRING_* — a host that feeds formatted
     *                               numbers may want everything quoted
     */
    public function __construct(
        private string $delimiter = ',',
        private string $newline = "\r\n",
        private bool $bom = true,
        private int $quoting = self::STRING_QUOTE_IF_NEEDED,
    ) {
        $this->delimiter = $this->normaliseDelimiter($this->delimiter);
        $this->newline = $this->newline === '' ? "\r\n" : $this->newline;
    }

    /** Open a file for writing; creates the parent directory when missing. */
    public function open(string $path): void
    {
        $dir = dirname($path);
        if ($dir !== '' && !is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create export directory: ' . $dir);
        }

        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open export file for writing: ' . $path);
        }

        $this->path = $path;
        $this->handle = $handle;
        $this->rows = 0;
        $this->bytes = 0;
        $this->finished = false;

        if ($this->bom) {
            $this->write(self::BOM);
        }
    }

    /** Write one record; fields keep their array order. */
    public function row(array $fields): void
    {
        $this->ensureOpen();
        $this->write(self::renderLine(array_values($fields), $this->delimiter, $this->newline, $this->quoting));
        $this->rows++;
    }

    /** @param iterable<array<int|string,mixed>> $records */
    public function rows(iterable $records): void
    {
        foreach ($records as $record) {
            $this->row((array) $record);
        }
    }

    /** Flush, close and return the number of bytes written. */
    public function finish(): int
    {
        if ($this->handle === null) {
            return $this->bytes;
        }
        if (!fflush($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
            throw new \RuntimeException('Cannot flush export file: ' . $this->path);
        }
        fclose($this->handle);
        $this->handle = null;
        $this->finished = true;

        return $this->bytes;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function rowCount(): int
    {
        return $this->rows;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * Pure one-record encoder (no handle, no BOM) — the function the tests pin
     * down, and what a host needs to build a CSV snippet in memory.
     */
    public static function line(array $fields, string $delimiter = ',', string $newline = "\r\n", int $quoting = self::STRING_QUOTE_IF_NEEDED): string
    {
        return self::renderLine(array_values($fields), self::normaliseDelimiterStatic($delimiter), $newline, $quoting);
    }

    /** Quote every field that carries the delimiter, a quote or a line break. */
    public static function needsQuoting(string $field, string $delimiter): bool
    {
        if ($field === '') {
            return false;
        }
        if ($delimiter !== '' && str_contains($field, $delimiter)) {
            return true;
        }
        if (str_contains($field, '"') || str_contains($field, "\n") || str_contains($field, "\r")) {
            return true;
        }
        // Leading/trailing whitespace survives only inside quotes.
        if (preg_match('/^\s|\s$/u', $field) === 1) {
            return true;
        }
        // "007" must not be read back as 7.
        return preg_match('/^0\d*$/u', $field) === 1;
    }

    public static function delimiter(): string
    {
        $configured = Config::string('exports.csv_delimiter', '');
        if ($configured === '') {
            return ',';
        }

        return self::normaliseDelimiterStatic($configured);
    }

    /** CRLF by default; 'LF'/'\n' accepted from a config file. */
    public static function newline(): string
    {
        $configured = strtoupper(trim(Config::string('exports.csv_newline', '')));

        return match ($configured) {
            'LF', '\N', '\\N' => "\n",
            'CR' => "\r",
            default => "\r\n",
        };
    }

    /** Excel needs the BOM to notice UTF-8; a host piping CSV elsewhere may not. */
    public static function useBom(): bool
    {
        return Config::bool('exports.csv_bom', true);
    }

    public static function quotingForce(): int
    {
        return Config::bool('exports.csv_quote_all', false) ? self::STRING_FORCE_QUOTE : self::STRING_QUOTE_IF_NEEDED;
    }

    private static function renderLine(array $fields, string $delimiter, string $newline, int $quoting): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $text = self::stringify($field);
            $force = $quoting === self::STRING_FORCE_QUOTE;
            $parts[] = $force || self::needsQuoting($text, $delimiter) ? '"' . str_replace('"', '""', $text) . '"' : $text;
        }

        return implode($delimiter, $parts) . $newline;
    }

    /** Cell => CSV text. Arrays/objects are JSON so a nested value is never lost. */
    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : (string) iconv('UTF-8', 'UTF-8//IGNORE', $value);
        }
        if (is_int($value) || is_float($value)) {
            if (is_float($value) && !is_finite($value)) {
                return '';
            }

            return (string) $value;
        }
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded === false ? '' : $encoded;
        }

        return '';
    }

    private function ensureOpen(): void
    {
        if ($this->handle === null) {
            throw new \LogicException('CsvWriter::open() must be called before writing rows.');
        }
    }

    private function write(string $chunk): void
    {
        /** @var resource $handle */
        $handle = $this->handle;
        $written = fwrite($handle, $chunk);
        if ($written === false) {
            throw new \RuntimeException('Cannot write to export file: ' . $this->path);
        }
        $this->bytes += $written;
    }

    private function normaliseDelimiter(string $delimiter): string
    {
        return self::normaliseDelimiterStatic($delimiter);
    }

    private static function normaliseDelimiterStatic(string $delimiter): string
    {
        $mapped = match (strtolower(trim($delimiter))) {
            'tab', '\t', '\\t' => "\t",
            'semicolon', ';' => ';',
            'pipe', '|' => '|',
            'colon' => ':',
            default => null,
        };
        if ($mapped !== null) {
            return $mapped;
        }
        if ($delimiter === '') {
            return ',';
        }
        // Multi-character delimiters are not CSV; keep the first byte.
        return substr($delimiter, 0, 1);
    }
}
