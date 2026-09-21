# AI Assistant Package — Documentation

One AI core for every channel and every job: WhatsApp auto-reply, in-app
user guide, vehicle diagnosis, and business analysis. The host application
decides what the AI is used for — the package never knows host tables and
never depends on a specific WhatsApp vendor package.

> Rename done (2026-09-21): folder/repo are `ai-assistant`, namespace
> `ExcelleInsights\AiAssistant\…`, composer `excelle-insights/ai-assistant`,
> tables `ai_assistant_*`. `AI_WHATSAPP_*` / `ai_whatsapp_*` survive only as
> documented legacy fallbacks.

## Contents

| File | What it covers |
|------|----------------|
| [01-installation.md](01-installation.md) | Requirements, Composer install, migrations, first verification |
| [02-connect-application.md](02-connect-application.md) | Wiring the package into YOUR app (MaintainA worked example + generic app) |
| [03-knowledge-training.md](03-knowledge-training.md) | Knowledge base: CSV format, training API, system-guide content |
| [04-whatsapp-channel.md](04-whatsapp-channel.md) | WhatsApp auto-reply flow, session window, queue, templates |
| [05-one-ai-router.md](05-one-ai-router.md) | One AI for all (pattern + package blocks; MaintainA `POST /api/ai/ask` as reference) |
| [06-configuration.md](06-configuration.md) | Every env key, table prefixes, rate limits |
| [07-troubleshooting.md](07-troubleshooting.md) | Diagnostics endpoint, common failures and fixes |
| [08-llm-providers-and-rename.md](08-llm-providers-and-rename.md) | Any LLM provider (OpenAI/OpenRouter/Ollama/…) + `ai_whatsapp_*` → `ai_assistant_*` rename |

## 30-second mental model

```
WhatsApp ──┐
In-app chat ─┼──▶ Host adapter (YOUR code, ~50 lines) ──▶ AI core (THIS package)
Diagnosis ──┤         routes intent, owns tables/APIs         knowledge + LLM + memory
Analysis ───┘
```

- The AI core only reads its own `ai_assistant_*` tables.
- Everything about your app (tables, WhatsApp sender, business rules) lives
  in your adapter / controllers — never in the package.
- Train once in the knowledge base; every channel reuses it.
