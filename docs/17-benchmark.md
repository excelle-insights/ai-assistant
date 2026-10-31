# 17 — The benchmark: the quality bar of one CRM

A benchmark is a list of canonical questions with what the answer should look
like. It is run on demand from the admin screen and in CI, and it is the only
way to know that a change to the package did not quietly break three answers
nobody noticed.

Nothing in the benchmark belongs to the package. The questions, the datasets
and the values come from the host's own CRM, and the file is JSON so a business
person can read it, keep it in the repository and change it when the business
changes.

- [The file](#the-file)
- [The six metrics](#the-six-metrics)
- [How a case is measured](#how-a-case-is-measured)
- [Running it](#running-it)
- [Writing the questions](#writing-the-questions)
- [In CI](#in-ci)
- [Settings](#settings)

---

## The file

One file per CRM, `ai-assistant.benchmark.json` by default:

```json
{
  "crm": "ams",
  "questions": [
    {
      "question": "How many orders were placed this month?",
      "dataset": "orders",
      "columns": ["records"],
      "value_column": "records",
      "value": 42,
      "live": { "table": "orders", "aggregate": "count" }
    },
    {
      "question": "What is the total amount invoiced this month?",
      "dataset": "orders",
      "columns": ["total_amount"],
      "value_column": "total_amount",
      "value": 1850000,
      "money": true,
      "live": { "table": "orders", "column": "total_amount", "aggregate": "sum" }
    },
    {
      "question": "Which officer collected the most this month?",
      "dataset": "orders",
      "columns": ["officer_name"]
    },
    {
      "question": "What is the total amount for the guest?",
      "dataset": "orders",
      "columns": ["total_amount"],
      "role": "guest",
      "forbidden_columns": ["total_amount"]
    }
  ]
}
```

| Key | Meaning |
|---|---|
| `crm` | The name of the application. Only used in the report. |
| `question` | The question, asked exactly as a user would ask it. |
| `dataset` | The dataset the answer must come from. |
| `columns` | The fields the answer must carry. |
| `value_column` | The field the value is read from (defaults to the first column). |
| `value` | What the answer should add up to. `null` = nobody has filled it in yet. |
| `money` | The answer must carry the money column, formatted as money. |
| `role` | The role the question is asked as. |
| `roles` | The roles the user holds. |
| `forbidden_columns` | Columns this role may never see. |
| `forbidden_datasets` | Datasets this role may never see. |
| `live` | The table to recompute the value from: `table`, `column`, `aggregate` (`count`, `sum`, `avg`, `min`, `max`), `where`. |

The file may also be a bare list of questions — the `crm` name is optional.

---

## The six metrics

A run reports one number per metric, each between 0 and 1:

| Metric | What it measures | A case fails when |
|---|---|---|
| `routing` | The answer came from the dataset it should | The answer came from another dataset |
| `columns` | The answer carries the fields that were asked for | A field is missing, or the value is wrong |
| `ids` | The answer shows a name, not a key | Too many key columns (the id rate is over the ceiling) |
| `money` | The money column is there and it is money | The column is missing, or it is not formatted as money |
| `permission` | A restricted role sees nothing it may not see | A forbidden column or dataset came back |
| `freshness` | The answer reflects the data as it is now | The value differs from the live database |

`ids` also reports a **rate**: the share of the answer's columns that are keys.
A CRM that answers with `42` where a name belongs has an id rate of 1.0; one
that answers `Jane Wanjiru` has 0.0. The ceiling is `benchmark.id_ceiling`,
0.5 by default.

A case **passes** when every check it could make passed. Checks that do not
apply — no expected value, no live check, a question about money that carries
no money column — are *skipped*, not failed, and are left out of the metric's
denominator.

---

## How a case is measured

The benchmark asks the **real assistant**. The question goes through the same
router, the same plan shaping, the same compiler and the same read-only runner
a user would hit — so what is measured is what a user gets, not a mock.

Two consequences matter:

- The tenant scope, the row scope and the hidden columns apply. A benchmark
  that ran without them would measure a different application.
- The answer's own metadata is what gets judged: the dataset it came from, the
  columns it carries, the format of each column and the value of the row.

**A role that is not allowed the answer passes the case.** When a case names a
role and the forbidden columns, and the assistant answers with nothing — because
the permission layer removed the column it was asked about — there is no answer
to judge, so the content checks are skipped. The `permission` check still runs:
the forbidden column must not have leaked. The `money` check flips with it:
when the money column is one the role may not see, the correct outcome is that
it stays away.

**Freshness is recomputed, never remembered.** The `live` block is turned into
a real query against the database, with the tenant filter applied when the
catalogue says the table carries the tenant column, and the answer is compared
with it. A stale number fails the case.

---

## Running it

Through the API:

```php
$api = new \ExcelleInsights\AiAssistant\Http\AssistantApi($pdo);

// A starter file: one question per table the CRM has.
$template = $api->handle('benchmark.template', ['company_id' => 3]);
// ['benchmark' => ['crm' => 'ams', 'questions' => [...]]]

// Write it where the repository lives.
$api->handle('benchmark.write', [
    'company_id' => 3,
    'benchmark' => $template['benchmark'],
    'options' => ['output_dir' => '/path/to/repo'],
]);

// Run it.
$report = $api->handle('benchmark.run', ['company_id' => 3]);
// ['benchmark' => ['total' => 12, 'passed' => 12, 'failed' => 0,
//                   'metrics' => ['routing' => ['accuracy' => 1.0, ...]], ...]]
```

Or from PHP:

```php
$benchmark = $api->service('benchmark');

$report = $benchmark->run(3, ['file' => '/path/to/repo/ai-assistant.benchmark.json']);
$starter = $benchmark->template(3, ['crm' => 'ams']);
$written = $benchmark->write($starter, ['output_dir' => '/path/to/repo']);
```

The report is:

```php
[
  'crm' => 'ams',
  'file' => '/path/to/repo/ai-assistant.benchmark.json',
  'total' => 12,
  'passed' => 12,
  'failed' => 0,
  'metrics' => [
    'routing'    => ['accuracy' => 1.0,  'passed' => 12, 'total' => 12],
    'columns'    => ['accuracy' => 1.0,  'passed' => 24, 'total' => 24],
    'ids'        => ['accuracy' => 0.92, 'passed' => 11, 'total' => 12, 'rate' => 0.08],
    'money'      => ['accuracy' => 1.0,  'passed' => 4,  'total' => 4],
    'permission' => ['accuracy' => 1.0,  'passed' => 3,  'total' => 3],
    'freshness'  => ['accuracy' => 1.0,  'passed' => 5,  'total' => 5],
  ],
  'cases' => [ /* one per question: the dataset, the columns, the value, the checks */ ],
  'errors' => [],
]
```

A run that cannot read the file reports the reason in `errors` and measures
nothing, rather than passing silently.

---

## Writing the questions

The benchmark is a business artefact. A few rules keep it honest:

- **Ask the way a user asks.** "How many orders were placed this month?" — not
  "SELECT COUNT(*) FROM orders". The router is part of what is being measured.
- **Fill in the value.** A case with `"value": null` is checked for routing and
  columns only. The value is what catches a wrong sum.
- **One question per business fact, not per table.** Ten questions that a
  manager actually asks are worth more than sixty that enumerate columns.
- **Name the role for anything a role may not see.** A case with a `role` and
  `forbidden_columns` proves the permission layer works; without it, the
  benchmark says nothing about permissions.
- **Keep it small enough to read.** A file nobody opens is a file nobody
  updates.

`benchmark.template` writes the skeleton — one question per table, with the
dataset known and the value left for a person who knows the business.

---

## In CI

The benchmark is a plain PHP call, so a pipeline runs it without any extra
tooling:

```bash
php -r '
require "vendor/autoload.php";
$api = new \ExcelleInsights\AiAssistant\Http\AssistantApi(pdo());
$report = $api->handle("benchmark.run", ["company_id" => 3])["benchmark"];
printf("benchmark: %d passed, %d failed\n", $report["passed"], $report["failed"]);
exit($report["failed"] === 0 ? 0 : 1);
'
```

A failing benchmark blocks the release. The point is that the person who
changed the package sees the break before a user does.

---

## Settings

| Path | Meaning |
|---|---|
| `benchmark.file` | The file the run reads (empty = the run must be told) |
| `benchmark.output_dir` | Where `benchmark.write` puts the file |
| `benchmark.id_ceiling` | The share of key columns an answer may carry (0–1, default 0.5) |

These live in `{prefix}_config` like every other behavioural setting, so they
can change without a deploy and differ per company. See
[03 — Configuration](03-configuration.md).

---

## Related documents

- [12 — API reference](12-api-reference.md) — the actions and the direct methods
- [16 — CRM intelligence roadmap](16-crm-intelligence-roadmap.md) — the phase this belongs to
- [06 — Data, analysis & charts](06-data-and-charts.md) — the semantic layer the questions are answered from
