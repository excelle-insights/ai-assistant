<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Contracts\ActionHandlerInterface;
use ExcelleInsights\AiAssistant\Contracts\ConversationStoreInterface;
use ExcelleInsights\AiAssistant\Contracts\KnowledgeRetrieverInterface;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Contracts\PermissionResolverInterface;
use ExcelleInsights\AiAssistant\Dto\Answer;
use ExcelleInsights\AiAssistant\Dto\AskRequest;
use ExcelleInsights\AiAssistant\Dto\Citation;
use ExcelleInsights\AiAssistant\Dto\ExportResult;
use ExcelleInsights\AiAssistant\Dto\QueryPlan;
use ExcelleInsights\AiAssistant\Dto\QueryResult;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\DateRange;
use ExcelleInsights\AiAssistant\Support\DomainPack;
use ExcelleInsights\AiAssistant\Support\LlmFactory;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;

/**
 * The channel-neutral brain of the assistant.
 *
 * One entry point, ask(), decides what kind of question was asked, gathers only
 * the context that kind needs, and returns an Answer any channel can render. It
 * knows nothing about WhatsApp, webhooks, screens or anyone's domain: an LLM
 * client is the only required collaborator, everything else arrives as a
 * contract or a callable hook.
 *
 * The data path never lets the model write SQL by default. The model names a
 * dataset, metrics, dimensions and filters (QueryPlan); QueryCompiler turns that
 * into parameterised SQL and adds the tenant and row scope the host supplies, so
 * a permission the model never saw still applies. Raw SQL stays an opt-in path
 * for the roles listed in semantic.raw_sql_roles.
 *
 * Hooks (constructor argument $hooks):
 *   rows            callable(array $compiled, UserContext $user): array|QueryResult
 *                   executes the compiled SQL and returns rows. A host that
 *                   answers from its own repositories sets this and never lets
 *                   the package touch a database.
 *   audit           callable(string $action, array $detail): void
 *   usage           callable(array $usage): void
 *   system_context  callable(UserContext $user): array — company facts for the prompt
 *   documents       callable(UserContext $user, string $question, int $k): array — chunks from uploaded files
 *   export          callable(mixed $result, string $format, UserContext $user, array $meta):
 *                   ExportResult|array — writes the file when the question asks
 *                   for one; normally wired to ExportService::create.
 */
final class AssistantService
{
    /** Intents the router may choose. */
    public const INTENTS = ['data', 'knowledge', 'document', 'action', 'report', 'help', 'smalltalk'];

    private LlmClientInterface $llm;
    private ?ConversationStoreInterface $conversations;
    private ?DomainPack $pack;
    private ?QueryCompiler $compiler;
    private ?KnowledgeRetrieverInterface $knowledge;
    private ?PermissionResolverInterface $permissions;
    private ?ChartService $charts;
    private ?ActionHandlerInterface $actions;
    private ?ReportService $reports;
    private SchemaPolicy $policy;

    /** @var array<string,callable> */
    private array $hooks;
    private string $model;
    private ?float $temperature;
    private int $historyTurns;
    private int $knowledgeTopK;

    /**
     * @param array<string,callable> $hooks
     * @param array<string,mixed> $options model, temperature, history_turns, knowledge_top_k
     */
    public function __construct(
        ?LlmClientInterface $llm = null,
        ?ConversationStoreInterface $conversations = null,
        ?DomainPack $pack = null,
        ?QueryCompiler $compiler = null,
        ?KnowledgeRetrieverInterface $knowledge = null,
        ?PermissionResolverInterface $permissions = null,
        ?ChartService $charts = null,
        ?ActionHandlerInterface $actions = null,
        ?ReportService $reports = null,
        ?SchemaPolicy $policy = null,
        array $hooks = [],
        array $options = [],
    ) {
        $this->llm = $llm ?? LlmFactory::make();
        $this->conversations = $conversations;
        $this->pack = $pack;
        $this->compiler = $compiler;
        $this->knowledge = $knowledge;
        $this->permissions = $permissions;
        $this->charts = $charts ?? new ChartService();
        $this->actions = $actions;
        $this->reports = $reports;
        $this->policy = $policy ?? new SchemaPolicy();
        $this->hooks = array_filter($hooks, 'is_callable');
        $this->model = (string) ($options['model'] ?? Config::string('llm.model', ''));
        $this->temperature = isset($options['temperature']) ? (float) $options['temperature'] : null;
        $this->historyTurns = max(0, (int) ($options['history_turns'] ?? Config::int('limits.memory_turns', 10)));
        $this->knowledgeTopK = max(1, (int) ($options['knowledge_top_k'] ?? Config::int('knowledge.top_k', 5)));
    }

    /**
     * Answer one turn.
     *
     * @param AskRequest|string $question
     * @param UserContext|array<string,mixed>|null $user
     * @param array<string,mixed> $options
     */
    public function ask(AskRequest|string $question, UserContext|array|null $user = null, array $options = []): Answer
    {
        $startedAt = microtime(true);
        $request = $this->normaliseRequest($question, $user, $options);
        $text = trim($request->question);
        if ($text === '') {
            return Answer::text('Ask me a question about your records, documents or reports.', 'help');
        }

        $conversationId = $this->openThread($request->user);
        $history = $request->history !== []
            ? $request->history
            : ($this->conversations !== null && $conversationId !== ''
                ? $this->conversations->history($conversationId, $this->historyTurns)
                : []);

        $decision = $this->route($request, $history);
        $intent = (string) $decision['intent'];
        $routedBy = (string) ($decision['by'] ?? 'router');

        $answer = match ($intent) {
            'data' => $this->answerWithData($request, (array) $decision['plan']),
            'knowledge' => $this->answerFromKnowledge($request, $this->knowledgeChunks($request)),
            'document' => $this->answerFromDocuments($request),
            'action' => $this->answerWithAction($request),
            'report' => $this->answerWithReport($request, (array) $decision['plan']),
            'help' => $this->answerHelp($request),
            default => $this->answerSmalltalk($request, $history),
        };

        $this->remember($request, $conversationId, $text, $answer, $startedAt);

        return $this->withMeta($answer, $request, $conversationId, $intent, $routedBy);
    }

