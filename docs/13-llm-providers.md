# 13 — LLM providers

The package talks to one interface, `Contracts\LlmClientInterface`:

```php
interface LlmClientInterface
{
    /** @param array<int,array{role:string,content:string}> $messages */
    public function chat(array $messages, string $model = '', ?float $temperature = null): array;
    public function embeddings(string $input, string $model = ''): array;
}
```

Only that class changes when the provider changes. `chat()` returns
`['content' => string, 'usage' => [...], 'raw' => mixed]`; `embeddings()`
returns a vector, or `[]` when the provider has no embeddings endpoint (callers
treat `[]` as "no embedding").

- [OpenAI-compatible endpoints](#openai-compatible-endpoints)
- [Environment keys](#environment-keys)
- [What the model actually does](#what-the-model-actually-does)
- [A native provider](#a-native-provider)
- [Models, temperature and options](#models-temperature-and-options)
- [Failure behaviour](#failure-behaviour)
- [Documents, pictures and audio](#documents-pictures-and-audio)
- [Troubleshooting](#troubleshooting)

---

## OpenAI-compatible endpoints

`Support\LlmFactory` builds the client from `LLM_PROVIDER`:

| `LLM_PROVIDER` | Base URL (default) | Key | Default model |
|---|---|---|---|
| `openai` | `https://api.openai.com/v1` | required | `gpt-4o` |
| `openrouter` | `https://openrouter.ai/api/v1` | required | `openai/gpt-4o` |
| `together` | `https://api.together.xyz/v1` | required | `gpt-4o` |
| `ollama` | `http://localhost:11434/v1` | optional | `llama3.1` |
| `vllm` | — set `LLM_BASE_URL` | optional for local | `gpt-4o` (set your own) |
| `lmstudio` | — set `LLM_BASE_URL` | optional for local | `gpt-4o` (set your own) |
| `custom` / `openai-compatible` | — set `LLM_BASE_URL` | as required | `gpt-4o` (set your own) |

```dotenv
LLM_PROVIDER=openrouter
LLM_API_KEY=sk-or-…
LLM_MODEL=anthropic/claude-sonnet-4
LLM_EMBEDDING_MODEL=text-embedding-3-small
LLM_BASE_URL=https://openrouter.ai/api/v1   # optional; the default is used
LLM_REQUEST_TIMEOUT=45
```

Notes:

- A missing key is tolerated only when the base URL points at `localhost`,
  `127.0.0.1` or a `*.local` host — that is how a local Ollama works without a
  key. Anywhere else an empty key throws at construction.
- `vllm`, `lmstudio` and `custom` have **no** built-in base URL; without
  `LLM_BASE_URL` they fall back to OpenAI's URL, so set it explicitly.
- `LLM_APP_URL` is sent as `HTTP-Referer`, which OpenRouter uses for app
  attribution; it is harmless elsewhere.
- Timeouts are per request; there is no built-in retry or queue. Your cron/job
  loop is the retry layer.

Anthropic and Gemini can be reached today through OpenRouter. For a native
SDK, implement `LlmClientInterface` (below).

---

## Environment keys

| Key | Legacy fallback | Meaning |
|---|---|---|
| `LLM_PROVIDER` | — | provider id |
| `LLM_API_KEY` | `OPENAI_API_KEY` | bearer token |
| `LLM_BASE_URL` | `OPENAI_BASE_URL` | endpoint root (no trailing slash needed) |
| `LLM_MODEL` | `OPENAI_MODEL` | chat model |
| `LLM_EMBEDDING_MODEL` | — | embeddings model |
| `LLM_REQUEST_TIMEOUT` | `OPENAI_REQUEST_TIMEOUT` | seconds |
| `LLM_APP_URL` | — | `HTTP-Referer` for OpenRouter |

These are **connection values**, so they stay in `.env`. They are not in the
settings catalogue and cannot be changed from `{prefix}_config`: a database row
must never be able to redirect prompts or exfiltrate a key. See
[03 — Configuration](03-configuration.md#config-that-cannot-come-from-the-database).

---

## What the model actually does

The model is used for:

1. **Intent + dataset selection** — one JSON-object completion marks the question
   as data, document, action or smalltalk and names a dataset.
2. **Query planning** — produces a plan (dataset, columns, filters, grouping,
   ordering, limit); the package compiles it under the table policy. The model
   does not write SQL on this path.
3. **Answer writing** — turns the rows into one or two sentences. If the model
   returns nothing, the rows are still rendered.
4. **Summaries** — conversation summaries and long-history compression.
5. **Booking-intent extraction** — the legacy WhatsApp pipeline asks the model
   whether a message contains a booking request.

Embeddings are used by knowledge retrieval (`knowledge.retrieval = hybrid`) and
semantic matching. With `[]` embeddings the package falls back to lexical
search — nothing breaks, recall just narrows.

Planning calls use temperature `0.0`; everything else uses the instance
temperature or the provider default. Any model that reliably returns a single
JSON object when asked is suitable. Very small chat models tend to fail the
planning step first; raise the model size before editing prompts.

---

## A native provider

```php
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;

final class AnthropicClient implements LlmClientInterface
{
    public function __construct(private \Anthropic\Client $client) {}

    public function chat(array $messages, string $model = '', ?float $temperature = null): array
    {
        $system = '';
        $turns = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system .= ($system === '' ? '' : "\n\n") . $m['content'];
                continue;
            }
            $turns[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        $res = $this->client->messages()->create([
            'model' => $model !== '' ? $model : 'claude-sonnet-4-20250514',
            'system' => $system,
            'messages' => $turns,
            'max_tokens' => 4096,
            'temperature' => $temperature ?? 0.2,
        ]);

        return [
            'content' => $res->content[0]->text ?? '',
            'usage' => [
                'prompt_tokens' => $res->usage->input_tokens ?? 0,
                'completion_tokens' => $res->usage->output_tokens ?? 0,
            ],
            'raw' => $res,
        ];
    }

    public function embeddings(string $input, string $model = ''): array
    {
        return [];   // Anthropic has no embeddings endpoint; lexical fallback applies.
    }
}

$api = new AssistantApi($pdo, ['llm' => new AnthropicClient($anthropic)]);
```

Token counts under `usage` are important even when your provider names them
differently: `prompt_tokens` / `completion_tokens` are folded into the
`{prefix}_usage_daily` rollup (aliases `input_tokens` / `output_tokens` are
understood too).

---

## Models, temperature and options

`AssistantService` reads its defaults from config and accepts per-call overrides:

```php
$api->chat('Revenue by region last month', $user, [
    'model' => 'gpt-4.1-mini',
    'temperature' => 0.1,
    'history_turns' => 6,
    'knowledge_top_k' => 8,
    'intent' => 'data',
    'chart' => true,
    'export' => 'csv',
]);
```

`llm.model` (fed by `LLM_MODEL`) is the default; the per-call `model` option
wins for that turn. Temperature is per call — when omitted, the provider
default is used. Planning calls always use temperature `0.0`.

---

## Failure behaviour

An LLM exception is **caught and audited** (`assistant.llm_failed`) and the turn
continues with empty model output:

- intent falls back to reading the question,
- data questions still compile and run if a dataset can be inferred,
- the answer sentence falls back to a deterministic rendering of the rows.

So a wrong key or unreachable endpoint often shows up as *plainer, less helpful
answers* rather than an error. Check `diagnostics()['llm']` and the audit trail
before blaming retrieval.

`diagnostics()` reports `provider`, `model`, `api_key` as `set`/`unset` (never
the value), `ready` and the construction error, if any.

---

## Documents, pictures and audio

`LlmClientInterface` is text-in, text-out. The package does **not** call vision
or transcription endpoints:

- **PDF / DOCX / XLSX / images** are turned into text by host extractors before
  indexing (see [05 — Knowledge & documents](05-knowledge-and-documents.md)).
- **Voice notes / images** on WhatsApp are skipped by `whatsapp.answer` because
  they carry no text; transcribe or caption them in your webhook first, then
  ask the assistant.
- If you want vision, implement a `DocumentExtractorInterface` (or a small
  `ExtractorInterface`) that calls your multimodal model and returns text. That
  keeps the credential and the provider-specific request in your app.

---

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Answers are terse and never say "based on…" | The model call is failing silently. Check `diagnostics()['llm']`, `LLM_API_KEY`, and `assistant.llm_failed` rows in the audit trail. |
| `LLM_API_KEY … not set` on boot | No key and the base URL is not local. Set `LLM_API_KEY` (or `OPENAI_API_KEY`). |
| `Unknown LLM_PROVIDER "gpt-4o"` | `LLM_PROVIDER` takes a provider id, not a model. Set `LLM_MODEL` instead. |
| Requests hit api.openai.com although you chose `vllm` | `vllm`/`lmstudio`/`custom` need an explicit `LLM_BASE_URL`. |
| `401` from OpenRouter | Key is for the wrong environment, or `HTTP-Referer` is blocked; set `LLM_APP_URL`. |
| Planning returns prose, then "I could not map that" | The model is too small or ignores JSON instructions. Use a larger model or a stricter one. |
| Embeddings are always `[]` | Your provider has no embeddings endpoint (or the model name is wrong); hybrid retrieval degrades to lexical, which is fine but less semantic. |
| Timeouts under load | Raise `LLM_REQUEST_TIMEOUT`; the package does not retry, so also add a retry in the calling job. |
| Usage rollup shows zero tokens | Your custom client does not return `usage` (or uses names other than `prompt_tokens`/`completion_tokens` and their aliases). |
