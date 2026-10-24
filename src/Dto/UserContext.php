<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

use ExcelleInsights\AiAssistant\Contracts\UserContextInterface;

/**
 * Normalised "who is asking", built from a host UserContextInterface or from a
 * plain array at the HTTP boundary. Internal services take this, never the
 * interface, so they stay testable.
 */
final readonly class UserContext
{
    /**
     * @param string[] $roles
     * @param array<string,mixed> $attributes
     */
    public function __construct(
        public string $userId = 'anonymous',
        public int $tenantId = 0,
        public string $channel = 'web',
        public string $threadId = '',
        public array $roles = [],
        public array $attributes = [],
    ) {
    }

    public static function fromInterface(UserContextInterface $ctx): self
    {
        return new self(
            userId: (string) $ctx->userId(),
            tenantId: (int) $ctx->tenantId(),
            channel: $ctx->channel() !== '' ? $ctx->channel() : 'web',
            threadId: (string) $ctx->threadId(),
            roles: array_values(array_map('strval', $ctx->roles())),
            attributes: $ctx->attributes(),
        );
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: (string) ($data['user_id'] ?? $data['userId'] ?? 'anonymous'),
            tenantId: (int) ($data['tenant_id'] ?? $data['companyId'] ?? $data['company_id'] ?? 0),
            channel: (string) ($data['channel'] ?? 'web'),
            threadId: (string) ($data['thread_id'] ?? $data['threadId'] ?? ''),
            roles: array_values(array_map('strval', (array) ($data['roles'] ?? []))),
            attributes: (array) ($data['attributes'] ?? []),
        );
    }

    public function withThread(string $channel, string $threadId): self
    {
        return new self($this->userId, $this->tenantId, $channel, $threadId, $this->roles, $this->attributes);
    }

    public function withTenant(int $tenantId): self
    {
        return new self($this->userId, $tenantId, $this->channel, $this->threadId, $this->roles, $this->attributes);
    }

    public function isGuest(): bool
    {
        return $this->userId === '' || $this->userId === 'anonymous';
    }

    /** Context array handed to TablePolicyInterface / permission checks. */
    public function policyContext(): array
    {
        return ['user_id' => $this->userId, 'roles' => $this->roles, 'tenant_id' => $this->tenantId, 'channel' => $this->channel];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,
            'channel' => $this->channel,
            'thread_id' => $this->threadId,
            'roles' => $this->roles,
        ];
    }
}
