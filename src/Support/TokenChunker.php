<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * Token-budgeted text splitter for document indexing.
 *
 * Rules, in order of precedence:
 *   1. split on block boundaries (blank lines); a heading stays with its block;
 *   2. inside a block, split on sentence boundaries — never mid-sentence;
 *   3. table-looking lines (markdown pipes, TSV) are atomic: a row is only cut
 *      when that row alone exceeds the budget;
 *   4. overlap between consecutive chunks is whole sentences, never characters.
 *
 * Offsets refer to the newline-normalised input (see normalise()), and `text`
 * is exactly substr($normalised, start_offset, end_offset - start_offset), so a
 * hit can always be mapped back onto the original extraction.
 */
final class TokenChunker
{
    /** Floor so a misconfigured tiny budget cannot produce 1-token chunks. */
    public const MIN_CHUNK_TOKENS = 32;

    /** Characters that end a sentence in the supported scripts. */
    private const SENTENCE_ENDINGS = '.!?。！？';

    /** Never carry more than this share of the budget as overlap. */
    private const OVERLAP_MAX_SHARE = 3;

    /**
     * @return list<array{text:string,index:int,token_count:int,start_offset:int,end_offset:int}>
     */
    public static function chunk(string $text, int $chunkTokens = 800, int $overlapTokens = 100): array
    {
        $src = self::normalise($text);
        if (trim($src) === '') {
            return [];
        }

        $budget = max(self::MIN_CHUNK_TOKENS, $chunkTokens);
        $overlap = max(0, min(max(0, $overlapTokens), intdiv($budget, self::OVERLAP_MAX_SHARE)));

        /** @var list<array{start:int,end:int,tokens:int}> $slices */
        $slices = [];
        foreach (self::units($src) as $unit) {
            foreach (self::fitUnit($src, $unit, $budget) as $slice) {
                $slices[] = $slice;
            }
        }
        if ($slices === []) {
            return [];
        }

        $chunks = [];
        $current = [];
        $currentTokens = 0;

        foreach ($slices as $slice) {
            if ($currentTokens > 0 && $currentTokens + (int) $slice['tokens'] > $budget) {
                $chunks[] = self::range($src, $current);
                $current = self::overlapCarry($current, $overlap);
                $currentTokens = self::sumTokens($current);
            }
            $current[] = $slice;
            $currentTokens += (int) $slice['tokens'];
        }
        if ($current !== []) {
            $chunks[] = self::range($src, $current);
        }

        $out = [];
        foreach ($chunks as $chunk) {
            if ($chunk['text'] === '') {
                continue;
            }
            $out[] = [
                'text' => $chunk['text'],
                'index' => count($out),
                'token_count' => $chunk['token_count'],
                'start_offset' => $chunk['start_offset'],
                'end_offset' => $chunk['end_offset'],
            ];
        }
        return $out;
    }

    /** Token estimate shared with the rest of the package. */
    public static function tokens(string $text): int
    {
        return Db::estimateTokens($text);
    }

