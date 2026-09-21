<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

final class CreateAiAssistantKnowledgeTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $table = $this->table($prefix . '_knowledge');
        if ($table->exists()) return;

        $table
            ->addColumn('company_id', 'integer', ['null' => false])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('content', 'text')
            ->addColumn('embedding', 'text', ['null' => true])
            ->addColumn('source', 'string', ['limit' => 50, 'default' => 'manual'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'active'])
            ->addTimestamps()
            ->addIndex(['company_id'])
            ->addIndex(['status'])
            ->create();
    }
}
