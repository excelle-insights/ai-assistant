<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use PDO;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;

/**
 * Per-tenant / per-user daily rollups ({prefix}_usage_daily) plus quota
 * enforcement. Split into two concerns:
 *
 *   record()/today()/range()/byUser()/topQuestions()  -> reporting rollups
 *   checkLimit()/consume()                            -> request quotas
 *
 * Quota storage: minute/hour windows need a per-bucket counter table. That table
 * ({prefix}_rate_limits) is OPTIONAL — when it is absent the service degrades to
 * a NON-PERSISTENT, per-request in-memory counter (documented below) so callers
 * never crash. day/month windows read the authoritative {prefix}_usage_daily.
 *
 * Nothing here throws on the write path: the chat flow calls record()/consume(),
 * so failures are caught and routed to an optional onError callback.
 */
class UsageService
{
    /** Counter columns of {prefix}_usage_daily (int), plus decimal cost. */
    private const COUNTERS = ['chats', 'tokens_in', 'tokens_out', 'documents', 'exports', 'actions', 'errors', 'unanswered'];

    /**
     * Input aliases folded onto a counter. The provider token names are here because
     * the runtime hands the `usage` hook exactly what the model returned, and a
     * rollup that silently recorded nothing is worse than one that is wordy.
     */
    private const ALIASES = [
        'chat' => 'chats', 'document' => 'documents', 'export' => 'exports',
        'action' => 'actions', 'error' => 'errors', 'unanswered' => 'unanswered',
        'token_in' => 'tokens_in', 'token_out' => 'tokens_out',
        'prompt_tokens' => 'tokens_in', 'completion_tokens' => 'tokens_out',
        'input_tokens' => 'tokens_in', 'output_tokens' => 'tokens_out',
    ];

    /** @var array<string,int> transient non-persistent counters (fallback store) */
    private array $memory = [];

    /** @var callable(Throwable):void|null */
    private $onError;

    /**
     * @param callable(Throwable):void|null $onError
     */
    public function __construct(private ?PDO $pdo = null, ?callable $onError = null)
    {
        $this->onError = $onError;
    }

    // ── rollups ──────────────────────────────────────────────────────────────

