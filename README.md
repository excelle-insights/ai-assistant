# Excelle Insights — AI Assistant Package

Standalone Composer package: one trainable AI core for WhatsApp auto-reply,
in-app user guidance, diagnosis support and business analysis — same install
pattern as `excelle-insights/whatsapp`, working with **any LLM provider**
(OpenAI, OpenRouter, Ollama, Together, vLLM…), not just OpenAI.

> Name history: folder/repo are now `ai-assistant`; own tables are
> `ai_assistant_*`. The composer name `excelle-insights/ai-assistant` and the
> PHP namespace `ExcelleInsights\AiAssistant\…` are kept until hosts migrate
> (then they become `excelle-insights/ai-assistant` / `…\AiAssistant\…`).

No edits to `vendor/excelle-insights/whatsapp` required.

Full step-by-step guides live in [`docs/`](docs/README.md) (install, connect,
knowledge, WhatsApp channel, one-AI router, config, troubleshooting, LLM
providers + table rename).

---

## Features

* Hooks after `WhatsappService::processWebhookPayload()` — no vendor edit.
* One AI for all channels: WhatsApp reply, knowledge-based user guide,
  vehicle-diagnosis support, business analysis (host routes per intent).
* Any LLM via `Contracts\LlmClientInterface` + `Support\LlmFactory`
  (`LLM_PROVIDER=openai|openrouter|together|ollama|vllm|lmstudio|custom`).
* Trainable per `company_id`: FAQs, services, pricing, How-To guides via
  `ai_assistant_knowledge` (embeddings + keyword search) and self-learning
  `ai_assistant_training`. No company model? Set `AI_ASSISTANT_TENANT_ID=0`
  and pass `0` everywhere — `company_id` is only a scoping key, see
  [`docs/02-connect-application.md`](docs/02-connect-application.md).
* Own tables (`AI_ASSISTANT_TABLE_PREFIX`, default `ai_assistant`; legacy
  `AI_WHATSAPP_TABLE_PREFIX` still honoured) + `SystemContextProviderInterface`
  to pull host data without FK tangles. Rename migration included.
* Async queue `ai_assistant_queue` + `whatsapp_conversations` / `whatsapp_messages` bridge.

---

## Requirements

* PHP >= 8.1, PDO MySQL, `ext-json`
* For the WhatsApp channel: host app with `excelle-insights/whatsapp` tables
  (`whatsapp_business_profiles`, `whatsapp_messages`, `whatsapp_conversations`,
  `whatsapp_credentials`, `whatsapp_access_tokens`). Other channels need no
  WhatsApp tables.
* Host `.env` LLM (OpenAI default; alternatives in [`docs/08-llm-providers-and-rename.md`](docs/08-llm-providers-and-rename.md)):
  ```
  LLM_PROVIDER=openai
  LLM_API_KEY=sk-proj-...        # or OPENAI_API_KEY (fallback)
  LLM_MODEL=gpt-4o               # or OPENAI_MODEL (fallback)
  OPENAI_WHISPER_MODEL=whisper-1
  LLM_REQUEST_TIMEOUT=30
  AI_RATE_LIMIT_PER_HOUR=60
  ```
* Host `.env` auto-reply + tables:
  ```
  # Toggle AI auto-reply for inbound WhatsApp messages. false/0 disables.
  AI_ASSISTANT_AUTO_REPLY=true
  # Canonical prefix for ai_assistant_knowledge / _sessions / _queue / _training / _schema / _reference.
  AI_ASSISTANT_TABLE_PREFIX=ai_assistant
  # Legacy fallbacks (pre-rename installs). Remove after migrating.
  AI_WHATSAPP_TABLE_PREFIX=ai_whatsapp
  # Optional fallback template id to queue when the 24h session window is closed.
  # 0 = try direct send anyway (will fail if window closed). Set to an Approved
  # whatsapp_templates.template_id to queue a template instead.
  AI_ASSISTANT_FALLBACK_TEMPLATE=0
  ```
* Migrations need `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` in host `.env`
  (runtime PDO is injected by the host, or built from `DB_DSN` by the facade).

---

## Installation

### 1. Require via Composer

```bash
composer require excelle-insights/ai-assistant
```

Or for local dev add to host `composer.json`:

```json
"repositories": [
  {"type": "vcs", "url": "/usr/local/var/www/ai-assistant"}
],
"require": {
  "excelle-insights/ai-assistant": "*"
}
```

```bash
composer update excelle-insights/ai-assistant
```

### 2. Run package migrations (own `phinx.php`, like `whatsapp` package)

```bash
# AI package tables:
vendor/bin/phinx migrate -c vendor/excelle-insights/ai-assistant/phinx.php

# Or from package dir:
cd /usr/local/var/www/ai-assistant && vendor/bin/phinx migrate -c phinx.php
```

