<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * Data-changing requests run through a two-step flow: the assistant proposes,
 * the user confirms, only then does the host write.
 *
 *   _actions        the catalogue of actions available in this app (registered
 *                   from the domain pack / ActionHandlerInterface, never by
 *                   editing vendor code).
 *   _action_runs    one row per attempt: params, preview, single-use
 *                   confirmation token, idempotency key, result, error.
 *   _notification_outbox  delivery queue for reports and alerts; the host's
 *                   NotificationInterface drains it (email/SMS/WhatsApp/in-app).
 */
final class CreateAiActionsAndOutbox extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();

        $actions = $this->table($prefix . '_actions');
        if (!$actions->exists()) {
            $actions
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('name', 'string', ['limit' => 100])
                ->addColumn('version', 'string', ['limit' => 20, 'default' => '1'])
                ->addColumn('label', 'string', ['limit' => 190, 'null' => true])
                ->addColumn('description', 'text', ['null' => true])
                ->addColumn('permission', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('requires_confirmation', 'boolean', ['default' => true])
                ->addColumn('definition_json', 'text', ['null' => true])
                ->addColumn('enabled', 'boolean', ['default' => true])
                ->addTimestamps()
                ->addIndex(['company_id', 'name', 'version'], ['unique' => true, 'name' => $prefix . '_action_name'])
                ->create();
        }

        $runs = $this->table($prefix . '_action_runs', ['id' => false, 'primary_key' => ['id']]);
        if (!$runs->exists()) {
            $runs
                ->addColumn('id', 'string', ['limit' => 36])
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('user_id', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('conversation_id', 'string', ['limit' => 36, 'null' => true])
                ->addColumn('channel', 'string', ['limit' => 30, 'null' => true])
                ->addColumn('action', 'string', ['limit' => 100])
                ->addColumn('params_json', 'text', ['null' => true])
                ->addColumn('preview_json', 'text', ['null' => true])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'proposed'])
                ->addColumn('confirmation_token', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('token_expires_at', 'datetime', ['null' => true])
                ->addColumn('idempotency_key', 'string', ['limit' => 190, 'null' => true])
                ->addColumn('result_json', 'text', ['null' => true])
                ->addColumn('error', 'text', ['null' => true])
                ->addColumn('approved_at', 'datetime', ['null' => true])
                ->addColumn('executed_at', 'datetime', ['null' => true])
                ->addTimestamps()
                ->addIndex(['confirmation_token'], ['unique' => true, 'name' => $prefix . '_run_token'])
                ->addIndex(['idempotency_key'], ['name' => $prefix . '_run_idem'])
                ->addIndex(['company_id', 'user_id', 'created_at'], ['name' => $prefix . '_run_user'])
                ->addIndex(['status'], ['name' => $prefix . '_run_status'])
                ->create();
        }

        $outbox = $this->table($prefix . '_notification_outbox');
        if (!$outbox->exists()) {
            $outbox
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('user_id', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('channel', 'string', ['limit' => 30, 'default' => 'email'])
                ->addColumn('recipient', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('subject', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('template', 'string', ['limit' => 60, 'null' => true])
                ->addColumn('body', 'text', ['null' => true])
                ->addColumn('payload_json', 'text', ['null' => true])
                ->addColumn('attachments_json', 'text', ['null' => true])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
                ->addColumn('attempts', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('error', 'text', ['null' => true])
                ->addColumn('next_attempt_at', 'datetime', ['null' => true])
                ->addColumn('sent_at', 'datetime', ['null' => true])
                ->addTimestamps()
                ->addIndex(['status', 'next_attempt_at'], ['name' => $prefix . '_outbox_due'])
                ->addIndex(['company_id', 'created_at'], ['name' => $prefix . '_outbox_tenant'])
                ->create();
        }
    }
}