    /** Newline-normalised copy of the input — the reference for all offsets. */
    public static function normalise(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * Sentence pieces of one block with offsets relative to the block start.
     *
     * @return list<array{text:string,start:int,end:int,tokens:int}>
     */
    public static function sentences(string $block): array
    {
        $pattern = '/(?<=[' . preg_quote(self::SENTENCE_ENDINGS, '/') . '])\s+|\n+| {2,}/u';
        $parts = preg_split($pattern, $block, -1, PREG_SPLIT_OFFSET_CAPTURE);
        if (!is_array($parts)) {
            $parts = preg_split('/\s+/', $block, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        }

        $out = [];
        foreach ($parts as $part) {
            $raw = (string) ($part[0] ?? '');
            $offset = (int) ($part[1] ?? 0);
            if (trim($raw) === '') {
                continue;
            }
            $stripped = ltrim($raw);
            $start = $offset + (strlen($raw) - strlen($stripped));
            $text = rtrim($stripped);
            $end = $start + strlen($text);
            if ($end <= $start) {
                continue;
            }
            $out[] = ['text' => $text, 'start' => $start, 'end' => $end, 'tokens' => self::tokens($text)];
        }
        return $out;
    }

    /**
     * Atomic units of the whole document: one per table row, one per sentence of
     * prose, in source order.
     *
     * @return list<array{start:int,end:int,tokens:int,atomic:bool}>
     */
    public static function units(string $src): array
    {
        $units = [];
        foreach (self::blocks($src) as $block) {
            $blockText = substr($src, (int) $block['start'], (int) $block['end'] - (int) $block['start']);
            if ($block['table']) {
                foreach ($block['lines'] as $line) {
                    $raw = (string) $line['text'];
                    $trimmed = trim($raw);
                    if ($trimmed === '') {
                        continue;
                    }
                    $start = (int) $line['start'] + (strlen($raw) - strlen(ltrim($raw)));
                    $units[] = [
                        'start' => $start,
                        'end' => $start + strlen($trimmed),
                        'tokens' => self::tokens($trimmed),
                        'atomic' => true,
                    ];
                }
                continue;
            }
            foreach (self::sentences($blockText) as $sentence) {
                $units[] = [
                    'start' => (int) $block['start'] + (int) $sentence['start'],
                    'end' => (int) $block['start'] + (int) $sentence['end'],
                    'tokens' => (int) $sentence['tokens'],
                    'atomic' => false,
                ];
            }
        }
        return $units;
    }

    /**
     * Blocks of consecutive non-blank lines, flagged as table or prose.
     *
     * @return list<array{start:int,end:int,table:bool,lines:list<array{text:string,start:int}>}>
     */
    public static function blocks(string $src): array
    {
        $lines = [];
        $offset = 0;
        foreach (explode("\n", $src) as $line) {
            $lines[] = ['text' => $line, 'start' => $offset];
            $offset += strlen($line) + 1;
        }

        $blocks = [];
        $current = [];
        foreach ($lines as $line) {
            if (trim((string) $line['text']) === '') {
                if ($current !== []) {
                    $blocks[] = self::blockOf($current);
                    $current = [];
                }
                continue;
            }
            $current[] = $line;
        }
        if ($current !== []) {
            $blocks[] = self::blockOf($current);
        }
        return $blocks;
    }

    /** Does this line look like a table row (markdown pipe row or TSV)? */
    public static function looksLikeTableRow(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return false;
        }
        if (str_starts_with($trimmed, '|') && str_ends_with($trimmed, '|') && strlen(trimmed) > 1) {
            return true;
        }
        if (substr_count($trimmed, "\t") >= 2) {
            return true;
        }
        return substr_count($trimmed, ' | ') >= 2;
    }

    /**
     * @param list<array{text:string,start:int}> $lines
     * @return array{start:int,end:int,table:bool,lines:list<array{text:string,start:int}>}
     */
    private static function blockOf(array $lines): array
    {
        $tableRows = 0;
        foreach ($lines as $line) {
            if (self::looksLikeTableRow((string) $line['text'])) {
                $tableRows++;
            }
        }
        $last = $lines[count($lines) - 1];
        return [
            'start' => (int) $lines[0]['start'],
            'end' => (int) $last['start'] + strlen((string) $last['text']),
            'table' => $tableRows > 0 && $tableRows >= ceil(count($lines) / 2),
            'lines' => $lines,
        ];
    }

    /**
     * Break an over-budget unit into budget-sized slices. Table rows stay atomic.
     *
     * @param array{start:int,end:int,tokens:int,atomic:bool} $unit
     * @return list<array{start:int,end:int,tokens:int}>
     */
    private static function fitUnit(string $src, array $unit, int $budget): array
    {
        $tokens = max(1, (int) $unit['tokens']);
        if ($tokens <= $budget || (bool) $unit['atomic']) {
            return [['start' => (int) $unit['start'], 'end' => (int) $unit['end'], 'tokens' => $tokens]];
        }

        $text = substr($src, (int) $unit['start'], max(0, (int) $unit['end'] - (int) $unit['start']));
        $found = preg_match_all('/\S+\s*/u', $text, $matches, PREG_SET_ORDER);
        if (!is_int($found) || $found === 0 || !is_array($matches) || $matches === []) {
            return self::hardSplit((int) $unit['start'], $text, $budget);
        }

        $slices = [];
        $runStart = null;
        $runEnd = 0;
        $runTokens = 0;
        foreach ($matches as $match) {
            $word = (string) $match[0][0];
            $wordStart = (int) $match[0][1];
            $wordTokens = max(1, (int) floor(strlen($word) / 4) + 1);
            if ($runStart === null) {
                $runStart = $wordStart;
            }
            if ($runTokens > 0 && $runTokens + $wordTokens > $budget) {
                $slices[] = self::slice($unit, (int) $runStart, (int) $runEnd, $runTokens);
                $runStart = $wordStart;
                $runTokens = 0;
            }
            $runEnd = $wordStart + strlen($word);
            $runTokens += $wordTokens;
        }
        if ($runStart !== null && $runTokens > 0) {
            $slices[] = self::slice($unit, (int) $runStart, (int) $runEnd, $runTokens);
        }
        if ($slices === []) {
            return [['start' => (int) $unit['start'], 'end' => (int) $unit['end'], 'tokens' => $tokens]];
        }
        return $slices;
    }

    /** @param array{start:int,end:int} $unit */
    private static function slice(array $unit, int $start, int $end, int $tokens): array
    {
        $start = max((int) $unit['start'], $start);
        $end = min((int) $unit['end'], max($start + 1, $end));
        return ['start' => $start, 'end' => $end, 'tokens' => max(1, $tokens)];
    }

    /**
     * @return list<array{start:int,end:int,tokens:int}>
     */
    private static function hardSplit(int $base, string $text, int $budget): array
    {
        $step = max(1, $budget * 4);
        $slices = [];
        foreach (str_split($text, $step) as $index => $piece) {
            $start = $base + $index * $step;
            $slices[] = ['start' => $start, 'end' => $start + strlen($piece), 'tokens' => max(1, self::tokens($piece))];
        }
        return $slices;
    }

    /**
     * Whole trailing units carried into the next chunk (sentence-level overlap).
     *
     * @param list<array{start:int,end:int,tokens:int}> $current
     * @return list<array{start:int,end:int,tokens:int}>
     */
    private static function overlapCarry(array $current, int $overlapTokens): array
    {
        if ($overlapTokens <= 0 || $current === []) {
            return [];
        }
        $carry = [];
        $tokens = 0;
        foreach (array_reverse($current) as $unit) {
            $unitTokens = (int) $unit['tokens'];
            if ($tokens + $unitTokens > $overlapTokens) {
                break;
            }
            array_unshift($carry, $unit);
            $tokens += $unitTokens;
            if (count($carry) >= 12) {
                break;
            }
        }
        return $carry;
    }

    /** @param list<array{tokens:int}> $units */
    private static function sumTokens(array $units): int
    {
        $total = 0;
        foreach ($units as $unit) {
            $total += (int) $unit['tokens'];
        }
        return $total;
    }

    /**
     * @param list<array{start:int,end:int}> $units
     * @return array{text:string,token_count:int,start_offset:int,end_offset:int}
     */
    private static function range(string $src, array $units): array
    {
        $start = (int) $units[0]['start'];
        $end = (int) $units[count($units) - 1]['end'];
        $raw = substr($src, $start, max(0, $end - $start));
        if ($raw === false) {
            $raw = '';
        }
        $text = trim($raw);
        // Keep the invariant substr($src, start_offset, end_offset - start_offset) === text.
        $start += strlen($raw) - strlen(ltrim($raw));
        $end = $start + strlen($text);
        return [
            'text' => $text,
            'token_count' => self::tokens($text),
            'start_offset' => $start,
            'end_offset' => max($start, $end),
        ];
    }
}
