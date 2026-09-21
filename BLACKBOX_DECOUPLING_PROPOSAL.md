# AI Package Black-Box Decoupling Proposal
> Status note (2026-09-21): the §1 audit below describes the pre-rename code
> (`ai-whatsapp` folder, `AiWhatsapp` namespace). The rename to `ai-assistant`
> / `AiAssistant` / `ai_assistant_*` + LLM-provider abstraction + single-tenant
> support have since been implemented; the remaining direction (ports &
> adapters, host-side discovery) still stands.
**Package:** `excelle-insights/ai-assistant` (`/usr/local/var/www/ai-assistant/`) → rename to `excelle-insights/ai-assistant` (code) / `ai_assistant_*` (tables)
**Host (example):** MaintainA (`/usr/local/var/www/maintaina/`)
**Status:** Proposal — not yet implemented
**Goal:** Make the AI package a fully black-box, channel- and schema-independent core. All host/WhatsApp specifics live in a thin host-side adapter (≈50 lines). The package never names a host table, never sees a physical table/column name, and never imports a WhatsApp vendor class.

---

## 1. How it interacts today (audit)

| # | Interaction | Location | Coupling problem |
|---|-------------|----------|------------------|
| 1 | Inbound hook after webhook | `maintaina/api/whatsapp/callback.php:79-81`, `maintaina/api/src/Controllers/WhatsappController.php:880-883` → `AiAssistantService::onInboundMessages($results)` | Host must duplicate the 2-line hook in 2 places; `$results` shape (`type/wa_message_id/conversation_id`) is the `excelle-insights/whatsapp` vendor contract |
| 2 | Direct reads/writes to vendor WhatsApp tables | `ai-assistant/src/Services/AiAssistantService.php:73-108,191-222,235-243` (`{waPrefix}_messages`, `{waPrefix}_conversations`, `{waPrefix}_queue`, `ai_status`/`ai_error` columns) | Package assumes the `excelle-insights/whatsapp` schema (`conversation_id`, `wa_message_id`, `session_expires_at`, `contact_phone`). Breaks with any other WhatsApp stack |
| 3 | Direct send via host class | `AiAssistantService.php:217` — `class_exists(Packages\Integrations\Whatsapp\WhatsappApi::class)` → `WhatsappApi::sendTextMessage()` | Hard dependency on MaintainA's wrapper; fallback is an internal queue insert |
| 4 | Direct reads of host business tables | `AiAssistantService.php:357-397` (`companies` table, `APP_NAME`, `AI_WHATSAPP_COMPANY_ID`); `SchemaService.php:22-30,389-408` (hardcoded `ALLOWED_TABLES` = `service_types`, `work_orders`, `employee_records`, … + hardcoded count SQL) | Package "knows" MaintainA's schema; not reusable on a non-garage / non-Excelle system |
| 5 | Host reaches into package internals | `WhatsappController.php:298-354` instantiates `KnowledgeService` and `SchemaService` directly instead of going through `AiAssistantManager` | UI is coupled to package class names; any refactor breaks the host |
| 6 | Partial abstraction already exists | `src/Contracts/SystemContextProviderInterface.php`, `src/Facade/AiAssistantManager.php:21-45` | Good start, but only covers company/customer context + `canAutoReply`. Transport, storage reads, and schema discovery bypass it |
| 7 | Ops coupling | `maintaina/api/composer.json:17` (`dev-main`), `maintaina/Jenkinsfile:18` (`migrate.php`), `WhatsappController.php:183-201` diag checks `ai_whatsapp_*` tables, `app/pages/admin/env-editor.php:136,315` AI tab | Fine to keep, but migrate/diag should call package CLI/facade, not hardcode table list. Diag + env keys must be renamed to `ai_assistant_*` / `AI_ASSISTANT_*` (see §6) |
| 8 | Latent bug to fix during refactor | `src/Support/EnvLoader.php:4` declares `ExcelleInsights\AiWhatsApp\Support` but all callers import `ExcelleInsights\AiAssistant\Support` | Works on macOS (case-insensitive FS), fatal on Linux PSR-4. Fix casing to `AiAssistant` (or `AiAssistant` after rename) |

