<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * How a text date is written, remembered per column.
 *
 * Plenty of applications store dates as text ("25-08-2026"). MySQL cannot
 * compare that with a date, so a query either reads it with the right format
 * or compares strings and gets the wrong months. The shape is detected while
 * the schema syncs and kept here, so every later query reads the column the
 * way it is actually written.
 *
 * An empty value means the column holds real dates and needs no format.
 */
final class AddSchemaCatalogDateFormat extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table(TablePrefix::get() . '_schema');
        if (!$table->exists()) {
            return;
        }

        if (!$table->hasColumn('date_format')) {
            $table->addColumn('date_format', 'string', ['limit' => 30, 'null' => true]);
        }
        $table->update();
    }
}
