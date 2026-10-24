<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * {prefix}_exports gains the columns a queued or approved export needs.
 *
 * A job that waits for approval (sensitive columns) or runs in the background
 * (large scope) cannot keep its rows in memory until someone says go, so the
 * query plan is stored instead and the rows are fetched at run time under the
 * requester's permissions. approved_by/approved_at record who let a sensitive
 * file out, which is the audit answer to "who exported customer phone numbers".
 */
final class AddExportPlanAndApproval extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $exports = $this->table($prefix . '_exports');
        if (!$exports->exists()) {
            return;
        }

        if (!$exports->hasColumn('plan_json')) {
            $exports->addColumn('plan_json', 'text', ['null' => true]);
        }
        if (!$exports->hasColumn('approved_by')) {
            $exports->addColumn('approved_by', 'string', ['limit' => 100, 'null' => true]);
        }
        if (!$exports->hasColumn('approved_at')) {
            $exports->addColumn('approved_at', 'datetime', ['null' => true]);
        }
        $exports->update();
    }
}
