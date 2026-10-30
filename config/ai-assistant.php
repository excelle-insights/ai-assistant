<?php

/**
 * excelle-insights/ai-assistant — package default configuration.
 *
 * This file contains NO application-specific content: no table names from any
 * product, no domain prompt, no currency baked into code. Every value below is
 * either a neutral default or read from the environment, and a host overrides
 * the whole file by publishing its own copy (or handing Config::load() an
 * array) — see docs/03-configuration.md.
 *
 * Precedence (lowest → highest):
 *   package defaults in this file
 *   host config file            (config/ai-assistant.php in the host, or AI_ASSISTANT_CONFIG_FILE)
 *   environment variables       (Support\Config::ENV_MAP)
 *   Config::set('a.b.c', $value)
 */

$env = static function (array $keys, mixed $default = null): mixed {
    foreach ($keys as $key) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value !== false && $value !== null && $value !== '') {
            return $value;
        }
    }
    return $default;
};

$csv = static function (mixed $value): array {
    if ($value === null) {
        return [];
    }
    if (is_array($value)) {
        return array_values(array_map('strval', $value));
    }
    return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
};

$bool = static function (mixed $value, bool $default): bool {
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true);
};

return [
    // Own-table prefix. {prefix}_knowledge, {prefix}_schema, … live in the HOST database.
    'table_prefix' => (string) $env(['AI_ASSISTANT_TABLE_PREFIX', 'AI_WHATSAPP_TABLE_PREFIX'], 'ai_assistant'),

    // ── Tenancy ──────────────────────────────────────────────────────────────
    // company_id is a scoping key inside the package's own tables, never a
    // foreign key. Apps with no company model set AI_ASSISTANT_TENANT_ID=0.
    'tenant' => [
        'column' => (string) $env(['AI_ASSISTANT_TENANT_COLUMN'], 'company_id'),
        'id' => $env(['AI_ASSISTANT_TENANT_ID', 'AI_WHATSAPP_COMPANY_ID']) !== null
            ? (int) $env(['AI_ASSISTANT_TENANT_ID', 'AI_WHATSAPP_COMPANY_ID'])
            : null,
        // Optional legacy fallback resolver: look the tenant up by name in a host
        // table. Leave blank (default) and the package never queries for it.
        'legacy_table' => (string) $env(['AI_ASSISTANT_TENANT_TABLE'], ''),
        'legacy_name_column' => (string) $env(['AI_ASSISTANT_TENANT_NAME_COLUMN'], 'name'),
    ],

    // ── Domain wording ───────────────────────────────────────────────────────
    'domain' => [
        // Your application's name, spoken in the default persona prompt.
        'name' => (string) $env(['APP_NAME', 'AI_ASSISTANT_DOMAIN_NAME'], ''),
        // What the assistant calls itself.
        'assistant_name' => (string) $env(['AI_ASSISTANT_NAME'], ''),
        // Currency symbol used when quoting amounts. Empty = no symbol; the raw
        // number is stated as-is. Set to your local symbol (KSh, $, €…) per app.
        'currency' => (string) $env(['AI_ASSISTANT_CURRENCY', 'APP_CURRENCY'], ''),
        'currency_position' => (string) $env(['AI_ASSISTANT_CURRENCY_POSITION'], 'before'),
        'locale' => (string) $env(['AI_ASSISTANT_LOCALE', 'APP_LOCALE'], 'en'),
        // Reply language. Empty = answer in the language the question came in.
        'language' => (string) $env(['AI_ASSISTANT_LANGUAGE'], ''),
        // false pins every reply to 'language'; true (default) follows the question.
        'multilingual' => $bool($env(['AI_ASSISTANT_MULTILINGUAL']), true),
        // Replaces the assistant persona prompt verbatim when set. Anything else
        // here ("always include VAT", "never offer discounts above 10%", tone,
        // terminology) belongs in custom_instructions, which is appended.
        'system_prompt' => (string) $env(['AI_ASSISTANT_SYSTEM_PROMPT'], ''),
        'custom_instructions' => (string) $env(['AI_ASSISTANT_CUSTOM_INSTRUCTIONS'], ''),
        // Replaces the routing/plan-generation instructions when set. The dataset
        // vocabulary and custom_instructions still travel with it.
        'query_prompt' => (string) $env(['AI_ASSISTANT_SQL_PROMPT'], ''),
    ],

    'timezone' => (string) $env(['AI_ASSISTANT_TIMEZONE', 'APP_TIMEZONE'], 'UTC'),

    // ── LLM provider (any OpenAI-compatible endpoint) ────────────────────────
    'llm' => [
        'provider' => (string) $env(['LLM_PROVIDER'], 'openai'),
        'api_key' => (string) $env(['LLM_API_KEY', 'OPENAI_API_KEY'], ''),
        'base_url' => $env(['LLM_BASE_URL', 'OPENAI_BASE_URL']),
        'model' => (string) $env(['LLM_MODEL', 'OPENAI_MODEL'], 'gpt-4o'),
        'embedding_model' => (string) $env(['LLM_EMBEDDING_MODEL'], 'text-embedding-3-small'),
        'vision_model' => (string) $env(['LLM_VISION_MODEL'], ''),
        'timeout' => (int) $env(['LLM_REQUEST_TIMEOUT', 'OPENAI_REQUEST_TIMEOUT'], 30),
        'whisper_model' => (string) $env(['OPENAI_WHISPER_MODEL'], 'whisper-1'),
    ],
    // Legacy alias — prefer 'llm'.
    'openai' => [
        'api_key' => (string) $env(['LLM_API_KEY', 'OPENAI_API_KEY'], ''),
        'model' => (string) $env(['LLM_MODEL', 'OPENAI_MODEL'], 'gpt-4o'),
        'timeout' => (int) $env(['LLM_REQUEST_TIMEOUT', 'OPENAI_REQUEST_TIMEOUT'], 30),
        'whisper_model' => (string) $env(['OPENAI_WHISPER_MODEL'], 'whisper-1'),
    ],

    // ── Table / column policy (what the assistant may read) ──────────────────
    'schema_policy' => [
        // allowlist: only allowed_tables are queryable (strict, recommended).
        // denylist: everything except blocked_tables (zero-authoring default).
        // all:      everything except the package's own tables.
        'mode' => (string) $env(['AI_ASSISTANT_TABLE_POLICY_MODE'], 'denylist'),
        'allowed_tables' => $csv($env(['AI_ASSISTANT_ALLOWED_TABLES'])),
        'blocked_tables' => $csv($env(['AI_ASSISTANT_BLOCKED_TABLES'])),
        // Never sent to a model or returned in a result. Credentials always are
        // blocked by a floor inside SchemaPolicy, on top of these.
        'blocked_columns' => $csv($env(['AI_ASSISTANT_BLOCKED_COLUMNS'])),
        // Readable but redacted (default: phone/email-like columns when unset).
        'masked_columns' => $csv($env(['AI_ASSISTANT_MASKED_COLUMNS'])),
        'max_rows' => (int) $env(['AI_ASSISTANT_MAX_ROWS'], 200),
        'default_limit' => (int) $env(['AI_ASSISTANT_DEFAULT_LIMIT'], 20),
        'include_own_tables' => $bool($env(['AI_ASSISTANT_INCLUDE_OWN_TABLES']), false),
        // When true, generated SQL must carry a tenant filter for tables that
        // have the tenant column (QueryCompiler adds it; raw SQL is rejected).
        'require_tenant_scope' => $bool($env(['AI_ASSISTANT_REQUIRE_TENANT_SCOPE']), true),
    ],

    // ── Schema catalog (learned structure + user-written descriptions) ───────
    'schema' => [
        // Delete catalog rows for tables/columns that no longer exist in the DB.
        'prune_stale' => $bool($env(['AI_ASSISTANT_SCHEMA_PRUNE']), false),
        // Fill blank descriptions with a generated guess. Hosts are expected to
        // replace guesses with real column meaning (docs/04).
        'auto_describe' => $bool($env(['AI_ASSISTANT_SCHEMA_AUTO_DESCRIBE']), true),
        // Tables the catalog ignores even in denylist mode. The package's own
        // tables are already excluded by schema_policy.include_own_tables=false.
        'skip_tables' => $csv($env(['AI_ASSISTANT_SCHEMA_SKIP_TABLES'])),
        // Budget for the "schema card" sent to the model when it writes SQL.
        'context_max_chars' => (int) $env(['AI_ASSISTANT_SCHEMA_CONTEXT_CHARS'], 14000),
        'context_max_tables' => (int) $env(['AI_ASSISTANT_SCHEMA_CONTEXT_TABLES'], 60),
    ],

    // ── Semantic layer (named metrics/dimensions the assistant may query) ─────
    // A host describes its business once, in its own config, and the assistant
    // then answers with a query plan instead of free-form SQL. With no packs
    // authored the layer is derived from the schema catalog, so this block is
    // optional — see src/Support/DomainPack.php for the shape of a pack.
    'semantic' => [
        'enabled' => $bool($env(['AI_ASSISTANT_SEMANTIC_ENABLED']), true),
        // Derive datasets for tables the packs do not already name.
        'auto_derive' => $bool($env(['AI_ASSISTANT_SEMANTIC_AUTO']), true),
        'packs' => is_string($env(['AI_ASSISTANT_SEMANTIC_PACKS']))
            ? (array) json_decode((string) $env(['AI_ASSISTANT_SEMANTIC_PACKS']), true)
            : [],
        // …or keep the packs as JSON outside the PHP config.
        'pack_file' => (string) $env(['AI_ASSISTANT_SEMANTIC_PACK'], ''),
        'max_dimensions' => (int) $env(['AI_ASSISTANT_SEMANTIC_MAX_DIMENSIONS'], 14),
        'max_datasets' => (int) $env(['AI_ASSISTANT_SEMANTIC_MAX_DATASETS'], 80),
        // Raw SQL is the escape hatch for questions a pack cannot express. Off
        // by default; when on, only the roles below may reach it.
        'allow_raw_sql' => $bool($env(['AI_ASSISTANT_ALLOW_RAW_SQL']), false),
        'raw_sql_roles' => $csv($env(['AI_ASSISTANT_RAW_SQL_ROLES'], 'admin,owner')),
        // Budget for the vocabulary block appended to the routing prompt.
        'vocab_chars' => (int) $env(['AI_ASSISTANT_VOCAB_CHARS'], 5000),
    ],

    // ── Charts (visualisation of any result set) ─────────────────────────────
    // Every key here is a shape hint the ChartService reads; empty/omitted means
    // its own default. Types not listed stay disabled only if you list some.
    // Supported: bar, column, line, area, pie, donut, stacked_bar, funnel,
    // scatter, heatmap, kpi, table, pivot, map (map degrades to table).
    'charts' => [
        'enabled_types' => $csv($env(['AI_ASSISTANT_CHART_TYPES'])),
        // Guard rails: above these the package chooses a table instead.
        'max_rows' => (int) $env(['AI_ASSISTANT_CHART_MAX_ROWS'], 300),
        'max_series' => (int) $env(['AI_ASSISTANT_CHART_MAX_SERIES'], 8),
        'bar_max_categories' => (int) $env(['AI_ASSISTANT_CHART_BAR_CATEGORIES'], 30),
        'pie_max_categories' => (int) $env(['AI_ASSISTANT_CHART_PIE_CATEGORIES'], 12),
        'table_above_rows' => (int) $env(['AI_ASSISTANT_CHART_TABLE_ROWS'], 8),
        'table_above_columns' => (int) $env(['AI_ASSISTANT_CHART_TABLE_COLUMNS'], 6),
        'table_page_size' => (int) $env(['AI_ASSISTANT_CHART_TABLE_PAGE_SIZE'], 25),
        'kpi_max_items' => (int) $env(['AI_ASSISTANT_CHART_KPI_ITEMS'], 6),
        'max_depth' => (int) $env(['AI_ASSISTANT_CHART_MAX_DEPTH'], 12),
        // Drawing hints your front-end may read straight off the spec.
        'compact_axis' => $bool($env(['AI_ASSISTANT_CHART_COMPACT_AXIS']), true),
        'bar_max_width' => (int) $env(['AI_ASSISTANT_CHART_BAR_MAX_WIDTH'], 48),
        'kpi_font_size' => (int) $env(['AI_ASSISTANT_CHART_KPI_FONT_SIZE'], 42),
        'scatter_symbol_size' => (int) $env(['AI_ASSISTANT_CHART_SCATTER_SIZE'], 12),
        'donut_inner_radius' => (string) $env(['AI_ASSISTANT_CHART_DONUT_RADIUS'], '45%'),
        'heatmap_labels' => $bool($env(['AI_ASSISTANT_CHART_HEATMAP_LABELS']), true),
        // Column-name vocabularies. Empty = ChartService's own generic list; add
        // your app's words rather than editing vendor/.
        'time_column_names' => $csv($env(['AI_ASSISTANT_CHART_TIME_COLUMNS'])),
        'month_tokens' => $csv($env(['AI_ASSISTANT_CHART_MONTH_TOKENS'])),
        'id_columns' => $csv($env(['AI_ASSISTANT_CHART_ID_COLUMNS'])),
        'funnel_stage_terms' => $csv($env(['AI_ASSISTANT_CHART_FUNNEL_STAGES'])),
        'latitude_columns' => $csv($env(
            ['AI_ASSISTANT_CHART_LAT_COLUMNS'], 'latitude,lat,lat_deg,gps_latitude,geo_lat'
        )),
        'longitude_columns' => $csv($env(
            ['AI_ASSISTANT_CHART_LNG_COLUMNS'], 'longitude,lng,lon,long,geo_lng,gps_longitude'
        )),
        // Formatting and axis defaults.
        'default_agg' => (string) $env(['AI_ASSISTANT_CHART_DEFAULT_AGG'], 'sum'),
        'default_unit' => (string) $env(['AI_ASSISTANT_CHART_DEFAULT_UNIT'], ''),
        'currency_by_default' => $bool($env(['AI_ASSISTANT_CHART_CURRENCY']), false),
        'multi_axis_label' => (string) $env(['AI_ASSISTANT_CHART_MULTI_AXIS_LABEL'], 'values'),
        // 'value_desc' | 'value_asc' | 'label_asc' — how categories are ordered.
        'sort_categories' => (string) $env(['AI_ASSISTANT_CHART_SORT'], 'value_desc'),
        // A column holding a previous-period value, for the "vs last month" sentence.
        'delta_column' => (string) $env(['AI_ASSISTANT_CHART_DELTA_COLUMN'], ''),
    ],

    // ── Saved reports ────────────────────────────────────────────────────────
    // Empty (default) = a report may name any dataset the semantic layer knows.
    // List dataset names to pin what may be saved and run as a report.
    'reports' => [
        'allowed_datasets' => $csv($env(['AI_ASSISTANT_REPORT_DATASETS'])),
    ],

    // ── Cron schedules (the host's cron drives tick) ─────────────────────────
    'schedules' => [
        // Jobs handed back per tick.
        'tick_limit' => (int) $env(['AI_ASSISTANT_SCHEDULE_TICK_LIMIT'], 200),
        // Consecutive failures before a schedule disables itself. 0 = never.
        'max_consecutive_failures' => (int) $env(['AI_ASSISTANT_SCHEDULE_MAX_FAILURES'], 5),
    ],

    // ── Reference values (lookup tables the assistant can quote) ─────────────
    'reference' => [
        'enabled' => $bool($env(['AI_ASSISTANT_REFERENCE_ENABLED']), true),
        // Explicit tables win. Empty = auto-discover using auto_suffixes.
        'tables' => $csv($env(['AI_ASSISTANT_REFERENCE_TABLES'])),
        'auto_suffixes' => $csv($env(
            ['AI_ASSISTANT_REFERENCE_SUFFIXES'],
            '_types,_categories,_statuses,_units,_levels,_roles,_currencies,_priorities,'
            . '_conditions,_frequencies,_methods,_departments,_positions,_industries,'
            . '_sources,_groups,_classes,_grades,_stages,_channels'
        )),
        'name_columns' => $csv($env(['AI_ASSISTANT_REFERENCE_NAME_COLUMNS'], 'name,title,label')),
        'price_columns' => $csv($env(
            ['AI_ASSISTANT_REFERENCE_PRICE_COLUMNS'],
            'default_amount,price,amount,cost,rate,unit_price,selling_price'
        )),
        'max_values' => (int) $env(['AI_ASSISTANT_REFERENCE_MAX_VALUES'], 50),
        'max_tables' => (int) $env(['AI_ASSISTANT_REFERENCE_MAX_TABLES'], 60),
    ],

    // ── Metrics (replaces the old hardcoded counts) ──────────────────────────
    // 'auto' derives a row count per queryable table (filtered by the tenant
    // column and an is_active column when they exist). 'custom' is a list of
    // ['key'=>..,'label'=>..,'table'=>..,'agg'=>'count|sum|avg|min|max',
    //  'column'=>..,'where'=>..,'tenant'=>true] — set it in a published config
    // file, or as JSON in AI_ASSISTANT_METRICS. Disable with
    // AI_ASSISTANT_METRICS_AUTO=false and no custom list.
    'metrics' => [
        'auto' => $bool($env(['AI_ASSISTANT_METRICS_AUTO']), true),
        // How many tables get an automatic row count (keeps the sync cheap).
        'auto_max_tables' => (int) $env(['AI_ASSISTANT_METRICS_MAX_TABLES'], 40),
        'custom' => is_string($env(['AI_ASSISTANT_METRICS']))
            ? (array) json_decode((string) $env(['AI_ASSISTANT_METRICS']), true)
            : [],
    ],

    // ── Retrieval / knowledge ───────────────────────────────────────────────
    'knowledge' => [
        // hybrid | keyword | vector
        'retrieval' => (string) $env(['AI_ASSISTANT_RETRIEVAL'], 'hybrid'),
        'top_k' => (int) $env(['AI_ASSISTANT_KNOWLEDGE_TOP_K'], 5),
        // Append newest knowledge when nothing matches. Off by default: unrelated
        // knowledge injected into a prompt makes confident wrong answers.
        'fallback_latest' => $bool($env(['AI_ASSISTANT_KNOWLEDGE_FALLBACK_LATEST']), false),
        'min_similarity' => (float) $env(['AI_ASSISTANT_MIN_SIMILARITY'], 0.2),
        // Hybrid retrieval: share of the score that comes from vector similarity
        // (the rest is keyword overlap). Only matters when embeddings exist.
        'vector_weight' => (float) $env(['AI_ASSISTANT_VECTOR_WEIGHT'], 0.6),
        // Learn from every answered exchange (self-training). Recommend leaving
        // it on with a review queue rather than auto-publishing.
        'self_training' => $bool($env(['AI_ASSISTANT_SELF_TRAINING']), true),
        // Rows scanned per search / per dedupe pass.
        'scan_limit' => (int) $env(['AI_ASSISTANT_KNOWLEDGE_SCAN_LIMIT'], 500),
        'dedupe_scan' => (int) $env(['AI_ASSISTANT_KNOWLEDGE_DEDUPE_SCAN'], 2000),
    ],

    // ── Features / limits ────────────────────────────────────────────────────
    'features' => $csv($env(
        ['AI_ASSISTANT_FEATURES'],
        'data_qa,documents,actions,charts,exports,reports,feedback,audit'
    )),
    'limits' => [
        'max_rows' => (int) $env(['AI_ASSISTANT_MAX_ROWS'], 200),
        'max_upload_mb' => (int) $env(['AI_ASSISTANT_MAX_UPLOAD_MB'], 25),
        'max_pages' => (int) $env(['AI_ASSISTANT_MAX_PAGES'], 300),
        'retention_days' => (int) $env(['AI_ASSISTANT_RETENTION_DAYS'], 365),
        'session_turns' => (int) $env(['AI_ASSISTANT_SESSION_TURNS', 'AI_WHATSAPP_SESSION_TURNS'], 10),
        'session_ttl_minutes' => (int) $env(['AI_ASSISTANT_SESSION_TTL'], 60),
        'memory_turns' => (int) $env(['AI_ASSISTANT_MEMORY_TURNS'], 10),
        // Rows a re-opened thread re-renders. 0 keeps table values (decrypted
        // names, for example) out of the conversation store at rest.
        'store_rows' => (int) $env(['AI_ASSISTANT_STORE_ROWS'], 50),
        // Turns kept in the prompt before older ones are summarised away. 0 = never.
        'summarize_after' => (int) $env(['AI_ASSISTANT_SUMMARIZE_AFTER'], 24),
        // Rows a single audit CSV export may contain.
        'audit_export_rows' => (int) $env(['AI_ASSISTANT_AUDIT_EXPORT_ROWS'], 10000),
    ],

    'rate_limit' => [
        'per_hour' => (int) $env(['AI_ASSISTANT_RATE_LIMIT_PER_HOUR', 'AI_RATE_LIMIT_PER_HOUR'], 60),
        'per_day' => (int) $env(['AI_ASSISTANT_RATE_LIMIT_PER_DAY'], 0),
    ],

    // ── Channels ─────────────────────────────────────────────────────────────
    'channels' => [
        'whatsapp' => [
            'enabled' => $bool($env(['AI_ASSISTANT_WHATSAPP_ENABLED']), true),
            // Host's WhatsApp package tables ({prefix}_messages, {prefix}_conversations,
            // {prefix}_queue). Not required for any other channel.
            'table_prefix' => (string) $env(['WHATSAPP_TABLE_PREFIX', 'AI_ASSISTANT_WHATSAPP_TABLE_PREFIX'], 'whatsapp'),
            'auto_reply' => $bool($env(['AI_ASSISTANT_AUTO_REPLY', 'AI_WHATSAPP_AUTO_REPLY']), true),
            'fallback_template_id' => (int) $env(['AI_ASSISTANT_FALLBACK_TEMPLATE', 'AI_WHATSAPP_FALLBACK_TEMPLATE'], 0),
            'booking_hook' => $bool($env(['AI_ASSISTANT_BOOKING_HOOK', 'AI_WHATSAPP_BOOKING_HOOK']), true),
            'session_window_hours' => (int) $env(['AI_ASSISTANT_SESSION_WINDOW_HOURS'], 24),
            // WhatsApp's own text limit is 4096 characters; longer answers are
            // split into consecutive messages at paragraph breaks.
            'max_message_chars' => (int) $env(['AI_ASSISTANT_WHATSAPP_MAX_CHARS'], 4096),
            // Data rows printed inside one chat message before "… n more rows".
            'max_rows_in_message' => (int) $env(['AI_ASSISTANT_WHATSAPP_MAX_ROWS'], 15),
            // Append "Sources: …" to chat replies (the widget shows them separately).
            'show_sources' => $bool($env(['AI_ASSISTANT_WHATSAPP_SOURCES']), true),
        ],
        'web' => ['enabled' => $bool($env(['AI_ASSISTANT_WEB_ENABLED']), true)],
        'email' => ['enabled' => false],
        'slack' => ['enabled' => false],
    ],

    // ── Exports ──────────────────────────────────────────────────────────────
    'exports' => [
        // csv, tsv and json are written by the package; only csv and json are
        // offered by default — add tsv to the list if you want it. xlsx / pdf /
        // docx / gsheets need a host exporter implementing
        // Contracts\ExporterInterface (your app already owns PhpSpreadsheet,
        // html2pdf, Google credentials).
        'formats' => $csv($env(['AI_ASSISTANT_EXPORT_FORMATS'], 'csv,json')),
        'async_above_rows' => (int) $env(['AI_ASSISTANT_EXPORT_ASYNC_ROWS'], 5000),
        // Where generated files live. Empty = <system temp dir>/ai-assistant-exports.
        // Point it at a path the host's download route can read.
        'directory' => (string) $env(['AI_ASSISTANT_EXPORT_DIR'], ''),
        'file_name_prefix' => (string) $env(['AI_ASSISTANT_EXPORT_PREFIX'], 'ai-export'),
        'download_ttl_minutes' => (int) $env(['AI_ASSISTANT_EXPORT_TTL'], 1440),
        // format => class-name|callable, e.g. 'xlsx' => \App\Ai\ExcelExporter::class
        'host_exporters' => [],
        // Sensitive exports (a pii/secret/financial column in the result) stop at
        // `awaiting_approval` unless the requester holds an approval role. Plain
        // exports of non-sensitive columns are written straight away.
        'require_confirmation' => $bool($env(['AI_ASSISTANT_EXPORT_CONFIRM']), true),
        // Never write more than this many rows into one file.
        'max_rows' => (int) $env(['AI_ASSISTANT_EXPORT_MAX_ROWS'], 100000),
        // Roles allowed to export pii/financial columns without a second approval.
        'approval_roles' => $csv($env(['AI_ASSISTANT_EXPORT_APPROVERS'], 'admin,owner')),
        // Delimiters and line endings of the built-in csv/tsv writer.
        // csv_newline takes 'CRLF' (default), 'LF' or 'CR'.
        'csv_delimiter' => (string) $env(['AI_ASSISTANT_EXPORT_CSV_DELIMITER'], ''),
        'csv_newline' => (string) $env(['AI_ASSISTANT_EXPORT_CSV_NEWLINE'], ''),
        'csv_bom' => $bool($env(['AI_ASSISTANT_EXPORT_CSV_BOM']), true),
        'csv_quote_all' => $bool($env(['AI_ASSISTANT_EXPORT_CSV_QUOTE_ALL']), false),
    ],

    // ── Document uploads (contract Q&A, attachments) ─────────────────────────
    'documents' => [
        'allowed_mime' => $csv($env(
            ['AI_ASSISTANT_DOCUMENT_MIME'],
            'text/plain,text/markdown,text/csv,application/pdf,'
            . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
            . 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,image/png,image/jpeg'
        )),
        'chunk_tokens' => (int) $env(['AI_ASSISTANT_CHUNK_TOKENS'], 800),
        'chunk_overlap' => (int) $env(['AI_ASSISTANT_CHUNK_OVERLAP'], 100),
        'max_chunks' => (int) $env(['AI_ASSISTANT_MAX_CHUNKS'], 400),
        // Extra host TextExtractorInterface class names or callables, checked
        // before the built-in extractors (PDF, DOCX, OCR…).
        'extractors' => [],
        // Publish indexed text into {prefix}_knowledge as well as chunks.
        'mirror_to_knowledge' => $bool($env(['AI_ASSISTANT_DOC_MIRROR_KNOWLEDGE']), true),
        // How much mirrored text per chunk, and rows scanned per document search.
        'mirror_chars' => (int) $env(['AI_ASSISTANT_DOC_MIRROR_CHARS'], 1500),
        'scan_limit' => (int) $env(['AI_ASSISTANT_DOCUMENT_SCAN_LIMIT'], 800),
        // Optional external binaries. PDF text works out of the box when
        // poppler's pdftotext is on PATH (or smalot/pdfparser is installed);
        // pdf_binary points at it explicitly. Image OCR stays off until
        // ocr_binary names a tesseract — the package will not guess a binary.
        'pdf_binary' => (string) $env(['AI_ASSISTANT_PDF_BINARY'], ''),
        'ocr_binary' => (string) $env(['AI_ASSISTANT_OCR_BINARY'], ''),
        'ocr_args' => (string) $env(['AI_ASSISTANT_OCR_ARGS'], ''),
        'ocr_stdout' => $bool($env(['AI_ASSISTANT_OCR_STDOUT']), false),
    ],

    'audit' => [
        'enabled' => $bool($env(['AI_ASSISTANT_AUDIT_ENABLED']), true),
        // 'table' writes {prefix}_audit, 'sink' uses a host AuditSinkInterface,
        // 'both' does each. Sensitive hosts can point the sink at their own log.
        // A class name here is treated as 'sink'.
        'store' => (string) $env(['AI_ASSISTANT_AUDIT_STORE'], 'table'),
        'record_sql' => $bool($env(['AI_ASSISTANT_AUDIT_SQL']), true),
    ],

    // ── Actions (the assistant proposes, the host writes) ────────────────────
    'actions' => [
        // Minutes a single-use confirm token stays valid.
        'confirmation_ttl_minutes' => (int) $env(['AI_ASSISTANT_ACTION_TOKEN_TTL'], 30),
    ],

    // ── Outbox (the package stores, your notifier delivers) ──────────────────
    'outbox' => [
        'batch' => (int) $env(['AI_ASSISTANT_OUTBOX_BATCH'], 50),
        'max_attempts' => (int) $env(['AI_ASSISTANT_OUTBOX_ATTEMPTS'], 5),
        // First retry wait; it doubles per attempt and stops at two hours.
        'backoff_seconds' => (int) $env(['AI_ASSISTANT_OUTBOX_BACKOFF'], 60),
    ],

    // Legacy block kept so existing hosts reading config['auto_reply'] still work.
    'auto_reply' => [
        'enabled' => $bool($env(['AI_ASSISTANT_AUTO_REPLY', 'AI_WHATSAPP_AUTO_REPLY']), true),
        'outside_session_use_template' => true,
        'fallback_template_id' => (int) $env(['AI_ASSISTANT_FALLBACK_TEMPLATE', 'AI_WHATSAPP_FALLBACK_TEMPLATE'], 0),
    ],
    'session' => [
        'turns' => (int) $env(['AI_ASSISTANT_SESSION_TURNS', 'AI_WHATSAPP_SESSION_TURNS'], 10),
        'ttl_minutes' => (int) $env(['AI_ASSISTANT_SESSION_TTL'], 60),
    ],
];
