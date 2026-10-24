# 01 — Installation

## Requirements

* PHP >= 8.1 with `pdo` and `json`; `ext-pdo_mysql` for a MySQL host, `ext-pdo_sqlite` for SQLite.
* A database the application already owns — the package adds its own tables to it.
* `robmorgan/phinx`, already declared in this package's `require-dev`. You need it
  only to create tables, never at runtime.
* Any OpenAI-compatible chat endpoint. Nothing else: no queue service, no vector
  database, no framework, no Redis.

## Install

```bash
composer require excelle-insights/ai-assistant
php vendor/excelle-insights/ai-assistant/migrate.php
```

(Not on Packagist in your registry yet? Add the VCS repository to your
`composer.json` and `composer update excelle-insights/ai-assistant`.)

The wrapper reads your existing `.env` — `DB_HOST`, `DB_NAME`, `DB_USER`,
`DB_PASSWORD`, and optionally `DB_PORT`, `DB_ADAPTER`, `AI_ASSISTANT_DB_ENV` —
builds a throwaway Phinx config, runs the migrations against **your** database
and prints:

```
AI Assistant migrations ran (prefix: ai_assistant, adapter: mysql).
```

Phinx flags pass straight through, so nothing about your upgrade cycle changes:

```bash
php vendor/excelle-insights/ai-assistant/migrate.php --dry-run   # print the SQL, change nothing
php vendor/excelle-insights/ai-assistant/migrate.php --change 1  # roll one migration back
```

Prefer your own Phinx? The package ships a config that reads the same keys
(including `DB_ADAPTER=sqlite`, where `DB_NAME` is a file path):

```bash
vendor/bin/phinx migrate -c vendor/excelle-insights/ai-assistant/phinx.php
vendor/bin/phinx status  -c vendor/excelle-insights/ai-assistant/phinx.php
```

Confirm the classes load before going further:

```bash
php -r "require 'vendor/autoload.php'; var_dump(class_exists('ExcelleInsights\AiAssistant\Http\AssistantApi'));"
# bool(true)
```

## Running migrations on every `composer update`

Composer only executes scripts declared by the **root** package, so a library
cannot install this hook for itself. Add one entry to your own `composer.json`
(keep the entries you already have):

```json
{
  "scripts": {
    "post-autoload-dump": [
      "@php artisan package:discover --ansi",
      "php vendor/excelle-insights/ai-assistant/migrate.php"
    ]
  }
}
```

Every migration checks whether its table or column already exists, so
re-running costs nothing and a deploy that runs twice is harmless.

## Tables the package creates

All of them carry the prefix from `AI_ASSISTANT_TABLE_PREFIX` (default
`ai_assistant`), so on a shared database they stay recognisable — and yours to
inspect, back up or drop. `company_id` in them is **a scoping key, never a
foreign key**: no constraint is added to any of your tables, and an app with no
company model just uses `0`.

| Table | Holds |
|---|---|
| `{prefix}_conversations`, `{prefix}_messages` | chat threads and turns: title, channel, pin, share token, summary, sequence |
| `{prefix}_knowledge` | trained knowledge: category, title, content, embedding, source, active flag, dedupe hash, scope |
| `{prefix}_training` | question/answer pairs learned from answered conversations |
| `{prefix}_schema` | the learned catalog: table, column, type, sensitivity, and the descriptions you write |
| `{prefix}_reference` | lookup values the assistant may quote (statuses, types, units, prices, counts) |
| `{prefix}_documents`, `{prefix}_document_chunks` | uploaded files and their chunks with embeddings |
| `{prefix}_exports` | export jobs, approval state, single-use download tokens |
| `{prefix}_actions`, `{prefix}_action_runs` | proposed data changes, confirm tokens, execution log |
| `{prefix}_notification_outbox` | queued outbound messages with attempts and backoff |
| `{prefix}_audit` | who asked what, which SQL ran, which file was exported |
| `{prefix}_usage_daily` | per-day counters per tenant and user |
| `{prefix}_settings` | per-tenant / per-user runtime overrides |
| `{prefix}_config` | durable behavioural settings per company (table policy, features, charts, limits, exports…), one JSON column per section |
| `{prefix}_report_definitions`, `{prefix}_schedules` | saved reports and their cron schedules |
| `{prefix}_feedback` | ratings and corrections with a review queue |
| `{prefix}_rate_limits` | durable counters behind `limit.check` |
| `{prefix}_sessions`, `{prefix}_queue` | **legacy** — only an existing WhatsApp host uses them; a new install ignores them |

Check from inside your app whenever you like:

```php
$diag = (new \ExcelleInsights\AiAssistant\Http\AssistantApi($pdo))->diagnostics();
$diag['database']['connected'];   // true
$diag['database']['missing'];     // [] when everything is installed
$diag['database']['tables'];      // true/false per table
```

`diagnostics()` also reports the LLM as `set`/`unset` — never the key itself.

## `.env` is small on purpose

The package keeps only what it needs *before* a database connection exists in
`.env` (or your config file): the DB connection, the LLM endpoint and key, the
table prefix, the tenant identity and the assistant's name. Everything
behavioural — which tables may be read, enabled features, currency, chart
limits, export rules, WhatsApp behaviour, audit — is seeded into
`{prefix}_config` through `config.save` or a settings screen, so it can change
without a deploy and differ per company.

```php
$api = new \ExcelleInsights\AiAssistant\Http\AssistantApi($pdo);
$api->handle('config.describe', ['company_id' => 0]);   // what can be set
$api->handle('config.save', ['company_id' => 0, 'settings' => [
    'schema_policy' => ['mode' => 'allowlist', 'allowed_tables' => 'orders,customers'],
]]);
```

The table starts empty: no row means "use the defaults", so this step is
optional. Full reference: [03 — Configuration](03-configuration.md#settings-in-the-database).

## Upgrading

```bash
composer update excelle-insights/ai-assistant
php vendor/excelle-insights/ai-assistant/migrate.php
```

New releases add migrations; existing ones are never rewritten, so an upgrade
only adds tables or columns and touches no rows.

An install that predates the `ai_assistant` naming may still hold
`ai_whatsapp_*` tables. The one-time rename migration was retired once those
installs were renamed. If yours still has them, keep
`AI_WHATSAPP_TABLE_PREFIX=ai_whatsapp` in `.env` and the legacy alias keeps
resolving; nothing else in the package depends on the old name.

## Trying it without a MySQL server

```bash
DB_ADAPTER=sqlite DB_NAME=/tmp/ai_mig.sqlite php vendor/excelle-insights/ai-assistant/migrate.php
```

## Uninstall

Drop the tables in the table above. The package wrote to nothing outside its
prefix and added no column to your tables, so your schema is left exactly as it
was.

Next: [connecting your application](02-connect-application.md).
