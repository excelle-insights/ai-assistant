<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

interface SystemContextProviderInterface
{
    /**
     * Company-level context for prompt (name, industry, services, hours, etc).
     */
    public function getCompanyContext(int $companyId): array;

    /**
     * Customer/conversation context for the inbound message.
     */
    public function getCustomerContext(int $conversationId): array;

    /**
     * Retrieve top-k knowledge chunks for a query (FTS / vector).
     */
    public function getCompanyKnowledge(int $companyId, string $query, int $k = 5): array;

    /**
     * Whether AI may auto-reply for this conversation (business hours, opt-in, etc).
     */
    public function canAutoReply(int $conversationId): bool;
}
