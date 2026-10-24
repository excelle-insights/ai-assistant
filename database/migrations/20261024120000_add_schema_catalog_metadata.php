<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * The schema catalog is what lets the assistant query an arbitrary application:
 * every table/column plus a human description written by the host's users.
 *
 * Adds the metadata needed to describe a column properly — a display label, a
 * sensitivity class (drives masking), whether it is a measure (drives charts
 * and aggregates), a display format (money/date/percent), and the table's own
 * comment — without touching the application's tables.
 */
final class AddSchemaCatalogMetadata extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table(TablePrefix::get() . '_schema');
        if (!$table->exists()) {
            return;
        }

        $additions = [
            ['label', 'string', ['limit' => 255, 'null' => true]],
            ['table_label', 'string', ['limit' => 255, 'null' => true]],
            ['sensitivity', 'string', ['limit' => 20, 'default' => 'internal']],
            ['is_measure', 'boolean', ['default' => false]],
            ['format', 'string', ['limit' => 30, 'null' => true]],
            ['aliases', 'text', ['null' => true]],
            ['description_source', 'string', ['limit' => 20, 'default' => 'auto']],
        ];

        foreach ($additions as [$name, $type, $options]) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $type, $options);
            }
        }
        $table->update();
    }
}
