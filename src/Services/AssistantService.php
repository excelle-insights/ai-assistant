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
    public const INTENTS = ['data', 'knowledge', 'document', 'action', 'report', 'help', 'smalltalk', 'clarify'];

    /** The parts of a date a question can group by. */
    private const PERIOD_NAMES = ['month', 'year', 'quarter', 'week', 'day', 'monthly', 'yearly', 'quarterly', 'weekly', 'daily'];

    /** Typoes that must not change what a question means. */
    private const MISSPELLINGS = [
        'inlude' => 'include', 'inlcude' => 'include', 'inclue' => 'include', 'inculde' => 'include',
        'includ' => 'include', 'inculdes' => 'includes', 'incude' => 'include',
        'adress' => 'address', 'emial' => 'email', 'phoen' => 'phone', 'contct' => 'contact',
        'quite' => 'quiet', 'amiunth' => 'amount', 'ammount' => 'amount', 'recieved' => 'received',
        'infomration' => 'information', 'infromation' => 'information', 'infomation' => 'information',
    ];

    /**
     * Words that ask for "the report" without saying which one.
     *
     * A question built from one of these names no dataset of its own, so
     * nothing can be answered until the user picks a subject.
     */
    private const VAGUE_SUBJECTS = [
        'report', 'reports', 'summary', 'summaries', 'digest', 'overview', 'breakdown',
        'numbers', 'figures', 'performance', 'commissions', 'allocation', 'reminders',
        'insights', 'statistics', 'dashboard',
    ];

    private LlmClientInterface $llm;
    private ?ConversationStoreInterface $conversations;
    private ?DomainPack $pack;
    private ?QueryCompiler $compiler;
    private ?KnowledgeRetrieverInterface $knowledge;
    private ?PermissionResolverInterface $permissions;
    private ?ChartService $charts;
    private ?ActionHandlerInterface $actions;
    private ?ReportService $reports;
    private ?CodeIndexService $code;
    private ?QueryLibraryService $queries;
    private ?RuleService $rules;
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
        ?CodeIndexService $code = null,
        ?QueryLibraryService $queries = null,
        ?RuleService $rules = null,
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
        $this->code = $code;
        $this->queries = $queries;
        $this->rules = $rules;
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
            'clarify' => $this->answerClarify($request, (array) $decision['plan']),
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

        $messages = $this->convo($this->routerPrompt($request), $request->question, $history);
        $json = self::jsonObject((string) ($this->chat($messages, 0.0)['content'] ?? ''));
        $intent = strtolower(trim((string) ($json['intent'] ?? '')));
        $plan = (array) ($json['plan'] ?? []);

        // "Include their contact details" names no dataset: it continues the
        // answer before it. Without this the router sends it to the knowledge
        // base and the thread loses its subject.
        $previous = $this->previousDataPlan($history);
        if ($previous !== null && $this->continuesPreviousAnswer($request->question) && $intent !== 'data') {
            return ['intent' => 'data', 'plan' => $this->asContinuation($previous), 'by' => 'follow_up'];
        }
        if ($intent === 'data' && $plan === [] && $previous !== null && $this->continuesPreviousAnswer($request->question)) {
            return ['intent' => 'data', 'plan' => $this->asContinuation($previous), 'by' => 'follow_up'];
        }
        // A question that only names fields is a record list, not a request for
        // help. The router has nothing to run, but the fields the user named
        // are enough to build the query.
        if (($intent === 'help' || $intent === 'knowledge') && $plan === []) {
            $fields = $this->fieldOnlyPlan($request->question);
            if ($fields !== null) {
                return ['intent' => 'data', 'plan' => $fields, 'by' => 'fields'];
            }
        }
        // A question too vague to answer is answered with a question.
        $clarify = $this->clarifyFor($request->question, $intent);
        if ($clarify !== null) {
            return ['intent' => 'clarify', 'plan' => $clarify, 'by' => 'clarify'];
        }
        // A screen this CRM already has: "the collections report" is the numbers
        // behind that screen, not a stored report to pick from. A question that
        // only names the subject ("how many leads") keeps the router's plan —
        // the screen is not what was asked for.
        $match = $this->packReportMatch($request);
        if ($match['report'] !== null) {
            $namesScreen = str_contains($match['phrase'], 'report') || str_contains($match['phrase'], 'screen');
            if ($intent !== 'data' || $namesScreen) {
                $screen = $this->planForPackReport($match['report'], $request);
                if ($screen !== []) {
                    return ['intent' => 'data', 'plan' => $screen, 'by' => 'pack_report'];
                }
            }
        }
        if (in_array($intent, self::INTENTS, true)) {
            return ['intent' => $intent, 'plan' => $plan, 'by' => 'router'];
        }

        // The model gave nothing usable — the question is read instead. `by` keeps
        // that visible in the answer's meta, so a support ticket can tell a model
        // answer from a fallback one.
        return ['intent' => $this->guessIntent($request->question), 'plan' => $plan, 'by' => 'guessed'];
    }

    /**
     * A continued plan keeps the period of the answer it continues: "include
     * their contact details" still means the same month.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function asContinuation(array $plan): array
    {
        $plan['continued'] = true;
        // A follow-up asks for more than the screen shows, so the question is
        // allowed to shape the answer again.
        unset($plan['fixed']);

        return $plan;
    }

    /**
     * The plan of the last data answer in this thread, if there is one.
     *
     * @param array<int,array<string,mixed>> $history
     * @return array<string,mixed>|null
     */
    private function previousDataPlan(array $history): ?array
    {
        for ($index = count($history) - 1; $index >= 0; $index--) {
            $turn = (array) ($history[$index] ?? []);
            if ((string) ($turn['role'] ?? '') !== 'assistant') {
                continue;
            }
            $plan = (array) ($turn['plan'] ?? []);
            if ($plan === []) {
                return null;
            }
            if (trim((string) ($plan['dataset'] ?? '')) === '') {
                return null;
            }
            return $plan;
        }

        return null;
    }

    /**
     * True when the question only asks for more of what was just answered.
     *
     * "Include contact info", "add their details", "show the numbers too" —
     * none of them names a dataset, so they are a continuation, not a new
     * question. A question that names its own subject is never one.
     */
    private function continuesPreviousAnswer(string $question): bool
    {
        $text = ' ' . strtolower(trim($question)) . ' ';
        // "inlude contact info" is the same question as "include contact info".
        $text = strtr($text, self::MISSPELLINGS);
        if (mb_strlen(trim($question)) > 120) {
            return false;
        }
        if ($this->namesItsOwnSubject($text)) {
            return false;
        }
        if (preg_match('/\b(how many|how much|total|sum|average|count|compare|rank|chart|export|download|report|create|add|delete|update|change|send|email|schedule)\b/', $text) === 1
            && preg_match('/\b(include|with|also|plus|add|show|give|list)\b/', $text) === 0) {
            return false;
        }
        if (preg_match('/\b(include|with|also|plus|add|show|give|list|more|their|them|it)\b/', $text) !== 1) {
            return false;
        }
        // The words that say "same records, more columns".
        return preg_match('/\b(details?|info|information|contacts?|emails?|phones?|numbers?|names?|addresses?|status|state|source|owner|campaign|amounts?|prices?|dates?|history|comments?|notes?|photos?|files?|documents?)\b/', $text) === 1;
    }

    /**
     * True when the question names a dataset of its own — then it is a new
     * question, not a continuation of the answer before it.
     */
    private function namesItsOwnSubject(string $text): bool
    {
        if ($this->pack === null) {
            return false;
        }
        foreach ($this->pack->listDatasets() as $dataset) {
            $names = array_merge(
                [(string) ($dataset['name'] ?? ''), (string) ($dataset['label'] ?? '')],
                array_map('strval', (array) ($dataset['aliases'] ?? []))
            );
            foreach ($names as $name) {
                $needle = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', ' ', str_replace('_', ' ', (string) $name))));
                if ($needle === '' || strlen($needle) < 3) {
                    continue;
                }
                if (str_contains($text, ' ' . $needle . ' ')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A question that only names fields.
     *
     * "Lead name, email, phone, etc" says what to show and nothing to count.
     * The router cannot answer it with a query, so it falls back to a
     * capability list; the fields it named are enough to build one.
     *
     * @return array<string,mixed>|null
     */
    private function fieldOnlyPlan(string $question): ?array
    {
        if ($this->pack === null) {
            return null;
        }
        $text = $this->questionWords($question);
        if (preg_match('/\b(how many|how much|totals?|sums?|averages?|avg|counts?|number of|percentage|compare|chart|graph|trend|breakdown|rank|ranking)\b/', $text) === 1) {
            return null;
        }
        foreach ($this->pack->listDatasets() as $dataset) {
            if (!$this->namesDataset($text, $dataset)) {
                continue;
            }
            $fields = $this->fieldsNamed($text, $dataset);
            if ($fields === []) {
                continue;
            }
            $identity = $this->identityDimension($dataset);
            $dimensions = $identity !== '' && !in_array($identity, $fields, true)
                ? array_merge([$identity], $fields)
                : $fields;

            return [
                'dataset' => (string) ($dataset['name'] ?? ''),
                'dimensions' => $dimensions,
                'metrics' => [],
                'filters' => [],
                'limit' => 20,
            ];
        }

        return null;
    }

    /**
     * A question too vague to answer, and the options that would answer it.
     *
     * "Give me the report" names a subject this CRM has many of. Guessing one
     * wastes the turn; asking which one is what a colleague would do.
     *
     * @return array<string,mixed>|null
     */
    private function clarifyFor(string $question, string $intent): ?array
    {
        if (!in_array($intent, ['help', 'knowledge'], true)) {
            return null;
        }
        $text = $this->questionWords($question);
        if (preg_match('/\b(what can you|how do you use|how do i use|help me use|capabilities|commands?|who are you|what are you|help)\b/', $text) === 1) {
            return null;
        }
        if ($this->namesItsOwnSubject($text)) {
            return null;
        }
        $vague = false;
        foreach (self::VAGUE_SUBJECTS as $subject) {
            if (str_contains($text, ' ' . $subject . ' ') || str_contains($text, ' ' . $subject . 's ')) {
                $vague = true;
                break;
            }
        }
        if (!$vague) {
            return null;
        }
        $options = $this->clarifyOptions($text);
        if ($options === []) {
            return null;
        }

        return ['options' => $options, 'question' => trim($question)];
    }

    /**
     * The subjects a vague question could mean, best match first.
     *
     * @return string[]
     */
    private function clarifyOptions(string $text): array
    {
        $reports = $this->packReports();
        $scored = [];
        foreach ($reports as $report) {
            $label = trim((string) ($report['label'] ?? '')) ?: trim((string) ($report['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $score = 0;
            foreach (preg_split('/\W+/', strtolower($label . ' ' . (string) ($report['description'] ?? ''))) ?: [] as $word) {
                if (strlen($word) >= 4 && str_contains($text, ' ' . $word . ' ')) {
                    $score++;
                }
            }
            $scored[] = ['label' => $label, 'score' => $score];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $options = array_map(static fn (array $entry): string => (string) $entry['label'], $scored);
        if ($options === [] && $this->pack !== null) {
            foreach ($this->pack->listDatasets() as $dataset) {
                $label = trim((string) ($dataset['label'] ?? ''));
                if ($label !== '') {
                    $options[] = $label;
                }
            }
        }

        return array_values(array_unique(array_slice($options, 0, 6)));
    }

    /** The reports this CRM already has, as the pack declares them. */
    private function packReports(): array
    {
        return $this->pack?->reports() ?? [];
    }

    /**
     * Ask instead of guessing.
     *
     * @param array<string,mixed> $plan
     */
    private function answerClarify(AskRequest $request, array $plan): Answer
    {
        $options = array_values(array_map('strval', (array) ($plan['options'] ?? [])));
        if ($options === []) {
            $options = $this->clarifyOptions($this->questionWords($request->question));
        }
        $text = $options === []
            ? 'Tell me a little more and I will answer it.'
            : 'To answer that, which one do you mean?';

        return new Answer(
            text: $text,
            intent: 'clarify',
            grounded: true,
            suggestions: array_slice($options, 0, 6),
            meta: ['clarified_question' => trim((string) ($plan['question'] ?? ''))]
        );
    }

    /**
     * The words of a question, with the typos that must not change its meaning
     * already repaired.
     */
    private function questionWords(string $question): string
    {
        $text = ' ' . trim((string) preg_replace('/[^a-z0-9_ ]+/u', ' ', strtolower($question)), ' ') . ' ';

        return ' ' . trim(strtr($text, self::MISSPELLINGS)) . ' ';
    }

    /**
     * A phrase reduced to the words a question uses.
     */
    private function normalisePhrase(string $phrase): string
    {
        // Lower case first: a capital letter is not part of the word and
        // stripping it would turn "Lead phone" into "ead phone".
        $lower = strtolower(str_replace('_', ' ', $phrase));

        return trim((string) preg_replace('/[^a-z0-9_ ]+/u', ' ', $lower));
    }

    /**
     * The longest phrase of a field the question uses.
     *
     * @param string[] $phrases
     */
    private function phraseHit(string $text, array $phrases): int
    {
        return strlen($this->matchedPhrase($text, $phrases));
    }

    /**
     * The longest phrase the question uses, as written.
     *
     * @param string[] $phrases
     */
    private function matchedPhrase(string $text, array $phrases): string
    {
        $best = '';
        foreach ($phrases as $phrase) {
            $needle = $this->normalisePhrase((string) $phrase);
            if ($needle === '' || strlen($needle) < 3) {
                continue;
            }
            if (str_contains($text, ' ' . $needle . ' ') || (strlen($needle) >= 5 && str_contains($text, $needle))) {
                if (strlen($needle) > strlen($best)) {
                    $best = $needle;
                }
            }
        }

        return $best;
    }

    /** True when the question uses this phrase as a whole word. */
    private function phraseIn(string $text, string $phrase): bool
    {
        $needle = $this->normalisePhrase($phrase);
        if ($needle === '' || strlen($needle) < 3) {
            return false;
        }

        return str_contains($text, ' ' . $needle . ' ') || str_contains($text, ' ' . $needle . 's ');
    }

    /**
     * True when the question names this dataset.
     *
     * @param array<string,mixed> $dataset
     */
    private function namesDataset(string $text, array $dataset): bool
    {
        $names = array_merge(
            [(string) ($dataset['name'] ?? ''), (string) ($dataset['label'] ?? '')],
            array_map('strval', (array) ($dataset['aliases'] ?? []))
        );
        foreach ($names as $name) {
            if ($this->phraseIn($text, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The dimensions of a dataset the question names.
     *
     * @param array<string,mixed> $dataset
     * @return string[]
     */
    private function fieldsNamed(string $text, array $dataset): array
    {
        $fields = [];
        foreach ((array) ($dataset['dimensions'] ?? []) as $dimension) {
            if (!is_array($dimension)) {
                continue;
            }
            $name = (string) ($dimension['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $phrases = array_merge(
                [$name, (string) ($dimension['label'] ?? '')],
                array_map('strval', (array) ($dimension['aliases'] ?? []))
            );
            if ($this->phraseHit($text, $phrases) > 0) {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    /**
     * The dimension that names a record of the dataset.
     *
     * @param array<string,mixed> $dataset
     */
    private function identityDimension(array $dataset): string
    {
        $names = [];
        foreach ((array) ($dataset['dimensions'] ?? []) as $dimension) {
            if (is_array($dimension)) {
                $names[] = (string) ($dimension['name'] ?? '');
            }
        }
        foreach (['name', 'title', 'unit', 'serial', 'client', 'project'] as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        return '';
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
        // "all listings", "projects", "users" — naming something the pack can
        // answer is a data request even without a measure word.
        if ($this->hasDataPath() && $this->pack !== null) {
            foreach ($this->pack->listDatasets() as $def) {
                $names = array_merge(
                    [(string) ($def['name'] ?? ''), (string) ($def['label'] ?? '')],
                    array_map('strval', (array) ($def['aliases'] ?? []))
                );
                foreach ($names as $name) {
                    $needle = strtolower(trim($name));
                    if ($needle !== '' && preg_match('/\b' . preg_quote($needle, '/') . '\b/', $t) === 1) {
                        return 'data';
                    }
                }
            }
        }
        if ($this->knowledge !== null && preg_match('/\b(policy|price|prices|rate|rates|discount|hours|opening|warranty|refund|procedure|process)\b/', $t) === 1) {
            return 'knowledge';
        }

        return $this->hasDataPath() ? 'data' : 'smalltalk';
    }

    private function routerPrompt(AskRequest $request): string
    {
        // A host can replace the routing instructions (own JSON dialect, extra
        // rules, another language). The vocabulary and custom instructions below
        // still travel with it — without them no model can name a dataset.
        $override = trim(Config::string('domain.query_prompt', ''));
        $lines = [$override !== '' ? $override : 'You route one user question to the right brain and, for a data question, describe the query.'];
        if ($override === '') {
            $lines = array_merge($lines, [
                'Reply with JSON only, no prose, shaped like:',
                '{"intent":"data|knowledge|document|action|report|help|smalltalk|clarify","plan":{}}',
                'For intent "data" the plan object is:',
                '{"dataset":"name","metrics":["name"],"dimensions":["name"],'
                . '"filters":[{"field":"name","operator":"=|!=|>|>=|<|<=|like|contains|in|not_in|between|is_null|not_null","value":null}],'
                . '"date_range":null,'
                . '"order_by":[{"field":"name","direction":"desc"}],"limit":20,"chart":"bar|line|area|pie|scatter|kpi|""}',
                'Rules:',
                '- Use only names that appear in the vocabulary. Never invent a column, table or SQL.',
                '- Anything countable is intent "data": a number must come from a query, never from memory.',
                '- A dataset name on its own is still "data" — "all listings", "show projects", "users", "tasks" must return rows, not help text.',
                '- "All users", "all leads" and "list clients" ask for records: use the identity dimension (name, or title/unit when the dataset has one) and no measure, so each record is identified. Group by categories only when the question says "by ..." or asks how many.',
                '- A word in also[...] or a dataset label is the same dataset (for example "projects" or "properties" for listings). Resolve it to the dataset name.',
                '- date[field] in the vocabulary marks the dataset\'s own date column. Use it only through date_range; never put that field in dimensions, filters, metrics or order_by.',
                '- periods[month|year] (or a dimension named month, year, quarter, week or day) are that same date broken into parts. A question about a period ("payment totals by month", "leads per quarter") groups by that name AND adds the metric for the amount, so every period is one summed row — never one row per record.',
                '- A question that only adds fields to the previous answer ("include contact info", "add their details") stays on the same dataset and adds those fields.',
                '- Time words ("this month", "last year", "today") always become date_range, never a filter on a date column.',
                '- Include date_range only when the question names a period ("this month", "last year", a date or a year). "All users", "all listings" and "show me tasks" mean every record: date_range must be null.',
                '- Facts the user typed in (prices, policies, instructions) are intent "knowledge".',
                '- Content of uploaded files is "document". Writes (add, change, remove) are "action". Recurring digests are "report".',
                '- "help" only when the user asks what you can do or how to use you; "smalltalk" for greetings and general conversation.',
                '- If no dataset fits, choose "knowledge" or "help" rather than guessing a dataset.',
                '- When the question asks for "the report" without saying which one, and the reports below list more than one, choose "clarify" and put the report names in plan.options.',
                '- Relative periods ("last year", "this month") resolve against the current date below: put the preset in date_range.preset instead of computing from/to yourself.',
            ]);
        }

        // The model has no clock. Without this line it anchors "last year" to
        // its training data and returns a period before the records exist.
        $lines[] = 'Current date: ' . $this->today();

        $vocabulary = $this->hasDataPath() && $this->pack !== null
            ? $this->pack->vocabulary(Config::int('semantic.vocab_chars', 12000))
            : '';
        if ($vocabulary !== '') {
            $lines[] = 'Vocabulary:';
            $lines[] = $vocabulary;
        }
        $screens = $this->packReportSection();
        if ($screens !== '') {
            $lines[] = $screens;
        }
        $custom = trim(Config::string('domain.custom_instructions', ''));
        if ($custom !== '') {
            $lines[] = $custom;
        }

        $known = $this->knownCodeQueries($request);
        if ($known !== '') {
            $lines[] = $known;
        }

        $reports = $this->knownReports($request);
        if ($reports !== '') {
            $lines[] = $reports;
        }

        $rules = $this->knownRules($request);
        if ($rules !== '') {
            $lines[] = $rules;
        }

        return implode("\n", $lines);
    }

    /**
     * What the CRM's own code already runs, for the questions it covers.
     *
     * These are the application's real joins and real WHERE clauses — the
     * ground truth a description cannot give. They are read, never run: the
     * pack still decides what may be executed, and the host's table policy
     * still applies to every answer.
     */
    private function knownCodeQueries(AskRequest $request): string
    {
        if ($this->code === null) {
            return '';
        }
        try {
            return $this->code->promptSection($request->user->tenantId, $request->question, 3);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The reports this CRM already runs, for the questions they answer.
     *
     * A named report is the business's own way of doing the calculation, so
     * the plan should follow its rules instead of inventing its own.
     */
    private function knownReports(AskRequest $request): string
    {
        if ($this->queries === null) {
            return '';
        }
        try {
            return $this->queries->promptSection($request->user->tenantId, $request->question, 2);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The business rules this CRM follows, for the questions they cover.
     *
     * A verified rule is somebody's own statement of how the business works;
     * it leads the prompt. A draft is only offered when nothing verified
     * answers, because a rule nobody checked must not outrank one that was.
     */
    private function knownRules(AskRequest $request): string
    {
        if ($this->rules === null) {
            return '';
        }
        try {
            return $this->rules->promptSection($request->user->tenantId, $request->question, 3);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The screens this CRM calls reports, for the router.
     *
     * A CRM that ships report screens wants "the collections report" answered
     * with the same numbers the screen shows, so the router is told which
     * dataset each screen stands for.
     */
    private function packReportSection(): string
    {
        $reports = $this->packReports();
        if ($reports === []) {
            return '';
        }
        $lines = ['Reports this CRM already has (answer them with the dataset behind them):'];
        foreach (array_slice($reports, 0, 30) as $report) {
            $name = trim((string) ($report['label'] ?? '')) ?: trim((string) ($report['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $line = '- ' . $name;
            if ((string) ($report['dataset'] ?? '') !== '') {
                $line .= ' -> dataset ' . (string) $report['dataset'];
            }
            if ((string) ($report['question'] ?? '') !== '') {
                $line .= ' ("' . (string) $report['question'] . '")';
            }
            $lines[] = $line;
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * The report screen this question names, when the pack declares one.
     *
     * @return array<string,mixed>|null
     */
    private function matchedPackReport(AskRequest $request): ?array
    {
        $match = $this->packReportMatch($request);

        return $match['report'];
    }

    /**
     * The report screen this question names, and the phrase that named it.
     *
     * The longest name the question uses wins: "the collections report" must
     * not fall for the "leads report" that also appears in the question.
     *
     * @return array{report:array<string,mixed>|null,phrase:string}
     */
    private function packReportMatch(AskRequest $request): array
    {
        $text = $this->questionWords($request->question);
        $best = null;
        $bestPhrase = '';
        foreach ($this->packReports() as $report) {
            $names = array_merge(
                [(string) ($report['name'] ?? ''), (string) ($report['label'] ?? '')],
                array_map('strval', (array) ($report['aliases'] ?? []))
            );
            $phrase = $this->matchedPhrase($text, $names);
            if (strlen($phrase) > strlen($bestPhrase)) {
                $best = $report;
                $bestPhrase = $phrase;
            }
        }

        return ['report' => $best, 'phrase' => $bestPhrase];
    }

    /**
     * The plan behind a report screen this CRM already has.
     *
     * A CRM that ships report screens wants "the collections report" answered
     * with the numbers that screen shows, not with a stored report to pick
     * from. The pack says which dataset the screen stands for and, when the
     * screen has a fixed shape, the columns it shows.
     *
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function planForPackReport(array $report, AskRequest $request): array
    {
        $declared = (array) ($report['plan'] ?? []);
        $dataset = trim((string) ($declared['dataset'] ?? $report['dataset'] ?? ''));
        if ($dataset === '') {
            return [];
        }
        if ($declared !== []) {
            return $this->fixedPlan($dataset, $declared);
        }
        // A report that names only its dataset is answered with the question
        // its screen answers, so the model writes the query the screen runs.
        $question = trim((string) ($report['question'] ?? ''));
        if ($question === '') {
            return [];
        }
        $screen = new AskRequest($question, $request->user, $request->history, $request->context, $request->options);
        $plan = $this->askForPlan($screen, '');
        if ($plan === []) {
            return [];
        }
        $plan['dataset'] = $dataset;

        return $plan;
    }

    /**
     * The plan of a report screen, exactly as the screen shows it.
     *
     * @param array<string,mixed> $declared
     * @return array<string,mixed>
     */
    private function fixedPlan(string $dataset, array $declared): array
    {
        $plan = [
            'dataset' => $dataset,
            'dimensions' => array_values(array_map('strval', (array) ($declared['dimensions'] ?? []))),
            'metrics' => array_values(array_map('strval', (array) ($declared['metrics'] ?? []))),
            'limit' => max(1, (int) ($declared['limit'] ?? 20)),
            // The shape is the screen's, not the question's: the columns the
            // user typed must not rewrite the columns the report shows.
            'fixed' => true,
        ];
        foreach (['order_by', 'filters', 'group_by', 'date_range'] as $key) {
            if (isset($declared[$key]) && is_array($declared[$key])) {
                $plan[$key] = $declared[$key];
            }
        }

        return $plan;
    }

    /**
     * The stored report that answers this question, if there is one.
     *
     * @return array<string,mixed>|null
     */
    private function matchedReport(AskRequest $request): ?array
    {
        if ($this->queries === null) {
            return null;
        }
        try {
            $matches = $this->queries->match($request->user->tenantId, $request->question, 1);
        } catch (\Throwable) {
            return null;
        }

        return $matches[0] ?? null;
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
            return $this->emptyDataAnswer(
                $request,
                (array) ($compiled['applied_filters'] ?? []),
                $result,
                $compiled,
                $this->latestPeriod($compiled, $user)
            );
        }

        $export = $this->exportFor($request, $result, $compiled);

        $citations = $this->dataCitations($compiled, $result);
        $report = $this->matchedReport($request) ?? $this->matchedPackReport($request);
        if ($report !== null) {
            $citations[] = new Citation(
                'query',
                (string) $report['name'],
                (string) ($report['id'] ?? ''),
                ['module' => (string) ($report['module'] ?? '')]
            );
        }

        return new Answer(
            text: $this->describeRows($request, $result, $compiled) . (string) ($export['text'] ?? ''),
            intent: 'data',
            rows: $result->rows,
            chart: $wantsChart ? $this->chartFor($result, $chartType) : [],
            citations: $citations,
            suggestions: (array) ($export['suggestions'] ?? []),
            sql: $this->mayUseRawSql($user) ? (string) ($compiled['sql'] ?? '') : null,
            appliedFilters: (array) ($compiled['applied_filters'] ?? []),
            meta: [
                'columns' => $result->columns,
                'formats' => (array) ($compiled['formats'] ?? []),
                'dataset' => (string) ($compiled['dataset'] ?? ''),
                // The plan that produced this answer, so a follow-up ("include
                // their contact details") can continue it instead of starting
                // a new question.
                'plan' => (array) ($compiled['plan'] ?? []),
                'truncated' => $result->truncated,
                'source' => $result->source,
                'export' => $export,
                'report' => $report === null ? null : [
                    'id' => (int) ($report['id'] ?? 0),
                    'name' => (string) ($report['name'] ?? ''),
                    'module' => (string) ($report['module'] ?? ''),
                ],
            ]
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
        $plan = $this->preparePlanShape($plan, $request->question);

        try {
            $queryPlan = $this->applyRowCap(QueryPlan::fromArray($plan), $request);
            return [$queryPlan, $this->compiled($compiler, $queryPlan, $plan, $request)];
        } catch (\Throwable $e) {
            $retry = $this->askForPlan($request, $e->getMessage());
            if ($retry === []) {
                throw $e;
            }
            $retry = $this->preparePlanShape($retry, $request->question);
            $queryPlan = $this->applyRowCap(QueryPlan::fromArray($retry), $request);
            return [$queryPlan, $this->compiled($compiler, $queryPlan, $retry, $request)];
        }
    }

    /**
     * Compile and keep the shaped plan beside the SQL: later steps read the
     * plan's dimensions (a single number vs a grouping) and its labels.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function compiled(QueryCompiler $compiler, QueryPlan $queryPlan, array $plan, AskRequest $request): array
    {
        $compiled = $compiler->compile($queryPlan, $this->scopeFor($queryPlan, $request->user));
        $compiled['plan'] = $plan;
        // The relations behind the names in this answer, so every name is
        // traceable to the relation that produced it.
        $compiled['relations'] = $this->pack?->relationNotes((string) ($compiled['dataset'] ?? '')) ?? [];

        return $compiled;
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function preparePlanShape(array $plan, string $question): array
    {
        $plan = $this->withDateFieldFixes($plan);
        $plan = $this->withDisplayLabels($plan, $question);
        $plan = $this->withDimensionColumns($plan, $question);
        // A report screen has a shape of its own: the columns it shows, not the
        // ones the question happens to mention.
        if (($plan['fixed'] ?? false) === true) {
            return $this->withKnownFields($this->withRelativePeriod($plan, $question));
        }
        $plan = $this->withMentionedFields($plan, $question);
        $plan = $this->withRecordList($plan, $question);
        $plan = $this->withKnownFields($plan);
        $plan = $this->withPeriodTotals($plan, $question);
        $plan = $this->withRelativePeriod($this->withDefaultShape($plan, $question), $question);

        return $this->withPeriodGuard($plan, $question);
    }

    /**
     * "Payment totals by month" is one summed row per month. A plan that groups
     * by a period but names no measure answers with one row per payment and its
     * own amount — the very numbers the user asked to be added up. So the
     * period grouping and the measure that belongs to it travel together.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function withPeriodTotals(array $plan, string $question): array
    {
        if ($this->pack === null || !isset($plan['dataset']) || !is_string($plan['dataset'])) {
            return $plan;
        }
        $dataset = $this->pack->dataset($plan['dataset']);
        if ($dataset === null) {
            return $plan;
        }
        $dimensions = array_values(array_map('strval', (array) ($plan['dimensions'] ?? [])));
        // "by month", "per quarter", "each year" — the period the user groups by.
        $named = '';
        if (preg_match('/\b(?:by|per|each|in|for)\s+(month|year|quarter|week|day)\b/i', $question, $m) === 1) {
            $named = strtolower($m[1]);
        }
        $groups = false;
        foreach ($dimensions as $dimension) {
            if (in_array(strtolower($dimension), self::PERIOD_NAMES, true)) {
                $groups = true;
                break;
            }
        }
        if (!$groups && $named === '') {
            return $plan;
        }
        // The dataset's own period dimension joins the answer, even when the
        // model grouped by the raw date column.
        if ($named !== '' && !in_array($named, $dimensions, true) && $this->pack->dimension((string) $plan['dataset'], $named) !== null) {
            $dimensions[] = $named;
            $plan['dimensions'] = $dimensions;
        }
        if ((array) ($plan['metrics'] ?? []) !== []) {
            return $plan;
        }
        // A list of the month's records is not a total; only a question that
        // asks for one gets a measure.
        if (preg_match('/\b(totals?|sums?|summed|how much|how many|counts?|number of|revenue|collected|averages?|average|monthly|yearly|quarterly|weekly|daily)\b/i', $question) !== 1) {
            return $plan;
        }
        $metric = $this->periodMetric($dataset, $question);
        if ($metric !== '') {
            $plan['metrics'] = [$metric];
        }

        return $plan;
    }

    /**
     * The measure a period question wants: a count when the user counts, the
     * money column when the user totals, the average when the user averages.
     *
     * @param array<string,mixed> $dataset
     */
    private function periodMetric(array $dataset, string $question): string
    {
        $metrics = [];
        foreach ((array) ($dataset['metrics'] ?? []) as $metric) {
            if (is_array($metric) && trim((string) ($metric['name'] ?? '')) !== '') {
                $metrics[] = $metric;
            }
        }
        if ($metrics === []) {
            return '';
        }
        $wantsCount = preg_match('/\b(how many|counts?|number of)\b/i', $question) === 1;
        $wantsAverage = preg_match('/\b(averages?|average)\b/i', $question) === 1;
        if ($wantsCount) {
            foreach ($metrics as $metric) {
                if ((string) ($metric['function'] ?? '') === 'count') {
                    return (string) $metric['name'];
                }
            }

            return '';
        }
        if ($wantsAverage) {
            foreach ($metrics as $metric) {
                if ((string) ($metric['function'] ?? '') === 'avg') {
                    return (string) $metric['name'];
                }
            }
        }
        foreach ($metrics as $metric) {
            if ((string) ($metric['function'] ?? '') === 'sum' && (string) ($metric['format'] ?? '') === 'money') {
                return (string) $metric['name'];
            }
        }
        foreach ($metrics as $metric) {
            if ((string) ($metric['function'] ?? '') === 'sum') {
                return (string) $metric['name'];
            }
        }
        foreach ($metrics as $metric) {
            if ((string) ($metric['function'] ?? '') === 'count') {
                return (string) $metric['name'];
            }
        }

        return '';
    }

    /**
     * Nobody asks for "officer 12"; they ask who the officer is. When the
     * dataset carries a key column and the display column that belongs to it
     * ("officer" and "officer_name"), the display column wins. A question that
     * asks for the id keeps the key.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function withDisplayLabels(array $plan, string $question): array
    {
        if ($this->pack === null || !isset($plan['dataset']) || !is_string($plan['dataset'])) {
            return $plan;
        }
        if (preg_match('/\bids?\b/i', $question) === 1) {
            return $plan;
        }
        $dimensions = array_map('strval', (array) ($plan['dimensions'] ?? []));
        if ($dimensions === []) {
            return $plan;
        }
        $dataset = (string) $plan['dataset'];
        $changed = false;
        foreach ($dimensions as $index => $name) {
            $dimension = $this->pack->dimension($dataset, $name);
            if ($dimension === null || !DomainPack::isIdDimension($dimension)) {
                continue;
            }
            $sibling = $this->pack->displaySibling($dataset, (string) ($dimension['name'] ?? $name));
            if ($sibling === null || $sibling === $name) {
                continue;
            }
            $dimensions[$index] = $sibling;
            $changed = true;
        }
        if ($changed) {
            $plan['dimensions'] = array_values($dimensions);
        }

        return $plan;
    }

    /**
     * "List payments with ... amount" sometimes comes back with the amount as
     * a metric. On a list question the named column is a value per record, not
     * a sum, and asking the compiler for it as a metric fails. Metrics that are
     * really pack dimensions are therefore demoted for record lists only.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function withDimensionColumns(array $plan, string $question): array
    {
        if ($this->pack === null || !isset($plan['dataset']) || !is_string($plan['dataset'])) {
            return $plan;
        }
        $metrics = (array) ($plan['metrics'] ?? []);
        if ($metrics === []) {
            return $plan;
        }
        if (preg_match('/\b(list|latest|recent|show|display|all|details?|records?)\b/i', $question) !== 1) {
            return $plan;
        }
        if (preg_match('/\b(how many|how much|counts?|totals?|sums?|averages?|per|by each)\b/i', $question) === 1) {
            return $plan;
        }
        $dataset = (string) $plan['dataset'];
        $entry = $this->pack->dataset($dataset);
        $moved = [];
        $keep = [];
        foreach ($metrics as $raw) {
            $name = (string) $raw;
            $dimension = $this->pack->dimension($dataset, $name);
            if ($dimension !== null && $this->pack->metric($dataset, $name) === null) {
                $moved[] = (string) $dimension['name'];
                continue;
            }
            $metric = $this->pack->metric($dataset, $name);
            if ($metric !== null && (string) ($metric['format'] ?? '') === 'money') {
                // A sum on a record list answers a different question ("how
                // much in total") than the one asked ("show every payment").
                // The dataset's own money column shows the amount per record.
                foreach ((array) ($entry['dimensions'] ?? []) as $candidate) {
                    if (is_array($candidate) && (string) ($candidate['format'] ?? '') === 'money') {
                        $moved[] = (string) $candidate['name'];
                        continue 2;
                    }
                }
            }
            $keep[] = $name;
        }
        if ($moved === []) {
            return $plan;
        }
        $plan['dimensions'] = array_values(array_unique(array_merge(
            array_map('strval', (array) ($plan['dimensions'] ?? [])),
            $moved
        )));
        if ($keep === []) {
            unset($plan['metrics']);
        } else {
            $plan['metrics'] = $keep;
        }

        return $plan;
    }

    /**
     * "List payments with receipt, project, amount and date" tends to come
     * back as a title-only list because the model drops the measure and the
     * date. Every pack field the question names is added to a record list, so
     * the amount the user asked for is in the answer. Aggregates are left
     * alone: an extra dimension would turn one number into rows.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function withMentionedFields(array $plan, string $question): array
    {
        if ($this->pack === null || !isset($plan['dataset']) || !is_string($plan['dataset'])) {
            return $plan;
        }
        $dataset = $this->pack->dataset($plan['dataset']);
        if ($dataset === null) {
            return $plan;
        }
        $words = $this->questionWords($question);
        $wantsId = preg_match('/\bids?\b/i', $question) === 1;
        $chosen = [];
        foreach ((array) ($plan['dimensions'] ?? []) as $dimension) {
            $chosen[] = (string) $dimension;
        }
        // The fields the question names that this table does not have: the
        // answer says where they live instead of quietly dropping them.
        $missing = $this->missingFieldsFor($dataset, $words);
        $named = [];
        foreach ((array) ($dataset['dimensions'] ?? []) as $dimension) {
            if (!is_array($dimension)) {
                continue;
            }
            $name = (string) ($dimension['name'] ?? '');
            if ($name === '' || in_array($name, $chosen, true)) {
                continue;
            }
            // A key column ("Staff id") is not a field somebody asked for; the
            // display column next to it carries the value.
            if (!$wantsId
                && DomainPack::isIdDimension($dimension)
                && $this->pack->displaySibling((string) $plan['dataset'], $name) !== null
            ) {
                continue;
            }
            $phrases = array_merge(
                [$name, (string) ($dimension['label'] ?? '')],
                array_map('strval', (array) ($dimension['aliases'] ?? []))
            );
            $hit = $this->phraseHit($words, $phrases);
            if ($hit > 0) {
                $named[] = [$hit, $name];
            }
        }
        usort($named, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($named as [, $name]) {
            if (count($chosen) >= 10) {
                break;
            }
            $chosen[] = (string) $name;
        }
        // "Show all payments collected" asks for money without naming the
        // column; a payment list without the amount is not an answer.
        if (preg_match('/\b(amount|money|paid|collected|revenue|price|fee|cost|balance|value|spend|ksh|shillings?)\b/i', $question) === 1) {
            $money = [];
            foreach ((array) ($dataset['dimensions'] ?? []) as $dimension) {
                if (!is_array($dimension)) {
                    continue;
                }
                $name = (string) ($dimension['name'] ?? '');
                if ($name === '' || in_array($name, $chosen, true)) {
                    continue;
                }
                if ((string) ($dimension['format'] ?? '') === 'money') {
                    $money[] = $name;
                }
            }
            foreach (array_slice($money, 0, 2) as $name) {
                if (count($chosen) >= 10) {
                    break;
                }
                $chosen[] = (string) $name;
            }
        }
        // "Include contact info" asks for the columns that carry contact
        // details — the phone and the address a record keeps for its person.
        if (preg_match('/\bcontacts?\b/i', $question) === 1) {
            $contacts = 0;
            foreach ((array) ($dataset['dimensions'] ?? []) as $dimension) {
                if ($contacts >= 4) {
                    break;
                }
                if (!is_array($dimension)) {
                    continue;
                }
                $name = (string) ($dimension['name'] ?? '');
                if ($name === '' || in_array($name, $chosen, true)) {
                    continue;
                }
                if (self::isContactColumn($dimension)) {
                    $chosen[] = $name;
                    $contacts++;
                }
            }
        }
        if ($chosen !== []) {
            $plan['dimensions'] = array_values(array_unique($chosen));
        }
        // The measures the question names. The router's own choice is kept and
        // a measure it missed is added: "users with their sales and quiet days"
        // asks for numbers per user, and half of them is no answer.
        $metrics = array_map('strval', (array) ($plan['metrics'] ?? []));
        $covered = $this->metricPhrases($dataset, $metrics);
        foreach ($this->namedMetricHits($dataset, $words, $chosen) as $hit) {
            if (count($metrics) >= 4) {
                break;
            }
            if (in_array($hit['name'], $metrics, true)) {
                continue;
            }
            if ($hit['phrase'] !== '' && in_array($hit['phrase'], $covered, true)) {
                continue;
            }
            $metrics[] = $hit['name'];
            $covered[] = $hit['phrase'];
        }
        if ($metrics !== []) {
            $plan['metrics'] = array_values(array_unique($metrics));
        }
        if ($missing !== []) {
            $plan['missing_fields'] = array_values(array_unique(array_merge(
                array_map('strval', (array) ($plan['missing_fields'] ?? [])),
                $missing
            )));
        }

        return $plan;
    }

    /**
     * The words a set of measures answers to.
     *
     * @param array<string,mixed> $dataset
     * @param string[] $names
     * @return string[]
     */
    private function metricPhrases(array $dataset, array $names): array
    {
        $phrases = [];
        foreach ($names as $name) {
            $metric = $this->pack?->metric((string) ($dataset['name'] ?? ''), $name);
            if ($metric === null) {
                continue;
            }
            foreach (array_merge(
                [(string) ($metric['name'] ?? ''), (string) ($metric['label'] ?? '')],
                array_map('strval', (array) ($metric['aliases'] ?? []))
            ) as $phrase) {
                $needle = $this->normalisePhrase($phrase);
                if ($needle !== '') {
                    $phrases[] = $needle;
                }
            }
        }

        return array_values(array_unique($phrases));
    }

    /**
     * The fields the question names that this dataset does not have.
     *
     * "client, amount paid, paid for, balance" against project payments names
     * balance — which the CRM keeps on its dues, not on a receipt. The answer
     * has to say so instead of dropping the word without a word.
     *
     * @param array<string,mixed> $dataset
     * @return string[]
     */
    private function missingFieldsFor(array $dataset, string $words): array
    {
        if ($this->pack === null) {
            return [];
        }
        $here = $this->fieldKeys($dataset);
        // The dataset's own name counts as covered: "Payments include…" names
        // the table itself, not a field of it.
        foreach (array_merge(
            [(string) ($dataset['name'] ?? ''), (string) ($dataset['label'] ?? '')],
            array_map('strval', (array) ($dataset['aliases'] ?? []))
        ) as $name) {
            $key = DomainPack::key($name);
            if ($key === '') {
                continue;
            }
            $here[$key] = true;
            $tail = (string) preg_replace('/^.*[\s_]/', '', $name);
            if ($tail !== $name && DomainPack::key($tail) !== '') {
                $here[DomainPack::key($tail)] = true;
            }
        }

        $missing = [];
        foreach ($this->pack->listDatasets() as $entry) {
            if ((string) ($entry['name'] ?? '') === (string) ($dataset['name'] ?? '')) {
                continue;
            }
            foreach (['dimensions', 'metrics'] as $group) {
                foreach ((array) ($entry[$group] ?? []) as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    // The field's own name is too generic to go by ("state",
                    // "type"); its label and aliases say what a person calls it.
                    $phrases = array_merge(
                        [(string) ($field['label'] ?? '')],
                        array_map('strval', (array) ($field['aliases'] ?? []))
                    );
                    $phrase = $this->matchedPhrase($words, $phrases);
                    // A short word is a preposition more often than a field.
                    if (strlen($phrase) < 5) {
                        continue;
                    }
                    $phraseKey = DomainPack::key($phrase);
                    if ($phraseKey === '' || isset($here[$phraseKey])) {
                        continue;
                    }
                    $missing[] = $phrase;
                    $here[$phraseKey] = true;
                }
            }
        }

        return array_slice(array_values(array_unique($missing)), 0, 3);
    }

    /**
     * Every word a dataset's own fields answer to.
     *
     * @param array<string,mixed> $dataset
     * @return array<string,true>
     */
    private function fieldKeys(array $dataset): array
    {
        $keys = [];
        foreach (['dimensions', 'metrics'] as $group) {
            foreach ((array) ($dataset[$group] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                foreach (array_merge(
                    [(string) ($field['name'] ?? ''), (string) ($field['label'] ?? '')],
                    array_map('strval', (array) ($field['aliases'] ?? []))
                ) as $label) {
                    $key = DomainPack::key($label);
                    if ($key !== '') {
                        $keys[$key] = true;
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * The measures the question names, best match first.
     *
     * A metric that only counts the rows of the dataset itself is not a measure
     * the user asked for: "10 best leads" says nothing about how many leads
     * there are, and counting them hides the records themselves.
     *
     * @param array<string,mixed> $dataset
     * @param string[] $chosen the columns the answer will show
     * @return string[]
     */
    private function namedMetrics(array $dataset, string $words, array $chosen): array
    {
        return array_map(
            static fn (array $hit): string => (string) $hit['name'],
            $this->namedMetricHits($dataset, $words, $chosen)
        );
    }

    /**
     * The measures the question names, with the words that named them.
     *
     * @param array<string,mixed> $dataset
     * @param string[] $chosen the columns the answer will show
     * @return array<int,array{name:string,hit:int,phrase:string}>
     */
    private function namedMetricHits(array $dataset, string $words, array $chosen): array
    {
        $table = strtolower(trim((string) ($dataset['table'] ?? '')));
        $hits = [];
        foreach ((array) ($dataset['metrics'] ?? []) as $metric) {
            if (!is_array($metric)) {
                continue;
            }
            $name = (string) ($metric['name'] ?? '');
            if ($name === '' || $this->countsOwnRows($metric, $table)) {
                continue;
            }
            $phrases = array_merge(
                [$name, (string) ($metric['label'] ?? '')],
                array_map('strval', (array) ($metric['aliases'] ?? []))
            );
            $hit = 0;
            $phrase = '';
            foreach ($phrases as $candidate) {
                $length = $this->phraseHit($words, [$candidate]);
                if ($length <= $hit) {
                    continue;
                }
                // The same words already name a column: that column is what the
                // user asked for, not a total over it.
                if ($this->coversColumn($this->normalisePhrase((string) $candidate), $dataset, $chosen)) {
                    continue;
                }
                $hit = $length;
                $phrase = $this->normalisePhrase((string) $candidate);
            }
            if ($hit > 0) {
                $hits[] = ['name' => $name, 'hit' => $hit, 'phrase' => $phrase];
            }
        }
        usort($hits, static fn (array $a, array $b): int => $b['hit'] <=> $a['hit']);
        $out = [];
        foreach ($hits as $hit) {
            // Two measures that answer to the same words are one measure.
            $same = false;
            foreach ($out as $picked) {
                if ($hit['phrase'] === '' || $picked['phrase'] === '') {
                    continue;
                }
                if ($hit['phrase'] === $picked['phrase'] || str_contains($picked['phrase'], $hit['phrase'])) {
                    $same = true;
                    break;
                }
            }
            if ($same) {
                continue;
            }
            $out[] = $hit;
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }

    /**
     * True when a phrase the question uses is one of the columns it names.
     *
     * @param array<string,mixed> $dataset
     * @param string[] $chosen
     */
    private function coversColumn(string $phrase, array $dataset, array $chosen): bool
    {
        foreach ($chosen as $name) {
            $dimension = $this->pack?->dimension((string) ($dataset['name'] ?? ''), (string) $name);
            if ($dimension === null) {
                continue;
            }
            $phrases = array_merge(
                [(string) ($dimension['name'] ?? ''), (string) ($dimension['label'] ?? '')],
                array_map('strval', (array) ($dimension['aliases'] ?? []))
            );
            foreach ($phrases as $candidate) {
                $needle = $this->normalisePhrase((string) $candidate);
                if ($needle === '' || $phrase === '') {
                    continue;
                }
                if ($needle === $phrase || str_contains($needle, $phrase) || str_contains($phrase, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * True when a metric counts the rows of the dataset itself.
     *
     * @param array<string,mixed> $metric
     */
    private function countsOwnRows(array $metric, string $table): bool
    {
        $function = strtolower(trim((string) ($metric['function'] ?? '')));
        if (!in_array($function, ['count', 'count_distinct'], true)) {
            return false;
        }
        $column = strtolower(trim((string) ($metric['column'] ?? '')));
        if ($column === '' || $column === '*' || !str_contains($column, '.')) {
            return true;
        }
        $prefix = strtolower((string) explode('.', $column)[0]);

        return $table === '' || $prefix === $table;
    }

    /**
     * A column that carries how to reach a person: a phone number, an email
     * address, a postal address or a messenger handle.
     *
     * @param array<string,mixed> $dimension
     */
    private static function isContactColumn(array $dimension): bool
    {
        $words = strtolower(trim((string) ($dimension['name'] ?? '')) . ' '
            . strtolower((string) ($dimension['label'] ?? '')) . ' '
            . strtolower(implode(' ', array_map('strval', (array) ($dimension['aliases'] ?? [])))));

        foreach (['email', 'mail', 'phone', 'mobile', 'tel', 'msisdn', 'whatsapp', 'contact', 'address', 'telephone'] as $word) {
            if (str_contains($words, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "All users in this system?" has no period, but models copy the prompt's
     * date_range example and invent "last 30 days", which then hides every row.
     * A range is only honoured when the question itself names a period.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function withPeriodGuard(array $plan, string $question): array
    {
        if (!isset($plan['date_range']) || $this->questionNamesPeriod($question)) {
            return $plan;
        }
        // A continued answer keeps the period of the answer it continues.
        if ((bool) ($plan['continued'] ?? false)) {
            return $plan;
        }
        unset($plan['date_range']);

        return $plan;
    }

    /** Relative presets, explicit dates, years and month names. */
    private function questionNamesPeriod(string $question): bool
    {
        if (DateRange::presetFromText($question) !== '') {
            return true;
        }

        return preg_match(
            '/\b(\d{4}-\d{2}(?:-\d{2})?|(?:19|20)\d{2}|jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?|since|until|till|before|after|between|as of|ytd|mtd)\b/i',
            $question
        ) === 1;
    }

    /**
     * Models keep treating the dataset's date column as a dimension or a filter
     * ("Unknown field post_date"). The date column belongs to date_range only,
     * so a filter on it becomes a range, and a date column named as a dimension
     * or sort key is dropped (the compiler orders by it automatically).
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function withDateFieldFixes(array $plan): array
    {
        if ($this->pack === null || !isset($plan['dataset']) || !is_string($plan['dataset'])) {
            return $plan;
        }
        $dataset = $this->pack->dataset($plan['dataset']);
        if ($dataset === null) {
            return $plan;
        }
        $dateField = strtolower(trim((string) ($dataset['date_field'] ?? '')));
        if ($dateField === '') {
            return $plan;
        }
        $table = strtolower(trim((string) ($dataset['table'] ?? '')));
        $isDateField = static function (mixed $field) use ($dateField, $table): bool {
            $key = strtolower(trim((string) $field));
            if ($key === '') {
                return false;
            }
            $short = str_contains($key, '.') ? substr($key, (int) strrpos($key, '.') + 1) : $key;
            return $short === $dateField || ($table !== '' && $key === $table . '.' . $dateField);
        };

        $from = '';
        $to = '';
        $filters = [];
        foreach ((array) ($plan['filters'] ?? []) as $filter) {
            if (!is_array($filter) || !$isDateField($filter['field'] ?? '')) {
                $filters[] = $filter;
                continue;
            }
            $operator = strtolower(trim((string) ($filter['operator'] ?? '=')));
            $value = $filter['value'] ?? null;
            if ($operator === 'between') {
                $pair = array_values((array) $value);
                [$from, $to] = [self::dateBound((string) ($pair[0] ?? ''), true), self::dateBound((string) ($pair[1] ?? ''), false)];
                continue;
            }
            if (in_array($operator, ['>=', '>'], true)) {
                $from = self::dateBound((string) $value, true);
                continue;
            }
            if (in_array($operator, ['<=', '<'], true)) {
                $to = self::dateBound((string) $value, false);
                continue;
            }
            [$open, $close] = [self::dateBound((string) $value, true), self::dateBound((string) $value, false)];
            if ($open !== '') {
                $from = $open;
                $to = $close;
            }
        }
        $plan['filters'] = $filters;
        if ($from !== '' || $to !== '') {
            $plan['date_range'] = array_filter(['from' => $from, 'to' => $to]);
        }
        // A dimension the pack declares for the date column is deliberate (a
        // payments list must show when each payment happened), so it stays.
        foreach (['dimensions', 'metrics'] as $key) {
            if (isset($plan[$key]) && is_array($plan[$key])) {
                $plan[$key] = array_values(array_filter(
                    $plan[$key],
                    fn ($field): bool => !$isDateField($field)
                        || ($key === 'dimensions' && $this->pack?->dimension((string) $plan['dataset'], (string) $field) !== null)
                ));
            }
        }
        if (isset($plan['order_by']) && is_array($plan['order_by'])) {
            $plan['order_by'] = array_values(array_filter(
                $plan['order_by'],
                static fn ($clause): bool => !is_array($clause) || !$isDateField($clause['field'] ?? '')
            ));
        }

        return $plan;
    }

    /** One ISO bound ("2026-08" covers the whole month) for a date field filter. */
    private static function dateBound(string $value, bool $start): string
    {
        $text = trim($value);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $text) === 1) {
            return $start ? $text . '-01' : (new \DateTimeImmutable($text . '-01'))->format('Y-m-t');
        }
        if (preg_match('/^(\d{4})$/', $text) === 1) {
            return $start ? $text . '-01-01' : $text . '-12-31';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $text;
        }
        try {
            $date = new \DateTimeImmutable($text);
            $hasDay = preg_match('/(?<!\d)\d{1,2}(?!\d)/', $text) === 1;
            if (!$hasDay) {
                return $start ? $date->format('Y-m-01') : $date->format('Y-m-t');
            }
            return $date->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * A record list, not a ranking.
     *
     * "10 best leads and their contact details" asks for ten records. A plan
     * that groups by source and counts them answers a different question, so
     * the grouping is dropped and the fields the user named are kept.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function withRecordList(array $plan, string $question): array
    {
        if ($this->pack === null || !isset($plan['dataset']) || !is_string($plan['dataset'])) {
            return $plan;
        }
        $dataset = $this->pack->dataset($plan['dataset']);
        if ($dataset === null) {
            return $plan;
        }
        $words = $this->questionWords($question);
        // "leads by source" is a breakdown, not a list of leads.
        if (preg_match('/\b(by|per|each|grouped|breakdown|broken|split|versus|vs|compared?)\b/', $words) === 1) {
            return $plan;
        }
        $cue = preg_match('/\b(list|show|display|give|fetch|bring)\b/', $words) === 1
            || preg_match('/\b(first|last|top|bottom|best|worst|newest|latest|recent)\s+\d{1,4}\b/', $words) === 1
            || preg_match('/\b\d{1,4}\s+(best|worst|latest|newest|recent|top|first|last)\b/', $words) === 1;
        if (!$cue) {
            return $plan;
        }
        // A question that names a measure asks for a number, not for records.
        if ($this->namedMetrics($dataset, $words, []) !== []) {
            return $plan;
        }
        $table = strtolower(trim((string) ($dataset['table'] ?? '')));
        foreach ((array) ($plan['metrics'] ?? []) as $metric) {
            $entry = $this->pack->metric((string) $plan['dataset'], (string) $metric);
            if ($entry === null || !$this->countsOwnRows($entry, $table)) {
                return $plan;
            }
        }
        unset($plan['metrics']);
        $dimensions = array_values(array_map('strval', (array) ($plan['dimensions'] ?? [])));
        $identity = $this->identityDimension($dataset);
        if ($identity !== '' && !in_array($identity, $dimensions, true)) {
            array_unshift($dimensions, $identity);
        }
        if ($dimensions !== []) {
            $plan['dimensions'] = $dimensions;
        }
        $limit = $this->recordListLimit($words);
        if ($limit > 0) {
            $plan['limit'] = $limit;
        }

        return $plan;
    }

    /**
     * How many records the question asks for.
     *
     * "10 best leads" and "the first 10 leads" both ask for ten.
     */
    private function recordListLimit(string $words): int
    {
        if (preg_match('/\b(\d{1,4})\s+(best|worst|latest|newest|recent|top|first|last)\b/', $words, $m) === 1) {
            return max(1, (int) $m[1]);
        }
        if (preg_match('/\b(first|last|top|bottom|best|worst|newest|latest|recent)\s+(\d{1,4})\b/', $words, $m) === 1) {
            return max(1, (int) $m[2]);
        }

        return 0;
    }

    /**
     * Fields the pack does not have.
     *
     * A question that names a column the dataset does not have must not take
     * the whole answer down: the compiler refuses an unknown field and the
     * user gets nothing at all. The field is dropped and remembered, so the
     * answer can say where that figure lives instead.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function withKnownFields(array $plan): array
    {
        if ($this->pack === null || !isset($plan['dataset']) || !is_string($plan['dataset'])) {
            return $plan;
        }
        $dataset = (string) $plan['dataset'];
        $missing = array_map('strval', (array) ($plan['missing_fields'] ?? []));
        // A dimension the dataset does not have is dropped: the rest of the
        // columns are still worth showing. A measure is not — replacing it
        // would answer with a number the user never asked for, so the
        // compiler keeps refusing it.
        $keep = [];
        foreach ((array) ($plan['dimensions'] ?? []) as $field) {
            $name = (string) $field;
            if ($name === '') {
                continue;
            }
            if ($this->pack->dimension($dataset, $name) !== null) {
                $keep[] = $name;
                continue;
            }
            // A measure named as a column is a column the table does not have.
            // The compiler ignores it, so it is dropped here rather than
            // carried into the answer as a column that never appears.
            if ($this->pack->metric($dataset, $name) === null && !in_array($name, $missing, true)) {
                $missing[] = $name;
            }
        }
        if ($keep !== []) {
            $plan['dimensions'] = $keep;
        } else {
            unset($plan['dimensions']);
        }
        $filters = [];
        foreach ((array) ($plan['filters'] ?? []) as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $field = (string) ($filter['field'] ?? '');
            if ($field === '' || $this->pack->dimension($dataset, $field) !== null) {
                $filters[] = $filter;
                continue;
            }
            if (!in_array($field, $missing, true)) {
                $missing[] = $field;
            }
        }
        $plan['filters'] = $filters;
        if ($missing !== []) {
            $plan['missing_fields'] = $missing;
        }

        return $plan;
    }

    /**
     * "All listings" names a dataset but no measure or field. A bare dataset
     * request answers with rows (the record title first); when the question
     * asks for a number, it answers with the dataset's count metric instead of
     * failing the compiler's "selected nothing" check.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function withDefaultShape(array $plan, string $question): array
    {
        if ($this->pack === null || !isset($plan['dataset']) || !is_string($plan['dataset'])) {
            return $plan;
        }
        $metrics = (array) ($plan['metrics'] ?? []);
        $dimensions = (array) ($plan['dimensions'] ?? []);
        $dataset = $this->pack->dataset($plan['dataset']);
        if ($dataset === null) {
            return $plan;
        }
        $names = $this->pack->dimensionNames((string) $plan['dataset']);
        $identity = '';
        foreach (['name', 'title', 'unit', 'serial'] as $candidate) {
            if (in_array($candidate, $names, true)) {
                $identity = $candidate;
                break;
            }
        }
        $listQuestion = preg_match('/\b(all|list|show|display)\b/i', $question) === 1
            && preg_match('/\b(how many|how much|counts?|totals?|number of|averages?|sums?|revenues?|trends?|conversions?|top|best|highest|lowest|worst|least|bottom)\b/i', $question) === 0
            && preg_match('/\bby\b/i', $question) === 0;

        // "All users" and "show clients" are record lists: a count per category
        // hides the very thing the user asked for. Prefer the identity dimension.
        if ($listQuestion && $identity !== '') {
            $dimensions = array_values(array_filter(
                array_map('strval', $dimensions),
                static fn (string $dimension): bool => $dimension !== $identity
            ));
            array_unshift($dimensions, $identity);
            $plan['dimensions'] = $dimensions;
            unset($plan['metrics']);
            if (!isset($plan['limit']) || (int) $plan['limit'] <= 0) {
                $plan['limit'] = 100;
            }

            return $plan;
        }

        if ($metrics !== []) {
            // "How many customers?" is one number; a grouped count answers a
            // different question than the one asked. "Which officer has the
            // most booked leads?" is a ranking: the grouping column is the
            // answer, so it stays.
            if ($dimensions !== []
                && preg_match('/\b(how many|number of|counts?)\b/i', $question) === 1
                && preg_match('/\b(per|by|each|across)\b/i', $question) === 0
                && preg_match('/\b(which|who|whose)\b/i', $question) === 0
                && preg_match('/\b(highest|lowest|best|worst|top|most|least|biggest|smallest|greatest)\b/i', $question) === 0
            ) {
                $plan['dimensions'] = [];
                unset($plan['limit'], $plan['order_by']);
            }

            return $plan;
        }

        // A dataset named with categories still deserves record identities.
        if ($dimensions !== []) {
            if (count($dimensions) < 6
                && preg_match('/\ball\b/i', $question) === 1
                && $identity !== ''
                && !in_array($identity, array_map('strval', $dimensions), true)) {
                array_unshift($dimensions, $identity);
                $plan['dimensions'] = $dimensions;
            }

            return $plan;
        }

        $wantsNumber = preg_match('/\b(how many|how much|counts?|totals?|number of|averages?|sums?|revenues?|trends?|conversions?)\b/i', $question) === 1;
        if ($wantsNumber) {
            foreach ((array) ($dataset['metrics'] ?? []) as $metric) {
                if (($metric['function'] ?? '') === 'count') {
                    $plan['metrics'] = [(string) ($metric['name'] ?? '')];
                    return $plan;
                }
            }
            $first = (string) ($dataset['metrics'][0]['name'] ?? '');
            if ($first !== '') {
                $plan['metrics'] = [$first];
            }
            return $plan;
        }

        $preferred = ['name', 'title', 'unit', 'serial', 'client', 'project', 'month'];
        foreach ((array) ($dataset['dimensions'] ?? []) as $dimension) {
            if (in_array((string) ($dimension['name'] ?? ''), $preferred, true)) {
                $plan['dimensions'] = [(string) $dimension['name']];
                return $plan;
            }
        }
        foreach ((array) ($dataset['dimensions'] ?? []) as $dimension) {
            if ((string) ($dimension['format'] ?? '') !== 'date') {
                $plan['dimensions'] = [(string) ($dimension['name'] ?? '')];
                break;
            }
        }

        return $plan;
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
        $messages = $this->convo($this->routerPrompt($request), $request->question, []);
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
            $candidates = array_merge(
                [$name, (string) ($def['label'] ?? ''), (string) ($def['table'] ?? ''), rtrim($name, 's')],
                array_map('strval', (array) ($def['aliases'] ?? []))
            );
            foreach ($candidates as $alias) {
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
            $chunks = $this->knowledge->search($request->user->tenantId, $request->question, $this->knowledgeTopK);
        } catch (\Throwable) {
            return [];
        }
        // The CRM's own queries are guidance for the router, not answers: a
        // grounded answer must come from a fact a person wrote.
        if (!is_array($chunks)) {
            return $chunks;
        }
        $out = [];
        $verified = [];
        $drafts = [];
        foreach ($chunks as $chunk) {
            if (is_array($chunk) && (string) ($chunk['source'] ?? '') === 'code') {
                continue;
            }
            $state = is_array($chunk)
                ? (string) ((array) ($chunk['metadata'] ?? []))['verification'] ?? ''
                : '';
            if ($state === RuleService::VERIFICATION_VERIFIED) {
                $verified[] = $chunk;
                continue;
            }
            if ($state !== '') {
                $drafts[] = $chunk;
                continue;
            }
            $out[] = $chunk;
        }

        // A verified rule leads: it is the business's own statement of how the
        // question is answered. A draft rule only speaks when nothing else does.
        if ($verified !== []) {
            return array_merge($verified, $out);
        }

        return $out !== [] ? $out : $drafts;
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
            return 'Reply in the language the user writes in — an English question gets an English answer.';
        }

        return Config::bool('domain.multilingual', true)
            ? 'Reply in ' . $language . " unless the question is written in another language, then use that one. An English question gets an English answer."
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
        // A single aggregate has exactly one right sentence, and a model that
        // restates the number can get it wrong. Say it from the row instead.
        $single = self::singleAggregateSentence($result, $compiled, $this->pack);
        if ($single !== '') {
            return $single;
        }

        $period = (string) ($compiled['applied_filters']['period'] ?? '');
        $dataset = (string) ($compiled['dataset'] ?? '');
        $missing = $this->missingFields($compiled);
        $currency = trim(Config::string('domain.currency', ''));
        $money = $currency !== ''
            ? ' Write money with thousands separators and the currency ' . $currency . ' (for example "'
                . $currency . ' 1,250,000"), and never add a different currency or unit.'
            : ' Write money exactly as the values give it, with thousands separators, and never add a currency'
                . ' symbol or a unit the table does not contain.';
        $messages = $this->convo(
            $this->groundedPrompt('the query result', $result->asPromptText(60, $this->columnLabels($dataset), (array) ($compiled['formats'] ?? [])), $request)
            . "\nThe table below is the complete answer and is already shown to the user: summarise it in one or two sentences"
            . ($period !== '' ? ', naming the period (' . $period . ')' : '')
            . '. Use the column labels.' . $money
            . ' Keep every digit.'
            . ' Never say the information is missing,'
            . ' incomplete or unavailable, and never apologise, when the table has rows.'
            . ' Never claim that every row shares a value ("all dated 25-08-2026") unless it is true; name a few rows instead.'
            . ($missing === [] ? '' : ' This table has no column for: ' . implode(', ', $missing) . '. Never say that figure is in the table.'),
            $request->question,
            []
        );
        $reply = trim((string) ($this->chat($messages)['content'] ?? ''));
        $note = $this->missingFieldNote($compiled, $request->question);
        if ($reply !== '' && self::numbersAreSourced($reply, $result, $compiled)) {
            return $note === '' ? $reply : $reply . "\n\n" . $note;
        }

        $label = '';
        $entry = $dataset !== '' ? $this->pack?->dataset($dataset) : null;
        if ($entry !== null) {
            $label = trim((string) ($entry['label'] ?? '')) !== '' ? (string) $entry['label'] : $dataset;
        } elseif ($dataset !== '') {
            $label = $dataset;
        }

        return 'Showing ' . $result->count() . ($label !== '' ? ' ' . strtolower($label) : ' records')
            . ($period !== '' ? ' (' . $period . ')' : '') . '.'
            . ($note === '' ? '' : "\n\n" . $note);
    }

    /**
     * The fields the question asked for that this table does not have.
     *
     * @param array<string,mixed> $compiled
     * @return string[]
     */
    private function missingFields(array $compiled): array
    {
        $missing = [];
        foreach ((array) ($compiled['plan']['missing_fields'] ?? []) as $field) {
            $name = trim((string) $field);
            if ($name !== '' && !in_array($name, $missing, true)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * Where a field the question asked for actually lives.
     *
     * @param array<string,mixed> $compiled
     */
    private function missingFieldNote(array $compiled, string $question): string
    {
        $missing = $this->missingFields($compiled);
        if ($missing === []) {
            return '';
        }
        $dataset = (string) ($compiled['dataset'] ?? '');
        $parts = [];
        foreach ($missing as $field) {
            $home = $this->fieldHome($field, $question, $dataset);
            $parts[] = $home === '' ? $field : $field . ' (' . $home . ')';
        }

        return 'The table has no column for: ' . implode(', ', $parts) . '.';
    }

    /**
     * The dataset that keeps a field, when the answer's own dataset does not.
     *
     * @param string $dataset the dataset the answer came from
     */
    private function fieldHome(string $name, string $question, string $dataset): string
    {
        if ($this->pack === null) {
            return '';
        }
        $wanted = DomainPack::key($name);
        $words = $this->questionWords($question);
        $candidates = [];
        foreach ($this->pack->listDatasets() as $entry) {
            if ((string) ($entry['name'] ?? '') === $dataset) {
                continue;
            }
            foreach (['dimensions', 'metrics'] as $key) {
                foreach ((array) ($entry[$key] ?? []) as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $labels = array_merge(
                        [(string) ($field['name'] ?? ''), (string) ($field['label'] ?? '')],
                        array_map('strval', (array) ($field['aliases'] ?? []))
                    );
                    $match = false;
                    foreach ($labels as $label) {
                        if ($label !== '' && DomainPack::key($label) === $wanted) {
                            $match = true;
                            break;
                        }
                    }
                    if (!$match) {
                        continue;
                    }
                    $datasetLabel = trim((string) ($entry['label'] ?? '')) ?: (string) ($entry['name'] ?? '');
                    $fieldLabel = trim((string) ($field['label'] ?? '')) ?: (string) ($field['name'] ?? '');
                    // The wording the question used picks the right one: "the
                    // client" is a client name, not a lead name.
                    $score = 0;
                    foreach (preg_split('/\W+/', strtolower($fieldLabel . ' ' . $datasetLabel)) ?: [] as $word) {
                        if (strlen($word) >= 4 && str_contains($words, ' ' . $word . ' ')) {
                            $score++;
                        }
                    }
                    $candidates[] = ['score' => $score, 'home' => 'kept in ' . $datasetLabel . ' as ' . $fieldLabel];
                }
            }
        }
        if ($candidates === []) {
            return '';
        }
        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return (string) $candidates[0]['home'];
    }

    /**
     * Readable names for the columns of a result, so a summary says "credited
     * amount" instead of the SQL alias "credited_amount".
     *
     * @return array<string,string>
     */
    private function columnLabels(string $dataset): array
    {
        if ($this->pack === null || $dataset === '') {
            return [];
        }
        $entry = $this->pack->dataset($dataset);
        if ($entry === null) {
            return [];
        }
        $labels = [];
        foreach (['dimensions', 'metrics'] as $key) {
            foreach ((array) ($entry[$key] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = strtolower(trim((string) ($item['name'] ?? '')));
                $label = trim((string) ($item['label'] ?? ''));
                if ($name !== '' && $label !== '') {
                    $labels[$name] = $label;
                }
            }
        }

        return $labels;
    }

    /**
     * Every figure in a written summary must already be in the table it
     * describes; a number the model produced on its own is a reason to drop the
     * prose and let the rows speak.
     *
     * @param array<string,mixed> $compiled
     */
    private static function numbersAreSourced(string $reply, QueryResult $result, array $compiled): bool
    {
        if (preg_match_all('/\d[\d,]*(?:\.\d+)?/', $reply, $matches) === 0) {
            return true;
        }
        $haystack = $result->asPromptText(200);
        foreach ((array) ($compiled['applied_filters'] ?? []) as $value) {
            if (is_scalar($value)) {
                $haystack .= ' ' . (string) $value;
            }
        }
        $digits = (string) preg_replace('/[^0-9]/', '', $haystack);
        foreach ($matches[0] as $token) {
            $number = (string) preg_replace('/[^0-9]/', '', (string) $token);
            // One- and two-digit numbers are prose ("3 of these", a share),
            // not a restatement of a figure.
            if ($number === '' || strlen($number) <= 2 || str_contains($digits, $number)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * "Total collections this year" is one row of one number: naming it is not a
     * writing task. The sentence is built from the plan's own labels, so the
     * figure in the words is always the figure in the table.
     *
     * @param array<string,mixed> $compiled
     */
    private static function singleAggregateSentence(QueryResult $result, array $compiled, ?DomainPack $pack): string
    {
        if (($compiled['aggregate'] ?? false) !== true || count($result->rows) !== 1) {
            return '';
        }
        $plan = (array) ($compiled['plan'] ?? []);
        if ((array) ($plan['dimensions'] ?? []) !== []) {
            return '';
        }
        $dataset = (string) ($compiled['dataset'] ?? '');
        $period = (string) ($compiled['applied_filters']['period'] ?? '');
        $entry = $dataset !== '' ? $pack?->dataset($dataset) : null;
        $datasetLabel = $entry !== null ? trim((string) ($entry['label'] ?? '')) : '';
        $single = count((array) $result->rows[0]) === 1;
        $parts = [];
        foreach ((array) $result->rows[0] as $column => $value) {
            if ($value === null) {
                continue;
            }
            $metric = $pack?->metric($dataset, (string) $column);
            $label = trim((string) ($metric['label'] ?? ''));
            if ($single && ($metric['function'] ?? '') === 'count' && $datasetLabel !== '') {
                $label = $datasetLabel;
            }
            $parts[] = ($label !== '' ? $label : (string) $column) . ': '
                . self::displayNumber($value, (string) ($metric['format'] ?? ''))
                . ($period !== '' ? ' (' . $period . ')' : '');
        }

        return $parts === [] ? '' : implode('; ', $parts) . '.';
    }

    private static function displayNumber(mixed $value, string $format): string
    {
        if (!is_numeric($value)) {
            return is_scalar($value) ? (string) $value : (string) json_encode($value);
        }
        $number = (float) $value;
        $text = number_format($number, $format === 'money' && floor($number) !== $number ? 2 : 0);
        $currency = trim(Config::string('domain.currency', ''));

        return $format === 'money' && $currency !== '' ? $currency . ' ' . $text : $text;
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
     * When a period returns nothing, the useful fact is usually that the data
     * ends earlier. One extra grouped query (only on empty answers) finds the
     * latest month that does have records, so a zero is not a dead end.
     *
     * @param array<string,mixed> $compiled
     * @return array{dataset?:string,month?:string,metric?:string,value?:string,empty?:bool}|array{}
     */
    private function latestPeriod(array $compiled, UserContext $user): array
    {
        if ($this->compiler === null || !isset($this->hooks['rows']) || $this->pack === null) {
            return [];
        }
        $datasetName = (string) ($compiled['dataset'] ?? '');
        $dataset = $datasetName !== '' ? $this->pack->dataset($datasetName) : null;
        if ($dataset === null || $this->pack->dimension($datasetName, 'month') === null) {
            return [];
        }
        $metric = null;
        foreach ((array) ($dataset['metrics'] ?? []) as $candidate) {
            if (($candidate['function'] ?? '') === 'count') {
                $metric = (string) ($candidate['name'] ?? '');
                break;
            }
        }
        if ($metric === null || $metric === '') {
            return [];
        }

        try {
            $probe = QueryPlan::fromArray([
                'dataset' => $datasetName,
                'metrics' => [$metric],
                'dimensions' => ['month'],
                'order_by' => [['field' => 'month', 'direction' => 'desc']],
                'limit' => 1,
            ]);
            $probeCompiled = $this->compiler->compile($probe, $this->scopeFor($probe, $user));
            $probeRows = $this->rowsFromHook($probeCompiled, $user)->rows;
        } catch (\Throwable) {
            return [];
        }
        $row = is_array($probeRows[0] ?? null) ? array_values($probeRows[0]) : [];
        $month = (string) ($row[0] ?? '');
        if ($month === '') {
            // The probe ran and found no month at all: the dataset is empty.
            return ['empty' => true, 'dataset' => (string) ($dataset['label'] ?? $datasetName)];
        }
        $value = $row[1] ?? null;

        try {
            $month = (new \DateTimeImmutable($month . '-01'))->format('F Y');
        } catch (\Throwable) {
            // Keep the stored value when it is not a YYYY-MM string.
        }

        $metricDef = $this->pack->metric($datasetName, $metric);

        return [
            'dataset' => (string) ($dataset['label'] ?? $datasetName),
            'month' => $month,
            'metric' => (string) (($metricDef['label'] ?? '') !== '' ? $metricDef['label'] : $metric),
            'value' => is_numeric($value) ? number_format((float) $value) : (string) $value,
        ];
    }

    /**
     * "Nothing matched" is an answer, and it must not be dressed up as a number.
     * A COUNT/SUM that legitimately returned zero keeps its row as the evidence
     * the query really ran; a result set with no rows has nothing to show.
     *
     * @param array<string,mixed> $appliedFilters
     * @param array<string,mixed> $compiled
     * @param array<string,mixed> $latest
     */
    private function emptyDataAnswer(
        AskRequest $request,
        array $appliedFilters,
        ?QueryResult $result = null,
        array $compiled = [],
        array $latest = []
    ): Answer {
        $scope = self::filterSentence($appliedFilters);
        $hasZeroRow = $result !== null && !$result->isEmpty() && self::hasAnyNumber((array) $result->rows[0]);
        $hint = '';
        $hasPeriod = trim((string) ($appliedFilters['period'] ?? '')) !== '';
        if (!empty($latest['empty'])) {
            $hint = ' Nothing has been recorded in ' . strtolower((string) $latest['dataset']) . ' yet.';
        } elseif ($hasPeriod && $latest !== []) {
            // "The data ends in March" only helps when a period was asked for.
            $where = $this->placeName($request);
            $hint = ' The most recent ' . strtolower((string) $latest['dataset'])
                . ($where !== '' ? ' in ' . $where : '')
                . ' are from ' . $latest['month']
                . ' (' . $latest['metric'] . ': ' . $latest['value'] . ')'
                . ', so this period sits after the latest records.';
        }

        return new Answer(
            text: 'No matching records' . ($scope !== '' ? ' ' . $scope : '') . '.' . $hint
                . (empty($latest['empty']) ? ' Try a wider period or a different filter.' : ''),
            intent: 'data',
            rows: $hasZeroRow ? $result->rows : [],
            citations: $hasZeroRow ? $this->dataCitations($compiled, $result) : [],
            sql: $hasZeroRow && $this->mayUseRawSql($request->user) ? (string) ($compiled['sql'] ?? '') : null,
            appliedFilters: $appliedFilters,
            suggestions: ['Try last 12 months', 'Show all records']
        );
    }

    /**
     * The business the answer speaks about, named by the host — never a name
     * baked into this package.
     */
    private function placeName(AskRequest $request): string
    {
        $name = trim(Config::string('domain.name', ''));
        if ($name !== '') {
            return $name;
        }

        $company = trim((string) ($request->user->attributes['company'] ?? ''));

        return $company !== '' ? $company : trim(Config::string('domain.assistant_name', ''));
    }

    /** An all-NULL aggregate row (SUM over no rows) is not evidence worth showing. */
    private static function hasAnyNumber(array $row): bool
    {
        foreach ($row as $value) {
            if (is_numeric($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A single aggregate row whose every measure is zero or NULL means an empty
     * scope: SUM/MAX/MIN over no rows come back as NULL, not 0.
     */
    private static function isZeroAggregate(QueryResult $result, array $compiled): bool
    {
        if (($compiled['aggregate'] ?? false) !== true || count($result->rows) !== 1) {
            return false;
        }
        foreach ((array) ($result->rows[0] ?? []) as $value) {
            if ($value === null) {
                continue;
            }
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
            $storeRows = max(0, Config::int('limits.store_rows', 50));
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
                    'rows' => $storeRows > 0 ? array_slice($answer->rows, 0, $storeRows) : [],
                    'chart' => $answer->chart,
                    'applied_filters' => $answer->appliedFilters,
                    'sql' => $answer->sql,
                    // The query behind the answer, so the next question in this
                    // thread can continue it instead of starting over.
                    'plan' => (array) ($answer->meta['plan'] ?? []),
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
