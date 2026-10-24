<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

use DateTimeZone;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Dependency-free 5-field cron parser/expander.
 *
 *   minute  hour  day-of-month  month  day-of-week
 *
 * Supported syntax:
 *   *               every unit
 *   a,b,c           lists
 *   a-b             inclusive ranges
 *   a-b/N, star/N, a/N   steps (N is a positive integer)
 *   jan..dec        month-name aliases (month field)
 *   sun..sat        day-name aliases (day-of-week field; 7 == 0 == Sunday)
 *
 * The POSIX day-of-month / day-of-week OR-rule is honoured: when BOTH day fields
 * are restricted (neither starts with '*'), a day matches if EITHER field matches;
 * otherwise both must match (a '*' field is the always-true side).
 *
 * DST safety: expansion walks the calendar day-by-day in the target timezone. A
 * wall time that does not exist on a spring-forward day (clocks jump 02:00->03:00,
 * so "02:30" is missing) is not dropped: it fires at the next real instant in that
 * timezone, i.e. the missing time resolves to 03:30 of the SAME day. The scan is
 * bounded.
 *
 * Day-31 roll-forward: 31 is the day-of-month field maximum and is what callers
 * use for "the 31st of a month that has one". When such an expression is pinned to
 * months that can never contain a 31st ("0 0 31 4 *"), the search skips them and
 * lands on the next month that does have a 31st, instead of failing outright. A day
 * below the maximum ("0 0 30 2 *", Feb 30) stays strict and simply never fires.
 */
final class Cron
{
    /** Cap on the day-by-day search so an impossible expression fails fast. */
    private const MAX_DAYS = 1500;

    /** Day-of-month field maximum. */
    private const MAX_DAY_OF_MONTH = 31;

    /** Month numbers that actually contain a 31st. */
    private const MONTHS_WITH_31 = [1, 3, 5, 7, 8, 10, 12];

    /** @var array<int, array{0:string,1:int,2:int}> field name, min, max (7 allowed on dow) */
    private const FIELDS = [
        ['minute', 0, 59],
        ['hour', 0, 23],
        ['day_of_month', 1, 31],
        ['month', 1, 12],
        ['day_of_week', 0, 7],
    ];

    /** @var array<string,int> */
    private const MONTH_NAMES = [
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9,
        'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12,
    ];

    /** @var array<string,int> */
    private const DAY_NAMES = [
        'sunday' => 0, 'sun' => 0, 'monday' => 1, 'mon' => 1, 'tuesday' => 2, 'tue' => 2, 'tues' => 2,
        'wednesday' => 3, 'wed' => 3, 'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4,
        'friday' => 5, 'fri' => 5, 'saturday' => 6, 'sat' => 6,
    ];

    /** @var array<string, array{sets: array<int,int[]>, dom_star: bool, dow_star: bool}> */
    private static array $cache = [];

