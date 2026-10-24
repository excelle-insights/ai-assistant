<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Extractors;

use ExcelleInsights\AiAssistant\Contracts\TextExtractorInterface;
use ExcelleInsights\AiAssistant\Dto\ExtractedDocument;
use ExcelleInsights\AiAssistant\Dto\UploadedFile;
use ExcelleInsights\AiAssistant\Support\Config;

/**
 * Chooses the extractor for an upload.
 *
 * Order, first match wins:
 *   1. host extractors from `documents.extractors` (class name or callable) —
 *      the host always keeps priority, because it owns the format libraries;
 *   2. built-ins that can genuinely read the payload in this environment
 *      (text/markdown/csv/json/log, html, docx, xlsx, and pdf/ocr when an
 *      optional reader or binary is present);
 *   3. content sniffing when the declared MIME is empty or octet-stream.
 *
 * When nothing can read the file, extract() throws \DomainException whose
 * message names the exact fix, so DocumentService records `needs_extractor`
 * instead of indexing an empty document.
 */
final class ExtractorRegistry
{
    /** @var list<TextExtractorInterface|callable(UploadedFile, array<string,mixed>): (ExtractedDocument|null)> */
    private array $hosts = [];

    /** @var list<TextExtractorInterface> */
    private array $builtins = [];

    /** @var array<string, TextExtractorInterface|callable|null> */
    private array $resolved = [];

    /** @param list<class-string|callable|TextExtractorInterface> $hostExtractors */
    public function __construct(array $hostExtractors = [], bool $withBuiltins = true)
    {
        foreach ($hostExtractors as $extractor) {
            $this->register($extractor);
        }
        if ($withBuiltins) {
            // Most specific first: OOXML containers, then PDF/OCR, then HTML,
            // then anything that is already text.
            $this->builtins = [
                new DocxExtractor(),
                new XlsxExtractor(),
                new PdfExtractor(),
                new OcrExtractor(),
                new HtmlExtractor(),
                new TextExtractor(),
            ];
        }
    }

    /** Build from configuration (`documents.extractors`). */
    public static function fromConfig(): self
    {
        $extras = [];
        foreach (Config::list('documents.extractors', []) as $entry) {
            if (is_string($entry) || is_array($entry) || is_object($entry)) {
                $extras[] = $entry;
            }
        }
        return new self($extras);
    }

    /**
     * @param class-string|callable|TextExtractorInterface $extractor
     */
    public function register(string|callable|object $extractor): void
    {
        if (is_string($extractor)) {
            if (!class_exists($extractor)) {
                throw new \InvalidArgumentException('Extractor class "' . $extractor . '" does not exist.');
            }
            $instance = new $extractor();
            if (!$instance instanceof TextExtractorInterface) {
                throw new \InvalidArgumentException(
                    'Extractor "' . $extractor . '" must implement ' . TextExtractorInterface::class . '.'
                );
            }
            $this->hosts[] = $instance;
            return;
        }

        if (is_object($extractor) && !$extractor instanceof TextExtractorInterface && !is_callable($extractor)) {
            throw new \InvalidArgumentException(
                'Extractors must implement ' . TextExtractorInterface::class . ' or be callable.'
            );
        }

        $this->hosts[] = $extractor;
        $this->resolved = [];
    }

    /** Can anything (host or built-in) read this MIME or extension? */
    public function supports(string $mimeOrExtension): bool
    {
        return $this->find($mimeOrExtension) !== null;
    }

    /**
     * The extractor that would handle this MIME or extension, or null.
     *
     * @return TextExtractorInterface|callable|null
     */
    public function find(string $mimeOrExtension): callable|object|null
    {
        $probe = trim($mimeOrExtension);
        if ($probe === '') {
            return null;
        }
        $key = 'probe:' . strtolower($probe);
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        foreach ($this->hosts as $extractor) {
            if (is_callable($extractor)) {
                continue; // callables are probed by trying them
            }
            if ($extractor->supports($probe)) {
                return $this->resolved[$key] = $extractor;
            }
        }
        foreach ($this->builtins as $extractor) {
            if ($extractor->supports($probe)) {
                return $this->resolved[$key] = $extractor;
            }
        }
        return $this->resolved[$key] = null;
    }

    /**
     * Every extractor that could plausibly read this file, in preference order.
     *
     * @return list<TextExtractorInterface|callable>
     */
    public function candidates(UploadedFile $file): array
    {
        $out = [];
        foreach ($this->hosts as $extractor) {
            if (is_callable($extractor)) {
                $out[] = $extractor;
                continue;
            }
            if ($extractor->supports($file->mime) || ($file->extension() !== '' && $extractor->supports($file->extension()))) {
                $out[] = $extractor;
            }
        }

        $probes = array_merge($this->sniffMimes($file), [$file->mime]);
        if ($file->extension() !== '') {
            $probes[] = $file->extension();
        }
        foreach ($probes as $probe) {
            $found = $this->find((string) $probe);
            if ($found !== null && !in_array($found, $out, true)) {
                $out[] = $found;
            }
        }
        return $out;
    }

