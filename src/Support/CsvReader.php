<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * Minimal RFC 4180 reader used for the admin uploads (schema descriptions,
 * knowledge CSV). Written by hand instead of fgetcsv/str_getcsv because PHP 8.4
 * deprecates their escape-parameter behaviour and both mishandle embedded
 * newlines in quoted fields the way real exports from Excel are written.
 */
final class CsvReader
{
    /**
     * @return array<int,array<int,string>> rows of raw string fields
     */
    public static function parse(string $csv, string $delimiter = ',', bool $stripBom = true): array
    {
        if ($stripBom) {
            $csv = self::stripBom($csv);
        }
        if ($csv === '') {
            return [];
        }
        if ($delimiter === ',') {
            $delimiter = self::detectDelimiter($csv);
        }

        $rows = [];
        $row = [];
        $field = '';
        $inQuotes = false;
        $len = strlen($csv);
        $i = 0;
        $rowHadContent = false;

        while ($i < $len) {
            $ch = $csv[$i];
            if ($inQuotes) {
                if ($ch === '"') {
                    if (($csv[$i + 1] ?? '') === '"') {
                        $field .= '"';
                        $i += 2;
                        continue;
                    }
                    $inQuotes = false;
                    $i++;
                    continue;
                }
                $field .= $ch;
                $i++;
                continue;
            }
            if ($ch === '"' && $field === '') {
                $inQuotes = true;
                $rowHadContent = true;
                $i++;
                continue;
            }
            if ($ch === $delimiter) {
                $row[] = $field;
                $field = '';
                $rowHadContent = true;
                $i++;
                continue;
            }
            if ($ch === "\r") {
                $i++;
                continue;
            }
            if ($ch === "\n") {
                $row[] = $field;
                $rows[] = $row;
                $row = [];
                $field = '';
                $rowHadContent = false;
                $i++;
                continue;
            }
            $field .= $ch;
            if ($ch !== ' ' && $ch !== "\t") {
                $rowHadContent = true;
            }
            $i++;
        }
        if ($field !== '' || $row !== [] || $rowHadContent) {
            $row[] = $field;
            $rows[] = $row;
        }

        return self::dropBlankRows($rows);
    }

    /** @return array<int,array<string,string>> header-keyed rows */
    public static function parseAssoc(string $csv, string $delimiter = ','): array
    {
        $rows = self::parse($csv, $delimiter);
        if ($rows === []) {
            return [];
        }
        $header = array_map(static fn (string $h): string => trim($h), array_map('strval', array_shift($rows)));
        $out = [];
        foreach ($rows as $row) {
            $assoc = [];
            foreach ($header as $i => $name) {
                if ($name === '') {
                    continue;
                }
                $assoc[$name] = trim((string) ($row[$i] ?? ''));
            }
            $out[] = $assoc;
        }
        return $out;
    }

    /** @return string[] normalised, lower-cased header names */
    public static function header(array $row): array
    {
        return array_map(static fn ($h): string => strtolower(trim((string) $h)), $row);
    }

    /** First non-empty index of any of $names within $header, or null. */
    public static function column(array $header, array $names): ?int
    {
        foreach ($names as $name) {
            $pos = array_search(strtolower(trim((string) $name)), $header, true);
            if ($pos !== false) {
                return (int) $pos;
            }
        }
        return null;
    }

    public static function stripBom(string $csv): string
    {
        foreach (["\xEF\xBB\xBF", "\xFF\xFE", "\xFE\xFF"] as $bom) {
            if (str_starts_with($csv, $bom)) {
                return substr($csv, strlen($bom));
            }
        }
        return $csv;
    }

    /** Sniff , vs ; vs \t from the first line — European Excel exports ';' by default. */
    public static function detectDelimiter(string $csv): string
    {
        $firstLine = strtok($csv, "\n") ?: '';
        $counts = [
            ',' => substr_count($firstLine, ','),
            ';' => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($counts);
        $best = (string) array_key_first($counts);
        return $counts[$best] > 0 ? $best : ',';
    }

    private static function dropBlankRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $joined = trim(implode('', array_map('strval', $row)));
            if ($joined === '') {
                continue;
            }
            $out[] = array_map(static fn ($v): string => (string) $v, $row);
        }
        return $out;
    }
}
