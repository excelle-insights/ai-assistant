<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

use ExcelleInsights\AiAssistant\Dto\ExtractedDocument;
use ExcelleInsights\AiAssistant\Dto\UploadedFile;

/**
 * Turns uploaded bytes into text the assistant can index.
 *
 * The package ships extractors for plain text, markdown, CSV/TSV and SQL dumps.
 * PDF, DOCX and images (OCR/vision) are deliberately host-pluggable: the format
 * libraries live in the host already and the package stays dependency-free.
 */
interface TextExtractorInterface
{
    /** 'application/pdf', 'image/png', … or a file extension like 'docx'. */
    public function supports(string $mimeOrExtension): bool;

    public function extract(UploadedFile $file, array $options = []): ExtractedDocument;
}
