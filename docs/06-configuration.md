# 06 — Configuration reference

Host `.env` keys. Nothing is configured inside the package.

## LLM provider (provider-agnostic)

One block configures every AI in the host — the package and the app share it.
Any OpenAI-compatible endpoint works; only base URL + key + model change.
See `.env.example` at the package root for a copy-ready template.

```ini
LLM_PROVIDER=custom
LLM_API_KEY=<provider key>
LLM_BASE_URL=https://api.deepseek.com
LLM_MODEL=deepseek-flash
LLM_REQUEST_TIMEOUT=30
LLM_VISION_MODEL=deepseek-v4-flash-vision-exp
LLM_TRANSCRIBE_MODEL=whisper-1
```

Provider examples (full matrix in doc 08):

```ini
# OpenAI — embeddings + Whisper audio work out of the box
LLM_PROVIDER=openai
LLM_MODEL=gpt-4o

# DeepSeek — OpenAI-compatible; no embeddings endpoint (keyword search)
LLM_PROVIDER=custom
LLM_BASE_URL=https://api.deepseek.com
LLM_MODEL=deepseek-flash

# OpenCode Zen / Go — /chat/completions models only
LLM_PROVIDER=custom
LLM_BASE_URL=https://opencode.ai/zen/v1
LLM_MODEL=deepseek-v4.1-flash

# Ollama — local, no key
LLM_PROVIDER=ollama
LLM_BASE_URL=http://localhost:11434/v1
LLM_MODEL=llama3.1
LLM_EMBEDDING_MODEL=nomic-embed-text
```

Legacy `OPENAI_API_KEY` / `OPENAI_MODEL` / `OPENAI_BASE_URL` /
`OPENAI_REQUEST_TIMEOUT` / `OPENAI_WHISPER_MODEL` are still read as fallbacks.

### Host-side keys (MaintainA)

| Key | Default | Meaning |
|-----|---------|---------|
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
| `LLM_VISION_MODEL` | falls back `LLM_MODEL` | Image-diagnosis model (must support vision) |
| `LLM_TRANSCRIBE_MODEL` | `whisper-1` (legacy `OPENAI_WHISPER_MODEL`) | Voice-transcription model |
| `LLM_TRANSCRIBE_BASE_URL` | falls back `LLM_BASE_URL` | Audio endpoint override |
| `LLM_TRANSCRIBE_API_KEY` | falls back `LLM_API_KEY` | Audio key override |
| `LLM_EXTRA_HEADERS` | unset | Extra request headers as JSON (e.g. OpenCode Go's `x-opencode-session`) |
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
