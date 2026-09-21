# 07 — Troubleshooting

## 1. Start here (MaintainA)

```bash
# in the browser / curl (auth required):
GET /api/integrations/whatsapp/ai-diagnostics
```

| Check | Ok means | If failing |
|-------|----------|------------|
| `db` | DB reachable | fix `api/.env` credentials |
| `package` | composer installed | `cd api && composer update excelle-insights/ai-assistant` |
| `hook_callback` / `hook_controller` | 2-line hook present | re-apply doc 02 Step 1 |
| `ai_tables` | `ai_assistant_*` present | run package migrations (doc 01 §4) |
| `ai_columns` | `ai_status`/`ai_error` on `whatsapp_messages` | `cd api && vendor/bin/phinx migrate` |
| `pk` | `whatsapp_messages` PK is `id` | `ALTER TABLE whatsapp_messages CHANGE COLUMN message_id id INT UNSIGNED NOT NULL AUTO_INCREMENT;` |
| `env_auto_reply` | not `false`/`0` | set `AI_ASSISTANT_AUTO_REPLY=true` |
| `env_openai` | key present | set `OPENAI_API_KEY` |
| `access_token` / `phone_id` | Meta connected | Settings → WhatsApp → Connect |

## 2. AI replies not arriving (WhatsApp)

1. `ai-diagnostics` all green? Fix reds first.
2. Check `whatsapp_messages.ai_status` for the inbound row:
   - `disabled` → auto-reply switch off.
   - `blocked` (`Business rules blocked auto-reply`) → `canAutoReply()` refused (hours/opt-in).
   - `failed: AI returned an empty response` / `OpenAI is not configured` → key/model/timeout.
   - `send_failed` → `ai_error` holds the Graph error (often: 24h window closed + no fallback template → set `AI_WHATSAPP_FALLBACK_TEMPLATE`).
   - `failed: Message had no text` → media/location message, nothing to answer (expected).
3. Duplicates? The hook is idempotent per `wa_message_id` — re-deliveries are skipped.

## 3. Guide answers are vague ("ask staff…")

The knowledge base has no matching How-To row. Add it (doc 03 §2–3), then
re-ask. Check `sources` in the ask-endpoint response (MaintainA:
`/api/ai/ask`; generic: empty `KnowledgeService::search()` result) — empty
means no chunk matched.

## 4. Diagnosis / analysis errors

- `Symptom description is too short` → send ≥ 10 chars (or `symptoms` field).
- `Question is required` (analysis) → send `question` (or `message`) ≥ 2 chars.
- `Too many requests` (429) → `AI_RATE_LIMIT_PER_HOUR` exhausted; retry after
  `Retry-After`.
- `Session expired. Please log out and log back in` → token lacks `company_id`.

## 5. Fresh-system order of operations

vendor WhatsApp migrations → bridge migrations (`WHATSAPP_MIGRATION_NOTES.md`)
→ package migrations (doc 01 §4) → env keys (doc 06) → hook (doc 02) →
knowledge import (doc 03) → `ai-diagnostics` green → test message.