    /**
     * Compiled SQL and the scope applied to it, without executing anything.
     * Hosts show this to an admin who asks "what would that query do".
     *
     * @param QueryPlan|array<string,mixed> $plan
     * @param UserContext|array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function explain(QueryPlan|array $plan, UserContext|array $user): array
    {
        $context = is_array($user) ? UserContext::fromArray($user) : $user;
        $prepared = is_array($plan) ? QueryPlan::fromArray($plan) : $plan;
        $compiled = $this->compilerOrFail()->compile($prepared, $this->scopeFor($prepared, $context));
        $compiled['capabilities'] = $this->capabilities($context);

        return $compiled;
    }

    /**
     * Run a plan the host already built — a dashboard widget that needs numbers
     * but no model.
     *
     * @param QueryPlan|array<string,mixed> $plan
     * @param UserContext|array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function run(QueryPlan|array $plan, UserContext|array $user, bool $withChart = false): array
    {
        $context = is_array($user) ? UserContext::fromArray($user) : $user;
        $prepared = is_array($plan) ? QueryPlan::fromArray($plan) : $plan;
        $compiled = $this->compilerOrFail()->compile($prepared, $this->scopeFor($prepared, $context));
        $result = $this->execute($compiled, $context, $withChart)
            ?? QueryResult::fromRows([], (string) ($compiled['sql'] ?? ''), 'none');

        return [
            'result' => $result,
            'sql' => $this->mayUseRawSql($context) ? (string) ($compiled['sql'] ?? '') : null,
            'bind' => $this->mayUseRawSql($context) ? (array) ($compiled['bind'] ?? []) : [],
            'applied_filters' => (array) ($compiled['applied_filters'] ?? []),
            'chart' => $withChart && !$result->isEmpty() ? $this->chartFor($result, $prepared->chart) : [],
        ];
    }

    /**
     * What this assistant can answer right now. Hosts use it for an empty-state
     * panel; the help intent uses it for the reply.
     *
     * @return array<string,mixed>
     */
    public function capabilities(?UserContext $user = null): array
    {
        $user ??= new UserContext();
        $datasets = $this->pack !== null ? $this->pack->datasetNames() : [];
        $metrics = [];
        foreach ($datasets as $name) {
            $metrics[(string) $name] = $this->pack !== null ? $this->pack->metricNames((string) $name) : [];
        }
        $actions = [];
        if ($this->actions !== null) {
            foreach ($this->actions->actions() as $action) {
                $actions[] = (string) (is_array($action) ? ($action['name'] ?? '') : (string) $action);
            }
        }

        return [
            'datasets' => $datasets,
            'metrics' => $metrics,
            'actions' => $actions,
            // `features` is a list, so each gate is an in_array — bool('features.reports')
            // would read a path that never resolves and ignore the host's switch.
            'reports' => $this->reports !== null
                && in_array('reports', array_map('strtolower', Config::list('features')), true),
            'documents' => isset($this->hooks['documents']),
            'exports' => isset($this->hooks['export']) && in_array('exports', array_map('strtolower', Config::list('features')), true),
            'knowledge' => $this->knowledge !== null,
            'raw_sql' => $this->mayUseRawSql($user),
        ];
    }

    // ─── routing ──────────────────────────────────────────────────────────────

    /**
     * One model call chooses the brain and, for a data question, describes the
     * query. A caller-supplied hint (options['intent']) skips the call.
     *
     * @param array<int,array<string,mixed>> $history
     * @return array{intent:string,plan:array<string,mixed>}
     */
    private function route(AskRequest $request, array $history): array
    {
        $hint = $request->intentHint();
        if ($hint !== '' && $hint !== 'auto' && in_array($hint, self::INTENTS, true)) {
            return ['intent' => $hint, 'plan' => (array) $request->option('plan', []), 'by' => 'hint'];
        }

        if (!$this->hasDataPath() && $this->knowledge === null && !isset($this->hooks['documents'])) {
            return ['intent' => 'smalltalk', 'plan' => [], 'by' => 'nothing_connected'];
        }

        $messages = $this->convo($this->routerPrompt(), $request->question, $history);
        $json = self::jsonObject((string) ($this->chat($messages, 0.0)['content'] ?? ''));
        $intent = strtolower(trim((string) ($json['intent'] ?? '')));
        if (in_array($intent, self::INTENTS, true)) {
            return ['intent' => $intent, 'plan' => (array) ($json['plan'] ?? []), 'by' => 'router'];
        }

        // The model gave nothing usable — the question is read instead. `by` keeps
        // that visible in the answer's meta, so a support ticket can tell a model
        // answer from a fallback one.
        return ['intent' => $this->guessIntent($request->question), 'plan' => (array) ($json['plan'] ?? []), 'by' => 'guessed'];
    }

    /** Last resort when the model gives nothing usable: read the question. */
    private function guessIntent(string $question): string
    {
        $t = strtolower($question);
        if (preg_match('/^(help|what can you|how do you use|commands?\b|capabilities?\b)/', $t) === 1) {
            return 'help';
        }
        if ($this->actions !== null && preg_match('/\b(add|create|new |update|change|move|assign|close|mark|cancel)\b/', $t) === 1) {
            return 'action';
        }
        if ($this->reports !== null && preg_match('/\b(report|digest|weekly|monthly|scheduled)\b/', $t) === 1) {
            return 'report';
        }
        if (isset($this->hooks['documents']) && preg_match('/\b(document|file|pdf|docx|upload|invoice|contract)\b/', $t) === 1) {
            return 'document';
        }
        if ($this->hasDataPath() && preg_match('/\b(how many|how much|total|average|count|list|show|top|best|worst|compare|trend|revenue|sum|chart|export|per |by )\b/', $t) === 1) {
            return 'data';
        }
        if ($this->knowledge !== null && preg_match('/\b(policy|price|prices|rate|rates|discount|hours|opening|warranty|refund|procedure|process)\b/', $t) === 1) {
            return 'knowledge';
        }

        return $this->hasDataPath() ? 'data' : 'smalltalk';
    }

    private function routerPrompt(): string
    {
        // A host can replace the routing instructions (own JSON dialect, extra
        // rules, another language). The vocabulary and custom instructions below
        // still travel with it — without them no model can name a dataset.
        $override = trim(Config::string('domain.query_prompt', ''));
        $lines = [$override !== '' ? $override : 'You route one user question to the right brain and, for a data question, describe the query.'];
        if ($override === '') {
            $lines = array_merge($lines, [
                'Reply with JSON only, no prose, shaped like:',
                '{"intent":"data|knowledge|document|action|report|help|smalltalk","plan":{}}',
                'For intent "data" the plan object is:',
                '{"dataset":"name","metrics":["name"],"dimensions":["name"],'
                . '"filters":[{"field":"name","operator":"=|!=|>|>=|<|<=|like|contains|in|not_in|between|is_null|not_null","value":null}],'
                . '"date_range":{"preset":"last_30_days"} or {"from":"YYYY-MM-DD","to":"YYYY-MM-DD"},'
                . '"order_by":[{"field":"name","direction":"desc"}],"limit":20,"chart":"bar|line|area|pie|scatter|kpi|""}',
                'Rules:',
                '- Use only names that appear in the vocabulary. Never invent a column, table or SQL.',
                '- Anything countable is intent "data": a number must come from a query, never from memory.',
                '- Facts the user typed in (prices, policies, instructions) are intent "knowledge".',
                '- Content of uploaded files is "document". Writes (add, change, remove) are "action". Recurring digests are "report".',
                '- "help" when the user asks what you can do; "smalltalk" for greetings and general conversation.',
                '- If no dataset fits, choose "knowledge" or "help" rather than guessing a dataset.',
                '- Relative periods ("last year", "this month") resolve against the current date below: put the preset in date_range.preset instead of computing from/to yourself.',
            ]);
        }

        // The model has no clock. Without this line it anchors "last year" to
        // its training data and returns a period before the records exist.
        $lines[] = 'Current date: ' . $this->today();

        $vocabulary = $this->hasDataPath() && $this->pack !== null
            ? $this->pack->vocabulary(Config::int('semantic.vocab_chars', 5000))
            : '';
        if ($vocabulary !== '') {
            $lines[] = 'Vocabulary:';
            $lines[] = $vocabulary;
        }
        $custom = trim(Config::string('domain.custom_instructions', ''));
        if ($custom !== '') {
            $lines[] = $custom;
        }

        return implode("\n", $lines);
    }

