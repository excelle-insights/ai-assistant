# AI Assistant — embeddable trainable assistant (`excelle-insights/ai-assistant`)

One PHP package that gives **any** application a trainable AI layer: chat that
answers from your own data, your documents and your knowledge base — on the web,
in WhatsApp, or in whatever channel you already run. Plain PHP + PDO, no
framework, no required service except an OpenAI-compatible LLM endpoint.

The package never knows what your app does. It learns your tables, your wording
and your permissions from your configuration and from the interfaces you
implement, and it writes only to its own `ai_assistant_*` tables in **your**
database. Nothing in `vendor/` is ever edited, and nothing about your domain is
hardcoded here.

```bash
composer require excelle-insights/ai-assistant
php vendor/excelle-insights/ai-assistant/migrate.php   # creates its tables in your DB
```

```php
use ExcelleInsights\AiAssistant\Http\AssistantApi;

$api = new AssistantApi($pdo);                    // your existing PDO connection
$api->handle('schema.train', ['options' => []]);  // learn your tables + columns
$answer = $api->handle('chat', [
    'question' => 'How many orders did we ship last month?',
    'user'     => ['user_id' => '7', 'tenant_id' => 3, 'roles' => ['manager']],
]);
// $answer['answer']['text'], ['sql'], ['rows'], ['chart'], ['citations'], ['meta']['intent']
```

## Configuration is mostly data

`.env` holds only what is needed before a database connection exists — the DB
credentials, the LLM endpoint/key, the table prefix and the assistant's name.
Behaviour (which tables may be read, features, limits, charts, exports, WhatsApp,
audit) lives in the package's `{prefix}_config` table and is edited from your own
settings screen or SQL:

```php
$api->handle('config.describe', ['company_id' => 0]);   // every settable path + type
$api->handle('config.save', ['company_id' => 0, 'settings' => [
    'schema_policy.mode'           => 'allowlist',
    'schema_policy.allowed_tables' => 'orders,customers',
    'features'                     => 'data_qa,documents,charts,exports',
]]);
```

A NULL value clears an override; booleans accept `yes`/`no`/`on`/`off`/`1`/`0`
and lists accept CSV or arrays. Env keys still exist as a break-glass override
and deliberately win over the table. Full reference:
[docs/03-configuration.md](docs/03-configuration.md#settings-in-the-database).

## What it does

| Capability | Entry point | Needs from you |
|---|---|---|
| Answer a question from your database | `chat`, `query.run` | PDO, LLM key |
| Learn your schema, keep your own column meanings | `schema.*` | nothing (optional: descriptions) |
| Train from an uploaded SQL dump or a second DB | `schema.import-dump`, `trainFromConnection` | the dump / connection |
| Knowledge base (FAQ, policies, prices) + CSV import | `knowledge.*` | nothing |
| Document upload → extract → chunk → cite (PDF, DOCX, XLSX, images, audio) | `document.*` | extractors for binary formats |
| Charts from any result set (14 types, ECharts-ready specs) | `chart.suggest`, `chart.build` | nothing |
| Exports (CSV/TSV/JSON built in; XLSX/PDF via your exporter) with approval gates | `export.*` | a writable directory |
| Saved reports, cron schedules, shared links | `report.*`, `schedule.*` | a runner + delivery |
| Data-changing actions with confirm tokens + idempotency | `action.*` | `ActionHandlerInterface` |
| Outbox for queued notifications with retries | `outbox.*` | `NotificationInterface` |
| WhatsApp / any channel auto-reply | `whatsapp.*`, `ChannelInterface` | a sender |
| Audit trail, usage counters, rate limits, per-tenant settings, feedback queue | `audit.*`, `usage.*`, `limit.*`, `settings.*`, `feedback.*` | nothing |
| Conversation history, transcripts, share links, summaries | `conversation.*` | its own tables |

Each row is documented in its own guide — see [docs/](docs/README.md).

## What it does not do (yet)

Honesty over marketing: these are **not implemented** in this package.

* Lead scoring, forecasting, churn detection — no such service exists here.
* No built-in XLSX / PDF / DOCX writer, no built-in OCR or audio transcription.
  The package ships interfaces (`ExporterInterface`, `TextExtractorInterface`)
  and your app — which already owns PhpSpreadsheet, html2pdf, Tesseract — plugs
  them in. `csv`, `tsv`, `json` are written by the package itself.
* Runtime SQL is written for **MySQL and SQLite**. Migrations use the
  adapter-agnostic Phinx API, so they will build tables for Postgres or SQL
  Server, but the package's own queries assume MySQL/SQLite quoting.
* No HTTP layer, no auth, no UI. It returns arrays; your router, session and
  frontend decide status codes and pixels.

## Requirements

* PHP >= 8.1 with `pdo` and `json`. `ext-pdo_mysql` (or `ext-pdo_sqlite`) for the DB.
* Any OpenAI-compatible chat endpoint (`/chat/completions`). Embeddings, vision
  and audio endpoints are optional and each feature degrades without them.
* Composer `require-dev` `robmorgan/phinx` only to run migrations. No required
  runtime dependency is added to your autoloader beyond the package itself.

## Guides

Start with [docs/README.md](docs/README.md) for the reading order.

1. [Installation & migrations](docs/01-installation.md) — what tables appear, how to re-run safely, `composer update` automation
2. [Connecting your application](docs/02-connect-application.md) — `AssistantApi`, every host interface, hooks, user context
3. [Configuration & environment](docs/03-configuration.md) — the whole config tree, precedence, every env key
4. [Schema training & column descriptions](docs/04-schema-training.md) — teach the assistant what your columns mean
5. [Knowledge & documents](docs/05-knowledge-and-documents.md) — FAQ training, CSV import, uploads, retrieval
6. [Data questions, analysis & charts](docs/06-data-and-charts.md) — semantic layer, query plans, visualisation
7. [Exports](docs/07-exports.md) — formats, approval gates, download tokens, host exporters
8. [Reports & schedules](docs/08-reports-and-schedules.md) — saved reports, cron delivery, feedback loop
9. [Actions & outbox](docs/09-actions-and-outbox.md) — safe writes into your app, queued notifications
10. [Channels & WhatsApp](docs/10-channels-and-whatsapp.md) — inbound handling, auto-reply, legacy service
11. [Governance](docs/11-governance.md) — audit, usage, rate limits, settings, conversations, retention
12. [API reference](docs/12-api-reference.md) — every method, every `handle()` action, the `ok`/`error` contract
13. [LLM providers](docs/13-llm-providers.md) — OpenAI, OpenRouter, Ollama, vLLM, DeepSeek, local
14. [Troubleshooting](docs/14-troubleshooting.md) — failures you will actually see, and what they mean

## Development

```bash
cd /path/to/ai-assistant
composer install            # dev: phinx
php tests/run.php           # every suite, no DB or network needed
```

The suites are plain PHP scripts with their own assertions (no PHPUnit):
`assistant`, `api`, `schema`, `knowledge`, `semantic`, `exports`, `actions`,
`channels`, `governance`, `config`, `conversation`. They run against SQLite in a
temp file, so `composer install && php tests/run.php` is green on a clean machine.

To try the migrations locally:

```bash
DB_ADAPTER=sqlite DB_NAME=/tmp/ai_mig.sqlite php migrate.php
```

`BLACKBOX_DECOUPLING_PROPOSAL.md` is a historical design note from the
pre-rename days. The guides in `docs/` are the current contract; where the two
disagree, `docs/` wins.
