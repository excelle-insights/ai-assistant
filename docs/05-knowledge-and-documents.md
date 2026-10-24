# 05 — Knowledge & documents

Two different things make the assistant sound like it knows your business:

- **Knowledge** — short, curated facts. "Returns are 30 days", "discounts above 10%
  need a manager", "the Githurai yard is closed Saturdays". Stored in
  `{prefix}_knowledge`, one row per fact, editable by a human.
- **Documents** — long files someone uploaded. A contract, a price list, a policy
  PDF. Stored as chunks in `{prefix}_documents` + `{prefix}_document_chunks`, and
  optionally mirrored into knowledge.

Both are *retrieved* — the assistant quotes what it finds and cites where it came
from. Neither is a vector database: they are ordinary rows in your database, with
optional embeddings, which is what makes them reviewable and portable.

- [Knowledge](#knowledge)
  - [Teach one fact](#teach-one-fact)
  - [Bulk import](#bulk-import)
  - [Scope, status and review](#scope-status-and-review)
  - [How retrieval works](#how-retrieval-works)
  - [Searching and statistics](#searching-and-statistics)
  - [Learning from answered questions](#learning-from-answered-questions)
- [Documents](#documents)
  - [Upload](#upload)
  - [What the package needs from you](#what-the-package-needs-from-you)
  - [Extraction, chunking and OCR](#extraction-chunking-and-ocr)
  - [Search, reindex, delete](#search-reindex-delete)
  - [Document answers in chat](#document-answers-in-chat)
- [Citations](#citations)
- [The narrow training API](#the-narrow-training-api)
- [Troubleshooting](#troubleshooting)

---

## Knowledge

`{prefix}_knowledge`: `company_id`, `title`, `content`, `category`, `embedding`,
`source`, `status`, `content_hash`, `scope`, `scope_key`, `doc_id`, `token_count`,
`metadata_json`, `updated_by`, `pinned`, `created_at`, `updated_at`.

`company_id` is a scoping integer, not a foreign key — an app with no company model
uses `0` everywhere.

### Teach one fact

```php
$api->handle('knowledge.add', [
    'title'    => 'Return window',
    'content'  => 'Items may be returned within 30 days of delivery with proof of purchase. Perishables are not returnable.',
    'category' => 'Policies',
    'source'   => 'manual',
    'tags'     => ['returns'],
    'roles'    => ['support'],
    'effective_from' => '2026-01-01',
    'effective_until' => null,
    'updated_by' => 'ops@acme',
    'metadata' => ['approved_by' => 'legal'],
]);
// → ['ok' => true, 'entry' => ['id' => 41, 'created' => true, 'changed' => true]]
```

`title` and `content` are required — an empty either gives
`ok:false, error:'Knowledge needs both a title and the content to remember.'`.
Everything else is optional and only the listed keys are read: `tags`, `roles`,
`effective_from`, `effective_until`, `updated_by`, `metadata`, `language`,
`entity_type`, `entity_id` (plus `category`, `source`, `scope`, `scope_key`,
`doc_id`, `pinned`, `status`, which `ingestRow` accepts directly).

It is **idempotent**, which is what lets you re-run a seeder without filling the
base with duplicates:

| Situation | Result |
|-----------|--------|
| Same normalised title *and* content already exists | `['created'=>false,'changed'=>false]`, existing id |
| Same title, different content | that row is updated, `version` bumped → `['created'=>false,'changed'=>true]` |
| New text under a title that already exists elsewhere | new row (dedupe matches on `sha256(normalise(title)."\n".normalise(content))`) |

Dedupe scans `knowledge.dedupe_scan` (2000) recent rows.

### Bulk import

Download the template, fill it, upload it:

```php
$api->knowledgeTemplate();
// → ['ok'=>true,'csv'=>"category,title,content\n\"Policies\",\"Returns\",…",
//     'filename'=>'ai-knowledge-template.csv']

$api->handle('knowledge.import', [
    'file'   => $csvText,          // or ['content'=>…] / ['tmp_path'=>…]
    'source' => 'csv',             // recorded in the `source` column
]);
// → ['ok'=>true,'inserted'=>34,'updated'=>3,'unchanged'=>12,'skipped'=>0,'errors'=>[]]
```

Headers: `title` and `content` are mandatory; `category` and `scope` are optional
and extra columns are ignored. A row with blank title or content is `skipped`, not
an error. Empty input gives `errors: ['CSV is empty']`; missing headings gives
`errors: ['CSV must have "title" and "content" columns (category is optional)']`.

Write the entries as *answers*, not notes: "We are open Monday to Friday 08:00 to
17:00" beats "hours: TBD". One fact per row — a row that answers three questions
retrieves worse than three rows.

### Scope, status and review

| `status` | Meaning |
|----------|---------|
| `active` | retrievable — the default |
| `inactive` | kept, never retrieved |
| `review` | harvested automatically, waiting for a human |
| `archived` | superseded; history keeps citing it |

| `scope` | Retrieval condition |
|---------|---------------------|
| `global` | every user of that tenant (default) |
| `personal` | only the owning `user_id` |
| `role` | only users holding the entry's `roles` |
| `scope_key` | only requests whose context carries the matching `scope_key` (project, branch, tenant-code) |

Turn an entry off instead of deleting it — `setActiveKnowledge()` keeps the audit
trail's citations resolvable:

```php
$api->setActiveKnowledge(41, $tenantId, false);
$api->updateKnowledge(41, $tenantId, ['title' => 'Return window (2026 policy)', 'category' => 'Policies']);
$api->deleteKnowledge(41, $tenantId);   // hard delete, admin only
```

`update()` accepts `title`, `category`, `source`, `status`, `scope`, `scope_key`,
`doc_id`, `updated_by` (truncated to their column widths) plus `content`, which
re-embeds and re-hashes. An id from another tenant simply is not found — no leak, no
error.

### How retrieval works

`knowledge.retrieval` = `hybrid` (default) | `keyword` | `vector`:

- Each stored row carries an `embedding` when your provider exposes an embeddings
  endpoint (embedded on write; `text-embedding-3-small` by default).
- A query is embedded once and scored against the stored vectors; the rest of the
  score is keyword coverage of the query in the title/content.
  `knowledge.vector_weight` (0.6) is vector's share of the blend.
- **No embeddings is not an error.** A row with no vector is scored lexically, so
  the package works unchanged against a gateway with no embeddings endpoint.
- Below `knowledge.min_similarity` (0.2) nothing is returned. `knowledge.top_k` (5)
  caps the hits, `knowledge.scan_limit` (500) caps rows read per search.
- `knowledge.fallback_latest` (default `false`) would append the newest entries when
  nothing matches. Leave it off: unrelated facts in a prompt produce confident
  wrong answers.
- `pinned` rows are preferred; `status='active'` is required to be retrieved at all;
  the `scope`/`roles` filter is applied against the `UserContext` you pass.

### Searching and statistics

```php
$api->handle('knowledge.search', ['query' => 'can I return opened goods', 'k' => 3]);
// → ['ok'=>true,'hits'=>[['id'=>41,'type'=>'knowledge','title'=>'Return window',
//      'content'=>'Items may be returned…','category'=>'Policies','source'=>'csv',
//      'scope'=>'global','status'=>'active','pinned'=>0,'version'=>2,
//      'score'=>0.71,'vector_score'=>0.8,'lexical_score'=>0.58,
//      'retrieval'=>'hybrid','fallback'=>false,'updated_at'=>'2026-10-24 09:12:03'], …]]

$api->handle('knowledge.list', ['limit' => 500]);   // newest first; embeddings are never listed
$api->getKnowledge(41, $tenantId);                  // one row, or code 'not_found'
$api->knowledgeStats($tenantId);
// stats: total, active, inactive, embedded, tokens, by_status, by_category, by_source, last_updated_at
```

`embedded / total` is the number to watch — if it is 0, hybrid retrieval is silently
lexical and re-run the seeder once your provider has embeddings, or accept keyword.

### Learning from answered questions

`knowledge.self_training` (`true`) lets the assistant store an exchange it answered
well, tagged `source='feedback'` or `'auto'` and usually `status='review'` so a human
publishes it. `feedbackToKnowledge()` is how a thumbs-up becomes a fact:

```php
$api->feedbackToKnowledge($feedbackId, $tenantId);
// inserts scope='global', status='active', source='feedback'
```

Review `status='review'` rows weekly. Auto-learning without review is how an
assistant learns your bugs.

## Documents

### Upload

```php
$api->handle('document.upload', [
    'file' => $uploadedFile,        // array (tmp_path/name/mime/size/content) or a file object
    'meta' => [
        'title'       => 'Supplier agreement 2026',
        'category'    => 'Contracts',
        'scope'       => 'global',      // global | personal | role | scope_key
        'scope_key'   => null,
        'roles'       => ['legal'],     // written into acl_json and enforced on search
        'user_ids'    => [],
        'entity_type' => 'supplier',    // attach the file to one record in YOUR app
        'entity_id'   => 'SUP-19',
        'language'    => 'en',
        'labels'      => ['signed'],
        'source'      => 'upload',
        'expires_at'  => '2027-10-01',
        'on_duplicate' => 'reuse',      // or 'insert'
    ],
], );
// → ['ok'=>true,'document'=>['id'=>'9b1c…','filename'=>'agreement.pdf','status'=>'indexed',
//     'chunk_count'=>14,'pages'=>3,'mime'=>'application/pdf','scope'=>'global',…]]
```

Statuses: `uploaded` → `indexed`, or `empty` (nothing readable), `failed`
(extractor crashed), `needs_extractor` (type the package cannot read).
Deduplication is by `sha256` within the tenant: an identical file already `indexed`
returns the existing row (`reuse`) unless `on_duplicate => 'insert'`. A previous
attempt that produced nothing is **retried in place** under the same id, so the list
does not fill up with corpses.

`documents.allowed_mime` and `limits.max_upload_mb` (25) are enforced first; a
rejected upload throws with the guard's message, surfaced as
`ok:false, error:'…'` by the envelope.

### What the package needs from you

The package stores **text and metadata**, never bytes. Bytes go to your storage:

```php
use ExcelleInsights\AiAssistant\Contracts\DocumentStoreInterface;

final class MyDocumentStore implements DocumentStoreInterface
{
    public function put(UploadedFile $file, string $storageKey): string   // persist bytes, return the key
    public function read(string $storageKey): ?string                     // bytes, for reindex
    public function exists(string $storageKey): bool
    public function delete(string $storageKey): bool
    public function localPath(string $storageKey): ?string                // path/stream the extractor can read
}
```

Register it as `services['document_store']`. Without a store:

- upload still works when the file carries `content` or a `storage_key`;
- **`reindex` cannot work**, and says so: "the package never stores upload bytes
  itself, so hand DocumentService a DocumentStoreInterface that can read the
  storage key, or ingest the file again."

Chunk text always lives in `{prefix}_document_chunks` (`document_id`, `chunk_no`,
`page_from`, `page_to`, `content`, `token_count`, `embedding`, `metadata_json`,
`company_id`), so a search never touches your object storage.

### Extraction, chunking and OCR

Out of the box: plain text, markdown, CSV, PDF (poppler's `pdftotext` found on
`PATH`, or `smalot/pdfparser` if installed), DOCX and XLSX (their XML parts), and
PNG/JPEG **only** if `documents.ocr_binary` names a tesseract. The package will not
guess a binary and shell out to it.

```php
'documents' => [
    'ocr_binary'  => '/usr/bin/tesseract',   // disabled until you set this
    'ocr_args'    => '-l eng',
    'ocr_stdout'  => false,                  // true = read stdout instead of a temp file
    'pdf_binary'  => '',                     // set only if pdftotext is off PATH
    'extractors'  => [\App\Ai\ZippedDocxExtractor::class],  // your TextExtractorInterface, tried FIRST
],
```

Chunking: `documents.chunk_tokens` (800) with `chunk_overlap` (100), capped at
`max_chunks` (400) per document. Each chunk's metadata records `section`,
`line_from`/`line_to`, `start_offset`/`end_offset`, `filename`, `extractor`, and its
id is `"{document_id}:{chunk_no}"`.

`documents.mirror_to_knowledge` (`true`) additionally publishes each chunk into
`{prefix}_knowledge` (up to `mirror_chars` = 1500 per chunk, `doc_id` set to the
document) so document text is found by ordinary knowledge retrieval too. Set it
`false` if you want documents to be a separate corpus you search explicitly.

### Search, reindex, delete

```php
$api->handle('document.search', ['query' => 'termination notice period', 'k' => 5]);
// hits carry: document_id, chunk_id, chunk_no, content, title, source, page, section,
//             token_count, score, retrieval, status

$api->searchDocuments($tenantId, 'termination', 5, [
    'entity_type' => 'supplier', 'entity_id' => 'SUP-19',   // narrow to one record
    'document_ids' => ['9b1c…'], 'scope' => 'global', 'status' => 'indexed',
    'viewer' => $user,                                       // applies acl_json roles/users
]);

$api->reindexDocument($tenantId, '9b1c…');   // rebuild chunks after you install an extractor
$api->documentChunks('9b1c…', $tenantId);    // inspect what was actually indexed
$api->handle('document.list', ['limit' => 100]);
$api->deleteDocument('9b1c…', $tenantId);    // rows + chunks; bytes stay yours
```

Reindexing is the documented fix for `needs_extractor`: install the extractor,
re-run, the same document id gets its chunks.

### Document answers in chat

`ask()` routes a question to documents when the intent resolves to `document` — the
chunk search runs, and the retrieved text is pasted into the prompt as grounding with
citations. Two ways to influence it:

- `handle('chat', [… 'options' => ['intent' => 'document']])` forces it.
- `services['hooks']['documents']` — `fn (UserContext $who, string $query, int $k): array`
  — returns your own hits (drive a vector store, an S3-backed index, a per-project
  filter) and the package uses them instead of `{prefix}_document_chunks`.

## Citations

Every grounded answer carries up to **6** citations, so a UI has something to show
for "how do you know":

```json
{"type":"document","label":"Supplier agreement 2026","ref":"9b1c…","detail":{"score":0.74,"page":2}}
```

| `type` | Comes from |
|--------|------------|
| `knowledge` | a `{prefix}_knowledge` row |
| `document` | a chunk of an uploaded file (hit `type` is `document`/`chunk`) |
| `record` | a row the answer references in your app (an id + label) |
| `query` | the SQL/data request that produced a number |
| `schema` | a catalog entry |

`ref` and `detail` are omitted when empty. In chat channels that cannot render them,
`channels.whatsapp.show_sources` appends a plain "Sources: …" line instead.

## The narrow training API

`Http\KnowledgeApi` is a second, smaller surface for hosts that only want admin
endpoints for training data — knowledge list/add/import/delete/template, schema
sync/list/template/import/delete-entry. Plain arrays in and out, no `ok`/`error`
envelope, no host interfaces, and it takes a `PDO` plus an optional LLM client:

```php
$training = new \ExcelleInsights\AiAssistant\Http\KnowledgeApi($pdo, $llm);
$training->addKnowledge($tenantId, 'Return window', '30 days with proof of purchase.', 'Policies');
$training->importCsv($tenantId, $csv);
$training->syncSchema($tenantId);
```

Prefer `AssistantApi`'s `knowledge.*` / `schema.*` actions in new installs: they are
the same operations with the table policy, the audit trail and scoped error codes
applied.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Answers ignore facts you added | `status` not `active`, or a `scope`/`roles` filter excludes the viewer | `knowledge.list` and check both |
| Retrieval feels random | no embeddings stored (`stats.embedded` is 0) so it is keyword-only | add an embeddings model, re-import (updates re-embed) |
| Wrong fact wins | two entries overlap and neither is pinned | merge them; `pinned` the correct one |
| `nothing_found` on a document question | status `needs_extractor` / `empty` | `document.list`, then install the extractor and `reindexDocument` |
| Reindex says it cannot read bytes | no `document_store` registered | register one with `read`/`localPath`, or re-upload |
| PDFs index to zero chunks | `pdftotext` not on `PATH`, parser not installed, or a scanned PDF | install poppler, or set `documents.pdf_binary`; scanned = set `ocr_binary` |
| Knowledge rows duplicating on reseed | titles differ in whitespace/case beyond normalisation, or dedupe scan window too small | raise `knowledge.dedupe_scan`; import with stable titles |
| `inserted: 0, errors: ['CSV must have "title" and "content" columns…']` | header names differ | rename the headers, or map them before uploading |

**Next:** [06 — Data, charts & visualisation](06-data-and-charts.md)
