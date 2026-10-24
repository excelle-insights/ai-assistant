<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

/**
 * Outbound notification for the queue, threshold alerts and digests. Rows sit in
 * {prefix}_notification_outbox until the host flushes them; the actual delivery
 * (email, SMS, WhatsApp, Slack, in-app) is the host's, because only the host has
 * credentials. A cron tick hands back due schedules as jobs — queue them here (or
 * send directly) yourself.
 */
interface NotificationInterface
{
    /** @return array{ok: bool, error?: string} */
    public function send(int $tenantId, string $channel, string $recipient, string $subject, string $body, array $attachments = []): array;
}
