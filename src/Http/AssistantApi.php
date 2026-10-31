<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Http;

use ExcelleInsights\AiAssistant\Channels\WhatsAppChannel;
use ExcelleInsights\AiAssistant\Contracts\ActionHandlerInterface;
use ExcelleInsights\AiAssistant\Contracts\ChannelInterface;
use ExcelleInsights\AiAssistant\Contracts\ChunkIndexInterface;
use ExcelleInsights\AiAssistant\Contracts\CodeScannerInterface;
use ExcelleInsights\AiAssistant\Contracts\ConversationStoreInterface;
use ExcelleInsights\AiAssistant\Contracts\DocumentStoreInterface;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Contracts\NotificationInterface;
use ExcelleInsights\AiAssistant\Contracts\PermissionResolverInterface;
use ExcelleInsights\AiAssistant\Contracts\TablePolicyInterface;
use ExcelleInsights\AiAssistant\Contracts\UserContextInterface;
use ExcelleInsights\AiAssistant\Dto\ExportRequest;
use ExcelleInsights\AiAssistant\Dto\ExportResult;
use ExcelleInsights\AiAssistant\Dto\QueryResult;
use ExcelleInsights\AiAssistant\Dto\UploadedFile;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Extractors\ExtractorRegistry;
use ExcelleInsights\AiAssistant\Services\ActionService;
use ExcelleInsights\AiAssistant\Services\AssistantService;
use ExcelleInsights\AiAssistant\Services\AuditService;
use ExcelleInsights\AiAssistant\Services\ChartService;
use ExcelleInsights\AiAssistant\Services\CodeIndexService;
use ExcelleInsights\AiAssistant\Services\ConfigService;
use ExcelleInsights\AiAssistant\Services\ConversationStore;
use ExcelleInsights\AiAssistant\Services\DiscoveryService;
use ExcelleInsights\AiAssistant\Services\DocumentService;
use ExcelleInsights\AiAssistant\Services\ExportService;
use ExcelleInsights\AiAssistant\Services\FeedbackService;
use ExcelleInsights\AiAssistant\Services\KnowledgeService;
use ExcelleInsights\AiAssistant\Services\OutboxService;
use ExcelleInsights\AiAssistant\Services\QueryCompiler;
use ExcelleInsights\AiAssistant\Services\QueryLibraryService;
use ExcelleInsights\AiAssistant\Services\RelationService;
use ExcelleInsights\AiAssistant\Services\ReportService;
use ExcelleInsights\AiAssistant\Services\RuleService;
use ExcelleInsights\AiAssistant\Services\SchemaService;
use ExcelleInsights\AiAssistant\Services\SettingsService;
use ExcelleInsights\AiAssistant\Services\SqlQueryRunner;
use ExcelleInsights\AiAssistant\Services\SchedulesService;
use ExcelleInsights\AiAssistant\Services\UsageService;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\ConfigCatalog;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\DomainPack;
use ExcelleInsights\AiAssistant\Support\EnvLoader;
use ExcelleInsights\AiAssistant\Support\LlmFactory;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;
use ExcelleInsights\AiAssistant\Support\TablePrefix;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * The whole package through one framework-agnostic surface.
 *
 * Every method takes and returns plain arrays, so a host wires it in whatever it
 * already has — a Laravel route, a Slim middleware, a switch in a raw PHP file:
 *
 *   $api = new AssistantApi($pdo, ['permissions' => new MyPermissions()]);
 *   echo json_encode($api->handle('chat', [
 *       'question' => 'revenue this month',
 *       'user'     => ['user_id' => '7', 'tenant_id' => 3, 'roles' => ['manager']],
 *   ]));
 *
 * The response contract is the same everywhere: `ok` is true for a job well done,
 * false with an `error` sentence for anything else — a refusal, a missing upload,
 * a bad token, an unreachable model. Nothing here throws at the controller, so a
 * host never leaks a stack trace into a JSON body. Codes worth branching on
 * (`not_found`, `expired`, `forbidden`) live in `code`.
 *
 * Assembly is lazy and injectable: pass nothing but a PDO and the API builds each
 * service on first use; pass a service in `$services` and it is used as-is. That
 * is what makes this work in an app that already has its own LLM client, its own
 * permissions, its own storage, or none of the above.
 *
 * Auth and tenancy stay in the host. `$user` is whoever the host says it is; the
 * package scopes by `tenant_id` and asks `PermissionResolverInterface` — it does
 * not know how a session works.
 */
final class AssistantApi
{
    /** Tables the migrations create. `legacy` only exists in an app that had the older host. */
    private const CORE_TABLES = [
        'conversations', 'messages', 'knowledge', 'schema', 'reference', 'training',
        'documents', 'document_chunks', 'exports', 'actions', 'action_runs',
        'notification_outbox', 'audit', 'usage_daily', 'settings', 'config',
        'report_definitions', 'schedules', 'feedback', 'rate_limits', 'relations', 'code_files', 'queries',
    ];
    private const LEGACY_TABLES = ['sessions', 'queue'];

    /** @var array<string,mixed> host-supplied collaborators, keyed by name */
    private array $services;

    /** @var array<string,mixed> built services, keyed by name */
    private array $made = [];

    /**
     * @param PDO|null            $pdo      the host database. null = read-only pure logic
     *                                      (formatting, chunking, plan validation) still works.
     * @param array<string,mixed> $services llm, assistant, policy, table_policy, permissions,
     *                                      action_handler, notifier, runner, report_runner,
     *                                      conversations, document_store, chunk_index, extractors,
     *                                      schema, knowledge, documents, export, actions, outbox,
     *                                      reports, schedules, feedback, usage, settings, config
     *                                      (the host config array), configuration (ConfigService),
     *                                      audit, charts, hooks, on_error, whatsapp_sender
     * @param array<string,mixed> $options  per-service build options: schema, knowledge,
     *                                      documents, conversations, assistant, semantic,
     *                                      channel, settings (['enabled' => false] keeps the
     *                                      database settings row out of Config) — each an array
     */
    public function __construct(
        private ?PDO $pdo = null,
        array $services = [],
        private array $options = [],
    ) {
        EnvLoader::load();
        $this->services = $services;
        if (isset($services['config']) && is_array($services['config'])) {
            Config::load($services['config']);
        }
        $this->attachDatabaseSettings();
    }

    /** The host's database, when this surface has one. */
    public function connection(): ?PDO
    {
        return $this->pdo;
    }

    /**
     * One collaborator by name, built on first use. Hosts that want to reuse what
     * this API assembled (to write their own controller) ask here instead of
     * wiring a second copy.
     */
    public function service(string $name): mixed
    {
        return match (strtolower(trim($name))) {
            'llm' => $this->llm(),
            'policy' => $this->policy(),
            'schema' => $this->schema(),
            'knowledge' => $this->knowledge(),
            'relations' => $this->relations(),
            'code' => $this->code(),
            'queries' => $this->queries(),
            'rules' => $this->rules(),
            'discovery' => $this->discovery(),
            'documents' => $this->documents(),
            'export' => $this->exports(),
            'actions' => $this->actions(),
            'outbox' => $this->outbox(),
            'reports' => $this->reports(),
            'schedules' => $this->schedules(),
            'feedback' => $this->feedback(),
            'usage' => $this->usage(),
            'settings' => $this->settings(),
            'configuration' => $this->configuration(),
            'audit' => $this->audit(),
            'charts' => $this->charts(),
            'conversations' => $this->conversations(),
            'assistant' => $this->assistant(),
            default => throw new InvalidArgumentException(
                'Unknown service "' . $name . '". Known: llm, policy, schema, knowledge, relations, code, queries, '
                . 'rules, discovery, documents, export, actions, outbox, reports, schedules, feedback, usage, settings, '
                . 'configuration, audit, charts, conversations, assistant.'
            ),
        };
    }

    /**
     * Run one action by name — the shape a single-endpoint host needs.
     *
     *   $api->handle('chat', ['question' => …, 'user' => …]);
     *   $api->handle('export.create', ['data' => …, 'format' => 'csv', 'user' => …]);
     *
     * `$input['user']` is read for every action that acts for somebody. Routing,
     * auth and JSON encoding stay outside; this only maps a name to a method.
     *
     * @param array<string,mixed> $input
     */
    public function handle(string $action, array $input = []): array
    {
        // Only an explicit `user` key counts as the requester: a flat request body
        // must never be mistaken for one and granted that tenant.
        $user = $this->userOrNull($input['user'] ?? null);
        foreach ($this->routes($input, $user) as $name => $call) {
            if ($name === strtolower(trim($action))) {
                return $this->wrap($call);
            }
        }

        return ['ok' => false, 'error' => 'Unknown action "' . $action . '".', 'actions' => array_keys($this->routes([], null))];
    }

    // ─── chat, data, visualisation ────────────────────────────────────────────

    /**
     * One turn of conversation. `$options` are the ask() options (intent, chart,
     * export, history, context, locale…).
     *
     * @param UserContext|array<string,mixed> $user
     * @param array<string,mixed> $options
     */
    public function chat(string $question, UserContext|array $user, array $options = []): array
    {
        return $this->wrap(function () use ($question, $user, $options): array {
            $context = $this->user($user);
            $answer = $this->assistant($context)->ask($question, $context, $options);
            $this->countTurn($context);

            return ['answer' => $answer->toArray() + ['plain' => $answer->plain()]];
        });
    }

    /**
     * One turn, however it arrived, counted against the tenant that asked it.
     * Accounting failures never cost a user their answer.
     */
    private function countTurn(UserContext $who): void
    {
        try {
            $this->usage()->record([
                'company_id' => $who->tenantId,
                'user_id' => $who->userId,
                'chats' => 1,
            ]);
        } catch (Throwable) {
            // usage is a nice-to-have; the reply already went out
        }
    }

    /** What this assistant can answer right now — an empty-state panel reads this. */
    public function capabilities(UserContext|array|null $user = null): array
    {
        return $this->wrap(function () use ($user): array {
            $context = $this->user($user ?? new UserContext());

            return ['capabilities' => $this->assistant($context)->capabilities($context)];
        });
    }

    /** Compiled SQL and scope for a plan, executed by nobody. */
    public function explain(array $plan, UserContext|array $user): array
    {
        return $this->wrap(function () use ($plan, $user): array {
            $context = $this->user($user);

            return ['query' => $this->assistant($context)->explain($plan, $context)];
        });
    }

    /**
     * Run a plan the host already built — a widget that needs numbers, no model.
     *
     * @param array<string,mixed> $plan
     * @param UserContext|array<string,mixed> $user
     */
    public function runQuery(array $plan, UserContext|array $user, bool $withChart = false): array
    {
        return $this->wrap(function () use ($plan, $user, $withChart): array {
            $context = $this->user($user);

            return ['result' => self::runArray($this->assistant($context)->run($plan, $context, $withChart))];
        });
    }

    /**
     * The admin path: one SELECT the assistant is allowed to read. Guarded by the
     * same policy a plan goes through, and only when `semantic.allow_raw_sql` is
     * on for this user's roles.
     *
     * @param UserContext|array<string,mixed> $user
     */
    public function runRawSql(string $sql, UserContext|array $user): array
    {
        return $this->wrap(function () use ($sql, $user): array {
            $context = $this->user($user);
            $assistant = $this->assistant($context);
            if (($assistant->capabilities($context)['raw_sql'] ?? false) !== true) {
                return ['ok' => false, 'code' => 'forbidden',
                    'error' => 'Raw SQL is not enabled for this user. Set AI_ASSISTANT_ALLOW_RAW_SQL=1 '
                        . 'and list the roles in semantic.raw_sql_roles.'];
            }
            $assistant->guardRawSql($sql);
            $runner = $this->runner();
            if ($runner === null) {
                return ['ok' => false, 'code' => 'unavailable',
                    'error' => 'Nothing to run it with: this surface has no database connection.'];
            }

            return ['result' => self::resultArray($runner(['sql' => $sql], $context), $sql)];
        });
    }

