# 08 — LLM providers (not just OpenAI) + table-prefix rename

## 1. Yes — the AI is provider-independent

All package code talks to `src/Contracts/LlmClientInterface.php`
(`chat()` + `embeddings()`), never to a concrete client. The concrete
`Client/OpenAIClient.php` speaks plain OpenAI-compatible HTTP, so it already
covers OpenAI, OpenRouter, Together, vLLM, LM Studio and Ollama — only the
base URL, key and model change. `Support\LlmFactory` picks from env:

```php
$llm = \ExcelleInsights\AiAssistant\Support\LlmFactory::make(); // LlmClientInterface
$ai  = new \ExcelleInsights\AiAssistant\Facade\AiAssistantManager(
    pdo: $pdo, contextProvider: $ctx, openAI: $llm   // any provider
);
```

## 2. Configure per provider (host `.env`, step by step)

### OpenAI (default — nothing new needed)

```ini
LLM_PROVIDER=openai
LLM_API_KEY=sk-proj-...
LLM_MODEL=gpt-4o
```
(`OPENAI_API_KEY` / `OPENAI_MODEL` still work as fallback.)

### OpenRouter (Claude, Gemini, Llama, … via one key)

```ini
LLM_PROVIDER=openrouter
LLM_API_KEY=sk-or-...
LLM_MODEL=openai/gpt-4o            # or anthropic/claude-3-5-sonnet, google/gemini-flash-1.5, …
LLM_APP_URL=https://your-domain    # optional, recommended by OpenRouter
```
Notes: embeddings on OpenRouter need an embedding model —
`LLM_EMBEDDING_MODEL=openai/text-embedding-3-small`. If embeddings fail, the
package logs nothing and stores `NULL` (keyword search keeps working).

### Ollama (local, no key, no cloud)

```bash
ollama pull llama3.1 && ollama serve   # exposes http://localhost:11434
```

```ini
LLM_PROVIDER=ollama
LLM_BASE_URL=http://localhost:11434/v1
LLM_MODEL=llama3.1
LLM_EMBEDDING_MODEL=nomic-embed-text   # ollama pull nomic-embed-text
```
No `LLM_API_KEY` needed for `localhost` / `127.0.0.1` / `*.local`.

### Together / vLLM / LM Studio / any OpenAI-compatible server

```ini
LLM_PROVIDER=together                  # or vllm | lmstudio | custom
LLM_API_KEY=...
LLM_BASE_URL=https://api.together.xyz/v1   # or http://gpu-box:8000/v1 …
LLM_MODEL=meta-llama/Meta-Llama-3.1-70B-Instruct-Turbo
```

### Native Anthropic / Gemini (without OpenRouter)

Implement the 2-method interface and register it (5 minutes):

```php
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
final class AnthropicClient implements LlmClientInterface {
    public function chat(array $messages, string $model = '', ?float $temperature = null): array { /* POST /v1/messages, map to ['content','usage'] */ }
    public function embeddings(string $input, string $model = ''): array { return []; } // or Voyage/cohere
}
// then extend LlmFactory::make() with case 'anthropic': return new AnthropicClient(...);
```

## 3. Rename: `ai_whatsapp_*` → `ai_assistant_*` (step by step)

Migration `database/migrations/20260922000000_rename_ai_whatsapp_to_ai_assistant.php`
renames `knowledge, sessions, queue, training, schema, reference`, data preserved.

```bash
# 1. Deploy the new package code (migrations + TablePrefix + factory).
cd /path/to/your-app/api && composer update excelle-insights/ai-assistant

# 2. Point env at the new prefix (keep the old key until step 4 passes).
AI_ASSISTANT_TABLE_PREFIX=ai_assistant
AI_WHATSAPP_TABLE_PREFIX=ai_whatsapp     # legacy fallback, remove later

# 3. Run package migrations (renames old → new; fresh installs: no-op).
vendor/bin/phinx migrate -c vendor/excelle-insights/ai-assistant/phinx.php
# …or: php vendor/excelle-insights/ai-assistant/migrate.php

# 4. Verify, then clean up.
SHOW TABLES LIKE 'ai_assistant%';   # expect 6 rows with your data
# SELECT COUNT(*) from each renamed table matches pre-deploy counts
# Remove AI_WHATSAPP_TABLE_PREFIX from .env
# Update any host code reading ai_whatsapp_* (e.g. MaintainA WhatsappController
# diagnostics) to ai_assistant_*.
```

Behaviour matrix:

| Situation | Result |
|-----------|--------|
| Old tables exist, new don't | `RENAME TABLE` (data kept) |
| Neither exists (fresh) | create-migrations build `ai_assistant_*` directly; rename is no-op |
| Old prefix == new prefix | no-op |
| Both exist | no-op — merge manually, then drop the stale set |

`Support\TablePrefix::get()` (code) and all create-migrations resolve
`AI_ASSISTANT_TABLE_PREFIX` → legacy `AI_WHATSAPP_TABLE_PREFIX` → default
`ai_assistant`, so code and migrations always agree.
