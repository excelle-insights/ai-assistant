<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

use ExcelleInsights\AiAssistant\Dto\Answer;
use ExcelleInsights\AiAssistant\Dto\OutboundMessage;

/**
 * A delivery surface: WhatsApp, a web widget, email, Slack, a public API.
 *
 * The assistant core produces an Answer and never knows how it leaves the
 * building. A channel adapter maps host transport calls onto send() and gives
 * the core a stable thread key.
 */
interface ChannelInterface
{
    /** Channel id used in {prefix}_conversations.channel — 'whatsapp', 'web', … */
    public function id(): string;

    /**
     * Deliver the answer. Implementations must be idempotent per message id if
     * the host can re-deliver the same inbound turn (webhook retries).
     *
     * @return array{ok: bool, error?: string, external_id?: string}
     */
    public function send(Answer $answer, OutboundMessage $message): array;
}
