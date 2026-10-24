<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

/**
 * Who is asking. The package never resolves a user itself: the host maps its
 * own auth layer onto this shape, which keeps the assistant permission-aware
 * without knowing anything about the host's users table.
 */
interface UserContextInterface
{
    /** Stable host user identifier (stringified); '' or 'anonymous' for guests. */
    public function userId(): string;

    /** Tenant scope key stored in the package's tables (company_id). */
    public function tenantId(): int;

    /** Channel the request arrived on: 'web', 'whatsapp', 'email', 'api'… */
    public function channel(): string;

    /** External conversation/thread id on that channel. */
    public function threadId(): string;

    /** Host role names, used by PermissionResolverInterface and knowledge scopes. */
    public function roles(): array;

    /** Free-form host context (display name, locale, branch…) for prompts. */
    public function attributes(): array;
}
