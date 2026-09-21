# 06 — Configuration reference

Host `.env` keys. Nothing is configured inside the package.

## OpenAI (reused host stack)

| Key | Default | Meaning |
|-----|---------|---------|
| `OPENAI_API_KEY` | — (required with `LLM_PROVIDER=openai`) | Single key for app + package (fallback for `LLM_API_KEY`) |
| `OPENAI_MODEL` | `gpt-4o` | Chat model (fallback for `LLM_MODEL`) |
| `OPENAI_WHISPER_MODEL` | `whisper-1` | Voice transcription (assistant widget) |
| `OPENAI_REQUEST_TIMEOUT` | `30` | HTTP seconds |
| `AI_RATE_LIMIT_PER_HOUR` | `60` | Per-company chat cap |
| `AI_CHAT_SESSION_STORAGE` | `storage/ai_sessions` | Staff chat sessions (file) |

## Package behaviour

| Key | Default | Meaning |
|-----|---------|---------|
| `LLM_PROVIDER` | `openai` | `openai\|openrouter\|together\|ollama\|vllm\|lmstudio\|custom` (doc 08) |
| `LLM_API_KEY` | falls back `OPENAI_API_KEY` | Provider key (not needed for local Ollama/LM Studio) |
| `LLM_BASE_URL` | per-provider default | Override for self-hosted gateways |
| `LLM_MODEL` | falls back `OPENAI_MODEL`, else per-provider default | Chat model |
| `LLM_EMBEDDING_MODEL` | `text-embedding-3-small` | Embeddings model |
| `AI_ASSISTANT_TABLE_PREFIX` | `ai_assistant` | Own-table prefix (canonical; doc 08 §3) |
| `AI_WHATSAPP_TABLE_PREFIX` | legacy fallback | Pre-rename prefix; remove after rename |
| `AI_ASSISTANT_TENANT_ID` | unset (= auto-resolve) | Fixed tenant id; set `0` for apps with no company model (doc 02) |
| `AI_ASSISTANT_AUTO_REPLY` | `true` | Master switch for WhatsApp auto-reply (`false`/`0` = off; legacy `AI_WHATSAPP_AUTO_REPLY` still honoured) |
| `AI_ASSISTANT_FALLBACK_TEMPLATE` | `0` | Approved template id when the 24h window is closed (legacy `AI_WHATSAPP_FALLBACK_TEMPLATE` still honoured) |
| `AI_ASSISTANT_SESSION_TURNS` | `10` | Conversation memory window (legacy `AI_WHATSAPP_SESSION_TURNS` still honoured) |
| Legacy (pre-rename, remove when ready) | | `AI_WHATSAPP_TABLE_PREFIX`, `AI_WHATSAPP_AUTO_REPLY`, `AI_WHATSAPP_FALLBACK_TEMPLATE`, `AI_WHATSAPP_COMPANY_ID`, `AI_WHATSAPP_SESSION_TURNS` |

## WhatsApp vendor (unchanged, host-owned)

`WHATSAPP_TABLE_PREFIX`, `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, `WHATSAPP_APP_ID`,
`WHATSAPP_APP_SECRET`, `WHATSAPP_REDIRECT_URI`, `WHATSAPP_MEDIA_PATH`,
`WHATSAPP_SINGLE_TENANT`, `WHATSAPP_TENANT_SECRET` — see
`WHATSAPP_ROUTER_SETUP.md` in the host docs.

## Ask endpoint (host-owned; MaintainA route shown)

MaintainA's `POST /api/ai/ask` needs nothing beyond the LLM keys above. The `mode`
(`auto|guide|diagnosis|analysis`) is per-request, not env. Other apps expose
their own equivalent (doc 05 §3).