**Net:** the "AI" is currently `AiAssistantService` + `SchemaService` with MaintainA/WhatsApp-vendor SQL baked in. The interface (`SystemContextProviderInterface`) is too narrow, so the package reaches around it.

---

## 2. Target: black-box core + ports & adapters

```
┌─────────────────────────────────────────────────┐
│ ai-assistant (BLACK BOX — owns ALL AI logic)    │
│  KnowledgeService · SchemaLearner · Reasoner    │
│  (LLM) · Memory/Sessions · Policy/Guardrails    │
│  Own tables only: ai_assistant_knowledge /       │
│   _sessions/_training/_schema/_reference/       │
│   _queue/_jobs (see §6 rename map)              │
│  Talks to outside ONLY via interfaces ↓         │
└──────────────┬──────────────┬─────────┬─────────┘
               │              │         │
   ChannelPort │  ContextPort │ SchemaDiscoveryPort │ LlmPort
               │              │         │
┌──────────────┴──────────────┴─────────┴─────────┐
│ HOST ADAPTER (lives in host, ~50 lines each)    │
│  MaintainA adapter · Other-system adapter …     │
│  Maps host tables/APIs → ports. Owns sending.   │
└─────────────────────────────────────────────────┘
```

Rules:
1. Package **never** `SELECT`s/`INSERT`s a table outside its own `ai_assistant_*` prefix.
2. Package **never** references `whatsapp_*`, `companies`, `service_types`, `WhatsappApi`, `WhatsappService`, any physical host table/column, or any vendor class.
3. Package **never sees a physical table/column name at all** — it only sees logical `dataset/field/label` descriptors supplied by the host (see §3.3).
4. Host **never** instantiates `KnowledgeService`/`SchemaService`/`AiAssistantService` — only `AiEngine` facade + port interfaces + DTOs.
5. Same core serves WhatsApp (any provider), in-app assistant, analysis/diagnosis jobs — only the adapter changes.

---

## 3. Proposed contracts (live 100% inside `ai-assistant/src/Contracts/`)

```php
// 3.1 Any message channel (WhatsApp vendor A/B, SMS, in-app chat)
interface ChannelPort {
  public function sendText(ChannelMessage $to, string $body): SendResult;
  public function sendTemplate(ChannelMessage $to, string $templateId, array $vars): SendResult;
  public function isSessionOpen(ChannelMessage $ctx): bool;
}

// 3.2 Host business context — data as VALUES, never table names
interface ContextPort {
  /** e.g. ['company_name'=>.., 'services'=>[[name,price]..], 'hours'=>..] */
  public function getBusinessContext(string $tenantId): array;
  public function getCustomerContext(string $channelUserKey): array;
  public function canAutoReply(string $conversationKey): bool;
}

// 3.3 Schema learning WITHOUT table knowledge (works with ANY table naming).
// Host describes what the AI may see; package only learns descriptions + values.
interface SchemaDiscoveryPort {
  /** Host returns LOGICAL field descriptors — physical names stay in the adapter. */
  public function describeFields(): array;   // [['dataset'=>'services','field'=>'price','label'=>'Price (KSh)','kind'=>'money'], ...]
  /** Host returns lookup values keyed by LOGICAL dataset. */
  public function lookupValues(string $tenantId, int $limit = 60): array; // ['services'=>[['name'=>'Oil Change','price'=>2500]], 'counts'=>[...]]
  /** Optional live read: host executes a package-proposed constrained query-spec (not raw SQL) and returns rows. Host may refuse. */
  public function runReadSpec(array $spec, int $limit = 20): array; // spec: ['dataset'=>..,'filters'=>..,'count'=>bool]
}

// 3.4 LLM — reuse host key or package key, swappable per deployment
interface LlmPort {
  public function chat(array $messages, string $model, ?float $temp = null): array; // ['content'=>..,'usage'=>..]
  public function embeddings(string $input): array;
}

// 3.5 Single entry point (replaces AiAssistantManager + direct service use)
final class AiEngine {
  public function __construct(LlmPort $llm, ?ContextPort $ctx = null, ?SchemaDiscoveryPort $schema = null, ?PDO $pdo = null);
  public function handleInbound(ChannelEvent $event, ChannelPort $channel): HandleResult; // WhatsApp path
  public function ask(string $tenantId, string $question, array $opts = []): Answer;       // assistant/analysis/diagnosis path
  public function queueJob(string $tenantId, string $kind, array $payload): string;        // async analysis/diagnosis
  public function knowledge(): KnowledgeApi;  // ingest/list/delete/importCsv — replaces TrainingService + direct KnowledgeService use
  public function schema(): SchemaApi;        // sync/list/import — no information_schema in core
}
```

