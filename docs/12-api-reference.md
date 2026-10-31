# 12 — API reference

`AssistantApi` is the whole public surface. It reads no superglobals, prints
nothing and routes nothing: you pass values in, it returns arrays. That makes it
usable from a controller, a queue worker, a CLI command, a test, or another
library.

- [Constructing the API](#constructing-the-api)
- [The result envelope](#the-result-envelope)
- [Who is asking](#who-is-asking)
- [The `handle()` actions](#the-handle-actions)
- [Direct methods](#direct-methods)
- [Service objects](#service-objects)
- [Common payload shapes](#common-payload-shapes)
- [Errors and codes](#errors-and-codes)

---

## Constructing the API

```php
use ExcelleInsights\AiAssistant\Http\AssistantApi;

$api = new AssistantApi(
    $pdo,          // ?PDO — null still allows formatting / validation / planning
    $services,     // host collaborators (see below)
    $options,      // per-service build options
);
```

Everything is optional. With no PDO and no services, pure logic still works:
chart building, export formatting, plan validation, WhatsApp chunking and the
config catalogue.

### `$services` keys

| Key | Type | Enables |
|---|---|---|
| `llm` | `LlmClientInterface` | a model other than the built-in OpenAI-compatible client |
| `assistant` | `AssistantService` | replace the runtime entirely |
| `policy` / `table_policy` | `SchemaPolicy` / `TablePolicyInterface` | table/column rules |
| `permissions` | `PermissionResolverInterface` | per-user dataset permission checks |
| `action_handler` | `ActionHandlerInterface` | propose/execute writes |
| `notifier` | callable or `NotificationInterface` | outbox delivery |
| `runner` | callable | run a compiled plan (default: SQL against `$pdo`) |
| `report_runner` | callable | execute a report definition |
| `conversations` | `ConversationStore` | conversation persistence |
| `code` | `CodeIndexService` | replace the code index |
| `code_scanner` | `CodeScannerInterface` | read the CRM's code with your own scanner |
| `document_store` | `DocumentStoreInterface` | document bytes |
| `chunk_index` | `ChunkIndexInterface` | document chunk search |
| `extractors` | array/class | document text extraction (PDF/DOCX/OCR) |
| `config` | array | a host config array (merged over the package file) |
| `configuration` | `ConfigService` | replace the `{prefix}_config` service |
| `whatsapp_sender` | callable | WhatsApp replies |
| `channel` | `ChannelInterface` | any delivery surface |
| `hooks` | array | extra runtime hooks (`rows`, `audit`, `usage`, …) |
| `on_error` | callable | receives swallowed storage/LLM errors |

`$options` keys: `schema`, `knowledge`, `documents`, `conversations`,
`assistant`, `semantic`, `channel`, `code`, `settings` (e.g.
`['enabled' => false]` to keep the DB settings layer out of `Config`).

```php
$api->connection();      // ?PDO
$api->service('audit');  // a built service: llm, policy, schema, knowledge,
                         // relations, code, documents, export, actions, outbox,
                         // reports, schedules, feedback, usage, settings,
                         // configuration, audit, charts, conversations, assistant
$api::VERSION;           // package version string
```

---

## The result envelope

Every public method returns the same envelope:

```php
['ok' => true,  'answer' => […]]                  // success
['ok' => false, 'error' => '…', 'type' => 'RuntimeException']  // thrown
['ok' => false, 'code' => 'not_found', 'error' => '…']         // expected refusal
```

- `ok` is `true` unless the payload has a non-null `error`.
- `code` is set for expected outcomes (`not_found`, `forbidden`, `unavailable`,
  `runner_missing`, `incomplete_qa`, …), so a UI can branch without parsing text.
- An exception is caught and returned as `ok: false` + `type`; it never escapes
  from `handle()`. Calling a method directly gets the same array.

`handle()` also knows the action names below; an unknown name returns
`['ok' => false, 'error' => …, 'actions' => [<every name>]]`.

---

## Who is asking

Every action that acts for somebody takes `user`:

```php
'user' => [
    'user_id'  => '7',
    'tenant_id' => 3,          // company_id / companyId also accepted
    'channel'  => 'web',
    'thread_id' => 'conv-9',
    'roles'    => ['admin', 'sales'],
    'attributes' => ['region' => 'KE'],
]
```

It may also be a `UserContext` or a `UserContextInterface`. A missing user is an
error for actions that need one (`forUser()`), so a request body cannot be
mistaken for a tenant grant. For actions that do not need a person, the tenant
comes from `tenant_id`, then `company_id`, then the user, then `tenant.id`.

---

## The `handle()` actions

Every row is `$api->handle('<name>', $input)`.

### Conversation and analysis

| Action | Input keys | Returns |
|---|---|---|
| `chat` | `question` (or `text`), `user`*, `options` | `answer` (array + `plain`) |
| `capabilities` | `user` | `capabilities` |
| `diagnostics` | — | package/database/llm/features/settings/policy/exports/wiring |
| `explain` | `plan`, `user`* | `query` — compiled SQL + scope, not executed |
| `query.run` | `plan`, `user`*, `chart` | `result` |
| `sql.run` | `sql`, `user`* | `result` — policy-guarded, `semantic.allow_raw_sql` |
| `chart.suggest` | `data`, `intent` | `chart`, `types` |
| `chart.build` | `type`, `data`, `options` | `spec`, `insight` |

### Exports

| Action | Input keys | Returns |
|---|---|---|
| `export.create` | `data`, `format`, `user`*, `meta` | export envelope (id, status, token, size…) |
| `export.approve` | `id`, `user`*, `data` | export envelope |
| `export.download` | `token`, `user` | `file` (path/name/mime), `content_disposition` |
| `export.history` | `user`*, `limit` | `items` |
| `export.run-queued` | `limit`, `user` | `jobs` |

### Actions and outbox

| Action | Input keys | Returns |
|---|---|---|
| `action.catalog` | `tenant_id` / `company_id` | `actions` |
| `action.preview` | `action`, `params`, `user`*, `meta` | `id`, `status`, `proposal`, `confirm_token`, `expires_at` |
| `action.confirm` | `token`, `user`*, `idempotency_key` | `status`, `id`, `action`, `result` |
| `action.cancel` | `id`, `user`* | `cancelled` |
| `action.history` | `tenant_id` / `company_id`, `user_id`, `limit` | `items` |
| `outbox.queue` | `message`, `user_id` | `id` |
| `outbox.flush` | `limit` | `sent`, `failed`, `pending`, `results` |
| `outbox.counts` | `tenant_id` / `company_id` | `counts` |

### Documents and knowledge

| Action | Input keys | Returns |
|---|---|---|
| `document.upload` | `file`, `user`*, `meta` | `document` |
| `document.list` | tenant, `limit` | `items` |
| `document.search` | tenant, `query`, `k` | `hits` |
| `knowledge.list` | tenant, `limit` | `items` |
| `knowledge.add` | `title`, `content`, `source`, `scope`… | `entry` |
| `knowledge.import` | `file`, `source` | `imported` |
| `knowledge.search` | tenant, `query`, `k`, `user` | `hits` |

### Schema and training

| Action | Input keys | Returns |
|---|---|---|
| `schema.sync` | `options` | `schema` |
| `schema.list` | `limit` | `items` |
| `schema.describe` | `table`, `max_columns` | `table`, `columns` |
| `schema.save` | `table`, `column`, `description`, `meta` | `saved` |
| `schema.import-dump` | `file`, `options` | `imported`, `skipped` |
| `schema.import-csv` | `file`, `options` | `imported`, `skipped` |
| `schema.train` | `options` | `schema`, `references`, `metrics`, `counts` |

`file` accepts a `$_FILES` entry, an absolute path, raw text, base64 or an
`UploadedFile` — the same normaliser for every upload action.

### Relations — what a key column stands for

| Action | Input keys | Returns |
|---|---|---|
| `relation.list` | tenant, `filter` | `items` |
| `relation.discover` | tenant, `options` | `discovery` |
| `relation.approve` | tenant, `id` | `relation` |
| `relation.reject` | tenant, `id` | `relation` |

`relation.discover` looks through the catalogue for key columns (`staff_id`,
`client_uuid`, anything the catalogue calls an id) and proposes the table they
point at. A proposal backed by strong evidence — a declared foreign key, or
matching key values — is verified on its own and joins the semantic layer; a
weaker one waits as `proposed` until it is approved. A rejected relation never
comes back.

### The CRM's own code

| Action | Input keys | Returns |
|---|---|---|
| `code.scan` | tenant, `options` | `scan` |
| `code.files` | tenant | `items` |
| `code.queries` | tenant, `options` | `items` |

`code.scan` reads the application's source and remembers the queries it finds.
The scan starts at the navigation map — the file that lists the modules — then
follows the pages it names and the files they include. Each query is stored as
knowledge with `source` = `code`, the tables it touches, its joins and its
WHERE clauses, and a citation back to the file it came from.

It is incremental: every file gets a fingerprint (content hash, size, modified
time) in `{prefix}_code_files`, so a second scan only reads what changed, and a
file that is deleted loses its entries instead of leaving orphans.

The queries are read, never run. The pack stays the authority on what may be
executed, and the host's table and column policy applies to every answer that
uses what was learned.

Settings (`operations_json`):

| Path | Meaning |
|---|---|
| `code.scan_paths` | Absolute paths to the folders that hold the application code |
| `code.max_files` | Files per scan (default 800) |
| `code.max_size` | Largest file read, in bytes (default 1 MiB) |
| `code.max_depth` | How deep to walk (default 8) |
| `code.navigation` | Navigation map files, when they do not follow the usual names |

### The query library

| Action | Input keys | Returns |
|---|---|---|
| `query.list` | tenant, `filter` | `items` |
| `query.get` | tenant, `id` | `query` |
| `query.save` | tenant, `query` | `query` |
| `query.delete` | tenant, `id` | `deleted` |
| `query.harvest` | tenant | `harvest` |

A stored query is a calculation the CRM already has, kept by name: what it
calculates in business language, the tables it touches, the file it came from.
`query.harvest` offers the queries the code scan found as **proposals** — a
person confirms one, describes it and activates it, and from then on the
assistant answers that family of questions the way the business does.

Only an **active** query steers the assistant. A proposal is a candidate, not a
decision: until somebody activates it, it changes nothing.

When a question matches an active query, the router prompt carries the
report's name, what it calculates and its rules (never its SQL), and the answer
cites the report it followed.

### Reports and schedules

| Action | Input keys | Returns |
|---|---|---|
| `report.list` | `folder`, `user` | `items` |
| `report.run` | `id`, `params`, `user`* | `result` or `runner_missing` |
| `schedule.create` | `name`, `cron`, `target`, `channel`… | `schedule` |
| `schedule.tick` | `limit` | `jobs` |

### Governance and configuration

| Action | Input keys | Returns |
|---|---|---|
| `feedback.submit` | `target`, `rating`, `reason`, `comment`, `user` | `feedback.id` |
| `usage.today` | tenant | `usage` |
| `limit.check` | `dimension`, `key`, `window`, `max` | `limit` |
| `audit.query` | `filter`, `limit`, `offset` | `items` |
| `settings.get` | `key`, `default`, `scope` | `value` |
| `settings.put` | `key`, `value`, `scope`, `updated_by` | `saved` |
| `config.describe` | tenant | every editable setting + current value |
| `config.get` | tenant, `path` | `settings` + `effective`, or one `setting` |
| `config.save` | `settings`, `updated_by` | `saved`, `cleared`, `errors`, `unknown`, `settings` |
| `config.forget` | `paths` (or `settings`) | `cleared` |

### Channels

| Action | Input keys | Returns |
|---|---|---|
| `whatsapp.inbound` | `payload` | `messages` (flat) |
| `whatsapp.answer` | `payload`, `defaults` | `replies` |

`*` marks a required user. Everything else is optional or read from the tenant.

---

## Direct methods

The `handle()` map covers the ten most common calls per area. Everything else is
a public method — same envelope, no routing layer.

### Chat and data

`chat()`, `capabilities()`, `explain()`, `runQuery()`, `runRawSql()`,
`buildChart()`, `suggestChart()`.

### Exports

`exportFormats()` (formats + approval + async threshold), `createExport()`,
`approveExport()`, `runQueuedExports()`, `download()`, `exportHistory()`,
`purgeExpiredExports()`.

### Actions and outbox

`actionCatalog()`, `previewAction()`, `confirmAction()`, `cancelAction()`,
`getAction()`, `actionHistory()`, `expireStaleActions()`, `queueNotification()`,
`queueNotifications()`, `dueNotifications()`, `flushOutbox()`,
`outboxHistory()`, `cancelNotification()`, `outboxCounts()`.

### Documents

`uploadDocument()`, `listDocuments()`, `getDocument()`, `documentChunks()`,
`searchDocuments()`, `reindexDocument()`, `deleteDocument()`.

### Knowledge

`listKnowledge()`, `getKnowledge()`, `addKnowledge()`, `updateKnowledge()`,
`setActiveKnowledge()`, `deleteKnowledge()`, `importKnowledgeCsv()`,
`knowledgeTemplate()`, `knowledgeStats()`, `searchKnowledge()`.

### Schema and training

`syncSchema()`, `listSchema()`, `schemaCatalog()`, `describeTable()`,
`searchSchema()`, `saveColumnDescription()`, `schemaTemplate()`,
`importSchemaDescriptions()`, `importSqlDump()`, `trainFromConnection()`,
`train()`, `validateSql()`, `referenceValues()`, `forgetReferenceValues()`.

### Relations and code

`listRelations()`, `discoverRelations()`, `approveRelation()`,
`rejectRelation()`, `scanCode()`, `codeFiles()`, `codeQueries()`.

### The query library

`listQueries()`, `getQuery()`, `saveQuery()`, `deleteQuery()`,
`harvestQueries()`.

### Reports and schedules

`createReport()`, `updateReport()`, `deleteReport()`, `getReport()`,
`listReports()`, `runReport()`, `validateReport()`, `reportHistory()`,
`shareReport()`, `duplicateReport()`, `createSchedule()`, `updateSchedule()`,
`pauseSchedule()`, `resumeSchedule()`, `deleteSchedule()`, `dueSchedules()`,
`tickSchedules()`, `markScheduleRun()`, `nextRuns()`.

### Conversations

`listConversations()`, `getConversation()`, `transcript()`,
`searchConversations()`, `renameConversation()`, `pinConversation()`,
`shareConversation()`, `revokeConversationShare()`, `sharedConversation()`,
`summarizeConversation()`, `deleteConversation()`.

### Governance

`submitFeedback()`, `feedbackList()`, `reviewFeedback()`, `feedbackSummary()`,
`feedbackToKnowledge()`, `usageToday()`, `usageRange()`, `usageByUser()`,
`topQuestions()`, `checkLimit()`, `consumeLimit()`, `auditTrail()`,
`auditSummary()`, `auditCsv()`, `auditVerify()`, `recordAudit()`,
`getSetting()`, `putSetting()`, `forgetSetting()`, `settingsList()`,
`effectiveConfig()`, `describeConfig()`, `getConfig()`, `saveConfig()`,
`forgetConfig()`.

### Channels and meta

`inboundWhatsApp()`, `answerWhatsApp()`, `diagnostics()`, `connection()`,
`service()`, `assistant()`.

---

## Service objects

`service('<name>')` returns the built collaborator, so a host can call a method
that has no API wrapper (a cron task, a migration, a test):

```php
$api->service('export')->purgeExpired(500);
$api->service('actions')->expireStale();
$api->service('audit')->retention(90);
$api->service('schedules')->nextRuns('0 8 * * *', 5, 'Africa/Nairobi');
$api->service('configuration')->effective(3);
```

Naming: `llm`, `policy`, `schema`, `knowledge`, `relations`, `code`,
`documents`, `export`, `actions`, `outbox`, `reports`, `schedules`, `feedback`,
`usage`, `settings`, `configuration`, `audit`, `charts`, `conversations`,
`assistant`.

---

## Common payload shapes

### Rows for exports and charts

An array of associative rows is the default; column order comes from
`meta.columns`, otherwise from the first row:

```php
$api->handle('export.create', [
    'data' => [
        ['region' => 'Nairobi', 'orders' => 120, 'revenue' => 480000],
        ['region' => 'Mombasa', 'orders' => 80,  'revenue' => 290000],
    ],
    'format' => 'csv',
    'user'   => ['user_id' => '7', 'tenant_id' => 3, 'roles' => ['admin']],
    'meta'   => [
        'title'   => 'Orders by region',
        'columns' => ['region', 'orders', 'revenue'],
        'entity'  => 'orders',
        'plan'    => $compiledPlan,   // lets approval re-run the query
    ],
]);
```

`chart.build`/`chart.suggest` accept the same row list, or a `{columns, rows}`
array. `export.create` also accepts a query result array (keys such as `rows`,
`columns`, `sql`, `truncated`).

### Uploads

```php
'file' => $_FILES['dump'],                       // an upload
'file' => '/abs/path/schema.sql',                // a path
'file' => ['name' => 'notes.csv', 'content' => $bytes],  // bytes
'file' => 'title,content' . "\n" . '…',          // raw text
```

Size and page limits come from `limits.max_upload_mb` / `limits.max_pages`.

### Plans

A plan is what `explain`/`query.run`/`sql.run` speak: `dataset`, `select`,
`filters`, `group_by`, `order_by`, `limit`, `tenant_scope`. Build it with the
semantic layer, or let `chat` build it. `validateReport()` and
`validateSql()` check one without running it.

---

## Errors and codes

| `code` | Where | Meaning |
|---|---|---|
| `not_found` | downloads, reports, documents, knowledge, actions, conversations, config.get | id/token/path does not exist for this tenant |
| `forbidden` | raw SQL, exports | policy or role says no |
| `unavailable` | WhatsApp, schema listing, runners | no sender/runner/database was wired |
| `nothing_learned` | schema imports | the dump/CSV contained no usable table structure |

`report.run` does not fail when no runner is wired: it returns the compiled
request with `runner_missing => true`, because the plan is useful on its own.
`feedbackToKnowledge()` reports failures as `reason` (`no_database`,
`feedback_not_found`, `incomplete_qa`, `knowledge_table_missing`) alongside
`ok: false`.

An exception is always caught: `ok: false`, `error`, `type`. Storage failures in
the chat path (audit, usage, conversations) are swallowed by design and reported
to `on_error`; they never take the answer down.
