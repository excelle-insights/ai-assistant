<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Contracts\NotificationInterface;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
use PDO;

/**
 * Outbound messages the package queues and the host sends.
 *
 * A scheduled report, a threshold alert or a "email me this every Monday"
 * request has nowhere to go without the host's mail/WhatsApp/Slack credentials,
 * so this service only ever writes rows into {prefix}_notification_outbox and
 * hands them back when they are due. Delivery is a host callable (or a
 * NotificationInterface) — flush() records what happened, including the
 * attempt count and the next retry window.
 *
 * Retries back off exponentially (outbox.backoff_seconds × 2^(attempts-1), so
 * 60s, 120s, 240s… capped at two hours) and stop at outbox.max_attempts, so one
 * bad recipient cannot spin a worker forever.
 * A row that never gets sent is still visible in history(), which is the point:
 * a report nobody received should be a support ticket, not a silent gap.
 */
final class OutboxService
{
    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    private string $table;

    /** @var (callable(array<string,mixed>):(bool|array<string,mixed>))|null */
    private $sender;

    /**
     * @param NotificationInterface|callable(array<string,mixed>):(bool|array<string,mixed>)|null $sender
     */
    public function __construct(private ?PDO $pdo = null, $sender = null)
    {
        $this->table = Db::table('notification_outbox');
        $this->sender = $sender === null ? null : self::coerce($sender);
    }

    /** @param NotificationInterface|callable $sender */
    public function setSender($sender): static
    {
        $this->sender = self::coerce($sender);

        return $this;
    }

    /**
     * Queue one message.
     *
     * @param array<string,mixed> $message channel, recipient, subject, body,
     *                                     template, payload, attachments
     * @return array<string,mixed> id + status
     */
    public function queue(int $tenantId, array $message, ?string $userId = null): array
    {
        $channel = strtolower(trim((string) ($message['channel'] ?? 'email'))) ?: 'email';
        $recipient = trim((string) ($message['recipient'] ?? ''));
        if ($recipient === '') {
            return ['ok' => false, 'status' => 'rejected', 'error' => 'Nothing to send to: no recipient for this ' . $channel . ' message.'];
        }

        $values = [
            'company_id' => $tenantId,
            'user_id' => $userId !== '' ? $userId : ($message['user_id'] ?? null),
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => (string) ($message['subject'] ?? ''),
            'template' => isset($message['template']) ? (string) $message['template'] : null,
            'body' => (string) ($message['body'] ?? ''),
            'payload_json' => Db::json($message['payload'] ?? []),
            'attachments_json' => Db::json($message['attachments'] ?? []),
            'status' => self::PENDING,
            'attempts' => 0,
            'next_attempt_at' => isset($message['scheduled_at']) ? (string) $message['scheduled_at'] : Db::now(),
            'created_at' => Db::now(),
        ];
        $id = $this->store($values);

        return ['ok' => true, 'id' => $id, 'status' => self::PENDING, 'channel' => $channel, 'recipient' => $recipient];
    }

    /** Queue several at once; returns each result in order. */
    public function queueMany(int $tenantId, array $messages, ?string $userId = null): array
    {
        $out = [];
        foreach ($messages as $message) {
            $out[] = $this->queue($tenantId, (array) $message, $userId);
        }

        return ['queued' => count(array_filter($out, static fn (array $r): bool => (bool) ($r['ok'] ?? false))), 'results' => $out];
    }

    /**
     * Rows waiting to go, due first. $now is injectable so a host (or a test)
     * can ask what a given minute looks like.
     *
     * @return array<int,array<string,mixed>>
     */
    public function due(int $limit = 0, ?string $now = null): array
    {
        $limit = $limit > 0 ? $limit : Config::int('outbox.batch', 50);

        return array_map([$this, 'hydrate'], $this->selectDue($limit, $now ?? Db::now()));
    }

    /**
     * Send what is due. Without a sender this returns the queue untouched — a
     * host that has not wired delivery yet still keeps every message on record.
     *
     * @return array<int,array<string,mixed>> one entry per row attempted
     */
    public function flush(int $limit = 0, ?string $now = null): array
    {
        $results = [];
        foreach ($this->due($limit, $now) as $row) {
            if ($this->sender === null) {
                $results[] = ['id' => $row['id'], 'ok' => false, 'status' => self::PENDING, 'error' => 'no sender registered'];
                continue;
            }
            $results[] = $this->deliver($row);
        }

        return $results;
    }

    /**
     * Send one queued row and record the outcome. Public so a worker that pulls
     * rows itself (a queue, not a cron sweep) can use the same bookkeeping.
     *
     * @param array<string,mixed> $row a row from due()
     * @return array<string,mixed>
     */
    public function deliver(array $row): array
    {
        $id = (string) ($row['id'] ?? '');
        if ($this->sender === null) {
            return ['id' => $id, 'ok' => false, 'status' => (string) ($row['status'] ?? self::PENDING), 'error' => 'no sender registered'];
        }

        $attempts = (int) ($row['attempts'] ?? 0);
        try {
            $produced = ($this->sender)(self::payloadOf($row));
        } catch (\Throwable $e) {
            return $this->recordFailure($id, $attempts, $e->getMessage());
        }

        $ok = is_array($produced) ? (bool) ($produced['ok'] ?? $produced['success'] ?? false) : (bool) $produced;
        if ($ok) {
            $this->update($id, ['status' => self::SENT, 'sent_at' => Db::now(), 'attempts' => $attempts + 1, 'error' => null]);

            return ['id' => $id, 'ok' => true, 'status' => self::SENT, 'attempts' => $attempts + 1];
        }

        $error = is_array($produced) && isset($produced['error']) ? (string) $produced['error'] : 'the sender refused this message';

        return $this->recordFailure($id, $attempts, $error);
    }

