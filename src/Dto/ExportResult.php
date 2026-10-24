<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * Bytes for CSV/JSON, or an external handle (sheet id, stored file key) for a
 * host exporter. A success sets exactly one of bytes / location; `failed()` sets
 * neither and carries `error` instead.
 */
final readonly class ExportResult
{
    public function __construct(
        public bool $ok,
        public string $format = 'csv',
        public ?string $fileName = null,
        public ?string $bytes = null,
        public ?string $location = null,
        public ?string $error = null,
        public array $meta = [],
    ) {
    }

    public static function file(string $format, string $fileName, string $bytes, array $meta = []): self
    {
        return new self(true, $format, $fileName, $bytes, null, null, $meta);
    }

    public static function external(string $format, string $location, string $fileName = '', array $meta = []): self
    {
        return new self(true, $format, $fileName !== '' ? $fileName : null, null, $location, null, $meta);
    }

    public static function failed(string $error, string $format = 'csv'): self
    {
        return new self(false, $format, null, null, null, $error);
    }

    public function size(): int
    {
        return $this->bytes !== null ? strlen($this->bytes) : 0;
    }
}