    /**
     * Increment the daily rollup. Keys: company_id|tenant_id, user_id, stat_date,
     * then counters (chats/tokens_in/tokens_out/cost/documents/exports/actions/
     * errors/unanswered) or their singular aliases. Never throws.
     *
     * @param array<string,mixed> $usage
     */
    public function record(array $usage): void
    {
        try {
            $row = $this->normalizeUsage($usage);
            if ($this->pdo !== null) {
                $this->upsertRow($row);
            }
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    /** Today's rollup for a tenant (optionally a single user). */
    public function today(int $companyId, ?int $userId = null): array
    {
        if ($this->pdo === null) {
            return $this->zeroUsage($companyId, $userId);
        }
        $today = $this->todayDate();
        $rows = $userId === null
            ? $this->range($companyId, $today, $today, null)
            : $this->fetchRow($companyId, $today, (string) $userId);
        return $rows;
    }

    /**
     * Summed rollup over a date range, optionally scoped to one user.
     *
     * @return array<string,mixed>
     */
    public function range(int $companyId, string $from, string $to, ?int $userId = null): array
    {
        if ($this->pdo === null) {
            return $this->zeroUsage($companyId, $userId);
        }
        try {
            $table = Db::requireTable($this->pdo, 'usage_daily');
            $sql = 'SELECT COALESCE(SUM(`chats`),0) AS chats, COALESCE(SUM(`tokens_in`),0) AS tokens_in,'
                . ' COALESCE(SUM(`tokens_out`),0) AS tokens_out, COALESCE(SUM(`cost`),0) AS cost,'
                . ' COALESCE(SUM(`documents`),0) AS documents, COALESCE(SUM(`exports`),0) AS exports,'
                . ' COALESCE(SUM(`actions`),0) AS actions, COALESCE(SUM(`errors`),0) AS errors,'
                . ' COALESCE(SUM(`unanswered`),0) AS unanswered'
                . ' FROM `' . $table . '` WHERE `company_id` = :c AND `stat_date` BETWEEN :from AND :to';
            $bind = ['c' => $companyId, 'from' => $this->day($from), 'to' => $this->day($to)];
            if ($userId !== null) {
                $sql .= ' AND `user_id` = :u';
                $bind['u'] = (string) $userId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bind);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return $this->shapeTotals($companyId, $row);
        } catch (Throwable $e) {
            $this->report($e);
            return $this->zeroUsage($companyId, $userId);
        }
    }

    /**
     * Per-user totals over a range, newest/highest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function byUser(int $companyId, string $from, string $to, int $limit = 50): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $table = Db::requireTable($this->pdo, 'usage_daily');
            $sql = 'SELECT `user_id`, COALESCE(SUM(`chats`),0) AS chats, COALESCE(SUM(`tokens_in`),0) AS tokens_in,'
                . ' COALESCE(SUM(`tokens_out`),0) AS tokens_out, COALESCE(SUM(`cost`),0) AS cost,'
                . ' COALESCE(SUM(`errors`),0) AS errors'
                . ' FROM `' . $table . '` WHERE `company_id` = :c AND `stat_date` BETWEEN :from AND :to'
                . ' GROUP BY `user_id` ORDER BY chats DESC LIMIT ' . max(1, $limit);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['c' => $companyId, 'from' => $this->day($from), 'to' => $this->day($to)]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->report($e);
            return [];
        }
    }

    /**
     * Most-asked questions from the audit trail (needs the {prefix}_audit table).
     *
     * @return array<int,array<string,mixed>>
     */
    public function topQuestions(int $companyId, string $from, string $to, int $limit = 20): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $table = Db::requireTable($this->pdo, 'audit');
            $sql = 'SELECT `question`, `query_hash`, COUNT(*) AS n'
                . ' FROM `' . $table . '` WHERE `company_id` = :c AND `question` IS NOT NULL'
                . ' AND `created_at` BETWEEN :from AND :to'
                . ' GROUP BY `question`, `query_hash` ORDER BY n DESC LIMIT ' . max(1, $limit);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'c' => $companyId,
                'from' => $this->toStart($from),
                'to' => $this->toEnd($to),
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->report($e);
            return [];
        }
    }

    // ── quota enforcement ──────────────────────────────────────────────────

    /**
     * Is one more request allowed? Reads the current usage for the active bucket
     * and applies the configured limit for the window. Never throws.
     *
     * @return array{allowed:bool,used:int,limit:int,reset_at:string}
     */
    public function checkLimit(string $dimension, string $key, string $window, ?int $overrideMax = null): array
    {
        $window = $this->normaliseWindow($window);
        $now = $this->now();
        try {
            $used = $this->readUsed($dimension, $key, $window, $now);
        } catch (Throwable $e) {
            $this->report($e);
            $used = 0;
        }
        return $this->limitMath($dimension, $window, $used, $overrideMax, $now);
    }

    /**
     * Register one (or $amount) consumed request(s) and return the resulting
     * limit state. Increments the transient counter; for day/month on a
     * company/user dimension it also relies on the {prefix}_usage_daily rollup.
     *
     * @return array{allowed:bool,used:int,limit:int,reset_at:string}
     */
    public function consume(string $dimension, string $key, string $window, ?int $overrideMax = null, int $amount = 1): array
    {
        $window = $this->normaliseWindow($window);
        $now = $this->now();
        try {
            $this->incrementUsed($dimension, $key, $window, $now, max(1, $amount));
            $used = $this->readUsed($dimension, $key, $window, $now);
        } catch (Throwable $e) {
            $this->report($e);
            $used = $amount;
        }
        return $this->limitMath($dimension, $window, $used, $overrideMax, $now);
    }

    // ── pure logic (no PDO) — unit-test surface ──────────────────────────────

