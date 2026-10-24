<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Extractors;

use ExcelleInsights\AiAssistant\Contracts\TextExtractorInterface;
use ExcelleInsights\AiAssistant\Dto\ExtractedDocument;
use ExcelleInsights\AiAssistant\Dto\UploadedFile;

/**
 * Built-in reader for HTML and XHTML: tags and attributes dropped, entities
 * decoded, block elements turned into line breaks so tables and lists stay
 * readable for the chunker. No dependencies, no crawler — it only understands
 * bytes the host already fetched.
 */
final class HtmlExtractor implements TextExtractorInterface
{
    use MatchesType;

    /** Canonical MIME this extractor is chosen for when the registry sniffs bytes. */
    public const MIME = 'text/html';

    private const MIMES = [self::MIME, 'application/xhtml+xml', 'text/htm'];
    private const EXTENSIONS = ['html', 'htm', 'xhtml'];

    public function supports(string $mimeOrExtension): bool
    {
        $probe = strtolower(trim(explode(';', $mimeOrExtension)[0]));
        if ($probe === '') {
            return false;
        }
        if ($this->matchesType($probe, self::MIMES, self::EXTENSIONS)) {
            return true;
        }
        // Content sniffing for this extractor lives in ExtractorRegistry::sniff(),
        // so an octet-stream upload that is really HTML still gets read.
        return false;
    }

    public function extract(UploadedFile $file, array $options = []): ExtractedDocument
    {
        $html = $this->decode($file->bytes);
        $text = $this->toText($html);

        if ($text === '') {
            return new ExtractedDocument(text: '', pageCount: 0, meta: ['extractor' => 'html', 'filename' => $file->name]);
        }

        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) === 1) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return new ExtractedDocument(
            text: $text,
            pages: [['page' => 1, 'text' => $text]],
            tables: $this->tables($html),
            language: null,
            pageCount: 1,
            meta: array_filter([
                'extractor' => 'html',
                'filename' => $file->name,
                'mime' => $file->mime,
                'title' => $title !== '' ? $title : null,
            ], static fn ($v) => $v !== null),
        );
    }

    /** Strip markup to readable plain text. */
    public function toText(string $html): string
    {
        $text = preg_replace('/<(script|style|noscript|template|svg|head)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<!--.*?-->/s', ' ', $text) ?? $text;
        // Cell boundaries become tabs so table rows still look like table rows.
        $text = preg_replace('/<\/(td|th)>/i', "\t", $text) ?? $text;
        $text = preg_replace('/<(br|hr)\b[^>]*>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|li|tr|h[1-6]|table|section|article|header|footer|blockquote|pre|ul|ol)>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<(li|h[1-6])\b[^>]*>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xC2\xA0", "\r\n", "\r"], [' ', "\n", "\n"], $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\t ?/', "\t", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = preg_replace('/^\h*\R/m', '', $text) ?? $text;
        return trim($text);
    }

    /**
     * Table content as line groups, kept so downstream prompts can cite a row.
     *
     * @return list<list<array{line:int,text:string}>>
     */
    private function tables(string $html): array
    {
        $out = [];
        if (preg_match_all('/<table\b[^>]*>.*?<\/table>/is', $html, $found) !== false) {
            foreach ($found[0] ?? [] as $tableHtml) {
                $lines = [];
                $rowNo = 0;
                if (preg_match_all('/<tr\b[^>]*>.*?<\/tr>/is', $tableHtml, $rows) !== false) {
                    foreach ($rows[0] ?? [] as $rowHtml) {
                        $rowNo++;
                        $cells = [];
                        if (preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/is', $rowHtml, $cellFound) !== false) {
                            foreach ($cellFound[1] ?? [] as $cell) {
                                $cells[] = trim(html_entity_decode(strip_tags((string) $cell), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                            }
                        }
                        if ($cells !== []) {
                            $lines[] = ['line' => $rowNo, 'text' => implode("\t", $cells)];
                        }
                    }
                }
                if ($lines !== []) {
                    $out[] = $lines;
                }
            }
        }
        return $out;
    }

    private function decode(string $bytes): string
    {
        foreach (["\xEF\xBB\xBF"] as $bom) {
            if (str_starts_with($bytes, $bom)) {
                $bytes = substr($bytes, strlen($bom));
                break;
            }
        }
        if (preg_match('/<meta[^>]+charset=["\']?([A-Za-z0-9\-_]+)/i', $bytes, $m) === 1) {
            $charset = strtoupper((string) $m[1]);
            if ($charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($bytes, 'UTF-8', $charset);
                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            }
        }
        return $bytes;
    }
}
