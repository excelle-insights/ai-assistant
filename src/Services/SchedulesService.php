<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use PDO;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Cron;
use ExcelleInsights\AiAssistant\Support\Db;

/**
 * Cron-like scheduling over {prefix}_schedules — periodic reports and threshold
 * alerts. The host's crontab calls tick() (or the queue drains due()), each job's
 * payload is handed back ready for the host's runner; markRun() records the
 * outcome and re-arms next_run_at, auto-disabling a job after N consecutive
 * failures (schedules.max_consecutive_failures, default 5).
 *
 * Next-run computation and the failure/disability rule are pure (built on
 * Support\Cron) so they are testable without a database; row IO uses protected
 * seams.
 */
class SchedulesService
{
    private const STATES = ['active', 'paused', 'disabled'];

    /** @var callable(Throwable):void|null */
    private $onError;

    /**
     * @param callable(Throwable):void|null $onError
     */
    public function __construct(private ?PDO $pdo = null, ?callable $onError = null)
    {
        $this->onError = $onError;
    }

    /**
     * Create a schedule.
     *
     * @param string|array|null $target report id, or an alert payload array
     * @param array<string,mixed> $extra  company_id, user_id, type, threshold, ...
     */
    public function create(
        string $name,
        string $cron,
        ?string $timezone = null,
        string|array|null $target = null,
        string $channel = 'email',
        ?string $recipient = null,
        string $format = 'pdf',
        string $state = 'active',
        array $extra = [],
    ): int|string {
        if (!Cron::isValid($cron)) {
            throw new \InvalidArgumentException("Invalid cron expression: '{$cron}'");
        }
        $timezone = $this->resolveTimezone($timezone);
        $state = in_array($state, self::STATES, true) ? $state : 'active';

        $payload = null;
        $reportId = null;
        $type = (string) ($extra['type'] ?? 'report');
        if (is_array($target)) {
            $payload = $target;
            if (($extra['type'] ?? null) === null) {
                $type = 'alert';
            }
        } elseif ($target !== null && $target !== '') {
            $reportId = (string) $target;
        }

        $row = [
            'company_id' => (int) ($extra['company_id'] ?? Config::get('tenant.id', 0)),
            'user_id' => $this->str($extra['user_id'] ?? null),
            'name' => trim($name) !== '' ? trim($name) : 'Scheduled job',
            'type' => $type,
            'cron' => $cron,
            'timezone' => $timezone,
            'report_id' => $reportId,
            'payload_json' => Db::json($payload),
            'channel' => $channel,
            'recipient' => $this->str($recipient),
            'format' => $format,
            'threshold_json' => Db::json($extra['threshold'] ?? null),
            'state' => $state,
            'next_run_at' => $this->nextRunAt($cron, $timezone, null),
            'failure_count' => 0,
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ];

        if ($this->pdo === null) {
            return 0;
        }
        try {
            $table = Db::requireTable($this->pdo, 'schedules');
            return Db::insert($this->pdo, $table, $row);
        } catch (Throwable $e) {
            $this->report($e);
            return 0;
        }
    }

    /**
     * @param array<string,mixed> $changes
     */
    public function update(int|string $id, array $changes, int $companyId): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        $allowed = ['name', 'type', 'cron', 'timezone', 'report_id', 'channel', 'recipient', 'format', 'state', 'payload', 'threshold'];
        $values = array_intersect_key($changes, array_flip($allowed));
        if ($values === []) {
            return false;
        }
        if (isset($values['cron']) && !Cron::isValid((string) $values['cron'])) {
            throw new \InvalidArgumentException("Invalid cron expression: '{$values['cron']}'");
        }
        if (isset($values['payload'])) {
            $values['payload_json'] = Db::json($values['payload']);
            unset($values['payload']);
        }
        if (isset($values['threshold'])) {
            $values['threshold_json'] = Db::json($values['threshold']);
            unset($values['threshold']);
        }
        if (isset($values['state']) && !in_array((string) $values['state'], self::STATES, true)) {
            unset($values['state']);
        }

        // Re-arm the schedule whenever timing fields change.
        $existing = $this->loadRow($id, $companyId);
        if ($existing !== null && (isset($values['cron']) || isset($values['timezone']) || isset($values['state']))) {
            $cron = (string) ($values['cron'] ?? $existing['cron']);
            $tz = $this->resolveTimezone((string) ($values['timezone'] ?? $existing['timezone']));
            $values['timezone'] = $tz;
            $values['next_run_at'] = $this->nextRunAt($cron, $tz, null);
        }

