# 10 — Channels & WhatsApp

The assistant core returns an `Answer`; a **channel** decides how that answer
leaves the building. The package ships one adapter — WhatsApp — and one small
interface, `Contracts\ChannelInterface`, for everything else (web widget, email,
Slack, SMS, a public API).

The package never holds a WhatsApp/Slack/mail credential and never calls a
provider SDK. Your app hands over a callable, and the adapter does the parts
that are specific to the surface: WhatsApp's 4096-character limit, markup it
cannot render, webhook re-delivery, and flattening Meta's nested payload.

- [Two ways to answer on WhatsApp](#two-ways-to-answer-on-whatsapp)
- [Wiring the adapter](#wiring-the-adapter)
- [Webhook in, replies out](#webhook-in-replies-out)
- [What the adapter does to a reply](#what-the-adapter-does-to-a-reply)
- [Other channels](#other-channels)
- [The legacy host-table pipeline](#the-legacy-host-table-pipeline)
- [Settings](#settings)
- [Troubleshooting](#troubleshooting)

---

## Two ways to answer on WhatsApp

| | Adapter path (`AssistantApi`) | Legacy path (`AiAssistantService`) |
|---|---|---|
| Entry point | `whatsapp.inbound`, `whatsapp.answer` | `onInboundMessages($results)` |
| Reads | the raw webhook body you pass in | your `{prefix}_messages` / `_conversations` tables |
| Sends via | `services['whatsapp_sender']` or `services['channel']` | `setTransport()`, host `_queue`, or a detected host SDK class |
| Stores | package conversations/audit (when wired) | host tables + `{prefix}_sessions` + `{prefix}_training` |
| Best for | new integrations, any framework | an app that already had the older WhatsApp pipeline |

Both are supported. Pick one per deployment; they do not need to run together.

---

## Wiring the adapter

```php
use ExcelleInsights\AiAssistant\Http\AssistantApi;

$api = new AssistantApi(
    $pdo,
    [
        // Only your app knows the WhatsApp API and its token.
        'whatsapp_sender' => function (string $to, string $body, array $meta): array {
            $response = $this->metaCloud->post('messages', [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'text',
                'text' => ['body' => $body],
            ]);

            return $response;   // Meta's JSON is understood as-is
        },
    ],
    [
        // Optional per-instance formatting overrides (beat the settings table).
        'channel' => [
            'max_message_chars'   => 4096,
            'max_rows_in_message' => 15,
            'show_sources'        => true,
        ],
    ],
);
```

You can inject a full `ChannelInterface` instead of a callable:

```php
$api = new AssistantApi($pdo, ['channel' => new MyWhatsAppChannel()]);
```

`services['channel']` wins over `services['whatsapp_sender']` when both are
present. With neither, `whatsapp.answer` returns
`['ok' => false, 'code' => 'unavailable', 'error' => …]` — it does not throw.

---

## Webhook in, replies out

```php
// Your controller. GET verification (hub.challenge) stays yours.
$result = $api->handle('whatsapp.answer', [
    'payload'  => $webhookBody,                 // Meta's body, verbatim
    'defaults' => [
        'tenant_id'  => 3,
        'company_id' => 3,
        'roles'      => ['customer'],
    ],
]);

// [
//   'replies' => [[
//       'to'     => '2547…',
//       'result' => ['ok' => true, 'parts' => 2, 'external_id' => 'wamid.…'],
//       'answer' => ['text' => '…', 'rows' => […], 'chart' => […], …],
//   ]],
// ]
```

Every inbound message is answered in the same tenant, but three context fields
are always taken from the message, not from `defaults`:

| Field | Value |
|---|---|
| `user_id` | the sender's number (`from`), or `anonymous` |
| `thread_id` | the sender's number |
| `channel` | `whatsapp` |

Messages with no usable text are skipped: delivery receipts, echoes, and
media-only messages (an image with no caption). If you want photos or voice
notes answered, transcribe them with your host extractor first (see
[05 — Knowledge & documents](05-knowledge-and-documents.md)) and call
`chat()` with the text.

If you only want the flattening — no answering — use `whatsapp.inbound`:

```php
$flat = $api->handle('whatsapp.inbound', ['payload' => $webhookBody]);
// ['messages' => [['from', 'to', 'wamid', 'type', 'text', 'name',
//                  'timestamp', 'conversation_id', 'reply_to', 'raw'], …]]
```

It accepts Meta's nested `entry[].changes[].value`, an already-flat
`['from' => …, 'text' => …]`, and `['messages' => [[…]]]`. Message types
`text`, `button`, `interactive`, `image`/`video`/`document` (caption) and
`location` are read; anything else falls back to `body` when present.

Acknowledge the webhook with `200` quickly, then answer. Meta re-delivers when
you are slow, which is exactly why the adapter deduplicates (below).

---

## What the adapter does to a reply

1. **Plain text first.** `Answer::plain($showSources, $maxRows)` renders the
   text, then up to `max_rows_in_message` data rows, then `Sources: …` when
   citations exist and `show_sources` is on.
2. **WhatsApp markup.** Headings and `**bold**` become `*bold*`, `__italic__`
   becomes `_italic_`, `[label](url)` becomes `label: url`, code fences are
   removed, trailing whitespace and extra blank lines are collapsed.
3. **Confirmation prompt.** When the answer carries a `pending_action`, the
   message ends with `Reply *confirm <action>* to go ahead.` (see
   [09 — Actions & outbox](09-actions-and-outbox.md)).
4. **Chunking.** Anything over `max_message_chars` (default 4096) is split at
   paragraph breaks first, then line breaks, and only as a last resort
   mid-line. Every part receives `meta.part` / `meta.parts`, and the
   idempotency key is suffixed `#1`, `#2`, … so a retry cannot duplicate one
   part.
5. **Deduplication.** The key is `idempotency_key`, falling back to
   `reply_to_message_id` (which `outboundFor()` fills from the inbound
   `wamid`). A repeat returns the cached result and sends nothing.
6. **Transport result normalisation.** The sender may return:

   | Shape | Interpreted as |
   |---|---|
   | `true` / `false` | success / failure |
   | `['ok' => true]`, `['success' => true]` | explicit success |
   | `['error' => '…']`, `error_message`, `message` | failure with that message |
   | `['external_id' => …]` (or `message_id`, `wamid`, `id`) | success + id |
   | raw Meta response (`messages[0].id`, `data.error.message`) | success when an id is present |

   A thrown exception becomes `['ok' => false, 'error' => <message>]`.

The in-memory dedupe window lives on the `AssistantApi` instance. A worker or
FPM process that handles each webhook with a fresh instance will not remember
the previous one — give the transport an idempotency key of your own, or skip
the adapter's dedupe by not setting `idempotency_key`, and enforce uniqueness
in your own store.

---

## Other channels

Implement `ChannelInterface` and hand it to `AssistantApi` (or use it from your
own controller):

```php
use ExcelleInsights\AiAssistant\Contracts\ChannelInterface;
use ExcelleInsights\AiAssistant\Dto\Answer;
use ExcelleInsights\AiAssistant\Dto\OutboundMessage;

final class WebWidgetChannel implements ChannelInterface
{
    public function __construct(private EventBus $bus) {}

    public function id(): string
    {
        return 'web';
    }

    public function send(Answer $answer, OutboundMessage $message): array
    {
        // The widget wants structured data, not a text blob.
        $this->bus->publish((string) $message->threadId, $answer->toArray());

        return ['ok' => true];
    }
}
```

`Answer::toArray()` keeps `rows`, `chart`, `citations`, `suggestions` and
`pending_action` intact, so a widget can render a table, a chart, or a confirm
button without re-parsing text. `OutboundMessage` carries `threadId`,
`recipient`, `replyToMessageId`, `idempotencyKey` and `meta`.

For **proactive** messages (a schedule or a threshold alert rather than a reply),
queue them in the outbox instead — see
[09 — Actions & outbox](09-actions-and-outbox.md).

---

## The legacy host-table pipeline

`Services\AiAssistantService` is kept for apps that already have WhatsApp
tables, and takes no dependency on them until you call it:

```php
$ai = new AiAssistantService($pdo);

$ai->setTransport(fn (string $to, string $body, array $meta) =>
    ['ok' => TwilioApi::send($to, $body)]
);
$ai->setBookingHandler($myBookingHandler);   // optional, BookingHandlerInterface

// Call after your webhook processor has stored the inbound messages.
$ai->onInboundMessages($results);
```

It reads and writes these host tables (prefix from
`channels.whatsapp.table_prefix`, default `whatsapp`):

| Table | Used for |
|---|---|
| `{prefix}_messages` | inbound text, `wa_message_id`, `ai_status` (dedupe + outcome) |
| `{prefix}_conversations` | contact, `session_expires_at`, `company_id` |
| `{prefix}_queue` | template messages sent outside the session window |

Per inbound message it will:

1. Stop when `channels.whatsapp.auto_reply` is off → status `disabled`.
2. Skip a `wamid` that already has an `ai_status` (Meta retries).
3. Ask `SystemContextProviderInterface::canAutoReply()` before answering.
4. Send the reply inside the session, or — when expired and
   `fallback_template_id` is set — queue that template instead.
5. Record `sent` / `send_failed` and the error on the message row.
6. Append the exchange to `{prefix}_sessions`, trimmed to
   `limits.session_turns` turns.
7. Save the Q&A to `{prefix}_training` unless `knowledge.self_training` is off.
8. Hand any booking intent to your `BookingHandlerInterface` when
   `channels.whatsapp.booking_hook` is on.

With no transport, it keeps the historical behaviour: use the host's
`Packages\Integrations\Whatsapp\WhatsappApi` class when it exists, otherwise
queue the reply for the host's own sender.

---

## Settings

All of these live in `{prefix}_config` (column shown) and can be overridden by
environment variables when you need a break-glass change. See
[03 — Configuration](03-configuration.md#settings-in-the-database).

| Setting | Column | Default | Used by |
|---|---|---|---|
| `channels.whatsapp.enabled` | `channels_json` | `true` | your UI/gate; the adapter is built only when you inject a sender |
| `channels.whatsapp.auto_reply` | `channels_json` | `true` | legacy pipeline |
| `channels.whatsapp.table_prefix` | `channels_json` | `whatsapp` | legacy pipeline |
| `channels.whatsapp.fallback_template_id` | `channels_json` | `0` | legacy pipeline (outside session window) |
| `channels.whatsapp.booking_hook` | `channels_json` | `true` | legacy pipeline |
| `channels.whatsapp.session_window_hours` | `channels_json` | `24` | your webhook logic; the legacy pipeline uses `session_expires_at` |
| `channels.whatsapp.max_message_chars` | `channels_json` | `4096` | adapter chunking |
| `channels.whatsapp.max_rows_in_message` | `channels_json` | `15` | adapter row rendering |
| `channels.whatsapp.show_sources` | `channels_json` | `true` | adapter `Sources:` line |
| `limits.session_turns` | `limits_json` | `10` | legacy `_sessions` window |
| `channels.web.enabled` | `channels_json` | `true` | your web surface |
| `features` | `features_json` | includes `documents` | gate for document Q&A |

`options['channel']` (`max_message_chars`, `max_rows_in_message`,
`show_sources`) still wins over both the table and the environment for that
one `AssistantApi` instance.

Pre-rename environment aliases remain honoured: `AI_WHATSAPP_AUTO_REPLY`,
`AI_WHATSAPP_FALLBACK_TEMPLATE`, `AI_WHATSAPP_BOOKING_HOOK`,
`AI_WHATSAPP_SESSION_TURNS`, `AI_WHATSAPP_TABLE_PREFIX`, `AI_WHATSAPP_COMPANY_ID`.

---

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `whatsapp.answer` returns `code: unavailable` | No `services['whatsapp_sender']` and no `services['channel']`. Nothing was sent. |
| Reply is sent but the customer sees markup debris | You bypassed `WhatsAppChannel::format()`; send through the adapter or call `format()` yourself. |
| Duplicate replies after a slow webhook | Meta retried and the sender did not carry `wamid` as an idempotency key. Use `WhatsAppChannel::outboundFor($inbound)` or enforce uniqueness yourself. |
| Long answers arrive truncated | The transport enforces a smaller limit than `max_message_chars`; lower that setting so the adapter splits earlier. |
| Images/voice notes are ignored | `whatsapp.answer` skips messages with no text. Transcribe/caption them first. |
| Legacy auto-reply does nothing | Check `channels.whatsapp.auto_reply`, `canAutoReply()`, and whether the `wa_message_id` already has an `ai_status`. |
| Legacy pipeline writes `disabled` on every message | Auto-reply is off in the settings table or an env override. |
| `SQLSTATE … {prefix}_messages` does not exist | The legacy pipeline needs your host tables; set `channels.whatsapp.table_prefix` to the prefix they actually use. |
| `SQLSTATE … {prefix}_queue` does not exist outside the session | Either create the host queue table or clear `fallback_template_id` so in-session text is attempted. |
