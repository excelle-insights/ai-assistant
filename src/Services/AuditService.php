<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use PDO;
use Throwable;
use ExcelleInsights\AiAssistant\Dto\AuditEvent;
use ExcelleInsights\AiAssistant\Contracts\AuditSinkInterface;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;

/**
 * Append-only audit trail for the assistant: every question, action, export and
 * report run. The chat path calls emit()/log() and must NEVER have its flow
 * broken by a logging failure, so every write is wrapped in try/catch and any
 * error is routed to an optional host callback instead of throwing.
 *
 * Sinks and precedence (highest priority first):
 *   1. an injected host AuditSinkInterface (via sink()) — always used when set;
 *   2. a host sink named by config `audit.store` (a class name) — used when it
 *      implements the contract;
 *   3. the package's own {prefix}_audit table — written whenever a PDO is
 *      available AND `audit.store` is 'table' | 'both' | (a sink class name,
 *      which still doubles up to the table best-effort).
 * i.e. `store = 'sink'` = sink only; `'table'` = DB only; `'both'` (or a sink
 * class name) = sink preferred AND table additionally when possible.
 *
 * Column handling that is independent of storage (hashing, SQL stripping, PII
 * masking, filter building) lives in pure methods so it can be unit-tested
 * without a database.
 */
class AuditService
{
    private ?SchemaPolicy $policy;
    private ?AuditSinkInterface $hostSink = null;
    /** @var callable(Throwable):void|null */
    private $onError;

    /** Columns the {prefix}_audit table actually has, in insert order. */
    private const COLUMNS = [
        'company_id', 'user_id', 'channel', 'event', 'intent', 'correlation_id', 'conversation_id',
        'model', 'tokens', 'cost', 'latency_ms', 'query_hash', 'sql_text', 'question', 'record_refs',
        'status', 'error', 'ip', 'detail_json', 'created_at',
    ];

    /**
     * @param callable(Throwable):void|null $onError
     */
    public function __construct(
        private ?PDO $pdo = null,
        ?callable $onError = null,
        ?SchemaPolicy $policy = null,
    ) {
        $this->onError = $onError;
        $this->policy = $policy;
    }

    /** Register the host's audit sink; returns $this for chaining. */
    public function sink(?AuditSinkInterface $host = null): static
    {
        $this->hostSink = $host;
        return $this;
    }

    // ── write path (never throws) ────────────────────────────────────────────