DTOs: `ChannelEvent{tenantId, conversationKey, messageId, channelUserKey, body, language?}`, `ChannelMessage{...}`, `SendResult{ok, error?}`, `HandleResult{status: sent|queued|blocked|failed, reply?}`.

### 3.3a Why this works with ANY table naming (physical → logical mapping)

The package never receives `"service_types"` or `"svc_title"`. The host adapter maps its own
physical schema to a small stable logical vocabulary (`services`, `orders`, `customers`,
`staff`, `branches`, `inventory`, …). Two different apps, two different physical schemas,
identical package view:

```php
// App A (MaintainA): physical service_types(name, default_amount)
// → adapter emits:
['dataset' => 'services', 'field' => 'name',  'label' => 'Service name', 'kind' => 'text']
['dataset' => 'services', 'field' => 'price', 'label' => 'Service price (KSh)', 'kind' => 'money']
// lookupValues('1') → ['services' => [['name' => 'Oil Change', 'price' => 2500], ...]]

// App B (other system): physical garage_services_tbl(svc_title, svc_price, is_deleted)
// → SAME logical shape:
['dataset' => 'services', 'field' => 'name',  'label' => 'Service name', 'kind' => 'text']
['dataset' => 'services', 'field' => 'price', 'label' => 'Service price (KSh)', 'kind' => 'money']
// lookupValues('7') → ['services' => [['name' => 'Brake Pads', 'price' => 9000], ...]]
```

Consequences:
- The LLM prompt is built from `label`s + lookup values only — physical names never appear in prompts, logs, or the package DB.
- `runReadSpec(['dataset'=>'services','filters'=>['name~'=>'oil'],'count'=>false])` is translated to real SQL **inside the host adapter** (App A: `SELECT name, default_amount FROM service_types WHERE …`; App B: `SELECT svc_title, svc_price FROM garage_services_tbl WHERE …`). The package cannot invent table names because it never knows any.
- `describeFields()` allow-list **is** the security boundary: a dataset/field the host doesn't describe is invisible to the AI. Core keeps a default denylist (`password`, `token`, `secret`, `api_key`, `otp`, `bank_account`, `passport`, `kra_pin`, …) as defence-in-depth and strips them from any returned rows.
- Hosts that refuse live reads return `[]` from `runReadSpec` — the AI still answers from knowledge + `lookupValues`.

What changes inside the package:
- `AiAssistantService::onInboundMessages/onInboundMessage` → `AiEngine::handleInbound`. Delete all `{waPrefix}_*` SQL, `ai_status` updates become `HandleResult` + optional `markStatus` callback on `ChannelPort`. Delete `Packages\...\WhatsappApi` reference; sending is `$channel->sendText/sendTemplate`.
- `SchemaService::tables()/columns()/information_schema` → move to a **host-side** `MaintainADiscoveryAdapter` (the only place allowed to touch `information_schema` + MaintainA tables). Core keeps scoring/validation over `describeFields()` output. `ALLOWED_TABLES`/`BLOCKED_COLUMNS` become host-supplied config with safe defaults in core.
- `resolveCompanyId()` / `companies` SQL → deleted; `tenantId` comes in on the `ChannelEvent`/constructor.
- `KnowledgeService`/`TrainingService` stay but are only reachable via `AiEngine::knowledge()`; host controller calls the facade.
- New `ask()`/`queueJob()` reuse the same prompt-builder + knowledge + memory, minus channel send — this is what enables "system assistant / analysis / diagnosis" without WhatsApp.

---

## 4. Host side after refactor (MaintainA example, ~50 lines per adapter, owns all tables)

