<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Extractors;

use ExcelleInsights\AiAssistant\Contracts\TextExtractorInterface;
use ExcelleInsights\AiAssistant\Dto\ExtractedDocument;
use ExcelleInsights\AiAssistant\Dto\UploadedFile;
use ExcelleInsights\AiAssistant\Support\Config;

/**
 * PDF text — with NO required dependency.
 *
 * Resolution order (each optional, detected with class_exists so nothing is
 * autoloaded or forced on the host):
 *   1. smalot/pdfparser            (\Smalot\PdfParser\Parser)
 *   2. spatie/pdf-to-text + the poppler `pdftotext` binary
 *   3. a `pdftotext` (or configured) binary found on PATH
 *
 * When none is available supports() is false and extract() throws a
 * DomainException naming the exact fix, so DocumentService records
 * `needs_extractor` instead of indexing empty text. The package never installs
 * a PDF library itself — the host already owns one or can register an
 * extractor in `documents.extractors`.
 */
final class PdfExtractor implements TextExtractorInterface
{
    use MatchesType;

    /** Canonical MIME this extractor is chosen for when the registry sniffs bytes. */
    public const MIME = 'application/pdf';

    private const MIMES = [self::MIME, 'application/x-pdf', 'application/acrobat'];
    private const EXTENSIONS = ['pdf'];

    public const NO_LIBRARY_MESSAGE =
        'No PDF text reader is available. Install smalot/pdfparser or spatie/pdf-to-text in the host, '
        . 'put poppler\'s pdftotext on PATH (or set documents.pdf_binary), '
        . 'or register a TextExtractorInterface for application/pdf in documents.extractors.';

    public function supports(string $mimeOrExtension): bool
    {
        return $this->isPdf($mimeOrExtension) && $this->engine() !== null;
    }

    public function extract(UploadedFile $file, array $options = []): ExtractedDocument
    {
        $engine = $this->engine();
        if ($engine === null) {
            throw new \DomainException(self::NO_LIBRARY_MESSAGE);
        }

        $text = match ($engine) {
            'smalot' => $this->withSmalot($file->bytes, $file->name),
            'binary' => $this->withBinary($file->bytes, $file->name),
            default => throw new \DomainException(self::NO_LIBRARY_MESSAGE),
        };

        $text = $this->tidy($text);
        if ($text === '') {
            return new ExtractedDocument(
                text: '',
                pageCount: 0,
                meta: ['extractor' => 'pdf', 'engine' => $engine, 'filename' => $file->name, 'empty' => true],
            );
        }

        // Page markers emitted by pdftotext (`\f`) are the only reliable page map.
        $pages = [];
        $chunks = preg_split('/\x0C+/', $text) ?: [];
        $clean = [];
        foreach ($chunks as $index => $page) {
            $page = trim($page);
            if ($page === '') {
                continue;
            }
            $pages[] = ['page' => count($pages) + 1, 'text' => $page];
            $clean[] = $page;
        }

        return new ExtractedDocument(
            text: $this->tidy(implode("\n\n", $clean)),
            pages: $pages,
            language: null,
            pageCount: count($pages),
            meta: ['extractor' => 'pdf', 'engine' => $engine, 'filename' => $file->name, 'mime' => $file->mime],
        );
    }

    /** Is this probe a PDF at all, ignoring whether a reader exists? */
    public function isPdf(string $mimeOrExtension): bool
    {
        return $this->matchesType(strtolower(trim($mimeOrExtension)), self::MIMES, self::EXTENSIONS);
    }

    /** Which optional engine is usable in this environment (null = none). */
    public function engine(): ?string
    {
        if (class_exists('\\Smalot\\PdfParser\\Parser')) {
            return 'smalot';
        }
        if ($this->pdfBinary() !== null) {
            return 'binary';
        }
        return null;
    }

    private function pdfBinary(): ?string
    {
        $configured = Config::string('documents.pdf_binary', '');
        if ($configured !== '') {
            $resolved = $this->executable($configured);
            if ($resolved !== null) {
                return $resolved;
            }
        }
        if (class_exists('\\Spatie\\PdfToText\\Pdf')) {
            $binary = $this->executable('pdftotext');
            if ($binary !== null) {
                return $binary;
            }
        }
        return $this->executable('pdftotext');
    }

    private function withSmalot(string $bytes, string $filename): string
    {
        $class = '\\Smalot\\PdfParser\\Parser';
        try {
            $parser = new $class();
            $document = $parser->parseContent($bytes);
            if (!method_exists($document, 'getText')) {
                throw new \DomainException(self::NO_LIBRARY_MESSAGE);
            }
            return (string) $document->getText();
        } catch (\DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('PDF parsing failed for "' . $filename . '": ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * poppler's pdftotext writing to stdout ("-" output target). The temp file
     * is removed even if the binary fails.
     */
    private function withBinary(string $bytes, string $filename): string
    {
        $binary = $this->pdfBinary();
        if ($binary === null) {
            throw new \DomainException(self::NO_LIBRARY_MESSAGE);
        }
        $path = $this->tempFile($bytes, 'pdf');
        if ($path === null) {
            throw new \RuntimeException('Could not stage "' . $filename . '" for PDF extraction.');
        }
        try {
            $command = escapeshellarg($binary) . ' -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null';
            $text = $this->runCommand($command);
            if ($text === null || trim($text) === '') {
                throw new \RuntimeException('PDF extraction produced no text for "' . $filename . '".');
            }
            return $text;
        } finally {
            @unlink($path);
        }
    }
}
