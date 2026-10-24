<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * {prefix}_messages.seq — the turn number inside a conversation.
 *
 * created_at only has second resolution, so a user message and the assistant's
 * reply to it can share a timestamp and come back from the database in the
 * wrong order. History, memory and the transcript all sort on seq instead.
 */
final class AddMessageSequence extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $messages = $this->table($prefix . '_messages');
        if (!$messages->exists()) {
            return;
        }

        if (!$messages->hasColumn('seq')) {
            $messages->addColumn('seq', 'integer', ['signed' => false, 'default' => 0, 'after' => 'user_id'])->update();
        }
        if (!$messages->hasIndexByName($prefix . '_msg_order')) {
            $messages->addIndex(['conversation_id', 'seq'], ['name' => $prefix . '_msg_order'])->update();
        }
    }
}
