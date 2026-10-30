<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * Channel-neutral chat storage for the in-app assistant: a conversation belongs
 * to a tenant, a user and a thread on some channel; messages carry the answer,
 * its citations, any chart/table payload and token usage.
 *
 * These are what conversation tools (rename, pin, search, share) and multi-turn
 * memory read. {prefix}_sessions stays for the WhatsApp 24h bridge.
 */
final class CreateAiConversationsAndMessages extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();

        $conversations = $this->table($prefix . '_conversations', ['id' => false, 'primary_key' => ['id']]);
        if (!$conversations->exists()) {
            $conversations
                ->addColumn('id', 'string', ['limit' => 36, 'null' => false])
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('user_id', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('channel', 'string', ['limit' => 30, 'default' => 'web'])
                ->addColumn('thread_key', 'string', ['limit' => 190, 'null' => true])
                ->addColumn('title', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('pinned', 'boolean', ['default' => false])
                ->addColumn('shared_token', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('context_json', 'text', ['null' => true])
                ->addColumn('summary', 'text', ['null' => true])
                ->addColumn('message_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('last_activity_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['company_id', 'user_id', 'last_activity_at'], ['name' => $prefix . '_conv_lookup'])
                ->addIndex(['company_id', 'channel', 'thread_key'], ['name' => $prefix . '_conv_thread', 'unique' => true])
                ->addIndex(['shared_token'], ['name' => $prefix . '_conv_share'])
                ->create();
        }

        $messages = $this->table($prefix . '_messages', ['id' => false, 'primary_key' => ['id']]);
        if (!$messages->exists()) {
            $messages
                ->addColumn('id', 'string', ['limit' => 36, 'null' => false])
                ->addColumn('conversation_id', 'string', ['limit' => 36])
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('user_id', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('role', 'string', ['limit' => 20])
                ->addColumn('content', 'text', ['null' => true])
                ->addColumn('content_type', 'string', ['limit' => 20, 'default' => 'text'])
                ->addColumn('intent', 'string', ['limit' => 20, 'null' => true])
                ->addColumn('citations_json', 'text', ['null' => true])
                ->addColumn('data_json', 'text', ['null' => true])
                ->addColumn('chart_json', 'text', ['null' => true])
                ->addColumn('sql_hash', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('model', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('tokens_in', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('tokens_out', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('latency_ms', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'ok'])
                ->addColumn('error', 'text', ['null' => true])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['conversation_id', 'created_at'], ['name' => $prefix . '_msg_conv'])
                ->addIndex(['company_id', 'created_at'], ['name' => $prefix . '_msg_tenant'])
                ->create();
        }
    }
}
