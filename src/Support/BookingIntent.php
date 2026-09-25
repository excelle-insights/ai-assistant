<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * BookingIntent — generic, host-agnostic value object describing a booking
 * request detected in an AI-assisted conversation.
 *
 * The package detects the intent and extracts the details; the HOST app
 * decides what to do with it (create a booking row, open a ticket, notify
 * staff, …) by implementing BookingHandlerInterface. This object deliberately
 * carries no application table/column knowledge — only conversation facts.
 */
final class BookingIntent
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $conversationId,
        public readonly string $contactPhone,
        public readonly ?string $contactName,
        public readonly string $messageBody,
        public readonly string $aiReply,
        public readonly ?string $service = null,
        /** Preferred date as Y-m-d in the host timezone, or null when unknown. */
        public readonly ?string $preferredDate = null,
        /** 0.0–1.0; 1.0 = explicit request, lower = inferred. */
        public readonly float $confidence = 0.0,
        /** Opaque extras for hosts (e.g. channel, language). No app schema. */
        public readonly array $extra = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'company_id'     => $this->companyId,
            'conversation_id'=> $this->conversationId,
            'contact_phone'  => $this->contactPhone,
            'contact_name'   => $this->contactName,
            'message_body'   => $this->messageBody,
            'ai_reply'       => $this->aiReply,
            'service'        => $this->service,
            'preferred_date' => $this->preferredDate,
            'confidence'     => $this->confidence,
            'extra'          => $this->extra,
        ];
    }
}
