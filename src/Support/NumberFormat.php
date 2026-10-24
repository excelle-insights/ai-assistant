<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * Locale-aware number rendering for charts, insights and text exports.
 *
 * Dependency-free on purpose: the intl/NumberFormatter extension is not part of
 * PHP's default build, and a composer package cannot assume a host has it. The
 * small separator table below covers the locales hosts actually configure;
 * anything unknown falls back to the grouping style of the locale prefix
 * (en.* = 1,234.56) instead of throwing.
 *
 * Nothing here knows about money: a currency symbol is only ever what
 * Config('domain.currency') says, so no application bakes its own unit in.
 */
final class NumberFormat
{
    /** Value scales to a compact suffix, ascending. */
    private const SCALES = [
        [1.0e12, 'T'],
        [1.0e9, 'B'],
        [1.0e6, 'M'],
        [1.0e3, 'K'],
    ];

    /** Locales whose decimal separator is a comma (thousands: dot or space). */
    private const COMMA_DECIMAL = [
        'de', 'es', 'it', 'nl', 'pt', 'pl', 'tr', 'sl', 'hr', 'bs', 'sr', 'mk', 'bg',
        'da', 'sv', 'no', 'nb', 'nn', 'is', 'ro', 'vi', 'el', 'ka', 'hy', 'az', 'sq',
        'af', 'fr', 'fi', 'ru', 'cs', 'sk', 'hu', 'be', 'uk', 'lv', 'lt', 'et', 'kk',
    ];

    /** Comma-decimal locales that group with a space rather than a dot. */
    private const SPACE_THOUSANDS = ['fr', 'fi', 'ru', 'cs', 'sk', 'hu', 'be', 'uk', 'lv', 'lt', 'et', 'kk', 'sv', 'no', 'nb', 'nn', 'da', 'pl'];

    /** Unit words that mean "render as money" without the caller repeating the symbol. */
    private const CURRENCY_UNITS = ['currency', 'money', 'cash', 'amount', 'price', 'cost', 'revenue', 'income', 'sales'];

    /**
     * Render one number.
     *
     *   $unit            null|'number'|'count' plain grouping; 'thousands'|'k',
     *                    'millions'|'m', 'billions'|'b', 'compact' auto suffix;
     *                    'percent'|'%'; 'currency'; any other word is appended as a label.
     *   $currency        null = read Config('domain.currency'); '' = never show a symbol.
     *   $position        null = read Config('domain.currency_position'); 'before'|'prefix'
     *                    or 'after'|'suffix'.
     *   $locale          null = read Config('domain.locale').
     *
     * Non-finite floats render as an em-dash placeholder rather than "NAN", and
     * trailing zeros are trimmed so axis-sized numbers stay short.
     */
    public static function format(
        float $value,
        ?string $unit = null,
        ?string $currency = null,
        ?string $position = null,
        ?string $locale = null,
    ): string {
        if (!is_finite($value)) {
            return self::unknown();
        }

        $symbol = self::currency($currency);
        $sep = self::separators($locale);
        $key = strtolower(trim((string) $unit));

        if (in_array($key, self::CURRENCY_UNITS, true)) {
            return self::decorate(self::grouped($value, 2, $sep, true), $symbol, self::resolvePosition($position));
        }

        if ($key === 'percent' || $key === '%') {
            return self::trimZerosAtEnd(self::localize(number_format($value, 2, '.', ''), $sep), $sep['decimal']) . '%';
        }

        // An explicit scale unit is honoured even when the value is below the
        // threshold (500 with unit 'thousands' reads "0.5K"), because the caller
        // said which scale the whole column uses.
        $forced = match ($key) {
            'thousands', 'k', 'thousand' => [1.0e3, 'K'],
            'millions', 'm', 'million' => [1.0e6, 'M'],
            'billions', 'b', 'billion' => [1.0e9, 'B'],
            default => null,
        };
        if ($forced !== null) {
            return self::decorate(self::forcedScale($value, $forced[0], $forced[1], $sep), $symbol, self::resolvePosition($position));
        }

        $suffix = match ($key) {
            '', 'number', 'count', 'value', 'plain' => null,
            'compact', 'auto', 'short' => self::compactDigits($value, $sep),
            default => null,
        };


        if ($suffix !== null) {
            return self::decorate($suffix, $symbol, self::resolvePosition($position));
        }

        $text = self::trimZerosAtEnd(self::grouped($value, 2, $sep, false), $sep['decimal']);
        if ($key === '' || $key === 'number' || $key === 'count' || $key === 'value' || $key === 'plain') {
            return $text;
        }

        // A caller-supplied unit word (pcs, min, km, days) stays as a suffix label.
        return $text . ' ' . $key;
    }

    /**
     * Compact form with an automatic K/M/B/T suffix — the label size a chart axis
     * or a one-line insight needs.
     */
    public static function compact(
        float $value,
        ?string $currency = null,
        ?string $position = null,
        ?string $locale = null,
        int $decimals = 1,
    ): string {
        if (!is_finite($value)) {
            return self::unknown();
        }
        $sep = self::separators($locale);
        $symbol = self::currency($currency);

        return self::decorate(self::compactDigits($value, $sep, $decimals), $symbol, self::resolvePosition($position));
    }