    /** A chart spec from rows, plus the one-line insight to put above it. */
    public function buildChart(string $type, mixed $data, array $options = []): array
    {
        return $this->wrap(function () use ($type, $data, $options): array {
            $charts = $this->charts();
            $spec = $charts->build($type, $data, $options);

            return ['spec' => $spec, 'insight' => $charts->insight($data, (string) ($spec['type'] ?? $type), $options['unit'] ?? null)];
        });
    }

    /** Which chart these rows want to be, and why. */
    public function suggestChart(mixed $data, ?string $intent = null): array
    {
        return $this->wrap(fn (): array => ['chart' => $this->charts()->suggest($data, $intent), 'types' => $this->charts()->types()]);
    }

    // ─── exports ──────────────────────────────────────────────────────────────

    /** Formats this install can actually produce, and whether files need approval. */
    public function exportFormats(): array
    {
        return $this->wrap(fn (): array => [
            'formats' => $this->exports()->formats(),
            'requires_approval' => Config::bool('exports.require_confirmation', true),
            'async_above_rows' => Config::int('exports.async_above_rows', 5000),
        ]);
    }

    /**
     * Make the file (or queue it, or hold it for approval). The bytes are written
     * by the package for csv/tsv/json; anything else goes to the host exporter.
     *
     * @param UserContext|array<string,mixed> $user
     * @param array<string,mixed> $meta title, question, file_name, columns, applied_filters, entity, plan
     */
    public function createExport(mixed $data, string $format, UserContext|array $user, array $meta = []): array
    {
        return $this->wrap(function () use ($data, $format, $user, $meta): array {
            $context = $this->user($user);
            $result = $this->exports()->create($data, $format, $context, $meta);

            return self::exportArray($result);
        });
    }

    /** An approved waiting export, produced now. */
    public function approveExport(string $id, UserContext|array $approver, mixed $data = null): array
    {
        return $this->wrap(fn (): array => self::exportArray($this->exports()->approve($id, $this->user($approver), $data)));
    }

    /** Work the queue (a cron tick calls this, or a host's queue worker). */
    public function runQueuedExports(int $limit = 5, UserContext|array|null $as = null): array
    {
        return $this->wrap(function () use ($limit, $as): array {
            $context = $as === null ? null : $this->user($as);

            return ['jobs' => $this->exports()->runQueued($limit, $context)];
        });
    }

    /**
     * Where a download token points. The host streams the file itself — this
     * returns the path, name and mime, never the contents, so a 40 MB xlsx does
     * not travel through JSON.
     *
     * @param UserContext|array<string,mixed>|null $user checked against the export's tenant
     */
    public function download(string $token, UserContext|array|null $user = null): array
    {
        return $this->wrap(function () use ($token, $user): array {
            $file = $this->exports()->resolve($token, $user === null ? null : $this->user($user));
            if ($file === null) {
                return ['ok' => false, 'code' => 'not_found',
                    'error' => 'That download link is expired, its file is gone or was never produced, '
                        . 'or it belongs to another company.'];
            }

            return ['file' => $file, 'content_disposition' => 'attachment; filename="' . $file['file_name'] . '"'];
        });
    }

    /** @param UserContext|array<string,mixed> $user */
    public function exportHistory(UserContext|array $user, int $limit = 20): array
    {
        return $this->wrap(fn (): array => ['items' => $this->exports()->history($this->user($user), $limit)]);
    }

    /** Delete files past their expiry window; run it from a nightly job. */
    public function purgeExpiredExports(int $limit = 200): array
    {
        return $this->wrap(fn (): array => ['purged' => $this->exports()->purgeExpired($limit)]);
    }

    // ─── actions (propose → confirm → host writes) ────────────────────────────

    /** The actions this app offers, from the `_actions` catalogue and the host handler. */
    public function actionCatalog(int $tenantId): array
    {
        return $this->wrap(fn (): array => ['items' => $this->actions()->catalog($tenantId)]);
    }

    /**
     * Ask before writing. Returns the proposal plus a single-use confirm token —
     * the host shows it, the user approves it, `confirmAction` runs it. A refusal
     * comes back as `ok: false` with the reason, so no confirm button gets built.
     *
     * @param UserContext|array<string,mixed> $user
     * @param array<string,mixed> $params
     * @param array<string,mixed> $meta conversation_id, channel, idempotency_key
     */
    public function previewAction(UserContext|array $user, string $action, array $params, array $meta = []): array
    {
        return $this->wrap(fn (): array => $this->actions()->propose($this->user($user), $action, $params, $meta));
    }

    /** @param UserContext|array<string,mixed>|null $user re-checked against the proposal */
    public function confirmAction(string $token, UserContext|array|null $user = null, ?string $idempotencyKey = null): array
    {
        return $this->wrap(fn (): array => $this->actions()->confirm($token, $user, $idempotencyKey));
    }

    public function cancelAction(string $id, UserContext|array|null $user = null): array
    {
        return $this->wrap(fn (): array => ['cancelled' => $this->actions()->cancel($id, $user)]);
    }

    public function getAction(string $id, int $tenantId): array
    {
        return $this->wrap(function () use ($id, $tenantId): array {
            $row = $this->actions()->get($id, $tenantId);
            if ($row === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such action run for this company.'];
            }

            return ['run' => $row];
        });
    }

    /** @param array<string,mixed> $filters */
    public function actionHistory(int $tenantId, ?string $userId = null, int $limit = 50, array $filters = []): array
    {
        return $this->wrap(fn (): array => ['items' => $this->actions()->history($tenantId, $userId, $limit, $filters)]);
    }

    /** Burn tokens nobody confirmed (a cron tick). */
    public function expireStaleActions(): array
    {
        return $this->wrap(fn (): array => ['expired' => $this->actions()->expireStale()]);
    }

    // ─── outbox ───────────────────────────────────────────────────────────────

    /**
     * Queue a delivery. The package only stores it; the host's notifier sends it.
     *
     * @param array<string,mixed> $message channel, recipient, subject, template, body, payload, attachments, scheduled_at
     */
    public function queueNotification(int $tenantId, array $message, ?string $userId = null): array
    {
        // queue() already answers with ok plus the id (or the reason it refused),
        // so it goes on the wire as it is instead of wrapped twice.
        return $this->wrap(fn (): array => $this->outbox()->queue($tenantId, $message, $userId));
    }

    /** @param array<int,array<string,mixed>> $messages */
    public function queueNotifications(int $tenantId, array $messages, ?string $userId = null): array
    {
        return $this->wrap(function () use ($tenantId, $messages, $userId): array {
            $batch = $this->outbox()->queueMany($tenantId, $messages, $userId);
            $refused = array_values(array_filter(
                (array) ($batch['results'] ?? []),
                static fn (array $r): bool => ($r['ok'] ?? false) !== true
            ));

            return $batch + [
                'ok' => $refused === [],
                'error' => $refused === [] ? null : count($refused) . ' of ' . count((array) ($batch['results'] ?? []))
                    . ' were refused: ' . (string) ($refused[0]['error'] ?? 'no recipient'),
            ];
        });
    }

    public function dueNotifications(int $limit = 0): array
    {
        return $this->wrap(fn (): array => ['items' => $this->outbox()->due($limit)]);
    }

    /** Deliver what is due, with the host's notifier. */
    public function flushOutbox(int $limit = 0): array
    {
        return $this->wrap(function () use ($limit): array {
            $results = $this->outbox()->flush($limit);
            $sent = count(array_filter($results, static fn (array $r): bool => (bool) ($r['ok'] ?? false)));

            return [
                'sent' => $sent,
                'failed' => count($results) - $sent,
                'pending' => count($this->outbox()->due()),
                'results' => $results,
            ];
        });
    }

    /** @param array<string,mixed> $filters */
    public function outboxHistory(int $tenantId, int $limit = 50, array $filters = []): array
    {
        return $this->wrap(fn (): array => ['items' => $this->outbox()->history($tenantId, $limit, $filters)]);
    }

    public function cancelNotification(string $id): array
    {
        return $this->wrap(fn (): array => ['cancelled' => $this->outbox()->cancel($id)]);
    }

    public function outboxCounts(int $tenantId): array
    {
        return $this->wrap(fn (): array => ['counts' => $this->outbox()->counts($tenantId)]);
    }

    // ─── documents ────────────────────────────────────────────────────────────

    /**
     * Index an upload. Accepts a `$_FILES['file']` entry, a raw byte string, a
     * path on the host's disk, or a base64 payload — all become one UploadedFile.
     *
     * @param UploadedFile|array<string,mixed>|string $file
     * @param UserContext|array<string,mixed> $user
     * @param array<string,mixed> $meta
     */
    public function uploadDocument($file, UserContext|array $user, array $meta = []): array
    {
        return $this->wrap(function () use ($file, $user, $meta): array {
            $context = $this->user($user);
            $row = $this->documents()->ingest(self::asFile($file), $context, $meta);

            return ['document' => $row];
        });
    }

    public function listDocuments(int $tenantId, int $limit = 100): array
    {
        return $this->wrap(fn (): array => ['items' => $this->documents()->list($tenantId, $limit)]);
    }

    public function getDocument(string $id, int $tenantId): array
    {
        return $this->wrap(function () use ($id, $tenantId): array {
            $row = $this->documents()->get($id, $tenantId);
            if ($row === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such document for this company.'];
            }

            return ['document' => $row];
        });
    }

    public function documentChunks(string $id, int $tenantId, int $limit = 200): array
    {
        return $this->wrap(fn (): array => ['items' => $this->documents()->chunks($id, $tenantId, $limit)]);
    }

    /** @param array<string,mixed> $filters */
    public function searchDocuments(int $tenantId, string $query, int $k = 5, array $filters = []): array
    {
        return $this->wrap(fn (): array => ['hits' => $this->documents()->search($tenantId, $query, $k, $filters)]);
    }

    public function reindexDocument(int $tenantId, string $id): array
    {
        return $this->wrap(fn (): array => ['document' => $this->documents()->reindex($tenantId, $id)]);
    }

    public function deleteDocument(string $id, int $tenantId): array
    {
        return $this->wrap(fn (): array => ['deleted' => $this->documents()->delete($id, $tenantId)]);
    }

    // ─── knowledge ────────────────────────────────────────────────────────────

    public function listKnowledge(int $tenantId, int $limit = 500): array
    {
        return $this->wrap(fn (): array => ['items' => $this->knowledge()->list($tenantId, $limit)]);
    }

    public function getKnowledge(int $id, int $tenantId): array
    {
        return $this->wrap(function () use ($id, $tenantId): array {
            $row = $this->knowledge()->find($id, $tenantId);
            if ($row === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such knowledge entry for this company.'];
            }

            return ['entry' => $row];
        });
    }

    /**
     * Teach one fact. `$input`: title, content, category, source, tags, roles,
     * effective_from, effective_until, updated_by, metadata.
     *
     * @param array<string,mixed> $input
     */
    public function addKnowledge(int $tenantId, array $input): array
    {
        return $this->wrap(function () use ($tenantId, $input): array {
            $title = trim((string) ($input['title'] ?? ''));
            $content = trim((string) ($input['content'] ?? ''));
            if ($title === '' || $content === '') {
                return ['ok' => false, 'error' => 'Knowledge needs both a title and the content to remember.'];
            }
            $extra = array_intersect_key($input, array_flip(
                ['tags', 'roles', 'effective_from', 'effective_until', 'updated_by', 'metadata', 'language', 'entity_type', 'entity_id']
            ));

            return ['entry' => $this->knowledge()->ingestRow(
                $tenantId,
                $title,
                $content,
                self::text($input['source'] ?? '') !== '' ? (string) $input['source'] : 'manual',
                isset($input['category']) ? (string) $input['category'] : null,
                $extra
            )];
        });
    }

