# 04 — Schema catalog & training

The assistant answers questions about *your* tables only if it knows what they
mean. `amount` — is it the order total, the discount, or a line-item price? A
column called `status` in a hospital app and the same name in a logistics app mean
different things entirely.

This package's answer is a **catalog you own**: `{prefix}_schema`, one row per
column, with a `description` written by a human. Every prompt that asks the model to
read your data carries the relevant slice of that catalog. The workflow is:

1. `schema.sync` — learn the structure (names, types, comments) automatically.
2. Download the template CSV, fill in what each column means, import it.
3. Re-sync whenever your app's schema changes; your wording survives.

Training from a **SQL dump** or a **second database connection** is covered below —
that is how you feed the assistant an uploaded `.sql` file, or a staging/analytics
database, without pointing it at production.

- [The catalog table](#the-catalog-table)
- [1. Learn the structure](#1-learn-the-structure)
- [2. Write the descriptions](#2-write-the-descriptions)
- [3. Train from a SQL dump](#3-train-from-a-sql-dump)
- [4. Train from another connection](#4-train-from-another-connection)
- [5. Full one-call training](#5-full-one-call-training)
- [How the catalog reaches the model](#how-the-catalog-reaches-the-model)
- [Which wording wins](#which-wording-wins)
- [Making the assistant useful, not just correct](#making-the-assistant-useful-not-just-correct)
- [Troubleshooting](#troubleshooting)

---

## The catalog table

`{prefix}_schema` (created by the package's migrations; no foreign keys into your
tables):

| Column | What it holds |
|--------|---------------|
| `table_name`, `column_name` | the pair, unique together. A row with an empty `column_name` describes the **table** itself |
| `data_type` | as reported by the database |
| `description` | plain-language meaning — the part that matters |
| `description_source` | `user`, `import`, `dump`, `comment`, `auto` — who wrote the description |
| `label` / `table_label` | presentation name ("Order total", "Customers") |
| `sensitivity` | `secret`, `pii`, `internal` are derived from the column name and the policy; `public` and `financial` are ones you write. All of them travel to the model as a warning |
| `is_measure` | 1 = a number worth aggregating, so `sum()` and averages are offered |
| `format` | `date`, `datetime`, `time`, `number`, `money`, `percent`, `bool`, `json`, `text` — how to render it |
| `aliases` | other words your users use for it (`total,ticket value,bill`) |
| `created_at`, `updated_at` | timestamps |

Unknown columns in the CSV are ignored, and the writer only sets fields that
actually exist in your table — a catalog created by an older migration simply gets
fewer columns filled.

## 1. Learn the structure

```php
$api->handle('schema.sync');
// → ['ok' => true, 'schema' => ['added'=>142,'updated'=>3,'unchanged'=>0,'total'=>145,'pruned'=>0,'tables'=>11]]
```

What it reads: `information_schema.tables`, `columns` and `key_column_usage` on the
PDO you handed the package. It writes only tables `schema_policy` allows, so your
`blocked_tables` and `allowed_tables` decide the catalog's scope as well as the
query scope. With `mode = all` a table created after the last sync appears on the
next one, and new columns on existing tables are added the same way. Turning on
`schema.prune_stale` removes rows for tables or columns that no longer exist.

| `options` key | Effect |
|---------------|--------|
| `tables` | array of table names — sync only these (case-insensitive) |
| `prune` | delete catalog rows whose table/column no longer exists; defaults to `schema.prune_stale` (`false`) |
| `sample_rows` | rows read per table to observe the data; defaults to `schema.sample_rows` (`25`), `0` disables sampling |

A sync also reads a handful of values per text column (`schema.sample_rows`) and
appends what it observes to the description: `Stored as text dates (format
dd-mm-yyyy).`, `Values seen: NOTPAID, PAID.`, or `Values are encrypted; read them
through a decrypting dataset expression.` Those facts are what let the model
write `STR_TO_DATE(col, '%d-%m-%Y')` or know a state column's real values. They
are added even with `schema.auto_describe` off — an observation is not a guess.
Rows whose description came from a person keep their labels, formats and measure
flags on later syncs, so a nightly sync never undoes your curation.

```php
$api->handle('schema.sync', ['options' => ['tables' => ['orders', 'order_lines'], 'prune' => true]]);
```

`schema.skip_tables` and the credential-column floor in `SchemaPolicy` apply here
too, so secrets never enter the catalog in the first place. If your server hides
`information_schema` (some shared hosts), the template and the description form
fall back to what the catalog already knows instead of erroring — import a dump
(section 3) to bootstrap it.

Read it back:

```php
$api->handle('schema.list', ['limit' => 1000]);   // raw rows, ordered by table then column
$api->schemaCatalog();                             // table → label/description/columns (policy-filtered)
$api->describeTable('orders');                     // the exact text the model sees for one table
$api->searchSchema('invoice due date');            // ["orders.due_date: When the invoice must be paid"]
```

## 2. Write the descriptions

This is the step that turns a generic assistant into one that understands your app.

**a. Get the template** — every allowed column, one row each, pre-filled with the
best guess and any database comment so your team only edits what is wrong:

```php
$file = $api->schemaTemplate();
// → ['ok' => true, 'csv' => "table,column,data_type,description,label,sensitivity,is_measure,format,aliases\n…",
//     'filename' => 'ai-schema-template.csv']
```

Header, verbatim:

```
table,column,data_type,description,label,sensitivity,is_measure,format,aliases
```

Serve that string from an admin page, let people edit it in a spreadsheet, and you
have a review process with no UI work.

**b. Import it back:**

```php
$api->handle('schema.import-csv', [
    'file' => $uploadedSqlFile,          // text, an array with tmp_path/content, or a Symfony-style object
    'options' => ['description_source' => 'import'],   // default 'import'
]);
// → ['ok' => true, 'updated' => 18, 'created' => 2, 'skipped' => 40, 'errors' => []]
```

Accepted headings (first match wins, so a dump of your own ORM's metadata works):

| Field | Accepted headers |
|-------|------------------|
| table | `table`, `table_name`, `tablename` |
| column | `column`, `column_name`, `field` |
| description | `description`, `meaning`, `comment` |
| data type | `data_type`, `type` |
| label / table label | `label`, `table_label` |
| sensitivity | `sensitivity` |
| is_measure | `is_measure`, `measure` |
| format | `format` |
| aliases | `aliases`, `alias` |

A row is **skipped** — not an error — when the table or description is blank, when
the table is one of the package's own or is blocked, or when the column fails the
policy. `table`, `column` and `description` headings are mandatory; without them
you get `errors: ['CSV needs "table", "column" and "description" headings']`. An
empty `column` means a table-level description.

**c. Or fix one column at a time** — this is what a "what does this field mean?"
form in your admin panel should call:

```php
$api->handle('schema.save', [
    'table' => 'orders', 'column' => 'amount',
    'description' => 'Total payable in KSh after discount and before VAT.',
    'meta' => ['label' => 'Order total', 'is_measure' => 1, 'format' => 'money',
               'aliases' => 'total,bill,value', 'sensitivity' => 'financial'],
]);
```

`meta` accepts `label`, `table_label`, `sensitivity`, `is_measure`, `format`,
`aliases`, `data_type`. Saving with `description => null` removes the description.
Writes from this path carry `description_source = 'user'`, the strongest source.

## 3. Train from a SQL dump

An uploaded `.sql` file teaches the assistant structure without giving it a
connection to that database. Useful when the data lives in a client's system, a
staging box you cannot reach from the app, or before you ever connect.

```php
$api->handle('schema.import-dump', [
    'file' => ['tmp_path' => '/tmp/uploaded.sql', 'name' => 'schema.sql'],
    'options' => ['strip_prefix' => 'acme_', 'tables' => ['orders', 'customers']],
]);
// → ['ok' => true, 'schema' => ['added'=>96,'updated'=>0,'unchanged'=>0,'total'=>96,'tables'=>7,'skipped'=>12]]
```

- Parser: MySQL `CREATE TABLE` statements. A schema-only dump
  (`mysqldump --no-data`) is the expected input.
- `COMMENT '…'` on a column becomes its description; a `COMMENT=` on the table
  becomes the table description. Rows are written with
  `description_source = 'dump'`.
- `KEY`, `UNIQUE`, `INDEX` and `CHECK` lines are recognised as not-columns and
  skipped; `FOREIGN KEY` targets are recorded as "Reference to <table>".
- `options.tables` filters by name (after `strip_prefix` is removed);
  `options.strip_prefix` drops a shared prefix from every table name.
- Nothing matched → `ok:false` with `code: 'nothing_learned'` and a message telling
  you to send a schema-only dump or train from a connection. The package will not
  silently create an empty catalog.

A dump has no data, so `is_measure`, `format` and `sensitivity` are inferred from
the column name and type. Review them, or supply them: the dump populates the
catalog, and section 2's CSV import lets you correct it in bulk.

## 4. Train from another connection

For "the assistant should learn from this other database" — an uploaded SQLite
file, a warehouse, a replica:

```php
$staging = new PDO('mysql:host=staging.internal;dbname=acme', $user, $pass);

$api->trainFromConnection($staging, ['prune' => false]);
// → ['ok' => true, 'schema' => ['added'=>…, 'updated'=>…, 'unchanged'=>…, 'total'=>…, 'pruned'=>…, 'tables'=>…]]
```

The method opens no connection and holds no credentials: it temporarily points the
schema service's *source* at the PDO you give it, runs the same `syncSchema()`
(same options, same result shape), then restores the original. Reads come from the
other database; the catalog rows are always written to `{prefix}_schema` in yours.
The policy still filters which tables get catalogued.

An uploaded SQLite file is just a connection:

```php
$api->trainFromConnection(new PDO('sqlite:' . $uploaded['tmp_path']), ['tables' => ['orders']]);
```

## 5. Full one-call training

`schema.train` is structure + lookup values + row counts + metrics, for one tenant:

```php
$api->handle('schema.train', ['options' => [
    'schema'    => ['tables' => ['orders', 'customers']],   // passed to syncSchema
    'reference' => [],                                       // passed to extractReferenceValues
    'metrics'   => [],                                       // passed to extractMetrics
]]);
// → ['ok'=>true,'schema'=>[…],'references'=>[…],'metrics'=>[…],'counts'=>[…]]
```

Where the extra pieces go — all in `{prefix}_reference`, one tenant's rows per
`company_id`, unique on (`company_id`, `source_table`, `kind`):

| `kind` | `source_table` | What it is |
|--------|----------------|------------|
| `lookup` | the real table name | the values of a small reference table — "service_types: Standard visit 1500, Deep clean 3200" |
| `metric` | `metric:{key}` | a configured aggregate's value |
| `metric` | `count:{table}` | a table's row count |

Discovery is config-driven (`reference.auto_suffixes`, `name_columns`,
`price_columns`, `max_values`, `max_tables`) — nothing about your app is hardcoded.
`metrics.auto` derives row counts for up to `metrics.auto_max_tables` tables;
`metrics.custom` computes named aggregates from your config list. `extractCounts()`
is an alias for `extractMetrics()`.

Inspect and forget:

```php
$api->referenceValues($tenantId);                 // all rows
$api->referenceValues($tenantId, 'lookup');       // one kind
$api->forgetReferenceValues($tenantId, 'lookup'); // the app changed, re-harvest later
```

## How the catalog reaches the model

When a question needs data, the package builds a "schema card" from the catalog and
puts it in the routing prompt: table and column labels, descriptions, types,
`sensitivity`, `is_measure`, `format` and `aliases` — bounded by
`schema.context_max_chars` (14000) and `schema.context_max_tables` (60), with the
semantic vocabulary taking `semantic.vocab_chars` (12000). Sentences are trimmed to
one short line per dataset, and any dataset that does not fit is still listed by
name and aliases under “Also available”, so a long description never hides a table.

Two consequences for how you write descriptions:

- **Be specific and state the unit.** "Total payable in KSh, discount applied, VAT
  excluded" prevents three of the five ways a model gets a money question wrong.
- **`aliases` is the highest-value column in the table.** Users write "revenue",
  "sales", "takings" for the same column; aliases let the router pick it without a
  guess. `is_measure` is what makes `sum()` over that column an option at all.

If a description is missing, `schema.auto_describe` (`true`) generates a guess from
the column name — which is exactly the behaviour you may want to replace with your
own words. `$api->describeTable('orders')` shows the compiled text, so you can see
what the model was told when it answered badly.

## Which wording wins

`writeCatalogRow()` applies one rule with two protected sources:

| Incoming source | Existing source | Result |
|-----------------|-----------------|--------|
| `auto` / `comment` / `dump` | `user` or `import` | **existing kept** |
| `user` or `import` | anything | incoming written |
| anything | same source | incoming written (a fresh import replaces an older import) |

Plus: an incoming *blank* description never erases a non-empty one.

So `schema.sync` after a deploy refreshes types and adds new columns without
touching a sentence you wrote. And because `dump` is not protected, a later sync may
overwrite dump-derived descriptions — import your descriptions *after* the dump, and
use `user`/`import` as the source so they stick.

## Making the assistant useful, not just correct

- **Describe the tables too.** A row with an empty `column` and a description like
  "One row per customer support conversation" tells the router which table to
  choose before it reads a single column.
- **Mark `sensitivity` honestly.** `pii` and `secret` reach the model as warnings.
  Note that the *export* gate does not read this column: `ExportService` recomputes
  sensitivity from the column name and from `schema_policy.blocked_columns` /
  `masked_columns`. A column you want approval-gated when exported must appear in
  `masked_columns` (see [07 — Exports](07-exports.md)).
- **Re-run training on a schedule**, not just on deploy — lookup values and counts
  go stale. `handle('schema.train')` is idempotent and cheap for small schemas.
- **Keep the surface small.** `schema_policy.mode = allowlist` with 15–30 tables
  makes answers faster and more accurate than cataloguing every table in the app.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `handle('schema.sync')` returns 0 tables | nothing passes `schema_policy`; an allowlist with an empty `allowed_tables` allows nothing, or every table is blocked | check `diagnostics()['policy']` and `config.get` for `schema_policy.mode` / `allowed_tables` |
| `code: 'unavailable'` on `schema.list` | the schema service has no PDO | hand the package your connection when constructing `AssistantApi` |
| `nothing_learned` from `schema.import-dump` | the file has no `CREATE TABLE` the parser recognises | send a schema-only MySQL dump, or use `trainFromConnection` |
| My description disappeared | it was written as `auto`/`comment`/`dump` and a later sync of the same class replaced it | re-save with `schema.save` (source `user`) or import with `description_source: import` |
| The assistant still invents a column | description blank so `auto_describe` guessed, or the table is outside the context budget | fill `description` + `aliases`; trim `schema.skip_tables` |
| Catalog rows for a dropped column | `schema.prune_stale` is `false` (default) | `handle('schema.sync', ['options' => ['prune' => true]])` |
| `errors: ['Empty file']` | the upload did not carry text | pass the file array your framework gives you (`tmp_path`/`content`), not its name |

**Next:** [05 — Knowledge & documents](05-knowledge-and-documents.md)
