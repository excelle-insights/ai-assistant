<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * {prefix}_queries — the calculations this CRM already has.
 *
 * One row per proven query: the name it is known by, the module it belongs to,
 * what it calculates in business language, the SQL behind it and the file it
 * came from.
 *
 *   name                the name people use ("Payments collected per staff")
 *   module              the part of the application it belongs to
 *   purpose             what it is for, in one or two sentences
 *   sql                 the statement, kept for the admin to read
 *   tables              the tables it touches
 *   source_file         where it was found
 *   description         what it calculates
 *   business_description the same thing in plain business words
 *   status              proposed | active | retired
 *
 * A harvested query is `proposed` until a person confirms it; only an `active`
 * one steers the assistant's plan.
 */
final class CreateAiAssistantQueriesTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $queries = $this->table($prefix . '_queries');
        if ($queries->exists()) {
            return;
        }

        $queries
            ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('name', 'string', ['limit' => 190])
            ->addColumn('module', 'string', ['limit' => 150, 'default' => ''])
            ->addColumn('purpose', 'string', ['limit' => 250, 'default' => ''])
            ->addColumn('sql', 'text', ['null' => true])
            ->addColumn('tables', 'text', ['null' => true])
            ->addColumn('source_file', 'string', ['limit' => 400, 'default' => ''])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('business_description', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'proposed'])
            ->addColumn('meta_json', 'text', ['null' => true])
            ->addColumn('updated_by', 'string', ['limit' => 100, 'null' => true])
            ->addTimestamps()
            ->addIndex(['company_id', 'name'], ['unique' => true, 'name' => $prefix . '_query_name'])
            ->addIndex(['company_id', 'status'], ['name' => $prefix . '_query_status'])
            ->addIndex(['company_id', 'module'], ['name' => $prefix . '_query_module'])
            ->create();
    }
}