    /** Today in the host's timezone, phrased so the model cannot misread it. */
    private function today(): string
    {
        $tz = Config::string('timezone', 'UTC') ?: 'UTC';
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone($tz));
        } catch (\Throwable) {
            $now = new \DateTimeImmutable('now');
            $tz = 'UTC';
        }

        return $now->format('l, j F Y') . ' (' . $now->format('Y-m-d') . ') in ' . $tz;
    }

    private function hasDataPath(): bool
    {
        return $this->pack !== null && $this->pack->hasDatasets() && ($this->compiler !== null || isset($this->hooks['rows']));
    }

    private function mayUseRawSql(UserContext $user): bool
    {
        if (!Config::bool('semantic.allow_raw_sql', false)) {
            return false;
        }
        $allowed = array_map('strtolower', Config::list('semantic.raw_sql_roles'));
        foreach ($user->roles as $role) {
            if (in_array(strtolower((string) $role), $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param UserContext|array<string,mixed>|null $user
     * @param array<string,mixed> $options
     */
    private function normaliseRequest(AskRequest|string $question, UserContext|array|null $user, array $options): AskRequest
    {
        if ($question instanceof AskRequest) {
            return $user === null ? $question : new AskRequest(
                $question->question,
                is_array($user) ? UserContext::fromArray($user) : $user,
                $question->history,
                $question->context + $options,
                $question->options + $options
            );
        }

        $context = $user === null ? new UserContext() : (is_array($user) ? UserContext::fromArray($user) : $user);

        return new AskRequest(trim($question), $context, (array) ($options['history'] ?? []), (array) ($options['context'] ?? []), $options);
    }

    /**
     * A thread for this turn. An empty thread id means "do not remember", so a
     * one-shot key keeps the turn out of any user's history.
     */
    private function openThread(UserContext $user): string
    {
        if ($this->conversations === null) {
            return '';
        }
        $thread = $user->threadId !== '' ? $user->threadId : 'one-shot-' . substr(hash('sha256', $user->userId . '|' . microtime(true)), 0, 16);
        try {
            return $this->conversations->touch($user->tenantId, $user->channel, $thread, ['user_id' => $user->userId, 'roles' => $user->roles]);
        } catch (\Throwable) {
            return '';
        }
    }

    // ─── data ─────────────────────────────────────────────────────────────────

    /**
     * Plan → compile → execute → answer. A plan the compiler rejects is retried
     * once with the compiler's own message, which lists the valid names; that is
     * what makes a wrong guess converge instead of wander.
     *
     * @param array<string,mixed> $plan
     */
    private function answerWithData(AskRequest $request, array $plan): Answer
    {
        if (!$this->hasDataPath()) {
            return new Answer(
                text: 'No datasets are connected yet, so I cannot answer questions about your records. '
                    . 'Describe your tables in the schema catalogue (or add a domain pack) and ask again.',
                intent: 'data',
                grounded: false
            );
        }

        $user = $request->user;
        if ($user->tenantId <= 0 && Config::bool('schema_policy.require_tenant_scope', true)) {
            return new Answer(
                text: 'I could not tell which account you belong to, so I did not query anything. Ask again from a signed-in session.',
                intent: 'data',
                grounded: false
            );
        }

        $wantsChart = $request->wantsChart();

        if ($this->compiler === null && isset($this->hooks['rows'])) {
            // A host that answers from its own repositories does not need the
            // compiler at all: it receives the plan and returns the rows.
            $compiled = [
                'sql' => '',
                'bind' => [],
                'columns' => [],
                'limit' => (int) ($plan['limit'] ?? 0),
                'dataset' => (string) ($plan['dataset'] ?? $plan['entity'] ?? ''),
                'applied_filters' => [],
                'plan' => $plan,
            ];
            $result = $this->rowsFromHook($compiled, $user);
            $chartType = (string) ($plan['chart'] ?? '');
        } else {
            try {
                $prepared = $this->preparePlan($plan, $request);
            } catch (\Throwable $e) {
                return $this->noPlanAnswer($request, $e->getMessage());
            }
            if ($prepared === null) {
                return $this->noPlanAnswer($request, 'the question did not name a dataset');
            }
            [$queryPlan, $compiled] = $prepared;
            $wantsChart = $wantsChart || $queryPlan->chart !== '';
            $chartType = $queryPlan->chart;
            $result = $this->execute($compiled, $user, $wantsChart);
            if ($result === null) {
                return new Answer(
                    text: 'The query is ready but nothing is connected to run it, so I did not answer with a number. '
                        . 'Register a rows executor (SqlQueryRunner) or a repository hook.',
                    intent: 'data',
                    sql: $this->mayUseRawSql($user) ? (string) ($compiled['sql'] ?? '') : null,
                    appliedFilters: (array) ($compiled['applied_filters'] ?? []),
                    grounded: false
                );
            }
        }

        if ($result->isEmpty() || self::isZeroAggregate($result, $compiled)) {
            return $this->emptyDataAnswer($request, (array) ($compiled['applied_filters'] ?? []), $result, $compiled);
        }

        $export = $this->exportFor($request, $result, $compiled);

        return new Answer(
            text: $this->describeRows($request, $result, $compiled) . (string) ($export['text'] ?? ''),
            intent: 'data',
            rows: $result->rows,
            chart: $wantsChart ? $this->chartFor($result, $chartType) : [],
            citations: $this->dataCitations($compiled, $result),
            suggestions: (array) ($export['suggestions'] ?? []),
            sql: $this->mayUseRawSql($user) ? (string) ($compiled['sql'] ?? '') : null,
            appliedFilters: (array) ($compiled['applied_filters'] ?? []),
            meta: ['columns' => $result->columns, 'truncated' => $result->truncated, 'source' => $result->source, 'export' => $export]
        );
    }

    /**
     * "Export these to Excel" is still a data answer: the query runs first and the
     * file is written from the rows the permission gates let through. Where files
     * live and who may download them is the host's business, so this goes through
     * the export hook — normally wired to ExportService::create().
     *
     * @param array<string,mixed> $compiled
     * @return array<string,mixed>
     */
    private function exportFor(AskRequest $request, QueryResult $result, array $compiled): array
    {
        $format = $request->exportFormat();
        if ($format === '' || !isset($this->hooks['export'])) {
            return [];
        }
        $meta = [
            'title' => (string) ($compiled['dataset'] ?? 'export'),
            'question' => $request->question,
            'entity' => (string) ($compiled['dataset'] ?? ''),
            'applied_filters' => (array) ($compiled['applied_filters'] ?? []),
            'plan' => (array) ($compiled['plan'] ?? []),
            'conversation_id' => $request->user->threadId,
        ];

        try {
            $produced = ($this->hooks['export'])($result, $format, $request->user, $meta);
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'format' => $format, 'error' => $e->getMessage()];
        }

        if ($produced instanceof ExportResult) {
            $produced = $produced->ok
                ? ['status' => 'ready', 'file' => $produced->fileName, 'location' => $produced->location, 'meta' => $produced->meta]
                : ['status' => 'failed', 'error' => (string) $produced->error];
        }
        if (!is_array($produced)) {
            return [];
        }

        $ready = ($produced['status'] ?? '') === 'ready';
        $name = (string) ($produced['file'] ?? '');
        $produced['format'] = $format;
        $produced['text'] = $ready
            ? ($name !== '' ? "\n\nSaved as " . $name . ' — the download link expires with the file.' : "\n\nThe file is ready.")
            : "\n\nI could not make the " . $format . ' file: ' . (string) ($produced['error'] ?? 'the exporter refused') . '.';
        $produced['suggestions'] = $ready ? ['Email me this file every Monday'] : ['Try csv instead'];

        return $produced;
    }

    /**
     * Build and compile a plan, retrying once when the compiler rejects it.
     *
     * @param array<string,mixed> $plan
     * @return array{0:QueryPlan,1:array<string,mixed>}|null
     */
    private function preparePlan(array $plan, AskRequest $request): ?array
    {
        $compiler = $this->compiler ?? $this->compilerOrFail();

        if ($plan === [] || !isset($plan['dataset']) || !is_string($plan['dataset'])) {
            $plan = $this->askForPlan($request, '');
        }
        if ($plan === []) {
            return null;
        }
        $plan = $this->withRelativePeriod($plan, $request->question);

        try {
            $queryPlan = $this->applyRowCap(QueryPlan::fromArray($plan), $request);
            return [$queryPlan, $compiler->compile($queryPlan, $this->scopeFor($queryPlan, $request->user))];
        } catch (\Throwable $e) {
            $retry = $this->askForPlan($request, $e->getMessage());
            if ($retry === []) {
                throw $e;
            }
            $retry = $this->withRelativePeriod($retry, $request->question);
            $queryPlan = $this->applyRowCap(QueryPlan::fromArray($retry), $request);
            return [$queryPlan, $compiler->compile($queryPlan, $this->scopeFor($queryPlan, $request->user))];
        }
    }

    /**
     * "Last year" must mean the last calendar year, not a period the model
     * computed from its training cut-off. A relative phrase in the question
     * therefore replaces any range the model supplied.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function withRelativePeriod(array $plan, string $question): array
    {
        $preset = DateRange::presetFromText($question);
        if ($preset === '' || !isset($plan['dataset'])) {
            return $plan;
        }
        $plan['date_range'] = ['preset' => $preset];

        return $plan;
    }

    /**
     * `max_rows` in the ask options is the host's own ceiling for this question.
     * It only ever lowers a plan that did not name a limit; the compiler clamps
     * whatever remains to the schema policy, so a host cannot ask past the cap.
     */
    private function applyRowCap(QueryPlan $plan, AskRequest $request): QueryPlan
    {
        $wanted = $request->maxRows();
        if ($wanted > 0 && $plan->limit === 0) {
            $plan->limit = $wanted;
        }

        return $plan;
    }

    /**
     * Ask the model for a plan, with the compiler's rejection text appended when
     * this is the retry.
     *
     * @return array<string,mixed>
     */
    private function askForPlan(AskRequest $request, string $error): array
    {
        $messages = $this->convo($this->routerPrompt(), $request->question, []);
        if ($error !== '') {
            $messages[] = ['role' => 'assistant', 'content' => json_encode(['intent' => 'data', 'plan' => $request->option('plan', [])], JSON_UNESCAPED_UNICODE) ?: '{}'];
            $messages[] = ['role' => 'user', 'content' => 'That plan was rejected: ' . $error . ' Fix it using only vocabulary names and reply with JSON only.'];
        }
        $json = self::jsonObject((string) ($this->chat($messages, 0.0)['content'] ?? ''));
        $plan = (array) ($json['plan'] ?? $json);
        unset($plan['intent']);
        if (isset($plan['dataset']) || isset($plan['entity'])) {
            return $plan;
        }
        // A provider that is down or chatty should not cost the user their number,
        // but a plan the compiler already rejected must fail loudly instead of
        // quietly answering a different question.
        return $error === '' ? $this->fallbackPlan($request->question) : [];
    }

    /**
     * The no-model reading of a countable question: the dataset the question
     * names, one measure from it, and a "by <dimension>" grouping when present.
     *
     * @return array<string,mixed>
     */
    private function fallbackPlan(string $question): array
    {
        if ($this->pack === null) {
            return [];
        }
        $words = ' ' . trim((string) preg_replace('/[^a-z0-9_ ]+/u', ' ', strtolower($question)), ' ') . ' ';
        $dataset = '';
        $strength = 0;
        foreach ($this->pack->listDatasets() as $def) {
            $name = (string) ($def['name'] ?? '');
            $best = 0;
            foreach ([$name, (string) ($def['label'] ?? ''), (string) ($def['table'] ?? ''), rtrim($name, 's')] as $alias) {
                $alias = trim((string) $alias);
                if ($alias === '') {
                    continue;
                }
                if (str_contains($words, ' ' . $alias . ' ')) {
                    $best = max($best, 3);
                } elseif (strlen($alias) >= 4 && str_contains($words, $alias)) {
                    $best = max($best, 2);
                }
            }
            if ($best > $strength) {
                $strength = $best;
                $dataset = $name;
            }
        }
        if ($dataset === '') {
            return [];
        }

        $metrics = $this->pack->metricNames($dataset);
        $measure = [];
        foreach (['records', 'count', 'total'] as $hint) {
            if (in_array($hint, $metrics, true)) {
                $measure = [$hint];
                break;
            }
        }
        if ($measure === [] && $metrics !== []) {
            $measure = [(string) $metrics[0]];
        }
        $plan = ['dataset' => $dataset, 'metrics' => $measure];

        if (preg_match('/\bby ([a-z0-9_]+)/', $words, $m) === 1 && in_array($m[1], $this->pack->dimensionNames($dataset), true)) {
            $plan['dimensions'] = [$m[1]];
        }
        $preset = DateRange::presetFromText($question);
        if ($preset !== '') {
            $plan['date_range'] = ['preset' => $preset];
        }
        if ($measure !== [] && preg_match('/\b(top|best|highest|most)\b/', $words) === 1) {
            $plan['order_by'] = [['field' => $measure[0], 'direction' => 'desc']];
            $plan['limit'] = 10;
        } elseif ($measure !== [] && preg_match('/\b(lowest|worst|least|bottom)\b/', $words) === 1) {
            $plan['order_by'] = [['field' => $measure[0], 'direction' => 'asc']];
            $plan['limit'] = 10;
        }

        return $plan;
    }

    /**
     * Scope the host owns: tenant, row filter and hidden fields per table. The
     * model never sees or influences any of it.
     *
     * @return array<string,mixed>
     */
    private function scopeFor(QueryPlan $plan, UserContext $user): array
    {
        $scope = [
            'tenant_id' => $user->tenantId,
            'tenant_column' => Config::string('tenant.column', 'company_id'),
        ];
        $tables = $this->pack !== null ? array_values(array_filter([
            $plan->dataset !== '' ? $this->pack->tableOf($plan->dataset) : '',
            $plan->entity !== '' ? (string) ($this->pack->entity($plan->entity)['table'] ?? '') : '',
        ])) : [];

        if ($this->permissions === null) {
            return $scope;
        }
        foreach ($tables as $table) {
            $entity = (string) $table;
            $rowScope = $this->permissions->rowScope($user, $entity);
            if (is_string($rowScope) && trim($rowScope) !== '') {
                $scope['row_scope'][$entity] = trim($rowScope);
            }
            $hidden = $this->permissions->hiddenColumns($user, $entity);
            if ($hidden !== []) {
                $scope['hidden_columns'][$entity] = array_values(array_map('strval', $hidden));
            }
        }

        return $scope;
    }

    /**
     * Run the compiled statement. The package never owns a connection: a host
     * that lets the assistant read its database passes SqlQueryRunner as the
     * 'rows' hook, and a host that answers from its own repositories passes
     * something else. With neither, nothing runs and the answer says so.
     *
     * @param array<string,mixed> $compiled
     */
    private function execute(array $compiled, UserContext $user, bool $withChart): ?QueryResult
    {
        if (!isset($this->hooks['rows'])) {
            return null;
        }

        return $this->rowsFromHook($compiled, $user);
    }

    /** @param array<string,mixed> $compiled */
    private function rowsFromHook(array $compiled, UserContext $user): QueryResult
    {
        try {
            $returned = ($this->hooks['rows'])($compiled, $user);
        } catch (\Throwable $e) {
            $this->audit('assistant.query_failed', ['error' => $e->getMessage(), 'dataset' => $compiled['dataset'] ?? '']);

            return QueryResult::fromRows([], (string) ($compiled['sql'] ?? ''), 'semantic');
        }

        if ($returned instanceof QueryResult) {
            return $returned;
        }
        $rows = is_array($returned) ? array_values(array_filter($returned, 'is_array')) : [];
        $max = (int) ($compiled['limit'] ?? 0) > 0 ? (int) $compiled['limit'] : $this->policy->maxRows();

        return QueryResult::fromRows(array_slice($rows, 0, $max), (string) ($compiled['sql'] ?? ''), 'host_provider');
    }

    /**
     * Raw SQL is the admin path: it must start with SELECT, carry no statement
     * separator, and touch only tables the policy allows.
     *
     * @param array<string,mixed> $compiled
     * @return array<string,mixed>
     */
    public function guardRawSql(string $sql): array
    {
        $clean = trim($sql);
        if ($clean === '' || preg_match('/^(\(|\bSELECT\b|\bWITH\b)/i', $clean) !== 1) {
            throw new \InvalidArgumentException('Raw SQL may only read data: one SELECT or WITH statement.');
        }
        if (str_contains($clean, ';')) {
            throw new \InvalidArgumentException('Raw SQL may not contain more than one statement.');
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|GRANT|REVOKE|TRUNCATE|REPLACE|EXEC|EXECUTE|OUTFILE|DUMPFILE)\b/i', $clean) === 1) {
            throw new \InvalidArgumentException('Raw SQL may only read data — no writes or DDL.');
        }
        if (preg_match('/\b(SLEEP|BENCHMARK|LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $clean) === 1) {
            throw new \InvalidArgumentException('Raw SQL may not write or block the server (sleep, benchmark, file access).');
        }
        preg_match_all('/\b(?:FROM|JOIN)\s+`?([a-zA-Z0-9_]+)`?/i', $clean, $matches);
        foreach (array_unique($matches[1] ?? []) as $table) {
            if (!$this->policy->isTableAllowed((string) $table)) {
                throw new \InvalidArgumentException('The table "' . $table . '" is not readable by the assistant.');
            }
        }

        return ['sql' => $clean];
    }

    private function compilerOrFail(): QueryCompiler
    {
        if ($this->compiler === null) {
            throw new \LogicException('No QueryCompiler was given, so data questions cannot be answered. Build one from the domain pack: new QueryCompiler($pack, $policy, $tableColumns).');
        }

        return $this->compiler;
    }

    // ─── the other brains ─────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>>|array<int,string> */
    private function knowledgeChunks(AskRequest $request): array
    {
        if ($this->knowledge === null) {
            return [];
        }
        try {
            return $this->knowledge->search($request->user->tenantId, $request->question, $this->knowledgeTopK);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Grounded answer over knowledge entries. No entries is an honest answer, not
     * a invented one.
     *
     * @param array<int,mixed> $chunks
     */
    private function answerFromKnowledge(AskRequest $request, array $chunks): Answer
    {
        $blocks = self::contextBlocks($chunks, 2400);
        if ($blocks === '') {
            return new Answer(
                text: 'I have nothing in the knowledge base about that yet. Add it (or describe the table you mean) and I can answer with a source.',
                intent: 'knowledge',
                grounded: false,
                suggestions: ['What can you do?', 'Train the assistant']
            );
        }

        $messages = $this->convo(
            $this->groundedPrompt('the trained knowledge base', $blocks, $request),
            $request->question,
            $this->historyFor($request)
        );
        $reply = (string) ($this->chat($messages)['content'] ?? '');

        return new Answer(
            text: $reply !== '' ? $reply : self::plainChunks($chunks),
            intent: 'knowledge',
            citations: self::knowledgeCitations($chunks),
            grounded: $reply !== '',
            meta: ['retrieved' => count($chunks)]
        );
    }

    private function answerFromDocuments(AskRequest $request): Answer
    {
        if (!isset($this->hooks['documents'])) {
            return $this->answerFromKnowledge($request, $this->knowledgeChunks($request));
        }
        try {
            $chunks = (array) $this->hooks['documents']($request->user, $request->question, $this->knowledgeTopK);
        } catch (\Throwable) {
            $chunks = [];
        }

        return $this->answerFromKnowledge($request, $chunks);
    }

    /**
     * Writes are always two-step: the host previews, the user confirms, the host
     * executes. This only ever produces the proposal.
     */
    private function answerWithAction(AskRequest $request): Answer
    {
        if ($this->actions === null) {
            return new Answer(
                text: 'Changes are not connected to me. Ask the application to register an action handler and I can prepare them for your approval.',
                intent: 'action',
                grounded: false
            );
        }
        $allowed = $this->actions->actions();
        $choice = $this->chooseAction($request->question, $allowed);
        if ($choice === '') {
            $names = array_values(array_filter(array_map(
                static fn ($a): string => (string) (is_array($a) ? ($a['name'] ?? '') : (string) $a),
                $allowed
            )));

            return new Answer(
                text: 'I could not match that to an action I may perform. I can do: ' . ($names !== [] ? implode(', ', $names) : 'none yet') . '.',
                intent: 'action',
                grounded: false
            );
        }
        // Without a resolver the host owns authorisation; preparing a proposal is
        // not executing one, and the host re-checks on confirm.
        if ($this->permissions !== null && !$this->permissions->canAct($request->user, $choice)) {
            return new Answer(
                text: 'Your role is not allowed to "' . $choice . '", so I did not prepare it.',
                intent: 'action',
                grounded: false
            );
        }

        try {
            $proposal = $this->actions->preview($request->user, $choice, (array) $request->option('params', []));
        } catch (\Throwable $e) {
            return new Answer(text: 'I could not prepare that change: ' . $e->getMessage(), intent: 'action', grounded: false);
        }
        if (!$proposal->valid) {
            return new Answer(
                text: $proposal->summary !== '' ? $proposal->summary : 'That change is missing something: ' . implode('; ', $proposal->errors),
                intent: 'action',
                pendingAction: $proposal->toArray(),
                grounded: false
            );
        }

        return new Answer(
            text: ($proposal->summary !== '' ? $proposal->summary : 'Prepare "' . $proposal->action . '"') . ' — confirm to apply it; nothing has changed yet.',
            intent: 'action',
            citations: [new Citation('record', $proposal->action)],
            pendingAction: $proposal->toArray(),
            suggestions: ['Confirm', 'Cancel']
        );
    }

    /**
     * @param array<int,mixed> $allowed
     */
    private function chooseAction(string $question, array $allowed): string
    {
        $names = [];
        foreach ($allowed as $action) {
            $name = (string) (is_array($action) ? ($action['name'] ?? '') : (string) $action);
            if ($name !== '') {
                $names[$name] = strtolower((string) (is_array($action) ? ($action['label'] ?? '') : $name));
            }
        }
        if ($names === []) {
            return '';
        }
        $messages = $this->convo(
            'Choose which action the user wants. Reply with JSON only: {"action":"name"}.'
            . ' Actions: ' . implode(', ', array_map(static fn (string $k, string $v): string => $k . ' (' . $v . ')', array_keys($names), array_values($names)))
            . ' If none fits, reply {"action":""}.',
            $question
        );
        $json = self::jsonObject((string) ($this->chat($messages, 0.0)['content'] ?? ''));
        $choice = strtolower(trim((string) ($json['action'] ?? '')));
        foreach ($names as $name => $label) {
            if ($choice === strtolower($name) || $choice === strtolower($label)) {
                return $name;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $plan */
    private function answerWithReport(AskRequest $request, array $plan): Answer
    {
        if ($this->reports === null) {
            return new Answer(
                text: 'Reports are not connected to me. I can still answer the same question once: ask it as a data question.',
                intent: 'report',
                grounded: false
            );
        }
        $name = trim((string) ($plan['report'] ?? $plan['name'] ?? ''));
        if ($name === '') {
            $list = [];
            foreach ($this->reports->list($request->user->tenantId, null, $request->user) as $row) {
                $list[] = (string) ($row['name'] ?? $row['id'] ?? '');
            }

            return new Answer(
                text: $list !== []
                    ? 'Which report? ' . implode(', ', array_filter($list))
                    : 'No reports exist yet for this account.',
                intent: 'report',
                grounded: false,
                suggestions: array_slice(array_filter($list), 0, 5)
            );
        }

        $result = $this->reports->run($name, (array) ($plan['params'] ?? []), $request->user);

        return new Answer(
            text: (string) ($result['summary'] ?? 'Report "' . $name . '" ran.'),
            intent: 'report',
            rows: (array) ($result['rows'] ?? []),
            citations: [new Citation('query', 'Report ' . $name, (string) ($result['run_id'] ?? ''))],
            meta: ['report' => $name]
        );
    }

    private function answerHelp(AskRequest $request): Answer
    {
        $capabilities = $this->capabilities($request->user);
        $lines = ['Here is what I can answer right now:'];
        if ($capabilities['datasets'] !== []) {
            $lines[] = '- Your records: ' . implode(', ', array_slice($capabilities['datasets'], 0, 14))
                . (count($capabilities['datasets']) > 14 ? ' (and more)' : '') . '. Ask for a number, a list, a comparison or a chart.';
        }
        if ($capabilities['knowledge']) {
            $lines[] = '- Trained knowledge: prices, policies, procedures, anything written into the knowledge base.';
        }
        if ($capabilities['documents']) {
            $lines[] = '- Uploaded documents: ask about a file you uploaded and I quote it with the page or section.';
        }
        if ($capabilities['actions'] !== []) {
            $lines[] = '- Changes I can prepare for approval: ' . implode(', ', array_slice($capabilities['actions'], 0, 10)) . '.';
        }
        if ($capabilities['reports']) {
            $lines[] = '- Reports and scheduled digests.';
        }
        if (!empty($capabilities['exports'])) {
            $lines[] = '- Files: add "as a csv" (or Excel/PDF, if this app enables them) to any data question and I write the file from the same rows I would show you.';
        }
        if (count($capabilities['datasets']) === 0 && !$capabilities['knowledge'] && !$capabilities['documents']) {
            $lines[] = '- Nothing yet: connect a schema catalogue or add knowledge first.';
        }
        $extra = trim(Config::string('domain.system_prompt', ''));
        if ($extra !== '') {
            $lines[] = '';
            $lines[] = $extra;
        }

        return new Answer(
            text: implode("\n", $lines),
            intent: 'help',
            grounded: true,
            suggestions: array_slice(array_map(static fn (string $d): string => 'How many records in ' . $d . ' this month?', $capabilities['datasets']), 0, 3)
        );
    }

    private function answerSmalltalk(AskRequest $request, array $history): Answer
    {
        $messages = $this->convo($this->personaPrompt($request), $request->question, $history);
        $reply = (string) ($this->chat($messages)['content'] ?? '');

        return new Answer(
            text: $reply !== '' ? $reply : 'I am here for your data, documents and reports — ask me something.',
            intent: 'smalltalk',
            grounded: false
        );
    }

    // ─── prompts and context ──────────────────────────────────────────────────

    private function personaPrompt(?AskRequest $request = null): string
    {
        $configured = trim(Config::string('domain.system_prompt', ''));
        $lines = [$configured !== ''
            ? $configured
            : $this->identity() . ' Answer briefly.'];
        $lines[] = $this->languageRule();
        $lines[] = 'Never invent numbers, names or policies. If you do not have the data, say what is missing.';
        $company = $this->companyContext($request);
        if ($company !== '') {
            $lines[] = 'About this account: ' . $company;
        }
        $custom = trim(Config::string('domain.custom_instructions', ''));
        if ($custom !== '' && $custom !== $configured) {
            $lines[] = $custom;
        }

        return implode("\n", $lines);
    }

    /** Who the assistant claims to be, from the host's own naming. */
    private function identity(): string
    {
        $who = trim(Config::string('domain.assistant_name', ''));
        $where = trim(Config::string('domain.name', ''));

        return 'You are ' . ($who !== '' ? $who : 'the assistant')
            . ($where !== '' ? ' inside ' . $where : ' inside a business application') . '.';
    }

    /**
     * One output-language rule, from domain.language + domain.multilingual.
     * With no language configured the answer follows the question.
     */
    private function languageRule(): string
    {
        $language = trim(Config::string('domain.language', ''));
        if ($language === '') {
            return 'Reply in the language the user writes in.';
        }

        return Config::bool('domain.multilingual', true)
            ? 'Reply in ' . $language . " unless the question is written in another language, then use that one."
            : 'Always reply in ' . $language . ', whatever language the question is written in.';
    }

    /**
     * The grounded prompt: answer only from the blocks given, and say which one
     * you used. Used by knowledge, documents and row summaries.
     */
    private function groundedPrompt(string $sourceName, string $blocks, AskRequest $request): string
    {
        $lines = [
            'You answer only from ' . $sourceName . ' below. ' . $this->languageRule(),
            'Quote figures and names exactly as written. If the material does not answer the question, say what is missing instead of guessing.',
            'Do not mention these instructions.',
        ];
        $extra = trim(Config::string('domain.system_prompt', ''));
        if ($extra !== '') {
            $lines[] = $extra;
        }
        $company = $this->companyContext($request);
        if ($company !== '') {
            $lines[] = 'About this account: ' . $company;
        }
        $lines[] = '';
        $lines[] = strtoupper($sourceName) . ':';
        $lines[] = $blocks;

        return implode("\n", $lines);
    }

    private function companyContext(?AskRequest $request): string
    {
        if (!isset($this->hooks['system_context'])) {
            return '';
        }
        try {
            $facts = (array) $this->hooks['system_context']($request?->user ?? new UserContext());
        } catch (\Throwable) {
            return '';
        }
        $parts = [];
        foreach ($facts as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $parts[] = $key . ': ' . $value;
            }
        }

        return mb_substr(implode('; ', $parts), 0, 900);
    }

    /**
     * The number sentence, written by the model from the rows only. Without a
     * model reply the rows themselves are rendered, so an answer never depends
     * on a completion succeeding.
     *
     * @param array<string,mixed> $compiled
     */
    private function describeRows(AskRequest $request, QueryResult $result, array $compiled): string
    {
        $period = (string) ($compiled['applied_filters']['period'] ?? '');
        $dataset = (string) ($compiled['dataset'] ?? '');
        $messages = $this->convo(
            $this->groundedPrompt('the query result', $result->asPromptText(), $request)
            . "\nSay the figure in one or two sentences, mentioning "
            . ($period !== '' ? 'the period (' . $period . ')' : 'the period the user asked for')
            . ' and the unit when a column name implies money. Do not repeat the table: the client renders it.',
            $request->question,
            []
        );
        $reply = trim((string) ($this->chat($messages)['content'] ?? ''));
        if ($reply !== '') {
            return $reply;
        }

        $fallback = $dataset !== '' ? 'From ' . $dataset . ($period !== '' ? ' (' . $period . ')' : '') . ': ' : '';

        return $fallback . $result->asPromptText(12);
    }

    /** @param array<string,mixed> $compiled @return Citation[] */
    private function dataCitations(array $compiled, QueryResult $result): array
    {
        $dataset = (string) ($compiled['dataset'] ?? '');
        $citations = [new Citation(
            'query',
            $dataset !== '' ? $dataset : 'query result',
            $result->sql !== null ? hash('sha256', $result->sql) : null,
            ['rows' => $result->count(), 'columns' => $result->columns]
        )];
        foreach ($result->recordRefs as $ref) {
            $citations[] = new Citation('record', (string) (is_array($ref) ? ($ref['label'] ?? '') : $ref), is_array($ref) ? (string) ($ref['id'] ?? '') : null);
        }

        return $citations;
    }

    /** @return array<string,mixed> */
    private function chartFor(QueryResult $result, string $wanted): array
    {
        $wanted = strtolower(trim($wanted));
        if ($wanted === '' || $wanted === 'null') {
            $suggested = $this->charts->suggest($result, 'data');

            return is_array($suggested) ? $suggested : [];
        }

        return $this->charts->build($wanted, $result);
    }

    /**
     * "Nothing matched" is an answer, and it must not be dressed up as a number.
     * A COUNT/SUM that legitimately returned zero keeps its row as the evidence
     * the query really ran; a result set with no rows has nothing to show.
     *
     * @param array<string,mixed> $appliedFilters
     * @param array<string,mixed> $compiled
     */
    private function emptyDataAnswer(AskRequest $request, array $appliedFilters, ?QueryResult $result = null, array $compiled = []): Answer
    {
        $scope = self::filterSentence($appliedFilters);
        $hasZeroRow = $result !== null && !$result->isEmpty();

        return new Answer(
            text: 'I found no matching records' . ($scope !== '' ? ' ' . $scope : '')
                . '. That is a real answer, not a missing one: the query ran and nothing matched. '
                . 'Try a wider period or a different filter.',
            intent: 'data',
            rows: $hasZeroRow ? $result->rows : [],
            citations: $hasZeroRow ? $this->dataCitations($compiled, $result) : [],
            sql: $hasZeroRow && $this->mayUseRawSql($request->user) ? (string) ($compiled['sql'] ?? '') : null,
            appliedFilters: $appliedFilters,
            suggestions: ['Try last 12 months', 'Show all records']
        );
    }

    /** A single aggregate row whose every measure is zero means an empty scope. */
    private static function isZeroAggregate(QueryResult $result, array $compiled): bool
    {
        if (($compiled['aggregate'] ?? false) !== true || count($result->rows) !== 1) {
            return false;
        }
        foreach ((array) ($result->rows[0] ?? []) as $value) {
            if (!is_numeric($value) || (float) $value !== 0.0) {
                return false;
            }
        }

        return true;
    }

    private function noPlanAnswer(AskRequest $request, string $reason): Answer
    {
        $datasets = $this->pack !== null ? $this->pack->datasetNames() : [];

        return new Answer(
            text: 'I could not turn that into a query (' . $reason . ').'
                . ($datasets !== [] ? ' I can answer about: ' . implode(', ', array_slice($datasets, 0, 12)) . '.' : ' No datasets are connected.'),
            intent: 'data',
            grounded: false,
            meta: ['reason' => $reason]
        );
    }

    /**
     * System prompt + history + this question, ready for chat().
     *
     * @param array<int,array<string,mixed>> $history
     * @return array<int,array{role:string,content:string}>
     */
    private function convo(string $system, string $question, array $history = []): array
    {
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $turn) {
            $role = (string) ($turn['role'] ?? 'user');
            $content = (string) ($turn['content'] ?? '');
            if ($content !== '' && in_array($role, ['user', 'assistant'], true)) {
                $messages[] = ['role' => $role, 'content' => mb_substr($content, 0, 4000)];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        return $messages;
    }

    /** @param array<int,array{role:string,content:string}> $messages @return array<string,mixed> */
    private function chat(array $messages, ?float $temperature = null): array
    {
        try {
            $result = $this->llm->chat($messages, $this->model, $temperature ?? $this->temperature);
        } catch (\Throwable $e) {
            $this->audit('assistant.llm_failed', ['error' => $e->getMessage()]);

            return ['content' => '', 'usage' => []];
        }
        $usage = (array) ($result['usage'] ?? []);
        if ($usage !== [] && isset($this->hooks['usage'])) {
            try {
                ($this->hooks['usage'])($usage);
            } catch (\Throwable) {
                // usage accounting must never cost the user their answer
            }
        }

        return is_array($result) ? $result : ['content' => (string) $result];
    }

    /** @return array<int,array<string,mixed>> */
    private function historyFor(AskRequest $request): array
    {
        if ($request->history !== []) {
            return $request->history;
        }
        if ($this->conversations === null) {
            return [];
        }
        $thread = $request->user->threadId;
        if ($thread === '') {
            return [];
        }
        try {
            $conversationId = $this->conversations->touch($request->user->tenantId, $request->user->channel, $thread);

            return $this->conversations->history($conversationId, $this->historyTurns);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Store the turn, then audit it. Both are best-effort: a history write must
     * not be the reason an answer fails.
     */
    private function remember(AskRequest $request, string $conversationId, string $question, Answer $answer, float $startedAt): void
    {
        if ($this->conversations !== null && $conversationId !== '') {
            try {
                $this->conversations->append($conversationId, [
                    'role' => 'user',
                    'content' => $question,
                    'intent' => $answer->intent,
                ]);
                $this->conversations->append($conversationId, [
                    'role' => 'assistant',
                    'content' => $answer->text,
                    'intent' => $answer->intent,
                    'citations' => array_map(static fn (Citation $c): array => $c->toArray(), $answer->citations),
                    'rows' => array_slice($answer->rows, 0, 50),
                    'chart' => $answer->chart,
                    'applied_filters' => $answer->appliedFilters,
                    'sql' => $answer->sql,
                    'status' => $answer->grounded ? 'ok' : 'ungrounded',
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
            } catch (\Throwable) {
                // keep the answer
            }
        }

        $this->audit('assistant.answered', [
            'tenant_id' => $request->user->tenantId,
            'user_id' => $request->user->userId,
            'channel' => $request->user->channel,
            'intent' => $answer->intent,
            'grounded' => $answer->grounded,
            'rows' => count($answer->rows),
            'citations' => count($answer->citations),
            'question' => mb_substr($question, 0, 500),
            'conversation_id' => $conversationId,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /** @param array<string,mixed> $detail */
    private function audit(string $action, array $detail): void
    {
        if (!isset($this->hooks['audit'])) {
            return;
        }
        try {
            ($this->hooks['audit'])($action, $detail);
        } catch (\Throwable) {
            // an audit sink that is down must not break the reply
        }
    }

    /** @return array<string,mixed> */
    private function withMeta(Answer $answer, AskRequest $request, string $conversationId, string $intent, string $routedBy = 'router'): Answer
    {
        $meta = $answer->meta + [
            'conversation_id' => $conversationId ?: null,
            'routed_by' => $routedBy,
            'channel' => $request->user->channel,
        ];

        return new Answer(
            text: $answer->text,
            intent: $intent,
            rows: $answer->rows,
            chart: $answer->chart,
            citations: $answer->citations,
            sql: $answer->sql,
            appliedFilters: $answer->appliedFilters,
            suggestions: $answer->suggestions,
            pendingAction: $answer->pendingAction,
            grounded: $answer->grounded,
            meta: $meta,
        );
    }

    // ─── small shared helpers ─────────────────────────────────────────────────

    /**
     * Pull the JSON object out of a completion: models wrap it in prose or
     * fences often enough that parsing the whole reply is not an option.
     *
     * @return array<string,mixed>
     */
    public static function jsonObject(string $text): array
    {
        $candidate = trim($text);
        if ($candidate === '') {
            return [];
        }
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $candidate, $m) === 1) {
            $candidate = $m[1];
        } else {
            $start = strpos($candidate, '{');
            $end = strrpos($candidate, '}');
            if ($start === false || $end === false || $end <= $start) {
                return [];
            }
            $candidate = substr($candidate, $start, $end - $start + 1);
        }
        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Render retrieved entries as prompt blocks, budgeted by characters.
     *
     * @param array<int,mixed> $chunks
     */
    private static function contextBlocks(array $chunks, int $maxChars): string
    {
        $blocks = [];
        $used = 0;
        foreach ($chunks as $chunk) {
            if (is_string($chunk)) {
                $title = '';
                $body = $chunk;
            } else {
                $row = (array) $chunk;
                $title = (string) ($row['title'] ?? $row['label'] ?? $row['source'] ?? '');
                $body = (string) ($row['content'] ?? $row['text'] ?? $row['chunk'] ?? '');
            }
            $body = trim($body);
            if ($body === '') {
                continue;
            }
            $block = ($title !== '' ? '[' . $title . '] ' : '') . $body;
            if ($used + strlen($block) > $maxChars) {
                break;
            }
            $blocks[] = $block;
            $used += strlen($block);
        }

        return implode("\n\n", $blocks);
    }

    /** @param array<int,mixed> $chunks */
    private static function plainChunks(array $chunks, int $max = 3): string
    {
        $out = [];
        foreach (array_slice($chunks, 0, $max) as $chunk) {
            $body = is_string($chunk) ? $chunk : (string) (((array) $chunk)['content'] ?? '');
            if (trim($body) !== '') {
                $out[] = '- ' . trim(mb_substr($body, 0, 400));
            }
        }

        return $out !== [] ? implode("\n", $out) : 'I have nothing on that yet.';
    }

    /** @param array<int,mixed> $chunks @return Citation[] */
    private static function knowledgeCitations(array $chunks): array
    {
        $citations = [];
        foreach ($chunks as $chunk) {
            if (is_string($chunk)) {
                $citations[] = new Citation('knowledge', mb_substr(trim($chunk), 0, 60));
                continue;
            }
            $row = (array) $chunk;
            $label = (string) ($row['title'] ?? $row['label'] ?? $row['source'] ?? 'knowledge');
            $citations[] = new Citation(
                in_array((string) ($row['type'] ?? ''), ['document', 'chunk'], true) ? 'document' : 'knowledge',
                $label !== '' ? $label : 'knowledge',
                isset($row['id']) ? (string) $row['id'] : null,
                array_filter(['score' => $row['score'] ?? null, 'page' => $row['page'] ?? null])
            );
        }

        return array_slice($citations, 0, 6);
    }

    /** @param array<string,mixed> $filters */
    private static function filterSentence(array $filters): string
    {
        $parts = [];
        foreach ($filters as $key => $value) {
            if ($key === 'dataset' || !is_scalar($value) || (string) $value === '') {
                continue;
            }
            $parts[] = (string) $value;
        }

        return $parts !== [] ? implode(' · ', $parts) : '';
    }
}