    /** @param array<string,mixed> $changes */
    public function updateKnowledge(int $id, int $tenantId, array $changes): array
    {
        return $this->wrap(fn (): array => ['updated' => $this->knowledge()->update($id, $tenantId, $changes)]);
    }

    /** Switch an entry off rather than delete it: history keeps citing what was true. */
    public function setActiveKnowledge(int $id, int $tenantId, bool $active): array
    {
        return $this->wrap(fn (): array => [
            'changed' => $active ? $this->knowledge()->activate($id, $tenantId) : $this->knowledge()->deactivate($id, $tenantId),
        ]);
    }

    public function deleteKnowledge(int $id, int $tenantId): array
    {
        return $this->wrap(fn (): array => ['deleted' => $this->knowledge()->delete($id, $tenantId)]);
    }

    public function importKnowledgeCsv(int $tenantId, string $csv, string $source = 'csv'): array
    {
        return $this->wrap(fn (): array => $this->knowledge()->importCsv($tenantId, $csv, $source));
    }

    public function knowledgeTemplate(): array
    {
        return $this->wrap(fn (): array => ['csv' => $this->knowledge()->csvTemplate(), 'filename' => 'ai-knowledge-template.csv']);
    }

    public function knowledgeStats(int $tenantId): array
    {
        return $this->wrap(fn (): array => ['stats' => $this->knowledge()->stats($tenantId)]);
    }

    public function searchKnowledge(int $tenantId, string $query, int $k = 5, UserContext|array|null $user = null): array
    {
        return $this->wrap(function () use ($tenantId, $query, $k, $user): array {
            $context = $user === null ? null : $this->user($user);

            return ['hits' => $this->knowledge()->retrieve($tenantId, $query, $k, $context)];
        });
    }

    // ─── relations ────────────────────────────────────────────────────────────

    /**
     * What the package knows about key columns: "staff_id" points at
     * "staff_member", the name comes from "staff_member.user_fname", and how
     * sure it is.
     *
     * @param array<string,mixed> $filter status, source_table, limit
     */
    public function listRelations(int $tenantId, array $filter = []): array
    {
        return $this->wrap(fn (): array => ['items' => $this->relations()->list($tenantId, $filter)]);
    }

    /**
     * Look through the catalog for key columns and propose relations.
     *
     * @param array<string,mixed> $opts tables, sample, auto_verify
     */
    public function discoverRelations(int $tenantId, array $opts = []): array
    {
        return $this->wrap(function () use ($tenantId, $opts): array {
            $result = $this->relations()->discover($tenantId, $opts);

            return ['discovery' => $result];
        });
    }

    /** Accept a proposed relation; it joins the semantic layer from now on. */
    public function approveRelation(int $tenantId, int $id): array
    {
        return $this->wrap(function () use ($tenantId, $id): array {
            $relation = $this->relations()->approve($tenantId, $id);
            if ($relation === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such relation for this company.'];
            }

            return ['relation' => $relation];
        });
    }

    /** Refuse a relation. It never comes back, not even on the next scan. */
    public function rejectRelation(int $tenantId, int $id): array
    {
        return $this->wrap(function () use ($tenantId, $id): array {
            $relation = $this->relations()->reject($tenantId, $id);
            if ($relation === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such relation for this company.'];
            }

            return ['relation' => $relation];
        });
    }

    // ─── the CRM's own code ──────────────────────────────────────────────────

    /**
     * Read the application's code and remember what it runs.
     *
     * The scan starts at the navigation map, follows the pages it names and
     * collects the queries they contain. It is incremental: a file that has not
     * changed is not read again.
     *
     * @param array<string,mixed> $opts paths, max_files, max_size, max_depth
     */
    public function scanCode(int $tenantId, array $opts = []): array
    {
        return $this->wrap(function () use ($tenantId, $opts): array {
            $result = $this->code()->scan($tenantId, $opts);
            if (($result['error'] ?? '') !== '') {
                return ['ok' => false, 'code' => 'unavailable', 'error' => $result['error'], 'scan' => $result];
            }

            return ['scan' => $result];
        });
    }

    /** The files the index knows about. */
    public function codeFiles(int $tenantId): array
    {
        return $this->wrap(fn (): array => ['items' => $this->code()->files($tenantId)]);
    }

    /**
     * The queries the CRM already runs that match a question.
     *
     * @param array<string,mixed> $opts question, k
     */
    public function codeQueries(int $tenantId, array $opts = []): array
    {
        return $this->wrap(function () use ($tenantId, $opts): array {
            $question = trim((string) ($opts['question'] ?? ''));
            if ($question === '') {
                return ['items' => []];
            }

            return ['items' => $this->code()->knownQueries($tenantId, $question, max(1, (int) ($opts['k'] ?? 5)))];
        });
    }

    // ─── the query library ────────────────────────────────────────────────────

    /**
     * The calculations this CRM already has, by name.
     *
     * @param array<string,mixed> $filter status, module, limit
     */
    public function listQueries(int $tenantId, array $filter = []): array
    {
        return $this->wrap(fn (): array => ['items' => $this->queries()->list($tenantId, $filter)]);
    }

    /** One stored query. */
    public function getQuery(int $tenantId, int $id): array
    {
        return $this->wrap(function () use ($tenantId, $id): array {
            $query = $this->queries()->find($id, $tenantId);
            if ($query === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such query for this company.'];
            }

            return ['query' => $query];
        });
    }

    /**
     * Store a query: a name, what it calculates, and the statement behind it.
     *
     * @param array<string,mixed> $query
     */
    public function saveQuery(int $tenantId, array $query, ?string $updatedBy = null): array
    {
        return $this->wrap(fn (): array => ['query' => $this->queries()->save($tenantId, $query, $updatedBy)]);
    }

