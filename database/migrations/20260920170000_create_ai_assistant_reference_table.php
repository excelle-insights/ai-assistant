<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

final class CreateAiAssistantReferenceTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
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
