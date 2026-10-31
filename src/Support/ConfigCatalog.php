<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * The behavioural settings a host may store in {prefix}_config instead of in
 * env variables or a config file — and the metadata a settings screen needs to
 * render them: type, allowed options, default, label and a one-line help.
 *
 * Each setting belongs to one JSON column of the config row, so a section is
 * readable (and editable) straight from SQL:
 *
 *   SELECT schema_policy_json FROM ai_assistant_config WHERE company_id = 0;
 *
 * This is a whitelist, not a suggestion: Services\ConfigService rejects paths
 * that are not listed here, so a typo cannot silently write a key nothing
 * reads. What is deliberately absent: secrets (llm.api_key), class/callable
 * registrations (exports.host_exporters, documents.extractors) and anything
 * that must exist before the database connection does (table_prefix, tenant.id).
 */
final class ConfigCatalog
{
    /** Column => what lives in it (label + one-line explanation for the UI). */
    public const GROUPS = [
        'schema_policy_json' => [
            'label' => 'Schema & access',
            'help' => 'Which tables and columns the assistant may see, and how wide a query may read.',
        ],
        'domain_json' => [
            'label' => 'Wording & locale',
            'help' => 'Currency, language and the instructions that shape how it speaks.',
        ],
        'features_json' => [
            'label' => 'Features',
            'help' => 'The switches that decide which parts of the package are on.',
        ],
        'semantic_json' => [
            'label' => 'Metrics & semantic layer',
            'help' => 'Named datasets/metrics, and whether raw SQL is an admin escape hatch.',
        ],
        'reference_json' => [
            'label' => 'Reference values',
            'help' => 'Lookup tables the assistant can quote (statuses, categories, prices).',
        ],
        'knowledge_json' => [
            'label' => 'Knowledge retrieval',
            'help' => 'How documents and knowledge entries are searched and injected.',
        ],
        'charts_json' => [
            'label' => 'Charts',
            'help' => 'The palette and guard rails used when visualising a result set.',
        ],
        'limits_json' => [
            'label' => 'Limits & quotas',
            'help' => 'Caps on rows, uploads, retention and requests.',
        ],
        'channels_json' => [
            'label' => 'Channels',
            'help' => 'Web/in-app and WhatsApp behaviour.',
        ],
        'exports_json' => [
            'label' => 'Exports',
            'help' => 'File formats, approval rules, download lifetime and CSV shape.',
        ],
        'documents_json' => [
            'label' => 'Documents',
            'help' => 'Upload types, chunking and the optional PDF/OCR binaries.',
        ],
        'operations_json' => [
            'label' => 'Audit, actions & jobs',
            'help' => 'Audit trail, confirmation tokens, notifications and cron schedules.',
        ],
    ];

