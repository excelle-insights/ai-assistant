<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

final class AddTrainingAndCategory extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();

        // category column on knowledge (for CSV organization)
        $knowledge = $this->table($prefix . '_knowledge');
        if (!$knowledge->hasColumn('category')) {
            $knowledge->addColumn('category', 'string', ['limit' => 100, 'null' => true])->save();
        }

        // training table — captures Q&A so the AI can self-learn
        $training = $this->table($prefix . '_training');
        if (!$training->exists()) {
            $training
                ->addColumn('company_id', 'integer', ['signed' => false])
                ->addColumn('question', 'text')
                ->addColumn('answer', 'text')
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['company_id'])
                ->create();
        }
    }
}
