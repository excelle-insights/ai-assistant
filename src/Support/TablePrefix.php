<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * Own-table prefix for the AI package.
 *
 * Canonical:  AI_ASSISTANT_TABLE_PREFIX (default: ai_assistant)
 * Legacy:     AI_WHATSAPP_TABLE_PREFIX  (default: ai_whatsapp)
 *
 * New installs use ai_assistant_*. Existing installs are renamed by migration
 * 20260922000000 (old → new) and keep working via the legacy key until the
 * operator switches env to AI_ASSISTANT_TABLE_PREFIX.
 */
final class TablePrefix
{
    /** Active prefix — what the code reads/writes today. */
    public static function get(): string
    {
        $p = $_ENV['AI_ASSISTANT_TABLE_PREFIX']
            ?? $_ENV['AI_WHATSAPP_TABLE_PREFIX']
            ?? 'ai_assistant';
        return self::sanitise((string) $p);
    }

    /** Legacy prefix — where pre-rename data lives. */
    public static function legacy(): string
    {
        $p = $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? 'ai_whatsapp';
        return self::sanitise((string) $p);
    }

    /** Canonical (post-rename) prefix — rename target on fresh installs. */
    public static function canonical(): string
    {
        $p = $_ENV['AI_ASSISTANT_TABLE_PREFIX'] ?? 'ai_assistant';
        return self::sanitise((string) $p);
    }

    private static function sanitise(string $p): string
    {
        $p = strtolower(trim($p));
        if ($p === '' || !preg_match('/^[a-z0-9_]+$/', $p)) {
            throw new \InvalidArgumentException('Invalid AI table prefix: "' . $p . '"');
        }
        return $p;
    }
}
