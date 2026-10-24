<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Contracts\ActionHandlerInterface;
use ExcelleInsights\AiAssistant\Contracts\PermissionResolverInterface;
use ExcelleInsights\AiAssistant\Dto\ActionResult;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
use PDO;

/**
 * Writes the assistant proposes but never performs.
 *
 * The three-step rule from the blueprint is enforced here:
 *
 *   propose()  the host's ActionHandlerInterface previews the change; this
 *              records it as `proposed` and hands back a single-use token
 *   confirm()  the user approves — the token is checked (owner, tenant, not
 *              expired, still unused) and only then does execute() run
 *   execute()  happens in the host, against the host's own tables
 *
 * Every step is written to {prefix}_action_runs, so "who approved creating this
 * lead, and what did the app actually do" is answerable long after the chat
 * scrolled past. The confirmation token is what makes a replayed webhook or a
 * double-clicked button harmless; the idempotency key is what makes a retried
 * execute() return the first result instead of a second record.
 *
 * The catalog in {prefix}_actions is the host's opt-in per action: an action
 * that is not listed there is still previewable (the domain pack said so), but
 * an entry can disable it or rename the permission it needs.
 */
final class ActionService
{
    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    private string $table;
    private string $catalogTable;

    public function __construct(
        private ?PDO $pdo = null,
        private ?ActionHandlerInterface $handler = null,
        private ?PermissionResolverInterface $permissions = null,
        private ?AuditService $audit = null,
    ) {
        $this->table = Db::table('action_runs');
        $this->catalogTable = Db::table('actions');
    }

    public function setHandler(?ActionHandlerInterface $handler): static
    {
        $this->handler = $handler;

        return $this;
    }

    public function setPermissions(?PermissionResolverInterface $permissions): static
    {
        $this->permissions = $permissions;

        return $this;
    }

    // ── catalog ───────────────────────────────────────────────────────────────

    /**
     * Actions the host has registered, tenant rows winning over global ones.
     *
     * @return array<string,array<string,mixed>> keyed by action name
     */
    public function catalog(int $tenantId): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $rows = [];
        foreach ($this->selectCatalog($tenantId) as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $scoped = (int) ($row['company_id'] ?? 0) === $tenantId;
            $existing = $rows[$name] ?? null;
            if ($existing === null || $scoped) {
                $rows[$name] = [
                    'name' => $name,
                    'label' => (string) ($row['label'] ?? $name),
                    'description' => (string) ($row['description'] ?? ''),
                    'permission' => ($row['permission'] ?? null) !== null ? (string) $row['permission'] : null,
                    'requires_confirmation' => (bool) ($row['requires_confirmation'] ?? true),
                    'enabled' => (bool) ($row['enabled'] ?? true),
                    'definition' => Db::decode($row['definition_json'] ?? null),
                ];
            }
        }

