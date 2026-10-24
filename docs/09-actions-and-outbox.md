# 09 — Actions & outbox

Two things the package is deliberately careful about:

- **Writes into your data** are proposed by the assistant but executed by *you*,
  only after a user confirms. There is no code path where the package inserts,
  updates or deletes one of your rows.
- **Outbound messages** (a scheduled report, a threshold alert, a "send me this"
  request) are queued by the package but delivered by *you*, because only your
  app has the mail/WhatsApp/Slack credentials.

- [The three-step action flow](#the-three-step-action-flow)
- [Implementing the handler](#implementing-the-handler)
- [The action catalogue](#the-action-catalogue)
- [Confirm, replay and idempotency](#confirm-replay-and-idempotency)
- [The outbox](#the-outbox)
- [Retries](#retries)
- [Settings](#settings)
- [Troubleshooting](#troubleshooting)

---

## The three-step action flow

```php
// 1. catalogue — what can this app do?
$api->handle('action.catalog', ['company_id' => 3]);

// 2. propose — a read-only preview + a confirm token. Nothing is written.
$proposal = $api->handle('action.preview', [
    'action' => 'add_lead',
    'params' => ['name' => 'Jane Mwangi', 'phone' => '+254700000000'],
    'user'   => ['user_id' => '7', 'tenant_id' => 3, 'roles' => ['sales']],
    'meta'   => ['conversation_id' => '…', 'channel' => 'web'],
]);
// ['id', 'status' => 'proposed', 'proposal' => [...], 'confirm_token', 'expires_at']

// 3. the user clicks Confirm — the host performs the change
$done = $api->handle('action.confirm', [
    'token' => $proposal['confirm_token'],
    'user'  => ['user_id' => '7', 'tenant_id' => 3, 'roles' => ['sales']],
    'idempotency_key' => 'lead-jane-2026-10-25',
]);
// ['ok' => true, 'status' => 'executed', 'id', 'action', 'result' => [...]]
```

Between 2 and 3 the package has written one row to `{prefix}_action_runs` (status
`proposed`) and nothing else. `action.cancel` marks a proposal abandoned;
`action.history` lists them; `expireStaleActions()` is a cron helper that marks
timed-out proposals `expired` (a token that timed out cannot execute anyway —
`confirm()` checks on every call).

The proposal is refused before any token exists when:

| Check | Message | Status |
|-------|---------|--------|
| no action named | `No action was named.` | `refused` |
| no handler wired | `This app has not registered any actions.` | `refused` |
| action disabled in the catalogue | `"transfer_funds" is switched off in this app.` | `refused` |
| `canAct()` says no | `You are not allowed to "transfer_funds".` | `refused` |
| handler says invalid | the joined proposal errors | `invalid` |
| handler threw | the exception message | `failed` |

A refusal never mints a token, so a UI cannot build a confirm button for it.
Statuses on `{prefix}_action_runs` are `proposed`, `confirmed`, `executed`,
`failed`, `refused`, `expired`, `cancelled`.

## Implementing the handler

```php
use ExcelleInsights\AiAssistant\Contracts\ActionHandlerInterface;
use ExcelleInsights\AiAssistant\Dto\{ActionProposal, ActionResult, UserContext};

final class AppActions implements ActionHandlerInterface
{
    public function actions(): array
    {
        return ['add_lead', 'add_note', 'move_deal'];
    }

    public function preview(UserContext $user, string $action, array $params): ActionProposal
    {
        return match ($action) {
            'add_lead' => new ActionProposal(
                action: $action,
                params: $params,
                changes: [
                    ['field' => 'name', 'before' => null, 'after' => $params['name'] ?? ''],
                    ['field' => 'phone', 'before' => null, 'after' => $params['phone'] ?? ''],
                ],
                summary: 'Create a lead for ' . ($params['name'] ?? '(no name)'),
                permission: 'leads.create',
            ),
            default => ActionProposal::invalid($action, ['that action is not implemented']),
        };
    }

    public function execute(UserContext $user, string $action, array $params, string $idempotencyKey): ActionResult
    {
        // You own the transaction, validation against *your* rules, and the row.
        $id = $this->leads->create($params + ['owner_id' => $user->userId]);

        return ActionResult::ok(['lead_id' => $id], 'Lead created');
    }
}

$api = new AssistantApi($pdo, ['action_handler' => new AppActions()]);
```

`ActionProposal` fields: `action`, `params`, `changes` (`field`/`before`/`after`
triples for a diff UI), `summary`, `requiresConfirmation` (default `true`),
`valid`, `errors`, `permission`, `affectedRows`. Use
`ActionProposal::invalid($action, $errors)` for "I would not accept this".

`ActionResult` carries `ok`, `data`, `message` (check the class for its exact
constructor). Whatever `execute()` returns is stored in `result_json`, so a
replayed confirmation can hand back the same result.

Wiring `PermissionResolverInterface` additionally lets `canAct($user, $action)`
gate proposals, and it is re-checked at confirmation time — a role removed
between propose and confirm cannot execute.

## The action catalogue

`{prefix}_actions` is your opt-in list, one row per action (rows with
`company_id = 0` are global; a company row with the same `name` wins). The
package never inserts here — use SQL or your own admin screen:

```sql
INSERT INTO ai_assistant_actions
    (company_id, name, label, description, permission, requires_confirmation, enabled, created_at)
VALUES
    (0, 'add_lead', 'Add lead', 'Creates a lead from the conversation', 'leads.create', 1, 1, NOW()),
    (0, 'transfer_funds', 'Transfer funds', 'Not enabled', 'finance.transfer', 1, 0, NOW());
```

An action **absent** from the catalogue is still previewable (the domain pack can
name it) — absence is not a denial. A row with `enabled = 0` *is*: it returns
"switched off in this app". That makes the table the place to kill a risky action
without removing code.

`action.catalog` returns the merged view keyed by name: `label`, `description`,
`permission`, `requires_confirmation`, `enabled`, `definition`.

## Confirm, replay and idempotency

`confirm()` is safe to call twice, in two different senses:

- **The same token again** does not execute twice. The row's status is no longer
  `proposed`, so it returns `already_handled: true` with the stored `result` (and
  `ok` = whether it had executed).
- **A different token with the same idempotency key** (a retried request, a
  double-clicked button that minted two proposals) returns the first run's
  result with `replayed: true` instead of creating a second lead.

The token itself is checked for: existence, `proposed` status, expiry
(`actions.confirmation_ttl_minutes`, default 30), same company, same user, and
`canAct()` again. The handler's `execute()` receives the idempotency key so it
can enforce its own uniqueness (a unique index on your side) if you want a second
line of defence.

`meta.conversation_id` and the user's channel are recorded on the proposal, so
"which chat produced this lead" is answerable from the history.

## The outbox

```php
$api->handle('outbox.queue', [
    'company_id' => 3,
    'message' => [
        'channel' => 'email',
        'recipient' => 'ops@acme.test',
        'subject' => 'Weekly sales digest',
        'body' => '…plain text or HTML your sender understands…',
        'template' => 'weekly_digest',          // optional, for your sender
        'payload' => ['report_id' => '…'],      // optional, for templating
        'attachments' => ['/path/to/file.pdf'], // optional; your sender's problem
        'scheduled_at' => '2026-10-26 07:00:00',// optional; default now
    ],
    'user_id' => '7',
]);
// ['ok' => true, 'id' => '…', 'status' => 'pending', 'channel' => 'email', 'recipient' => '…']
```

A message with no recipient is refused (`status: rejected`, `ok: false`) — it is
not queued to fail later. `queueNotifications()` takes a list and returns
`queued` plus a per-message `results` array.

Flush with a sender registered:

```php
$api = new AssistantApi($pdo, [
    'notifier' => function (array $message): array {
        // $message: id, company_id, channel, recipient, subject, body,
        //           template, payload, attachments
        $ok = $myMailer->send($message['recipient'], $message['subject'], $message['body']);
        return ['ok' => $ok, 'error' => $ok ? null : 'mailer refused'];
    },
]);

$api->handle('outbox.flush', ['limit' => 100]);
// ['sent' => 12, 'failed' => 1, 'pending' => 0, 'results' => [...]]
```

`services['notifier']` may be a callable receiving the payload array, or a
`NotificationInterface` (`send(int $tenantId, string $channel, string $recipient,
string $subject, string $body, array $attachments = []): array`). No sender = no
delivery: rows stay `pending` and `flush` reports `no sender registered`, which
is honest and keeps the queue inspectable.

Other methods: `outboxCounts($tenantId)` (`pending`, `sent`, `failed`,
`cancelled`, `total`), `outboxHistory($tenantId, $limit, $filters)`,
`cancelNotification($id)` (only a `pending` row can be cancelled).

The package **stores and schedules**; it never talks to a mail server, an SMS
gateway or WhatsApp directly. WhatsApp replies are a separate path — see
[10 — Channels & WhatsApp](10-channels-and-whatsapp.md).

## Retries

A failed delivery becomes `pending` again with `next_attempt_at = now +
backoff_seconds × 2^(attempts-1)`, capped at two hours. After
`outbox.max_attempts` it becomes `failed` and stops. A message that never gets
sent stays visible in `history()`, which is the point: a report nobody received
should be a support ticket, not a silent gap.

`deliver($row)` is public, so a worker that pulls rows itself (a real queue
rather than a cron sweep) can reuse the same bookkeeping.

## Settings

| Setting | Default | Effect |
|---------|---------|--------|
| `actions.confirmation_ttl_minutes` | `30` | how long a confirm token lives |
| `outbox.batch` | `50` | suggested batch size for a cron call |
| `outbox.max_attempts` | `5` | attempts before a message is `failed` |
| `outbox.backoff_seconds` | `60` | first retry wait; doubles, capped at 2 h |
| `features` | includes `actions` | whether the capability is advertised |

All live in `{prefix}_config` (`operations_json`), editable with `config.save`.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `This app has not registered any actions.` | no `action_handler` service | wire `ActionHandlerInterface` |
| `"X" is switched off in this app.` | catalogue row `enabled = 0` | enable it or remove the row |
| `That confirmation is not recognised or has already been used.` | token wrong, or row not found | re-propose; confirm always re-checks |
| `That confirmation expired.` | past `actions.confirmation_ttl_minutes` | re-propose |
| `belongs to another user/account` | token used by someone else | propose and confirm as the same user |
| Proposal has no `confirm_token` | status was `refused`, `invalid` or `failed` | show the `error`, do not offer a button |
| `flush` → `no sender registered` | `services['notifier']` missing | wire it, or keep rows for a worker that delivers directly |
| Message stuck `pending` | `next_attempt_at` in the future | it is backing off; inspect `error` |
| Message `failed` | attempts exhausted | fix the recipient/provider, queue again |

**Next:** [10 — Channels & WhatsApp](10-channels-and-whatsapp.md)
