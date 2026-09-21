# 02 — Connect the package to YOUR application (step by step)

The package is a black box: it owns AI logic + its own tables. Your app owns
three things: **(a)** the inbound hook, **(b)** business context, **(c)** sending.
No package file is ever edited.

## Step 1 — Hook the inbound event (2 lines, no vendor edit)

After your WhatsApp receiver stores the inbound message, call the package.
MaintainA does this in **two** places (both are needed):

```php
// api/whatsapp/callback.php — after WhatsappService::processWebhookPayload($payload)
if (class_exists(\ExcelleInsights\AiAssistant\Facade\AiAssistantManager::class)) {
    (new \ExcelleInsights\AiAssistant\Services\AiAssistantService())->onInboundMessages($results);
}

// api/src/Controllers/WhatsappController.php::webhookProcess() — same 2 lines
// after WhatsappService::processWebhookPayload($payload)
```

`$results` is the array your receiver returns, one entry per message:
`['type' => 'message', 'wa_message_id' => ..., 'conversation_id' => ...]`.

**Generic app (different WhatsApp stack):** build the same `$results` shape from
your own receiver (Twilio webhook, Meta-direct, SMS gateway…) and call
`onInboundMessages($results)`. The package does not care which provider
filled your inbox tables.

## Step 2 — Provide business context (host implements one interface)

```php
// e.g. api/src/Services/MaintainaContextProvider.php
use ExcelleInsights\AiAssistant\Contracts\SystemContextProviderInterface;

class MaintainaContextProvider implements SystemContextProviderInterface
{
    public function getCompanyContext(int $companyId): array
    {
        // name, services, hours… from YOUR tables
    }
    public function getCustomerContext(int $conversationId): array
    {
        // contact + last service records… from YOUR tables
    }
    public function getCompanyKnowledge(int $companyId, string $query, int $k = 5): array
    {
        // top-k chunks (default: package KnowledgeService::search does this)
    }
    public function canAutoReply(int $conversationId): bool
    {
        return true; // business hours / opt-in / staff-online checks here
    }
}
```

Pass it to the facade (one shared helper in your app, e.g. `boot_ai_engine()`):

```php
$ai = new \ExcelleInsights\AiAssistant\Facade\AiAssistantManager(
    pdo: \ExcelleCore\Core\Database::getInstance()->getConnection(),
    contextProvider: new MaintainaContextProvider(),
    openAI: new \ExcelleCore\AI\OpenAIClient() // reuse host's; or null = package fallback
);
```

Rules:

- Host code **never** instantiates `KnowledgeService` / `SchemaService` /
  `AiAssistantService` directly — only the facade + the interface above.
  (MaintainA's `WhatsappController::aiKnowledge()/aiSchema()` still does this
  today; it is scheduled to move behind the facade.)
- The package **never** queries your tables — only the interface methods.

### No company model? Single-tenant apps work too

`company_id` in the package tables is only an integer scoping key (no foreign
keys). If your app has no companies/tenants:

```ini
AI_ASSISTANT_TENANT_ID=0
```

and pass that same value everywhere (`search(0, …)`, `ingest(0, …)`,
`getCompanyContext(0)` …). An explicitly set id — even `0` — short-circuits
tenant resolution before any `companies`-table lookup, so hosts without that
table pay zero queries. Unset, it falls back to legacy
`AI_WHATSAPP_COMPANY_ID`, then `companies`-table resolution, then `1`.

## Step 3 — Reuse the host LLM stack (no second key)

The package reads `LLM_*` (`LLM_PROVIDER`, `LLM_API_KEY`, `LLM_MODEL` —
each falling back to the `OPENAI_*` equivalent) from the host `.env`
via `Support\LlmFactory` (doc 08), or accepts your injected
`LlmClientInterface`. Token balance, rate limits and logging stay in the
host (`AiTokenService`, `AIRateLimiter`, `AILogger` in MaintainA) — the
package just calls chat.

## Step 4 — Confirm the wiring

`GET /api/integrations/whatsapp/ai-diagnostics` must report:

- `package: ok` (composer installed)
- `hook_callback: ok`, `hook_controller: ok` (Step 1 present)
- `ai_tables: ok` (migrations ran)
- `env_openai: ok`, plus token / phone checks

Next: train content in [03-knowledge-training.md](03-knowledge-training.md),
then the channel flow in [04-whatsapp-channel.md](04-whatsapp-channel.md).
