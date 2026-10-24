<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * Pure text-scoring primitives shared by the knowledge and the document
 * retrievers: normalisation, tokenising, keyword coverage, cosine similarity
 * and the hybrid blend.
 *
 * Nothing here touches the database, the network or configuration: every
 * result is a deterministic function of the strings passed in, which is what
 * makes retrieval ranking testable with `php tests/knowledge.php`.
 */
final class TextScore
{
    /**
     * Words that carry no retrieval signal in any language-neutral Q&A corpus.
     * Deliberately generic — no domain vocabulary lives here.
     */
    public const STOPWORDS = [
        'what', 'are', 'the', 'you', 'your', 'do', 'does', 'did', 'is', 'a', 'an', 'of', 'for',
        'i', 'we', 'our', 'can', 'could', 'how', 'to', 'about', 'with', 'this', 'that', 'me',
        'my', 'please', 'hello', 'hi', 'and', 'or', 'in', 'on', 'at', 'from', 'there', 'their',
        'them', 'us', 'it', 'its', 'offering', 'offer', 'tell', 'give', 'show', 'list',
        'available', 'when', 'where', 'who', 'why', 'which', 'will', 'would', 'should', 'have',
        'has', 'had', 'be', 'been', 'being', 'am', 'any', 'all', 'as', 'if', 'so', 'no', 'not',
        'yes', 'than', 'then', 'into', 'of the', 'get', 'got', 'make', 'made', 'want', 'need',
    ];

    /**
     * Relative importance of a field when scoring keyword coverage. Unknown
     * field names score at 1.0 so a host with its own columns still works.
     */
    public const FIELD_WEIGHTS = [
        'question' => 3.0,
        'title' => 3.0,
        'name' => 3.0,
        'answer' => 2.0,
        'content' => 2.0,
        'text' => 2.0,
        'category' => 1.5,
        'tags' => 1.0,
        'keywords' => 1.0,
    ];

    /** Case-insensitive, whitespace-collapsed, control-character-free form of a string. */
    public static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\x00"], ["\n", "\n", ''], $text);
        $lower = self::lower($text);
        $collapsed = preg_replace('/\s+/u', ' ', $lower);
        if (!is_array($collapsed)) {
            $collapsed = preg_replace('/\s+/', ' ', $lower);
        }
        return trim((string) $collapsed);
    }

    /**
     * Search terms for a string: normalised, stopwords and 1-2 character
     * tokens dropped, plus naive singular/plural variants of each term.
     *
     * @return string[]
     */
    public static function tokenize(string $text, bool $dropStopwords = true): array
    {
        $lower = self::lower($text);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $lower);
        if (!is_array($words)) {
            $words = preg_split('/[^a-zA-Z0-9]+/', $lower) ?: [];
        }

        $out = [];
        foreach ($words as $word) {
            $word = trim((string) $word);
            if ($word === '' || strlen($word) <= 2) {
                continue;
            }
            if ($dropStopwords && in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $out[] = $word;
            foreach (self::variants($word) as $variant) {
                $out[] = $variant;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Keyword coverage in [0, 1]: the weighted share of query terms present in
     * the supplied fields. A row matching every term scores 1.0, one matching
     * a third scores roughly a third.
     *
     * @param string[] $queryTerms
     * @param array<string,string> $fields field name => text
     */
    public static function keywordScore(array $queryTerms, array $fields, string $wholeQuery = ''): float
    {
        $terms = array_values(array_filter(array_map(
            static fn ($t) => self::lower(trim((string) $t)),
            $queryTerms
        ), static fn ($t) => $t !== ''));

        if ($terms === [] || $fields === []) {
            return 0.0;
        }

        $totalWeight = 0.0;
        $earned = 0.0;

        foreach ($fields as $name => $text) {
            $text = (string) $text;
            if (trim($text) === '') {
                continue;
            }
            $weight = self::FIELD_WEIGHTS[self::lower((string) $name)] ?? 1.0;
            $haystack = array_flip(self::tokenize($text, false));
            $hits = 0;
            foreach ($terms as $term) {
                if (isset($haystack[$term])) {
                    $hits++;
                }
            }
            $earned += $weight * min(1.0, $hits / count($terms));
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        $score = $earned / $totalWeight;

        // Exact-phrase bonus: the whole question appears verbatim somewhere.
        $needle = self::normalize($wholeQuery);
        if ($needle !== '' && strlen($needle) > 5) {
            foreach ($fields as $text) {
                if (str_contains(self::normalize((string) $text), $needle)) {
                    $score = min(1.0, $score + 0.15);
                    break;
                }
            }
        }

        return round(max(0.0, min(1.0, $score)), 6);
    }

    /** Cosine similarity of two vectors; 0.0 when either is unusable. */
    public static function cosine(array $a, array $b): float
    {
        if ($a === [] || $b === [] || count($a) !== count($b)) {
            return 0.0;
        }
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $x) {
            $x = (float) $x;
            $y = (float) $b[$i];
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }
        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }
        return round(max(0.0, min(1.0, $dot / (sqrt($normA) * sqrt($normB)))), 6);
    }

    /**
     * Hybrid blend. A null vector score means "no embedding available" — the
     * keyword score is then used alone, so retrieval still works with no LLM.
     */
    public static function blend(?float $vectorScore, float $keywordScore, float $vectorWeight): float
    {
        $vectorWeight = max(0.0, min(1.0, $vectorWeight));
        if ($vectorScore === null) {
            return round(max(0.0, min(1.0, $keywordScore)), 6);
        }
        return round(max(0.0, min(1.0, $vectorWeight * $vectorScore + (1 - $vectorWeight) * $keywordScore)), 6);
    }

    /** Decode a stored embedding (JSON text or already-decoded list) to float[]. */
    public static function vectorOf(mixed $embedding): ?array
    {
        if ($embedding === null) {
            return null;
        }
        if (is_array($embedding)) {
            $vector = $embedding;
        } else {
            $string = trim((string) $embedding);
            if ($string === '') {
                return null;
            }
            $decoded = json_decode($string, true);
            $vector = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($vector) || $vector === []) {
            return null;
        }
        $out = [];
        foreach (array_values($vector) as $value) {
            if (!is_numeric($value)) {
                return null;
            }
            $out[] = (float) $value;
        }
        return $out === [] ? null : $out;
    }

    public static function lower(string $text): string
    {
        return function_exists('mb_strtolower') ? (string) mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    /** @return string[] naive morphological variants of one term */
    private static function variants(string $word): array
    {
        $out = [];
        if (str_ends_with($word, 'ies') && strlen($word) > 4) {
            $out[] = substr($word, 0, -3) . 'y';
        } elseif (str_ends_with($word, 'es') && strlen($word) > 3) {
            $out[] = substr($word, 0, -2);
        } elseif (str_ends_with($word, 's') && strlen($word) > 3) {
            $out[] = substr($word, 0, -1);
        }
        return $out;
    }
}
