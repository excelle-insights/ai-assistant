<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

final class CreateAiAssistantSchemaTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $table = $this->table($prefix . '_schema');
        if ($table->exists()) return;

        $table
            ->addColumn('table_name', 'string', ['limit' => 255])
            ->addColumn('column_name', 'string', ['limit' => 255])
            ->addColumn('data_type', 'text')
            ->addColumn('description', 'text', ['null' => true])
            ->addTimestamps()
            ->addIndex(['table_name', 'column_name'], ['unique' => true])
            ->addIndex(['table_name'])
            ->create();
    }
}
