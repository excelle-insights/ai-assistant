<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * What an exporter must produce: rows (already permission-filtered and masked),
 * a file name, and the question/filters that produced them so the file carries
 * its own provenance.
 */
final readonly class ExportRequest
{
    /**
     * @param string[] $columns
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $chart
     * @param array<string,mixed> $options
     */
    public function __construct(
        public UserContext $user,
        public string $format,
        public string $fileName,
        public array $columns,
        public array $rows,
        public string $title = '',
        public string $question = '',
        public array $appliedFilters = [],
        public array $chart = [],
        public array $options = [],
    ) {
    }

    public function mimeType(): string
    {
        return match (strtolower($this->format)) {
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'pdf' => 'application/pdf',
            'tsv' => 'text/tab-separated-values',
            default => 'application/octet-stream',
        };
    }
}
