<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * One audited event: a question, an action, an export, a report run.
 * Append-only by convention — nothing in the package updates these rows.
 */
final readonly class AuditEvent
{
    public function __construct(
        public int $tenantId,
        public string $userId,
        public string $event,
        public ?string $channel = null,
        public ?string $intent = null,
        public ?string $correlationId = null,
        public ?string $model = null,
        public int $tokens = 0,
        public float $cost = 0.0,
        public ?int $latencyMs = null,
        public ?string $queryHash = null,
        public ?string $sql = null,
        public array $recordRefs = [],
        public string $status = 'ok',
        public ?string $error = null,
        public ?string $ip = null,
        public array $detail = [],
    ) {
    }

    public static function new(int $tenantId, string $userId, string $event, array $fields = []): self
    {
        return new self(
            tenantId: $tenantId,
            userId: $userId,
            event: $event,
            channel: isset($fields['channel']) ? (string) $fields['channel'] : null,
            intent: isset($fields['intent']) ? (string) $fields['intent'] : null,
            correlationId: isset($fields['correlation_id']) ? (string) $fields['correlation_id'] : null,
            model: isset($fields['model']) ? (string) $fields['model'] : null,
            tokens: (int) ($fields['tokens'] ?? 0),
            cost: (float) ($fields['cost'] ?? 0),
            latencyMs: isset($fields['latency_ms']) ? (int) $fields['latency_ms'] : null,
            queryHash: isset($fields['query_hash']) ? (string) $fields['query_hash'] : null,
            sql: isset($fields['sql']) ? (string) $fields['sql'] : null,
            recordRefs: (array) ($fields['record_refs'] ?? []),
            status: (string) ($fields['status'] ?? 'ok'),
            error: isset($fields['error']) ? (string) $fields['error'] : null,
            ip: isset($fields['ip']) ? (string) $fields['ip'] : null,
            detail: (array) ($fields['detail'] ?? []),
        );
    }

    public function withCorrelationId(string $id): self
    {
        return new self(
            $this->tenantId, $this->userId, $this->event, $this->channel, $this->intent, $id,
            $this->model, $this->tokens, $this->cost, $this->latencyMs, $this->queryHash, $this->sql,
            $this->recordRefs, $this->status, $this->error, $this->ip, $this->detail,
        );
    }

    public static function hashQuery(string $sql): string
    {
        return hash('sha256', strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql)));
    }

    public function toArray(): array
    {
        return [
            'company_id' => $this->tenantId,
            'user_id' => $this->userId,
            'event' => $this->event,
            'channel' => $this->channel,
            'intent' => $this->intent,
            'correlation_id' => $this->correlationId,
            'model' => $this->model,
            'tokens' => $this->tokens,
            'cost' => $this->cost,
            'latency_ms' => $this->latencyMs,
            'query_hash' => $this->queryHash,
            'sql' => $this->sql,
            'record_refs' => $this->recordRefs,
            'status' => $this->status,
            'error' => $this->error,
            'ip' => $this->ip,
            'detail' => $this->detail,
        ];
    }
}
