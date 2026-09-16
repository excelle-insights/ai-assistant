<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAiWhatsappSessionsTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? 'ai_whatsapp';
        $table = $this->table($prefix . '_sessions');
        if ($table->exists()) return;

        $table
            ->addColumn('conversation_id', 'integer')
            ->addColumn('company_id', 'integer')
            ->addColumn('history_json', 'text', ['null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['conversation_id'], ['unique' => true])
            ->addIndex(['company_id'])
            ->create();
    }
}