    /**
     * Fold a raw usage payload onto the canonical counter row. Pure.
     *
     * @param array<string,mixed> $usage
     * @return array<string,mixed>
     */
    public function normalizeUsage(array $usage): array
    {
        $companyId = (int) ($usage['company_id'] ?? $usage['tenant_id'] ?? 0);
        $userId = (string) ($usage['user_id'] ?? $usage['userId'] ?? '');
        $statDate = isset($usage['stat_date']) && $usage['stat_date'] !== ''
            ? $this->day((string) $usage['stat_date'])
            : $this->todayDate();

        $counters = array_fill_keys(self::COUNTERS, 0);
        foreach (self::COUNTERS as $column) {
            if (isset($usage[$column]) && is_numeric($usage[$column])) {
                $counters[$column] = max(0, (int) $usage[$column]);
            }
        }
        foreach (self::ALIASES as $alias => $column) {
            if (isset($usage[$alias]) && is_numeric($usage[$alias])) {
                $counters[$column] += max(0, (int) $usage[$alias]);
            }
        }

        return [
            'company_id' => $companyId,
            'user_id' => $userId,
            'stat_date' => $statDate,
            'cost' => round(max(0.0, (float) ($usage['cost'] ?? 0)), 6),
            'counters' => $counters,
            // latency is captured for reference only; {prefix}_usage_daily has no
            // column for it (see report — needs latency_ms_sum / latency_count).
            'latency_ms' => isset($usage['latency_ms']) ? (int) $usage['latency_ms'] : null,
        ];
    }

    /**
     * Bucket id + window start + reset instant for a window. Pure & testable.
     *
     * @return array{bucket:string,window_start:string,reset_at:string}
     */
    public function windowBounds(string $window, DateTimeInterface $now): array
    {
        $tz = $this->tz();
        $d = DateTimeImmutable::createFromInterface($now)->setTimezone($tz);
        $resetFormat = 'Y-m-d H:i:s';

        return match ($window) {
            'minute' => [
                'bucket' => $d->format('YmdHi'),
                'window_start' => $d->setTime((int) $d->format('G'), (int) $d->format('i'), 0)->format($resetFormat),
                'reset_at' => $d->setTime((int) $d->format('G'), (int) $d->format('i'), 0)->modify('+1 minute')->format($resetFormat),
            ],
            'month' => [
                'bucket' => $d->format('Ym'),
                'window_start' => $d->setDate((int) $d->format('Y'), (int) $d->format('n'), 1)->setTime(0, 0, 0)->format($resetFormat),
                'reset_at' => $d->setDate((int) $d->format('Y'), (int) $d->format('n'), 1)->setTime(0, 0, 0)->modify('+1 month')->format($resetFormat),
            ],
            'hour' => [
                'bucket' => $d->format('YmdH'),
                'window_start' => $d->setTime((int) $d->format('G'), 0, 0)->format($resetFormat),
                'reset_at' => $d->setTime((int) $d->format('G'), 0, 0)->modify('+1 hour')->format($resetFormat),
            ],
            default => [ // day
                'bucket' => $d->format('Y-m-d'),
                'window_start' => $d->setTime(0, 0, 0)->format($resetFormat),
                'reset_at' => $d->setTime(0, 0, 0)->modify('+1 day')->format($resetFormat),
            ],
        };
    }

    /**
     * The quota decision itself, given an already-read `used` count. Pure &
     * testable (a fake can drive `used`). limit <= 0 means unlimited.
     *
     * @return array{allowed:bool,used:int,limit:int,reset_at:string}
     */
    public function limitMath(string $dimension, string $window, int $used, ?int $overrideMax, DateTimeInterface $now): array
    {
        $max = $overrideMax !== null ? max(0, (int) $overrideMax) : $this->configuredLimit($dimension, $window);
        $bounds = $this->windowBounds($window, $now);
        if ($max <= 0) {
            return ['allowed' => true, 'used' => max(0, $used), 'limit' => 0, 'reset_at' => $bounds['reset_at']];
        }
        return [
            'allowed' => $used < $max,
            'used' => max(0, $used),
            'limit' => $max,
            'reset_at' => $bounds['reset_at'],
        ];
    }

