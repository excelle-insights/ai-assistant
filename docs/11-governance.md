# 11 — Governance: audit, feedback, usage & settings

Four small subsystems answer the questions an operator asks after launch:
*what happened*, *was it useful*, *how much did it cost*, and *what does this
tenant want differently*. They are independent — wire the ones you need.

- [Audit trail](#audit-trail)
- [Feedback](#feedback)
- [Usage and quotas](#usage-and-quotas)
- [Per-user settings](#per-user-settings)
- [Deployment settings](#deployment-settings)
- [Troubleshooting](#troubleshooting)

---

## Audit trail

Every chat turn, action, export and report run can be recorded in
`{prefix}_audit`. Writes never throw: a logging failure must not cost a user
their answer — it is routed to the `on_error` callback instead.

### What gets written

| Event | Emitted by | Key detail |
|---|---|---|
| `assistant.answered` | every `ask()` | intent, grounded, rows, citations, question (500 chars), latency |
| `assistant.query_failed` | plan compiled but query failed | error, dataset |
| `assistant.llm_failed` | model call failed | error |
| `ai.action_proposed` / `ai.action_executed` / `ai.action_failed` / `ai.action_refused` / `ai.action_cancelled` | actions | params, result, error/refusal |
| `ai.export_refused` / `ai.export_awaiting_approval` / `ai.export_queued` / `ai.export_ready` / `ai.export_failed` / `ai.export_approved` | exports | rows, columns, format |
| `report_run` | report execution | report id, user, channel |
| anything you call `recordAudit()` with | your app | free-form `detail` |

The row carries: `company_id`, `user_id`, `channel`, `event`, `intent`,
`correlation_id`, `conversation_id`, `model`, `tokens`, `cost`, `latency_ms`,
`query_hash`, `sql_text`, `question`, `record_refs`, `status`, `error`, `ip`,
`detail_json`, `created_at`.

```php
$api->handle('audit.query', [
    'filter' => [
        'company_id' => 3,
        'from' => '2026-10-01',
        'to' => '2026-10-31',
        'errors_only' => true,
        'events' => ['assistant.answered', 'ai.export_failed'],
    ],
    'limit' => 100,
    'offset' => 0,
]);
```

Filter keys: `company_id`/`tenant_id`, `user_id`, `event`/`action`, `intent`,
`status`, `correlation_id`, `conversation_id`, `channel`, `query_hash`, the IN
lists `events` / `statuses` / `intents`, `from` / `to`, `has_sql`,
`errors_only`.

```php
$summary = $api->auditSummary(['company_id' => 3, 'from' => '2026-10-01']);
// total, avg_latency_ms, total_tokens, total_cost, errors, error_rate,
// unanswered, top_users[5]

$csv = $api->auditCsv(['company_id' => 3], 5000);
// ['csv' => '…', 'filename' => 'ai-audit.csv'] — empty string when nothing matches

$api->recordAudit(
    ['company_id' => 3, 'user_id' => '7', 'channel' => 'web'],
    'host.invoice_clicked',
    ['invoice_id' => 991]
);  // ['trace_id' => '<uuid>']
```

### What is deliberately not stored

- **SQL** is dropped when `audit.record_sql` is off; the `query_hash` is kept,
  so repeated questions are still groupable.
- **PII**: when a `SchemaPolicy` is wired (as `AssistantApi` does), emails and
  phone-like runs in `question` and `sql_text` are masked. With no policy the
  text is stored as written.
- **Secrets** are never written; `record_refs` is for ids, not values.

### Storage and sinks

`audit.store` selects the destination:

| Value | Behaviour |
|---|---|
| `table` (default) | write to `{prefix}_audit` |
| `sink` | host sink only — the injected `sink()` or a class named in `audit.store`; no table rows |
| `both` | host sink when one resolves, plus the table best-effort |
| a class name | treated like `both`: that sink plus the table |

An injected sink — `$api`'s `services['audit']->sink($mySink)` — always wins.
Point a sink at append-only storage when you need tamper evidence: the package
table is append-only by convention, not cryptographically chained.
`auditVerify()` only answers "is the table present and readable".

### Retention

```php
$removed = $api->audit()->retention();      // limits.retention_days (365)
$removed = $api->audit()->retention(90);    // explicit
```

This is the only sanctioned delete. Schedule it; do not prune the table by hand.

---

## Feedback

`{prefix}_feedback` stores thumbs/ratings, feeds a review queue, and can turn a
corrected answer into knowledge.

```php
$api->handle('feedback.submit', [
    'target' => [
        'message_id'      => 'msg_123',
        'conversation_id' => 'conv_9',
        'question'        => 'What was Q3 revenue?',
        'answer'          => 'KES 4.2M',
    ],
    'rating'  => 'down',            // up/down, thumbs_*, 1–5, + / -
    'reason'  => 'wrong_number',
    'comment' => 'That is Q2, not Q3.',
    'user'    => ['user_id' => '7', 'tenant_id' => 3],
]);
```

If `message_id` is present and `question`/`answer` are not, the service fills
them from `{prefix}_messages` when that table exists. Rows land as `pending`.

The admin queue is on the service (not the `handle()` map):

```php
$api->feedbackList(3, 'pending', 50);              // ['items' => [...]]
$api->reviewFeedback(12, 'accepted', 'admin@acme');  // accepted|rejected|converted
$api->feedbackSummary(3, '2026-10-01', '2026-10-31');
// total, helpful, negative, helpful_pct, by_reason, unanswered

$api->feedbackToKnowledge(12);
// ['result' => ['ok' => true, 'knowledge_id' => 55]]
// or ['ok' => false, 'reason' => 'incomplete_qa' | 'knowledge_table_missing' | …]
```

`convertToKnowledge()` hashes the normalised question + answer, so running it
twice returns `['ok' => true, 'skipped' => true, 'knowledge_id' => …]`. The
promoted entry uses `source = 'feedback'`, `status = 'active'` and is marked
`converted` in the queue.

A rating counts as helpful when it is `up`, `4` or `5`; everything else is
negative and its `reason` is rolled up in `by_reason`.

---

## Usage and quotas

`{prefix}_usage_daily` is one row per `(company_id, stat_date, user_id)` with
counters: `chats`, `tokens_in`, `tokens_out`, `cost`, `documents`, `exports`,
`actions`, `errors`, `unanswered`.

```php
$api->handle('usage.today', ['user' => ['tenant_id' => 3]]);
$api->usageRange(3, '2026-10-01', '2026-10-31', 7);
$api->usageByUser(3, '2026-10-01', '2026-10-31', 50);
$api->topQuestions(3, '2026-10-01', '2026-10-31', 20);  // from the audit table
```

`record()` accepts the canonical counters and these aliases: `chat`, `document`,
`export`, `action`, `error`, `unanswered`, `token_in`, `token_out`,
`prompt_tokens`, `completion_tokens`, `input_tokens`, `output_tokens`.

### Quotas

```php
$check = $api->handle('limit.check', [
    'dimension' => 'chats',
    'key'       => 'user:7',
    'window'    => 'hour',          // minute | hour | day | month
    'max'       => null,            // optional override
]);
// ['limit' => ['allowed' => true, 'used' => 12, 'limit' => 60, 'reset_at' => '…']]

$api->consumeLimit('chats', 'user:7', 'hour');
```

The message call itself is **not** gated automatically — call `limit.check`
before it and `consumeLimit` after, so a refused request costs no model call.
`limit <= 0` means unlimited. The limit is resolved per dimension first
(`rate_limit.chats_per_hour`, `rate_limit.chats_per_day`, …), then from the
generic `rate_limit.per_hour` / `rate_limit.per_day`.

Minute/hour counters use `{prefix}_rate_limits`. When that table is missing the
service falls back to an in-memory counter for the life of the request — the
caller never crashes, but the window is not shared across processes. Day/month
totals for a company/user are read from `usage_daily`, which is authoritative.

---

## Per-user settings

`{prefix}_settings` is a key/value store for *preferences*, not deployment
configuration: a user's language, a role's default dataset, a tenant's display
currency. Rows are JSON and **plaintext** — never put API keys or tokens here
(`SettingsService::isSecretKey()` flags likely credentials so your UI can refuse
them).

| Column | Meaning |
|---|---|
| `company_id` | tenant; `0` only for global rows |
| `scope` | `global`, `company`/`tenant`/`role`/`project`, or `user` |
| `scope_key` | the role/project id, or the user id for `user` scope |
| `key_name` | preference name |
| `value_json` | `{"v": <value>}` wrapper so nulls/arrays round-trip |
| `updated_by` | who saved it |

Precedence: **global < company/role/project < user**, and within a rank the
later `updated_at` wins. A row only applies when its company matches (global
rows must have `company_id = 0`) and, for `user` scope, when the scope key
matches the requested user.

```php
$api->handle('settings.get', ['key' => 'locale', 'user' => ['user_id' => '7', 'tenant_id' => 3]]);
$api->handle('settings.put', [
    'key'   => 'locale',
    'value' => 'sw',
    'scope' => ['scope' => 'user', 'scope_key' => '7', 'company_id' => 3],
    'updated_by' => '7',
]);

$api->forgetSetting('locale', ['scope' => 'user', 'scope_key' => '7', 'company_id' => 3]);
$api->settingsList(['scope' => 'company', 'company_id' => 3]);   // exact scope
$merged = $api->effectiveConfig(3, 7);  // ['config' => global+company+user flattened]
```

When `AssistantApi` builds its `AssistantService`, it passes that flattened map
to the `system_context` hook, so the assistant sees the user's preferences
without any extra wiring.

**Do not confuse the two tables:**

| | `{prefix}_config` | `{prefix}_settings` |
|---|---|---|
| Purpose | deployment/tenant behaviour (policy, features, limits) | per-user/role preferences |
| Shape | one row per company, JSON per section | one row per key+scope |
| Edited by | admin settings screen (`config.*`) | your app / `settings.*` |
| Reaches the assistant | via `Config` (all layers) | via `system_context` |

See [03 — Configuration](03-configuration.md#settings-in-the-database) for the
`config` table.

---

## Deployment settings

| Setting | Column | Default | Effect |
|---|---|---|---|
| `audit.enabled` | `operations_json` | `true` | stop writing audit rows |
| `audit.store` | `operations_json` | `table` | `table`, `sink` or `both` |
| `audit.record_sql` | `operations_json` | `true` | store generated SQL or only its hash |
| `limits.retention_days` | `limits_json` | `365` | default for `audit()->retention()` |
| `limits.audit_export_rows` | `limits_json` | `10000` | ceiling for `auditCsv()` |
| `rate_limit.per_hour` | `limits_json` | `60` | generic hourly quota; `0` = unlimited |
| `rate_limit.per_day` | `limits_json` | `0` | generic daily quota |
| `limits.session_turns` | `limits_json` | `10` | turns replayed in a conversation |
| `limits.memory_turns` | `limits_json` | `10` | turns replayed into a prompt |
| `limits.store_rows` | `limits_json` | `50` | answer rows kept for transcript re-render; `0` = none |
| `limits.summarize_after` | `limits_json` | `24` | summarise older turns; `0` = never |

`masked_columns` lives in `schema_policy.masked_columns`; see
[03 — Configuration](03-configuration.md#schema_policy--what-it-may-read-schema_policy_json) and
[06 — Data & charts](06-data-and-charts.md).

---

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `audit.query` returns `[]` with a database | `{prefix}_audit` missing (run migrations) or `audit.store = 'sink'` with no sink configured. |
| Nothing is written at all | `audit.enabled` is off in `{prefix}_config` or an env override. |
| Questions appear but `sql_text` is null | `audit.record_sql` is off; `query_hash` is still populated. |
| `auditQuery` shows a masked question | The policy's `masked_columns` matched a named entity; by design. |
| Audit CSV is empty | An empty result set returns `''`, not a header row. Check the filter window. |
| Feedback queue is empty after ratings | Ratings are stored with the tenant from `target.company_id` or the user context — check they agree. |
| `feedbackToKnowledge` says `incomplete_qa` | The row has no question/answer; submit them explicitly or rate a stored message. |
| `limit.check` always allows | The dimension/window resolved to `0`; set `rate_limit.per_hour` or the per-dimension key. |
| Hourly quota is not shared between workers | `{prefix}_rate_limits` is missing, so counters are per-request. Run migrations. |
| Per-user preference is ignored | The row's `scope_key` does not match the user id, or its `company_id` differs from the request. |
| Settings saved but the assistant does not see them | `settings.*` reach the prompt through `system_context`; changing a *behaviour* setting belongs in `config.save`, not `settings.put`. |
