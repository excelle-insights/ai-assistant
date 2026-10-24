# 07 — Exports

An export is a file made from a result set — the rows behind a chat answer, a
saved report, or any array your app already holds. `csv`, `tsv` and `json` are
written by the package itself, with no dependency. `xlsx`, `pdf`, `docx`,
`gsheets` or anything else become available the moment you register a host
exporter class, because your app already owns that library.

Nothing here writes to your tables and nothing here needs a queue service: a job
may wait in `{prefix}_exports` and a cron entry (or your worker) finishes it.

- [The fast path](#the-fast-path)
- [Formats](#formats)
- [The gates, in order](#the-gates-in-order)
- [What comes back](#what-comes-back)
- [Download links](#download-links)
- [Approval for sensitive columns](#approval-for-sensitive-columns)
- [Large exports](#large-exports)
- [History and cleanup](#history-and-cleanup)
- [Writing a host exporter](#writing-a-host-exporter)
- [Settings that shape the file](#settings-that-shape-the-file)
- [Troubleshooting](#troubleshooting)

---

## The fast path

```php
$api = new AssistantApi($pdo);

$result = $api->handle('export.create', [
    'format' => 'csv',
    'data'   => $answer['answer']['rows'],     // any list of rows, any keys
    'user'   => ['user_id' => '7', 'tenant_id' => 3, 'roles' => ['manager']],
    'meta'   => [
        'title' => 'Orders this quarter',
        'question' => 'Which orders shipped this quarter?',
        'columns' => ['order_no', 'customer_name', 'total'],  // selection + order
        'applied_filters' => ['quarter' => '2026-Q3'],
        'entity' => 'orders',                  // used by canExport(entity)
        'conversation_id' => $answer['answer']['meta']['conversation_id'] ?? null,
    ],
]);

if ($result['ok'] && $result['status'] === 'ready') {
    // Option A: the record exists — return a link your route resolves:
    $url = '/ai/download?token=' . urlencode($result['download_token']);
    // Option B: stream the path straight away (same process, same disk):
    // header('Content-Type: ' . $result['meta']['mime']);
    // readfile($result['location']);
}
```

`data` may be a plain array of rows, a `QueryResult`, or an answer payload — the
service finds the tabular part. Columns not present in a row become empty cells;
`meta.columns` lets you hide ids, reorder fields or drop internals.

## Formats

`ExportService::formats()` answers what this install can produce, and it is the
same list `export.create` accepts:

- the built-ins **∩** `exports.formats` (default `csv,json`; add `tsv` yourself);
- **plus** every format a host exporter is registered for — registering it is
  the opt-in, so an `xlsx` exporter works even when `exports.formats` still says
  `csv,json`.

Asking for anything else fails with a sentence naming the enabled formats:

```json
{"ok": false, "error": "Format \"pdf\" is not enabled here. Enabled: csv, json, xlsx."}
```

## The gates, in order

Every `create()` runs the same sequence. The first gate that refuses stops the
job; nothing is written before it.

| # | Gate | Refusal | What it protects |
|---|------|---------|------------------|
| 1 | Format enabled | `Format "…" is not enabled here.` | you never promise a file the install cannot make |
| 2 | `PermissionResolverInterface::canExport($user, $entity)` | `Your role is not allowed to export "orders".` | per-role export permission |
| 3 | Column policy | blocked columns are **dropped**; masked columns are **redacted**; `pii`/`secret`/`financial` columns are marked **sensitive** | credentials and contact data |
| 4 | Rows and columns remain | `Every column … is blocked for your role`, `There is nothing to export` | an empty file is a bug, not a deliverable |
| 5 | Sensitive approval | job saved as `awaiting_approval` | a human lets sensitive data out |
| 6 | Size | job saved as `queued` above `exports.async_above_rows` | the HTTP request does not time out |
| 7 | Render | status `ready` or `failed` (with `error`) | the file really exists |

Column rules come from `schema_policy`: `blocked_columns` are removed from the
result entirely, and `masked_columns` stay in the file with their values replaced
by asterisks. Sensitivity is inferred from the column name and the schema
catalogue. A column you want approval-gated when exported must be classified
`pii`, `secret` or `financial` — see [04 — Schema & training](04-schema-training.md).

`meta.entity` is what `canExport()` receives. Leave it empty and a resolver that
scopes by entity has nothing to check; set it to the real table/dataset name.

## What comes back

`handle('export.create')` always answers with the same envelope:

```php
[
    'ok' => true,
    'format' => 'csv',
    'file_name' => 'orders-this-quarter-20261025-101500-a1b2c3.csv',
    'location' => '/tmp/ai-assistant-exports/orders-…csv',   // null for waiting/queued jobs
    'error' => null,
    'size' => 48213,
    'id' => 'e1f2…',                 // the job row, when there is a database
    'status' => 'ready',             // ready | queued | awaiting_approval | failed
    'download_token' => '9f2c…',     // resolve this, do not guess a path
    'meta' => [
        'rows' => 1200, 'bytes' => 48213, 'mime' => 'text/csv',
        'expires_in_minutes' => 1440, 'truncated' => false, 'recorded' => true,
    ],
]
```

Three states are not failures even though there is no file yet:

- `awaiting_approval` — a sensitive job is parked; approve it (below).
- `queued` — the job is too large for one request; a worker finishes it.
- `failed` — `error` says why; nothing was left behind.

Without a PDO the service still renders the file, but `id` and
`download_token` are empty and `meta.recorded` is `false` — there is no row a
link could resolve against, so hand `location` to your own download route.

## Download links

`export.download` **never streams bytes through JSON**. It resolves a token to
the file's *path*, name, MIME type and size; your route sends it:

```php
$found = $api->handle('export.download', [
    'token' => $_GET['token'] ?? '',
    'user'  => ['user_id' => $userId, 'tenant_id' => $tenantId],
]);
if ($found['ok']) {
    $file = $found['file'];   // path, file_name, mime, size, format
    header('Content-Type: ' . $file['mime']);
    header('Content-Disposition: ' . $found['content_disposition']);
    header('Content-Length: ' . $file['size']);
    readfile($file['path']);
}
```

The token is per job, not per click: it can be used again until
`exports.download_ttl_minutes` runs out (default 24 h) or the job is purged, which
is what makes a "your report is ready" e-mail work twice. When you pass `user`,
the token must belong to that company; without it, the token alone is the
capability — so always pass the user when you have one. A token that is expired,
points at a deleted file, belongs to another company, or whose job is not `ready`
resolves to `code: not_found`.

## Approval for sensitive columns

```php
$waiting = $api->handle('export.create', [
    'format' => 'csv',
    'data' => $rows,                       // includes customer_phone, total
    'user' => $requester,
    'meta' => ['entity' => 'customers', 'title' => 'Customer list'],
]);
// status = awaiting_approval, meta.sensitive_columns = ['customer_phone']

$approved = $api->handle('export.approve', [
    'id' => $waiting['id'],
    'user' => ['user_id' => '1', 'tenant_id' => 3, 'roles' => ['admin']],
    'data' => $rows,                       // optional; see below
]);
```

- Approval needs the requester or a role in `exports.approval_roles`
  (default `admin,owner`).
- When the job stored a re-runnable plan (`meta.plan`), `approve()` fetches the
  rows again at approval time **under the approver's permissions** and re-runs
  every gate. Data that was fine an hour ago is checked again.
- When the job stored no plan and no rows, approval fails with "This job kept no
  rows and no plan a runner can re-run" — pass `data` to `approve()`, or create
  the export with a `plan` (see below).
- If the re-check finds nothing (deleted rows, tightened policy) the job becomes
  `failed` and says so; it never produces an empty file silently.
- The approver's id and timestamp are stored in the row, which is the audit
  answer to "who exported customer phone numbers".

Set `exports.require_confirmation=false` only when every exporter in the install
is already allowed to see those columns — it removes the human gate, not the
column policy.

## Large exports

Above `exports.async_above_rows` (default 5000) the rows are not written in the
request. The job is stored with `plan_json` and status `queued`; a cron entry
finishes it:

```php
// cron: every minute
$api->runQueuedExports(5);          // no handle() action, by design
```

A queued job needs either a stored plan the runner can fetch or rows passed to
`runQueuedExports()`'s equivalent service call. The `rows` hook —
`services['rows']` — is what the service uses to re-fetch; without it a queued
job fails with "No rows runner is registered for queued exports", which is
better than writing a half file.

Large files are still capped by `exports.max_rows` (default 100000): extra rows
are dropped and the result carries `meta.truncated = true`.

## History and cleanup

```php
$api->handle('export.history', ['user' => $user, 'limit' => 20]);
// items: id, format, file_name, title, row_count, size_bytes, status,
//        created_at, expires_at, error

$api->purgeExpiredExports(200);     // ['purged' => [rows, filesRemoved]]
```

`history()` is scoped to the company **and** the user — a colleague cannot list
your exports. Put `purgeExpiredExports` on a nightly cron: it deletes expired
rows and their files, and is the only sanctioned deletion. It is a method, not a
`handle()` action, because a public action that deletes other people's files is
not something to expose carelessly.

## Writing a host exporter

Register it in your config file (never in `{prefix}_config` — a JSON table
cannot name a class safely):

```php
// config/ai-assistant.php in YOUR app
use App\Ai\ExcelExporter;

return [
    'exports' => [
        'formats' => ['csv', 'json', 'xlsx'],
        'host_exporters' => ['xlsx' => ExcelExporter::class],   // or a callable
    ],
];
```

```php
namespace App\Ai;

use ExcelleInsights\AiAssistant\Contracts\ExporterInterface;
use ExcelleInsights\AiAssistant\Dto\ExportRequest;
use ExcelleInsights\AiAssistant\Dto\ExportResult;

final class ExcelExporter implements ExporterInterface
{
    public function export(ExportRequest $request): ExportResult
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($request->columns, null, 'A1');
        $sheet->fromArray($request->rows, null, 'A2');

        $path = sys_get_temp_dir() . '/' . $request->fileName;
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        // A path in `location` is yours; the package stores it as-is.
        return ExportResult::external('xlsx', $path, $request->fileName);
    }
}
```

`ExportRequest` carries `columns`, `rows` (already filtered and masked),
`fileName`, `title`, `question`, `appliedFilters`, `chart`, `user` and
`options`. A callable exporter may return an `ExportResult` or throw; anything
else is refused. `ExportResult::external()` is for handles that are not files (a
Google Sheet id, an S3 URI) — the download action then finds nothing local, so
your own route serves it.

`\Throwable` from an exporter fails the job with its message, so a missing
PhpSpreadsheet shows up as an error on the export, not a 500 in chat.

## Settings that shape the file

All are editable in the settings screen (`config.save`) unless noted; the env key
still works.

| Setting | Default | Effect |
|---------|---------|--------|
| `exports.formats` | `csv,json` | which built-ins are offered |
| `exports.async_above_rows` | `5000` | rows above this are queued |
| `exports.max_rows` | `100000` | hard row cap; excess is truncated and flagged |
| `exports.directory` | `<tmp>/ai-assistant-exports` | where files are written; must be writable by PHP |
| `exports.file_name_prefix` | `ai-export` | fallback slug when a title has no usable characters |
| `exports.download_ttl_minutes` | `1440` | how long a token resolves |
| `exports.require_confirmation` | `true` | sensitive columns need approval |
| `exports.approval_roles` | `admin,owner` | who may approve |
| `exports.csv_delimiter` | `,` | `;` is common for local Excel; tsv always uses a tab |
| `exports.csv_newline` | `CRLF` | `CRLF`, `LF` or `CR` |
| `exports.csv_bom` | `true` | Excel needs the BOM to notice UTF-8 |
| `exports.csv_quote_all` | `false` | quote every field, not only those needing it |
| `exports.host_exporters` | `[]` | **config file only** — format ⇒ class or callable |

`exports.directory` is a convenience default, not a storage abstraction: a
multi-server deployment should point it at shared storage, or register a host
exporter that uploads somewhere durable.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `Format "xlsx" is not enabled here` | no exporter registered, or it is not in `exports.formats` | register `exports.host_exporters` — registering alone is enough |
| `Your role is not allowed to export "orders"` | `canExport()` returned false | grant the role, or leave `meta.entity` empty if your resolver allows empty |
| `Every column in that export is blocked` | all requested columns are in `blocked_columns` / the credential floor | choose readable columns |
| `status: awaiting_approval` forever | nobody called `export.approve` | approve as requester or an `approval_roles` member |
| `status: queued` forever | cron never calls `runQueuedExports` | add it to cron and wire `services['rows']` |
| `Cannot write the export file into …` | directory missing/unwritable | create it, `chmod`, or point `exports.directory` at a writable path |
| `download` → `not_found` | token expired, file purged, job not `ready`, or another company | check `expires_at`, re-export |
| An exporter returns nothing usable | callable returned neither `ExportResult` nor a file | return `ExportResult::file()` / `::external()` |
| CSV opens as one column in Excel | delimiter mismatch | set `exports.csv_delimiter=;` or open as UTF-8 CSV |

**Next:** [08 — Reports & schedules](08-reports-and-schedules.md)
