# 06 — Data Q&A, analysis & visualisation

"How many orders did we lose last month?" — that is the question this package exists
to answer, and it answers it in a way you can ship: the model never writes raw SQL
against your database by default. It picks **a named metric, a dimension, a date
range and filters** — a *query plan* — and the package compiles that into
parameterised SQL with your tenant and permission scope injected.

Everything below is what a front-end needs: the answer shape, the plan shape, the
chart spec, and the knobs.

- [Two paths to an answer](#two-paths-to-an-answer)
- [Asking](#asking)
- [The answer shape](#the-answer-shape)
- [Query plans](#query-plans)
- [Analysis you can ask for](#analysis-you-can-ask-for)
- [`explain` and `query.run` without a model](#explain-and-queryrun-without-a-model)
- [Raw SQL](#raw-sql)
- [Charts](#charts)
- [Rendering a chart in your app](#rendering-a-chart-in-your-app)
- [Row-level scope, and the `rows` hook](#row-level-scope-and-the-rows-hook)
- [Not implemented](#not-implemented)
- [Troubleshooting](#troubleshooting)

---

## Two paths to an answer

| | Semantic plan | Raw SQL |
|---|---|---|
| Who writes the query | the package, from a plan the model chose | the model or an admin, verbatim |
| Gated by | `schema_policy`, permissions, tenant scope | `semantic.allow_raw_sql` **and** `semantic.raw_sql_roles`, then the same policy |
| Default | on | **off** |
| Use for | every ordinary question | one-off analyst questions the vocabulary cannot express |

Stay on the left column. A plan cannot mention a table the policy blocks, cannot
select a credential column, and gets its tenant filter added for you — that is the
entire reason the package prefers it.

## Asking

```php
$answer = $api->handle('chat', [
    'question' => 'revenue by month this year, as a chart',
    'user'     => ['user_id' => '42', 'tenant_id' => 7, 'roles' => ['owner'], 'channel' => 'web'],
    'options'  => ['chart' => true],
]);
```

`question` or `text` (either). `user` is any accepted shape — a `UserContext`, an
array, or your own object (see
[02 — Connect your application](02-connect-application.md)).
`options`:

| Key | Default | Effect |
|-----|---------|--------|
| `intent` | `'auto'` | force one of `data`, `knowledge`, `document`, `action`, `report`, `help`, `smalltalk` |
| `plan` | `[]` | hand in a plan and skip the model's routing entirely |
| `history` | `[]` | turns to replay (the conversation store supplies these normally) |
| `context` | `[]` | free-form facts for the prompt (`['page' => 'orders']`, a selection, a record id) |
| `chart` | `false` | build a chart spec even if the words did not ask for one |
| `export` | `''` | format to produce; parsed from the words when left empty (`csv`, `tsv`, `json`, `xlsx`, `xls`, `pdf`, `docx`) |
| `params` | `[]` | filter values a report/question template references |
| `max_rows` | 0 | the caller's row ceiling — applied, then clamped to `schema_policy.max_rows` |

## The answer shape

`handle('chat')` returns `{ ok, answer }`, and `answer` is:

```json
{
  "text": "Revenue this year is 1,240,000 across 4 months. …",
  "plain": "the same sentence with no markdown, for SMS/WhatsApp",
  "intent": "data",
  "grounded": true,
  "rows": [{"month": "2026-01", "revenue": 310000}],
  "chart": { "type": "line", "option": {…}, "series": […], "…": "see Charts below" },
  "citations": [{"type": "query", "label": "revenue by month"}, {"type": "record", "label": "Orders", "ref": "orders"}],
  "sql": "SELECT DATE_FORMAT(o.created_at,'%Y-%m') AS month, SUM(o.total_amount) AS revenue …",
  "applied_filters": {"date_range": "this year", "company_id": 7},
  "suggestions": ["revenue by customer", "orders lost last month"],
  "pending_action": null,
  "meta": {"conversation_id": "9b1c…", "routed_by": "router", "channel": "web",
            "columns": ["month", "revenue"], "truncated": false, "source": "mysql",
            "export": {"status": "ready", "file": "revenue-by-month-….csv", "location": "/…", "meta": {…}}}
}
```

| Key | Notes |
|-----|-------|
| `text` | markdown, safe to render |
| `plain` | no markdown — for narrow channels |
| `grounded` | `false` means the package could not connect the question to data/knowledge. Show it as a caveat, not as an error |
| `rows` | up to `schema_policy.max_rows` (200) rows, already masked |
| `chart` | `null` unless a chart was asked for or `options.chart` |
| `citations` | at most 6, `type` ∈ `knowledge, document, record, query, schema, reference, web` |
| `sql` | the compiled SQL — show it in an admin "how did it get that" drawer |
| `applied_filters` | what the answer was actually scoped by, including the tenant filter |
| `pending_action` | set when the answer is a proposal awaiting confirmation ([09](09-actions-and-outbox.md)) |
| `meta.routed_by` | `hint` (you passed `options.intent`), `router` (the model chose), `guessed` (words decided it), `nothing_connected` — the last two are what to look at when answers are wrong |

`meta` also carries per-path extras: `columns`/`truncated`/`source`/`export` on data
answers, `retrieved` on knowledge answers, `report` on report runs.

## Query plans

The plan is the contract between your app and the model. `handle('query.run')` takes
it directly, so a widget can ask for numbers without spending a model call.

```php
$api->handle('query.run', [
    'plan' => [
        'dataset'    => 'orders',
        'metrics'    => ['revenue'],
        'dimensions' => ['status', 'month'],
        'filters'    => [
            ['field' => 'status', 'operator' => 'not_in', 'value' => ['cancelled', 'refunded']],
            ['field' => 'total_amount', 'operator' => 'between', 'value' => [1000, 50000]],
        ],
        'date_range' => ['preset' => 'last_90_days'],
        'order_by'   => [['field' => 'revenue', 'direction' => 'desc']],
        'limit'      => 20,
        'chart'      => 'bar',
    ],
    'user'  => $user,
    'chart' => true,     // ask for a spec alongside the rows
]);
// → ['ok'=>true,'result'=>['columns'=>[…],'rows'=>[…],'sql'=>'…','applied_filters'=>[…],
//     'truncated'=>false,'source'=>'mysql','record_refs'=>[…],'count'=>20,'chart'=>[…]]]
```

Accepted plan keys (`QueryPlan::fromArray`):

| Key | Shape |
|-----|-------|
| `dataset` | a dataset name from the semantic layer, or a table the policy allows |
| `metrics` | `string[]` — named measures (`revenue`, `order_count`) |
| `dimensions` | `string[]` — named slices (`month`, `status`, `source`) |
| `filters` | `[{field, operator, value}]` |
| `date_range` / `dateRange` | `['preset'=>…]`, or explicit `['from'=>…, 'to'=>…]`, or a plain string ("last 30 days") |
| `order_by` / `orderBy` | `[{field, direction}]`, or strings like `"revenue desc"` |
| `limit` | int; clamped to `schema_policy.max_rows`; `default_limit` (20) when unset |
| `chart` | a chart type name |
| `joins` | `[{table, on: {"left.col": "right.col"}}]` |
| `entity` | an entity name for a detail lookup ("order #A-1002") instead of an aggregate |

Operators: `= != > >= < <= in not_in like between is_null not_null contains`.
Anything else **throws** rather than being dropped — silently losing a filter would
present an unscoped number as a scoped one.

Presets: `today`, `yesterday`, `this_week`/`week`, `last_week`, `this_month`/`month`,
`last_month`, `this_quarter`/`quarter`, `last_quarter`, `this_year`/`year`/`ytd`,
`last_year`, `mtd`, `all`/`all_time`, and `last_N_days` / `last_N_weeks` /
`last_N_months` / `last_N_quarters` (or `['last_days' => N]`). Relative periods
resolve in `timezone`.

Aggregation is decided by the plan, not by luck: metrics present → `GROUP BY` the
dimensions; no metrics → a detail query that keeps every row.

### A dimension on the many side of a relation

A unit can have two buyers, a receipt can cover two lines. A plain join of that
relation turns one receipt into two receipts, and every total over it counts the
money twice. Say so in the pack and the far side is folded into one value:

```php
'dimensions' => [[
    'name'      => 'client_name',
    'collect'   => 'group_concat',   // fold the far side into one value
    'separator' => ' & ',            // how the values are put together
    'expr'      => 'clients.name',
    'via'       => ['unit_clients', 'clients'],
]],
```

The relation is then compiled into one derived table, so the base table keeps its
own grain: one receipt stays one receipt, the money is counted once, and the cell
reads "Albert Ouma Wanga & JUDITH OMBUOR". `collect` accepts `group_concat` (or
`true`); the separator is plain text only, and it is rejected if it carries
anything else.

### A dimension that holds codes

Some columns store a code, not a word: `hs_state = '2'` means sold, `pay_state`
holds only `PAID` or `NOTPAID`. The user says "sold" and "overdue", so a filter
built from their words never matches. Declare the set and the two vocabularies
meet in the middle:

```php
'dimensions' => [[
    'name'   => 'state',
    'expr'   => 'listing_info.hs_state',
    'values' => ['open' => '1', 'sold' => '2', 'blocked' => '5'],
]],
```

The router now sees `state{open|sold|blocked}` and is told to filter a braced
dimension with one of those words only — an invented value ("overdue") is a
measure, not a filter. The compiler maps the word back to the stored code when
binding `=`, `!=`, `in` and `not_in`, matching on label or code and ignoring
case, spacing and punctuation ("Not Paid" binds the same value as "notpaid"). A
value the set does not mention is passed through untouched rather than silently
rewritten, and `like`/`contains` are never mapped because partial text is the
user's own words. Codes without labels work too: `'PAID', 'NOTPAID'` binds
exactly what it says.

## Analysis you can ask for

All of these are the same mechanism, and they work because your pack (or the derived
catalog) names them:

- totals, counts, averages, min/max over a period or a slice
- group-by and top-N ("top 10 customers by revenue")
- time series and trends, by day/week/month/quarter
- composition and share (`pie`, `donut`, `stacked_bar`)
- funnels and stage conversion (`funnel`, needs ordered stage-named values)
- correlation of two numeric columns (`scatter`)
- density of two dimensions (`heatmap` / `pivot`)
- headline numbers (`kpi`) and comparisons across a dimension
- detail lookup for one record (`entity` + an id/reference)
- "explain this number" — `$api->explain(['plan'=>…])` returns the SQL and the
  filters without running it

## `explain` and `query.run` without a model

```php
$api->handle('explain', ['plan' => ['dataset' => 'orders', 'metrics' => ['revenue'], 'dimensions' => ['month']]]);
// → ['ok'=>true,'query'=>['sql'=>'SELECT …','bind'=>[…],'tables'=>['orders'],
//     'columns'=>['month','revenue'],'limit'=>20,'aggregate'=>true,'dataset'=>'orders',
//     'date_field'=>'created_at','applied_filters'=>['period'=>'this year',…],
//     'capabilities'=>['datasets'=>[…],'metrics'=>[…],'reports'=>true,…]]]

$api->handle('query.run', ['plan' => $savedPlan, 'user' => $user]);
```

`explain` costs nothing and answers the question your CFO asks when a number looks
wrong. `query.run` is the path for dashboards: cache the plan once, re-run it on a
timer, and you never pay a token per widget refresh.

One detail to know before you build an admin drawer on it: `query.run` only returns
`sql` and `bind` when the *requesting user* may use raw SQL
(`semantic.allow_raw_sql` + `semantic.raw_sql_roles`). For everyone else those two
come back `null`/`[]`, and the rows still arrive. `explain` is not gated that way, so
give the "how did it get that" button to the roles that can read SQL, not to all.

## Raw SQL

```php
// config: 'semantic' => ['allow_raw_sql' => true, 'raw_sql_roles' => ['analyst','admin']]
$api->handle('sql.run', ['sql' => 'SELECT status, COUNT(*) c FROM orders GROUP BY status', 'user' => $user]);
```

One statement, `SELECT` only, validated against the same `SchemaPolicy` as everything
else (`$api->validateSql($sql)` exposes that check alone). With
`require_tenant_scope` on, a query that does not filter the tenant column is
rejected. It is off by default because the plan path already covers ordinary
questions, and an unchecked model-written query is the one way this package could
read something you did not intend.

## Charts

Two entry points, no rendering inside the package:

```php
$api->handle('chart.suggest', ['data' => $rows, 'intent' => 'share of total']);
// → ['ok'=>true,'chart'=>$spec,'types'=>['bar','column','line',…]]

$api->handle('chart.build', ['type' => 'funnel', 'data' => $rows, 'options' => ['title' => 'Pipeline']]);
// → ['ok'=>true,'spec'=>$spec,'insight'=>'Stage 1→2 drops 46%.']
```

`data` is a `QueryResult` or plain `array<int,array>` — so your own numbers get the
same treatment. `options`: `title`, `unit`, `currency`, `locale`, `dimension`,
`measure`/`measures`, `agg`, `sort`, `limit`, `rows`/`columns` (pivot), `x`/`y`/`size`
(scatter), `group`, `split`, `orientation`, `insight`.

**Types this build supports:** `bar, column, line, area, pie, donut, stacked_bar,
funnel, scatter, heatmap, kpi, table, pivot, map`. `map` is accepted but degrades to
`table` — the package has no geo data. Anything unrecognised degrades to a table with
a `note`, never an exception. `charts.enabled_types` narrows the palette (and so
`chart.suggest`'s choices); `reports.chart` accepts these names plus `none`.

**How a type is chosen.** `suggest()` reads the shape of the data plus any `intent`
phrase: a date-shaped column → `line`; "share/proportion/distribution" → `pie`;
"top/compare/breakdown" → `bar`; "cumulative" → `area`; "conversion/pipeline/funnel"
→ `funnel`; "correlation/relationship" → `scatter`; "matrix/crosstab/pivot" →
`pivot`; "kpi/metric/headline/summary" → `kpi`; more columns than will read well →
`table`. The guards are the `charts.*` numbers:

| Config | Default | Stops a chart from being… |
|--------|---------|---------------------------|
| `charts.max_rows` | 300 | …drawn from more rows than that |
| `charts.max_series` | 8 | …a spaghetti of series |
| `charts.bar_max_categories` | 30 | …a bar chart with 40 slivers |
| `charts.pie_max_categories` | 12 | …a pie nobody can read |
| `charts.table_above_rows` | 8 | …showing >8 rows as a table instead of a chart |
| `charts.kpi_max_items` | 6 | …a wall of KPI tiles |
| `charts.compact_axis` | `true` | (formats `1.2k` on the axis) |

**The spec**, in full, so you can render it without guessing:

```json
{
  "type": "line",
  "title": "Revenue by month",
  "option": {"xAxis": {"type": "category", "data": ["2026-01", "…"]},
             "yAxis": {"type": "value"}, "series": [{"name": "Revenue", "type": "line", "data": [310000, "…"]}],
             "legend": {"show": true, "data": ["Revenue"]}, "tooltip": {"…": "…"}},
  "series": [{"name": "Revenue", "type": "line", "data": [310000]}],
  "dataset": {"source": [["2026-01", 310000], ["2026-02", 288000]], "dimensions": ["month", "revenue"], "source_format": "table"},
  "insight": "Revenue this year is 1,240,000; it peaked in January.",
  "reason": "A date column and one measure read as a trend.",
  "note": null,
  "format": {"unit": null, "currency": "KSh", "currency_position": "before",
             "locale": "en", "compact_axis": true, "axis_formatter": "{value}"},
  "meta": {"dimension": "month", "measure": "revenue", "measures": ["revenue"],
           "columns": ["month", "revenue"], "row_count": 4, "labels": ["2026-01", "…"],
           "requested_type": "line"}
}
```

`option` is an **ECharts-shaped option object** — the library this package's own
widget uses — because it is the most portable way to describe a chart across
front-ends. `series` and `dataset` are also there for Chart.js, Recharts, ApexCharts
or your own SVG. `insight` is the one factual sentence; `currency` comes from
`domain.currency`, never from the chart code.

## Rendering a chart in your app

The package has no UI. Two supported patterns:

```html
<!-- 1. ECharts: the option is already shaped for it -->
<script>
  const spec = answer.chart;
  echarts.init(document.getElementById('c')).setOption(spec.option);
</script>

<!-- 2. Any other library: feed the flat series/dataset yourself -->
<script>
  const { labels } = answer.chart.meta;          // category axis values
  const series = answer.chart.series;             // [{name, type, data: [numbers]}]
  myChart({ labels, series, title: answer.chart.title, unit: answer.chart.format.unit });
</script>
```

For a `kpi` spec, read `series[].value` (tiles, not a plot). For `pivot`/`table`,
read `dataset.source` and render rows. A chart in a chat message that your front-end
cannot draw still has `answer.rows` beside it — degrade to a table, do not hide the
numbers.

## Row-level scope, and the `rows` hook

Your permission resolver answers "may this user ask"; its `rowScope()` returns the
SQL fragment or filter the package must add — and `null` (no restriction) is
different from `''` (see [02](02-connect-application.md)). Beyond that, if you need
to change results after they come back:

```php
$api = new AssistantApi($pdo, [
    'hooks' => [
        // rows(): edit the compiled request before SQL is built (add a branch filter,
        //         drop a column this role never sees)
        'rows'    => fn (array $compiled, UserContext $who): array => …,
        // export(): the same gate on the way to a file
        'export'  => fn (QueryResult $r, string $format, UserContext $who, array $meta): ?QueryResult => …,
    ],
]);
```

Derived datasets never expose a blocked or PII column, so auto-deriving on a new app
is safe by default — the floor is in `SchemaPolicy`, not in the pack.

## Not implemented

Honest list, so you do not design around something that is not there:

- **Forecasting, lead scoring, churn.** The blueprint lists them; this build does the
  querying, charting, exporting and reporting they would sit on. Nothing named
  "prediction" exists in `handle()`.
- **A geo/map renderer** (`map` → `table`).
- **Cross-database joins.** One PDO per assistant; join inside your database.
- **Cohort/retention analysis as a named feature.** You can get it with a plan and a
  `count_distinct` metric, but there is no `retention()` action.
- **No chart is drawn server-side.** No image files; specs are JSON only.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| "I can't answer that" / `grounded:false`, `routed_by:'nothing_connected'` | no dataset matches the words | `schema.sync` + descriptions; add a pack with the words your users say (`aliases`) |
| Wrong metric chosen | two metrics share a name-ish alias | tighten `aliases`; `semantic.max_datasets` too high invites collisions |
| Numbers look doubled | a `joins` fan-out: one order, many lines | measure on the table whose grain matches, or `count_distinct`; a dimension on the many side can be `collect`ed instead |
| `Unsupported filter operator "not equals" on "status"` | the model or your UI used prose | map to the operator list; `!=`/`not_in` |
| Every answer is a table | `charts.table_above_rows` (8) with wide results, or > `bar_max_categories` | reduce dimensions in the plan; raise the guard if the data is genuinely dense |
| Tenant data leaking between companies | `require_tenant_scope=false` **and** a resolver that returns `null` | turn it back on; verify `applied_filters` shows the tenant |
| Raw SQL refused for an admin | `semantic.allow_raw_sql` off, or their role is not in `raw_sql_roles` | both are deliberate defaults; enable per role |
| Chart renders blank in Chart.js | you read `option` (ECharts shape) | use `series` + `meta.labels` instead |

**Next:** [07 — Exports](07-exports.md)