Creates (prefix from `TablePrefix::get()`, default `ai_assistant`):
* `{prefix}_knowledge` (`company_id`, `category`, `title`, `content`, `embedding`, `source`, `status`)
* `{prefix}_sessions` (`conversation_id`, `company_id`, `history_json`, `expires_at`)
* `{prefix}_queue` (`conversation_id`, `wa_message_id`, `status`, `payload`, `attempts`, `error`)
* `{prefix}_training` (`company_id`, `question`, `answer`)
* `{prefix}_schema` (learned field descriptions)
* `{prefix}_reference` (lookup summaries: services + prices, counts)

Upgrading from `ai_whatsapp_*`? Migration `20260922000000` renames old → new,
data preserved — see [`docs/08-llm-providers-and-rename.md`](docs/08-llm-providers-and-rename.md) §3.

No `company_id` column added to vendor `whatsapp_*` tables.

### 3. Hook inbound (host adds 2 lines, no vendor edit)

In `api/whatsapp/callback.php` and `api/src/Controllers/WhatsappController.php::webhookProcess()` after `WhatsappService::processWebhookPayload($payload)`:

```php
if (class_exists(\ExcelleInsights\AiAssistant\Facade\AiAssistantManager::class)) {
    (new \ExcelleInsights\AiAssistant\Services\AiAssistantService())->onInboundMessages($results);
}
```

`$results` is the array returned by `processWebhookPayload` (`['type'=>'message', 'wa_message_id'=>..., 'conversation_id'=>...]`).

### 4. Provide system context (host implements interface)

```php
// api/src/Services/MaintainaContextProvider.php
use ExcelleInsights\AiAssistant\Contracts\SystemContextProviderInterface;

class MaintainaContextProvider implements SystemContextProviderInterface {
  public function getCompanyContext(int $companyId): array {
    $db = \ExcelleCore\Core\Database::getInstance()->getConnection();
    $row = $db->query("SELECT name FROM companies WHERE id=$companyId")->fetch();
    return ['name'=>$row['name'], 'services'=> $db->query("SELECT name FROM service_types WHERE company_id=$companyId")->fetchAll(PDO::FETCH_COLUMN)];
  }
  public function getCustomerContext(int $conversationId): array { /* last 3 service_records like AiController.php:604 */ return []; }
  public function getCompanyKnowledge(int $companyId, string $query, int $k=5): array { /* FTS over ai_assistant_knowledge */ return []; }
  public function canAutoReply(int $conversationId): bool { return true; } // business hours, opt-in
}
```

Pass to manager (any `LlmClientInterface` accepted — use `LlmFactory::make()`
for env-driven provider selection):

```php
use ExcelleInsights\AiAssistant\Support\LlmFactory;

$ai = new \ExcelleInsights\AiAssistant\Facade\AiAssistantManager(
  pdo: \ExcelleCore\Core\Database::getInstance()->getConnection(),
  contextProvider: new MaintainaContextProvider(),
  openAI: LlmFactory::make() // or new \ExcelleCore\AI\OpenAIClient() to reuse host's
);
```

If you don't provide `openAI`, the package builds one via `LlmFactory::make()`
reading `LLM_*` (fallback `OPENAI_*`) from host `.env`.

Any app works here: any `PDO`, any `SystemContextProviderInterface`
implementation, any `LlmClientInterface` (`LlmFactory::make()` or your own) —
`ExcelleCore\*` above is just the MaintainA example.

### 4b. Receive booking requests (host implements interface)

When a customer asks to book ("I want to book…", "nataka miadi…"), the
package detects the intent, extracts the service + preferred date from the
conversation, and hands a `Support\BookingIntent` to the host. The package
never writes app tables — the host persists it however its domain requires
(booking row, ticket, CRM lead).

```php
// Host side, e.g. api/src/Services/AppBookingHandler.php
use ExcelleInsights\AiAssistant\Contracts\BookingHandlerInterface;
use ExcelleInsights\AiAssistant\Support\BookingIntent;

class AppBookingHandler implements BookingHandlerInterface {
  public function handleBookingIntent(BookingIntent $intent): void {
    // $intent->companyId, ->contactPhone, ->contactName, ->service,
    // ->preferredDate (Y-m-d or null), ->confidence, ->messageBody, ->aiReply
    // De-dupe + insert into YOUR bookings table + notify staff here.
  }
}
```

Register it where you hook inbound (constructor or setter — both work):

```php
$ai = new \ExcelleInsights\AiAssistant\Services\AiAssistantService(
  openAI: $llm,
  bookingHandler: new AppBookingHandler(),   // or:
);
$ai->setBookingHandler(new AppBookingHandler());
$ai->onInboundMessages($results);
```

Notes:
- The handler runs **after** the reply is sent and the session is stored, so
  it can't delay or break the customer-facing flow; host exceptions are
  caught and logged by the package.
