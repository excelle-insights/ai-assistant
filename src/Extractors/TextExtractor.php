<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Extractors;

use ExcelleInsights\AiAssistant\Contracts\TextExtractorInterface;
use ExcelleInsights\AiAssistant\Dto\ExtractedDocument;
use ExcelleInsights\AiAssistant\Dto\UploadedFile;
use ExcelleInsights\AiAssistant\Support\TokenChunker;

/**
 * Built-in reader for everything that already is text: .txt, .md, .csv, .tsv,
 * .json, .log, .sql, .xml, .yml — and any other `text/*` MIME the host allows.
 *
 * Zero dependencies. UTF-8 is assumed; invalid bytes are converted or replaced
 * rather than throwing, because a partially mojibake log file is still worth
 * indexing.
 */
final class TextExtractor implements TextExtractorInterface
{
    use MatchesType;

    /** Canonical MIME this extractor is chosen for when the registry sniffs bytes. */
    public const MIME = 'text/plain';

    private const MIMES = [
        'text/plain',
        'text/markdown',
        'text/csv',
        'text/tab-separated-values',
        'text/tsv',
        'text/xml',
        'text/yaml',
        'text/x-yaml',
        'application/json',
        'application/xml',
        'application/sql',
        'application/x-sql',
    ];

    private const EXTENSIONS = [
        'txt', 'text', 'log', 'md', 'markdown', 'mdx', 'csv', 'tsv',
        'json', 'sql', 'dump', 'xml', 'yml', 'yaml', 'ini',
    ];

    public function supports(string $mimeOrExtension): bool
    {
        $probe = strtolower(trim(explode(';', $mimeOrExtension)[0]));
        if ($probe === '') {
            return false;
        }
        if (str_starts_with($probe, 'text/') && $probe !== 'text/html' && $probe !== 'text/xml') {
            return true;
        }
        return $this->matchesType($probe, self::MIMES, self::EXTENSIONS);
    }

    public function extract(UploadedFile $file, array $options = []): ExtractedDocument
    {
        $text = $this->tidy($this->decode($file->bytes));

        if ($text === '') {
            return new ExtractedDocument(text: '', pageCount: 0, meta: ['extractor' => 'text', 'filename' => $file->name]);
        }

        $lines = preg_split('/\n/', $text) ?: [];
        $tables = [];
        $lineNo = 0;
        $block = [];
        foreach ($lines as $line) {
            $lineNo++;
            if (TokenChunker::looksLikeTableRow((string) $line)) {
                $block[] = ['line' => $lineNo, 'text' => (string) $line];
                continue;
            }
            if ($block !== []) {
                $tables[] = $block;
                $block = [];
            }
        }
        if ($block !== []) {
            $tables[] = $block;
        }

        return new ExtractedDocument(
            text: $text,
            pages: [['page' => 1, 'text' => $text]],
            tables: $tables,
            language: null,
            pageCount: 1,
            meta: [
                'extractor' => 'text',
                'filename' => $file->name,
                'mime' => $file->mime,
                'line_count' => count($lines),
            ],
        );
    }

    /** Strip a BOM and coerce the payload to valid UTF-8 without throwing. */
    private function decode(string $bytes): string
    {
        foreach (["\xEF\xBB\xBF", "\xFE\xFF", "\xFF\xFE"] as $bom) {
            if (str_starts_with($bytes, $bom)) {
                $bytes = substr($bytes, strlen($bom));
                break;
            }
        }
        if (function_exists('mb_check_encoding') && mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        return $bytes;
    }
}
