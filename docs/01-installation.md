# 01 — Installation (step by step)

## 1. Requirements

- PHP >= 8.1 with `ext-json`, `ext-pdo` (+ `pdo_mysql`)
- A host app database (MySQL) with a `.env` file
- An OpenAI key (the host's key is reused — no second key needed)
- Composer 2.x

## 2. Install the package

### Option A — from Packagist (normal)

```bash
cd /path/to/your-app/api
composer require excelle-insights/ai-assistant
```

### Option B — local path (development, what MaintainA uses today)

In host `api/composer.json`:

```json
"repositories": [
  { "type": "vcs", "url": "/usr/local/var/www/ai-assistant" }
],
"require": {
  "excelle-insights/ai-assistant": "dev-main"
}
```

```bash
cd /path/to/your-app/api
composer update excelle-insights/ai-assistant
```

Verify the class loads:

```bash
php -r "require 'vendor/autoload.php'; var_dump(class_exists('ExcelleInsights\AiAssistant\Facade\AiAssistantManager'));"
# expect: bool(true)
```

## 3. Configure env (host `.env`)

```ini
# Reused OpenAI stack (same key the host app already uses)
OPENAI_API_KEY=sk-proj-...
OPENAI_MODEL=gpt-4o
OPENAI_WHISPER_MODEL=whisper-1
OPENAI_REQUEST_TIMEOUT=30
AI_RATE_LIMIT_PER_HOUR=60

# AI auto-reply + own-table prefix (full key list: 06-configuration.md)
AI_ASSISTANT_AUTO_REPLY=true
AI_ASSISTANT_TABLE_PREFIX=ai_assistant
AI_ASSISTANT_FALLBACK_TEMPLATE=0
```

## 4. Run migrations (creates ONLY `ai_assistant_*` tables)

```bash
# From the host app:
cd /path/to/your-app/api
vendor/bin/phinx migrate -c vendor/excelle-insights/ai-assistant/phinx.php

# …or via the package helper (used by MaintainA's Jenkinsfile):
php vendor/excelle-insights/ai-assistant/migrate.php
```

Created tables (all prefixed, no foreign keys into host tables):

| Table | Purpose |
|-------|---------|
| `{prefix}_knowledge` | Trainable Q&A / FAQs (`company_id, title, content, embedding, source, status`) |
| `{prefix}_sessions` | Conversation memory (`conversation_id, company_id, history_json, expires_at`) |
| `{prefix}_queue` | Async send queue (`conversation_id, wa_message_id, status, attempts, error`) |
| `{prefix}_training` | Self-learned Q&A (`company_id, question, answer`) |
| `{prefix}_schema` | Learned field descriptions for schema-aware answers |
| `{prefix}_reference` | Lookup summaries (services + prices, counts, categories) |

> Never add `company_id` to vendor `whatsapp_*` tables. The package tables
> are the only ones the package may write to.

## 5. Verify

1. Tables exist: `SHOW TABLES LIKE 'ai_assistant%';` → 6 rows.
2. Diagnostics endpoint (MaintainA): `GET /api/integrations/whatsapp/ai-diagnostics`
   → `package: ok`, `ai_tables: ok`, `env_openai: ok`.
3. Train one row (see `03-knowledge-training.md`), then send a test message.

Next: [02-connect-application.md](02-connect-application.md).
