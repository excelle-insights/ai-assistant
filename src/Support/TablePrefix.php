<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * Own-table prefix for the AI package.
 *
 * Resolved through Config, so a host can set it however it likes:
 *   AI_ASSISTANT_TABLE_PREFIX (env)          — highest
 *   Config::set('table_prefix', …)           — programmatic
 *   'table_prefix' in the host config file
 *   AI_WHATSAPP_TABLE_PREFIX (pre-rename alias, still honoured)
 *   ai_assistant                              — default
 *
 * Existing installs are renamed to the canonical prefix by migration, and keep
 * working through the legacy alias until the operator switches env.
 */
final class TablePrefix
{
    /** Active prefix — what the code reads/writes today. */
    public static function get(): string
    {
        $fromConfig = Config::string('table_prefix', '');
        if ($fromConfig !== '') {
            return self::sanitise($fromConfig);
        }
        $p = $_ENV['AI_ASSISTANT_TABLE_PREFIX']
            ?? $_ENV['AI_WHATSAPP_TABLE_PREFIX']
            ?? (getenv('AI_ASSISTANT_TABLE_PREFIX') !== false ? getenv('AI_ASSISTANT_TABLE_PREFIX') : (getenv('AI_WHATSAPP_TABLE_PREFIX') !== false ? getenv('AI_WHATSAPP_TABLE_PREFIX') : 'ai_assistant'));
        return self::sanitise((string) $p);
    }

    /** Legacy prefix — where pre-rename data lives. */
    public static function legacy(): string
    {
        $p = $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? (getenv('AI_WHATSAPP_TABLE_PREFIX') !== false ? getenv('AI_WHATSAPP_TABLE_PREFIX') : 'ai_whatsapp');
        return self::sanitise((string) $p);
    }

    /** Canonical (post-rename) prefix — rename target on fresh installs. */
    public static function canonical(): string
    {
        $p = $_ENV['AI_ASSISTANT_TABLE_PREFIX'] ?? (getenv('AI_ASSISTANT_TABLE_PREFIX') !== false ? getenv('AI_ASSISTANT_TABLE_PREFIX') : 'ai_assistant');
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
