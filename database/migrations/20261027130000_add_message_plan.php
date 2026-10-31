<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * The plan behind an answer, kept beside the message.
 *
 * "Include their contact details" has no dataset of its own: it continues the
 * answer before it. The plan of that answer — the dataset, the fields and the
 * filters — is what the next question reads, so the thread stays on one
 * subject instead of asking the model to guess again.
 */
final class AddMessagePlan extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table(TablePrefix::get() . '_messages');
        if (!$table->exists()) {
            return;
        }

        if (!$table->hasColumn('plan_json')) {
            $table->addColumn('plan_json', 'text', ['null' => true]);
        }
        $table->update();
    }
}
