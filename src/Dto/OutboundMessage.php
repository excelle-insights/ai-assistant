<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * What a channel adapter needs in order to deliver an answer: who to send to,
 * what to reply to, and how to stay idempotent when the host re-delivers a turn.
 */
final readonly class OutboundMessage
{
    /** @param array<string,mixed> $meta */
    public function __construct(
        public string $threadId,
        public ?string $recipient = null,
        public ?string $replyToMessageId = null,
        public ?string $idempotencyKey = null,
        public array $meta = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            threadId: (string) ($data['thread_id'] ?? $data['threadId'] ?? $data['conversation_id'] ?? ''),
            recipient: isset($data['recipient']) ? (string) $data['recipient'] : (isset($data['contact_phone']) ? (string) $data['contact_phone'] : null),
            replyToMessageId: isset($data['reply_to_message_id']) ? (string) $data['reply_to_message_id'] : (isset($data['wa_message_id']) ? (string) $data['wa_message_id'] : null),
            idempotencyKey: isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : null,
            meta: (array) ($data['meta'] ?? []),
        );
    }
}
