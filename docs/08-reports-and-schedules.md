# 08 — Reports & schedules

A saved report is a **definition**, not a snapshot: intent, dataset, filters,
chart type and columns. Running it compiles that definition into the same kind of
request a chat question produces, and — when you wire a runner — executes it
against live data under the requesting user's permissions. A schedule is a
definition plus a cron expression; the package decides *when* a job is due, your
cron and your delivery do the rest.

- [Save a report](#save-a-report)
- [The definition](#the-definition)
- [Run it](#run-it)
- [Share, duplicate, and the history](#share-duplicate-and-the-history)
- [Schedules](#schedules)
- [The cron loop, end to end](#the-cron-loop-end-to-end)
- [Settings](#settings)
- [Troubleshooting](#troubleshooting)

---

## Save a report

```php
$created = $api->createReport([
    'name' => 'Weekly sales by region',
    'folder' => 'Sales',
    'description' => 'What the Monday stand-up looks at',
    'visibility' => 'team',                 // private | team | company | public
    'visible_to' => ['roles' => ['manager', 'sales']],
    'definition' => [
        'intent' => 'data_qa',
        'question' => 'Sales by region this week',
        'dataset' => 'orders',              // your semantic layer's dataset name
        'chart' => 'bar',                   // table | bar | column | line | area | pie |
                                            // donut | stacked_bar | funnel | scatter |
                                            // heatmap | kpi | pivot | map | none
        'columns' => ['region_name', 'revenue'],
        'filters' => ['status' => ['operator' => 'in', 'values' => ['paid', 'shipped']]],
    ],
]);
$id = $created['report']['id'];
```

`createReport` is a method, not a `handle()` action (report definitions are
admin-shaped, not chat-shaped). It returns
`['report' => ['id' => …]]`. Calling it with an invalid definition throws
`InvalidArgumentException` with the joined validation errors; pre-check with:

```php
$api->validateReport($definition, 'Weekly sales');   // ['errors' => []]
```

The other methods are `getReport($id, $tenantId)`, `listReports($tenantId,
$folder = null, $user = null)`, `updateReport($id, $changes, $tenantId)`,
`deleteReport($id, $tenantId)` and `duplicateReport($id, $tenantId, $user = null)`.

## The definition

| Field | Required | Notes |
|-------|----------|-------|
| `intent` | yes | what kind of question the report asks (`data_qa` is the usual one) |
| `question` | — | the sentence a runner may use instead of the compiled plan |
| `dataset` | — | a dataset the semantic layer knows; checked against `reports.allowed_datasets` when that list is non-empty |
| `chart` / `chart_type` | — | one of the palette above; default `table` |
| `columns` | — | the fields the report wants back |
| `filters` | — | field ⇒ `['operator' => …, 'values' => […]]`, merged with run-time `params.filters` |

Validation is deliberately light: datasets are *your* vocabulary, so the package
only rejects a dataset name when you declared an allowlist. Everything else
(field names, joins, tenant scope) is enforced when the request is compiled and
executed, which is where real policy lives.

## Run it

```php
$api->handle('report.run', [
    'id' => $id,
    'params' => ['filters' => ['region_id' => ['operator' => '=', 'values' => [3]]]],
    'user' => ['user_id' => '7', 'tenant_id' => 3, 'roles' => ['manager']],
]);
// ['result' => …]
```

With a runner wired, the result is whatever your runner returns:

```php
$api = new AssistantApi($pdo, [
    'report_runner' => function (array $definition, array $params, ?UserContext $ctx) use ($api) {
        // The same guarded pipeline a chat question takes: planning, policy,
        // tenant scope and a chart spec, under the requesting user's context.
        return $api->chat(
            (string) ($definition['question'] ?? ''),
            $ctx ?? ['tenant_id' => 0, 'user_id' => 'scheduler'],
        );
    },
]);
```

Your runner may also be a completely separate engine — it receives the
definition, the run-time params and the user, which is all it needs.

Without one, `run` returns the **compiled request** with `runner_missing: true`:

```php
[
  'report_id' => '…', 'name' => 'Weekly sales by region',
  'intent' => 'data_qa', 'question' => 'Sales by region this week',
  'dataset' => 'orders', 'chart' => 'bar', 'columns' => ['region_name', 'revenue'],
  'filters' => […], 'params' => […], 'company_id' => 3, 'user_id' => '7',
  'runner_missing' => true,
]
```

That is a usable answer for a host that already has a query engine — you get the
tenant, the user and the filters, so running it yourself cannot drift from what
the report meant. A runner that throws comes back as `['error' =>
'runner_failed', 'message' => …]`; the report row stays intact.

Running a report is audited as `report_run` with the report id in the event's
references, which is what `reportHistory()` reads (`{prefix}_audit` via
`AuditService`; empty when audit is not wired).

## Share, duplicate, and the history

```php
$api->shareReport($id, $tenantId);            // ['token' => '…'], idempotent
$api->shareReport($id, $tenantId, true);      // rotate — the only revocation
$api->duplicateReport($id, $tenantId, $user); // ['report' => ['id' => …]]
$api->reportHistory($id, $tenantId, 20);      // ['items' => [...]]
```

The share token never expires on its own; your download/preview route decides
whether it is still acceptable. It is stored inside `visible_to_json` (there is
no dedicated column), so `duplicate` clears it and a copy is always private.

Visibility controls `list` and `get` for other people: `private` is the owner
only, `team` adds `visible_to.roles` / `visible_to.users`, `company` is everyone
in the tenant, `public` is readable even without a user context. It is not a
substitute for the SQL policy — a report still reads only tables the requester
may read.

## Schedules

```php
$api->handle('schedule.create', [
    'name' => 'Monday sales digest',
    'cron' => '0 7 * * 1',                  // 5 fields: min hour day month weekday
    'timezone' => 'Africa/Nairobi',
    'target' => $reportId,                  // a report id…
    // 'target' => ['question' => 'Revenue yesterday', 'channel' => 'email'],  // …or an alert payload
    'type' => 'report',                     // report | alert
    'channel' => 'email',                   // your notifier's channel name
    'recipient' => 'ops@acme.test',
    'format' => 'pdf',
    'threshold' => ['metric' => 'revenue', 'operator' => '<', 'value' => 50000],  // alerts
    'company_id' => 3,
    'user_id' => '7',
]);
// ['schedule' => ['id' => 12]]
```

- `cron` must have exactly five fields; names (`mon`, `jan`) and ranges/lists are
  supported. Invalid expressions throw with the offending field.
- `next_run_at` is computed immediately in `timezone`, which defaults to
  `timezone` from your config (UTC if unset).
- `state` is `active`, `paused` or `disabled`. Pausing keeps the row and stops
  it being due; `resumeSchedule()` re-arms it from now.
- `format` is a suggestion for your runner (`pdf` by default); the package does
  not render the report or send it.

Preview a cron before saving it:

```php
$api->nextRuns('0 7 * * 1', 3, 'Africa/Nairobi');
// ['runs' => ['2026-10-26 07:00:00', '2026-11-02 07:00:00', '2026-11-09 07:00:00']]
```

Definition of the row: `SchedulesService` over `{prefix}_schedules`, with
`type`, `payload_json`, `threshold_json`, `channel`, `recipient`, `format`,
`state`, `next_run_at`, `last_run_at`, `last_status`, `last_error` and
`failure_count`.

## The cron loop, end to end

```php
// 1. cron, every minute — hand every due job to your delivery code
$tick = $api->handle('schedule.tick', ['limit' => 50]);
foreach ($tick['jobs'] as $job) {
    // ['id','company_id','type','report_id','payload','threshold',
    //  'channel','recipient','format','due_at']

    try {
        $result = $job['type'] === 'alert'
            ? $myAlerts->evaluate($job['payload'], $job['threshold'])
            : $api->handle('report.run', ['id' => $job['report_id'], 'params' => $job['payload']]);

        $api->queueNotification($job['company_id'], [
            'channel' => $job['channel'],
            'recipient' => $job['recipient'],
            'subject' => 'Your scheduled report',
            'body' => $myFormatter->body($result),
        ]);

        $api->markScheduleRun($job['id'], true);
    } catch (\Throwable $e) {
        $api->markScheduleRun($job['id'], false, $e->getMessage());
    }
}

// 2. a second cron (or a worker) sends what the outbox holds
$api->flushOutbox(100);
```

`tick()` advances `next_run_at` **before** it hands a job back, so two overlapping
cron runs cannot fire the same schedule twice. If your worker dies mid-job, the
next occurrence is still correct; mark it failed so the failure count reflects
reality. `markScheduleRun()` increments `failure_count`, stores the error, and
disables the schedule after `schedules.max_consecutive_failures` (default 5;
`0` = never). `dueSchedules()` lists what is due without advancing anything — an
admin view rather than a worker.

The package never sends. `{prefix}_notification_outbox` and
[09 — Actions & outbox](09-actions-and-outbox.md) cover delivery and retries.

## Settings

All editable through `config.save` (column `operations_json`), env as break-glass.

| Setting | Default | Effect |
|---------|---------|--------|
| `reports.allowed_datasets` | `[]` (any) | when non-empty, a definition naming another dataset is rejected |
| `schedules.tick_limit` | `200` | how many due jobs one `tick()` returns |
| `schedules.max_consecutive_failures` | `5` | auto-disable threshold (`0` = never) |
| `timezone` | `UTC` | default zone for schedules and relative dates |

Cron delivery is not a feature switch: `features` decides whether your UI
advertises reports/schedules, but an existing schedule keeps running until you
pause it.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `Invalid report definition: report name is required` | no `name` and no `definition.name` | name it |
| `unknown chart type 'bars'` | typo | use a type from the palette |
| `unknown dataset 'invoices'` | `reports.allowed_datasets` is non-empty and does not include it | add it or clear the list |
| `runner_missing: true` | no `report_runner` service | wire one, or execute the compiled request yourself |
| `error: runner_failed` | your runner threw | read `message`; the report row is untouched |
| `Invalid cron expression` | not 5 fields, or an impossible range | preview with `nextRuns()` |
| A schedule never fires | `state` is not `active`, `next_run_at` is in the past and no cron runs, or cron itself is not running | `dueSchedules()`, then the system crontab |
| A schedule disabled itself | `failure_count` reached the cap | fix the cause, `resumeSchedule()` |
| Share link works for anyone | tokens never expire by design | rotate with `shareReport($id, $tenant, true)` |

**Next:** [09 — Actions & outbox](09-actions-and-outbox.md)