        try {
            $table = Db::requireTable($this->pdo, 'schedules');
            Db::updateWhere($this->pdo, $table, $values, ['id' => (string) $id, 'company_id' => $companyId]);
            return true;
        } catch (Throwable $e) {
            $this->report($e);
            return false;
        }
    }

    public function pause(int|string $id, int $companyId): bool
    {
        return $this->setState($id, $companyId, 'paused');
    }

    public function resume(int|string $id, int $companyId): bool
    {
        $row = $this->loadRow($id, $companyId);
        if ($row === null) {
            return false;
        }
        $cron = (string) $row['cron'];
        $tz = $this->resolveTimezone((string) $row['timezone']);
        return $this->applyUpdate($id, $companyId, [
            'state' => 'active',
            'failure_count' => 0,
            'next_run_at' => $this->nextRunAt($cron, $tz, null),
        ]);
    }

    public function delete(int|string $id, int $companyId): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        try {
            $table = Db::requireTable($this->pdo, 'schedules');
            $stmt = $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE `id` = :id AND `company_id` = :c');
            $stmt->execute(['id' => (string) $id, 'c' => $companyId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            $this->report($e);
            return false;
        }
    }

    /**
     * Active schedules whose next_run_at is due (across all tenants — this is the
     * system tick, so it is intentionally not company-scoped).
     *
     * @return array<int,array<string,mixed>>
     */
    public function due(?DateTimeInterface $now = null, int $limit = 200): array
    {
        if ($this->pdo === null) {
            return [];
        }
        $cutoff = ($now ?? new DateTimeImmutable('now', $this->tz()))->format('Y-m-d H:i:s');
        try {
            $table = Db::requireTable($this->pdo, 'schedules');
            $stmt = $this->pdo->prepare(
                'SELECT * FROM `' . $table . '` WHERE `state` = :s AND `next_run_at` IS NOT NULL AND `next_run_at` <= :c'
                . ' ORDER BY `next_run_at` ASC LIMIT ' . max(1, $limit)
            );
            $stmt->execute(['s' => 'active', 'c' => $cutoff]);
            return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $this->report($e);
            return [];
        }
    }

    /**
     * Due jobs with their payload decoded, ready for the host's queue. Marks each
     * as handed off by advancing next_run_at so a crashed tick cannot double-fire.
     *
     * @return array<int,array<string,mixed>>
     */
    public function tick(?int $limit = null): array
    {
        $limit = $limit ?? Config::int('schedules.tick_limit', 200);
        $due = $this->due(null, $limit);
        $jobs = [];
        $now = new DateTimeImmutable('now', $this->tz());
        foreach ($due as $row) {
            // Advance the schedule first (best-effort) to prevent overlapping ticks.
            $this->advance($row, $now);
            $jobs[] = [
                'id' => $row['id'],
                'company_id' => (int) $row['company_id'],
                'type' => (string) $row['type'],
                'report_id' => $row['report_id'] ?? null,
                'payload' => $row['payload'] ?? [],
                'threshold' => $row['threshold'] ?? [],
                'channel' => (string) $row['channel'],
                'recipient' => $row['recipient'] ?? null,
                'format' => (string) $row['format'],
                'due_at' => $row['next_run_at'] ?? null,
            ];
        }
        return $jobs;
    }

    /**
     * Record a run's outcome: stamp last_run_at/last_status/last_error, adjust
     * failure_count and re-arm (or disable) the schedule.
     *
     * @return array{state:string,failure_count:int,next_run_at:?string}
     */
    public function markRun(int|string $id, bool $ok, ?string $error = null): array
    {
        $row = $this->loadRow($id);
        if ($row === null) {
            return ['state' => 'unknown', 'failure_count' => 0, 'next_run_at' => null];
        }
        $max = Config::int('schedules.max_consecutive_failures', 5);
        $decision = $this->applyFailure((int) ($row['failure_count'] ?? 0), $ok, $max);

        $cron = (string) $row['cron'];
        $tz = $this->resolveTimezone((string) $row['timezone']);
        $next = $decision['state'] === 'active' ? $this->nextRunAt($cron, $tz, null) : null;

        $values = [
            'last_run_at' => Db::now(),
            'last_status' => $ok ? 'ok' : 'error',
            'last_error' => $ok ? null : ($error === null ? null : mb_substr(trim($error), 0, 1000)),
            'failure_count' => $decision['failure_count'],
            'state' => $decision['state'],
        ];
        if ($next !== null || $decision['state'] !== 'active') {
            $values['next_run_at'] = $next;
        }

        $this->applyUpdate($id, (int) ($row['company_id'] ?? 0), $values);
        return ['state' => $decision['state'], 'failure_count' => $decision['failure_count'], 'next_run_at' => $next];
    }

    /**
     * Preview/validate: the next $count fire times for a cron, as strings.
     * Empty when the expression is invalid or has no future occurrence in range.
     *
     * @return string[]
     */
    public function nextRuns(string $cron, int $count, string $tz = 'UTC'): array
    {
        if (!Cron::isValid($cron)) {
            return [];
        }
        try {
            $zone = new DateTimeZone($this->resolveTimezone($tz));
        } catch (Throwable) {
            return [];
        }
        $runs = Cron::nextRuns($cron, max(1, $count), new DateTimeImmutable('now', $zone), $zone);
        return array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s'), $runs);
    }

    // ── pure logic (no PDO) ────────────────────────────────────────────────

    /**
     * First fire time strictly after `$from` (default now) for a cron in a zone,
     * formatted for storage, or null when the expression never fires in range.
     */
    public function nextRunAt(string $cron, string $tz = 'UTC', ?DateTimeInterface $from = null): ?string
    {
        if (!Cron::isValid($cron)) {
            return null;
        }
        try {
            $zone = new DateTimeZone($this->resolveTimezone($tz));
        } catch (Throwable) {
            $zone = $this->tz();
        }
        $start = $from ?? new DateTimeImmutable('now', $zone);
        $next = Cron::next($cron, $start, $zone);
        return $next?->format('Y-m-d H:i:s');
    }

    /**
     * Consecutive-failure policy. Pure & testable: a success resets the counter;
     * reaching the max disables the job.
     *
     * @return array{failure_count:int,state:string}
     */
    public function applyFailure(int $failureCount, bool $ok, int $maxFailures): array
    {
        if ($ok) {
            return ['failure_count' => 0, 'state' => 'active'];
        }
        $next = $failureCount + 1;
        if ($maxFailures > 0 && $next >= $maxFailures) {
            return ['failure_count' => $next, 'state' => 'disabled'];
        }
        return ['failure_count' => $next, 'state' => 'active'];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function hydrate(array $row): array
    {
        $row['payload'] = Db::decode($row['payload_json'] ?? null);
        $row['threshold'] = Db::decode($row['threshold_json'] ?? null);
        return $row;
    }

    // ── storage seams ──────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    protected function loadRow(int|string $id, ?int $companyId = null): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        try {
            $table = Db::requireTable($this->pdo, 'schedules');
            $sql = 'SELECT * FROM `' . $table . '` WHERE `id` = :id';
            $bind = ['id' => (string) $id];
            if ($companyId !== null) {
                $sql .= ' AND `company_id` = :c';
                $bind['c'] = $companyId;
            }
            $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($bind);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            $this->report($e);
            return null;
        }
    }

    /** @param array<string,mixed> $values */
    protected function applyUpdate(int|string $id, int $companyId, array $values): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        try {
            $table = Db::requireTable($this->pdo, 'schedules');
            Db::updateWhere($this->pdo, $table, $values, ['id' => (string) $id]);
            return true;
        } catch (Throwable $e) {
            $this->report($e);
            return false;
        }
    }

    // ── internals ──────────────────────────────────────────────────────────

    private function setState(int|string $id, int $companyId, string $state): bool
    {
        $row = $this->loadRow($id, $companyId);
        if ($row === null) {
            return false;
        }
        return $this->applyUpdate($id, $companyId, ['state' => $state]);
    }

    private function advance(array $row, DateTimeInterface $now): void
    {
        $cron = (string) $row['cron'];
        $tz = $this->resolveTimezone((string) $row['timezone']);
        $next = $this->nextRunAt($cron, $tz, $now);
        $this->applyUpdate((int) $row['id'], (int) $row['company_id'], ['next_run_at' => $next]);
    }

    private function resolveTimezone(?string $tz): string
    {
        $tz = trim((string) $tz);
        if ($tz === '') {
            return Config::string('timezone', 'UTC') ?: 'UTC';
        }
        try {
            new DateTimeZone($tz);
            return $tz;
        } catch (Throwable) {
            return Config::string('timezone', 'UTC') ?: 'UTC';
        }
    }

    private function tz(): DateTimeZone
    {
        try {
            return new DateTimeZone(Config::string('timezone', 'UTC') ?: 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    private function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
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