```php
// api/src/Services/Ai/MaintainaChannelAdapter implements ChannelPort {
//   sendText: Packages\Integrations\Whatsapp\WhatsappApi::sendTextMessage() + insert to whatsapp_messages (existing code moves HERE)
//   isSessionOpen: SELECT session_expires_at FROM whatsapp_conversations ... (moves HERE)
// }
// api/src/Services/Ai/MaintainaContextAdapter implements ContextPort { ... companies/service_types ... }
// api/src/Services/Ai/MaintainaDiscoveryAdapter implements SchemaDiscoveryPort {
//   describeFields(): maps information_schema for service_types/work_orders/... → logical datasets (physical names never leave this file)
//   lookupValues(): SELECT name, default_amount FROM service_types ... → ['services'=>[...]]
//   runReadSpec(): switch ($spec['dataset']) { case 'services': <real SQL> ... } default: return []
// }

// webhook (callback.php + WhatsappController::webhookProcess) shrinks to:
$engine = boot_ai_engine(); // builds AiEngine + MaintainA adapters, one helper
$result = $engine->handleInbound(ChannelEvent::fromWhatsapp($payload, $tenantId), new MaintainAChannelAdapter($pdo));
// diagnosis/assistant reuse, no WhatsApp involved:
$answer = $engine->ask($companyId, "Why are work orders piling up this week?");
```

A different system (different WhatsApp provider, e.g. Twilio/Meta-direct, or no WhatsApp at all) writes its own 3 adapters and reuses the package untouched — including `ask()` for dashboards, CLI diagnosis, cron analysis jobs.

---

## 5. Is it possible? Yes — with these bounds

- **Yes**, full black-box is possible because every current leak ( §1 rows 2–4 ) has a clean port replacement ( §3 ), and §3.3a removes even physical-name knowledge.
- **Constraint 1:** the package can never do "magic" live SQL without host help — `runReadSpec` is deliberately a struct over logical datasets, not raw SQL, so the host enforces allow-lists. Hosts that refuse live reads still get knowledge + lookup-values answers.
- **Constraint 2:** PII/secrets safety is contractual (`describeFields()` must never include them) + core denylist as backup.
- **Constraint 3:** keep backward compat for one release: leave `AiAssistantService::onInboundMessages` as a deprecated shim delegating to `AiEngine` so existing hooks don't break mid-deploy. Same for `AI_WHATSAPP_*` env keys and `ai_whatsapp_*` tables (auto-renamed, see §6).

---

## 6. Rename: `ai_whatsapp_*` → `ai_assistant_*` (required — no longer WhatsApp-only)

### 6.1 Table map (drop old only after data is moved)

| Old (`AI_WHATSAPP_TABLE_PREFIX=ai_whatsapp`) | New (`AI_ASSISTANT_TABLE_PREFIX=ai_assistant`) | Notes |
|---|---|---|
| `ai_whatsapp_knowledge` | `ai_assistant_knowledge` | FAQ/training chunks — `RENAME`, keep `company_id→tenant_id` column rename optional (keep `company_id` + add `tenant_id` alias in code during transition) |
| `ai_whatsapp_sessions` | `ai_assistant_sessions` | Conversation memory — `RENAME` |
| `ai_whatsapp_queue` | `ai_assistant_queue` | Outbound/channel queue — `RENAME`, add `channel` column (`whatsapp|sms|inapp|job`) |
| `ai_whatsapp_training` | `ai_assistant_training` | Self-learned Q&A — `RENAME` |
| `ai_whatsapp_schema` | `ai_assistant_schema` | Learned field descriptors — `RENAME`, add `dataset`/`field`/`label`/`kind` columns; physical `table_name`/`column_name` columns **dropped** (must not store physical names post-refactor) |
| `ai_whatsapp_reference` | `ai_assistant_reference` | Lookup summaries — `RENAME`, key by logical `dataset` instead of `source_table` |
| (new) | `ai_assistant_jobs` | Async analysis/diagnosis jobs for `queueJob()` |

### 6.2 Migration strategy (one Phinx migration in the package)

