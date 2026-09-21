<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

final class CreateAiAssistantQueueTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $table = $this->table($prefix . '_queue');
        if ($table->exists()) return;

        $table
            ->addColumn('conversation_id', 'integer')
            ->addColumn('wa_message_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
            ->addColumn('payload', 'text', ['null' => true])
            ->addColumn('attempts', 'integer', ['default' => 0])
            ->addColumn('error', 'text', ['null' => true])
            ->addTimestamps()
            ->addIndex(['conversation_id'])
            ->addIndex(['status'])
            ->create();
    }
}