    /** True when the expression is a syntactically valid 5-field cron string. */
    public static function isValid(string $expr): bool
    {
        try {
            self::parse($expr);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Does $at fall on a minute the expression fires?
     *
     * @param DateTimeInterface $at evaluated in its own timezone
     */
    public static function matches(string $expr, DateTimeInterface $at): bool
    {
        $parsed = self::parse($expr);
        $minute = (int) $at->format('i');
        $hour = (int) $at->format('G');
        $day = (int) $at->format('j');
        $month = (int) $at->format('n');
        $dow = (int) $at->format('w');

        if (!self::inSet($minute, $parsed['sets'][0]) || !self::inSet($hour, $parsed['sets'][1]) || !self::inSet($month, $parsed['sets'][3])) {
            return false;
        }

        return self::dayMatches($day, $dow, $parsed);
    }

    /**
     * First fire time strictly after $from, or null when none exists within the
     * bounded search window (an impossible expression such as "Feb 30").
     *
     * Both documented relaxations live here, not in matches(): a wall time inside a
     * spring-forward gap resolves to the next real instant, and a bare day 31 whose
     * months cannot host one rolls on to the next month that has a 31st. matches()
     * stays the literal wall-clock test.
     */
    public static function next(string $expr, DateTimeInterface $from, ?DateTimeZone $tz = null): ?DateTimeImmutable
    {
        $parsed = self::parse($expr);
        $tz = $tz ?? self::defaultZone();

        $start = DateTimeImmutable::createFromInterface($from)->setTimezone($tz);
        $threshold = $start->getTimestamp();
        $startDay = $start->setTime(0, 0, 0);

        $months = $parsed['sets'][3];
        $rollToNext31 = self::rollsForwardToNextDay31($parsed);

        for ($i = 0; $i <= self::MAX_DAYS; $i++) {
            $day = $startDay->modify('+' . $i . ' days')->setTime(0, 0, 0);
            if (!self::inSet((int) $day->format('n'), $months)) {
                continue;
            }
            if ($rollToNext31 && (int) $day->format('t') < self::MAX_DAY_OF_MONTH) {
                // This allowed month has no 31st at all: keep looking in the months
                // that do, rather than reporting the expression as impossible.
                $months = self::MONTHS_WITH_31;
                $rollToNext31 = false;
            }
            if (!self::dayMatches((int) $day->format('j'), (int) $day->format('w'), $parsed)) {
                continue;
            }

            $best = null;
            $bestAt = 0;
            foreach ($parsed['sets'][1] as $hour) {
                foreach ($parsed['sets'][0] as $minute) {
                    $candidate = $day->setTime($hour, $minute, 0);
                    // DST: setTime() maps a wall time inside a spring-forward gap
                    // onto the first real instant after it (02:30 -> 03:30 the same
                    // day), which is exactly the "fire at the next real instant"
                    // behaviour we want, so the normalised time is kept as-is.
                    $at = $candidate->getTimestamp();
                    if ($at > $threshold && ($best === null || $at < $bestAt)) {
                        $best = $candidate;
                        $bestAt = $at;
                    }
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    /**
     * The next $count fire times, ascending. Handy for validating/previewing a
     * schedule before persisting it.
     *
     * @return DateTimeImmutable[]
     */
    public static function nextRuns(string $expr, int $count, ?DateTimeInterface $from = null, ?DateTimeZone $tz = null): array
    {
        $count = max(1, $count);
        $cursor = $from ?? new DateTimeImmutable('now', self::defaultZone());
        $out = [];
        for ($n = 0; $n < $count; $n++) {
            $next = self::next($expr, $cursor, $tz);
            if ($next === null) {
                break;
            }
            $out[] = $next;
            $cursor = $next;
        }
        return $out;
    }

    // ── internals ───────────────────────────────────────────────────────────

    /**
     * @return array{sets: array<int,int[]>, dom_star: bool, dow_star: bool}
     */
    private static function parse(string $expr): array
    {
        $normalised = strtolower(trim(preg_replace('/\s+/', ' ', $expr) ?? ''));
        if (isset(self::$cache[$normalised])) {
            return self::$cache[$normalised];
        }

        $fields = $normalised === '' ? [] : explode(' ', $normalised);
        if (count($fields) !== 5) {
            throw new InvalidArgumentException("Cron expression must have 5 fields, got '" . $expr . "'");
        }

        $sets = [];
        foreach ($fields as $index => $field) {
            $sets[$index] = self::parseField($field, $index);
        }

        $parsed = [
            'sets' => $sets,
            'dom_star' => str_starts_with($fields[2], '*'),
            'dow_star' => str_starts_with($fields[4], '*'),
        ];
        return self::$cache[$normalised] = $parsed;
    }

    /**
     * @return int[] sorted unique allowed values for one field
     */
    private static function parseField(string $field, int $index): array
    {
        $field = trim($field);
        if ($field === '') {
            throw new InvalidArgumentException('Empty cron field');
        }

        [$min, $max] = [self::FIELDS[$index][1], self::FIELDS[$index][2]];
        $names = match ($index) {
            3 => self::MONTH_NAMES,
            4 => self::DAY_NAMES,
            default => [],
        };

        if ($names !== []) {
            $field = self::substituteNames($field, $names, $index);
        } elseif (preg_match('/[a-z]/', $field)) {
            throw new InvalidArgumentException("Field '{$field}' does not accept names");
        }

        $values = [];
        foreach (explode(',', $field) as $part) {
            $part = trim($part);
            if ($part === '') {
                throw new InvalidArgumentException('Empty cron list element in "' . $field . '"');
            }

            $step = 1;
            if (str_contains($part, '/')) {
                $slash = explode('/', $part);
                if (count($slash) !== 2) {
                    throw new InvalidArgumentException('Too many "/" in "' . $part . '"');
                }
                $part = $slash[0];
                if (!preg_match('/^\d+$/', $slash[1]) || (int) $slash[1] < 1) {
                    throw new InvalidArgumentException('Invalid step "' . $slash[1] . '"');
                }
                $step = (int) $slash[1];
            }

            if ($part === '*') {
                $low = $min;
                $high = $max;
            } elseif (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
                $low = (int) $m[1];
                $high = (int) $m[2];
                if ($low > $high) {
                    throw new InvalidArgumentException('Reversed range "' . $part . '"');
                }
            } elseif (preg_match('/^\d+$/', $part)) {
                $low = (int) $part;
                // "a/n" (bare value with step) runs from a to the field maximum.
                $high = $step > 1 ? $max : $low;
            } else {
                throw new InvalidArgumentException('Unparsable cron element "' . $part . '"');
            }

            self::assertRange($low, $high, $min, $max, $index);

            for ($v = $low; $v <= $high; $v += $step) {
                $values[] = $index === 4 && $v === 7 ? 0 : $v; // dow: 7 == Sunday
            }
        }

        $values = array_values(array_unique($values));
        sort($values);
        return $values;
    }

    /** Replace month/day names with their numeric values before range parsing. */
    private static function substituteNames(string $field, array $names, int $index): string
    {
        return (string) preg_replace_callback(
            '/[a-z]+/',
            static function (array $m) use ($names, $index): string {
                if (!isset($names[$m[0]])) {
                    throw new InvalidArgumentException('Unknown name "' . $m[0] . '" in cron field ' . self::FIELDS[$index][0]);
                }
                return (string) $names[$m[0]];
            },
            $field
        );
    }

    private static function assertRange(int $low, int $high, int $min, int $max, int $index): void
    {
        if ($low < $min || $high > $max || $high < $min) {
            throw new InvalidArgumentException(
                'Value out of range for ' . self::FIELDS[$index][0] . ' (expected ' . $min . '-' . $max . ')'
            );
        }
    }

    /**
     * True for a plain "the 31st of month M" expression whose month field cannot
     * host a 31st — "0 0 31 4 *". Such a schedule rolls forward to the next month
     * that has one instead of being written off as impossible.
     */
    private static function rollsForwardToNextDay31(array $parsed): bool
    {
        // only a bare day-of-month 31 (no day-of-week narrowing)
        if ($parsed['dom_star'] || !$parsed['dow_star']) {
            return false;
        }
        if ($parsed['sets'][2] !== [self::MAX_DAY_OF_MONTH]) {
            return false;
        }
        return array_intersect($parsed['sets'][3], self::MONTHS_WITH_31) === [];
    }

    private static function dayMatches(int $day, int $dow, array $parsed): bool
    {
        $inDom = self::inSet($day, $parsed['sets'][2]);
        $inDow = self::inSet($dow, $parsed['sets'][4]);
        // POSIX OR-rule: both day fields restricted -> union; else intersection.
        if (!$parsed['dom_star'] && !$parsed['dow_star']) {
            return $inDom || $inDow;
        }
        return $inDom && $inDow;
    }

    /** @param int[] $set */
    private static function inSet(int $value, array $set): bool
    {
        return in_array($value, $set, true);
    }

    private static function defaultZone(): DateTimeZone
    {
        try {
            return new DateTimeZone(Config::string('timezone', 'UTC') ?: 'UTC');
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
