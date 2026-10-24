<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * `SchedulesService::markRun($id, false, $error)` has always taken the failure
 * text, but there was nowhere to put it, so a schedule that kept failing said
 * only "error". Hosts had to read their own cron log to find out why.
 */
final class AddScheduleLastError extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $table = $this->table($prefix . '_schedules');
        if (!$table->hasColumn('last_error')) {
            $table->addColumn('last_error', 'text', ['null' => true])->update();
        }
    }
}
