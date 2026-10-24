<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

final class CreateAiAssistantBookingMessagesTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $table = $this->table($prefix . '_booking_messages', ['signed' => false]);
        if ($table->exists()) return;

        $table
            ->addColumn('booking_id', 'integer')
            ->addColumn('sender_type', 'string', ['limit' => 20])
            ->addColumn('sender_name', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('sender_id', 'integer', ['null' => true])
            ->addColumn('message', 'text')
            ->addColumn('is_ai', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['booking_id'])
            ->addIndex(['created_at'])
            ->create();
    }
}
