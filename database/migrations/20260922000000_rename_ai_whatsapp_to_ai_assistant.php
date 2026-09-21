<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * Rename ai_whatsapp_* → ai_assistant_* (package is no longer WhatsApp-only).
 *
 * - Existing installs: old tables are RENAMED, data preserved. Run AFTER
 *   switching env to AI_ASSISTANT_TABLE_PREFIX=ai_assistant, or before —
 *   both orders work (see below).
 * - Fresh installs: create-migrations already use the new prefix, this is a no-op.
 * - If old AND new both exist (partial manual run): no-op, merge manually.
 *
 * Order independence: this migration compares the legacy prefix
 * (AI_WHATSAPP_TABLE_PREFIX, default ai_whatsapp) with the canonical prefix
 * (AI_ASSISTANT_TABLE_PREFIX, default ai_assistant) and renames whatever
 * legacy tables exist to the canonical names.
 */
final class RenameAiAssistantToAiAssistant extends AbstractMigration
{
    private const SUFFIXES = [
        'knowledge',
        'sessions',
        'queue',
        'training',
        'schema',
        'reference',
    ];

    public function change(): void
    {
        $old = TablePrefix::legacy();
        $new = TablePrefix::canonical();

        if ($old === $new) {
            return;
        }

        foreach (self::SUFFIXES as $suffix) {
            $oldTable = $old . '_' . $suffix;
            $newTable = $new . '_' . $suffix;
            if ($this->hasTable($oldTable) && !$this->hasTable($newTable)) {
                $this->table($oldTable)->rename($newTable)->update();
            }
        }
    }
}