    private function recordFailure(string $id, int $attempts, string $error): array
    {
        $max = max(1, Config::int('outbox.max_attempts', 5));
        $used = $attempts + 1;
        $exhausted = $used >= $max;

        $this->update($id, [
            'status' => $exhausted ? self::FAILED : self::PENDING,
            'attempts' => $used,
            'error' => $error,
            'next_attempt_at' => $exhausted ? null : self::retryAt($used, Config::int('outbox.backoff_seconds', 60)),
        ]);

        return ['id' => $id, 'ok' => false, 'status' => $exhausted ? self::FAILED : self::PENDING,
            'attempts' => $used, 'error' => $error];
    }

    /** Stop trying to send a queued message (a report the user deleted). */
    public function cancel(string $id): bool
    {
        $row = $this->find($id);
        if ($row === null || (string) ($row['status'] ?? '') !== self::PENDING) {
            return false;
        }
        $this->update($id, ['status' => self::CANCELLED]);

        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $tenantId, int $limit = 50, array $filters = []): array
    {
        return array_map([$this, 'hydrate'], $this->select(
            ['company_id' => $tenantId] + $filters,
            [['created_at', 'desc'], ['id', 'desc']],
            max(1, $limit)
        ));
    }

    public function get(string $id): ?array
    {
        $row = $this->find($id);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return array<string,int> status => count, for a diagnostics endpoint */
    public function counts(int $tenantId): array
    {
        $out = [self::PENDING => 0, self::SENT => 0, self::FAILED => 0, self::CANCELLED => 0];
        foreach ($this->history($tenantId, 1000) as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($out[$status])) {
                $out[$status]++;
            }
        }

        return $out + ['total' => array_sum($out)];
    }

    /**
     * When attempt $attempts should be retried, doubling each time. Pure, so the
     * backoff is asserted without a clock.
     *
     * @param int|float $backoffSeconds
     */
    public static function retryAt(int $attempts, int|float $backoffSeconds = 60, ?int $now = null): string
    {
        $delay = (float) $backoffSeconds * (2 ** max(0, $attempts - 1));
        // Two hours is the ceiling a host will actually notice a stuck message in.
        $delay = min($delay, 7200.0);

        return date('Y-m-d H:i:s', ($now ?? time()) + (int) $delay);
    }

    /** @return array<string,mixed> the payload a host sender is handed */
    public static function payloadOf(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'company_id' => (int) ($row['company_id'] ?? 0),
            'channel' => (string) ($row['channel'] ?? 'email'),
            'recipient' => (string) ($row['recipient'] ?? ''),
            'subject' => (string) ($row['subject'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'template' => $row['template'] ?? null,
            'payload' => Db::decode($row['payload_json'] ?? null),
            'attachments' => Db::decode($row['attachments_json'] ?? null),
        ];
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /** @param NotificationInterface|callable $sender */
    private static function coerce($sender): callable
    {
        if ($sender instanceof NotificationInterface) {
            return static function (array $payload) use ($sender): array {
                return $sender->send(
                    (int) $payload['company_id'],
                    (string) $payload['channel'],
                    (string) $payload['recipient'],
                    (string) $payload['subject'],
                    (string) $payload['body'],
                    (array) $payload['attachments']
                );
            };
        }
        if (!is_callable($sender)) {
            throw new \InvalidArgumentException('An outbox sender must be callable or implement NotificationInterface.');
        }

        return $sender;
    }

    private function hydrate(array $row): array
    {
        $row['payload'] = Db::decode($row['payload_json'] ?? null);
        $row['attachments'] = Db::decode($row['attachments_json'] ?? null);
        unset($row['payload_json'], $row['attachments_json']);

        return $row;
    }

    // ── storage seams ─────────────────────────────────────────────────────────

    /** @param array<string,mixed> $values */
    protected function store(array $values): string
    {
        if ($this->pdo === null) {
            return '';
        }

        return (string) Db::insert($this->pdo, $this->table, $values);
    }

    protected function find(string $id): ?array
    {
        $rows = $this->select(['id' => $id], [], 1);

        return $rows[0] ?? null;
    }

    /**
     * @param array<string,mixed> $where
     * @param array<int,array{0:string,1:string}> $order
     * @return array<int,array<string,mixed>>
     */
    protected function select(array $where, array $order = [], int $limit = 50): array
    {
        if ($this->pdo === null) {
            return [];
        }

        return Db::select($this->pdo, $this->table, $where, [], $order, max(1, $limit));
    }

    /**
     * Due rows: pending, and either never scheduled or scheduled by now. A NULL
     * next_attempt_at means "send it", so a row written by an older version of
     * this package is not stuck forever.
     */
    protected function selectDue(int $limit, string $now): array
    {
        if ($this->pdo === null) {
            return [];
        }
        $table = Db::identifier($this->table);
        $sql = 'SELECT * FROM `' . $table . '` WHERE `status` = :status'
            . ' AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= :now)'
            . ' ORDER BY `next_attempt_at` ASC, `id` ASC LIMIT ' . max(1, $limit);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':status' => self::PENDING, ':now' => $now]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $values */
    protected function update(string $id, array $values): void
    {
        if ($this->pdo === null || $id === '') {
            return;
        }
        // null means "clear this column" here, so it must survive to the UPDATE.
        Db::updateWhere($this->pdo, $this->table, $values, ['id' => $id], 'updated_at');
    }
}
