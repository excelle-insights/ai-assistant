<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

use ExcelleInsights\AiAssistant\Dto\ExportRequest;
use ExcelleInsights\AiAssistant\Dto\ExportResult;

/**
 * Heavy document formats (XLSX, branded PDF, Google Sheets) are produced by the
 * host, which already owns PhpSpreadsheet/html2pdf/Google credentials. The
 * package generates CSV and JSON itself and stays dependency-free.
 *
 * Register an exporter in config('exports.host_exporters') as a class name or a
 * callable factory and ExportService will use it for that format.
 */
interface ExporterInterface
{
    /** 'xlsx', 'pdf', 'gsheets', … */
    public function format(): string;

    public function export(ExportRequest $request): ExportResult;
}