```sql
-- For each table above, if old exists and new does not:
RENAME TABLE `ai_whatsapp_knowledge` TO `ai_assistant_knowledge`;
-- If neither exists (fresh install): CREATE TABLE `ai_assistant_*` (...) directly.
-- If both exist (partial manual run): copy missing rows by id, then DROP old after verify.
-- Never CREATE ai_whatsapp_* on fresh installs.
```

- Env: new canonical `AI_ASSISTANT_TABLE_PREFIX=ai_assistant`. Code reads `AI_ASSISTANT_TABLE_PREFIX` first, falls back to legacy `AI_WHATSAPP_TABLE_PREFIX` for one release, then the fallback is removed.
- Same fallback pattern for `AI_ASSISTANT_AUTO_REPLY` ← `AI_WHATSAPP_AUTO_REPLY`, `AI_ASSISTANT_FALLBACK_TEMPLATE`, `AI_ASSISTANT_COMPANY_ID`.
- Package namespace: `ExcelleInsights\AiAssistant\…` → `ExcelleInsights\AiAssistant\…` with `class_alias` shims for one release; composer package `excelle-insights/ai-assistant` → `excelle-insights/ai-assistant` (keep old name as abandoned alias if published).
- Host updates in the same release: diag check list (`WhatsappController.php:183`), Jenkins `migrate.php` call, `env-editor.php` AI tab keys, `.env.example` block.

### 6.3 Migration plan (phased, no big-bang)

- [ ] **Phase 0:** fix `EnvLoader` namespace casing; add `AiEngine` facade + `ChannelPort/ContextPort/SchemaDiscoveryPort/LlmPort` + DTOs; keep old classes as `@deprecated` shims. Add `ai_assistant_*` rename migration (old→new `RENAME`, fresh-install creates new only) + `AI_ASSISTANT_*` env keys with legacy fallback.
- [ ] **Phase 1:** extract MaintainA adapters (`Channel/Context/Discovery`) into `maintaina/api/src/Services/Ai/`; move all `whatsapp_*` + `companies` SQL there; webhook files call only `AiEngine::handleInbound`.
- [ ] **Phase 2:** move `information_schema` + `ALLOWED_TABLES`/counts out of core; core learns only via logical `describeFields()/lookupValues()`; drop physical-name columns from `ai_assistant_schema/reference`.
- [ ] **Phase 3:** switch `WhatsappController` knowledge/schema endpoints to `AiEngine::knowledge()/schema()`; expose `ask()` endpoint (`POST /api/ai/ask`) for in-app assistant + `queueJob()` worker for analysis/diagnosis. Update diag/env-editor to new names.
- [ ] **Phase 4:** prove portability — second adapter (stub channel + different physical schema mapped to same logical datasets) in `ai-assistant/examples/GenericAdapter/` with a smoke test; remove deprecated shims, legacy env fallback, and any remaining `ai_whatsapp_*` support; tag `v2`.

---

## 7. Acceptance criteria

1. `grep -r "whatsapp_\|FROM companies\|information_schema\|WhatsappApi" ai-assistant/src` returns nothing; `grep -r "AiWhatsapp\|ai-whatsapp" ai-assistant/src` returns nothing (rename done; excluding `examples/` + shims before Phase 4).
2. No physical host table/column string appears in package prompts, logs, or `ai_assistant_*` rows (spot-check `ai_assistant_schema`/`_reference`: only `dataset/field/label`).
3. Fresh host integration = implement 3 interfaces + `boot_ai_engine()` + webhook call; no package edits. A second host with different physical table names maps to the same logical datasets with zero package changes.
4. `ask()` works with `ChannelPort` stubbed out (proves non-WhatsApp use: assistant/analysis/diagnosis).
5. Existing MaintainA flow (inbound → knowledge → reply/queue, training CSV, schema sync) passes end-to-end on Phase 1 shims and again on Phase 4 core, with data preserved through the §6.1 rename.

---

## 8. Effort sketch

| Phase | Touch | Rough size |
|-------|-------|------------|
| 0 contracts + facade + rename migration | package only | M |
| 1 MaintainA adapters + webhook cutover | host mostly | M |
| 2 logical-schema extraction | both | M |
| 3 `ask()`/jobs + controller cutover | both | S–M |
| 4 generic example + shim/removal | package | S |
