# Excelle Insights — AI WhatsApp Package

Standalone Composer package that reads inbound WhatsApp messages and auto-replies with a trainable AI — same install pattern as `excelle-insights/whatsapp`, reusing the host app's OpenAI stack.

No edits to `vendor/excelle-insights/whatsapp` required.

---
## Features

* Hooks after `WhatsappService::processWebhookPayload()` — no vendor edit.
* Trainable per `company_id`: FAQs, services, pricing via `ai_whatsapp_knowledge` (embeddings / FTS).
* Reuses host `OPENAI_API_KEY` / `OPENAI_MODEL` (`AiController.php:76` pattern) — no separate key.
* Own tables (`AI_WHATSAPP_TABLE_PREFIX`, default `ai_whatsapp`) + `SystemContextProviderInterface` to pull host data without FK tangles.
* Async queue `ai_whatsapp_queue` + `whatsapp_conversations` / `whatsapp_messages` bridge.

---

## Requirements

* PHP >= 8.1, PDO MySQL, `ext-json`
* Host app with `excelle-insights/whatsapp` tables (`whatsapp_business_profiles`, `whatsapp_messages`, `whatsapp_conversations`, `whatsapp_credentials`, `whatsapp_access_tokens`) and `DB_DSN`/`DB_USER`/`DB_PASSWORD` in `.env`
* Host `.env` OpenAI:
  ```
  OPENAI_API_KEY=sk-proj-...
  OPENAI_MODEL=gpt-4o
  OPENAI_WHISPER_MODEL=whisper-1
  OPENAI_REQUEST_TIMEOUT=30
  AI_RATE_LIMIT_PER_HOUR=60
  ```
* Host `.env` AI WhatsApp auto-reply:
  ```
  # Toggle AI auto-reply for inbound WhatsApp messages. false/0 disables.
  AI_WHATSAPP_AUTO_REPLY=true
  # Table prefix for ai_whatsapp_knowledge / _sessions / _queue.
  AI_WHATSAPP_TABLE_PREFIX=ai_whatsapp
  # Optional fallback template id to queue when the 24h session window is closed.
  # 0 = try direct send anyway (will fail if window closed). Set to an Approved
  # whatsapp_templates.template_id to queue a template instead.
  AI_WHATSAPP_FALLBACK_TEMPLATE=0
  ```

---

## Installation

### 1. Require via Composer (after you push this package to GitHub / Packagist)

```bash
composer require excelle-insights/ai-whatsapp
```

Or for local dev add to host `composer.json`:

```json
"repositories": [
  {"type": "vcs", "url": "/usr/local/var/www/ai-whatsapp"}
],
"require": {
  "excelle-insights/ai-whatsapp": "*"
}
```

```bash
composer update excelle-insights/ai-whatsapp
```

### 2. Run package migrations (own `phinx.php`, like `whatsapp` package)

```bash
# Host already has whatsapp tables:
vendor/bin/phinx migrate -c vendor/excelle-insights/whatsapp/phinx.php

# AI package tables:
vendor/bin/phinx migrate -c vendor/excelle-insights/ai-whatsapp/phinx.php

# Or from package dir:
cd /usr/local/var/www/ai-whatsapp && vendor/bin/phinx migrate -c phinx.php
```

Creates:
* `ai_whatsapp_knowledge` (`company_id`, `title`, `content`, `embedding`, `source`, `status`)
* `ai_whatsapp_sessions` (`conversation_id`, `company_id`, `history_json`, `expires_at`)
* `ai_whatsapp_queue` (`conversation_id`, `wa_message_id`, `status`, `attempts`, `error`)

No `company_id` column added to vendor `whatsapp_*` tables.

### 3. Hook inbound (host adds 2 lines, no vendor edit)

In `api/whatsapp/callback.php` and `api/src/Controllers/WhatsappController.php::webhookProcess()` after `WhatsappService::processWebhookPayload($payload)`:

```php
if (class_exists(\ExcelleInsights\AiWhatsapp\Facade\AiWhatsappManager::class)) {
    (new \ExcelleInsights\AiWhatsapp\Services\AiWhatsappService())->onInboundMessages($results);
}
```

`$results` is the array returned by `processWebhookPayload` (`['type'=>'message', 'wa_message_id'=>..., 'conversation_id'=>...]`).

### 4. Provide system context (host implements interface)

