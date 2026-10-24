<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * {prefix}_config — behavioural settings stored in the host database instead of
 * in env variables: which tables/columns the assistant may read, features,
 * limits, chart rails, export rules, channel behaviour and so on.
 *
 * One row per company; company_id = 0 is the deployment-wide row, read first,
 * and a company row wins key-by-key. A column holds one section as JSON keyed by
 * dotted path, so it stays readable from SQL:
 *
 *   SELECT schema_policy_json FROM ai_assistant_config WHERE company_id = 0;
 *   {"schema_policy.mode":"allowlist","schema_policy.allowed_tables":["orders"]}
 *
 * No row is inserted here: an empty table means "use the config file and env",
 * which is exactly what an existing install expects after `composer update`.
 * Settings are written through Services\ConfigService (AssistantApi's
 * config.save) or directly in SQL; Support\ConfigCatalog lists every editable
 * path, its type and allowed values.
 */
final class CreateAiConfigTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $config = $this->table($prefix . '_config');
        if ($config->exists()) {
            return;
        }

        $config
            ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('schema_policy_json', 'text', ['null' => true])
            ->addColumn('domain_json', 'text', ['null' => true])
            ->addColumn('features_json', 'text', ['null' => true])
            ->addColumn('semantic_json', 'text', ['null' => true])
            ->addColumn('reference_json', 'text', ['null' => true])
            ->addColumn('knowledge_json', 'text', ['null' => true])
            ->addColumn('charts_json', 'text', ['null' => true])
            ->addColumn('limits_json', 'text', ['null' => true])
            ->addColumn('channels_json', 'text', ['null' => true])
            ->addColumn('exports_json', 'text', ['null' => true])
            ->addColumn('documents_json', 'text', ['null' => true])
            ->addColumn('operations_json', 'text', ['null' => true])
            ->addColumn('updated_by', 'string', ['limit' => 100, 'null' => true])
            ->addTimestamps()
            ->addIndex(['company_id'], ['unique' => true, 'name' => $prefix . '_config_company'])
            ->create();
    }
}
