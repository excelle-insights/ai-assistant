<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * Resolves the relative periods users actually type ("this quarter", "last 30
 * days", "since January") into concrete timestamps, in the host's configured
 * timezone so "today" means the same thing to the assistant as to the CRM.
 */
final class DateRange
{
    /**
     * @param array<string|int,mixed> $range  ['preset'=>..] | ['from'=>..,'to'=>..] | ['last_days'=>30]
     * @return array{from?:string,to?:string,label:string}
     */
    public static function resolve(array $range, ?\DateTimeZone $tz = null): array
    {
        $tz ??= new \DateTimeZone(Config::string('timezone', 'UTC') ?: 'UTC');
        $now = new \DateTimeImmutable('now', $tz);

        // Explicit bounds always win.
        $from = self::stringOf($range, ['from', 'start', 'date_from', '0']);
        $to = self::stringOf($range, ['to', 'end', 'date_to', '1']);
        if ($from !== '' || $to !== '') {
            return array_filter([
                'from' => $from !== '' ? self::bound($from, $tz, false) : null,
                'to' => $to !== '' ? self::bound($to, $tz, true) : null,
                'label' => trim($from . ($to !== '' ? ' → ' . $to : '')),
            ], fn ($v) => $v !== null && $v !== '');
        }

        $preset = strtolower(trim((string) self::stringOf($range, ['preset', 'period', 'range', 'relative', 'label'])));

        if ($days = self::daysOf($range, $preset)) {
            return [
                'from' => $now->modify('-' . ($days - 1) . ' days')->setTime(0, 0)->format('Y-m-d H:i:s'),
                'to' => $now->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
                'label' => 'last ' . $days . ' days',
            ];
        }

        return match ($preset) {
            'today' => ['from' => $now->setTime(0, 0)->format('Y-m-d H:i:s'), 'to' => $now->setTime(23, 59, 59)->format('Y-m-d H:i:s'), 'label' => 'today'],
            'yesterday' => ['from' => $now->modify('-1 day')->setTime(0, 0)->format('Y-m-d H:i:s'), 'to' => $now->modify('-1 day')->setTime(23, 59, 59)->format('Y-m-d H:i:s'), 'label' => 'yesterday'],
            'this_week', 'week' => self::week($now, 0),
            'last_week' => self::week($now, -1),
            'this_month', 'month' => self::month($now, 0),
            'last_month' => self::month($now, -1),
            'this_quarter', 'quarter' => self::quarter($now, 0),
            'last_quarter' => self::quarter($now, -1),
            'this_year', 'year', 'ytd' => ['from' => $now->modify('first day of january')->setTime(0, 0)->format('Y-m-d H:i:s'), 'to' => $now->setTime(23, 59, 59)->format('Y-m-d H:i:s'), 'label' => 'this year'],
            'last_year' => ['from' => $now->modify('-1 year')->modify('first day of january')->setTime(0, 0)->format('Y-m-d H:i:s'), 'to' => $now->modify('-1 year')->modify('last day of december')->setTime(23, 59, 59)->format('Y-m-d H:i:s'), 'label' => 'last year'],
            'mtd' => ['from' => $now->modify('first day of this month')->setTime(0, 0)->format('Y-m-d H:i:s'), 'to' => $now->setTime(23, 59, 59)->format('Y-m-d H:i:s'), 'label' => 'month to date'],
            'all', 'all_time', '' => ['label' => 'all time'],
            default => ['label' => $preset],
        };
    }

    /** "last 7 days" / "past 30 days" / "3m" — the phrasings a model will emit. */
    public static function presetFromText(string $text): string
    {
        $t = strtolower($text);
        if (preg_match('/\b(last|past|previous)\s+(\d+|one|two|three|four|five|six|seven|eight|nine|ten|twelve)\s+(day|days|week|weeks|month|months|quarter|quarters|year|years)\b/', $t, $m)) {
            $n = self::wordNumber($m[2]);
            $unit = rtrim($m[3], 's');
            return match ($unit) {
                'day' => 'last_' . $n . '_days',
                'week' => 'last_' . $n . '_weeks',
                'month' => 'last_' . $n . '_months',
                'quarter' => 'last_' . $n . '_quarters',
                default => 'last_' . $n . '_years',
            };
        }
        foreach (['this quarter' => 'this_quarter', 'last quarter' => 'last_quarter', 'this month' => 'this_month', 'last month' => 'last_month', 'this week' => 'this_week', 'last week' => 'last_week', 'this year' => 'this_year', 'last year' => 'last_year', 'year to date' => 'ytd', 'month to date' => 'mtd', 'today' => 'today', 'yesterday' => 'yesterday'] as $needle => $preset) {
            if (str_contains($t, $needle)) {
                return $preset;
            }
        }
        return '';
    }

    private static function daysOf(array $range, string $preset): int
    {
        foreach (['last_days', 'days', 'last_days_count'] as $key) {
            if (isset($range[$key]) && is_numeric($range[$key])) {
                return max(1, (int) $range[$key]);
            }
        }
        if (preg_match('/^last_(\d+)_days$/', $preset, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^last_(\d+)_weeks$/', $preset, $m)) {
            return (int) $m[1] * 7;
        }
        if (preg_match('/^last_(\d+)_months$/', $preset, $m)) {
            return (int) $m[1] * 30;
        }
        if (preg_match('/^last_(\d+)_quarters$/', $preset, $m)) {
            return (int) $m[1] * 91;
        }
        if (preg_match('/^last_(\d+)_years$/', $preset, $m)) {
            return (int) $m[1] * 365;
        }
        return 0;
    }

    private static function week(\DateTimeImmutable $now, int $offset): array
    {
        $start = $now->modify('monday this week')->modify($offset . ' weeks')->setTime(0, 0);
        $end = $start->modify('+6 days')->setTime(23, 59, 59);
        return ['from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s'), 'label' => $offset === 0 ? 'this week' : 'last week'];
    }

    private static function month(\DateTimeImmutable $now, int $offset): array
    {
        $start = $now->modify('first day of this month')->modify($offset . ' months')->setTime(0, 0);
        $end = $start->modify('last day of this month')->setTime(23, 59, 59);
        return ['from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s'), 'label' => $offset === 0 ? 'this month' : 'last month'];
    }

    private static function quarter(\DateTimeImmutable $now, int $offset): array
    {
        $q = (int) $now->format('n');
        $quarterStart = ((int) ceil($q / 3) - 1) * 3 + 1;
        $start = $now->setDate((int) $now->format('Y'), $quarterStart, 1)->setTime(0, 0);
        if ($offset !== 0) {
            $start = $start->modify(($offset * 3) . ' months');
        }
        $end = $start->modify('+2 months')->modify('last day of this month')->setTime(23, 59, 59);
        return ['from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s'), 'label' => $offset === 0 ? 'this quarter' : 'last quarter'];
    }

    private static function bound(string $value, \DateTimeZone $tz, bool $endOfDay): ?string
    {
        try {
            $date = new \DateTimeImmutable(trim($value), $tz);
        } catch (\Throwable) {
            return null;
        }
        $hasTime = (bool) preg_match('/\d{1,2}:\d{2}/', $value);
        return $hasTime ? $date->format('Y-m-d H:i:s') : $date->setTime($endOfDay ? 23 : 0, $endOfDay ? 59 : 0, $endOfDay ? 59 : 0)->format('Y-m-d H:i:s');
    }

    private static function stringOf(array $range, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($range[$key]) && is_scalar($range[$key]) && trim((string) $range[$key]) !== '') {
                return (string) $range[$key];
            }
        }
        return '';
    }

    private static function wordNumber(string $word): int
    {
        return (int) match (strtolower($word)) {
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
            'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
            'twelve' => 12, default => (int) $word,
        };
    }
}
