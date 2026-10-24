# 02 — Connecting your application

Everything the package does goes through one object:

```php
use ExcelleInsights\AiAssistant\Http\AssistantApi;

$api = new AssistantApi($pdo);                 // your existing PDO connection
```

That is a complete integration. Ask it something and it routes the question,
writes its own tables, and returns a plain array:

```php
$answer = $api->handle('chat', [
    'question' => 'Which customers spent the most this quarter?',
    'user'     => ['user_id' => '7', 'tenant_id' => 3, 'roles' => ['manager']],
]);

$answer['ok'];                    // true, or false with 'error'
$answer['answer']['text'];        // the reply
$answer['answer']['rows'];        // the data behind it, if any
$answer['answer']['chart'];       // chart spec, if asked for one
$answer['answer']['sql'];         // null unless the role may see SQL
$answer['answer']['meta']['intent'];   // data | knowledge | document | action | report | help | smalltalk
```

No framework is assumed. Build the object once in your container — a Laravel
singleton, a Symfony service, a `bootstrap.php` factory, whatever you already
have — and inject it where routes need it. It holds no request state, so one
instance per request is safe.

The package never touches your tables. It reads them only through the SQL you
let it run (see [schema training](04-schema-training.md) and
[data questions](06-data-and-charts.md)), and writes only to its own
`ai_assistant_*` tables.

## Three levels of wiring

You can stop at any of them.

**1. Nothing but config.** `AssistantApi($pdo)` + an LLM key in `.env`. You get
chat, knowledge, schema training, charts, exports (CSV/JSON), audit, usage,
settings and conversations. Data questions run through the package's own guarded
SQL against your database.

**2. Closures.** `services['hooks']` for the handful of things only your app
knows, without writing a class:

```php
$api = new AssistantApi($pdo, [
    'hooks' => [
        'rows'    => fn (array $compiled, $user) => $pdo->query($compiled['sql'])->fetchAll(PDO::FETCH_ASSOC),
        'export'  => fn ($result, string $format, $user, array $meta) => $myExports->create(...),
        'audit'   => fn (string $action, array $detail) => Log::info($action, $detail),
        'usage'   => function (array $usage) { $this->tokens += $usage['total_tokens'] ?? 0; },
        'documents' => fn ($user, string $question, int $k) => $myDocs->search(...),
        'system_context' => fn ($user) => ['company' => $user->attributes['company_name'] ?? ''],
    ],
]);
```

| Hook | Called with | Returns | Used for |
|---|---|---|---|
| `rows` | compiled query `array`, `UserContext` | `QueryResult` or `array<int,array>` | running the SQL — or answering from your ORM instead |
| `export` | `QueryResult`, `string $format`, `UserContext`, `array $meta` | `array` with `status`, `file`/`error` | inline "export this" inside a chat answer |
| `audit` | `string $action`, `array $detail` | `void` | your own log instead of / beside `{prefix}_audit` |
| `usage` | `array` provider usage (`prompt_tokens`, …) | `void` | your token accounting and billing |
| `documents` | `UserContext`, `string $question`, `int $k` | `array` of chunks | your document store as a citation source |
| `system_context` | `UserContext` | `array<string,mixed>` | facts spoken in prompts (company name, plan, hours) |

Every hook is optional and every hook failure is caught: an audit sink that is
down never costs the user their answer.

**3. Interfaces.** For anything with real behaviour — permissions, actions,
delivery, storage — the package defines a contract and you implement it.

## Host interfaces

`ExcelleInsights\AiAssistant\Contracts\*`. Register them as `services` keys.

| Interface | `services` key | If you do not wire it |
|---|---|---|
| `LlmClientInterface` | `llm` | package builds an OpenAI-compatible client from `LLM_*` — usually fine |
| `UserContextInterface` | — (per request) | pass an array as `user` instead |
| `PermissionResolverInterface` | `permissions` | only the config table/column policy applies; no row scope, no per-user column hiding, exports allowed by config alone |
| `TablePolicyInterface` | `table_policy` | `schema_policy` config decides what is readable |
| `ActionHandlerInterface` | `action_handler` | every action is refused — the package never writes your rows |
| `NotificationInterface` (or a callable) | `notifier` | outbox queues fill but nothing is delivered |
| `ChannelInterface` | `channel` | `whatsapp.answer` returns `code: unavailable` unless `services['whatsapp_sender']` is a callable |
| `DocumentStoreInterface` | `document_store` | uploads have nowhere to keep their bytes |
| `ChunkIndexInterface` | `chunk_index` | built-in: embeddings stored as JSON in `{prefix}_document_chunks`, ranked in PHP |
| `TextExtractorInterface` | config `documents.extractors` (class names/callables) | built-ins handle txt/md/csv/pdf/docx/xlsx/images |
| `ExporterInterface` | config `exports.host_exporters` (`format => class|callable`) | `csv`, `tsv`, `json` work; `xlsx`/`pdf`/`gsheets` are refused |
| `ConversationStoreInterface` | `conversations` | built-in store in `{prefix}_conversations` / `_messages` (needs PDO) |
| `AuditSinkInterface` | config `audit.store` (class name, or `table`/`sink`/`both`) | built-in writes `{prefix}_audit` |
| `KnowledgeRetrieverInterface` | `services['assistant']` (build `AssistantService` yourself) | built-in hybrid search over `{prefix}_knowledge` |
| `SqlQueryRunner` | `services['runner']` (or a `rows` hook) | package executes with its own PDO |

