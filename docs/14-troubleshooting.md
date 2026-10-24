# 14 — Troubleshooting

Start with `diagnostics()`. It answers most of the questions below in one call,
and it never echoes a secret.

```php
$d = $api->diagnostics();

$d['database']['connected'];       // PDO present?
$d['database']['missing'];         // tables the migrations have not created
$d['llm']['ready'];                // client could be built
$d['llm']['error'];                // why not, when false
$d['settings']['database_layer'];  // {prefix}_config is feeding Config
$d['settings']['present'];         // the table exists
$d['policy']['mode'];              // denylist | allowlist | all
$d['features'];                    // enabled feature names
$d['wiring'];                      // which collaborators you injected
```

Then read how a value actually resolved:

```php
$api->handle('config.get', ['company_id' => 0, 'path' => 'schema_policy.mode']);
// ['setting' => ['value' => 'allowlist', 'default' => 'denylist',
//                'source' => 'database' | 'env' | 'file' | 'default',
//                'overridden' => true]]
```

- [Nothing works / setup](#nothing-works--setup)
- [Settings do not apply](#settings-do-not-apply)
- [The model is not answering properly](#the-model-is-not-answering-properly)
- [No tables / no data answers](#no-tables--no-data-answers)
- [Training and column descriptions](#training-and-column-descriptions)
- [Documents and knowledge](#documents-and-knowledge)
- [Charts](#charts)
- [Exports](#exports)
- [Actions](#actions)
- [Outbox](#outbox)
- [Reports and schedules](#reports-and-schedules)
- [WhatsApp](#whatsapp)
- [Audit, usage and feedback](#audit-usage-and-feedback)
- [SQL and storage errors](#sql-and-storage-errors)
- [Collecting a useful bug report](#collecting-a-useful-bug-report)

---

## Nothing works / setup

| Symptom | Cause and fix |
|---|---|
| `Class "ExcelleInsights\…" not found` | `composer dump-autoload`; confirm `vendor/autoload.php` is loaded. |
| `DB_DSN not set` from `AiAssistantService` | The legacy service builds its own PDO. Either pass `$pdo` or set `DB_DSN`. `AssistantApi` never opens a connection. |
| `migrate.php` says "Database config incomplete" | The host `.env` lacks `DB_NAME` (and `DB_HOST`/`DB_USER` for non-sqlite). The script reads the host keys, not `AI_ASSISTANT_*`. |
| `Phinx not found` | `composer require --dev robmorgan/phinx`, or run your own `phinx -c vendor/excelle-insights/ai-assistant/phinx.php`. |
| `missing` lists tables after a deploy | Migrations did not run. Composer does not run a library's scripts; add the `post-autoload-dump` entry shown in [01 — Installation](01-installation.md#running-migrations-on-every-composer-update). |
| Tables are created under the wrong name | `AI_ASSISTANT_TABLE_PREFIX` is read **before** a database connection, so it cannot come from `{prefix}_config`. Set it in `.env`. |
| `diagnostics()['database']['connected'] === false` | `null` was passed as the PDO. Pure formatting still works; nothing is stored. |
| Everything works in CLI, nothing in the web app | The two processes load different `.env` files (or the web process has no `EnvLoader::load()` call). |

---

## Settings do not apply

| Symptom | Cause and fix |
|---|---|
| A saved value has no effect | Environment variables are the break-glass layer and **beat** the database. Check `config.get`: `source` would say `env`, not `database`. |
| Value reverts after a deploy | You are looking at a different `company_id` row. `company_id = 0` is the global row; a tenant row wins key by key. |
| `config.save` returns `unknown` | The path is not in the catalogue (it is a secret, a class registration, or pre-connection). See [03 — Configuration](03-configuration.md#config-that-cannot-come-from-the-database). |
| `config.save` returns `errors` for a path | Type/option validation failed — the message names the expected type and allowed values. |
| Save works but the running process still uses the old value | `saveConfig()` calls `Config::refreshSettings()`; a direct `ConfigService::save()` does not. Call `Config::refreshSettings()` yourself. |
| Booleans saved as "yes" stay false | Accepted true words are `1`, `true`, `yes`, `on`; `0`, `false`, `no`, `off` and `''` are false. A different word is coerced by `(bool)`, which is true. |
| Lists ignore one entry | Send a CSV string or a real array; whitespace around entries is trimmed. |
| `settings.put` value never reaches the prompt | `{prefix}_settings` is *preference* storage passed as `system_context`; a behavioural change belongs in `config.save`. |
| `{prefix}_config` is missing | No overrides are applied and `config.save` throws. Run migrations; nothing else breaks. |

---

## The model is not answering properly

| Symptom | Cause and fix |
|---|---|
| Answers are short, generic, or missing the "answered by" sentence | The LLM call failed and was swallowed. Check `diagnostics()['llm']` and `audit.query` with `events => ['assistant.llm_failed']`. |
| `LLM_API_KEY … not set` | No key and the endpoint is not local. Set `LLM_API_KEY` (or `OPENAI_API_KEY`). |
| `Unknown LLM_PROVIDER "…"` | The variable takes a provider (`openai`, `ollama`, …), not a model. Put the model in `LLM_MODEL`. |
| Calls go to OpenAI but you configured `vllm` | `vllm` / `lmstudio` / `custom` need an explicit `LLM_BASE_URL`. |
| Plans come back as prose | The model is too weak for JSON planning. Use a larger model; planning always runs at temperature 0. |
| Timeouts under load | Raise `LLM_REQUEST_TIMEOUT`; the package does not retry. Retry in your job/queue. |
| Embeddings are `[]` | The provider/model has no embeddings endpoint. Retrieval falls back to lexical — functional, less semantic. |
| Token/cost rollups stay zero | A custom client omitted `usage` or used names outside `prompt_tokens`/`completion_tokens`/`input_tokens`/`output_tokens`. |

Model connection values are intentionally environment-only and cannot be set
from the database; see [13 — LLM providers](13-llm-providers.md).

---

## No tables / no data answers

| Symptom | Cause and fix |
|---|---|
| `capabilities()` shows no datasets | `schema_policy.mode = allowlist` with an empty `allowed_tables` allows nothing. Add tables or switch mode. |
| Your app's tables are invisible | They are in `blocked_tables`, or the mode is `all` and they are the package's own tables (`include_own_tables = false`). |
| A table is visible but some columns never appear | `blocked_columns` (never shown) or `masked_columns` (shown redacted). Credentials are always blocked by a policy floor. |
| Questions compile but return nothing | `require_tenant_scope` is on and the table has a tenant column the user does not match. Check the compiled `query` from `explain`. |
| `sql.run` says `forbidden` | Raw SQL is off. Set `semantic.allow_raw_sql` and list the role in `semantic.raw_sql_roles`; the SQL still passes the table policy. |
| Row counts look capped | `schema_policy.default_limit`, `schema_policy.max_rows`, and the outer `limits.max_rows`. |
| New tables are not known | Run `schema.sync` (or `schema.train` after adding descriptions). |
| An answer says "ungrounded" | The question did not map to a dataset. Check column descriptions — the planner relies on them, not on your table names. |

---

## Training and column descriptions

| Symptom | Cause and fix |
|---|---|
| `schema.import-dump` imports nothing | The dump must contain `CREATE TABLE` statements. Plain `INSERT`-only dumps train no structure. |
| Descriptions are ignored | They are attached to `table.column`; a mismatch (case, prefix, old name) attaches nowhere. Re-run `schema.sync` then `schema.save`. |
| The assistant guesses meanings | `schema.auto_describe` fills blanks with guesses. Replace them with real descriptions via `schema.save` / `schema.import-csv`; that is the highest-leverage training step. |
| `trainFromConnection` fails | The source PDO must be readable and the same adapter family. Training reads structure and reference values only, never copies rows. |
| Reference values are stale | `referenceValues()` then `forgetReferenceValues()` and retrain; or set `reference.auto_suffixes` to the columns that hold statuses/types. |
| Metrics are missing | Metric derivation reads column names. If your names are opaque, author `metrics` in the semantic pack instead of relying on derivation. |

---

## Documents and knowledge

| Symptom | Cause and fix |
|---|---|
| Upload comes back `needs_extractor` | No extractor can read that MIME. Register a host extractor (PDF/DOCX/OCR); the file and row are kept so re-indexing does not need a re-upload. |
| PDF/DOCX text is empty | No extractor is registered. Register host extractors — the package does not bundle OCR. See [05 — Knowledge & documents](05-knowledge-and-documents.md). |
| Knowledge search returns nothing | `features` excludes `documents` (which disables the document hook), `knowledge.top_k` is 0, or `knowledge.min_similarity` is too high. Lexical fallback still needs a term match. |
| A knowledge entry never appears | It is `status = inactive` or `effective_from` is in the future. |
| Duplicate entries | Dedupe is by `content_hash`; entries that differ by whitespace still dedupe, entries that differ in wording do not. |
| `feedbackToKnowledge` says `knowledge_table_missing` | Run migrations. That method writes to `{prefix}_knowledge` directly. |
| Uploads reject a valid file | `limits.max_upload_mb` or `limits.max_pages`, or the MIME is outside `documents.allowed_mime` (empty means allow the built-in list). |

---

## Charts

| Symptom | Cause and fix |
|---|---|
| `chart.build` returns a table instead of a chart | The data has no numeric series or no label dimension. Check `chart.suggest` output first. |
| A request keeps asking for a pie with 40 slices | Cap it with `charts.pie_max_categories` (bar charts use `charts.bar_max_categories`); the builder falls back to a bar/table. |
| The chart disappears in a widget | `charts.enabled_types` excludes that type, `features` excludes `charts`, or the type requested is not in `ChartService::types()`. |
| Labels are truncated | `charts.compact_axis`, `charts.multi_axis_label` and the label/axis budget; fewer categories also helps. |
| The "insight" sentence is missing | No model reply was available; the chart spec is still valid. |

All `charts.*` paths are editable from `{prefix}_config`; see
[03 — Configuration](03-configuration.md#charts--visualisation-shape-hints-charts_json).

---

## Exports

| Symptom | Cause and fix |
|---|---|
| `format` returns `No exporter is registered` | Only `csv`, `tsv`, `json` are built in; `xlsx`/`pdf`/`docx` need a host exporter in `exports.host_exporters` (config file only). |
| Export refuses with `forbidden` | The format is not in `exports.formats`, the user cannot export that entity, or a blocked column is in the selection. |
| Status stays `awaiting_approval` | `exports.require_confirmation` is on and the user lacks `exports.approval_roles`. Approve with `export.approve`. |
| Status stays `queued` | Row count exceeded `exports.async_above_rows`; run `export.run-queued` from cron with a user context. |
| Rows are missing | The assistant's row cap (`schema_policy` / `limits.max_rows`) applied before the export; `meta.truncated` says so. |
| Download says `not_found` | Token expired (`exports.download_ttl_minutes`), file purged, never produced, or belongs to another company. |
| `Cannot write the export file` | `exports.directory` is not writable. It defaults to the system temp directory. |
| XLSX arrives as a path, not bytes | By design: host exporters return a location/bytes; the API returns the path for your app to stream. |

---

## Actions

| Symptom | Cause and fix |
|---|---|
| `action.catalog` is empty | No rows in `{prefix}_actions` and no handler. The package never invents actions; your app inserts the catalogue. |
| `action.preview` says the action is unknown | The row is `enabled = 0`, or the name does not match the catalogue. |
| Confirming says `expired` | `actions.confirmation_ttl_minutes` elapsed. Preview again. |
| Confirming says `forbidden` | The confirmer is not the proposer's tenant/user, or lacks the role recorded on the proposal. |
| The host wrote twice | The action was executed twice without the same `idempotency_key`. Pass one per user intent. |
| No audit rows for actions | `audit.enabled` is off, or the audit sink is unreachable (writes are swallowed). |

---

## Outbox

| Symptom | Cause and fix |
|---|---|
| `outbox.queue` refuses | There is no recipient, or no notifier to deliver it. Pass `services['notifier']`. |
| `sent` is always 0 | `flushOutbox` found no `due()` rows: they are scheduled in the future, or `scheduled_at` is in the past but status is not `pending`. |
| Messages retry forever | Check the notifier's error text on the row; after `outbox.max_attempts` the status becomes `failed`. |
| Backoff grows too fast | It doubles from `outbox.backoff_seconds`, capped at 2 hours. |
| A cancelled message still went out | Cancellation only affects `pending` rows; an in-flight flush already handed it to the notifier. |

See [09 — Actions & outbox](09-actions-and-outbox.md).

---

## Reports and schedules

| Symptom | Cause and fix |
|---|---|
| `report.run` returns `runner_missing` | There is no `services['report_runner']`. The compiled request is still returned so you can execute it yourself. |
| `report.run` returns `forbidden` | The report's `visibility`/`visible_to` excludes this user, or the definition references a blocked dataset. |
| A schedule never fires | Status is `paused`/`disabled`, `next_run_at` is in the future, or nothing calls `schedule.tick`. The package never runs a daemon. |
| A schedule disabled itself | `markScheduleRun(false)` reached the max failure count; inspect `last_error`, then resume it. |
| The cron expression looks wrong | Check it with `nextRuns($cron)`. Five fields: minute hour day-of-month month day-of-week. |
| Duplicate deliveries | `tick` advances `next_run_at` before handing off, but two tick workers can still overlap; run one worker or use your queue's lock. |

See [08 — Reports & schedules](08-reports-and-schedules.md).

---

## WhatsApp

| Symptom | Cause and fix |
|---|---|
| `code: unavailable` | No `services['whatsapp_sender']` and no `services['channel']`. |
| Nothing is flattened | The payload is not Meta-shaped and has no `from`/`text`/`messages` key; call `whatsapp.inbound` to see what the adapter read. |
| Duplicate answers | The transport did not carry `wamid` as an idempotency key, or each webhook is handled by a fresh process with no shared dedupe. |
| Long replies are cut | Lower `channels.whatsapp.max_message_chars` so the adapter splits before the transport truncates. |
| Images/voice notes are skipped | They carry no text. Transcribe/caption first. |
| The legacy pipeline writes `disabled` | `channels.whatsapp.auto_reply` is off. |
| The legacy pipeline writes `failed` | The conversation/message rows were not found, or `canAutoReply()` returned false. |
| `{prefix}_messages` does not exist | Set `channels.whatsapp.table_prefix` to the host's actual prefix. |
| A closed-window message is queued, not sent | `fallback_template_id` is set and the session expired; clear it to attempt text outside the window. |

See [10 — Channels & WhatsApp](10-channels-and-whatsapp.md).

---

## Audit, usage and feedback

| Symptom | Cause and fix |
|---|---|
| `audit.query` returns `[]` | Table missing, `audit.enabled` off, or `audit.store = 'sink'` with no sink. |
| `sql_text` is null | `audit.record_sql` is off; `query_hash` is still there. |
| Audit CSV is empty | An empty result returns `''`, not a header. Check the filter window. |
| `limit.check` always allows | The resolved limit is 0. Set `rate_limit.per_hour` or the per-dimension key. |
| Hourly counters are not shared | `{prefix}_rate_limits` is missing. Run migrations. |
| `usage.today` is all zeros | Nothing calls `record()`; `chat()` records `chats`, but host jobs must record their own counters. |
| A preference is ignored | Scope/key mismatch. Verify with `settingsList()` for the exact scope. |
| `feedbackList` is empty | The rating was stored under a different `company_id`; submit `target.company_id` explicitly. |

See [11 — Governance](11-governance.md).

---

## SQL and storage errors

| Symptom | Cause and fix |
|---|---|
| `Table 'db.ai_assistant_x' doesn't exist` | Migrations not run, or `AI_ASSISTANT_TABLE_PREFIX` differs from the one used at migration time. |
| MySQL "Unknown column" after an upgrade | A migration was skipped. Re-run `migrate.php`; it is idempotent. |
| `SQLSTATE[HY000] [2002]` | The host `.env` points at a different server than the app. The package uses your PDO; migrations use `DB_*`. |
| Writes silently do nothing | The chat path swallows storage errors by design. Pass `on_error` to see them: `new AssistantApi($pdo, ['on_error' => fn ($e) => $log->error($e->getMessage())])`. |
| A value is redacted unexpectedly | `masked_columns` matched it, or it looks like an email/phone in the audit trail. |

---

## Collecting a useful bug report

```php
$report = [
    'diagnostics' => $api->diagnostics(),
    'config'      => $api->handle('config.get', ['company_id' => $tenantId]),
    'explain'     => $api->explain($plan, $user),          // if data is involved
    'audit'       => $api->auditTrail(['company_id' => $tenantId], 20),
];
```

`diagnostics()` and `config.get` never contain secrets; `auditTrail` questions
and SQL are already PII-masked when a policy is present. Include the package
version (`AssistantApi::VERSION`) and the PHP version.

If you are developing the package itself, `php tests/run.php` runs every suite
in its own process; a failing suite prints the assertion, not a stack of
unrelated output.