        return $rows;
    }

    /** Is this action allowed to run at all in this install? */
    public function isEnabled(string $action, int $tenantId): bool
    {
        $entry = $this->catalog($tenantId)[$action] ?? null;

        return $entry === null ? true : (bool) $entry['enabled'];
    }

    // ── step 1: propose ───────────────────────────────────────────────────────

    /**
     * Preview a change and record it. Nothing is written to the host's tables.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $meta conversation_id, channel, question
     * @return array<string,mixed> id, status, proposal, confirm_token, expires_at
     */
    public function propose(UserContext $user, string $action, array $params, array $meta = []): array
    {
        $action = trim($action);
        if ($action === '') {
            return $this->refused($user, '', $params, 'No action was named.', $meta);
        }
        if ($this->handler === null) {
            return $this->refused($user, $action, $params, 'This app has not registered any actions.', $meta);
        }
        if (!$this->isEnabled($action, $user->tenantId)) {
            return $this->refused($user, $action, $params, '"' . $action . '" is switched off in this app.', $meta);
        }
        if ($this->permissions !== null && !$this->allowed($user, $action)) {
            return $this->refused($user, $action, $params, 'You are not allowed to "' . $action . '".', $meta);
        }

        try {
            $proposal = $this->handler->preview($user, $action, $params);
        } catch (\Throwable $e) {
            $this->log($user, 'ai.action_failed', $action, $params, ['error' => $e->getMessage()], $meta);

            return ['status' => 'failed', 'error' => $e->getMessage(), 'proposal' => null];
        }

        if (!$proposal->valid) {
            $reason = $proposal->errors !== [] ? implode('; ', array_map('strval', $proposal->errors)) : 'the change is not valid';
            $this->log($user, 'ai.action_refused', $action, $params, ['errors' => $proposal->errors], $meta);

            return ['status' => 'invalid', 'error' => $reason, 'proposal' => $proposal->toArray()];
        }

        $id = Db::uuid();
        $expiresIn = max(1, Config::int('actions.confirmation_ttl_minutes', 30));
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn * 60);
        // A random token, stored once and returned once: the caller proves the
        // user clicked confirm by presenting it, and it cannot be re-used.
        $token = bin2hex(random_bytes(16));

        $values = [
            'id' => $id,
            'company_id' => $user->tenantId,
            'user_id' => $user->userId,
            'conversation_id' => ($meta['conversation_id'] ?? null) !== null ? (string) $meta['conversation_id'] : null,
            'channel' => (string) ($meta['channel'] ?? $user->channel ?? null),
            'action' => $action,
            'params_json' => Db::json($params),
            'preview_json' => Db::json($proposal->toArray()),
            'status' => self::STATUS_PROPOSED,
            'confirmation_token' => $token,
            'token_expires_at' => $expiresAt,
            'created_at' => Db::now(),
        ];
        $this->store($values);
        $this->log($user, 'ai.action_proposed', $action, $params, ['id' => $id], $meta);

        return [
            'id' => $id,
            'status' => self::STATUS_PROPOSED,
            'action' => $action,
            'proposal' => $proposal->toArray(),
            'requires_confirmation' => $proposal->requiresConfirmation,
            'confirm_token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    // ── step 2: confirm → execute ─────────────────────────────────────────────

    /**
     * The user approved. Verify the token, then let the host perform the change.
     *
     * @param UserContext|array<string,mixed>|null $user the confirming user; must match the proposer
     * @param string|null $idempotencyKey host-supplied key; a repeat returns the first result
     * @return array<string,mixed>
     */
    public function confirm(string $token, UserContext|array|null $user = null, ?string $idempotencyKey = null): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'status' => 'invalid', 'error' => 'No confirmation token was given.'];
        }

        $row = $this->findByToken($token);
        if ($row === null) {
            return ['ok' => false, 'status' => 'invalid', 'error' => 'That confirmation is not recognised or has already been used.'];
        }

        $status = (string) ($row['status'] ?? '');
        if ($status !== self::STATUS_PROPOSED) {
            // Replaying a confirmation returns what already happened rather than
            // doing it twice — the answer the user needs is already stored.
            return ['ok' => $status === self::STATUS_EXECUTED, 'status' => $status, 'id' => (string) $row['id'],
                'result' => Db::decode($row['result_json'] ?? null), 'action' => (string) $row['action'],
                'already_handled' => true];
        }

        if (($row['token_expires_at'] ?? null) !== null && (string) $row['token_expires_at'] < Db::now()) {
            $this->setStatus((string) $row['id'], self::STATUS_EXPIRED);

            return ['ok' => false, 'status' => self::STATUS_EXPIRED, 'error' => 'That confirmation expired. Ask again and it will be re-checked.'];
        }

        $context = $user === null ? null : (is_array($user) ? UserContext::fromArray($user) : $user);
        $action = (string) $row['action'];
        $params = Db::decode($row['params_json'] ?? null);
        $meta = ['conversation_id' => $row['conversation_id'] ?? null, 'channel' => $row['channel'] ?? null];

        if ($context !== null) {
            if ((int) $row['company_id'] !== $context->tenantId) {
                return $this->refused($context, $action, $params, 'That confirmation belongs to another account.', $meta, (string) $row['id']);
            }
            if ((string) ($row['user_id'] ?? '') !== '' && (string) ($row['user_id'] ?? '') !== $context->userId) {
                return $this->refused($context, $action, $params, 'That confirmation belongs to another user.', $meta, (string) $row['id']);
            }
            if ($this->permissions !== null && !$this->allowed($context, $action)) {
                return $this->refused($context, $action, $params, 'You are not allowed to "' . $action . '".', $meta, (string) $row['id']);
            }
        }

        $key = trim((string) ($idempotencyKey ?? $row['idempotency_key'] ?? $row['id']));
        $replay = $key !== '' ? $this->findByIdempotency($key, (int) $row['company_id']) : null;
        if ($replay !== null && (string) $replay['id'] !== (string) $row['id']) {
            return ['ok' => (string) ($replay['status'] ?? '') === self::STATUS_EXECUTED, 'status' => (string) $replay['status'],
                'id' => (string) $replay['id'], 'result' => Db::decode($replay['result_json'] ?? null), 'replayed' => true];
        }

        if ($this->handler === null) {
            return ['ok' => false, 'status' => self::STATUS_FAILED, 'error' => 'This app has not registered any actions.', 'id' => (string) $row['id']];
        }

        $this->setStatus((string) $row['id'], self::STATUS_CONFIRMED, [
            'idempotency_key' => $key !== '' ? $key : null,
            'approved_at' => Db::now(),
        ]);

        try {
            $result = $this->handler->execute($context ?? UserContext::fromArray(['user_id' => $row['user_id'] ?? 'system', 'tenant_id' => (int) $row['company_id']]), $action, $params, $key);
        } catch (\Throwable $e) {
            $this->finish((string) $row['id'], self::STATUS_FAILED, null, $e->getMessage());

            return ['ok' => false, 'status' => self::STATUS_FAILED, 'error' => $e->getMessage(), 'id' => (string) $row['id']];
        }

        $array = $result instanceof ActionResult ? $result->toArray() : (array) $result;
        $ok = (bool) ($array['ok'] ?? false);
        $this->finish((string) $row['id'], $ok ? self::STATUS_EXECUTED : self::STATUS_FAILED, Db::json($array), $ok ? null : (string) ($array['error'] ?? $array['message'] ?? 'the action failed'));
        $this->log($context, $ok ? 'ai.action_executed' : 'ai.action_failed', $action, $params, ['id' => (string) $row['id'], 'result' => $array], $meta);

        return ['ok' => $ok, 'status' => $ok ? self::STATUS_EXECUTED : self::STATUS_FAILED, 'id' => (string) $row['id'],
            'action' => $action, 'result' => $array];
    }

    /** A user (or a new turn) walked away from a proposal. */
    public function cancel(string $id, UserContext|array|null $user = null): bool
    {
        $row = $this->find($id);
        if ($row === null || (string) ($row['status'] ?? '') !== self::STATUS_PROPOSED) {
            return false;
        }
        $this->setStatus($id, self::STATUS_CANCELLED);
        $this->log($user === null ? null : (is_array($user) ? UserContext::fromArray($user) : $user), 'ai.action_cancelled', (string) $row['action'], Db::decode($row['params_json'] ?? null), ['id' => $id]);

        return true;
    }

    /** Proposal records, newest first. */
    public function history(int $tenantId, ?string $userId = null, int $limit = 50, array $filters = []): array
    {
        $where = ['company_id' => $tenantId] + $filters;
        if ($userId !== null && $userId !== '') {
            $where['user_id'] = $userId;
        }

        return array_map([$this, 'hydrate'], $this->select($where, [], [['created_at', 'desc'], ['id', 'desc']], max(1, $limit)));
    }

    public function get(string $id, int $tenantId): ?array
    {
        $row = $this->find($id);
        if ($row === null || (int) ($row['company_id'] ?? 0) !== $tenantId) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Proposals nobody confirmed in time. Returns how many were swept; a host
     * that never calls this still cannot execute an expired token, because
     * confirm() checks the expiry itself.
     */
    public function expireStale(): int
    {
        if ($this->pdo === null) {
            return 0;
        }
        $stmt = $this->pdo->prepare('UPDATE ' . $this->table . ' SET status = :expired, confirmation_token = NULL'
            . ' WHERE status = :proposed AND token_expires_at IS NOT NULL AND token_expires_at < :now');
        $stmt->execute(['expired' => self::STATUS_EXPIRED, 'proposed' => self::STATUS_PROPOSED, 'now' => Db::now()]);

        return $stmt->rowCount();
    }

    // ── pure logic, asserted directly in tests ────────────────────────────────

    /**
     * Whether one confirmation may be replayed: only a proposal that is still
     * `proposed` and still inside its window.
     */
    public static function consumable(array $row, ?string $now = null): bool
    {
        $at = $now ?? Db::now();

        return (string) ($row['status'] ?? '') === self::STATUS_PROPOSED
            && trim((string) ($row['confirmation_token'] ?? '')) !== ''
            && (($row['token_expires_at'] ?? null) === null || (string) $row['token_expires_at'] >= $at);
    }

    /** The status a finished run gets, from what the host reported. */
    public static function statusOf(mixed $result): string
    {
        if ($result instanceof ActionResult) {
            return $result->ok ? self::STATUS_EXECUTED : self::STATUS_FAILED;
        }
        if (is_array($result)) {
            return (bool) ($result['ok'] ?? $result['success'] ?? false) ? self::STATUS_EXECUTED : self::STATUS_FAILED;
        }

        return $result ? self::STATUS_EXECUTED : self::STATUS_FAILED;
    }

    /** @return array<string,mixed> the shape confirm() returns for a replay */
    public static function replayOf(array $row): array
    {
        $status = (string) ($row['status'] ?? '');

        return [
            'ok' => $status === self::STATUS_EXECUTED,
            'status' => $status,
            'id' => (string) ($row['id'] ?? ''),
            'result' => Db::decode($row['result_json'] ?? null),
            'already_handled' => true,
        ];
    }

    // ── internals ─────────────────────────────────────────────────────────────

    private function allowed(UserContext $user, string $action): bool
    {
        // Without a resolver the host owns authorisation — as it does for every
        // other write. With one, the vote is taken again on confirm: a role
        // change between the proposal and the click must be caught.
        return $this->permissions === null ? true : $this->permissions->canAct($user, $action);
    }

    /** @param array<string,mixed> $meta */
    private function refused(UserContext $user, string $action, array $params, string $reason, array $meta, ?string $existingId = null): array
    {
        if ($existingId !== null) {
            $this->setStatus($existingId, self::STATUS_REFUSED, ['error' => $reason]);
        }
        $this->log($user, 'ai.action_refused', $action, $params, ['reason' => $reason] + $meta, $meta);

        return ['ok' => false, 'status' => self::STATUS_REFUSED, 'error' => $reason, 'id' => $existingId];
    }

    /** @param array<string,mixed> $detail */
    private function log(?UserContext $user, string $event, string $action, array $params, array $detail, array $meta = []): void
    {
        if ($this->audit === null) {
            return;
        }
        $this->audit->log(
            ['company_id' => (int) ($user?->tenantId ?? 0), 'user_id' => $user?->userId, 'channel' => $user?->channel ?? ($meta['channel'] ?? null),
             'conversation_id' => $meta['conversation_id'] ?? null, 'intent' => 'action'],
            $event,
            ['action' => $action, 'params' => array_keys($params)] + $detail
        );
    }

    private function hydrate(array $row): array
    {
        $row['params'] = Db::decode($row['params_json'] ?? null);
        $row['preview'] = Db::decode($row['preview_json'] ?? null);
        $row['result'] = Db::decode($row['result_json'] ?? null);
        unset($row['params_json'], $row['preview_json'], $row['result_json'], $row['confirmation_token']);

        return $row;
    }

    // ── storage seams (overridden in tests; no database = no history) ─────────

    protected function store(array $values): void
    {
        if ($this->pdo === null) {
            return;
        }
        Db::insert($this->pdo, $this->table, $values);
    }

    protected function find(string $id): ?array
    {
        $rows = $this->select(['id' => $id], [], [], 1);

        return $rows[0] ?? null;
    }

    protected function findByToken(string $token): ?array
    {
        $rows = $this->select(['confirmation_token' => $token], [], [], 1);

        return $rows[0] ?? null;
    }

    protected function findByIdempotency(string $key, int $tenantId): ?array
    {
        $rows = $this->select(['idempotency_key' => $key, 'company_id' => $tenantId], [], [['created_at', 'desc']], 1);

        return $rows[0] ?? null;
    }

    protected function setStatus(string $id, string $status, array $extra = []): void
    {
        $this->update($id, ['status' => $status] + $extra);
    }

    protected function finish(string $id, string $status, ?string $resultJson, ?string $error): void
    {
        $this->update($id, [
            'status' => $status,
            'result_json' => $resultJson,
            'error' => $error,
            'executed_at' => Db::now(),
        ]);
    }

    protected function update(string $id, array $values): void
    {
        if ($this->pdo === null || $id === '') {
            return;
        }
        // A null here means "leave the column as it is" — Db::updateWhere skips nulls
        // only when the column is absent, so drop them first.
        $values = array_filter($values, static fn (mixed $v): bool => $v !== null);
        if ($values === []) {
            return;
        }
        Db::updateWhere($this->pdo, $this->table, $values, ['id' => $id], 'updated_at');
    }

    /** @param array<int,array{0:string,1:string}> $order */
    protected function select(array $where, array $like = [], array $order = [], int $limit = 100): array
    {
        if ($this->pdo === null) {
            return [];
        }

        return Db::select($this->pdo, $this->table, $where, $like, $order, $limit);
    }

    /** @return array<int,array<string,mixed>> */
    protected function selectCatalog(int $tenantId): array
    {
        if ($this->pdo === null) {
            return [];
        }

        return Db::select($this->pdo, $this->catalogTable, ['company_id' => [0, $tenantId]], [], [['company_id', 'desc']], 200);
    }
}
