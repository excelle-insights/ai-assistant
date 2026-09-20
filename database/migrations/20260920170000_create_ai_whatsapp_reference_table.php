<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAiWhatsappReferenceTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? 'ai_whatsapp';
        $table = $this->table($prefix . '_reference');
        if ($table->exists()) return;

        $table
            ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_table', 'string', ['limit' => 255])
            ->addColumn('label', 'string', ['limit' => 255])
            ->addColumn('summary', 'text')
            ->addTimestamps()
            ->addIndex(['company_id', 'source_table'], ['unique' => true])
            ->addIndex(['company_id'])
            ->create();
    }
}
