<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * {prefix}_reference stores one summary row per lookup table (kind=lookup) AND
 * per derived metric (kind=metric). Both previously wrote source_table only, so
 * a metric named after a lookup table overwrote it — the assistant lost the
 * real service/product names and kept only a count.
 *
 * Adds the discriminator plus room for structured values, and makes the unique
 * key (company_id, source_table, kind). Existing rows keep kind='lookup'.
 */
final class AddReferenceKindAndMeta extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $table = $this->table($prefix . '_reference');
        if (!$table->exists()) {
            return;
        }

        if (!$table->hasColumn('kind')) {
            $table->addColumn('kind', 'string', ['limit' => 20, 'default' => 'lookup', 'after' => 'source_table']);
        }
        if (!$table->hasColumn('values_json')) {
            $table->addColumn('values_json', 'text', ['null' => true]);
        }
        if (!$table->hasColumn('row_count')) {
            $table->addColumn('row_count', 'integer', ['null' => true, 'signed' => false, 'default' => null]);
        }
        if (!$table->hasColumn('unit')) {
            $table->addColumn('unit', 'string', ['limit' => 40, 'null' => true]);
        }
        $table->update();

        $oldIndex = 'company_id_source_table';
        $newIndex = $prefix . '_ref_tenant_table_kind';
        if ($table->hasIndexByName($oldIndex)) {
            $table->removeIndexByName($oldIndex);
        }
        if (!$table->hasIndexByName($newIndex)) {
            $table->addIndex(['company_id', 'source_table', 'kind'], ['unique' => true, 'name' => $newIndex]);
        }
        $table->update();
    }
}