    /**
     * $isRatio = true turns a 0..1 share into a percentage; otherwise the number
     * is already a percentage (42 -> "42%"). Documented because both callers exist.
     */
    public static function percent(float $value, int $decimals = 1, ?string $locale = null, bool $isRatio = false): string
    {
        if (!is_finite($value)) {
            return self::unknown();
        }
        $sep = self::separators($locale);
        $percent = $isRatio ? $value * 100.0 : $value;
        $text = self::trimZerosAtEnd(self::localize(number_format($percent, max(0, $decimals), '.', ''), $sep), $sep['decimal']);

        return $text . '%';
    }

    /** Plain grouped number, no unit, no currency. */
    public static function plain(float $value, ?int $decimals = null, ?string $locale = null): string
    {
        if (!is_finite($value)) {
            return self::unknown();
        }
        $sep = self::separators($locale);
        $dec = $decimals === null ? 2 : max(0, $decimals);

        return self::trimZerosAtEnd(self::localize(number_format($value, $dec, '.', ''), $sep), $sep['decimal']);
    }

    /** @return array{decimal:string,thousands:string} */
    public static function separators(?string $locale): array
    {
        $code = strtolower(trim((string) $locale !== '' ? (string) $locale : Config::string('domain.locale', 'en')));
        $base = self::baseLocale($code);
        if ($base === '' || !in_array($base, self::COMMA_DECIMAL, true)) {
            return ['decimal' => '.', 'thousands' => ','];
        }

        return ['decimal' => ',', 'thousands' => in_array($base, self::SPACE_THOUSANDS, true) ? ' ' : '.'];
    }

    /** Split a formatted string back into a float (locale separators tolerated). */
    public static function parse(string $text): ?float
    {
        $clean = preg_replace('/[^\d,.\-+eE]/u', '', trim($text)) ?? '';
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }

        return (float) str_replace(',', '.', $clean);
    }

    /** Configured currency symbol; '' when the host set none. */
    public static function currency(?string $currency = null): string
    {
        return $currency === null ? Config::string('domain.currency', '') : trim($currency);
    }

    /** 'before' (RSD 12) or 'after' (12 RSD), defaulting to configuration. */
    public static function position(?string $position = null): string
    {
        return self::resolvePosition($position);
    }

    /** @return array{decimal:string,thousands:string} */
    private static function grouped(float $value, int $decimals, array $sep, bool $trim): string
    {
        $text = self::localize(number_format($value, $decimals, '.', ','), $sep);

        return $trim ? self::trimZerosAtEnd($text, $sep['decimal']) : $text;
    }

    /**
     * Force a specific scale (1.2M for a value in the millions) regardless of the
     * magnitude — what a column whose unit is declared as 'millions' must show.
     *
     * @param array{decimal:string,thousands:string} $sep
     */
    private static function forcedScale(float $value, float $factor, string $suffix, array $sep): string
    {
        $text = self::trimZerosAtEnd(self::localize(number_format($value / $factor, 1, '.', ''), $sep), $sep['decimal']);

        return $text . $suffix;
    }

    /** @param array{decimal:string,thousands:string} $sep */
    private static function compactDigits(float $value, array $sep, int $decimals = 1): string
    {
        $abs = abs($value);
        foreach (self::SCALES as [$threshold, $suffix]) {
            if ($abs >= $threshold) {
                $scaled = $value / $threshold;
                // Keep two decimals when the first one would round to zero
                // (0.04M is meaningless as "0.0M").
                $dec = $decimals;
                if (abs($scaled) < 1.0 / (10 ** $decimals)) {
                    $dec = $decimals + 1;
                }

                return self::trimZerosAtEnd(self::localize(number_format($scaled, $dec, '.', ''), $sep), $sep['decimal']) . $suffix;
            }
        }

        return self::grouped($value, $decimals > 1 ? $decimals : 1, $sep, true);
    }

    /** @param array{decimal:string,thousands:string} $sep */
    private static function localize(string $number, array $sep): string
    {
        $protected = str_replace(',', "\0", $number);

        return str_replace(["\0", '.'], [$sep['thousands'], $sep['decimal']], $protected);
    }

    private static function decorate(string $body, string $symbol, string $position): string
    {
        if ($symbol === '') {
            return $body;
        }

        // 1-2 character symbols are typographic signs ($, €, ¥) and hug the
        // number; longer ISO-style codes read better separated by a space.
        $gap = mb_strlen($symbol) <= 2 && $position === 'before' ? '' : ' ';

        return $position === 'after' ? $body . $gap . $symbol : $symbol . $gap . $body;
    }

    private static function resolvePosition(?string $position): string
    {
        $raw = strtolower(trim($position ?? Config::string('domain.currency_position', 'before')));

        return match ($raw) {
            'after', 'suffix', 'post', 'right', 'trailing' => 'after',
            default => 'before',
        };
    }

    private static function trimZerosAtEnd(string $text, string $decimal): string
    {
        if (!str_contains($text, $decimal)) {
            return $text;
        }
        $trimmed = rtrim($text, '0');
        $trimmed = rtrim($trimmed, $decimal);

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    private static function baseLocale(string $code): string
    {
        if ($code === '') {
            return '';
        }
        $code = str_replace('_', '-', strtolower($code));
        $base = explode('-', $code)[0];

        return is_string($base) ? $base : '';
    }

    private static function unknown(): string
    {
        return '—';
    }
}
