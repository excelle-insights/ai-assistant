<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * {prefix}_relations — what a key column stands for.
 *
 * A CRM stores "staff_id = 12" where the person reads "Peter Otieno". This
 * table remembers that 12 is a member of `staff_member`, that the column to
 * show is `staff_member.user_fname`, and how sure the package is about it.
 *
 * One row per (company, source table, source column):
 *
 *   method      how the relation was found — 'foreign_key' (the database
 *               declares it), 'convention' (the names line up), 'overlap'
 *               (the values line up) or 'manual' (a human wrote it).
 *   confidence  0..1 — how many sampled key values were found on the other
 *               side. 1.0 for a declared foreign key.
 *   status      'proposed' (found, not trusted yet), 'verified' (in use) or
 *               'rejected' (a human said no; it never comes back).
 *
 * Only 'verified' rows reach the semantic layer, and only when the target
 * table passes the host's table policy — the package can never widen access.
 */
final class CreateAiAssistantRelationsTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $relations = $this->table($prefix . '_relations');
        if ($relations->exists()) {
            return;
        }

        $relations
            ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_table', 'string', ['limit' => 120])
            ->addColumn('source_column', 'string', ['limit' => 120])
            ->addColumn('target_table', 'string', ['limit' => 120])
            ->addColumn('target_column', 'string', ['limit' => 120, 'default' => 'id'])
            ->addColumn('label_column', 'string', ['limit' => 120, 'default' => ''])
            ->addColumn('method', 'string', ['limit' => 30, 'default' => 'convention'])
            ->addColumn('confidence', 'decimal', ['precision' => 6, 'scale' => 4, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'proposed'])
            ->addColumn('meta_json', 'text', ['null' => true])
            ->addColumn('updated_by', 'string', ['limit' => 100, 'null' => true])
            ->addTimestamps()
            ->addIndex(
                ['company_id', 'source_table', 'source_column'],
                ['unique' => true, 'name' => $prefix . '_relation_source']
            )
            ->addIndex(['company_id', 'status'], ['name' => $prefix . '_relation_status'])
            ->create();
    }
}
