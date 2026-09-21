# 05 — One AI for all: guide, diagnosis, analysis (+ WhatsApp)

There is ONE assistant. The system decides per question which brain to use.
Users (and WhatsApp customers) just ask — routing is automatic, with an
explicit `mode` override when the caller already knows.

This is a **pattern**, not a package endpoint: the package supplies the
building blocks (knowledge search, LLM interface, memory tables); **your app**
owns the router, the branches, and the HTTP endpoint — in any framework, for
any frontend (web, mobile, `curl`). MaintainA's `POST /api/ai/ask` below is
the reference implementation, not part of this package.

## 1. The three brains

| Intent | Brain (LLM + …) | Reads | Writes |
|--------|-----------------|-------|--------|
| `guide` | LLM + **knowledge base** (doc 03) | `{prefix}_knowledge`, `{prefix}_training` | nothing (answers only) |
| `diagnosis` | LLM + **domain context** (MaintainA: assets, last 3 service records, OBD codes) | host domain tables via adapter | host result table (MaintainA: `ai_diagnostic_results`) |
| `analysis` | LLM + **live business data** (MaintainA: jobs, revenue, expenses, inventory, fleet, customers) | host reports tables via adapter | nothing |

WhatsApp auto-reply (doc 04) is the same core on the messaging channel:
knowledge + reference data + conversation memory.

## 2. The router (deterministic, no LLM call)

Reference: `AssistantRouter::route($message, $mode)` in the host
(MaintainA: `api/src/Services/AssistantRouter.php`). Copy the idea with YOUR
domain keywords:

1. `mode` = `guide|diagnosis|analysis` → use it (caller knows best:
   the guide page sends `guide`, the diagnose page sends `diagnosis`).
2. `mode` = `auto`/empty → keyword scoring:
   - diagnosis words: `obd, diagnos…, fault, symptom, noise, smoke, overheat,
     leak, vibration, misfire, stall, brake, clutch, gear…, engine,
     warning light, battery, radiator, coolant, knocking…`
   - analysis words: `revenue, profit, expense, forecast, trend, analys…,
     report, top customer, performance, how many, total jobs, mechanic…`
   - guide words (`how do I…`, `where is…`, `steps`, `button`, `settings`,
     `permission`…) win ties — *"how do I add a service type"* is a guide
     question even though it contains "service".
   - no match → `guide` (knowledge covers the most ground).

## 3. Reference endpoint — MaintainA `POST /api/ai/ask` (auth)

Your app exposes its own equivalent. Contract (plain JSON, any frontend):

```jsonc
// request (union of fields; send what you have)
{
  "message": "how do I create a job card?",
  "mode": "auto",                 // auto | guide | diagnosis | analysis
  "session_id": "guide_abc123",   // optional chat continuity (guide)
  "customer_id": 12,              // optional (guide/diagnosis context)
  "vehicle_id": 45,               // diagnosis: domain context
  "symptoms": "overheats uphill", // diagnosis: falls back to message (min 10 chars)
  "obd_codes": ["P0300"],
  "engine_behaviour": "rough idle",
  "from": "2026-08-01", "to": "2026-09-21",  // analysis: date range
  "history": [{ "role": "user", "content": "…" }]  // last turns (guide/analysis)
}
```

```jsonc
// response
{ "status": 1, "data": {
  "intent": "guide",              // the brain the system chose
  "reply": "Go to Job Cards > Add…",
  "sources": ["How to create a job card"]  // guide: knowledge titles used
}}
```

- `intent: diagnosis` returns the full structured diagnosis
  (`probable_causes, recommended_inspections, severity_level,
  suggested_repairs, maintenance_category, diagnostic_id, confidence_level`).
- `intent: analysis` returns `{ reply, data_fetched }` (numbers come from real
  DB data, charts/tables embedded as fenced JSON blocks when asked).

Copy-paste curl (same for Postman: `POST`, Bearer token, JSON body):

```bash
TOKEN=your_jwt_here
BASE=https://your-domain/api

# Health first — all green before anything else
curl -s -H "Authorization: Bearer $TOKEN" $BASE/integrations/whatsapp/ai-diagnostics

# Guide
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"message":"How do I create a job card?","mode":"auto"}' $BASE/ai/ask

# Diagnosis (structured page flow = same endpoint, explicit mode + fields)
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"message":"overheats uphill, coolant leaking","mode":"diagnosis","vehicle_id":45,"obd_codes":["P0300"]}' \
  $BASE/ai/ask

# Analysis (date range optional; defaults to month-to-date)
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"message":"Revenue last month by mechanic?","mode":"analysis","from":"2026-08-01","to":"2026-09-21"}' \
  $BASE/ai/ask

# Train one How-To row, then re-ask the guide question above
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"csv":"category,title,content\n\"How-To\",\"How to create a job card\",\"Go to Job Cards > Add Job Card, pick vehicle and customer, add services, Save.\""}' \
  $BASE/integrations/whatsapp/ai-knowledge/import
```

Generic-app mapping (no MaintainA code needed):

| Branch | Package building blocks you reuse |
|--------|-----------------------------------|
| guide | `KnowledgeService::search($tenantId, $q, 5)` + `LlmClientInterface::chat()` with a guide system prompt; return `{intent, reply, sources}` |
| diagnosis | your domain-context query + `LlmClientInterface::chat()` with a diagnosis prompt; persist to your result table |
| analysis | your reporting queries + `LlmClientInterface::chat()` with an analyst prompt |

## 4. Who calls it (MaintainA wiring)

| Caller | Sends | Gets |
|--------|-------|------|
| WhatsApp hook | inbound text (+ receiver context) | customer reply (doc 04 path) |
| **AI Guide icon/page** (`app/pages/ai/guide.php`, sidebar after WhatsApp) | `message`, `mode: guide|auto` | how-to answers from knowledge |
| Diagnose page / widget | `message/symptoms` + `vehicle_id` | structured LLM diagnosis |
| Analytics page / manager chat | `message/question` + range | data-grounded analysis |
| Any future channel / frontend | same contract | same brains |

## 5. Add it to a new app (checklist)

1. Copy the router idea: `route(text, mode)` with YOUR domain keywords (§2).
2. Implement the three branches against YOUR tables (knowledge table,
   domain tables, reporting tables) — reuse the package's
   `KnowledgeService::search()` and `LlmFactory::make()` for the guide branch (§3 table).
3. Expose one endpoint + one UI entry point in YOUR stack (any framework,
   any frontend — the contract is plain JSON). Train How-To rows (doc 03 §3)
   before launch, or the guide brain has nothing to say.