    /** Resolve the configured max for a dimension+window (0/negative = unlimited). */
    public function configuredLimit(string $dimension, string $window): int
    {
        $dimension = strtolower($dimension);
        // Per-dimension override first, then the generic rate_limit.* for the window.
        $paths = match ($window) {
            'minute' => ["rate_limit.{$dimension}_per_minute", "rate_limit.{$dimension}_per_min", 'rate_limit.per_minute'],
            'hour' => ["rate_limit.{$dimension}_per_hour", 'rate_limit.per_hour'],
            'month' => ["rate_limit.{$dimension}_per_month", 'rate_limit.per_month'],
            default => ["rate_limit.{$dimension}_per_day", 'rate_limit.per_day'],
        };
        foreach ($paths as $path) {
            $value = Config::get($path, null);
            if ($value !== null && is_numeric($value)) {
                return (int) $value;
            }
        }
        return 0;
    }

    /** True when the optional {prefix}_rate_limits table is available. */
    public function hasPersistentCounter(): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        try {
            return Db::tableExists($this->pdo, 'rate_limits')
                && count(array_intersect(['dimension', 'scope_key', 'window', 'bucket', 'count'], Db::columnsOf($this->pdo, Db::table('rate_limits')))) >= 5;
        } catch (Throwable) {
            return false;
        }
    }

    // ── storage seams ──────────────────────────────────────────────────────

    /**
     * Read the current count for the active bucket. day/month on company/user
     * come from {prefix}_usage_daily; transient windows use {prefix}_rate_limits
     * when present, otherwise the in-memory fallback (non-persistent).
     */
    protected function readUsed(string $dimension, string $key, string $window, DateTimeInterface $now): int
    {
        $bounds = $this->windowBounds($window, $now);

        // Authoritative daily/monthly quota from the rollup table.
        if ($this->pdo !== null && ($window === 'day' || $window === 'month') && in_array($dimension, ['company', 'user'], true)) {
            return $this->rollupCount($dimension, $key, $window, $now);
        }

        if ($this->hasPersistentCounter()) {
            $sql = 'SELECT `count` FROM `' . Db::table('rate_limits') . '`'
                . ' WHERE `dimension` = :d AND `scope_key` = :k AND `window` = :w AND `bucket` = :b LIMIT 1';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['d' => $dimension, 'k' => $key, 'w' => $window, 'b' => $bounds['bucket']]);
            return (int) $stmt->fetchColumn();
        }

        // Non-persistent fallback (documented): per-process memory only.
        return $this->memory[$this->memoryKey($dimension, $key, $bounds['bucket'])] ?? 0;
    }

    /** Bump the count in the active bucket by $amount. */
    protected function incrementUsed(string $dimension, string $key, string $window, DateTimeInterface $now, int $amount): void
    {
        $bounds = $this->windowBounds($window, $now);

        if ($this->hasPersistentCounter()) {
            $this->upsertCounter($dimension, $key, $window, $bounds['bucket'], $bounds['reset_at'], $amount);
            return;
        }
        $mapKey = $this->memoryKey($dimension, $key, $bounds['bucket']);
        $this->memory[$mapKey] = ($this->memory[$mapKey] ?? 0) + $amount;
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * Atomic incrementing upsert into {prefix}_usage_daily keyed by
     * (company_id, stat_date, user_id) — the table's primary key.
     *
     * @param array<string,mixed> $row from normalizeUsage()
     */
    protected function upsertRow(array $row): void
    {
        if ($this->pdo === null) {
            return;
        }
        $table = Db::requireTable($this->pdo, 'usage_daily');
        $counters = $row['counters'];

        $insert = [
            'company_id' => (int) $row['company_id'],
            'stat_date' => (string) $row['stat_date'],
            'user_id' => (string) $row['user_id'],
            'cost' => (float) $row['cost'],
            'updated_at' => Db::now(),
        ];
        foreach (self::COUNTERS as $column) {
            $insert[$column] = (int) ($counters[$column] ?? 0);
        }

        Db::upsertIncrements($this->pdo, $table, $insert, [...self::COUNTERS, 'cost'], ['updated_at']);
    }

    private function upsertCounter(string $dimension, string $key, string $window, string $bucket, string $resetAt, int $amount): void
    {
        if ($this->pdo === null) {
            return;
        }
        Db::upsertIncrements(
            $this->pdo,
            Db::identifier(Db::table('rate_limits')),
            [
                'company_id' => in_array($dimension, ['company'], true) ? (int) $key : (int) (Config::get('tenant.id', 0) ?? 0),
                'dimension' => $dimension,
                'scope_key' => $key,
                'window' => $window,
                'bucket' => $bucket,
                'count' => $amount,
                'expires_at' => $resetAt,
            ],
            ['count'],
            ['expires_at']
        );
    }

    /** chats count from the rollup table for the current day/month bucket. */
    private function rollupCount(string $dimension, string $key, string $window, DateTimeInterface $now): int
    {
        $tz = $this->tz();
        $d = DateTimeImmutable::createFromInterface($now)->setTimezone($tz);
        $companyId = $dimension === 'company' ? (int) $key : (int) (Config::get('tenant.id', 0) ?? 0);
        $userId = $dimension === 'user' ? (string) $key : null;

        $from = $window === 'month'
            ? $d->setDate((int) $d->format('Y'), (int) $d->format('n'), 1)->format('Y-m-d')
            : $d->format('Y-m-d');
        $to = $window === 'month'
            ? $d->setDate((int) $d->format('Y'), (int) $d->format('n'), 1)->modify('+1 month')->modify('-1 day')->format('Y-m-d')
            : $d->format('Y-m-d');

        try {
            $table = Db::table('usage_daily');
            $sql = 'SELECT COALESCE(SUM(`chats`),0) FROM `' . $table . '` WHERE `company_id` = :c AND `stat_date` BETWEEN :f AND :t';
            $bind = ['c' => $companyId, 'f' => $from, 't' => $to];
            if ($userId !== null) {
                $sql .= ' AND `user_id` = :u';
                $bind['u'] = $userId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bind);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $this->report($e);
            return 0;
        }
    }

    private function fetchRow(int $companyId, string $date, string $userId): array
    {
        try {
            $table = Db::table('usage_daily');
            $stmt = $this->pdo->prepare(
                'SELECT * FROM `' . $table . '` WHERE `company_id` = :c AND `stat_date` = :d AND `user_id` = :u LIMIT 1'
            );
            $stmt->execute(['c' => $companyId, 'd' => $date, 'u' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->shapeTotals($companyId, $row) : $this->zeroUsage($companyId, (int) ($userId ?: 0));
        } catch (Throwable $e) {
            $this->report($e);
            return $this->zeroUsage($companyId, (int) ($userId ?: 0));
        }
    }

    private function shapeTotals(int $companyId, array $row): array
    {
        $out = ['company_id' => $companyId];
        foreach (self::COUNTERS as $column) {
            $out[$column] = (int) ($row[$column] ?? 0);
        }
        $out['cost'] = round((float) ($row['cost'] ?? 0), 6);
        $out['tokens_total'] = $out['tokens_in'] + $out['tokens_out'];
        return $out;
    }

    private function zeroUsage(int $companyId, ?int $userId): array
    {
        $out = $this->shapeTotals($companyId, []);
        $out['user_id'] = $userId;
        return $out;
    }

    private function memoryKey(string $dimension, string $key, string $bucket): string
    {
        return $dimension . '|' . $key . '|' . $bucket;
    }

    private function normaliseWindow(string $window): string
    {
        $w = strtolower(trim($window));
        return match ($w) {
            'min', 'minute' => 'minute',
            'h', 'hour' => 'hour',
            'm', 'mo', 'month' => 'month',
            'd', 'day' => 'day',
            default => 'day',
        };
    }

    private function todayDate(): string
    {
        return DateTimeImmutable::createFromInterface($this->now())->setTimezone($this->tz())->format('Y-m-d');
    }

    private function day(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : $this->todayDate();
    }

    private function toStart(string $value): string
    {
        return $this->day($value) . ' 00:00:00';
    }

    private function toEnd(string $value): string
    {
        return $this->day($value) . ' 23:59:59';
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->tz());
    }

    private function tz(): DateTimeZone
    {
        try {
            return new DateTimeZone(Config::string('timezone', 'UTC') ?: 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    private function report(Throwable $e): void
    {
        if ($this->onError !== null) {
            try {
                ($this->onError)($e);
            } catch (Throwable) {
            }
        }
    }
}
