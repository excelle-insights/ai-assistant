<?php

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Support\TablePrefix;
use Phinx\Migration\AbstractMigration;

/**
 * Persistent quota counters.
 *
 *   _rate_limits   one row per (dimension, scope key, window, bucket).
 *
 * Without it the per-minute and per-hour limits are counted in process memory
 * only, so a host running one PHP process per request would never reach its own
 * cap. Day and month quotas are already authoritative — they roll up into
 * {prefix}_usage_daily — this table is what makes the short windows real.
 *
 * The unique key is not decoration: UsageService increments with
 * Db::upsertIncrements(), which relies on the database raising a duplicate-key
 * conflict (`ON DUPLICATE KEY UPDATE` / `ON CONFLICT DO UPDATE`).
 */
final class CreateAiRateLimitsTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $table = $this->table($prefix . '_rate_limits');
        if ($table->exists()) {
            return;
        }

        $table
            ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('dimension', 'string', ['limit' => 20])
            ->addColumn('scope_key', 'string', ['limit' => 120])
            ->addColumn('window', 'string', ['limit' => 10])
            ->addColumn('bucket', 'string', ['limit' => 20])
            ->addColumn('count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('expires_at', 'datetime', ['null' => true])
            ->addIndex(['dimension', 'scope_key', 'window', 'bucket'], ['unique' => true, 'name' => $prefix . '_rl_key'])
            ->addIndex(['expires_at'], ['name' => $prefix . '_rl_expires'])
            ->addIndex(['company_id'], ['name' => $prefix . '_rl_company'])
            ->create();
    }
}
