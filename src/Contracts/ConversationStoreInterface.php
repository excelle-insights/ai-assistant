<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

/**
 * Conversation + message persistence, keyed by (tenant, channel, thread) rather
 * than one vendor's numeric conversation id, so any surface can hold history.
 */
interface ConversationStoreInterface
{
    /** Internal conversation id; creates the row when it does not exist. */
    public function touch(int $tenantId, string $channel, string $threadId, array $attributes = []): string;

    /** @param array{role:string,content:string,content_type?:string,citations?:array,meta?:array} $turn */
    public function append(string $conversationId, array $turn): int;

    /**
     * Recent turns, oldest first.
     * @return array<int,array{role:string,content:string,created_at?:string}>
     */
    public function history(string $conversationId, int $limit = 10): array;

    /** @return array<int,array<string,mixed>> */
    public function listForUser(int $tenantId, string $userId, int $limit = 50): array;

    public function rename(string $conversationId, string $title): bool;

    public function setPinned(string $conversationId, bool $pinned): bool;

    public function delete(string $conversationId): bool;

    /** Rolling summary so long threads stay inside the prompt budget. */
    public function setSummary(string $conversationId, string $summary): void;
}
