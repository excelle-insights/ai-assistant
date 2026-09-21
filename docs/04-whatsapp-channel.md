# 04 — WhatsApp channel (step by step)

## 1. End-to-end flow

```
Meta → (meta-router) → POST /api/integrations/whatsapp/webhook
  → WhatsappService::processWebhookPayload() stores inbound
    (whatsapp_messages direction=inbound, whatsapp_conversations)
  → AiAssistantService::onInboundMessages($results)      ← Step 1 hook (doc 02)
    → idempotency check (already auto-replied wa_message_id? skip)
    → canAutoReply()? knowledge search + schema/reference context
    → OpenAI chat → reply text
    → 24h session open? send now : queue template
    → mark ai_status sent|send_failed|blocked (+ store session + self-learn)
```

## 2. Configure auto-reply (host `.env`)

```ini
AI_ASSISTANT_AUTO_REPLY=true    # false/0 disables all auto-replies
AI_WHATSAPP_FALLBACK_TEMPLATE=0 # Approved template id for closed 24h window;
                                # 0 = attempt direct send anyway
```

## 3. The 24h session rule (Meta constraint)

- `session_expires_at` in the future → `WhatsappApi::sendTextMessage()` + an
  outbound row in `whatsapp_messages`, `last_message_at` updated.
- Expired → the package enqueues into `{waPrefix}_queue` with
  `AI_WHATSAPP_FALLBACK_TEMPLATE` (template message, placeholders carry the AI
  reply). Your existing `processQueueItem()` worker sends it — nothing new to build.

## 4. Operate it

| Task | How |
|------|-----|
| Inbox + reply manually | WhatsApp page → Inbox tab (`GET conversations/{id}/messages`, `POST …/send`) |
| See what the AI did | `whatsapp_messages.ai_status` / `ai_error` per message (`sent`, `send_failed`, `blocked`, `disabled`, `failed`) |
| Bulk/template sends | Queue tab (`GET queue-view`, `POST queue`, `POST queue/{id}/process`) |
| Templates | Templates tab (sync/retry/delete via Meta) |
| Health | `GET /api/integrations/whatsapp/ai-diagnostics` |

## 5. Switch WhatsApp providers later

Only the host adapter changes (your receiver builds `$results`, your sender
implements send). The package flow above is identical for Meta-direct, Twilio,
or any gateway — see the port design in `../BLACKBOX_DECOUPLING_PROPOSAL.md`.
