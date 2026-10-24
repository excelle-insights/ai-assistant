<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * {prefix}_sessions was keyed on one vendor's numeric conversation id, so no
 * other surface could share it. conversation_id stays (the WhatsApp bridge keeps
 * writing it); channel + thread_key give every surface a stable identity.
 *
 * thread_key is (tenant, channel, external thread) as text: '' for a stateless
 * API call, a WhatsApp conversation id, a web session uuid, an email subject
 * hash — whatever the host's channel adapter supplies.
 */
final class GeneraliseAiSessions extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $table = $this->table($prefix . '_sessions');
        if (!$table->exists()) {
            return;
        }

        if (!$table->hasColumn('channel')) {
            $table->addColumn('channel', 'string', ['limit' => 30, 'default' => 'whatsapp']);
        }
        if (!$table->hasColumn('thread_key')) {
            $table->addColumn('thread_key', 'string', ['limit' => 190, 'null' => true]);
        }
        if (!$table->hasColumn('user_id')) {
            $table->addColumn('user_id', 'string', ['limit' => 100, 'null' => true]);
        }
        if (!$table->hasColumn('summary')) {
            $table->addColumn('summary', 'text', ['null' => true]);
        }
        $table->update();

        $index = $prefix . '_sess_channel_thread';
        if (!$table->hasIndexByName($index)) {
            // MySQL unique indexes allow many NULLs, so legacy rows are untouched.
            $table->addIndex(['channel', 'thread_key'], ['unique' => true, 'name' => $index]);
        }
        $userIndex = $prefix . '_sess_tenant_user';
        if (!$table->hasIndexByName($userIndex)) {
            $table->addIndex(['company_id', 'user_id'], ['name' => $userIndex]);
        }
        $table->update();
    }
}
