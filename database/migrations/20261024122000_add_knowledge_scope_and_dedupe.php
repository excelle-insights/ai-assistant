<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * Knowledge was insert-only: re-importing a CSV duplicated every row, there was
 * no way to edit an entry, and everything was visible to every user in the
 * tenant.
 *
 * Adds a content hash for idempotent upserts, a visibility scope (global | role
 * | project | personal, matching how hosts already gate their modules), a page
 * count for documents, and structured metadata for citations.
 */
final class AddKnowledgeScopeAndDedupe extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $table = $this->table($prefix . '_knowledge');
        if (!$table->exists()) {
            return;
        }

        if (!$table->hasColumn('content_hash')) {
            $table->addColumn('content_hash', 'string', ['limit' => 64, 'null' => true]);
        }
        if (!$table->hasColumn('scope')) {
            $table->addColumn('scope', 'string', ['limit' => 20, 'default' => 'global']);
        }
        if (!$table->hasColumn('scope_key')) {
            $table->addColumn('scope_key', 'string', ['limit' => 190, 'null' => true]);
        }
        if (!$table->hasColumn('doc_id')) {
            $table->addColumn('doc_id', 'string', ['limit' => 36, 'null' => true]);
        }
        if (!$table->hasColumn('token_count')) {
            $table->addColumn('token_count', 'integer', ['null' => true, 'signed' => false, 'default' => null]);
        }
        if (!$table->hasColumn('metadata_json')) {
            $table->addColumn('metadata_json', 'text', ['null' => true]);
        }
        if (!$table->hasColumn('updated_by')) {
            $table->addColumn('updated_by', 'string', ['limit' => 100, 'null' => true]);
        }
        if (!$table->hasColumn('pinned')) {
            $table->addColumn('pinned', 'boolean', ['default' => false]);
        }
        $table->update();

        $index = $prefix . '_know_tenant_hash';
        if (!$table->hasIndexByName($index)) {
            $table->addIndex(['company_id', 'content_hash'], ['name' => $index]);
        }
        $scopeIndex = $prefix . '_know_tenant_scope';
        if (!$table->hasIndexByName($scopeIndex)) {
            $table->addIndex(['company_id', 'scope', 'scope_key'], ['name' => $scopeIndex]);
        }
        $table->update();
    }
}
