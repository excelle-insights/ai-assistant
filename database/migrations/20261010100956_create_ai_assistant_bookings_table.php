<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

final class CreateAiAssistantBookingsTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $table = $this->table($prefix . '_bookings', ['signed' => false]);
        if ($table->exists()) return;

        $table
            ->addColumn('booking_number', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('company_id', 'integer', ['default' => 0])
            ->addColumn('conversation_id', 'integer', ['null' => true])
            ->addColumn('channel', 'string', ['limit' => 20, 'default' => 'whatsapp'])
            ->addColumn('contact_user_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('contact_phone', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('contact_name', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('service', 'text', ['null' => true])
            ->addColumn('preferred_date', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
            ->addColumn('source_message', 'text', ['null' => true])
            ->addColumn('ai_reply', 'text', ['null' => true])
            ->addColumn('confidence', 'decimal', ['precision' => 4, 'scale' => 2, 'default' => 0])
            ->addColumn('metadata', 'text', ['null' => true])
            ->addColumn('confirmed_at', 'datetime', ['null' => true])
            ->addColumn('cancelled_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['booking_number'], ['unique' => true, 'name' => 'uq_bookings_number'])
            ->addIndex(['company_id'])
            ->addIndex(['conversation_id'])
            ->addIndex(['contact_phone'])
            ->addIndex(['contact_user_id'])
            ->addIndex(['status'])
            ->create();
    }
}