    /**
     * Extract text from an upload.
     *
     * @param array<string,mixed> $options forwarded to the extractor
     * @throws \DomainException when the host must supply an extractor
     */
    public function extract(UploadedFile $file, array $options = []): ExtractedDocument
    {
        $empty = null;
        foreach ($this->candidates($file) as $extractor) {
            try {
                $document = is_callable($extractor)
                    ? $extractor($file, $options)
                    : $extractor->extract($file, $options);
            } catch (\DomainException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Extractor ' . $this->describe($extractor) . ' failed on "' . $file->name . '": ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            if ($document === null) {
                continue;
            }
            if (!$document instanceof ExtractedDocument) {
                throw new \RuntimeException(
                    'Extractor ' . $this->describe($extractor) . ' must return ' . ExtractedDocument::class . ' or null.'
                );
            }
            if (trim($document->text) !== '') {
                return $document;
            }
            // Legitimately empty (a scanned PDF, an empty sheet): keep trying a
            // smarter reader, and only then accept the empty result.
            $empty = $document;
        }

        if ($empty !== null) {
            return $empty;
        }

        throw new \DomainException($this->unsupportedMessage($file));
    }

    /** Clear, actionable "the host must provide an extractor" message. */
    public function unsupportedMessage(UploadedFile $file): string
    {
        return sprintf(
            'No extractor can read %s (mime %s, extension %s). The host must provide a %s for this '
            . 'type and list it in documents.extractors — or install something the package can detect: '
            . 'smalot/pdfparser or a pdftotext binary for PDFs, ext-zip for .docx/.xlsx, a tesseract '
            . 'binary (documents.ocr_binary) for images.',
            $file->name !== '' ? '"' . $file->name . '"' : 'this upload',
            $file->mime !== '' ? $file->mime : 'unknown',
            $file->extension() !== '' ? $file->extension() : 'none',
            TextExtractorInterface::class
        );
    }

    /**
     * MIME guesses taken from the payload, used when the declared type is
     * missing or application/octet-stream.
     *
     * @return list<string>
     */
    public function sniffMimes(UploadedFile $file): array
    {
        $declared = strtolower(trim(explode(';', $file->mime)[0]));
        if ($declared !== '' && $declared !== 'application/octet-stream' && $declared !== 'binary/octet-stream') {
            return [];
        }

        $bytes = substr($file->bytes, 0, 8192);
        if ($bytes === false) {
            return [];
        }

        $out = [];
        if (str_starts_with($bytes, '%PDF')) {
            $out[] = PdfExtractor::MIME;
        }
        if (str_starts_with($bytes, "PK\x03\x04")) {
            $inner = $this->ooxmlProbe($file->bytes);
            if ($inner !== null) {
                $out[] = $inner;
            }
        }
        $head = strtolower(ltrim($bytes));
        if (str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html') || str_starts_with($head, '<body')) {
            $out[] = HtmlExtractor::MIME;
        }
        if ($out === [] && $this->looksLikeText($bytes)) {
            $out[] = TextExtractor::MIME;
        }
        return $out;
    }

    /** Which OOXML document type is inside this ZIP container? */
    public function ooxmlProbe(string $bytes): ?string
    {
        if (!class_exists(\ZipArchive::class) || $bytes === '') {
            return null;
        }
        $path = @tempnam(sys_get_temp_dir(), 'aia-sniff-');
        if (!is_string($path) || @file_put_contents($path, $bytes) === false) {
            return null;
        }
        $zip = new \ZipArchive();
        try {
            if ($zip->open($path) !== true) {
                return null;
            }
            try {
                if (is_array($zip->statName('word/document.xml'))) {
                    return DocxExtractor::MIME;
                }
                if (is_array($zip->statName('xl/workbook.xml'))) {
                    return XlsxExtractor::MIME;
                }
                return null;
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    /** @return list<TextExtractorInterface> */
    public function builtins(): array
    {
        return $this->builtins;
    }

    /** @return list<TextExtractorInterface|callable> */
    public function hostExtractors(): array
    {
        return $this->hosts;
    }

    private function looksLikeText(string $bytes): bool
    {
        if ($bytes === '' || str_contains($bytes, "\x00")) {
            return false;
        }
        $printable = preg_replace('/[^\P{C}]/u', '', $bytes) ?? '';
        return strlen($printable) >= (int) (strlen($bytes) * 0.85);
    }

    private function describe(TextExtractorInterface|callable|string $extractor): string
    {
        if (is_string($extractor)) {
            return $extractor;
        }
        if (is_object($extractor)) {
            return $extractor::class;
        }
        return 'callable';
    }
}