```php
// api/src/Services/MaintainaContextProvider.php
use ExcelleInsights\AiWhatsapp\Contracts\SystemContextProviderInterface;

class MaintainaContextProvider implements SystemContextProviderInterface {
  public function getCompanyContext(int $companyId): array {
    $db = \ExcelleCore\Core\Database::getInstance()->getConnection();
    $row = $db->query("SELECT name FROM companies WHERE id=$companyId")->fetch();
    return ['name'=>$row['name'], 'services'=> $db->query("SELECT name FROM service_types WHERE company_id=$companyId")->fetchAll(PDO::FETCH_COLUMN)];
  }
  public function getCustomerContext(int $conversationId): array { /* last 3 service_records like AiController.php:604 */ return []; }
  public function getCompanyKnowledge(int $companyId, string $query, int $k=5): array { /* FTS over ai_whatsapp_knowledge */ return []; }
  public function canAutoReply(int $conversationId): bool { return true; } // business hours, opt-in
}
```

Pass to manager:

```php
$ai = new \ExcelleInsights\AiWhatsapp\Facade\AiWhatsappManager(
  pdo: \ExcelleCore\Core\Database::getInstance()->getConnection(),
  contextProvider: new MaintainaContextProvider(),
  openAI: new \ExcelleCore\AI\OpenAIClient() // reuse host's, or null to use package's fallback
);
```

If you don't provide `openAI`, package uses its own `Client\OpenAIClient` reading `OPENAI_API_KEY` from host `.env` (like `WhatsAppManager.php:45` `EnvLoader::load()`).

### 5. Train

```bash
curl -X POST https://host/api/ai-whatsapp/knowledge \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"company_id":1,"title":"Hours","content":"Mon-Fri 8am-5pm, Sat 8-12"}'
```

Or via service:

```php
$training = new \ExcelleInsights\AiWhatsapp\Services\TrainingService($pdo);
$training->ingest($companyId, "Return Policy", "30 days...", "manual");
```

File import:

```bash
curl -X POST https://host/api/ai-whatsapp/knowledge/import -F "file=@policy.pdf" -F "company_id=1"
```

---

## Configuration

`config/ai-whatsapp.php` (publish to host `config/` if needed):

```php
return [
  'table_prefix' => $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? 'ai_whatsapp',
  'openai' => [
    'api_key' => $_ENV['OPENAI_API_KEY'] ?? '',
    'model' => $_ENV['OPENAI_MODEL'] ?? 'gpt-4o',
    'timeout' => $_ENV['OPENAI_REQUEST_TIMEOUT'] ?? 30,
  ],
  'auto_reply' => [
    'enabled' => ($_ENV['AI_WHATSAPP_AUTO_REPLY'] ?? 'true') !== 'false',
    'outside_session_use_template' => true, // 24h window → queue template
    'fallback_template_id' => (int)($_ENV['AI_WHATSAPP_FALLBACK_TEMPLATE'] ?? 0),
  ],
];
```

---

## How It Works

1. **Inbound** `POST /api/integrations/whatsapp/webhook` (router flattened `{field,value}`) → `WhatsappService::processWebhookPayload()` inserts `whatsapp_messages` `direction=inbound` + `whatsapp_conversations` + `events`.
2. **Hook** calls `AiWhatsappService::onInboundMessages()` for each new `wa_message_id`.
3. **Context** via `SystemContextProvider` + **Knowledge** via `KnowledgeService::search($companyId, $messageBody, 5)`.
4. **Prompt** built and sent via `OpenAIClient::chat()` (same as `AiController.php:220`).
5. **Reply** via `WhatsappApi::sendTextMessage($from, $reply)` if `isSessionActive()` else enqueue to `whatsapp_queue` with template.

---

## Pushing to Public & Using in Host

```bash
cd /usr/local/var/www/ai-whatsapp
git init
git add .
git commit -m "feat: initial ai-whatsapp package"
git remote add origin git@github.com:excelle-insights/ai-whatsapp.git
git push -u origin main

# Host:
composer require excelle-insights/ai-whatsapp
```
Host `composer.json` will then resolve `excelle-insights/ai-whatsapp` from Packagist.
---

## References

* Host AI: `api/src/Controllers/AiController.php:76` `callAI()`, `api/.env:78` `OPENAI_*`
* WhatsApp vendor: `api/vendor/excelle-insights/whatsapp/src/Facade/WhatsAppManager.php:45`, `database/migrations/20260101000005_create_whatsapp_messages_table.php:16`, `WHATSAPP_MIGRATION_NOTES.md`
