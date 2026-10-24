<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

use ExcelleInsights\AiAssistant\Dto\AuditEvent;

/**
 * Where the host wants the audit trail. The package writes its own append-only
 * {prefix}_audit table by default (audit.store = 'table'); a host with an
 * existing log/SIEM pipeline implements this and sets audit.store = 'sink'.
 */
interface AuditSinkInterface
{
    public function record(AuditEvent $event): void;

    /** @return array<int,array<string,mixed>> */
    public function query(array $filters = [], int $limit = 100): array;
}