    /**
     * Record one event. Accepts a ready AuditEvent or a plain context array.
     * Honors `audit.enabled`, always hashes the query, blanks sql_text when
     * `audit.record_sql` is false, and masks PII in question/sql_text when a
     * SchemaPolicy was injected. Failures are swallowed and reported to onError.
     */
    public function emit(AuditEvent|array $event): void
    {
        if (!$this->enabled()) {
            return;
        }
        try {
            $data = $event instanceof AuditEvent ? $this->eventToArray($event) : $event;
            $normalized = $this->normalize($data);
            $this->dispatch($normalized);
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    /**
     * Generic logging surface hosts can call without building a DTO. Returns the
     * event's trace/correlation id (a UUID when the caller did not supply one) so
     * the write path stays useful even with no database connection.
     *
     * @param array<string,mixed> $context tenant/user/channel/… keys
     * @param array<string,mixed> $detail  free-form payload stored as detail_json
     */
    public function log(array $context, string $action, array $detail = []): string
    {
        $data = array_merge($context, $detail, ['event' => $action]);
        $normalized = $this->normalize($data);
        if ($this->enabled()) {
            try {
                $this->dispatch($normalized);
            } catch (Throwable $e) {
                $this->report($e);
            }
        }
        return (string) $normalized['correlation_id'];
    }

    // ── read path ────────────────────────────────────────────────────────────

    /**
     * Filtered audit rows. `$filter` keys: company_id, user_id, event|action,
     * intent, status, correlation_id, conversation_id, channel, query_hash,
     * from, to. Returns [] (never throws) when storage is unavailable.
     *
     * @return array<int,array<string,mixed>>
     */
    public function query(array $filter, int $limit = 100, int $offset = 0): array
    {
        if ($this->pdo === null) {
            return [];
        }
        $sinkMode = $this->storeMode();
        if ($sinkMode === 'sink') {
            $sink = $this->resolveSink();
            if ($sink !== null) {
                return $sink->query($filter, $this->clampLimit($limit));
            }
        }
        try {
            $table = Db::requireTable($this->pdo, 'audit');
            [$where, $bind] = $this->buildWhere($filter);
            $sql = 'SELECT `' . implode('`,`', self::COLUMNS) . '` FROM `' . $table . '`';
            if ($where !== []) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY `created_at` DESC LIMIT ' . $this->clampLimit($limit) . ' OFFSET ' . max(0, (int) $offset);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bind);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->report($e);
            return [];
        }
    }

    /**
     * Aggregate counters for a filtered window.
     *
     * @return array<string,mixed>
     */
    public function summary(array $filter): array
    {
        if ($this->pdo === null) {
            return $this->emptySummary();
        }
        try {
            $table = Db::requireTable($this->pdo, 'audit');
            [$where, $bind] = $this->buildWhere($filter);
            $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
            $sql = 'SELECT COUNT(*) AS total,'
                . ' AVG(`latency_ms`) AS avg_latency_ms,'
                . ' SUM(`tokens`) AS total_tokens,'
                . ' SUM(`cost`) AS total_cost,'
                . " SUM(CASE WHEN `status` <> 'ok' OR `error` IS NOT NULL THEN 1 ELSE 0 END) AS errors,"
                . " SUM(CASE WHEN `status` IN ('no_answer','unanswered') OR `event` = 'unanswered' THEN 1 ELSE 0 END) AS unanswered"
                . ' FROM `' . $table . '`' . $whereSql;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bind);
            $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $total = (int) ($agg['total'] ?? 0);

            // Top users is a second, cheap grouped query.
            $topUsers = [];
            try {
                $uSql = 'SELECT `user_id`, COUNT(*) AS n FROM `' . $table . '`' . $whereSql
                    . ' GROUP BY `user_id` ORDER BY n DESC LIMIT 5';
                $uStmt = $this->pdo->prepare($uSql);
                $uStmt->execute($bind);
                foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
                    $topUsers[] = ['user_id' => $u['user_id'], 'events' => (int) $u['n']];
                }
            } catch (Throwable $e) {
                $this->report($e);
            }

            return [
                'total' => $total,
                'avg_latency_ms' => $agg['avg_latency_ms'] !== null ? round((float) $agg['avg_latency_ms'], 2) : null,
                'total_tokens' => (int) ($agg['total_tokens'] ?? 0),
                'total_cost' => round((float) ($agg['total_cost'] ?? 0), 6),
                'errors' => (int) ($agg['errors'] ?? 0),
                'error_rate' => $total > 0 ? round((int) $agg['errors'] / $total, 4) : 0.0,
                'unanswered' => (int) ($agg['unanswered'] ?? 0),
                'top_users' => $topUsers,
            ];
        } catch (Throwable $e) {
            $this->report($e);
            return $this->emptySummary();
        }
    }

    /**
     * CSV export of a filtered window (RFC 4180 quoting, comma, LF line breaks).
     * An empty window — including the no-database case, where query() yields [] —
     * exports nothing: the return value is the empty string, not a lone header.
     */
    public function exportCsv(array $filter, int $maxRows = 5000): string
    {
        $rows = $this->query($filter, $this->clampLimit($maxRows), 0);
        if ($rows === []) {
            return '';
        }
        $columns = ['created_at', 'company_id', 'user_id', 'event', 'intent', 'channel', 'status',
            'tokens', 'cost', 'latency_ms', 'model', 'question', 'query_hash', 'correlation_id', 'error'];

        $handle = fopen('php://temp', 'rb+');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? '';
                $line[] = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
            }
            fputcsv($handle, $line);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * Delete audit rows older than `$days` (default `limits.retention_days`).
     * Append-only by convention, so this is the ONLY sanctioned deletion.
     */
    public function retention(?int $days = null): int
    {
        if ($this->pdo === null) {
            return 0;
        }
        $days = $days ?? Config::int('limits.retention_days', 365);
        if ($days < 0) {
            return 0;
        }
        try {
            $table = Db::requireTable($this->pdo, 'audit');
            $stmt = $this->pdo->prepare(
                'DELETE FROM `' . $table . '` WHERE `created_at` IS NOT NULL'
                . ' AND `created_at` < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)'
            );
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            $this->report($e);
            return 0;
        }
    }

