# 03 — Knowledge base & training (step by step)

Train once, use everywhere: WhatsApp replies, the in-app guide, diagnosis
support and analysis all read the same `{prefix}_knowledge` table.

## 1. What a knowledge row is

`{prefix}_knowledge`: `company_id | category | title | content | embedding | source | status`

- `category` groups rows (`Working Hours`, `Company`, `Contact`, `How-To`, …).
- `status = 'active'` rows are searchable; anything else is ignored.
- `embedding` is filled automatically on ingest (used for future vector search;
  current search is keyword + recency).

## 2. Add knowledge — CSV import (recommended for bulk)

CSV header (category optional):

```csv
category,title,content
"Working Hours","Opening hours","We are open Monday to Friday 8:00am - 5:00pm and Saturday 8:00am - 12:00pm."
"Company","Our mission","To provide reliable, high-quality services to our customers."
"How-To","How to create a job card","Go to Job Cards > Add Job Card, select the vehicle and customer, add services and parts, then Save."
"Contact","Phone number","You can reach us on +254 7XX XXX XXX."
```

Steps (MaintainA):

1. `GET /api/integrations/whatsapp/ai-knowledge/template` → download starter CSV.
2. Fill it in (one row per fact / how-to).
3. `POST /api/integrations/whatsapp/ai-knowledge/import` with `{ "csv": "<content>" }`.
4. `GET /api/integrations/whatsapp/ai-knowledge` → confirm rows listed.

Or in PHP:

```php
$training = new \ExcelleInsights\AiAssistant\Services\TrainingService($pdo, $knowledge);
$training->ingest($companyId, 'Return Policy', '30 days...', 'manual');
$knowledge->importCsv($companyId, $csvText); // ['inserted'=>n,'skipped'=>n,'errors'=>[]]
```

## 3. What to train (minimum viable set)

| Category | Examples |
|----------|----------|
| Working Hours | Opening hours, holidays |
| Company | Mission, vision, values, about us |
| Contact | Location, phone, WhatsApp hours |
| Services & Pricing | Each service + price (also synced to `{prefix}_reference` via schema sync) |
| **How-To (system guide)** | **Every task users ask about: create job card, add vehicle, invoice, permissions, settings — this powers the in-app "AI Guide" icon (see `05-one-ai-router.md`)** |
| Policies | Return, warranty, payment terms |

## 4. Maintain the base

- Edit: add a corrected row, delete the stale one
  (`DELETE /api/integrations/whatsapp/ai-knowledge/{id}`).
- The AI also self-learns: every answered exchange is stored in
  `{prefix}_training` and reused as "PREVIOUS Q&A" context.
- Multi-tenant: every row carries `company_id`; imports always scope to the
  caller's company.

## 5. Verify

Ask the result through any channel (`05-one-ai-router.md`): a pricing question
should quote the trained price; a "how do I …" question should return the
How-To steps — never a guess.

## 6. Endpoints for any application (framework-agnostic handler)

The package ships no routes — HTTP stays in the host. But every app gets the
same 5-lines-per-route handler: `src/Http/KnowledgeApi.php` (plain arrays
in/out, no framework, no auth; tenancy = the `$tenantId` you pass, `0` when
you have no company model).

```php
use ExcelleInsights\AiAssistant\Http\KnowledgeApi;

$training = new KnowledgeApi($pdo); // + optional 2nd arg: any LlmClientInterface

// Laravel / Slim / raw PHP — same calls:
$training->listKnowledge($tenantId);                    // ['items' => [...]]
$training->addKnowledge($tenantId, $title, $content, $category);
$training->importCsv($tenantId, $csvText);              // ['inserted','skipped','errors']
$training->deleteKnowledge($id, $tenantId);             // ['deleted' => bool]
$training->csvTemplate();                               // ['csv','filename']
$training->syncSchema($tenantId);                       // schema + reference + counts
$training->listSchema();
$training->schemaTemplate();
$training->importSchemaDescriptions($csv);
$training->deleteSchemaEntry($id);
```

Raw-PHP example (any frontend posts JSON to it):

```php
$api = new KnowledgeApi($pdo);
$in  = json_decode(file_get_contents('php://input'), true);
header('Content-Type: application/json');
echo json_encode($api->importCsv((int)($in['tenant_id'] ?? 0), (string)($in['csv'] ?? '')));
//Serialise + auth + tenant resolution are yours; training logic is the package's.
```

MaintainA already exposes these via `WhatsappController`
(`…/ai-knowledge`, `…/ai-knowledge/import|template|{id}`,
`…/ai-schema/sync|…`) and may delegate to `KnowledgeApi` in a later cleanup —
behaviour is identical either way.
