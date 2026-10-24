<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * Reports, schedules and export history.
 *
 *   _report_definitions      a saved report is a QueryPlan plus layout, not a
 *                            screenshot — it re-runs against live data and re-uses
 *                            the requester's own permissions every time.
 *   _schedules               "pipeline report every Monday 8am" and threshold
 *                            alerts; the host's cron calls SchedulesService::tick()
 *                            and delivers the jobs it hands back.
 *   _exports                 who exported what, when, which format, how many rows
 *                            — the audit trail sensitive exports need, plus the
 *                            download token/async job state for big files.
 */
final class CreateAiReportsSchedulesAndExports extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();

        $reports = $this->table($prefix . '_report_definitions', ['id' => false, 'primary_key' => ['id']]);
        if (!$reports->exists()) {
            $reports
                ->addColumn('id', 'string', ['limit' => 36])
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('owner_user_id', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('name', 'string', ['limit' => 255])
                ->addColumn('folder', 'string', ['limit' => 120, 'null' => true])
                ->addColumn('description', 'text', ['null' => true])
                ->addColumn('definition_json', 'text', ['null' => true])
                ->addColumn('visibility', 'string', ['limit' => 20, 'default' => 'private'])
                ->addColumn('visible_to_json', 'text', ['null' => true])
                ->addColumn('run_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('last_run_at', 'datetime', ['null' => true])
                ->addTimestamps()
                ->addIndex(['company_id', 'folder', 'name'], ['name' => $prefix . '_report_list'])
                ->addIndex(['company_id', 'owner_user_id'], ['name' => $prefix . '_report_owner'])
                ->create();
        }

        $schedules = $this->table($prefix . '_schedules');
        if (!$schedules->exists()) {
            $schedules
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('user_id', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('name', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('type', 'string', ['limit' => 30, 'default' => 'report'])
                ->addColumn('cron', 'string', ['limit' => 60])
                ->addColumn('timezone', 'string', ['limit' => 60, 'null' => true])
                ->addColumn('report_id', 'string', ['limit' => 36, 'null' => true])
                ->addColumn('payload_json', 'text', ['null' => true])
                ->addColumn('channel', 'string', ['limit' => 30, 'default' => 'email'])
                ->addColumn('recipient', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('format', 'string', ['limit' => 20, 'default' => 'pdf'])
                ->addColumn('threshold_json', 'text', ['null' => true])
                ->addColumn('state', 'string', ['limit' => 20, 'default' => 'active'])
                ->addColumn('next_run_at', 'datetime', ['null' => true])
                ->addColumn('last_run_at', 'datetime', ['null' => true])
                ->addColumn('last_status', 'string', ['limit' => 20, 'null' => true])
                ->addColumn('failure_count', 'integer', ['signed' => false, 'default' => 0])
                ->addTimestamps()
                ->addIndex(['state', 'next_run_at'], ['name' => $prefix . '_sched_due'])
                ->addIndex(['company_id', 'user_id'], ['name' => $prefix . '_sched_owner'])
                ->create();
        }

        $exports = $this->table($prefix . '_exports', ['id' => false, 'primary_key' => ['id']]);
        if (!$exports->exists()) {
            $exports
                ->addColumn('id', 'string', ['limit' => 36])
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('user_id', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('conversation_id', 'string', ['limit' => 36, 'null' => true])
                ->addColumn('format', 'string', ['limit' => 20])
                ->addColumn('file_name', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('title', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('question', 'text', ['null' => true])
                ->addColumn('columns_json', 'text', ['null' => true])
                ->addColumn('filters_json', 'text', ['null' => true])
                ->addColumn('row_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('size_bytes', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
                ->addColumn('storage_key', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('download_token', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('error', 'text', ['null' => true])
                ->addColumn('expires_at', 'datetime', ['null' => true])
                ->addColumn('completed_at', 'datetime', ['null' => true])
                ->addTimestamps()
                ->addIndex(['download_token'], ['unique' => true, 'name' => $prefix . '_export_token'])
                ->addIndex(['company_id', 'user_id', 'created_at'], ['name' => $prefix . '_export_owner'])
                ->addIndex(['status'], ['name' => $prefix . '_export_status'])
                ->create();
        }
    }
}
