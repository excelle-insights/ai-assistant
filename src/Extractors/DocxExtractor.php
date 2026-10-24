<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Extractors;

use ExcelleInsights\AiAssistant\Contracts\TextExtractorInterface;
use ExcelleInsights\AiAssistant\Dto\ExtractedDocument;
use ExcelleInsights\AiAssistant\Dto\UploadedFile;

/**
 * Dependency-free .docx reader.
 *
 * A .docx file is a ZIP container whose `word/document.xml` holds the body, so
 * this needs nothing but ext-zip and ext-dom — both are already implied by the
 * package's own tooling, and neither is added to composer.json. When ext-zip is
 * missing the extractor honestly reports `supports() === false`, which makes
 * DocumentService store `needs_extractor` instead of indexing empty text.
 *
 * Word does not put page breaks into document.xml in a way that can be mapped
 * reliably, so `pageCount` is 0 (unknown) rather than a guess.
 */
final class DocxExtractor implements TextExtractorInterface
{
    use MatchesType;

    private const MIMES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
    ];

    private const EXTENSIONS = ['docx', 'dotx'];

    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function supports(string $mimeOrExtension): bool
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\DOMDocument::class)) {
            return false;
        }
        $probe = strtolower(trim(explode(';', $mimeOrExtension)[0]));
        if ($probe === '') {
            return false;
        }
        if ($this->matchesType($probe, self::MIMES, self::EXTENSIONS)) {
            return true;
        }
        // ZIP/unknown payloads are resolved by ExtractorRegistry::sniff(), which
        // opens the container and looks for word/document.xml before choosing.
        return false;
    }

    public function extract(UploadedFile $file, array $options = []): ExtractedDocument
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \DomainException('The zip extension is required to read "' . $file->name . '".');
        }

        $path = $this->tempFile($file->bytes, 'docx');
        if ($path === null) {
            throw new \RuntimeException('Could not stage "' . $file->name . '" for extraction.');
        }

        $zip = new \ZipArchive();
        try {
            if ($zip->open($path) !== true) {
                throw new \DomainException('"' . $file->name . '" is not a readable .docx container.');
            }
            $document = $zip->getFromName('word/document.xml');
            $core = $zip->getFromName('docProps/core.xml');
            $zip->close();
        } finally {
            @unlink($path);
        }

        if (!is_string($document) || trim($document) === '') {
            return new ExtractedDocument(text: '', pageCount: 0, meta: ['extractor' => 'docx', 'filename' => $file->name]);
        }

        $dom = $this->loadXml($document);
        $lines = $dom === null ? [] : $this->lines($dom);
        $text = $this->tidy(implode("\n\n", $lines));

        if ($text === '') {
            return new ExtractedDocument(text: '', pageCount: 0, meta: ['extractor' => 'docx', 'filename' => $file->name]);
        }

        return new ExtractedDocument(
            text: $text,
            pages: [],
            tables: $this->tableRows($lines),
            language: null,
            pageCount: 0,
            meta: array_filter([
                'extractor' => 'docx',
                'filename' => $file->name,
                'mime' => $file->mime,
                'paragraph_count' => count($lines),
                'title' => $this->xmlValue($core, 'dc:title') ?? $this->xmlValue($core, 'title'),
            ], static fn ($v) => $v !== null),
        );
    }

    /** @return list<string> rendered paragraph / table-row lines in document order */
    private function lines(\DOMDocument $dom): array
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::WORD_NS);
        $lines = [];

        $nodes = $xpath->query('/w:document/w:body/*');
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xpath->query('//w:p | //w:tbl');
        }
        if ($nodes === false) {
            return $lines;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $name = $node->localName;
            if ($name === 'p') {
                $text = $this->paragraphText($xpath, $node);
                if ($text === '') {
                    continue;
                }
                $level = $this->headingLevel($xpath, $node);
                $lines[] = $level > 0 ? str_repeat('#', $level) . ' ' . $text : $text;
            } elseif ($name === 'tbl') {
                foreach ($this->tableLines($xpath, $node) as $row) {
                    $lines[] = $row;
                }
            }
        }
        return $lines;
    }

    private function paragraphText(\DOMXPath $xpath, \DOMElement $paragraph): string
    {
        $out = '';
        $nodes = $xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $paragraph);
        if ($nodes === false) {
            return '';
        }
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $out .= match ($node->localName) {
                'tab' => "\t",
                'br', 'cr' => "\n",
                default => $node->textContent,
            };
        }
        return trim(preg_replace('/\s+\n/', "\n", $out) ?? $out);
    }

    private function headingLevel(\DOMXPath $xpath, \DOMElement $paragraph): int
    {
        $styles = $xpath->query('./w:pPr/w:pStyle/@w:val', $paragraph);
        if ($styles === false || $styles->length === 0) {
            return 0;
        }
        $value = strtolower(trim((string) $styles->item(0)?->nodeValue));
        if ($value === 'title') {
            return 1;
        }
        if (preg_match('/heading\s*([1-6])/', $value, $m) === 1) {
            return (int) $m[1];
        }
        return str_contains($value, 'headline') ? 1 : 0;
    }

    /**
     * @return list<string> one pipe-separated line per table row
     */
    private function tableLines(\DOMXPath $xpath, \DOMElement $table): array
    {
        $rows = [];
        $trNodes = $xpath->query('./w:tr', $table);
        if ($trNodes === false) {
            return $rows;
        }
        foreach ($trNodes as $tr) {
            if (!$tr instanceof \DOMElement) {
                continue;
            }
            $cells = [];
            $tcNodes = $xpath->query('./w:tc', $tr);
            if ($tcNodes !== false) {
                foreach ($tcNodes as $tc) {
                    if ($tc instanceof \DOMElement) {
                        $cells[] = trim(str_replace("\n", ' ', $this->cellText($xpath, $tc)));
                    }
                }
            }
            if ($cells !== []) {
                $rows[] = '| ' . implode(' | ', $cells) . ' |';
            }
        }
        return $rows;
    }

    private function cellText(\DOMXPath $xpath, \DOMElement $cell): string
    {
        $parts = [];
        $paragraphs = $xpath->query('./w:p', $cell);
        if ($paragraphs !== false) {
            foreach ($paragraphs as $paragraph) {
                if ($paragraph instanceof \DOMElement) {
                    $text = $this->paragraphText($xpath, $paragraph);
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Table-looking lines grouped for ExtractedDocument::$tables.
     *
     * @param list<string> $lines
     * @return list<list<array{line:int,text:string}>>
     */
    private function tableRows(array $lines): array
    {
        $tables = [];
        $block = [];
        foreach ($lines as $index => $line) {
            if (str_starts_with((string) $line, '|')) {
                $block[] = ['line' => $index + 1, 'text' => (string) $line];
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
        return $tables;
    }

    private function loadXml(string $xml): ?\DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NOBLANKS is deliberately NOT used: <w:t xml:space="preserve">
            // </w:t> carries a real space between runs and must survive.
            $loaded = $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $loaded ? $dom : null;
    }

    /** Cheap single-value read from a small side-car XML part (docProps). */
    private function xmlValue(?string $xml, string $localName): ?string
    {
        if ($xml === null || trim($xml) === '') {
            return null;
        }
        if (preg_match('#<' . preg_quote($localName, '#') . '[^>]*>(.*?)</' . preg_quote($localName, '#') . '>#s', $xml, $m) === 1) {
            $value = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return $value !== '' ? $value : null;
        }
        return null;
    }
}