- Extraction uses the LLM when configured, with a keyword fallback when not
  (`BookingIntentExtractor` is also usable standalone).
- Disable per deployment with `AI_ASSISTANT_BOOKING_HOOK=false`.
- Timezone for relative dates ("Friday", "tomorrow"): `AI_ASSISTANT_TIMEZONE`
  (fallback `APP_TIMEZONE`, then `UTC`).

### 5. Train

```bash
curl -X POST https://host/api/integrations/whatsapp/ai-knowledge/import \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"csv":"category,title,content\n\"Working Hours\",\"Opening hours\",\"Mon-Fri 8am-5pm, Sat 8-12\""}'
```

Or via service:

```php
use ExcelleInsights\AiAssistant\Services\KnowledgeService;
use ExcelleInsights\AiAssistant\Services\TrainingService;

$knowledge = new KnowledgeService($pdo, LlmFactory::make());
$training = new TrainingService($pdo, $knowledge);
$training->ingest($companyId, "Return Policy", "30 days...", "manual");
```

File import: `POST https://host/api/integrations/whatsapp/ai-knowledge/import`
(CSV; see [`docs/03-knowledge-training.md`](docs/03-knowledge-training.md)).

---

## Configuration

`config/ai-assistant.php` (publish to host `config/` if needed):

```php
return [
  'table_prefix' => $_ENV['AI_ASSISTANT_TABLE_PREFIX'] ?? $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? 'ai_assistant',
  'llm' => [ // canonical — any OpenAI-compatible provider (see Support\LlmFactory)
    'provider' => $_ENV['LLM_PROVIDER'] ?? 'openai',
    'api_key' => $_ENV['LLM_API_KEY'] ?? $_ENV['OPENAI_API_KEY'] ?? '',
    'base_url' => $_ENV['LLM_BASE_URL'] ?? $_ENV['OPENAI_BASE_URL'] ?? null,
    'model' => $_ENV['LLM_MODEL'] ?? $_ENV['OPENAI_MODEL'] ?? 'gpt-4o',
    'embedding_model' => $_ENV['LLM_EMBEDDING_MODEL'] ?? 'text-embedding-3-small',
    'timeout' => (int)($_ENV['LLM_REQUEST_TIMEOUT'] ?? $_ENV['OPENAI_REQUEST_TIMEOUT'] ?? 30),
    'whisper_model' => $_ENV['OPENAI_WHISPER_MODEL'] ?? 'whisper-1',
  ],
  'auto_reply' => [
    'enabled' => ($_ENV['AI_ASSISTANT_AUTO_REPLY'] ?? $_ENV['AI_WHATSAPP_AUTO_REPLY'] ?? 'true') !== 'false',
    'outside_session_use_template' => true, // 24h window → queue template
    'fallback_template_id' => (int)($_ENV['AI_WHATSAPP_FALLBACK_TEMPLATE'] ?? 0),
  ],
];
```

---

## How It Works

1. **Inbound** `POST /api/integrations/whatsapp/webhook` (router flattened `{field,value}`) → `WhatsappService::processWebhookPayload()` inserts `whatsapp_messages` `direction=inbound` + `whatsapp_conversations` + `events`.
2. **Hook** calls `AiAssistantService::onInboundMessages()` for each new `wa_message_id`.
3. **Context** via `SystemContextProvider` + **Knowledge** via `KnowledgeService::search($companyId, $messageBody, 5)`.
4. **Prompt** built and sent via the configured LLM (`LlmClientInterface::chat()`, default model from `LlmFactory::defaultModel()`).
5. **Reply** via `WhatsappApi::sendTextMessage($from, $reply)` if `isSessionActive()` else enqueue to `whatsapp_queue` with template.

Same core answers guide/diagnosis/analysis requests — the host routes per
intent (see [`docs/05-one-ai-router.md`](docs/05-one-ai-router.md)).

---

## Pushing Changes

```bash
cd /usr/local/var/www/ai-assistant
git add .
git commit -m "feat: ..."
git push origin main   # origin = git@github.com:excelle-insights/ai-assistant.git

# Host picks it up:
cd /path/to/host/api && composer update excelle-insights/ai-assistant
```

(Cutting over to composer name `excelle-insights/ai-assistant` happens as a
separate versioned step once hosts are ready.)

---

## References

* Step-by-step: [`docs/`](docs/README.md) (esp. `08-llm-providers-and-rename.md`)
* Black-box proposal: [`BLACKBOX_DECOUPLING_PROPOSAL.md`](BLACKBOX_DECOUPLING_PROPOSAL.md)
* Host AI: `api/src/Controllers/AiController.php` `callAI()`, host `.env` `OPENAI_*` / `LLM_*`
* WhatsApp vendor: `api/vendor/excelle-insights/whatsapp/src/Facade/WhatsAppManager.php`, `WHATSAPP_MIGRATION_NOTES.md` (host docs)