Legacy contracts, kept for the pre-existing WhatsApp host and used by nothing
new: `SystemContextProviderInterface`, `BookingHandlerInterface`
(see [channels & WhatsApp](10-channels-and-whatsapp.md)).

### Permissions, in full

This is the one interface the package cannot guess for you, and the one it most
needs:

```php
use ExcelleInsights\AiAssistant\Contracts\PermissionResolverInterface;
use ExcelleInsights\AiAssistant\Dto\UserContext;

final class AppPermissions implements PermissionResolverInterface
{
    public function canView(UserContext $user, string $entity): bool
    {
        return $user->isGuest() ? $entity === 'public_orders' : true;
    }
    public function rowScope(UserContext $user, string $entity): ?string
    {
        return in_array('manager', $user->roles, true) ? null : 'assigned_user_id = ' . (int) $user->userId;
    }
    public function hiddenColumns(UserContext $user, string $entity): array
    {
        return in_array('finance', $user->roles, true) ? [] : ['total_amount', 'cost_price'];
    }
    public function canAct(UserContext $user, string $action): bool
    {
        return $action === 'add_note' || in_array('manager', $user->roles, true);
    }
    public function canExport(UserContext $user, string $entity): bool
    {
        return in_array('export', $user->roles, true);
    }
}
```

`rowScope()` returning `null` means "the host scopes the query itself" — the
package then leaves the SQL alone and your `rows` hook is responsible. `''`
means "no extra restriction". Returning a fragment is the common case and the
package injects it into every generated query for that table, so the model can
never see another tenant's rows even if it writes bad SQL.

## Who is asking: `UserContext`

Auth stays yours. The package only needs six facts, and accepts any of three
shapes:

```php
new UserContext('7', 3, 'web', 'thread-42', ['manager'], ['company_name' => 'Kipendo Ltd'])
$userObjectFromMyFramework                    // implements UserContextInterface
['user_id' => '7', 'tenant_id' => 3, 'roles' => ['manager']]   // plain array
```

Accepted array keys: `user_id`/`userId`, `tenant_id`/`company_id`/`companyId`,
`channel` (default `web`), `thread_id`/`threadId`, `roles[]`, `attributes[]`.
`attributes` reach your `system_context` hook and `TablePolicyInterface`
context; nothing else reads them.

Tenancy is enforced by the package on every generated query for a table that
has the tenant column (`tenant.column`, default `company_id`) — see
`schema_policy.require_tenant_scope`.

## Confirming the wiring

`diagnostics()` answers "is this host actually connected" without probing your
routes:

```php
$api->diagnostics()['wiring'];
// host_assistant, permissions, action_handler, notifier, document_store,
// chunk_index, report_runner, whatsapp_sender, extra_hooks
$api->diagnostics()['features'];   // what the config enables
$api->diagnostics()['policy'];     // mode, allowed/blocked tables, caps
$api->diagnostics()['settings'];   // config table present? how many settings are editable, which are overridden
$api->diagnostics()['llm'];        // provider, model, api_key: set|unset — never the key
```

Creating the object with `new AssistantApi($pdo)` already attaches the
`{prefix}_config` settings layer, so behavioural values stored there are live
from the first question — no extra wiring. To build an admin screen on top:

```php
$api->handle('config.describe', ['company_id' => 0]);   // sections + fields + current values
$api->handle('config.save', ['company_id' => 0, 'settings' => [...]]);
```

`diagnostics()['settings']['database_layer']` tells you whether that layer is
attached; pass `['settings' => ['enabled' => false]]` as the third constructor
argument to opt out.

Build the object once and expose that to your own admin screen; it is the
fastest way to tell "not configured" apart from "broken".

Next: [configuration](03-configuration.md), then
[train the schema](04-schema-training.md) before asking the first data question.
