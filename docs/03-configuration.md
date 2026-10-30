# 03 — Configuration reference

Configuration has two homes, on purpose:

| Lives in | Holds | Why |
|----------|-------|-----|
| `.env` (or your config file) | Database connection, LLM endpoint and credentials, the package's table prefix, tenant identity, the assistant's name | Must exist *before* a connection does, and secrets must not sit in a table your application can read |
| `{prefix}_config` (a row per company) | Everything behavioural: table policy, features, wording, charts, limits, exports, documents, channels, audit, schedules | Changes often, differs per company, and belongs to the person running the product — not to the deployer |

Everything below still works through `.env` and a published config file; the
database is simply the *normal* place for behaviour, and env stays the
break-glass override.

- [How a value is resolved](#how-a-value-is-resolved)
- [Settings in the database](#settings-in-the-database)
- [Values, types and coercion](#values-types-and-coercion)
- [Reference, block by block](#reference-block-by-block)
- [Config that cannot come from the database](#config-that-cannot-come-from-the-database)
- [Changing config at runtime](#changing-config-at-runtime)
- [Verifying what the package actually sees](#verifying-what-the-package-actually-sees)

---

## How a value is resolved

Lower number = weaker. A later layer wins over an earlier one.

| # | Layer | Where it lives |
|---|-------|----------------|
| 1 | Built-in floor | `Support\Config::DEFAULTS` — always present, so a missing key never breaks anything |
| 2 | Package default file | `config/ai-assistant.php` inside the package (reads legacy env keys itself) |
| 3 | **Your** config file | `config/ai-assistant.php` in your project root, or the path in `AI_ASSISTANT_CONFIG_FILE` |
| 4 | `Config::load($array)` / `Config::loadFile($path)` | your boot code |
| 5 | **Settings store** | `{prefix}_config` — the row for the current company, then the `company_id = 0` row |
| 6 | Environment variables | `Config::ENV_MAP` — only keys that are actually set |
| 7 | `Config::set('path.to.key', $value)` | programmatic; wins over everything |

Two asymmetries worth knowing:

- **Env beats the database.** That is deliberate: when a settings row causes
  trouble, one environment variable takes the value back without SQL. Everything
  you set in a settings screen shows `source` = `database`; when an env key
  shadows it, `source` becomes `env` and `settings['overrides']` still lists the
  stored value. `config.get` shows both, so the disagreement is visible.
- **Env beats your file only for keys in `ENV_MAP`.** Keys read by the package
  file's own env closures (`charts.*`, `schedules.*`, `exports.csv_*`,
  `documents.ocr_*`, `semantic.vocab_chars`…) sit *below* your published file.

Practical rule: put connections and secrets in `.env`; put behaviour in the
settings screen / `{prefix}_config`; reach for a config file when you need
arrays, class names or callables the database deliberately refuses to hold.

Discovery of layer 3 is automatic: the first ancestor directory that contains a
`vendor/` folder is taken as your project root and `<root>/config/ai-assistant.php`
is read. The package never mistakes its own file for yours.

## Settings in the database

### The table

The migration `20261025120000_create_ai_config_table.php` creates:

```
{prefix}_config
  company_id           0 = deployment-wide row, any other value = that company
  schema_policy_json   ┐
  domain_json          │
  features_json        │ one JSON object per section, keyed by the setting's
  semantic_json        │ full dotted path
  reference_json       │
  knowledge_json       │  {"schema_policy.mode":"allowlist",
  charts_json          │   "schema_policy.allowed_tables":["orders","customers"]}
  limits_json          │
  channels_json        │
  exports_json         │
  documents_json       │
  operations_json      ┘
  updated_by           who touched it last (from the API user or the caller)
  created_at, updated_at
  UNIQUE (company_id)
```

One row per company, one column per section: a section is readable and editable
straight from SQL while adding a setting later never needs another migration.

```sql
SELECT schema_policy_json FROM ai_assistant_config WHERE company_id = 0;

UPDATE ai_assistant_config
   SET schema_policy_json = '{"schema_policy.mode":"allowlist",
                              "schema_policy.allowed_tables":["orders","customers"]}'
 WHERE company_id = 0;
```

There is no seeded row. No row = no overrides = the file/env/defaults answer, so
installing or upgrading before migrating changes nothing about behaviour.
Company **0 is read first, then the company's own row wins key by key** — a
global default can be set for the whole deployment and one company can still
override only the keys it needs.

### The catalogue — what may be stored

`Support\ConfigCatalog` is a whitelist of ~130 paths and the metadata a settings
screen needs: type, allowed options, default, label, help text, minimum and
maximum. `save()` rejects a path that is not listed, so a typo cannot write a
key that no code reads.

| Column / section | Paths it holds |
|------------------|----------------|
| `schema_policy_json` — Schema & access | `schema_policy.*`, `schema.*`, `tenant.column` |
| `domain_json` — Wording & locale | `domain.currency`, `domain.currency_position`, `domain.locale`, `domain.language`, `domain.multilingual`, `domain.custom_instructions`, `domain.system_prompt`, `domain.query_prompt`, `timezone` |
| `features_json` — Features | `features` |
| `semantic_json` — Metrics & semantic layer | `semantic.*`, `metrics.*` |
| `reference_json` — Reference values | `reference.*` |
| `knowledge_json` — Knowledge retrieval | `knowledge.*` |
| `charts_json` — Charts | `charts.*` |
| `limits_json` — Limits & quotas | `limits.*`, `rate_limit.*` |
| `channels_json` — Channels | `channels.web.*`, `channels.whatsapp.*` |
| `exports_json` — Exports | `exports.*` except `host_exporters` |
| `documents_json` — Documents | `documents.*` except `extractors` |
| `operations_json` — Audit, actions & jobs | `audit.*`, `actions.*`, `outbox.*`, `reports.allowed_datasets`, `schedules.*` |

Deliberately **absent** from the catalogue, and therefore not storable in the
table:

- secrets — `llm.api_key`, transcribe keys, extra gateway headers;
- class and callable registrations — `exports.host_exporters`,
  `documents.extractors` (a JSON table cannot name a class safely);
- anything needed *before* the connection exists — `table_prefix`, `tenant.id`,
  `tenant.legacy_table`, `llm.*`;
- identity — `domain.name`, `domain.assistant_name`. The assistant's name is a
  deployment fact, not a per-company preference; it stays in `.env`.

### Driving it from the API

```php
$api = new AssistantApi($pdo);

// 1. What can be set, grouped into sections, with today's values and source
$schema = $api->handle('config.describe', ['company_id' => 7]);
foreach ($schema['sections'] as $section) {
    // $section['column'] = 'charts_json', 'label' = 'Charts', 'settings' = [...]
    // each setting: path, label, help, type, options, min, max, default,
    //               value, source (database|env|file|set|default)
}

// 2. One path, or every override at once
$api->handle('config.get', ['company_id' => 7, 'path' => 'schema_policy.mode']);
$api->handle('config.get', ['company_id' => 7]);   // ['settings' => …, 'effective' => …]

// 3. Save — nested input is fine, values are coerced and validated
$api->handle('config.save', [
    'company_id' => 7,
    'settings' => [
        'schema_policy' => [
            'mode' => 'allowlist',
            'allowed_tables' => 'orders, customers, leads',   // CSV is fine
            'require_tenant_scope' => 'yes',                  // yes/no is fine
        ],
        'charts' => ['max_rows' => '42'],                     // numeric string is fine
        'exports' => ['csv_delimiter' => ';'],
    ],
    'updated_by' => 'admin@acme',
]);

// 4. Forget — the path falls back to file/env again
$api->handle('config.forget', ['company_id' => 7, 'paths' => ['charts.max_rows']]);
```

`config.save` returns exactly what happened, so a settings screen can point at
the offending field:

```php
[
  'saved'    => ['schema_policy.mode', 'schema_policy.allowed_tables', …],
  'cleared'  => ['charts.max_rows'],           // value was null
  'errors'   => ['schema_policy.mode' => 'Expected one of: denylist, allowlist, all.'],
  'unknown'  => ['nope'],                      // path not in the catalogue
  'settings' => [ …overrides for this company, after the write… ],
]
```

A valid sibling still saves when another path in the same call is rejected — one
bad field never discards the rest. A `null` value clears that one override (same
as `config.forget`). Saving company `0` writes the deployment-wide row.

Public methods exist too: `$api->describeConfig()`, `$api->getConfig()`,
`$api->saveConfig()`, `$api->forgetConfig()`. They are thin wrappers over the
same service, so a UI can skip `handle()` and call them directly.

### Hosts that do not use `AssistantApi`

The settings layer belongs to `Support\Config`, not to the HTTP facade. Attach it
once at boot:

```php
use ExcelleInsights\AiAssistant\Services\ConfigService;
use ExcelleInsights\AiAssistant\Support\Config;

$settings = new ConfigService($pdo);
Config::useSettings(fn () => $settings->overrides(/* current company */ 0));

Config::get('schema_policy.mode');   // now answers from the database
```

- The loader is lazy: the row is read on the first lookup, once per process.
- It may itself call `Config` (for the table prefix and tenant) — a re-entrancy
  guard makes that safe instead of recursive.
- A loader that throws contributes nothing; a missing table contributes nothing.
  Neither breaks an install that upgraded code before migrating.
- After writing settings in the same process, call `Config::refreshSettings()`.
  `AssistantApi` does that for `config.save` / `config.forget` already.
- `AssistantApi` attaches the layer itself. Turn it off with
  `new AssistantApi($pdo, [], ['settings' => ['enabled' => false]])` — useful in
  tests, or when a host prefers to own the layer.

`Config::sourceOf('schema_policy.mode')` answers `database`, `env`, `set`,
`file` or `default`, and `Config::settingsValues()` returns the raw overrides —
the two calls to reach for when a value is not what you set.

### What this replaces

`{prefix}_settings` ([Changing config at runtime](#changing-config-at-runtime))
still exists and still wins for its own keys: it is the per-user / per-role
overlay for an individual request. `{prefix}_config` is the durable, per-company
default that everyone in that company sees. Use the first for "this admin wants
20 rows in the dashboard", the second for "this company allows these tables".

---

## Reference, block by block

Dotted path = the config key, `column` = where it lives in `{prefix}_config`.
Every path shown also has an `.env` key or config-file position, named in the
"env" column where one exists; env still overrides the database. (Every
`charts.*` key has an env key too; that table omits the column for width.)

### `.env` only — connections, identity, table names

| Key | Default | env |
|-----|---------|-----|
| `table_prefix` | `ai_assistant` | `AI_ASSISTANT_TABLE_PREFIX` (legacy `AI_WHATSAPP_TABLE_PREFIX`) |
| `tenant.id` | `null` | `AI_ASSISTANT_TENANT_ID` (legacy `AI_WHATSAPP_COMPANY_ID`) |
| `tenant.legacy_table` | `''` | `AI_ASSISTANT_TENANT_TABLE` |
| `tenant.legacy_name_column` | `name` | — |
| `domain.name` | `''` | `AI_ASSISTANT_DOMAIN_NAME`, `APP_NAME` |
| `domain.assistant_name` | `''` | `AI_ASSISTANT_NAME` |
| `llm.provider` | `openai` | `LLM_PROVIDER` |
| `llm.api_key` | `''` | `LLM_API_KEY`, `OPENAI_API_KEY` |
| `llm.base_url` | provider default | `LLM_BASE_URL`, `OPENAI_BASE_URL` |
| `llm.model` | `gpt-4o` | `LLM_MODEL`, `OPENAI_MODEL` |
| `llm.embedding_model` | `text-embedding-3-small` | `LLM_EMBEDDING_MODEL` |
| `llm.vision_model` | `''` (no image answers) | `LLM_VISION_MODEL` |
| `llm.timeout` | `30` | `LLM_REQUEST_TIMEOUT`, `OPENAI_REQUEST_TIMEOUT` |
| `llm.whisper_model` | `whisper-1` | `OPENAI_WHISPER_MODEL` |
| `llm.transcribe_base_url` / `llm.transcribe_api_key` | falls back to the chat values | `LLM_TRANSCRIBE_BASE_URL`, `LLM_TRANSCRIBE_API_KEY` |
| `llm.extra_headers` | `[]` | `LLM_EXTRA_HEADERS` (JSON) |

`table_prefix` names the package's own tables (`{prefix}_knowledge`,
`{prefix}_schema`, `{prefix}_config`, …); the migrations create them in **your**
database. Changing it after installing means the tables keep their old names —
see [01 — Installation](01-installation.md).

`tenant.column` **is** editable in the database, and must be set on the
`company_id = 0` row: it describes the host, not one company. `company_id` is
only a scoping integer inside the package tables — there is never a foreign key
into your schema. No company model? `AI_ASSISTANT_TENANT_ID=0` and pass `0`
everywhere; nothing then queries your tables.

Any OpenAI-compatible endpoint works, including local ones — see
[13 — LLM providers](13-llm-providers.md). Without an API key the package still
runs; it just cannot call a model (`diagnostics()['llm']['ready']` is `false`).
An `openai` block is kept as a legacy alias for the same four values.

### `schema_policy` — what it may read (`schema_policy_json`)

| Key | Default | Type | env |
|-----|---------|------|-----|
| `schema_policy.mode` | `denylist` | `denylist` · `allowlist` · `all` | `AI_ASSISTANT_TABLE_POLICY_MODE` |
| `schema_policy.allowed_tables` | `[]` | list | `AI_ASSISTANT_ALLOWED_TABLES` |
| `schema_policy.blocked_tables` | `[]` | list | `AI_ASSISTANT_BLOCKED_TABLES` |
| `schema_policy.blocked_columns` | `[]` | list | `AI_ASSISTANT_BLOCKED_COLUMNS` |
| `schema_policy.masked_columns` | `[]` | list | `AI_ASSISTANT_MASKED_COLUMNS` |
| `schema_policy.max_rows` | `200` | int | `AI_ASSISTANT_MAX_ROWS` |
| `schema_policy.default_limit` | `20` | int | `AI_ASSISTANT_DEFAULT_LIMIT` |
| `schema_policy.include_own_tables` | `false` | yes/no | `AI_ASSISTANT_INCLUDE_OWN_TABLES` |
| `schema_policy.require_tenant_scope` | `true` | yes/no | `AI_ASSISTANT_REQUIRE_TENANT_SCOPE` |
| `schema.skip_tables` | `[]` | list | `AI_ASSISTANT_SCHEMA_SKIP_TABLES` |

`allowlist` is the production recommendation: only `allowed_tables` are
queryable. `denylist` is the zero-authoring default (everything except
`blocked_tables`). `all` means everything except the package's own tables.
Switching a company to `allowlist` is one row:

```php
$api->handle('config.save', ['company_id' => 7, 'settings' => ['schema_policy' => [
    'mode' => 'allowlist',
    'allowed_tables' => 'orders,customers,leads,support_tickets',
]]]);
```

Two floors exist that no setting can remove, on purpose: a credential column
list (`password`, `api_key`, `token`, `cvv`, `private_key`, …) is always blocked,
and the migration tables (`phinxlog`, `migrations`, …) always are.
`masked_columns` stay readable in the SQL but are redacted in the result; left
empty, phone- and e-mail-shaped columns are masked by pattern.

`require_tenant_scope` makes generated SQL carry a tenant filter for tables that
have the tenant column, and rejects raw SQL that does not. `max_rows` is a hard
ceiling — a request for more (`ask()`'s `options.max_rows`, a report limit) is
clamped to it; `default_limit` applies when no limit is asked for.

### `schema` — the learned catalog (`schema_policy_json`)

| Key | Default | Type | env |
|-----|---------|------|-----|
| `schema.auto_describe` | `true` | yes/no | `AI_ASSISTANT_SCHEMA_AUTO_DESCRIBE` |
| `schema.sample_rows` | `25` | int | `AI_ASSISTANT_SCHEMA_SAMPLE_ROWS` |
| `schema.prune_stale` | `false` | yes/no | `AI_ASSISTANT_SCHEMA_PRUNE` |
| `schema.context_max_chars` | `14000` | int | `AI_ASSISTANT_SCHEMA_CONTEXT_CHARS` |
| `schema.context_max_tables` | `60` | int | `AI_ASSISTANT_SCHEMA_CONTEXT_TABLES` |

`auto_describe` fills a blank description with a generated guess so the assistant
is never mute about an unfamiliar column; descriptions *you* wrote are never
overwritten, which is why filling them in matters — see
[04 — Schema & training](04-schema-training.md). `sample_rows` is how many rows a
sync reads per table to *observe* the data: stored date formats (`dd-mm-yyyy`),
encrypted values and a state column's actual values are appended to the
description as facts, even with `auto_describe` off. Curated rows keep their
labels, formats and measure flags on later syncs. `prune_stale=true` deletes
catalog rows for columns that no longer exist after a schema sync. The last two
are the budget of the "schema card" sent to the model when it writes SQL.

### `domain` / `timezone` — how the assistant speaks (`domain_json`)

| Key | Default | Type | env |
|-----|---------|------|-----|
| `domain.currency` | `''` (no symbol) | string | `AI_ASSISTANT_CURRENCY`, `APP_CURRENCY` |
| `domain.currency_position` | `before` | `before` · `after` | `AI_ASSISTANT_CURRENCY_POSITION` |
| `domain.locale` | `en` | string | `AI_ASSISTANT_LOCALE`, `APP_LOCALE` |
| `domain.language` | `''` (follow the question) | string | `AI_ASSISTANT_LANGUAGE` |
| `domain.multilingual` | `true` | yes/no | `AI_ASSISTANT_MULTILINGUAL` |
| `domain.custom_instructions` | `''` | string | `AI_ASSISTANT_CUSTOM_INSTRUCTIONS` |
| `domain.system_prompt` | `''` (built-in persona) | string | `AI_ASSISTANT_SYSTEM_PROMPT` |
| `domain.query_prompt` | `''` (built-in routing) | string | `AI_ASSISTANT_SQL_PROMPT` |
| `timezone` | `UTC` | string | `AI_ASSISTANT_TIMEZONE`, `APP_TIMEZONE` |

`system_prompt` *replaces* the persona. `custom_instructions` is *appended* — put
business rules there ("amounts include VAT", "never quote a discount above 10%",
tone, terminology) so you keep the grounding the package already does.
`query_prompt` replaces only the routing/plan instructions; the dataset
vocabulary and `custom_instructions` still travel with it. `timezone` stamps the
dates the assistant computes and the cron windows of
[08 — Reports & schedules](08-reports-and-schedules.md).

`domain.name` and `domain.assistant_name` are **not** in the catalogue — they
name the deployment, not a preference, and stay in `.env`.

### `features` — capability switchboard (`features_json`)

Default: `data_qa, documents, actions, charts, exports, reports, feedback, audit`
(list type — CSV is fine when saving). Names the package recognises are reported
by `handle('capabilities')` and mirrored in `diagnostics()['features']`. Dropping
a name makes that capability unadvertised; the deeper gate is your permission
resolver, so treat this as the product-level switch and permissions as the
per-user one. `knowledge`, `schedules` and `outreach` are recognised too.

### `semantic` / `metrics` (`semantic_json`)

| Key | Default | Type | env |
|-----|---------|------|-----|
| `semantic.enabled` | `true` | yes/no | `AI_ASSISTANT_SEMANTIC_ENABLED` |
| `semantic.auto_derive` | `true` | yes/no | `AI_ASSISTANT_SEMANTIC_AUTO` |
| `semantic.packs` | `[]` | JSON | `AI_ASSISTANT_SEMANTIC_PACKS` |
| `semantic.pack_file` | `''` | path — config file only | `AI_ASSISTANT_SEMANTIC_PACK` |
| `semantic.max_datasets` | `80` | int | `AI_ASSISTANT_SEMANTIC_MAX_DATASETS` |
| `semantic.max_dimensions` | `14` | int | `AI_ASSISTANT_SEMANTIC_MAX_DIMENSIONS` |
| `semantic.allow_raw_sql` | `false` | yes/no | `AI_ASSISTANT_ALLOW_RAW_SQL` |
| `semantic.raw_sql_roles` | `admin,owner` | list | `AI_ASSISTANT_RAW_SQL_ROLES` |
| `semantic.vocab_chars` | `5000` | int | `AI_ASSISTANT_VOCAB_CHARS` |
| `metrics.auto` | `true` | yes/no | `AI_ASSISTANT_METRICS_AUTO` |
| `metrics.auto_max_tables` | `40` | int | `AI_ASSISTANT_METRICS_MAX_TABLES` |
| `metrics.custom` | `[]` | JSON | `AI_ASSISTANT_METRICS` |

With no pack authored, datasets are derived from the schema catalog within the
`max_datasets` / `max_dimensions` caps — which is what makes the package usable in
an app nobody wrote a pack for. A pack (its shape is in
`src/Support/DomainPack.php`) turns guesses into definitions: "revenue" becomes
`sum(orders.total)` once, in the database, instead of in every prompt.
`semantic.packs` accepts the same JSON inline; `pack_file` is a file path only
(a database value cannot be a path the package is allowed to trust blindly) and
stays in the config file. `allow_raw_sql` is the escape hatch for questions a
pack cannot express, limited to `raw_sql_roles`. See
[06 — Data, charts & visualisation](06-data-and-charts.md).

`metrics.auto` counts rows per queryable table, applying the tenant column and an
`is_active` column when they exist. `metrics.custom` replaces or extends that with
a list of `['key'=>…, 'label'=>…, 'table'=>…, 'agg'=>'count|sum|avg|min|max',
'column'=>…, 'where'=>…, 'tenant'=>true]`.

### `reference` — lookup values it may quote (`reference_json`)

| Key | Default | Type | env |
|-----|---------|------|-----|
| `reference.enabled` | `true` | yes/no | `AI_ASSISTANT_REFERENCE_ENABLED` |
| `reference.tables` | `[]` (auto-discover) | list | `AI_ASSISTANT_REFERENCE_TABLES` |
| `reference.auto_suffixes` | 20 suffixes (`_types`, `_categories`, `_statuses`, …) | list | `AI_ASSISTANT_REFERENCE_SUFFIXES` |
| `reference.name_columns` | `name,title,label` | list | `AI_ASSISTANT_REFERENCE_NAME_COLUMNS` |
| `reference.price_columns` | `default_amount,price,amount,cost,rate,unit_price,selling_price` | list | `AI_ASSISTANT_REFERENCE_PRICE_COLUMNS` |
| `reference.max_values` | `50` | int | `AI_ASSISTANT_REFERENCE_MAX_VALUES` |
| `reference.max_tables` | `60` | int | — (config file / database) |

This is how the assistant knows that your `service_types` table means "Standard
visit 1500, Deep clean 3200" without you writing it. Explicit `tables` win over
discovery.

### `knowledge` — retrieval (`knowledge_json`)

| Key | Default | Type | env |
|-----|---------|------|-----|
| `knowledge.retrieval` | `hybrid` | `hybrid` · `keyword` · `vector` | `AI_ASSISTANT_RETRIEVAL` |
| `knowledge.top_k` | `5` | int | `AI_ASSISTANT_KNOWLEDGE_TOP_K` |
| `knowledge.min_similarity` | `0.2` | float | `AI_ASSISTANT_MIN_SIMILARITY` |
| `knowledge.vector_weight` | `0.6` | float | — (database / config file) |
| `knowledge.fallback_latest` | `false` | yes/no | `AI_ASSISTANT_KNOWLEDGE_FALLBACK_LATEST` |
| `knowledge.self_training` | `true` | yes/no | `AI_ASSISTANT_SELF_TRAINING` |
| `knowledge.scan_limit` | `500` | int | `AI_ASSISTANT_KNOWLEDGE_SCAN_LIMIT` |
| `knowledge.dedupe_scan` | `2000` | int | `AI_ASSISTANT_KNOWLEDGE_DEDUPE_SCAN` |

`vector_weight` is the share of a hybrid score that comes from embedding
similarity; the rest is keyword overlap. `fallback_latest` appends the newest
knowledge when nothing matches — leave it off, because unrelated knowledge in a
prompt produces confidently wrong answers. `self_training` is also the switch the
legacy host service checks before learning from an answered exchange. See
[05 — Knowledge & documents](05-knowledge-and-documents.md).

### `charts` — visualisation shape hints (`charts_json`)

Every chart setting is storable in the database. All 28:

| Key | Default | Type |
|-----|---------|------|
| `charts.enabled_types` | `[]` (all 14) | list |
| `charts.max_rows` | `300` | int |
| `charts.max_series` | `8` | int |
| `charts.bar_max_categories` | `30` | int |
| `charts.pie_max_categories` | `12` | int |
| `charts.table_above_rows` | `8` | int |
| `charts.table_above_columns` | `6` | int |
| `charts.table_page_size` | `25` | int |
| `charts.kpi_max_items` | `6` | int |
| `charts.max_depth` | `12` | int |
| `charts.compact_axis` | `true` | yes/no |
| `charts.bar_max_width` | `48` | int |
| `charts.kpi_font_size` | `42` | int |
| `charts.scatter_symbol_size` | `12` | int |
| `charts.donut_inner_radius` | `45%` | string |
| `charts.heatmap_labels` | `true` | yes/no |
| `charts.time_column_names` | `[]` (built-in list) | list |
| `charts.month_tokens` | `[]` (built-in list) | list |
| `charts.id_columns` | `[]` (built-in list) | list |
| `charts.funnel_stage_terms` | `[]` (built-in list) | list |
| `charts.latitude_columns` | `latitude,lat,lat_deg,gps_latitude,geo_lat` | list |
| `charts.longitude_columns` | `longitude,lng,lon,long,geo_lng,gps_longitude` | list |
| `charts.default_agg` | `sum` | `sum` · `avg` · `min` · `max` · `count` |
| `charts.default_unit` | `''` | string |
| `charts.currency_by_default` | `false` | yes/no |
| `charts.multi_axis_label` | `values` | string |
| `charts.sort_categories` | `value_desc` | `value_desc` · `value_asc` · `label_asc` |
| `charts.delta_column` | `''` | string |

Types are `bar, column, line, area, pie, donut, stacked_bar, funnel, scatter,
heatmap, kpi, table, pivot, map` — `map` has no geo source, so it renders as a
table. Listing `enabled_types` *restricts* the palette; leave it empty to offer
all. The `*_columns` / `*_terms` / `*_tokens` lists are vocabularies for
recognising a time axis, an identifier, a funnel stage — extend them with your
app's own words rather than editing `vendor/`. Everything here is a shape hint for
the spec the package builds; your front-end decides how to draw it. See
[06 — Data, charts & visualisation](06-data-and-charts.md).

### `limits` / `rate_limit` (`limits_json`)

| Key | Default | Type | env |
|-----|---------|------|-----|
| `limits.max_rows` | `200` | int | `AI_ASSISTANT_MAX_ROWS` |
| `limits.max_upload_mb` | `25` | int | `AI_ASSISTANT_MAX_UPLOAD_MB` |
| `limits.max_pages` | `300` | int | `AI_ASSISTANT_MAX_PAGES` |
| `limits.retention_days` | `365` | int | `AI_ASSISTANT_RETENTION_DAYS` |
| `limits.session_turns` | `10` | int | `AI_ASSISTANT_SESSION_TURNS` (legacy `AI_WHATSAPP_SESSION_TURNS`) |
| `limits.session_ttl_minutes` | `60` | int | `AI_ASSISTANT_SESSION_TTL` |
| `limits.memory_turns` | `10` | int | `AI_ASSISTANT_MEMORY_TURNS` |
| `limits.store_rows` | `50` | int | `AI_ASSISTANT_STORE_ROWS` |
| `limits.summarize_after` | `24` | int | `AI_ASSISTANT_SUMMARIZE_AFTER` |
| `limits.audit_export_rows` | `10000` | int | `AI_ASSISTANT_AUDIT_EXPORT_ROWS` |
| `rate_limit.per_hour` | `60` | int | `AI_ASSISTANT_RATE_LIMIT_PER_HOUR` (legacy `AI_RATE_LIMIT_PER_HOUR`) |
| `rate_limit.per_day` | `0` (unlimited) | int | `AI_ASSISTANT_RATE_LIMIT_PER_DAY` |

`memory_turns` is how much recent conversation is replayed into a prompt;
`store_rows` is how many answer rows a re-opened thread re-renders — `0` stores
none, which keeps decrypted table values out of `{prefix}_messages`;
`summarize_after` is the point where older turns are summarised away (`0` = never);
`retention_days` is the number `AuditService::retention()` proposes for old audit
rows; `session_*` belong to the legacy `{prefix}_sessions` store. `rate_limit.*` are
the defaults `handle('limit.check')` uses when a request does not pass `max`.

Quota windows are finer than these two keys — `handle('limit.check', ['dimension'=>…,
'key'=>…, 'window'=>…])` resolves `rate_limit.{dimension}_per_minute|_per_hour|_per_day|_per_month`
first and falls back to `rate_limit.per_minute` / `per_hour` / `per_day`. See
[11 — Governance](11-governance.md).

### `channels` (`channels_json`)

| Key | Default | Type | env |
|-----|---------|------|-----|
| `channels.web.enabled` | `true` | yes/no | `AI_ASSISTANT_WEB_ENABLED` |
| `channels.whatsapp.enabled` | `true` | yes/no | `AI_ASSISTANT_WHATSAPP_ENABLED` |
| `channels.whatsapp.table_prefix` | `whatsapp` | string | `WHATSAPP_TABLE_PREFIX`, `AI_ASSISTANT_WHATSAPP_TABLE_PREFIX` |
| `channels.whatsapp.auto_reply` | `true` | yes/no | `AI_ASSISTANT_AUTO_REPLY` (legacy `AI_WHATSAPP_AUTO_REPLY`) |
| `channels.whatsapp.fallback_template_id` | `0` | int | `AI_ASSISTANT_FALLBACK_TEMPLATE` (legacy `AI_WHATSAPP_FALLBACK_TEMPLATE`) |
| `channels.whatsapp.booking_hook` | `true` | yes/no | `AI_ASSISTANT_BOOKING_HOOK` (legacy `AI_WHATSAPP_BOOKING_HOOK`) |
| `channels.whatsapp.session_window_hours` | `24` | int | `AI_ASSISTANT_SESSION_WINDOW_HOURS` |
| `channels.whatsapp.max_message_chars` | `4096` | int | `AI_ASSISTANT_WHATSAPP_MAX_CHARS` |
| `channels.whatsapp.max_rows_in_message` | `15` | int | `AI_ASSISTANT_WHATSAPP_MAX_ROWS` |
| `channels.whatsapp.show_sources` | `true` | yes/no | `AI_ASSISTANT_WHATSAPP_SOURCES` |
| `channels.email.enabled` / `channels.slack.enabled` | `false` | yes/no | — (config file only) |

`channels.whatsapp.table_prefix` names *your* WhatsApp package's tables
(`{prefix}_messages`, …). It is the only channel setting that points at tables the
assistant did not create. `max_message_chars` is WhatsApp's own limit — longer
answers are split at paragraph breaks. See
[10 — Channels & WhatsApp](10-channels-and-whatsapp.md).

### `exports` (`exports_json`)

| Key | Default | Type | env |
|-----|---------|------|-----|
| `exports.formats` | `csv,json` | list | `AI_ASSISTANT_EXPORT_FORMATS` |
| `exports.async_above_rows` | `5000` | int | `AI_ASSISTANT_EXPORT_ASYNC_ROWS` |
| `exports.directory` | `''` → `<system temp>/ai-assistant-exports` | string | `AI_ASSISTANT_EXPORT_DIR` |
| `exports.file_name_prefix` | `ai-export` | string | `AI_ASSISTANT_EXPORT_PREFIX` |
| `exports.download_ttl_minutes` | `1440` | int | `AI_ASSISTANT_EXPORT_TTL` |
| `exports.require_confirmation` | `true` | yes/no | `AI_ASSISTANT_EXPORT_CONFIRM` |
| `exports.approval_roles` | `admin,owner` | list | `AI_ASSISTANT_EXPORT_APPROVERS` |
| `exports.max_rows` | `100000` | int | `AI_ASSISTANT_EXPORT_MAX_ROWS` |
| `exports.csv_delimiter` | `,` | string | `AI_ASSISTANT_EXPORT_CSV_DELIMITER` |
| `exports.csv_newline` | `CRLF` | `CRLF` · `LF` · `CR` | `AI_ASSISTANT_EXPORT_CSV_NEWLINE` |
| `exports.csv_bom` | `true` | yes/no | `AI_ASSISTANT_EXPORT_CSV_BOM` |
| `exports.csv_quote_all` | `false` | yes/no | `AI_ASSISTANT_EXPORT_CSV_QUOTE_ALL` |

csv, tsv and json are written by the package with no dependencies. `xlsx`, `pdf`,
`docx` become offerable only when a host exporter class is registered in your
config file (`exports.host_exporters`, see below) — your app already owns
PhpSpreadsheet or html2pdf; the package will not add a second one.
`exports.directory` must be somewhere your download route can read; the default
temp path is fine for a single host but is not durable across restarts. See
[07 — Exports](07-exports.md).

### `documents` (`documents_json`)

| Key | Default | Type | env |
|-----|---------|------|-----|
| `documents.allowed_mime` | text, markdown, csv, pdf, docx, xlsx, png, jpeg | list | `AI_ASSISTANT_DOCUMENT_MIME` |
| `documents.chunk_tokens` | `800` | int | `AI_ASSISTANT_CHUNK_TOKENS` |
| `documents.chunk_overlap` | `100` | int | — (database / config file) |
| `documents.max_chunks` | `400` | int | `AI_ASSISTANT_MAX_CHUNKS` |
| `documents.mirror_to_knowledge` | `true` | yes/no | `AI_ASSISTANT_DOC_MIRROR_KNOWLEDGE` |
| `documents.mirror_chars` | `1500` | int | `AI_ASSISTANT_DOC_MIRROR_CHARS` |
| `documents.scan_limit` | `800` | int | `AI_ASSISTANT_DOCUMENT_SCAN_LIMIT` |
| `documents.pdf_binary` | `''` (find `pdftotext` on PATH) | string | `AI_ASSISTANT_PDF_BINARY` |
| `documents.ocr_binary` | `''` (OCR disabled) | string | `AI_ASSISTANT_OCR_BINARY` |
| `documents.ocr_args` | `''` | string | `AI_ASSISTANT_OCR_ARGS` |
| `documents.ocr_stdout` | `false` | yes/no | `AI_ASSISTANT_OCR_STDOUT` |

PDF text works out of the box when poppler's `pdftotext` is on `PATH` (or
`smalot/pdfparser` is installed). Image OCR stays off until `ocr_binary` names a
tesseract — the package will not guess a binary and shell out to it. See
[05 — Knowledge & documents](05-knowledge-and-documents.md).

### `audit` / `actions` / `outbox` / `reports` / `schedules` (`operations_json`)

| Key | Default | Type | env |
|-----|---------|------|-----|
| `audit.enabled` | `true` | yes/no | `AI_ASSISTANT_AUDIT_ENABLED` |
| `audit.store` | `table` | `table` · `sink` · `both` | `AI_ASSISTANT_AUDIT_STORE` |
| `audit.record_sql` | `true` | yes/no | `AI_ASSISTANT_AUDIT_SQL` |
| `actions.confirmation_ttl_minutes` | `30` | int | `AI_ASSISTANT_ACTION_TOKEN_TTL` |
| `outbox.batch` | `50` | int | `AI_ASSISTANT_OUTBOX_BATCH` |
| `outbox.max_attempts` | `5` | int | `AI_ASSISTANT_OUTBOX_ATTEMPTS` |
| `outbox.backoff_seconds` | `60` | int | `AI_ASSISTANT_OUTBOX_BACKOFF` |
| `reports.allowed_datasets` | `[]` (any known dataset) | list | `AI_ASSISTANT_REPORT_DATASETS` |
| `schedules.tick_limit` | `200` | int | `AI_ASSISTANT_SCHEDULE_TICK_LIMIT` |
| `schedules.max_consecutive_failures` | `5` | int | `AI_ASSISTANT_SCHEDULE_MAX_FAILURES` |

`audit.store=sink` hands every event to your `Contracts\AuditSinkInterface` and
writes nothing to `{prefix}_audit`; `both` does each. The database setting is
limited to those three words — naming a *class* is a config-file value (see
below). `record_sql=false` keeps the generated SQL out of the trail. Outbox
retries wait `backoff_seconds × 2^(attempts-1)` and stop at two hours per wait,
then at `max_attempts`. A schedule disables itself after
`max_consecutive_failures` failures (`0` = never); `tick_limit` caps how many due
jobs one `tick()` hands back. Note that **the package does not deliver scheduled
work** — your cron calls the tick and your notifier sends what comes back. See
[09 — Actions & outbox](09-actions-and-outbox.md),
[08 — Reports & schedules](08-reports-and-schedules.md) and
[11 — Governance](11-governance.md).

### Legacy blocks

`auto_reply.*` and `session.*` are kept so an existing host that reads
`config['auto_reply']['enabled']` keeps working; the same env keys fill them. Do
not use them in a new install — the canonical keys are
`channels.whatsapp.auto_reply` and `limits.session_*`.

---

## Config that cannot come from the database

Four values name *code* or a *path*, not data. They stay in your config file or
at boot, never in `{prefix}_config` (the table is plaintext JSON that your
application and anyone with SQL access can read):

```php
'exports' => [
    // format => class name or callable implementing ExporterInterface
    'host_exporters' => ['xlsx' => \App\Ai\ExcelExporter::class],
],
'documents' => [
    // TextExtractorInterface class names or callables, tried before the built-ins
    'extractors' => [\App\Ai\ZippedDocxExtractor::class],
],
'audit' => [
    // a class name here is treated as store = 'sink'
    'store' => \App\Ai\LogAuditSink::class,
],
'semantic' => [
    // a path the package is told to trust
    'pack_file' => __DIR__ . '/../ai/pack.json',
],
```

Everything else the package reads is either in the catalogue (storable in the
database) or in the `.env`-only block above (needed before the connection exists).
`diagnostics()['settings']['editable']` reports how many paths the installed
build accepts.

## Changing config at runtime

Three distinct mechanisms, do not confuse them:

**1. Durable per-company settings — `{prefix}_config`.** The normal one. One row
per company, validated against `ConfigCatalog`, read through `Config` like any
other value. Covered above: `config.save` / `config.forget` / `config.describe`.

**2. Per-request overlay — `{prefix}_settings` (the key/value store).** Layers on
top of the resolved config for one company / role / project / user at a time:

```php
$api->handle('settings.put', ['key' => 'exports.max_rows', 'value' => 5000,
                              'scope' => ['company_id' => 7, 'scope' => 'company'],
                              'updated_by' => 'admin@acme']);
$api->handle('settings.get', ['key' => 'exports.max_rows',
                              'scope' => ['company_id' => 7]]);
```

Ranks are global 0 < company/role/project/tenant 1 < user 2, and values are stored
wrapped as `{"v": … }`. `SettingsService::mergeConfig($tenantId, $userId)` —
exposed as the public method `$api->effectiveConfig($tenantId, $userId)` — resolves
the chain. It is not a `handle()` action; neither are `settingsList()` and
`forgetSetting()`. `mergeConfig` also runs as the `system_context` hook, so
grounded answers see tenant overrides without you wiring anything. This store is
free-form (no catalogue), so it is the place for one-off per-user tweaks, not for
values a settings screen must validate.

**3. Process-wide — `Config::set('limits.max_rows', 50)`.** Changes it for
everything after that point in this process, and beats every stored layer.
`Config::reset()` (mostly in tests) discards everything resolved so far,
including the settings loader, and re-discovers.

## Verifying what the package actually sees

```php
$d = $api->handle('diagnostics');
```

| Key | Tells you |
|-----|-----------|
| `package` | name, resolved `table_prefix` |
| `database` | connected, which of the 22 tables exist, legacy tables, `missing` |
| `settings` | the `{prefix}_config` table, whether it exists, whether the database layer is attached, how many settings are editable, and which paths are overridden |
| `llm` | provider, model, `api_key` reported as `set`/`unset` (never the value), `ready`, `error` |
| `features` | the list from `features` |
| `policy` | mode, allowed/blocked tables, max rows, tenant column, whether own tables are included |
| `exports` | offerable formats, directory, and its `error` if the directory is not writable |
| `limits` | the resolved `limits` + `rate_limit` block |
| `wiring` | which host interfaces you actually registered |

`Config::sourceFile()` names the file that was discovered,
`Config::envIsSet('schema_policy.max_rows')` answers "is an environment variable
winning here?", and `$api->handle('config.get', ['company_id' => 7, 'path' => …])`
shows the stored override together with its `source`. Those are the three
quickest checks when a value is not what you set.

**Next:** [04 — Schema & training](04-schema-training.md)
