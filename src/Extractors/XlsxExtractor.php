<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Extractors;

use ExcelleInsights\AiAssistant\Contracts\TextExtractorInterface;
use ExcelleInsights\AiAssistant\Dto\ExtractedDocument;
use ExcelleInsights\AiAssistant\Dto\UploadedFile;

/**
 * Dependency-free .xlsx reader: a spreadsheet is a ZIP of XML parts, so ext-zip
 * and ext-dom (both already implied by this package's own tooling) are enough —
 * no PhpSpreadsheet, no new composer requirement.
 *
 * Each sheet becomes a heading followed by one pipe-separated line per row, so
 * TokenChunker keeps rows atomic. Values are read as stored: shared strings and
 * inline strings resolved, formulas reported as their cached value.
 */
final class XlsxExtractor implements TextExtractorInterface
{
    use MatchesType;

    private const SCHEMA_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_PKG_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    // REL_PKG_NS documents the workbook.xml.rels namespace; relationship elements
    // are matched by local name so prefixed and unprefixed files both parse.

    /** Canonical MIME this extractor is chosen for when the registry sniffs bytes. */
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const MIMES = [
        self::MIME,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
    ];
    private const EXTENSIONS = ['xlsx', 'xlsm'];

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
        return $probe === 'application/zip' || $probe === 'application/x-zip-compressed';
    }

    public function extract(UploadedFile $file, array $options = []): ExtractedDocument
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \DomainException('The zip extension is required to read "' . $file->name . '".');
        }

        $path = $this->tempFile($file->bytes, 'xlsx');
        if ($path === null) {
            throw new \RuntimeException('Could not stage "' . $file->name . '" for extraction.');
        }

        $shared = [];
        $sheets = [];
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new \DomainException('"' . $file->name . '" is not a readable .xlsx container.');
            }
            try {
                $zipEntries = [];
                for ($i = 0; $i < (int) $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (is_string($name)) {
                        $zipEntries[] = $name;
                    }
                }

                $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
                if (is_string($sharedXml) && trim($sharedXml) !== '') {
                    $shared = $this->sharedStrings($sharedXml);
                }

                $names = $this->sheetNames($zipEntries, fn (string $entry) => $zip->getFromName($entry));
                sort($zipEntries);
                foreach ($zipEntries as $entry) {
                    if (!preg_match('#^xl/worksheets/[^/]+\.xml$#', $entry)) {
                        continue;
                    }
                    $xml = $zip->getFromName($entry);
                    if (!is_string($xml) || trim($xml) === '') {
                        continue;
                    }
                    $rows = $this->sheetRows($xml, $shared);
                    if ($rows === []) {
                        continue;
                    }
                    $sheets[] = [
                        'name' => $names[$entry] ?? basename($entry, '.xml'),
                        'rows' => $rows,
                    ];
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }

        if ($sheets === []) {
            return new ExtractedDocument(text: '', pageCount: 0, meta: ['extractor' => 'xlsx', 'filename' => $file->name]);
        }

        $lines = [];
        $tables = [];
        foreach ($sheets as $sheet) {
            $lines[] = '# ' . $sheet['name'];
            $block = [];
            foreach ($sheet['rows'] as $rowNumber => $row) {
                $line = '| ' . implode(' | ', $row) . ' |';
                $lines[] = $line;
                $block[] = ['line' => (int) $rowNumber, 'text' => $line];
            }
            if ($block !== []) {
                $tables[] = $block;
            }
            $lines[] = '';
        }

        return new ExtractedDocument(
            text: $this->tidy(implode("\n", $lines)),
            pages: [],
            tables: $tables,
            language: null,
            pageCount: count($sheets),
            meta: [
                'extractor' => 'xlsx',
                'filename' => $file->name,
                'mime' => $file->mime,
                'sheets' => array_map(static fn (array $s) => $s['name'], $sheets),
            ],
        );
    }

    /** @return list<string> */
    public function sharedStrings(string $xml): array
    {
        $dom = $this->load($xml);
        if ($dom === null) {
            return [];
        }
        $out = [];
        foreach ($dom->getElementsByTagName('si') as $si) {
            $text = '';
            foreach ($si->getElementsByTagName('t') as $t) {
                $text .= $t->textContent;
            }
            $out[] = $text;
        }
        return $out;
    }

    /**
     * Sheet display names keyed by their worksheet part path.
     *
     * @param list<string> $entries
     * @param callable(string):string|false $reader
     * @return array<string,string>
     */
    public function sheetNames(array $entries, callable $reader): array
    {
        if (!in_array('xl/workbook.xml', $entries, true)) {
            return [];
        }
        $workbook = $reader('xl/workbook.xml');
        if (!is_string($workbook) || trim($workbook) === '') {
            return [];
        }
        $dom = $this->load($workbook);
        if ($dom === null) {
            return [];
        }

        // r:id -> sheet name, then resolved through workbook.xml.rels to a part path.
        $byRelId = [];
        $rels = $reader('xl/_rels/workbook.xml.rels');
        $relTargets = [];
        if (is_string($rels) && trim($rels) !== '') {
            $relDom = $this->load($rels);
            if ($relDom !== null) {
                foreach ($relDom->getElementsByTagName('Relationship') as $rel) {
                    if ($rel instanceof \DOMElement) {
                        $target = (string) $rel->getAttribute('Target');
                        $relTargets[(string) $rel->getAttribute('Id')] = ltrim(preg_replace('#^\.\./#', '', $target) ?? $target, '/');
                    }
                }
            }
        }

        $out = [];
        foreach ($dom->getElementsByTagName('sheet') as $sheet) {
            if (!$sheet instanceof \DOMElement) {
                continue;
            }
            $name = trim($sheet->getAttribute('name'));
            if ($name === '') {
                continue;
            }
            $relId = '';
            foreach ($sheet->attributes ?? [] as $attribute) {
                if ($attribute instanceof \DOMAttr && str_ends_with($attribute->localName, 'id')) {
                    $relId = (string) $attribute->value;
                    break;
                }
            }
            if ($relId !== '' && isset($relTargets[$relId])) {
                $part = $relTargets[$relId];
                $out[$part] = $name;
                $out['xl/' . $part] = $name;
                continue;
            }
            $out[$name] = $name;
        }
        return $out;
    }

    /**
     * @param list<string> $shared
     * @return array<int, list<string>> row number => cell texts
     */
    public function sheetRows(string $xml, array $shared): array
    {
        $dom = $this->load($xml);
        if ($dom === null) {
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('s', self::SCHEMA_NS);
        $rowNodes = $xpath->query('//s:row');
        if ($rowNodes === false || $rowNodes->length === 0) {
            $rowNodes = $dom->getElementsByTagName('row');
        }

        $rows = [];
        foreach ($rowNodes as $rowNode) {
            if (!$rowNode instanceof \DOMElement) {
                continue;
            }
            $cells = [];
            $columnIndex = 0;
            foreach ($rowNode->childNodes as $cell) {
                if (!$cell instanceof \DOMElement || !in_array($cell->localName, ['c', 'cell'], true)) {
                    continue;
                }
                $target = $this->columnIndex($cell->getAttribute('r'), $columnIndex);
                $columnIndex = $target + 1;
                while (count($cells) < $target) {
                    $cells[] = '';
                }
                $cells[] = $this->cellValue($cell, $shared);
            }
            $cells = array_map(static fn ($v) => trim(str_replace(["\n", "\t"], ' ', (string) $v)), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            $number = (int) ($rowNode->getAttribute('r') !== '' ? (int) $rowNode->getAttribute('r') : count($rows) + 1);
            $rows[$number > 0 ? $number : count($rows) + 1] = $cells;
        }
        ksort($rows);
        return $rows;
    }

    /** @param list<string> $shared */
    private function cellValue(\DOMElement $cell, array $shared): string
    {
        $type = strtolower(trim($cell->getAttribute('t')));
        if ($type === 'inlinestr') {
            $text = '';
            foreach ($cell->getElementsByTagName('t') as $t) {
                $text .= $t->textContent;
            }
            return $text;
        }
        $value = null;
        foreach ($cell->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array($child->localName, ['v', 't'], true)) {
                $value = $child->textContent;
                break;
            }
        }
        if ($value === null) {
            return '';
        }
        if ($type === 's') {
            $index = (int) trim($value);
            return $shared[$index] ?? '';
        }
        if ($type === 'b') {
            return trim($value) === '1' ? '1' : '0';
        }
        if ($type === 'e') {
            return '';
        }
        return trim($value);
    }

    /** "B12" => 2 (1-based); falls back to the running position. */
    private function columnIndex(string $reference, int $fallback): int
    {
        if (preg_match('/^([A-Z]+)/', strtoupper(trim($reference)), $m) === 1) {
            $index = 0;
            foreach (str_split($m[1]) as $letter) {
                $index = $index * 26 + (ord($letter) - 64);
            }
            if ($index > 0) {
                return $index;
            }
        }
        return max(1, $fallback);
    }

    private function load(string $xml): ?\DOMDocument
    {
        if (!class_exists(\DOMDocument::class)) {
            return null;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $loaded ? $dom : null;
    }
}