    /** Forget a query. */
    public function deleteQuery(int $tenantId, int $id): array
    {
        return $this->wrap(function () use ($tenantId, $id): array {
            if (!$this->queries()->delete($id, $tenantId)) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such query for this company.'];
            }

            return ['deleted' => $id];
        });
    }

    /**
     * Offer the queries the code scan found as proposals.
     *
     * A query somebody already described is left alone: their wording is
     * theirs, and a later scan must not overwrite it.
     */
    public function harvestQueries(int $tenantId, ?string $updatedBy = null): array
    {
        return $this->wrap(function () use ($tenantId, $updatedBy): array {
            $statements = $this->code()->statements($tenantId, 500);
            if ($statements === []) {
                return ['harvest' => ['created' => 0, 'skipped' => 0, 'items' => []],
                    'note' => 'The code index holds nothing yet. Run code.scan first.'];
            }

            return ['harvest' => $this->queries()->harvest($tenantId, $statements, $updatedBy)];
        });
    }

    // ─── the rules ────────────────────────────────────────────────────────────

    /**
     * The rules of this CRM, with their owner and their verification state.
     *
     * @param array<string,mixed> $filter verification, module, category, limit
     */
    public function listRules(int $tenantId, array $filter = []): array
    {
        return $this->wrap(fn (): array => ['items' => $this->rules()->list($tenantId, $filter)]);
    }

    /** One rule. */
    public function getRule(int $tenantId, int $id): array
    {
        return $this->wrap(function () use ($tenantId, $id): array {
            $rule = $this->rules()->find($id, $tenantId);
            if ($rule === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such rule for this company.'];
            }

            return ['rule' => $rule];
        });
    }

    /**
     * Store a rule: what the module is for, and the rule that decides a row.
     *
     * @param array<string,mixed> $rule
     */
    public function saveRule(int $tenantId, array $rule, ?string $updatedBy = null): array
    {
        return $this->wrap(fn (): array => ['rule' => $this->rules()->save($tenantId, $rule, $updatedBy)]);
    }

    /**
     * Confirm a rule — or send it back to draft.
     *
     * A rule that carries an example question is checked against the data
     * before it is confirmed: verification is a decision, and the decision needs
     * the answer in front of the person making it.
     *
     * @param array<string,mixed> $input verification, owner, example_question, check
     */
    public function verifyRule(int $tenantId, int $id, array $input = [], ?UserContext $user = null): array
    {
        return $this->wrap(function () use ($tenantId, $id, $input, $user): array {
            $rule = $this->rules()->find($id, $tenantId);
            if ($rule === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such rule for this company.'];
            }

            $question = trim((string) ($input['example_question'] ?? $rule['example_question']));
            $check = [];
            if ($question !== '' && ($input['check'] ?? true) !== false) {
                $check = $this->checkRule($question, $user);
            }

            $stored = $this->rules()->verify($id, $tenantId, [
                'verification' => (string) ($input['verification'] ?? RuleService::VERIFICATION_VERIFIED),
                'owner' => trim((string) ($input['owner'] ?? '')),
                'example_question' => $question,
                'check' => $check,
            ]);

            return ['rule' => $stored, 'check' => $check];
        });
    }

    /**
     * Ask the rule's example question and report what came back.
     *
     * A rule that contradicts the data — the question returns nothing — is
     * flagged, not quietly confirmed.
     *
     * @return array<string,mixed>
     */
    private function checkRule(string $question, ?UserContext $user): array
    {
        if ($user === null) {
            return ['status' => 'unchecked', 'question' => $question];
        }
        try {
            $answer = $this->assistant($user)->ask($question, $user);
        } catch (Throwable $e) {
            return ['status' => 'error', 'question' => $question, 'answer' => $e->getMessage()];
        }

        $rows = count($answer->rows);

        return [
            'status' => $rows > 0 ? 'ok' : 'empty',
            'question' => $question,
            'answer' => mb_substr($answer->text, 0, 600),
            'rows' => $rows,
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** Forget a rule. */
    public function deleteRule(int $tenantId, int $id): array
    {
        return $this->wrap(function () use ($tenantId, $id): array {
            if (!$this->rules()->delete($id, $tenantId)) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such rule for this company.'];
            }

            return ['deleted' => $id];
        });
    }

    /** The starter sheet for a host's modules and operations screen. */
    public function ruleSheetTemplate(): array
    {
        return $this->wrap(fn (): array => [
            'csv' => $this->rules()->sheetTemplate(),
            'filename' => 'ai-modules-template.csv',
            'columns' => RuleService::SHEET_COLUMNS,
        ]);
    }

    /**
     * Import the modules and operations sheet.
     *
     * Re-importing updates the rules the sheet owns and leaves every other
     * fact alone.
     */
    public function importRuleSheet(int $tenantId, string $csv, ?string $updatedBy = null): array
    {
        return $this->wrap(fn (): array => $this->rules()->importSheet($tenantId, $csv, $updatedBy));
    }

    // ─── the install ──────────────────────────────────────────────────────────

    /**
     * Read the CRM, draft its vocabulary, write it down and prove it works.
     *
     * @param array<string,mixed> $opts prune, code, relations, questions, files
     */
    public function discover(int $tenantId, array $opts = []): array
    {
        return $this->wrap(fn (): array => ['discovery' => $this->discovery()->run($tenantId, $opts)]);
    }

    /**
     * The drafted semantic layer and knowledge, without writing or running
     * anything — what an admin sees before committing to it.
     */
    public function discoverDraft(int $tenantId): array
    {
        return $this->wrap(fn (): array => [
            'pack' => $this->discovery()->draftPack($tenantId),
            'knowledge' => $this->discovery()->draftKnowledge($tenantId),
        ]);
    }

    /**
     * Load a committed knowledge file into the base.
     *
     * The pack file is read by the semantic layer on its own; the knowledge
     * file is loaded here, once, and then behaves like any other fact.
     */
    public function importDiscoveryKnowledge(int $tenantId, string $path, ?string $updatedBy = null): array
    {
        return $this->wrap(fn (): array => $this->discovery()->importKnowledgeFile($tenantId, $path, $updatedBy));
    }

    // ─── schema learning ──────────────────────────────────────────────────────

    /** Mirror the live structure into the catalog, keeping human descriptions. */
    public function syncSchema(array $opts = []): array
    {
        return $this->wrap(fn (): array => ['schema' => $this->schema()->syncSchema($opts)]);
    }

    public function listSchema(int $limit = 1000): array
    {
        return $this->wrap(function () use ($limit): array {
            $schema = $this->schema();
            if (!$schema->connected()) {
                return ['ok' => false, 'code' => 'unavailable',
                    'error' => 'No database connection for this schema service, so nothing is catalogued. '
                        . 'Hand the package the host PDO, or read the catalog from a dump instead.'];
            }

            return ['items' => $schema->listSchema($limit)];
        });
    }

    /** The catalog as structure: table → label, description, columns. */
    public function schemaCatalog(bool $policyFiltered = true): array
    {
        return $this->wrap(fn (): array => ['tables' => $this->schema()->catalog($policyFiltered)]);
    }

    /** What the model sees for one table — useful when a description is missing. */
    public function describeTable(string $table, int $maxColumns = 0): array
    {
        return $this->wrap(function () use ($table, $maxColumns): array {
            $columns = (array) ($this->schema()->catalog(false)[$table]['columns'] ?? []);
            if ($maxColumns > 0) {
                $columns = array_slice($columns, 0, $maxColumns);
            }

            return [
                'table' => $table,
                'description' => $this->schema()->describeTable($table, $maxColumns),
                'columns' => $columns,
            ];
        });
    }

    public function searchSchema(string $query, int $k = 6): array
    {
        return $this->wrap(fn (): array => ['hits' => $this->schema()->searchSchema($query, $k)]);
    }

    /**
     * Write down what a column means. This is the answer to "the model guessed my
     * `amount` column wrong" — a user's words win over every later re-sync.
     *
     * @param array<string,mixed> $meta label, table_label, sensitivity, format, aliases, is_measure, data_type
     */
    public function saveColumnDescription(string $table, string $column, ?string $description, array $meta = []): array
    {
        return $this->wrap(function () use ($table, $column, $description, $meta): array {
            if (trim($table) === '') {
                return ['ok' => false, 'error' => 'A description needs the table it belongs to.'];
            }
            // An empty column edits the table-level card in the same catalogue.

            return ['saved' => $this->schema()->saveDescription($table, $column, $description, $meta)];
        });
    }

    public function schemaTemplate(): array
    {
        return $this->wrap(fn (): array => ['csv' => $this->schema()->schemaTemplate(), 'filename' => 'ai-schema-template.csv']);
    }

    /** @param array<string,mixed> $opts description_source (default "import") */
    public function importSchemaDescriptions(string $csv, array $opts = []): array
    {
        return $this->wrap(fn (): array => $this->schema()->importDescriptions($csv, $opts));
    }

    /**
     * Learn the structure from a SQL dump the host uploaded — no connection to
     * that database needed. Table and column COMMENTs become descriptions.
     *
     * @param UploadedFile|array<string,mixed>|string $file
     * @param array<string,mixed> $opts
     */
    public function importSqlDump($file, array $opts = []): array
    {
        return $this->wrap(function () use ($file, $opts): array {
            $sql = self::asText($file);
            if (trim($sql) === '') {
                return ['ok' => false, 'error' => 'That file has no SQL in it.'];
            }

            $stats = $this->schema()->importFromSqlDump($sql, $opts);
            if ((int) ($stats['tables'] ?? 0) === 0) {
                return ['ok' => false, 'code' => 'nothing_learned',
                    'error' => (string) ($stats['unknown'] ?? 'No CREATE TABLE statement found in that file.')
                        . ' Send the schema-only dump your own exporter writes (mysqldump --no-data), '
                        . 'or train from a second connection.'];
            }

            return ['schema' => $stats];
        });
    }

    /** Learn from a second live connection the host opened (an uploaded database). */
    public function trainFromConnection(PDO $source, array $opts = []): array
    {
        return $this->wrap(fn (): array => ['schema' => $this->schema()->ingestFromConnection($source, $opts)]);
    }

    /**
     * Everything one tenant's training needs in one call: structure, lookup
     * values, derived metrics and row counts.
     */
    public function train(int $tenantId, array $opts = []): array
    {
        return $this->wrap(function () use ($tenantId, $opts): array {
            $schema = $this->schema();

            return [
                'schema' => $schema->syncSchema((array) ($opts['schema'] ?? [])),
                'references' => $schema->extractReferenceValues($tenantId, (array) ($opts['reference'] ?? [])),
                'metrics' => $schema->extractMetrics($tenantId, (array) ($opts['metrics'] ?? [])),
                'counts' => $schema->extractCounts($tenantId),
            ];
        });
    }

    /** Does this SQL obey the table/column policy? A cheap pre-flight for an admin tool. */
    public function validateSql(string $sql, ?int $tenantId = null): array
    {
        return $this->wrap(fn (): array => ['error' => $this->schema()->validateSql($sql, $tenantId)]);
    }

    public function referenceValues(int $tenantId, ?string $kind = null): array
    {
        return $this->wrap(fn (): array => ['items' => $this->schema()->listReference($tenantId, $kind)]);
    }

    /** Forget harvested lookup values (they were wrong, or the app changed). */
    public function forgetReferenceValues(int $tenantId, ?string $kind = null): array
    {
        return $this->wrap(fn (): array => ['removed' => $this->schema()->forgetReference($tenantId, $kind)]);
    }

    // ─── reports ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $input name + definition (+ visibility, folder, …) */
    public function createReport(array $input, UserContext|array|null $user = null): array
    {
        return $this->wrap(fn (): array => ['report' => ['id' => $this->reports()->create($input, $this->contextOrNull($user))]]);
    }

    public function updateReport(string $id, array $changes, int $tenantId): array
    {
        return $this->wrap(fn (): array => ['updated' => $this->reports()->update($id, $changes, $tenantId)]);
    }

    public function deleteReport(string $id, int $tenantId): array
    {
        return $this->wrap(fn (): array => ['deleted' => $this->reports()->delete($id, $tenantId)]);
    }

    public function getReport(string $id, int $tenantId): array
    {
        return $this->wrap(function () use ($id, $tenantId): array {
            $row = $this->reports()->get($id, $tenantId);
            if ($row === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such report for this company.'];
            }

            return ['report' => $row];
        });
    }

    public function listReports(int $tenantId, ?string $folder = null, UserContext|array|null $user = null): array
    {
        return $this->wrap(fn (): array => ['items' => $this->reports()->list($tenantId, $folder, $this->contextOrNull($user))]);
    }

    /**
     * Run a saved report. Without a `report_runner` the compiled request comes
     * back with `runner_missing` — the plan is the answer, the host executes it.
     *
     * @param array<string,mixed> $params
     */
    public function runReport(string $id, array $params, UserContext|array|null $user = null): array
    {
        return $this->wrap(fn (): array => ['result' => $this->reports()->run($id, $params, $this->contextOrNull($user))]);
    }

    /** @param array<string,mixed> $definition */
    public function validateReport(array $definition, string $name = ''): array
    {
        return $this->wrap(fn (): array => ['errors' => $this->reports()->validateDefinition($definition, $name)]);
    }

    public function reportHistory(string $id, int $tenantId, int $limit = 20): array
    {
        return $this->wrap(fn (): array => ['items' => $this->reports()->history($id, $tenantId, $limit)]);
    }

    /** A stable, revocable link to hand somebody a report. */
    public function shareReport(string $id, int $tenantId, bool $rotate = false): array
    {
        return $this->wrap(function () use ($id, $tenantId, $rotate): array {
            $token = $this->reports()->shareToken($id, $tenantId, $rotate);
            if ($token === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such report to share.'];
            }

            return ['token' => $token];
        });
    }

    public function duplicateReport(string $id, int $tenantId, UserContext|array|null $user = null): array
    {
        return $this->wrap(fn (): array => ['report' => ['id' => $this->reports()->duplicate($id, $tenantId, $this->contextOrNull($user))]]);
    }

    // ─── schedules ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $input name, cron, timezone, target (report id or alert payload),
     *                                   channel, recipient, format, state, company_id, user_id, type
     */
    public function createSchedule(array $input): array
    {
        return $this->wrap(fn (): array => ['schedule' => ['id' => $this->schedules()->create(
            (string) ($input['name'] ?? ''),
            (string) ($input['cron'] ?? ''),
            isset($input['timezone']) ? (string) $input['timezone'] : null,
            $input['target'] ?? null,
            (string) ($input['channel'] ?? 'email'),
            isset($input['recipient']) ? (string) $input['recipient'] : null,
            (string) ($input['format'] ?? 'pdf'),
            (string) ($input['state'] ?? 'active'),
            array_intersect_key($input, array_flip(['company_id', 'user_id', 'type', 'threshold', 'params', 'payload', 'description']))
        )]]);
    }

    /** @param array<string,mixed> $changes */
    public function updateSchedule(int|string $id, array $changes, int $tenantId): array
    {
        return $this->wrap(fn (): array => ['updated' => $this->schedules()->update($id, $changes, $tenantId)]);
    }

    public function pauseSchedule(int|string $id, int $tenantId): array
    {
        return $this->wrap(fn (): array => ['paused' => $this->schedules()->pause($id, $tenantId)]);
    }

    public function resumeSchedule(int|string $id, int $tenantId): array
    {
        return $this->wrap(fn (): array => ['resumed' => $this->schedules()->resume($id, $tenantId)]);
    }

    public function deleteSchedule(int|string $id, int $tenantId): array
    {
        return $this->wrap(fn (): array => ['deleted' => $this->schedules()->delete($id, $tenantId)]);
    }

    /** The jobs that are due — this is also how an admin lists schedules. */
    public function dueSchedules(int $limit = 200): array
    {
        return $this->wrap(fn (): array => ['items' => $this->schedules()->due(null, max(1, $limit))]);
    }

    /** Take the due jobs and advance them; the host delivers and calls markRun. */
    public function tickSchedules(?int $limit = null): array
    {
        return $this->wrap(fn (): array => ['jobs' => $this->schedules()->tick($limit)]);
    }

    public function markScheduleRun(int|string $id, bool $ok, ?string $error = null): array
    {
        return $this->wrap(fn (): array => ['state' => $this->schedules()->markRun($id, $ok, $error)]);
    }

    /** Preview a cron expression — the answer to "is that the right time?". */
    public function nextRuns(string $cron, int $count = 5, string $timezone = 'UTC'): array
    {
        return $this->wrap(fn (): array => ['runs' => $this->schedules()->nextRuns($cron, $count, $timezone)]);
    }

    // ─── feedback ─────────────────────────────────────────────────────────────

    /**
     * Thumbs up or down on an answer. `$target` is an answer/conversation id, or
     * the array the host got from `chat()` (it carries what it needs).
     *
     * @param string|int|array<string,mixed> $target
     * @param UserContext|array<string,mixed>|null $user
     */
    public function submitFeedback($target, string $rating, ?string $reason = null, ?string $comment = null, $user = null): array
    {
        return $this->wrap(fn (): array => [
            'feedback' => ['id' => $this->feedback()->submit($target, $rating, $reason, $comment, $this->contextOrNull($user))],
        ]);
    }

    public function feedbackList(int $tenantId, string $status = 'pending', int $limit = 50): array
    {
        return $this->wrap(fn (): array => ['items' => $this->feedback()->listForAdmin($tenantId, $status, $limit)]);
    }

    public function reviewFeedback(int $id, ?string $action = null, ?string $reviewedBy = null): array
    {
        return $this->wrap(fn (): array => ['reviewed' => $this->feedback()->markReviewed($id, $action, $reviewedBy)]);
    }

    public function feedbackSummary(int $tenantId, string $from, string $to): array
    {
        return $this->wrap(fn (): array => ['aggregate' => $this->feedback()->aggregate($tenantId, $from, $to)]);
    }

    /** Turn a correction into a knowledge entry, so the same mistake is not repeated. */
    public function feedbackToKnowledge(int $id): array
    {
        return $this->wrap(fn (): array => ['result' => $this->feedback()->convertToKnowledge($id)]);
    }

    // ─── usage, limits, audit, settings ───────────────────────────────────────

    public function usageToday(int $tenantId, ?int $userId = null): array
    {
        return $this->wrap(fn (): array => ['usage' => $this->usage()->today($tenantId, $userId)]);
    }

    public function usageRange(int $tenantId, string $from, string $to, ?int $userId = null): array
    {
        return $this->wrap(fn (): array => ['usage' => $this->usage()->range($tenantId, $from, $to, $userId)]);
    }

    public function usageByUser(int $tenantId, string $from, string $to, int $limit = 50): array
    {
        return $this->wrap(fn (): array => ['items' => $this->usage()->byUser($tenantId, $from, $to, $limit)]);
    }

    public function topQuestions(int $tenantId, string $from, string $to, int $limit = 20): array
    {
        return $this->wrap(fn (): array => ['items' => $this->usage()->topQuestions($tenantId, $from, $to, $limit)]);
    }

    /** Would this request be allowed? Check before spending a model call. */
    public function checkLimit(string $dimension, string $key, string $window = 'hour', ?int $overrideMax = null): array
    {
        return $this->wrap(fn (): array => ['limit' => $this->usage()->checkLimit($dimension, $key, $window, $overrideMax)]);
    }

    /** Count the request as spent, returning the same shape as checkLimit(). */
    public function consumeLimit(string $dimension, string $key, string $window = 'hour', ?int $overrideMax = null, int $amount = 1): array
    {
        return $this->wrap(fn (): array => ['limit' => $this->usage()->consume($dimension, $key, $window, $overrideMax, $amount)]);
    }

    /** @param array<string,mixed> $filter company_id, user_id, event, intent, status, from, to… */
    public function auditTrail(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        return $this->wrap(fn (): array => ['items' => $this->audit()->query($filter, $limit, $offset)]);
    }

    /** @param array<string,mixed> $filter */
    public function auditSummary(array $filter = []): array
    {
        return $this->wrap(fn (): array => ['summary' => $this->audit()->summary($filter)]);
    }

    /** @param array<string,mixed> $filter */
    public function auditCsv(array $filter = [], int $maxRows = 5000): array
    {
        return $this->wrap(fn (): array => ['csv' => $this->audit()->exportCsv($filter, $maxRows), 'filename' => 'ai-audit.csv']);
    }

    /**
     * Is the audit trail present and readable? There is no tamper-evident hash
     * chain in this package — an auditor who needs one points `audit.store' at a
     * host sink that writes to append-only storage.
     */
    public function auditVerify(): array
    {
        return $this->wrap(fn (): array => ['verified' => $this->audit()->verify()]);
    }

    /** Write an event that happened outside the assistant (a host button, a job). */
    public function recordAudit(array $context, string $action, array $detail = []): array
    {
        return $this->wrap(fn (): array => ['trace_id' => $this->audit()->log($context, $action, $detail)]);
    }

    public function getSetting(string $key, mixed $default = null, ?array $scope = null): array
    {
        return $this->wrap(fn (): array => ['value' => $this->settings()->get($key, $default, $scope)]);
    }

    public function putSetting(string $key, mixed $value, ?array $scope = null, ?string $updatedBy = null): array
    {
        return $this->wrap(fn (): array => ['saved' => $this->settings()->put($key, $value, $scope, $updatedBy)]);
    }

    public function forgetSetting(string $key, ?array $scope = null): array
    {
        return $this->wrap(fn (): array => ['removed' => $this->settings()->forget($key, $scope)]);
    }

    public function settingsList(?array $scope = null): array
    {
        return $this->wrap(fn (): array => ['items' => $this->settings()->allFor($scope)]);
    }

    /** Config + this tenant's overrides, the way the assistant would see them. */
    public function effectiveConfig(int $tenantId, ?int $userId = null): array
    {
        return $this->wrap(fn (): array => ['config' => $this->settings()->mergeConfig($tenantId, $userId)]);
    }

    // ─── settings stored in the database ({prefix}_config) ────────────────────

    /**
     * The settings screen: every DB-editable setting, its type, allowed values,
     * default and current value. No secrets are included — api keys and class
     * registrations stay in env/config (Support\ConfigCatalog explains why).
     */
    public function describeConfig(int $companyId = 0): array
    {
        return $this->wrap(fn (): array => $this->configuration()->describe($companyId));
    }

    /** What this tenant stores in the database, and how each path resolves. */
    public function getConfig(int $companyId = 0, ?string $path = null): array
    {
        return $this->wrap(function () use ($companyId, $path): array {
            $service = $this->configuration();
            if ($path !== null && trim($path) !== '') {
                $all = $service->effective($companyId);
                $path = trim($path);

                return isset($all[$path])
                    ? ['path' => $path, 'setting' => $all[$path]]
                    : ['ok' => false, 'code' => 'not_found', 'error' => 'No editable setting "' . $path . '".'];
            }

            return ['settings' => $service->overrides($companyId), 'effective' => $service->effective($companyId)];
        });
    }

    /**
     * Save settings for a tenant (company_id = 0 writes the deployment-wide row).
     * Values are validated against the catalog and coerced; a null value clears
     * an override. Returns what saved, what was unknown and per-path errors.
     *
     * @param array<string,mixed> $settings
     */
    public function saveConfig(array $settings, int $companyId = 0, ?string $updatedBy = null): array
    {
        return $this->wrap(function () use ($settings, $companyId, $updatedBy): array {
            $result = $this->configuration()->save($settings, $companyId, $updatedBy);
            // The API may keep serving after this call in one process.
            Config::refreshSettings();

            return $result + ['settings' => $this->configuration()->overrides($companyId)];
        });
    }

    /**
     * Remove overrides so the paths fall back to the config file/env again.
     *
     * @param array<int,string> $paths
     */
    public function forgetConfig(array $paths, int $companyId = 0): array
    {
        return $this->wrap(function () use ($paths, $companyId): array {
            $result = $this->configuration()->forget($paths, $companyId);
            Config::refreshSettings();

            return $result + ['settings' => $this->configuration()->overrides($companyId)];
        });
    }

    // ─── conversations ────────────────────────────────────────────────────────

    public function listConversations(int $tenantId, string $userId, int $limit = 50): array
    {
        return $this->wrap(fn (): array => ['items' => $this->storeOrFail()->listForUser($tenantId, $userId, $limit)]);
    }

    public function getConversation(string $id, int $limit = 20): array
    {
        return $this->wrap(function () use ($id, $limit): array {
            $store = $this->storeOrFail();
            $row = $store->getConversation($id);
            if ($row === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'No such conversation.'];
            }

            return ['conversation' => $row, 'turns' => $store->transcript($id, $limit)];
        });
    }

    public function transcript(string $id, int $limit = 200): array
    {
        return $this->wrap(fn (): array => ['turns' => $this->storeOrFail()->transcript($id, $limit)]);
    }

    public function searchConversations(int $tenantId, string $term, int $limit = 30): array
    {
        return $this->wrap(fn (): array => ['items' => $this->storeOrFail()->search($tenantId, $term, $limit)]);
    }

    public function renameConversation(string $id, string $title): array
    {
        return $this->wrap(fn (): array => ['renamed' => $this->storeOrFail()->rename($id, $title)]);
    }

    public function pinConversation(string $id, bool $pinned): array
    {
        return $this->wrap(fn (): array => ['pinned' => $this->storeOrFail()->setPinned($id, $pinned)]);
    }

    public function shareConversation(string $id): array
    {
        return $this->wrap(fn (): array => ['token' => $this->storeOrFail()->share($id)]);
    }

    public function revokeConversationShare(string $id): array
    {
        return $this->wrap(fn (): array => ['revoked' => $this->storeOrFail()->revokeShare($id)]);
    }

    public function sharedConversation(string $token): array
    {
        return $this->wrap(function () use ($token): array {
            $row = $this->storeOrFail()->findByShare($token);
            if ($row === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'That shared link is no longer open.'];
            }

            return ['conversation' => $row];
        });
    }

    public function summarizeConversation(string $id): array
    {
        return $this->wrap(fn (): array => ['summary' => $this->storeOrFail()->summarize($id)]);
    }

    public function deleteConversation(string $id): array
    {
        return $this->wrap(fn (): array => ['deleted' => $this->storeOrFail()->delete($id)]);
    }

    // ─── whatsapp ─────────────────────────────────────────────────────────────

    /**
     * Flatten a webhook body into customer messages — Meta's nesting, and the
     * already-flat shapes, both work.
     *
     * @param array<string,mixed> $payload
     */
    public function inboundWhatsApp(array $payload): array
    {
        return $this->wrap(fn (): array => ['messages' => WhatsAppChannel::fromWebhook($payload)]);
    }

    /**
     * The whole webhook in one call: read every message, answer it, send the
     * reply. Needs a sender — `services['whatsapp_sender']` or the host's own
     * `services['channel']` — because only the host knows its WhatsApp API.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $defaults tenant/user fields merged into every message's context
     */
    public function answerWhatsApp(array $payload, array $defaults = []): array
    {
        return $this->wrap(function () use ($payload, $defaults): array {
            $channel = $this->whatsappChannel();
            if ($channel === null) {
                return ['ok' => false, 'code' => 'unavailable',
                    'error' => 'Pass services["whatsapp_sender"] (a callable(string $to, string $body, array $meta)) '
                        . 'or a ChannelInterface to answer on WhatsApp.'];
            }
            $replies = [];
            foreach (WhatsAppChannel::fromWebhook($payload) as $inbound) {
                $text = trim((string) ($inbound['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $context = $this->user(array_merge($defaults, [
                    'user_id' => (string) ($inbound['from'] ?? 'anonymous'),
                    'channel' => 'whatsapp',
                    'thread_id' => (string) ($inbound['from'] ?? ''),
                ]));
                $answer = $this->assistant($context)->ask($text, $context);
                $this->countTurn($context);
                $replies[] = [
                    'to' => (string) ($inbound['from'] ?? ''),
                    'result' => $channel->send($answer, WhatsAppChannel::outboundFor($inbound, '', (array) ($inbound['meta'] ?? []))),
                    'answer' => $answer->toArray(),
                ];
            }

            return ['replies' => $replies];
        });
    }

    // ─── meta ─────────────────────────────────────────────────────────────────

    /**
     * What is wired, what is missing, what a page of support would need to know.
     * Secrets are reported as "set"/"unset" and never echoed.
     */
    public function diagnostics(): array
    {
        $llmError = null;
        try {
            $this->llm();
        } catch (Throwable $e) {
            $llmError = $e->getMessage();
        }

        $tables = [];
        foreach (self::CORE_TABLES as $suffix) {
            $tables[$suffix] = $this->pdo !== null ? Db::tableExists($this->pdo, $suffix) : null;
        }
        $legacy = [];
        foreach (self::LEGACY_TABLES as $suffix) {
            $legacy[$suffix] = $this->pdo !== null ? Db::tableExists($this->pdo, $suffix) : null;
        }

        $formats = null;
        $formatError = null;
        try {
            $formats = $this->exports()->formats();
        } catch (Throwable $e) {
            $formatError = $e->getMessage();
        }

        return [
            'ok' => true,
            'package' => ['name' => 'excelle-insights/ai-assistant', 'table_prefix' => TablePrefix::get()],
            'database' => [
                'connected' => $this->pdo !== null,
                'tables' => $tables,
                'legacy_tables' => $legacy,
                'missing' => array_keys(array_filter($tables, static fn ($seen): bool => $seen === false)),
            ],
            'llm' => [
                'provider' => Config::string('llm.provider', 'openai'),
                'model' => Config::string('llm.model', '') ?: null,
                'api_key' => self::text(Config::get('llm.api_key', '')) !== '' ? 'set' : 'unset',
                'ready' => $llmError === null,
                'error' => $llmError,
            ],
            'features' => Config::list('features'),
            'settings' => [
                'table' => Db::table('config'),
                'present' => $this->pdo !== null ? Db::tableExists($this->pdo, 'config') : null,
                'database_layer' => Config::hasSettingsLayer(),
                'editable' => count(ConfigCatalog::paths()),
                'overrides' => array_keys(Config::settingsValues()),
            ],
            'policy' => $this->policy()->describe(),
            'exports' => ['formats' => $formats, 'directory' => ExportService::directory(), 'error' => $formatError],
            'limits' => (array) Config::get('limits', []),
            'wiring' => [
                'host_assistant' => isset($this->services['assistant']),
                'permissions' => $this->permissions() !== null,
                'action_handler' => $this->actionHandler() !== null,
                'notifier' => isset($this->services['notifier']),
                'document_store' => $this->given('document_store', DocumentStoreInterface::class) !== null,
                'chunk_index' => $this->given('chunk_index', ChunkIndexInterface::class) !== null,
                'report_runner' => isset($this->services['report_runner']),
                'whatsapp_sender' => isset($this->services['whatsapp_sender']) || isset($this->services['channel']),
                'extra_hooks' => array_keys((array) ($this->services['hooks'] ?? [])),
            ],
        ];
    }

    /** The version string composer would have installed this from. */
    public const VERSION = 'dev';

    // ─── assembly ─────────────────────────────────────────────────────────────

    private function made(string $key, callable $build): mixed
    {
        if (!array_key_exists($key, $this->made)) {
            $this->made[$key] = $build();
        }

        return $this->made[$key];
    }

    /** @param class-string $class */
    private function given(string $key, string $class): ?object
    {
        $value = $this->services[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!$value instanceof $class) {
            throw new InvalidArgumentException('services["' . $key . '"] must be an instance of ' . $class . '.');
        }

        return $value;
    }

    private function llm(): LlmClientInterface
    {
        /** @var LlmClientInterface */
        return $this->made('llm', fn () => $this->given('llm', LlmClientInterface::class) ?? LlmFactory::make());
    }

    /** A model the optional collaborators can go without. */
    private function llmOrNull(): ?LlmClientInterface
    {
        try {
            return $this->llm();
        } catch (Throwable) {
            return null;
        }
    }

    private function policy(): SchemaPolicy
    {
        /** @var SchemaPolicy */
        return $this->made('policy', function (): SchemaPolicy {
            $injected = $this->given('policy', SchemaPolicy::class);
            if ($injected !== null) {
                /** @var SchemaPolicy */
                return $injected;
            }
            $host = $this->services['table_policy'] ?? null;

            return new SchemaPolicy(null, $host instanceof TablePolicyInterface ? $host : null);
        });
    }

    private function schema(): SchemaService
    {
        /** @var SchemaService */
        return $this->made('schema', fn () => $this->given('schema', SchemaService::class)
            ?? new SchemaService($this->pdo, $this->policy(), (array) ($this->options['schema'] ?? [])));
    }

    private function charts(): ChartService
    {
        /** @var ChartService */
        return $this->made('charts', fn () => $this->given('charts', ChartService::class) ?? new ChartService());
    }

    private function knowledge(): KnowledgeService
    {
        /** @var KnowledgeService */
        return $this->made('knowledge', fn () => $this->given('knowledge', KnowledgeService::class)
            ?? new KnowledgeService($this->pdo, $this->llmOrNull(), (array) ($this->options['knowledge'] ?? [])));
    }

    /** What a key column stands for: the relations the semantic layer may use. */
    private function relations(): RelationService
    {
        /** @var RelationService */
        return $this->made('relations', fn () => $this->given('relations', RelationService::class)
            ?? new RelationService(
                $this->pdo,
                $this->schema(),
                $this->policy(),
                (array) ($this->options['relations'] ?? []),
            ));
    }

    /** The CRM's own code, read once and remembered. */
    private function code(): CodeIndexService
    {
        /** @var CodeIndexService */
        return $this->made('code', fn () => $this->given('code', CodeIndexService::class)
            ?? new CodeIndexService(
                $this->pdo,
                $this->knowledge(),
                $this->given('code_scanner', CodeScannerInterface::class),
                (array) ($this->options['code'] ?? []),
            ));
    }

    /** The calculations this CRM already has, by name. */
    private function queries(): QueryLibraryService
    {
        /** @var QueryLibraryService */
        return $this->made('queries', fn () => $this->given('queries', QueryLibraryService::class)
            ?? new QueryLibraryService($this->pdo, (array) ($this->options['queries'] ?? [])));
    }

    /** The business rules of this CRM, owned and verified. */
    private function rules(): RuleService
    {
        /** @var RuleService */
        return $this->made('rules', fn () => $this->given('rules', RuleService::class)
            ?? new RuleService($this->knowledge(), (array) ($this->options['rules'] ?? [])));
    }

    /** The install: read the CRM, draft its vocabulary, write it down. */
    private function discovery(): DiscoveryService
    {
        /** @var DiscoveryService */
        return $this->made('discovery', fn () => $this->given('discovery', DiscoveryService::class)
            ?? new DiscoveryService(
                $this->pdo,
                $this->schema(),
                $this->relations(),
                $this->code(),
                $this->knowledge(),
                $this->policy(),
                (array) ($this->options['discovery'] ?? []),
            ));
    }

    private function documents(): DocumentService
    {
        /** @var DocumentService */
        return $this->made('documents', function (): DocumentService {
            $injected = $this->given('documents', DocumentService::class);

            return $injected instanceof DocumentService ? $injected : new DocumentService(
                $this->pdo,
                $this->llmOrNull(),
                $this->given('extractors', ExtractorRegistry::class),
                $this->given('chunk_index', ChunkIndexInterface::class),
                $this->given('document_store', DocumentStoreInterface::class),
                (array) ($this->options['documents'] ?? []),
            );
        });
    }

    private function audit(): AuditService
    {
        /** @var AuditService */
        return $this->made('audit', fn () => $this->given('audit', AuditService::class)
            ?? new AuditService($this->pdo, $this->onError(), $this->policy()));
    }

    private function usage(): UsageService
    {
        /** @var UsageService */
        return $this->made('usage', fn () => $this->given('usage', UsageService::class)
            ?? new UsageService($this->pdo, $this->onError()));
    }

    private function settings(): SettingsService
    {
        /** @var SettingsService */
        return $this->made('settings', fn () => $this->given('settings', SettingsService::class)
            ?? new SettingsService($this->pdo, $this->onError()));
    }

    private function configuration(): ConfigService
    {
        /** @var ConfigService */
        return $this->made('configuration', fn () => $this->given('configuration', ConfigService::class)
            ?? new ConfigService($this->pdo));
    }

    /**
     * Feed {prefix}_config into Config, so policy, features, limits and the rest
     * can be edited from a settings screen instead of env. Lazy: the row is read
     * on the first config lookup, and a missing table simply contributes nothing
     * (an install that updated the package but has not migrated yet still runs).
     */
    private function attachDatabaseSettings(): void
    {
        if ($this->pdo === null || ($this->options['settings']['enabled'] ?? true) === false) {
            return;
        }

        // Read before the layer exists: tenant.id itself stays in env/config.
        $companyId = Config::int('tenant.id', 0);
        $service = $this->configuration();
        Config::useSettings(fn (): array => $service->overrides($companyId));
    }

    private function feedback(): FeedbackService
    {
        /** @var FeedbackService */
        return $this->made('feedback', fn () => $this->given('feedback', FeedbackService::class)
            ?? new FeedbackService($this->pdo, $this->onError()));
    }

    private function schedules(): SchedulesService
    {
        /** @var SchedulesService */
        return $this->made('schedules', fn () => $this->given('schedules', SchedulesService::class)
            ?? new SchedulesService($this->pdo, $this->onError()));
    }

    private function permissions(): ?PermissionResolverInterface
    {
        $value = $this->given('permissions', PermissionResolverInterface::class);

        return $value instanceof PermissionResolverInterface ? $value : null;
    }

    private function actionHandler(): ?ActionHandlerInterface
    {
        $value = $this->given('action_handler', ActionHandlerInterface::class);

        return $value instanceof ActionHandlerInterface ? $value : null;
    }

    private function exports(): ExportService
    {
        /** @var ExportService */
        return $this->made('exports', fn () => $this->given('export', ExportService::class)
            ?? new ExportService($this->pdo, $this->policy(), $this->audit(), $this->permissions(), $this->exportRows()));
    }

    /**
     * Re-run a queued or approved export from the plan stored with it. The rows
     * come back through the same compile-and-scope path a question takes, so an
     * approval made by a manager still reads only what that manager may read.
     */
    private function exportRows(): ?callable
    {
        if ($this->runner() === null || ($this->semantic()['compiler'] ?? null) === null) {
            return null;
        }

        return function (ExportRequest $request) {
            $plan = (array) ($request->options['plan'] ?? []);
            if ($plan === []) {
                return null;
            }
            $run = $this->assistant($request->user)->run($plan, $request->user);

            return $run['result'] ?? null;
        };
    }

    private function actions(): ActionService
    {
        /** @var ActionService */
        return $this->made('actions', fn () => $this->given('actions', ActionService::class)
            ?? new ActionService($this->pdo, $this->actionHandler(), $this->permissions(), $this->audit()));
    }

    private function outbox(): OutboxService
    {
        /** @var OutboxService */
        return $this->made('outbox', function (): OutboxService {
            $injected = $this->given('outbox', OutboxService::class);
            if ($injected instanceof OutboxService) {
                return $injected;
            }
            $sender = $this->services['notifier'] ?? null;
            if ($sender !== null && !is_callable($sender) && !$sender instanceof NotificationInterface) {
                throw new InvalidArgumentException('services["notifier"] must be callable or a NotificationInterface.');
            }

            return new OutboxService($this->pdo, $sender);
        });
    }

    private function reports(): ReportService
    {
        /** @var ReportService */
        return $this->made('reports', function (): ReportService {
            $injected = $this->given('reports', ReportService::class);
            if ($injected instanceof ReportService) {
                return $injected;
            }
            $runner = $this->services['report_runner'] ?? null;
            if ($runner !== null && !is_callable($runner)) {
                throw new InvalidArgumentException('services["report_runner"] must be callable(array $definition, array $params, ?UserContext $ctx).');
            }

            return new ReportService($this->pdo, $runner, $this->onError(), $this->audit());
        });
    }

    private function conversations(): ?ConversationStoreInterface
    {
        return $this->made('conversations', function (): ?ConversationStoreInterface {
            $injected = $this->services['conversations'] ?? null;
            if ($injected instanceof ConversationStoreInterface) {
                return $injected;
            }
            if ($this->pdo === null) {
                return null;
            }

            return new ConversationStore($this->pdo, $this->llmOrNull(), (array) ($this->options['conversations'] ?? []));
        });
    }

    private function storeOrFail(): ConversationStoreInterface
    {
        $store = $this->conversations();
        if ($store === null) {
            throw new \RuntimeException('No conversation store: this surface has no database connection.');
        }

        return $store;
    }

    /** The host's rows provider, or one that reads the connection it was given. */
    private function runner(): ?callable
    {
        return $this->made('runner', function (): ?callable {
            $injected = $this->services['runner'] ?? null;
            if ($injected instanceof SqlQueryRunner) {
                return $injected;
            }
            if (is_callable($injected)) {
                return $injected;
            }
            if ($this->pdo === null) {
                return null;
            }

            return new SqlQueryRunner($this->pdo);
        });
    }

    /**
     * The runtime, assembled once per request. The `rows`, `audit`, `usage`,
     * `system_context`, `documents` and `export` hooks are all wired to package
     * services here, with the requesting user's tenant for accounting — a host
     * that wants its own behaviour passes `services['hooks']` and those win.
     */
    public function assistant(?UserContext $user = null): AssistantService
    {
        $injected = $this->given('assistant', AssistantService::class);
        if ($injected instanceof AssistantService) {
            return $injected;
        }

        $semantic = $this->semantic((int) ($user?->tenantId ?? 0));
        $features = array_map('strval', Config::list('features'));
        $hooks = [];
        $runner = $this->runner();
        if ($runner !== null) {
            $hooks['rows'] = $runner;
        }
        $hooks['audit'] = fn (string $action, array $detail): string => $this->audit()->log($detail, $action);
        $tenant = (int) ($user?->tenantId ?? Config::get('tenant.id', 0));
        $accountUser = (string) ($user?->userId ?? '');
        $hooks['usage'] = function (array $usage) use ($tenant, $accountUser): void {
            $this->usage()->record($usage + ['company_id' => $tenant, 'user_id' => $accountUser]);
        };
        $hooks['system_context'] = fn (UserContext $who): array => $this->settings()->mergeConfig(
            $who->tenantId,
            self::isNumeric((string) $who->userId) ? (int) $who->userId : null
        );
        // A knowledge service with nothing to read would answer "I don't know"
        // forever, so an install without a database says "trained knowledge is not
        // available here" instead of claiming it is.
        $knowledge = $this->pdo !== null || isset($this->services['knowledge']) ? $this->knowledge() : null;
        if ($this->pdo !== null && self::hasFeature($features, 'documents')) {
            $hooks['documents'] = fn (UserContext $who, string $question, int $k): array =>
                $this->documents()->search($who->tenantId, $question, $k);
        }
        if ($this->pdo !== null && self::hasFeature($features, 'exports')) {
            $hooks['export'] = fn (mixed $result, string $format, UserContext $who, array $meta): ExportResult =>
                $this->exports()->create($result, $format, $who, $meta);
        }

        return new AssistantService(
            llm: $this->llm(),
            conversations: $this->conversations(),
            pack: $semantic['pack'],
            compiler: $semantic['compiler'],
            knowledge: $knowledge,
            permissions: $this->permissions(),
            charts: $this->charts(),
            actions: $this->actionHandler(),
            reports: self::hasFeature($features, 'reports') ? $this->reports() : null,
            policy: $this->policy(),
            hooks: array_merge($hooks, array_filter((array) ($this->services['hooks'] ?? []), 'is_callable')),
            options: (array) ($this->options['assistant'] ?? []),
            code: $this->pdo !== null ? $this->code() : null,
            queries: $this->pdo !== null ? $this->queries() : null,
            rules: $this->pdo !== null ? $this->rules() : null,
        );
    }

    /**
     * Datasets and the compiler, read from the semantic packs plus whatever the
     * catalog already knows. A catalog nobody has synced is not an error: the
     * assistant answers from knowledge and documents and says so.
     *
     * `$tenantId` decides which approved relations apply: the deployment-wide
     * ones plus the company's own.
     *
     * @return array{pack:DomainPack,compiler:?QueryCompiler,catalog:array<string,mixed>,columns:array<string,string[]>}
     */
    private function semantic(?int $tenantId = null): array
    {
        $tenant = max(0, (int) $tenantId);
        return $this->made('semantic' . ($tenant > 0 ? '.' . $tenant : ''), function () use ($tenant): array {
            $options = (array) ($this->options['semantic'] ?? []);
            $catalog = $options['catalog'] ?? null;
            if (!is_array($catalog)) {
                try {
                    // Policy-filtered: a blocked table must not become a dataset
                    // just because its structure is still in the catalogue.
                    $catalog = $this->schema()->catalog(true);
                } catch (Throwable) {
                    $catalog = [];
                }
            }
            $columns = [];
            foreach ($catalog as $table => $entry) {
                $names = [];
                foreach ((array) ($entry['columns'] ?? []) as $column) {
                    $name = self::text($column['name'] ?? '');
                    if ($name !== '') {
                        $names[] = $name;
                    }
                }
                $columns[self::text($table)] = $names;
            }
            // A key column that nobody described still becomes a name, as long as
            // a verified relation says what it points at.
            $relations = $this->approvedRelations($tenant);
            $pack = DomainPack::load(null, array_merge($options, [
                'catalog' => $catalog,
                'relations' => $relations,
                'allowed_tables' => $this->policy()->allowedTables(),
            ]));
            $compiler = $this->given('compiler', QueryCompiler::class);
            if ($compiler === null && $pack->hasDatasets()) {
                $compiler = new QueryCompiler($pack, $this->policy(), $columns, (array) ($options['compiler'] ?? []));
            }

            return ['pack' => $pack, 'compiler' => $compiler, 'catalog' => $catalog, 'columns' => $columns];
        });
    }

    /**
     * The relations the semantic layer may use for this company: verified ones
     * whose target table the host's policy allows.
     *
     * @return array<int,array<string,mixed>>
     */
    private function approvedRelations(int $tenantId): array
    {
        try {
            return $this->relations()->approved($tenantId);
        } catch (Throwable) {
            return [];
        }
    }

    private function whatsappChannel(): ?ChannelInterface
    {
        return $this->made('channel', function (): ?ChannelInterface {
            $injected = $this->services['channel'] ?? null;
            if ($injected instanceof ChannelInterface) {
                return $injected;
            }
            $sender = $this->services['whatsapp_sender'] ?? null;
            if (!is_callable($sender)) {
                return null;
            }

            return new WhatsAppChannel($sender, (array) ($this->options['channel'] ?? []));
        });
    }

    private function onError(): ?callable
    {
        $handler = $this->services['on_error'] ?? null;

        return is_callable($handler) ? $handler : null;
    }

    // ─── internals ────────────────────────────────────────────────────────────

    /**
     * Run one piece of work and always answer with the same shape: `ok` plus
     * either the payload or a sentence a human can act on.
     */
    private function wrap(callable $work): array
    {
        try {
            $result = $work();
            if (!is_array($result)) {
                $result = ['result' => $result];
            }
            if (array_key_exists('ok', $result)) {
                return $result;
            }

            return array_merge(['ok' => !isset($result['error']) || $result['error'] === null], $result);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'type' => (new \ReflectionClass($e))->getShortName()];
        }
    }

    private function user(mixed $user): UserContext
    {
        if ($user instanceof UserContext) {
            return $user;
        }
        if ($user instanceof UserContextInterface) {
            return UserContext::fromInterface($user);
        }
        if (is_array($user)) {
            return UserContext::fromArray($user);
        }

        throw new InvalidArgumentException(
            'A user is needed: pass a UserContext, a UserContextInterface, or an array like '
            . '{"user_id": "7", "tenant_id": 3, "roles": ["admin"]}.'
        );
    }

    private function userOrNull(mixed $user): ?UserContext
    {
        try {
            return $user === null ? null : $this->user($user);
        } catch (Throwable) {
            return null;
        }
    }

    private function contextOrNull(mixed $user): ?UserContext
    {
        return $user === null ? null : $this->user($user);
    }

    /** @return array<string,mixed> */
    private static function resultArray(mixed $result, string $sql): array
    {
        if ($result instanceof QueryResult) {
            return [
                'columns' => $result->columns,
                'rows' => $result->rows,
                'sql' => $result->sql ?? $sql,
                'applied_filters' => $result->appliedFilters,
                'truncated' => $result->truncated,
                'source' => $result->source,
                'record_refs' => $result->recordRefs,
                'count' => $result->count(),
            ];
        }
        if (is_array($result)) {
            $rows = array_values(array_filter($result, 'is_array'));

            return ['columns' => array_keys((array) ($rows[0] ?? [])), 'rows' => $rows, 'sql' => $sql, 'count' => count($rows)];
        }

        return ['columns' => [], 'rows' => [], 'sql' => $sql, 'count' => 0];
    }

    /**
     * `AssistantService::run()` answers with a QueryResult object next to what the
     * compiler knew. The wire wants plain arrays — and the SQL travels only when
     * that user may see it, which is exactly when the service put it here.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private static function runArray(array $run): array
    {
        $rows = self::resultArray($run['result'] ?? null, (string) ($run['sql'] ?? ''));
        $rows['sql'] = $run['sql'] ?? null;
        $rows['applied_filters'] = (array) ($run['applied_filters'] ?? []);
        $rows['chart'] = (array) ($run['chart'] ?? []);

        return $rows;
    }

    /**
     * An export as the wire should see it: status, the id and token to hand back,
     * and no file contents.
     *
     * @return array<string,mixed>
     */
    private static function exportArray(ExportResult $result): array
    {
        $meta = (array) $result->meta;
        // `bytes` in the meta of a produced file is its size, not its contents, and
        // the token has its own field rather than appearing twice.
        $size = isset($meta['bytes']) ? (int) $meta['bytes'] : $result->size();
        unset($meta['bytes'], $meta['download_token']);
        $token = self::text($result->meta['download_token'] ?? '');

        return [
            'ok' => $result->ok,
            'format' => $result->format,
            'file_name' => $result->fileName,
            'location' => $result->location,
            'error' => $result->error,
            'size' => $size,
            'id' => self::text($meta['id'] ?? ''),
            'status' => self::text($meta['status'] ?? ($result->ok ? 'ready' : 'failed')),
            'download_token' => $token,
            'meta' => $meta,
        ];
    }

    /**
     * A `$_FILES` entry, a path, raw text, base64 or an UploadedFile — the three
     * ways a host actually has a file at hand.
     */
    private static function asFile(mixed $file): UploadedFile
    {
        if ($file instanceof UploadedFile) {
            return $file;
        }
        $maxMb = max(1, Config::int('limits.max_upload_mb', 25));
        if (is_array($file)) {
            if ($file === []) {
                // The host looked in $_FILES and found nothing there.
                throw new InvalidArgumentException('Nothing was uploaded.');
            }
            if (isset($file['name']) && (isset($file['tmp_name']) || isset($file['contents']))) {
                return UploadedFile::fromFilesArray($file, $maxMb);
            }
            if (isset($file['base64'])) {
                return UploadedFile::fromBase64((string) $file['base64'], self::text($file['name'] ?? 'upload'), self::text($file['mime'] ?? ''));
            }
            if (isset($file['content'])) {
                return UploadedFile::fromRaw((string) $file['content'], self::text($file['name'] ?? 'upload'), self::text($file['mime'] ?? ''));
            }
            throw new InvalidArgumentException(
                'An upload array needs tmp_name or contents (the shape of $_FILES["file"] works as-is), '
                . 'or content/base64 with a name.'
            );
        }
        if (is_string($file) && self::isPath($file) && is_file($file)) {
            return UploadedFile::fromPath($file);
        }
        if (is_string($file) && $file !== '') {
            throw new InvalidArgumentException('A plain string is read as a file path; that path does not exist. '
                . 'Send ["name" => …, "content" => $bytes] instead.');
        }

        throw new InvalidArgumentException('Nothing was uploaded.');
    }

    /** The bytes of an upload as text (a SQL dump), whatever shape they came in. */
    private static function asText(mixed $file): string
    {
        if (is_string($file) && !self::isPath($file)) {
            return $file;
        }

        return self::asFile($file)->bytes;
    }

    /**
     * A string is a path only when it could be one: a single short line that
     * exists. Anything else — a dump pasted into the body — is the content.
     */
    private static function isPath(string $value): bool
    {
        return strlen($value) < 512 && !str_contains($value, "\n") && !str_contains($value, "\0");
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function isNumeric(string $value): bool
    {
        return $value !== '' && ctype_digit($value);
    }

    /** @param array<int,string> $features */
    private static function hasFeature(array $features, string $name): bool
    {
        return in_array(strtolower($name), array_map('strtolower', $features), true);
    }

    /**
     * The names `handle()` knows, each picking exactly the keys it needs out of
     * `$input`. Anything richer than these ten calls is a method on this class.
     *
     * @param array<string,mixed> $input
     * @return array<string,callable>
     */
    private function routes(array $input, ?UserContext $user): array
    {
        $tenant = static fn (int $fallback = 0): int => (int) ($input['tenant_id'] ?? $input['company_id'] ?? $user?->tenantId ?? $fallback);
        $forUser = static fn (): UserContext => $user ?? throw new InvalidArgumentException('This action needs a user.');

        return [
            'chat' => fn (): array => $this->chat((string) ($input['question'] ?? $input['text'] ?? ''), $forUser(), (array) ($input['options'] ?? [])),
            'capabilities' => fn (): array => $this->capabilities($user),
            'diagnostics' => fn (): array => $this->diagnostics(),
            'explain' => fn (): array => $this->explain((array) ($input['plan'] ?? []), $forUser()),
            'query.run' => fn (): array => $this->runQuery((array) ($input['plan'] ?? []), $forUser(), (bool) ($input['chart'] ?? false)),
            'sql.run' => fn (): array => $this->runRawSql((string) ($input['sql'] ?? ''), $forUser()),
            'chart.suggest' => fn (): array => $this->suggestChart($input['data'] ?? [], isset($input['intent']) ? (string) $input['intent'] : null),
            'chart.build' => fn (): array => $this->buildChart((string) ($input['type'] ?? ''), $input['data'] ?? [], (array) ($input['options'] ?? [])),
            'export.create' => fn (): array => $this->createExport($input['data'] ?? [], (string) ($input['format'] ?? 'csv'), $forUser(), (array) ($input['meta'] ?? [])),
            'export.approve' => fn (): array => $this->approveExport((string) ($input['id'] ?? ''), $forUser(), $input['data'] ?? null),
            'export.download' => fn (): array => $this->download((string) ($input['token'] ?? ''), $user),
            'export.history' => fn (): array => $this->exportHistory($forUser(), (int) ($input['limit'] ?? 20)),
            'export.run-queued' => fn (): array => $this->runQueuedExports((int) ($input['limit'] ?? 5), $user),
            'action.catalog' => fn (): array => $this->actionCatalog($tenant()),
            'action.preview' => fn (): array => $this->previewAction($forUser(), (string) ($input['action'] ?? ''), (array) ($input['params'] ?? []), (array) ($input['meta'] ?? [])),
            'action.confirm' => fn (): array => $this->confirmAction((string) ($input['token'] ?? ''), $user, isset($input['idempotency_key']) ? (string) $input['idempotency_key'] : null),
            'action.cancel' => fn (): array => $this->cancelAction((string) ($input['id'] ?? ''), $user),
            'action.history' => fn (): array => $this->actionHistory($tenant(), isset($input['user_id']) ? (string) $input['user_id'] : null, (int) ($input['limit'] ?? 50)),
            'outbox.queue' => fn (): array => $this->queueNotification($tenant(), (array) ($input['message'] ?? []), isset($input['user_id']) ? (string) $input['user_id'] : null),
            'outbox.flush' => fn (): array => $this->flushOutbox((int) ($input['limit'] ?? 0)),
            'outbox.counts' => fn (): array => $this->outboxCounts($tenant()),
            'document.upload' => fn (): array => $this->uploadDocument($input['file'] ?? [], $forUser(), (array) ($input['meta'] ?? [])),
            'document.list' => fn (): array => $this->listDocuments($tenant(), (int) ($input['limit'] ?? 100)),
            'document.search' => fn (): array => $this->searchDocuments($tenant(), (string) ($input['query'] ?? ''), (int) ($input['k'] ?? 5)),
            'knowledge.list' => fn (): array => $this->listKnowledge($tenant(), (int) ($input['limit'] ?? 500)),
            'knowledge.add' => fn (): array => $this->addKnowledge($tenant(), (array) $input),
            'knowledge.import' => fn (): array => $this->importKnowledgeCsv($tenant(), self::asText($input['file'] ?? ''), (string) ($input['source'] ?? 'csv')),
            'knowledge.search' => fn (): array => $this->searchKnowledge($tenant(), (string) ($input['query'] ?? ''), (int) ($input['k'] ?? 5), $user),
            'schema.sync' => fn (): array => $this->syncSchema((array) ($input['options'] ?? [])),
            'schema.list' => fn (): array => $this->listSchema((int) ($input['limit'] ?? 1000)),
            'schema.describe' => fn (): array => $this->describeTable(
                (string) ($input['table'] ?? ''), (int) ($input['max_columns'] ?? 0)
            ),
            'schema.save' => fn (): array => $this->saveColumnDescription(
                (string) ($input['table'] ?? ''), (string) ($input['column'] ?? ''),
                isset($input['description']) ? (string) $input['description'] : null, (array) ($input['meta'] ?? [])
            ),
            'schema.import-dump' => fn (): array => $this->importSqlDump($input['file'] ?? '', (array) ($input['options'] ?? [])),
            'schema.import-csv' => fn (): array => $this->importSchemaDescriptions(self::asText($input['file'] ?? ''), (array) ($input['options'] ?? [])),
            'schema.train' => fn (): array => $this->train($tenant(), (array) ($input['options'] ?? [])),
            'relation.list' => fn (): array => $this->listRelations($tenant(), (array) ($input['filter'] ?? [])),
            'relation.discover' => fn (): array => $this->discoverRelations($tenant(), (array) ($input['options'] ?? [])),
            'relation.approve' => fn (): array => $this->approveRelation($tenant(), (int) ($input['id'] ?? 0)),
            'relation.reject' => fn (): array => $this->rejectRelation($tenant(), (int) ($input['id'] ?? 0)),
            'code.scan' => fn (): array => $this->scanCode($tenant(), (array) ($input['options'] ?? [])),
            'code.files' => fn (): array => $this->codeFiles($tenant()),
            'code.queries' => fn (): array => $this->codeQueries($tenant(), (array) ($input['options'] ?? [])),
            'query.list' => fn (): array => $this->listQueries($tenant(), (array) ($input['filter'] ?? [])),
            'query.get' => fn (): array => $this->getQuery($tenant(), (int) ($input['id'] ?? 0)),
            'query.save' => fn (): array => $this->saveQuery(
                $tenant(),
                (array) ($input['query'] ?? $input),
                isset($input['updated_by']) ? (string) $input['updated_by'] : $user?->userId
            ),
            'query.delete' => fn (): array => $this->deleteQuery($tenant(), (int) ($input['id'] ?? 0)),
            'query.harvest' => fn (): array => $this->harvestQueries(
                $tenant(),
                isset($input['updated_by']) ? (string) $input['updated_by'] : $user?->userId
            ),
            'rule.list' => fn (): array => $this->listRules($tenant(), (array) ($input['filter'] ?? [])),
            'rule.get' => fn (): array => $this->getRule($tenant(), (int) ($input['id'] ?? 0)),
            'rule.save' => fn (): array => $this->saveRule(
                $tenant(),
                (array) ($input['rule'] ?? $input),
                isset($input['updated_by']) ? (string) $input['updated_by'] : $user?->userId
            ),
            'rule.verify' => fn (): array => $this->verifyRule($tenant(), (int) ($input['id'] ?? 0), (array) $input, $user),
            'rule.delete' => fn (): array => $this->deleteRule($tenant(), (int) ($input['id'] ?? 0)),
            'rule.sheet-template' => fn (): array => $this->ruleSheetTemplate(),
            'rule.sheet-import' => fn (): array => $this->importRuleSheet(
                $tenant(),
                self::asText($input['file'] ?? ''),
                isset($input['updated_by']) ? (string) $input['updated_by'] : $user?->userId
            ),
            'discover.run' => fn (): array => $this->discover($tenant(), (array) ($input['options'] ?? [])),
            'discover.draft' => fn (): array => $this->discoverDraft($tenant()),
            'discover.knowledge-import' => fn (): array => $this->importDiscoveryKnowledge(
                $tenant(),
                (string) ($input['path'] ?? $input['file'] ?? ''),
                isset($input['updated_by']) ? (string) $input['updated_by'] : $user?->userId
            ),
            'report.list' => fn (): array => $this->listReports($tenant(), isset($input['folder']) ? (string) $input['folder'] : null, $user),
            'report.run' => fn (): array => $this->runReport((string) ($input['id'] ?? ''), (array) ($input['params'] ?? []), $user),
            'schedule.create' => fn (): array => $this->createSchedule((array) $input),
            'schedule.tick' => fn (): array => $this->tickSchedules(isset($input['limit']) ? (int) $input['limit'] : null),
            'feedback.submit' => fn (): array => $this->submitFeedback(
                $input['target'] ?? '', (string) ($input['rating'] ?? ''),
                isset($input['reason']) ? (string) $input['reason'] : null,
                isset($input['comment']) ? (string) $input['comment'] : null, $user
            ),
            'usage.today' => fn (): array => $this->usageToday($tenant()),
            'limit.check' => fn (): array => $this->checkLimit(
                (string) ($input['dimension'] ?? 'chats'), (string) ($input['key'] ?? ''), (string) ($input['window'] ?? 'hour'),
                isset($input['max']) ? (int) $input['max'] : null
            ),
            'audit.query' => fn (): array => $this->auditTrail((array) ($input['filter'] ?? []), (int) ($input['limit'] ?? 100), (int) ($input['offset'] ?? 0)),
            'settings.get' => fn (): array => $this->getSetting((string) ($input['key'] ?? ''), $input['default'] ?? null, isset($input['scope']) ? (array) $input['scope'] : null),
            'settings.put' => fn (): array => $this->putSetting(
                (string) ($input['key'] ?? ''), $input['value'] ?? null,
                isset($input['scope']) ? (array) $input['scope'] : null, isset($input['updated_by']) ? (string) $input['updated_by'] : null
            ),
            'config.describe' => fn (): array => $this->describeConfig($tenant()),
            'config.get' => fn (): array => $this->getConfig($tenant(), isset($input['path']) ? (string) $input['path'] : null),
            'config.save' => fn (): array => $this->saveConfig(
                (array) ($input['settings'] ?? []), $tenant(),
                isset($input['updated_by']) ? (string) $input['updated_by'] : $user?->userId
            ),
            'config.forget' => fn (): array => $this->forgetConfig(
                (array) ($input['paths'] ?? $input['settings'] ?? []), $tenant()
            ),
            'conversation.list' => fn (): array => $this->listConversations($tenant(), (string) ($input['user_id'] ?? $user?->userId ?? '')),
            'whatsapp.inbound' => fn (): array => $this->inboundWhatsApp((array) ($input['payload'] ?? [])),
            'whatsapp.answer' => fn (): array => $this->answerWhatsApp((array) ($input['payload'] ?? []), (array) ($input['defaults'] ?? [])),
        ];
    }
}