    /** True when the audit table exists and is usable. */
    public function verify(): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        try {
            return Db::tableExists($this->pdo, 'audit');
        } catch (Throwable $e) {
            $this->report($e);
            return false;
        }
    }

    // ── pure logic (no PDO) — also the unit-test surface ─────────────────────

    /** `audit.enabled` (defaults to on). */
    public function enabled(): bool
    {
        return Config::bool('audit.enabled', true);
    }

    /** `audit.record_sql` — when false sql_text is dropped but the hash is kept. */
    public function shouldRecordSql(): bool
    {
        return Config::bool('audit.record_sql', true);
    }

    /**
     * Normalise any input shape into a canonical, storage-ready row keyed by the
     * real audit columns. Pure: hashing, SQL stripping and PII masking happen
     * here so they are testable without a database.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function normalize(array $data): array
    {
        $sql = $this->firstScalar($data, ['sql', 'sql_text', 'query']);
        $question = $this->firstScalar($data, ['question', 'q', 'text']);
        $rawHash = $this->firstScalar($data, ['query_hash', 'hash']);

        $queryHash = $rawHash !== null && $rawHash !== ''
            ? $rawHash
            : ($sql !== null && trim($sql) !== '' ? AuditEvent::hashQuery($sql) : null);

        $recordSql = $this->shouldRecordSql();
        $sqlStored = $recordSql && $sql !== null ? $this->maskText($sql) : null;

        $detail = $data['detail'] ?? $data['detail_json'] ?? [];
        if (is_string($detail)) {
            $detail = Db::decode($detail);
        }
        $recordRefs = $data['record_refs'] ?? [];
        if (is_string($recordRefs)) {
            $recordRefs = Db::decode($recordRefs);
        }

        $correlation = $this->firstScalar($data, ['correlation_id', 'trace_id', 'id']);
        if ($correlation === null || $correlation === '') {
            $correlation = Db::uuid();
        }

        return [
            'company_id' => (int) ($data['company_id'] ?? $data['tenant_id'] ?? 0),
            'user_id' => $this->nullableString($this->firstScalar($data, ['user_id', 'userId'])),
            'channel' => $this->nullableString($this->firstScalar($data, ['channel'])),
            'event' => (string) ($data['event'] ?? $data['action'] ?? 'chat'),
            'intent' => $this->nullableString($this->firstScalar($data, ['intent'])),
            'correlation_id' => (string) $correlation,
            'conversation_id' => $this->nullableString($this->firstScalar($data, ['conversation_id'])),
            'model' => $this->nullableString($this->firstScalar($data, ['model'])),
            'tokens' => (int) ($data['tokens'] ?? 0),
            'cost' => round((float) ($data['cost'] ?? 0), 6),
            'latency_ms' => isset($data['latency_ms']) && $data['latency_ms'] !== null ? (int) $data['latency_ms'] : null,
            'query_hash' => $queryHash,
            'sql_text' => $sqlStored,
            'question' => $question !== null ? $this->maskText($question) : null,
            'record_refs' => is_array($recordRefs) ? $recordRefs : [],
            'status' => (string) ($data['status'] ?? 'ok'),
            'error' => $this->nullableString($this->firstScalar($data, ['error'])),
            'ip' => $this->nullableString($this->firstScalar($data, ['ip'])),
            'detail' => is_array($detail) ? $detail : [],
            'created_at' => $this->firstScalar($data, ['created_at']) ?? Db::now(),
        ];
    }

    /**
     * Turn a filter array into [whereFragments, bindParams] with bound
     * placeholders only. Exposed (pure) so tests can assert the SQL fragments
     * and parameters without a database.
     *
     * @return array{0: string[], 1: array<string,mixed>}
     */
    public function buildWhere(array $filter): array
    {
        $conditions = [];
        $bind = [];
        $i = 0;

        $equality = [
            'company_id' => 'company_id',
            'tenant_id' => 'company_id',
            'user_id' => 'user_id',
            'event' => 'event',
            'action' => 'event',
            'intent' => 'intent',
            'status' => 'status',
            'correlation_id' => 'correlation_id',
            'conversation_id' => 'conversation_id',
            'channel' => 'channel',
            'query_hash' => 'query_hash',
        ];
        foreach ($equality as $key => $column) {
            if (array_key_exists($key, $filter) && $filter[$key] !== null && $filter[$key] !== '') {
                $col = Db::identifier($column);
                $param = ':f' . $i++;
                $conditions[] = "`{$col}` = {$param}";
                $bind[$param] = $column === 'company_id' ? (int) $filter[$key] : (string) $filter[$key];
            }
        }

        // IN() lists (e.g. multiple events/statuses/intents).
        $inLists = ['events' => 'event', 'statuses' => 'status', 'intents' => 'intent'];
        foreach ($inLists as $key => $column) {
            if (empty($filter[$key]) || !is_array($filter[$key])) {
                continue;
            }
            $col = Db::identifier($column);
            $placeholders = [];
            foreach (array_values($filter[$key]) as $v) {
                $name = ':f' . $i++;
                $placeholders[] = $name;
                $bind[$name] = (string) $v;
            }
            $conditions[] = "`{$col}` IN (" . implode(',', $placeholders) . ')';
        }

        if (!empty($filter['from'])) {
            $conditions[] = '`created_at` >= :f' . $i;
            $bind[':f' . $i++] = $this->toDateTimeString((string) $filter['from'], false);
        }
        if (!empty($filter['to'])) {
            $conditions[] = '`created_at` <= :f' . $i;
            $bind[':f' . $i++] = $this->toDateTimeString((string) $filter['to'], true);
        }

        if (!empty($filter['has_sql'])) {
            $conditions[] = '`sql_text` IS NOT NULL';
        }
        if (!empty($filter['errors_only'])) {
            $conditions[] = "(`status` <> 'ok' OR `error` IS NOT NULL)";
        }

        return [$conditions, $bind];
    }

    /**
     * Mask PII in free text using the injected SchemaPolicy's column vocabulary.
     * No-op when no policy is configured (the caller opted into plain logging).
     */
    public function maskText(?string $text): ?string
    {
        if ($text === null || $text === '' || $this->policy === null) {
            return $text;
        }
        // Emails -> j***@domain
        $masked = (string) preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            static fn (array $m): string => SchemaPolicy::maskValue($m[0], 'email'),
            $text
        );
        // Phone-like runs (leading +, 7+ digits) -> partial redaction.
        $masked = (string) preg_replace_callback(
            '/\+\d[\d\s().\-]{5,}\d/',
            static function (array $m): string {
                $digits = (string) preg_replace('/\D/', '', $m[0]);
                return strlen($digits) >= 7 ? SchemaPolicy::maskValue($m[0], 'phone') : $m[0];
            },
            $masked
        );
        return $masked;
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * Route a normalized row to the configured sink(s). Sink first, then table.
     *
     * @param array<string,mixed> $row
     */
    private function dispatch(array $row): void
    {
        $mode = $this->storeMode();
        $sink = $this->resolveSink();

        if ($sink !== null) {
            $sink->record($this->eventFromRow($row));
        }

        // Table write is best-effort: writeRow() no-ops when there is no PDO, so
        // tests can still exercise the seam by overriding it.
        $writeTable = $mode === 'table' || $mode === 'both' || ($sink !== null && $mode !== 'sink');
        if ($writeTable) {
            $this->writeRow($row);
        }
    }

    /**
     * Storage seam — the ONLY place a row hits the DB. Tests subclass and
     * override this to capture the payload without a PDO.
     *
     * @param array<string,mixed> $row
     */
    protected function writeRow(array $row): void
    {
        if ($this->pdo === null) {
            return;
        }
        $table = Db::requireTable($this->pdo, 'audit');
        $values = $row;
        $values['record_refs'] = Db::json($row['record_refs'] ?? []);
        $values['detail_json'] = Db::json($row['detail'] ?? []);
        unset($values['detail']);

        $columns = array_values(array_filter(self::COLUMNS, static fn (string $c): bool => array_key_exists($c, $values)));
        $payload = [];
        foreach ($columns as $column) {
            $payload[$column] = $values[$column];
        }
        Db::insert($this->pdo, $table, $payload);
    }

    private function storeMode(): string
    {
        $store = strtolower(trim(Config::string('audit.store', 'table')));
        if (in_array($store, ['table', 'sink', 'both'], true)) {
            return $store;
        }
        // A class name is a sink that still writes the table best-effort.
        return $store === '' ? 'table' : 'both';
    }

    private function resolveSink(): ?AuditSinkInterface
    {
        if ($this->hostSink !== null) {
            return $this->hostSink;
        }
        // No injected host sink: honour a class name in `audit.store` if it
        // implements the contract. Re-resolved per call (cheap, no stale cache).
        $store = trim(Config::string('audit.store', 'table'));
        if ($store !== '' && !in_array(strtolower($store), ['table', 'sink', 'both'], true) && class_exists($store)) {
            try {
                $instance = new $store();
                if ($instance instanceof AuditSinkInterface) {
                    return $instance;
                }
            } catch (Throwable $e) {
                $this->report($e);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $row */
    private function eventFromRow(array $row): AuditEvent
    {
        return new AuditEvent(
            tenantId: (int) $row['company_id'],
            userId: (string) ($row['user_id'] ?? ''),
            event: (string) $row['event'],
            channel: $row['channel'] ?? null,
            intent: $row['intent'] ?? null,
            correlationId: $row['correlation_id'] ?? null,
            model: $row['model'] ?? null,
            tokens: (int) ($row['tokens'] ?? 0),
            cost: (float) ($row['cost'] ?? 0),
            latencyMs: isset($row['latency_ms']) ? (int) $row['latency_ms'] : null,
            queryHash: $row['query_hash'] ?? null,
            sql: $row['sql_text'] ?? null,
            recordRefs: (array) ($row['record_refs'] ?? []),
            status: (string) ($row['status'] ?? 'ok'),
            error: $row['error'] ?? null,
            ip: $row['ip'] ?? null,
            detail: array_merge((array) ($row['detail'] ?? []), [
                'question' => $row['question'] ?? null,
                'conversation_id' => $row['conversation_id'] ?? null,
            ]),
        );
    }

    /** @return array<string,mixed> */
    private function eventToArray(AuditEvent $e): array
    {
        $detail = $e->detail;
        return array_merge($detail, [
            'company_id' => $e->tenantId,
            'user_id' => $e->userId,
            'event' => $e->event,
            'channel' => $e->channel,
            'intent' => $e->intent,
            'correlation_id' => $e->correlationId,
            'model' => $e->model,
            'tokens' => $e->tokens,
            'cost' => $e->cost,
            'latency_ms' => $e->latencyMs,
            'query_hash' => $e->queryHash,
            'sql' => $e->sql,
            'record_refs' => $e->recordRefs,
            'status' => $e->status,
            'error' => $e->error,
            'ip' => $e->ip,
            'detail' => $detail,
        ]);
    }

    /** @param array<string,mixed> $data */
    private function firstScalar(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                return (string) $data[$key];
            }
        }
        return null;
    }

    private function nullableString(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    private function clampLimit(int $limit): int
    {
        $max = Config::int('limits.audit_export_rows', 10000);
        if ($limit <= 0) {
            return $max;
        }
        return min($limit, $max);
    }

    private function toDateTimeString(string $value, bool $endOfDay): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value)) {
            return str_replace('T', ' ', substr($value, 0, 19));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function emptySummary(): array
    {
        return [
            'total' => 0, 'avg_latency_ms' => null, 'total_tokens' => 0, 'total_cost' => 0.0,
            'errors' => 0, 'error_rate' => 0.0, 'unanswered' => 0, 'top_users' => [],
        ];
    }

    private function report(Throwable $e): void
    {
        if ($this->onError !== null) {
            try {
                ($this->onError)($e);
            } catch (Throwable) {
                // never let the error handler itself break the caller
            }
        }
    }
}