    /**
     * path => metadata. `type` is one of bool|int|float|string|list|json|enum.
     *
     * @var array<string,array<string,mixed>>
     */
    private const SETTINGS = [
        // ── Schema & access ──────────────────────────────────────────────────
        'schema_policy.mode' => [
            'column' => 'schema_policy_json', 'type' => 'enum',
            'options' => ['denylist', 'allowlist', 'all'], 'default' => 'denylist',
            'label' => 'Table policy mode',
            'help' => 'allowlist = only the listed tables; denylist = everything except blocked; all = everything but the package\'s own tables.',
        ],
        'schema_policy.allowed_tables' => [
            'column' => 'schema_policy_json', 'type' => 'list', 'default' => [],
            'label' => 'Allowed tables',
            'help' => 'The only queryable tables in allowlist mode. Comma-separated or an array.',
        ],
        'schema_policy.blocked_tables' => [
            'column' => 'schema_policy_json', 'type' => 'list', 'default' => [],
            'label' => 'Blocked tables',
            'help' => 'Never queryable, in every mode.',
        ],
        'schema_policy.blocked_columns' => [
            'column' => 'schema_policy_json', 'type' => 'list', 'default' => [],
            'label' => 'Blocked columns',
            'help' => 'Stripped from prompts, results and SQL. Credential-like names are blocked by a built-in floor anyway.',
        ],
        'schema_policy.masked_columns' => [
            'column' => 'schema_policy_json', 'type' => 'list', 'default' => [],
            'label' => 'Masked columns',
            'help' => 'Readable but redacted (phone, email…). Empty = the package\'s usual contact columns.',
        ],
        'schema_policy.max_rows' => [
            'column' => 'schema_policy_json', 'type' => 'int', 'default' => 200, 'min' => 1, 'max' => 100000,
            'label' => 'Rows per query',
            'help' => 'Hard cap on rows a single answer may read.',
        ],
        'schema_policy.default_limit' => [
            'column' => 'schema_policy_json', 'type' => 'int', 'default' => 20, 'min' => 1, 'max' => 10000,
            'label' => 'Default LIMIT',
        ],
        'schema_policy.include_own_tables' => [
            'column' => 'schema_policy_json', 'type' => 'bool', 'default' => false,
            'label' => 'Let it read its own tables',
        ],
        'schema_policy.require_tenant_scope' => [
            'column' => 'schema_policy_json', 'type' => 'bool', 'default' => true,
            'label' => 'Require tenant scope',
            'help' => 'Every generated query must filter the tenant column; raw SQL is rejected when it does not.',
        ],
        'tenant.column' => [
            'column' => 'schema_policy_json', 'type' => 'string', 'default' => 'company_id',
            'label' => 'Tenant column',
            'help' => 'The host column that scopes rows. Deployment-wide: set it on the company_id = 0 row.',
        ],
        'schema.prune_stale' => [
            'column' => 'schema_policy_json', 'type' => 'bool', 'default' => false,
            'label' => 'Prune deleted columns',
            'help' => 'Drop catalog rows for tables/columns that no longer exist.',
        ],
        'schema.auto_describe' => [
            'column' => 'schema_policy_json', 'type' => 'bool', 'default' => true,
            'label' => 'Guess blank descriptions',
            'help' => 'Fill gaps with generated guesses; replace them with real meanings for good answers.',
        ],
        'schema.sample_rows' => [
            'column' => 'schema_policy_json', 'type' => 'int', 'default' => 25, 'min' => 0,
            'label' => 'Rows sampled per table',
            'help' => 'A sync reads this many values per text column to describe date formats, encrypted columns and status values. 0 = never sample.',
        ],
        'schema.skip_tables' => [
            'column' => 'schema_policy_json', 'type' => 'list', 'default' => [],
            'label' => 'Skip tables in the catalog',
        ],
        'schema.context_max_chars' => [
            'column' => 'schema_policy_json', 'type' => 'int', 'default' => 14000, 'min' => 1000,
            'label' => 'Schema card budget (characters)',
        ],
        'schema.context_max_tables' => [
            'column' => 'schema_policy_json', 'type' => 'int', 'default' => 60, 'min' => 1,
            'label' => 'Schema card tables',
        ],

        // ── Wording & locale ─────────────────────────────────────────────────
        'domain.currency' => [
            'column' => 'domain_json', 'type' => 'string', 'default' => '',
            'label' => 'Currency symbol',
            'help' => 'Empty = plain numbers with no symbol.',
        ],
        'domain.currency_position' => [
            'column' => 'domain_json', 'type' => 'enum', 'options' => ['before', 'after'], 'default' => 'before',
            'label' => 'Symbol position',
        ],
        'domain.locale' => [
            'column' => 'domain_json', 'type' => 'string', 'default' => 'en',
            'label' => 'Locale',
        ],
        'domain.language' => [
            'column' => 'domain_json', 'type' => 'string', 'default' => '',
            'label' => 'Reply language',
            'help' => 'Empty = answer in the language the question arrived in.',
        ],
        'domain.multilingual' => [
            'column' => 'domain_json', 'type' => 'bool', 'default' => true,
            'label' => 'Follow the question\'s language',
        ],
        'domain.custom_instructions' => [
            'column' => 'domain_json', 'type' => 'string', 'default' => '',
            'label' => 'Custom instructions',
            'help' => 'Appended to every grounded answer: tone, rules, "always include VAT".',
        ],
        'domain.system_prompt' => [
            'column' => 'domain_json', 'type' => 'string', 'default' => '',
            'label' => 'Persona prompt override',
            'help' => 'Replaces the persona prompt entirely. Leave empty unless you want full control.',
        ],
        'domain.query_prompt' => [
            'column' => 'domain_json', 'type' => 'string', 'default' => '',
            'label' => 'Query prompt override',
            'help' => 'Replaces the routing/plan instructions; the dataset vocabulary is still appended.',
        ],
        'timezone' => [
            'column' => 'domain_json', 'type' => 'string', 'default' => 'UTC',
            'label' => 'Timezone',
            'help' => 'Relative periods ("today", "this month") resolve in this zone.',
        ],

        // ── Features ────────────────────────────────────────────────────────
        'features' => [
            'column' => 'features_json', 'type' => 'list',
            'default' => ['data_qa', 'documents', 'actions', 'charts', 'exports', 'reports', 'feedback', 'audit'],
            'label' => 'Enabled features',
            'help' => 'Known: data_qa, knowledge, documents, actions, charts, exports, reports, schedules, feedback, audit, outreach.',
        ],

        // ── Semantic layer ──────────────────────────────────────────────────
        'semantic.enabled' => [
            'column' => 'semantic_json', 'type' => 'bool', 'default' => true,
            'label' => 'Use the semantic layer',
        ],
        'semantic.auto_derive' => [
            'column' => 'semantic_json', 'type' => 'bool', 'default' => true,
            'label' => 'Derive datasets automatically',
        ],
        'semantic.packs' => [
            'column' => 'semantic_json', 'type' => 'json', 'default' => [],
            'label' => 'Datasets & metrics pack',
            'help' => 'A JSON object with datasets/metrics, or use semantic.pack_file for a file.',
        ],
        'semantic.max_dimensions' => [
            'column' => 'semantic_json', 'type' => 'int', 'default' => 14, 'min' => 1,
            'label' => 'Max dimensions per dataset',
        ],
        'semantic.max_datasets' => [
            'column' => 'semantic_json', 'type' => 'int', 'default' => 80, 'min' => 1,
            'label' => 'Max datasets',
        ],
        'semantic.allow_raw_sql' => [
            'column' => 'semantic_json', 'type' => 'bool', 'default' => false,
            'label' => 'Allow raw SQL',
            'help' => 'An admin escape hatch; still checked against the table/column policy.',
        ],
        'semantic.raw_sql_roles' => [
            'column' => 'semantic_json', 'type' => 'list', 'default' => ['admin', 'owner'],
            'label' => 'Roles allowed raw SQL',
        ],
        'semantic.vocab_chars' => [
            'column' => 'semantic_json', 'type' => 'int', 'default' => 12000, 'min' => 500,
            'label' => 'Vocabulary budget (characters)',
        ],
        'relations.auto_verify' => [
            'column' => 'semantic_json', 'type' => 'bool', 'default' => true,
            'label' => 'Use well-evidenced relations on their own',
            'help' => 'A relation whose key values match the target table is trusted without a review.',
        ],
        'relations.auto_verify_threshold' => [
            'column' => 'semantic_json', 'type' => 'float', 'default' => 0.9, 'min' => 0.5, 'max' => 1.0,
            'label' => 'How much overlap a relation needs',
            'help' => 'Share of sampled key values that must exist on the other side (0.5 – 1.0).',
        ],
        'relations.sample_limit' => [
            'column' => 'semantic_json', 'type' => 'int', 'default' => 20, 'min' => 2, 'max' => 200,
            'label' => 'Key values checked per relation',
        ],
        'metrics.auto' => [
            'column' => 'semantic_json', 'type' => 'bool', 'default' => true,
            'label' => 'Count rows per table',
        ],
        'metrics.auto_max_tables' => [
            'column' => 'semantic_json', 'type' => 'int', 'default' => 40, 'min' => 1,
            'label' => 'Tables to count',
        ],
        'metrics.custom' => [
            'column' => 'semantic_json', 'type' => 'json', 'default' => [],
            'label' => 'Custom metrics',
            'help' => 'List of {key, label, table, agg, column?, where?, tenant?}.',
        ],

        // ── Reference values ────────────────────────────────────────────────
        'reference.enabled' => [
            'column' => 'reference_json', 'type' => 'bool', 'default' => true,
            'label' => 'Quote reference values',
        ],
        'reference.tables' => [
            'column' => 'reference_json', 'type' => 'list', 'default' => [],
            'label' => 'Reference tables',
            'help' => 'Empty = auto-discover by suffix.',
        ],
        'reference.auto_suffixes' => [
            'column' => 'reference_json', 'type' => 'list',
            'default' => [
                '_types', '_categories', '_statuses', '_units', '_levels', '_roles',
                '_currencies', '_priorities', '_conditions', '_frequencies', '_methods',
                '_departments', '_positions', '_industries', '_sources', '_groups',
                '_classes', '_grades', '_stages', '_channels',
            ],
            'label' => 'Auto-discovery suffixes',
        ],
        'reference.name_columns' => [
            'column' => 'reference_json', 'type' => 'list', 'default' => ['name', 'title', 'label'],
            'label' => 'Name columns',
        ],
        'reference.price_columns' => [
            'column' => 'reference_json', 'type' => 'list',
            'default' => ['default_amount', 'price', 'amount', 'cost', 'rate', 'unit_price', 'selling_price'],
            'label' => 'Price columns',
        ],
        'reference.max_values' => [
            'column' => 'reference_json', 'type' => 'int', 'default' => 50, 'min' => 1,
            'label' => 'Values per reference table',
        ],
        'reference.max_tables' => [
            'column' => 'reference_json', 'type' => 'int', 'default' => 60, 'min' => 1,
            'label' => 'Reference tables scanned',
        ],

        // ── Knowledge ───────────────────────────────────────────────────────
        'knowledge.retrieval' => [
            'column' => 'knowledge_json', 'type' => 'enum',
            'options' => ['hybrid', 'keyword', 'vector'], 'default' => 'hybrid',
            'label' => 'Retrieval mode',
        ],
        'knowledge.top_k' => [
            'column' => 'knowledge_json', 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 50,
            'label' => 'Entries retrieved',
        ],
        'knowledge.fallback_latest' => [
            'column' => 'knowledge_json', 'type' => 'bool', 'default' => false,
            'label' => 'Fall back to newest entries',
            'help' => 'Off by default: unrelated knowledge produces confident wrong answers.',
        ],
        'knowledge.min_similarity' => [
            'column' => 'knowledge_json', 'type' => 'float', 'default' => 0.2, 'min' => 0, 'max' => 1,
            'label' => 'Minimum similarity',
        ],
        'knowledge.vector_weight' => [
            'column' => 'knowledge_json', 'type' => 'float', 'default' => 0.6, 'min' => 0, 'max' => 1,
            'label' => 'Vector share of the score',
        ],
        'knowledge.self_training' => [
            'column' => 'knowledge_json', 'type' => 'bool', 'default' => true,
            'label' => 'Learn from answered exchanges',
        ],
        'knowledge.scan_limit' => [
            'column' => 'knowledge_json', 'type' => 'int', 'default' => 500, 'min' => 1,
            'label' => 'Rows scanned per search',
        ],
        'knowledge.dedupe_scan' => [
            'column' => 'knowledge_json', 'type' => 'int', 'default' => 2000, 'min' => 1,
            'label' => 'Rows scanned for duplicates',
        ],

        // ── Charts ──────────────────────────────────────────────────────────
        'charts.enabled_types' => [
            'column' => 'charts_json', 'type' => 'list', 'default' => [],
            'label' => 'Allowed chart types',
            'help' => 'bar, column, line, area, pie, donut, stacked_bar, funnel, scatter, heatmap, kpi, table, pivot, map. Empty = all.',
        ],
        'charts.max_rows' => [
            'column' => 'charts_json', 'type' => 'int', 'default' => 300, 'min' => 1,
            'label' => 'Rows a chart may use',
        ],
        'charts.max_series' => [
            'column' => 'charts_json', 'type' => 'int', 'default' => 8, 'min' => 1,
            'label' => 'Maximum series',
        ],
        'charts.bar_max_categories' => [
            'column' => 'charts_json', 'type' => 'int', 'default' => 30, 'min' => 1,
            'label' => 'Bar categories',
        ],
        'charts.pie_max_categories' => [
            'column' => 'charts_json', 'type' => 'int', 'default' => 12, 'min' => 1,
            'label' => 'Pie slices',
        ],
        'charts.table_above_rows' => [
            'column' => 'charts_json', 'type' => 'int', 'default' => 8, 'min' => 1,
            'label' => 'Table above N rows',
        ],
        'charts.table_above_columns' => [
            'column' => 'charts_json', 'type' => 'int', 'default' => 6, 'min' => 1,
            'label' => 'Table above N columns',
        ],
        'charts.table_page_size' => [
            'column' => 'charts_json', 'type' => 'int', 'default' => 25, 'min' => 1,
            'label' => 'Table page size',
        ],
        'charts.kpi_max_items' => [
            'column' => 'charts_json', 'type' => 'int', 'default' => 6, 'min' => 1,
            'label' => 'KPI tiles',
        ],
        'charts.max_depth' => [
            'column' => 'charts_json', 'type' => 'int', 'default' => 12, 'min' => 1,
            'label' => 'Pivot depth',
        ],
        'charts.compact_axis' => [
            'column' => 'charts_json', 'type' => 'bool', 'default' => true,
            'label' => 'Compact axis numbers (1.2k)',
        ],
        'charts.bar_max_width' => [
            'column' => 'charts_json', 'type' => 'int', 'default' => 48, 'min' => 1,
            'label' => 'Bar width (px)',
        ],
        'charts.kpi_font_size' => [
            'column' => 'charts_json', 'type' => 'int', 'default' => 42, 'min' => 8,
            'label' => 'KPI font size',
        ],
        'charts.scatter_symbol_size' => [
            'column' => 'charts_json', 'type' => 'int', 'default' => 12, 'min' => 1,
            'label' => 'Scatter point size',
        ],
        'charts.donut_inner_radius' => [
            'column' => 'charts_json', 'type' => 'string', 'default' => '45%',
            'label' => 'Donut inner radius',
        ],
        'charts.heatmap_labels' => [
            'column' => 'charts_json', 'type' => 'bool', 'default' => true,
            'label' => 'Heatmap labels',
        ],
        'charts.time_column_names' => [
            'column' => 'charts_json', 'type' => 'list', 'default' => [],
            'label' => 'Your time columns',
            'help' => 'Extra names treated as dates. Empty = the package\'s generic list.',
        ],
        'charts.month_tokens' => [
            'column' => 'charts_json', 'type' => 'list', 'default' => [],
            'label' => 'Extra month names',
        ],
        'charts.id_columns' => [
            'column' => 'charts_json', 'type' => 'list', 'default' => [],
            'label' => 'Your id columns',
        ],
        'charts.funnel_stage_terms' => [
            'column' => 'charts_json', 'type' => 'list', 'default' => [],
            'label' => 'Funnel stage words',
        ],
        'charts.latitude_columns' => [
            'column' => 'charts_json', 'type' => 'list',
            'default' => ['latitude', 'lat', 'lat_deg', 'gps_latitude', 'geo_lat'],
            'label' => 'Latitude columns',
        ],
        'charts.longitude_columns' => [
            'column' => 'charts_json', 'type' => 'list',
            'default' => ['longitude', 'lng', 'lon', 'long', 'geo_lng', 'gps_longitude'],
            'label' => 'Longitude columns',
        ],
        'charts.default_agg' => [
            'column' => 'charts_json', 'type' => 'enum',
            'options' => ['sum', 'avg', 'min', 'max', 'count'], 'default' => 'sum',
            'label' => 'Default aggregation',
        ],
        'charts.default_unit' => [
            'column' => 'charts_json', 'type' => 'string', 'default' => '',
            'label' => 'Default unit (hours, kg…)',
        ],
        'charts.currency_by_default' => [
            'column' => 'charts_json', 'type' => 'bool', 'default' => false,
            'label' => 'Money formatting by default',
        ],
        'charts.multi_axis_label' => [
            'column' => 'charts_json', 'type' => 'string', 'default' => 'values',
            'label' => 'Second-axis label',
        ],
        'charts.sort_categories' => [
            'column' => 'charts_json', 'type' => 'enum',
            'options' => ['value_desc', 'value_asc', 'label_asc'], 'default' => 'value_desc',
            'label' => 'Category order',
        ],
        'charts.delta_column' => [
            'column' => 'charts_json', 'type' => 'string', 'default' => '',
            'label' => 'Previous-period column',
        ],

        // ── Limits ──────────────────────────────────────────────────────────
        'limits.max_rows' => [
            'column' => 'limits_json', 'type' => 'int', 'default' => 200, 'min' => 1,
            'label' => 'Hard row cap',
            'help' => 'The outer ceiling; schema_policy.max_rows cannot exceed it.',
        ],
        'limits.max_upload_mb' => [
            'column' => 'limits_json', 'type' => 'int', 'default' => 25, 'min' => 1,
            'label' => 'Upload size (MB)',
        ],
        'limits.max_pages' => [
            'column' => 'limits_json', 'type' => 'int', 'default' => 300, 'min' => 1,
            'label' => 'Pages per document',
        ],
        'limits.retention_days' => [
            'column' => 'limits_json', 'type' => 'int', 'default' => 365, 'min' => 1,
            'label' => 'Retention (days)',
        ],
        'limits.session_turns' => [
            'column' => 'limits_json', 'type' => 'int', 'default' => 10, 'min' => 1,
            'label' => 'Session turns',
        ],
        'limits.session_ttl_minutes' => [
            'column' => 'limits_json', 'type' => 'int', 'default' => 60, 'min' => 1,
            'label' => 'Session lifetime (minutes)',
        ],
        'limits.memory_turns' => [
            'column' => 'limits_json', 'type' => 'int', 'default' => 10, 'min' => 1,
            'label' => 'Turns replayed into a prompt',
        ],
        'limits.store_rows' => [
            'column' => 'limits_json', 'type' => 'int', 'default' => 50, 'min' => 0,
            'label' => 'Rows kept in chat history',
            'help' => 'Rows a re-opened thread re-renders. 0 stores none, so values shown in a table (decrypted names, for example) are never written at rest.',
        ],
        'limits.summarize_after' => [
            'column' => 'limits_json', 'type' => 'int', 'default' => 24, 'min' => 0,
            'label' => 'Summarise after N turns',
            'help' => '0 = never summarise.',
        ],
        'limits.audit_export_rows' => [
            'column' => 'limits_json', 'type' => 'int', 'default' => 10000, 'min' => 1,
            'label' => 'Audit CSV rows',
        ],
        'rate_limit.per_hour' => [
            'column' => 'limits_json', 'type' => 'int', 'default' => 60, 'min' => 0,
            'label' => 'Requests per hour',
            'help' => '0 = unlimited.',
        ],
        'rate_limit.per_day' => [
            'column' => 'limits_json', 'type' => 'int', 'default' => 0, 'min' => 0,
            'label' => 'Requests per day',
            'help' => '0 = unlimited.',
        ],

        // ── Channels ────────────────────────────────────────────────────────
        'channels.web.enabled' => [
            'column' => 'channels_json', 'type' => 'bool', 'default' => true,
            'label' => 'Web / in-app assistant',
        ],
        'channels.whatsapp.enabled' => [
            'column' => 'channels_json', 'type' => 'bool', 'default' => true,
            'label' => 'WhatsApp channel',
        ],
        'channels.whatsapp.auto_reply' => [
            'column' => 'channels_json', 'type' => 'bool', 'default' => true,
            'label' => 'Reply automatically',
        ],
        'channels.whatsapp.table_prefix' => [
            'column' => 'channels_json', 'type' => 'string', 'default' => 'whatsapp',
            'label' => 'Your WhatsApp table prefix',
        ],
        'channels.whatsapp.fallback_template_id' => [
            'column' => 'channels_json', 'type' => 'int', 'default' => 0, 'min' => 0,
            'label' => 'Closed-window template id',
        ],
        'channels.whatsapp.booking_hook' => [
            'column' => 'channels_json', 'type' => 'bool', 'default' => true,
            'label' => 'Offer booking intent',
        ],
        'channels.whatsapp.session_window_hours' => [
            'column' => 'channels_json', 'type' => 'int', 'default' => 24, 'min' => 1,
            'label' => 'Session window (hours)',
        ],
        'channels.whatsapp.max_message_chars' => [
            'column' => 'channels_json', 'type' => 'int', 'default' => 4096, 'min' => 100,
            'label' => 'Characters per message',
        ],
        'channels.whatsapp.max_rows_in_message' => [
            'column' => 'channels_json', 'type' => 'int', 'default' => 15, 'min' => 1,
            'label' => 'Data rows per message',
        ],
        'channels.whatsapp.show_sources' => [
            'column' => 'channels_json', 'type' => 'bool', 'default' => true,
            'label' => 'Append "Sources:"',
        ],

        // ── Exports ─────────────────────────────────────────────────────────
        'exports.formats' => [
            'column' => 'exports_json', 'type' => 'list', 'default' => ['csv', 'json'],
            'label' => 'Enabled formats',
            'help' => 'csv, tsv and json are built in; xlsx/pdf/docx need a host exporter class (config file only).',
        ],
        'exports.async_above_rows' => [
            'column' => 'exports_json', 'type' => 'int', 'default' => 5000, 'min' => 1,
            'label' => 'Queue above N rows',
        ],
        'exports.directory' => [
            'column' => 'exports_json', 'type' => 'string', 'default' => '',
            'label' => 'Export directory',
            'help' => 'Empty = <system temp>/ai-assistant-exports. Must be writable by the web user.',
        ],
        'exports.file_name_prefix' => [
            'column' => 'exports_json', 'type' => 'string', 'default' => 'ai-export',
            'label' => 'File name fallback',
        ],
        'exports.download_ttl_minutes' => [
            'column' => 'exports_json', 'type' => 'int', 'default' => 1440, 'min' => 1,
            'label' => 'Download link lifetime (minutes)',
        ],
        'exports.require_confirmation' => [
            'column' => 'exports_json', 'type' => 'bool', 'default' => true,
            'label' => 'Sensitive exports need approval',
        ],
        'exports.approval_roles' => [
            'column' => 'exports_json', 'type' => 'list', 'default' => ['admin', 'owner'],
            'label' => 'Roles that may approve',
        ],
        'exports.max_rows' => [
            'column' => 'exports_json', 'type' => 'int', 'default' => 100000, 'min' => 1,
            'label' => 'Rows per file',
            'help' => 'Extra rows are dropped and the file is marked truncated.',
        ],
        'exports.csv_delimiter' => [
            'column' => 'exports_json', 'type' => 'string', 'default' => ',',
            'label' => 'CSV delimiter',
            'help' => '";" is common for local Excel. Empty = ",".',
        ],
        'exports.csv_newline' => [
            'column' => 'exports_json', 'type' => 'enum', 'options' => ['CRLF', 'LF', 'CR'], 'default' => 'CRLF',
            'label' => 'CSV line ending',
        ],
        'exports.csv_bom' => [
            'column' => 'exports_json', 'type' => 'bool', 'default' => true,
            'label' => 'UTF-8 BOM',
            'help' => 'Excel needs it to notice UTF-8.',
        ],
        'exports.csv_quote_all' => [
            'column' => 'exports_json', 'type' => 'bool', 'default' => false,
            'label' => 'Quote every field',
        ],

        // ── Documents ───────────────────────────────────────────────────────
        'documents.allowed_mime' => [
            'column' => 'documents_json', 'type' => 'list',
            'default' => [
                'text/plain', 'text/markdown', 'text/csv', 'application/pdf',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'image/png', 'image/jpeg',
            ],
            'label' => 'Allowed upload types',
        ],
        'documents.chunk_tokens' => [
            'column' => 'documents_json', 'type' => 'int', 'default' => 800, 'min' => 50,
            'label' => 'Chunk size (tokens)',
        ],
        'documents.chunk_overlap' => [
            'column' => 'documents_json', 'type' => 'int', 'default' => 100, 'min' => 0,
            'label' => 'Chunk overlap',
        ],
        'documents.max_chunks' => [
            'column' => 'documents_json', 'type' => 'int', 'default' => 400, 'min' => 1,
            'label' => 'Chunks per document',
        ],
        'documents.mirror_to_knowledge' => [
            'column' => 'documents_json', 'type' => 'bool', 'default' => true,
            'label' => 'Mirror chunks into knowledge',
        ],
        'documents.mirror_chars' => [
            'column' => 'documents_json', 'type' => 'int', 'default' => 1500, 'min' => 1,
            'label' => 'Mirrored characters per chunk',
        ],
        'documents.scan_limit' => [
            'column' => 'documents_json', 'type' => 'int', 'default' => 800, 'min' => 1,
            'label' => 'Rows scanned per search',
        ],
        'documents.pdf_binary' => [
            'column' => 'documents_json', 'type' => 'string', 'default' => '',
            'label' => 'pdftotext path',
            'help' => 'Empty = find it on PATH (or use smalot/pdfparser when installed).',
        ],
        'documents.ocr_binary' => [
            'column' => 'documents_json', 'type' => 'string', 'default' => '',
            'label' => 'tesseract path',
            'help' => 'Image OCR stays off until you name a binary.',
        ],
        'documents.ocr_args' => [
            'column' => 'documents_json', 'type' => 'string', 'default' => '',
            'label' => 'OCR arguments',
        ],
        'documents.ocr_stdout' => [
            'column' => 'documents_json', 'type' => 'bool', 'default' => false,
            'label' => 'OCR writes to stdout',
        ],

        // ── Audit, actions, jobs ────────────────────────────────────────────
        'audit.enabled' => [
            'column' => 'operations_json', 'type' => 'bool', 'default' => true,
            'label' => 'Audit trail',
        ],
        'audit.store' => [
            'column' => 'operations_json', 'type' => 'string', 'default' => 'table',
            'label' => 'Audit storage',
            'help' => 'table | sink | both.',
        ],
        'audit.record_sql' => [
            'column' => 'operations_json', 'type' => 'bool', 'default' => true,
            'label' => 'Record generated SQL',
        ],
        'actions.confirmation_ttl_minutes' => [
            'column' => 'operations_json', 'type' => 'int', 'default' => 30, 'min' => 1,
            'label' => 'Confirm token lifetime (minutes)',
        ],
        'outbox.batch' => [
            'column' => 'operations_json', 'type' => 'int', 'default' => 50, 'min' => 1,
            'label' => 'Outbox batch size',
        ],
        'outbox.max_attempts' => [
            'column' => 'operations_json', 'type' => 'int', 'default' => 5, 'min' => 1,
            'label' => 'Outbox attempts',
        ],
        'outbox.backoff_seconds' => [
            'column' => 'operations_json', 'type' => 'int', 'default' => 60, 'min' => 1,
            'label' => 'Outbox first retry (seconds)',
        ],
        'reports.allowed_datasets' => [
            'column' => 'operations_json', 'type' => 'list', 'default' => [],
            'label' => 'Datasets allowed in reports',
            'help' => 'Empty = any dataset the semantic layer knows.',
        ],
        'schedules.tick_limit' => [
            'column' => 'operations_json', 'type' => 'int', 'default' => 200, 'min' => 1,
            'label' => 'Jobs per cron tick',
        ],
        'schedules.max_consecutive_failures' => [
            'column' => 'operations_json', 'type' => 'int', 'default' => 5, 'min' => 0,
            'label' => 'Failures before disabling',
            'help' => '0 = never disable.',
        ],

        // ── The CRM's own code ───────────────────────────────────────────────
        'code.scan_paths' => [
            'column' => 'operations_json', 'type' => 'list', 'default' => [],
            'label' => 'Folders to scan',
            'help' => 'Absolute paths to the folders that hold the application code, '
                . 'for example /var/www/app/admin. Empty = the code index stays empty.',
        ],
        'code.max_files' => [
            'column' => 'operations_json', 'type' => 'int', 'default' => 800, 'min' => 1,
            'label' => 'Files per scan',
        ],
        'code.max_size' => [
            'column' => 'operations_json', 'type' => 'int', 'default' => 1048576, 'min' => 1024,
            'label' => 'Largest file read (bytes)',
        ],
        'code.max_depth' => [
            'column' => 'operations_json', 'type' => 'int', 'default' => 8, 'min' => 1,
            'label' => 'How deep to walk',
        ],
        'code.navigation' => [
            'column' => 'operations_json', 'type' => 'list', 'default' => [],
            'label' => 'Navigation map files',
            'help' => 'Files that list the modules, when they do not follow the usual names.',
        ],
        'queries.match_threshold' => [
            'column' => 'operations_json', 'type' => 'float', 'default' => 0.2, 'min' => 0.0, 'max' => 1.0,
            'label' => 'How well a question must match a stored report',
            'help' => 'Below this score a stored report is not offered as the way to answer the question.',
        ],
        'rules.match_threshold' => [
            'column' => 'operations_json', 'type' => 'float', 'default' => 0.15, 'min' => 0.0, 'max' => 1.0,
            'label' => 'How well a question must match a business rule',
            'help' => 'Below this score a rule is not put in front of the router.',
        ],
    ];

    public static function has(string $path): bool
    {
        return isset(self::SETTINGS[$path]);
    }

    /** @return array<string,mixed>|null metadata for one path */
    public static function get(string $path): ?array
    {
        return self::SETTINGS[$path] ?? null;
    }

    /** @return array<int,string> */
    public static function paths(): array
    {
        return array_keys(self::SETTINGS);
    }

    /** @return array<int,string> */
    public static function columns(): array
    {
        return array_keys(self::GROUPS);
    }

    public static function columnFor(string $path): ?string
    {
        return self::SETTINGS[$path]['column'] ?? null;
    }

    /** @return array<int,string> the fields inside every column */
    public static function pathsForColumn(string $column): array
    {
        $out = [];
        foreach (self::SETTINGS as $path => $meta) {
            if (($meta['column'] ?? '') === $column) {
                $out[] = $path;
            }
        }
        return $out;
    }
}
