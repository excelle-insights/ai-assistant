# AI Assistant — Documentation

One AI core for every channel and every job: WhatsApp auto-reply, in-app user
guide, vehicle diagnosis and business analysis. The host application decides
what the assistant is for. The package never knows your tables and never
depends on a specific messaging vendor.

> Rename done (2026-09-21): folder/repo are `ai-assistant`, namespace
> `ExcelleInsights\AiAssistant\…`, composer `excelle-insights/ai-assistant`,
> tables `ai_assistant_*`. `AI_WHATSAPP_*` / `ai_whatsapp_*` survive only as
> documented legacy fallbacks.

## Reading order

| # | File | What it covers |
|---|------|----------------|
| 01 | [Installation](01-installation.md) | Requirements, Composer install, migrations, the tables created, upgrading, uninstall |
| 02 | [Connecting your application](02-connect-application.md) | Constructing `AssistantApi`, user context, every host interface, hooks, diagnostics |
| 03 | [Configuration](03-configuration.md) | Precedence, `{prefix}_config` (database settings), the catalogue, every block, env break-glass |
| 04 | [Schema training](04-schema-training.md) | Teaching the assistant your tables and columns; descriptions, aliases, sensitivity, dumps |
| 05 | [Knowledge & documents](05-knowledge-and-documents.md) | FAQ entries, CSV import, uploads, chunking, retrieval, citations |
| 06 | [Data, analysis & charts](06-data-and-charts.md) | Semantic layer, query plans, SQL guardrails, chart specs |
| 07 | [Exports](07-exports.md) | CSV/TSV/JSON, approval gates, download tokens, host exporters |
| 08 | [Reports & schedules](08-reports-and-schedules.md) | Saved reports, cron delivery, feedback loop |
| 09 | [Actions & outbox](09-actions-and-outbox.md) | Data-changing actions with confirm tokens, queued notifications |
| 10 | [Channels & WhatsApp](10-channels-and-whatsapp.md) | Channel adapter, webhook handling, chunking, the legacy pipeline |
| 11 | [Governance](11-governance.md) | Audit, feedback, usage counters, rate limits, per-user settings, retention |
| 12 | [API reference](12-api-reference.md) | Every `handle()` action, public method, payload and error code |
| 13 | [LLM providers](13-llm-providers.md) | OpenAI-compatible endpoints, local models, failure behaviour |
| 14 | [Troubleshooting](14-troubleshooting.md) | Failures you will actually see, and what they mean |
| 15 | [Bookings](15-bookings.md) | Package-owned bookings, booking numbers, per-contact recall, host actions |
| 16 | [CRM intelligence roadmap](16-crm-intelligence-roadmap.md) | What the package already knows about a CRM, what is missing, and the phase plan for learning a CRM by itself |
| 17 | [Benchmark](17-benchmark.md) | The per-CRM quality bar: canonical questions, the six metrics, how a run is scored |

These guides are the current contract. `BLACKBOX_DECOUPLING_PROPOSAL.md` in the
repository root is a historical design note; where the two disagree, `docs/`
wins.

## 30-second mental model

```
WhatsApp ──┐
In-app chat ─┼──▶ Host adapter (YOUR code)  ──▶  AI core (THIS package)
Diagnosis ──┤      routes intents, owns auth         schema + knowledge
Analysis ───┘      and UI                            LLM + memory + tools
```

- The AI core only reads its own `{prefix}_*` tables (default
  `ai_assistant_*`). It adds no column to yours and needs no foreign key.
- Everything about your app — table names, WhatsApp sender, business rules —
  lives in your adapter/controllers, never in the package.
- Train the schema and the knowledge base once; every channel reuses them.

## Configuration is mostly data

`.env` holds only what the package needs **before** it can reach the database:
the DB connection, the LLM endpoint and key, the table prefix, the tenant
identity and the assistant's name. Everything behavioural — the table policy,
enabled features, semantic metrics, chart limits, export rules, audit and
WhatsApp behaviour — is stored per company in `{prefix}_config` and edited
through the API:

```php
$api->handle('config.describe', ['company_id' => 0]); // every settable path
$api->handle('config.save', [
    'company_id' => 0,
    'settings'   => [
        'schema_policy' => ['mode' => 'allowlist', 'allowed_tables' => 'orders,customers'],
        'features'      => ['exports' => 'yes'],
    ],
]);
```

Values accept `yes`/`no`/`on`/`off`/`1`/`0` and comma-separated or array lists.
A later `.env` value still wins over the database row — that is the break-glass
path, not the everyday one. Full reference: [03 — Configuration](03-configuration.md#settings-in-the-database).

## Development

```bash
cd /path/to/ai-assistant
composer install            # dev only: phinx
php tests/run.php           # every suite, no DB server or network needed
```

The suites are plain PHP scripts with their own assertions (no PHPUnit):
`assistant`, `api`, `schema`, `knowledge`, `semantic`, `exports`, `actions`,
`channels`, `governance`, `conversation`, `config`. They run against SQLite in
a temp file, so `composer install && php tests/run.php` is green on a clean
machine.

To try migrations locally:

```bash
DB_ADAPTER=sqlite DB_NAME=/tmp/ai_mig.sqlite \
  php vendor/excelle-insights/ai-assistant/migrate.php
```

## Where to look first

| You want to… | Start at |
|---|---|
| Install and see the tables | [01 — Installation](01-installation.md) |
| Answer a question from your own data | [02](02-connect-application.md) → [04](04-schema-training.md) → [06](06-data-and-charts.md) |
| Train FAQ/policies | [05 — Knowledge & documents](05-knowledge-and-documents.md) |
| Connect WhatsApp | [10 — Channels & WhatsApp](10-channels-and-whatsapp.md) |
| Control what it may read | [03 — Configuration](03-configuration.md#schema_policy--what-it-may-read-schema_policy_json) |
| Understand an error | [14 — Troubleshooting](14-troubleshooting.md) |
