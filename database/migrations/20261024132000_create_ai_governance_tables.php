<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * Governance and administration.
 *
 *   _audit            append-only trail of every question, action, export and
 *                     report run — required by the "audit log" feature and the
 *                     only way to answer "who asked the AI for this data".
 *   _feedback         thumbs up/down plus reason, feeding a review queue.
 *   _usage_daily      per-tenant/day rollups for quotas, cost and adoption.
 *   _settings         per-tenant / per-role / per-user toggles and custom
 *                     instructions ("always include VAT") without code changes.
 */
final class CreateAiGovernanceTables extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();

        $audit = $this->table($prefix . '_audit');
        if (!$audit->exists()) {
            $audit
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('user_id', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('channel', 'string', ['limit' => 30, 'null' => true])
                ->addColumn('event', 'string', ['limit' => 40])
                ->addColumn('intent', 'string', ['limit' => 20, 'null' => true])
                ->addColumn('correlation_id', 'string', ['limit' => 36, 'null' => true])
                ->addColumn('conversation_id', 'string', ['limit' => 36, 'null' => true])
                ->addColumn('model', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('tokens', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('cost', 'decimal', ['precision' => 12, 'scale' => 6, 'default' => 0])
                ->addColumn('latency_ms', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('query_hash', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('sql_text', 'text', ['null' => true])
                ->addColumn('question', 'text', ['null' => true])
                ->addColumn('record_refs', 'text', ['null' => true])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'ok'])
                ->addColumn('error', 'text', ['null' => true])
                ->addColumn('ip', 'string', ['limit' => 45, 'null' => true])
                ->addColumn('detail_json', 'text', ['null' => true])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addIndex(['company_id', 'created_at'], ['name' => $prefix . '_audit_tenant_time'])
                ->addIndex(['user_id', 'created_at'], ['name' => $prefix . '_audit_user_time'])
                ->addIndex(['correlation_id'], ['name' => $prefix . '_audit_corr'])
                ->addIndex(['event'], ['name' => $prefix . '_audit_event'])
                ->create();
        }

        $feedback = $this->table($prefix . '_feedback');
        if (!$feedback->exists()) {
            $feedback
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('message_id', 'string', ['limit' => 36, 'null' => true])
                ->addColumn('conversation_id', 'string', ['limit' => 36, 'null' => true])
                ->addColumn('user_id', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('rating', 'string', ['limit' => 10])
                ->addColumn('reason', 'string', ['limit' => 60, 'null' => true])
                ->addColumn('comment', 'text', ['null' => true])
                ->addColumn('question', 'text', ['null' => true])
                ->addColumn('answer', 'text', ['null' => true])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
                ->addColumn('reviewed_by', 'string', ['limit' => 100, 'null' => true])
                ->addTimestamps()
                ->addIndex(['company_id', 'status'], ['name' => $prefix . '_fb_status'])
                ->addIndex(['message_id'], ['name' => $prefix . '_fb_message'])
                ->create();
        }

        $usage = $this->table($prefix . '_usage_daily', ['id' => false, 'primary_key' => ['company_id', 'stat_date', 'user_id']]);
        if (!$usage->exists()) {
            $usage
                ->addColumn('company_id', 'integer', ['signed' => false])
                ->addColumn('stat_date', 'date')
                ->addColumn('user_id', 'string', ['limit' => 100, 'default' => ''])
                ->addColumn('chats', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('tokens_in', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('tokens_out', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('cost', 'decimal', ['precision' => 14, 'scale' => 6, 'default' => 0])
                ->addColumn('documents', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('exports', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('actions', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('errors', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('unanswered', 'integer', ['signed' => false, 'default' => 0])
                ->addTimestamps()
                ->addIndex(['stat_date'], ['name' => $prefix . '_usage_date'])
                ->create();
        }

        $settings = $this->table($prefix . '_settings');
        if (!$settings->exists()) {
            $settings
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('scope', 'string', ['limit' => 20, 'default' => 'tenant'])
                ->addColumn('scope_key', 'string', ['limit' => 190, 'default' => ''])
                ->addColumn('key_name', 'string', ['limit' => 100])
                ->addColumn('value_json', 'text', ['null' => true])
                ->addColumn('updated_by', 'string', ['limit' => 100, 'null' => true])
                ->addTimestamps()
                ->addIndex(['company_id', 'scope', 'scope_key', 'key_name'], ['unique' => true, 'name' => $prefix . '_setting_key'])
                ->create();
        }
    }
}
