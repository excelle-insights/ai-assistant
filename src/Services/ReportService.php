<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use PDO;
use Throwable;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;

/**
 * Saved report definitions over {prefix}_report_definitions. A saved report is a
 * declarative request (intent, question, dataset filters, chart type, columns),
 * not a snapshot — it re-runs against live data under the requester's permissions.
 *
 * Execution is delegated: the constructor takes an optional `$runner` callable.
 * run() compiles the request and, when a runner is present, hands it
 * (definition, params, ctx); without one it returns the compiled request tagged
 * ['runner_missing' => true]. This keeps the service free of any other agent's
 * pipeline.
 *
 * Definition validation, request compilation and share-token generation are pure
 * (testable without PDO); row access goes through protected seams.
 */
class ReportService
{
    /**
     * What a saved definition may name as its chart. This is ChartService's own
     * palette plus 'none' (a report that only wants rows); 'map' is in the engine's
     * list but degrades to a table there, as the package has no geo data.
     * Anything else is rejected so a typo cannot silently change a report's shape.
     */
    private const CHART_TYPES = [
        'table', 'bar', 'column', 'line', 'area', 'pie', 'donut', 'stacked_bar',
        'funnel', 'scatter', 'heatmap', 'kpi', 'pivot', 'map', 'none',
    ];

    private const VISIBILITIES = ['private', 'team', 'company', 'public'];

    /** @var callable(array,array,?UserContext):mixed|null */
    private $runner;
    /** @var callable(Throwable):void|null */
    private $onError;

    /**
     * @param callable(array,array,?UserContext):mixed|null $runner
     * @param callable(Throwable):void|null $onError
     */
    public function __construct(
        private ?PDO $pdo = null,
        ?callable $runner = null,
        ?callable $onError = null,
        private ?AuditService $audit = null,
    ) {
        $this->runner = $runner;
        $this->onError = $onError;
    }

    /** @param callable(array,array,?UserContext):mixed $runner */
    public function setRunner(callable $runner): static
    {
        $this->runner = $runner;
        return $this;
    }

    // ── CRUD ───────────────────────────────────────────────────────────────

    /**
     * Persist a new definition. Returns the new id, or throws on invalid input
     * via the validation errors list (callers can pre-check with validate()).
     *
     * @param array<string,mixed> $input name + definition (+ visibility/…)
     */
    public function create(array $input, ?UserContext $ctx = null): string
    {
        $definition = $input['definition'] ?? $input;
        $errors = $this->validateDefinition($definition, (string) ($input['name'] ?? ''));
        if ($errors !== []) {
            throw new \InvalidArgumentException('Invalid report definition: ' . implode('; ', $errors));
        }
        $id = (string) ($input['id'] ?? Db::uuid());
        $companyId = (int) ($input['company_id'] ?? $ctx?->tenantId ?? 0);
        $now = Db::now();

        if ($this->pdo !== null) {
            $table = Db::requireTable($this->pdo, 'report_definitions');
            Db::insert($this->pdo, $table, [
                'id' => $id,
                'company_id' => $companyId,
                'owner_user_id' => $ctx?->userId ?? ($input['owner_user_id'] ?? null),
                'name' => (string) ($input['name'] ?? 'Untitled report'),
                'folder' => $this->str($input['folder'] ?? null),
                'description' => $this->str($input['description'] ?? null),
                'definition_json' => Db::json($definition),
                'visibility' => in_array((string) ($input['visibility'] ?? 'private'), self::VISIBILITIES, true)
                    ? (string) $input['visibility'] : 'private',
                'visible_to_json' => Db::json((array) ($input['visible_to'] ?? [])),
                'run_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return $id;
    }

    /**
     * @param array<string,mixed> $changes
     */
    public function update(string $id, array $changes, int $companyId): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        if (isset($changes['definition'])) {
            $errors = $this->validateDefinition((array) $changes['definition'], (string) ($changes['name'] ?? ''));
            if ($errors !== []) {
                throw new \InvalidArgumentException('Invalid report definition: ' . implode('; ', $errors));
            }
            $changes['definition_json'] = Db::json($changes['definition']);
            unset($changes['definition']);
        }
        if (isset($changes['visible_to'])) {
            $changes['visible_to_json'] = Db::json((array) $changes['visible_to']);
            unset($changes['visible_to']);
        }
        try {
            $table = Db::requireTable($this->pdo, 'report_definitions');
            $values = array_intersect_key(
                $changes,
                array_flip(['name', 'folder', 'description', 'definition_json', 'visibility', 'visible_to_json', 'owner_user_id'])
            );
            if ($values === []) {
                return false;
            }
            $values['updated_at'] = Db::now();
            Db::updateWhere($this->pdo, $table, $values, ['id' => $id, 'company_id' => $companyId], '');
            return true;
        } catch (Throwable $e) {
            $this->report($e);
            return false;
        }
    }

    public function delete(string $id, int $companyId): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        try {
            $table = Db::requireTable($this->pdo, 'report_definitions');
            $stmt = $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE `id` = :id AND `company_id` = :c');
            $stmt->execute(['id' => $id, 'c' => $companyId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            $this->report($e);
            return false;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id, int $companyId): ?array
    {
        $row = $this->loadDefinition($id, $companyId);
        if ($row === null) {
            return null;
        }
        return $this->hydrate($row);
    }

    /**
     * Tenant-scoped, visibility-aware listing for a requester.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(int $companyId, ?string $folder = null, ?UserContext $ctx = null): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $table = Db::requireTable($this->pdo, 'report_definitions');
            $sql = 'SELECT * FROM `' . $table . '` WHERE `company_id` = :c';
            $bind = ['c' => $companyId];
            if ($folder !== null && $folder !== '') {
                $sql .= ' AND `folder` = :f';
                $bind['f'] = $folder;
            }
            $sql .= ' ORDER BY `folder` ASC, `name` ASC';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bind);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->report($e);
            return [];
        }

        $visible = [];
        foreach ($rows as $row) {
            if ($this->canView($row, $ctx)) {
                $visible[] = $this->hydrate($row);
            }
        }
        return $visible;
    }

    // ── run / share / duplicate / history ──────────────────────────────────

    /**
     * Compile and (when a runner is wired) execute the report.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function run(string $id, array $params, ?UserContext $ctx = null): array
    {
        $companyId = (int) ($ctx?->tenantId ?? Config::get('tenant.id', 0));
        $row = $this->loadDefinition($id, $companyId);
        if ($row === null) {
            return ['error' => 'not_found', 'report_id' => $id];
        }

        $definition = Db::decode($row['definition_json'] ?? null);
        $request = $this->compileRequest($definition, $params, $ctx, $row);

        if ($this->runner !== null) {
            try {
                $result = ($this->runner)($definition, $params, $ctx);
            } catch (Throwable $e) {
                $this->report($e);
                return ['error' => 'runner_failed', 'report_id' => $id, 'message' => $e->getMessage()];
            }
            $this->registerRun($id, $companyId, $ctx);
            return is_array($result) ? $result : ['result' => $result];
        }

        return array_merge($request, ['runner_missing' => true]);
    }

    /**
     * A stable share token, kept inside visible_to_json (no dedicated share
     * column exists yet). Idempotent until `$rotate` is true: rotation is the
     * only revocation, and the token never expires on its own — a host that
     * needs expiry checks it in its own download route or re-shares per run.
     */
    public function shareToken(string $id, int $companyId, bool $rotate = false): ?string
    {
        $row = $this->loadDefinition($id, $companyId);
        if ($row === null) {
            return null;
        }
        $meta = Db::decode($row['visible_to_json'] ?? null);
        if (!$rotate && !empty($meta['share_token'])) {
            return (string) $meta['share_token'];
        }
        $token = substr(hash('sha256', $id . '|' . Db::uuid()), 0, 32);
        $meta['share_token'] = $token;
        if ($this->pdo !== null) {
            try {
                $table = Db::requireTable($this->pdo, 'report_definitions');
                Db::updateWhere($this->pdo, $table, ['visible_to_json' => Db::json($meta)], ['id' => $id, 'company_id' => $companyId], '');
            } catch (Throwable $e) {
                $this->report($e);
            }
        }
        return $token;
    }

    public function duplicate(string $id, int $companyId, ?UserContext $ctx = null): ?string
    {
        $row = $this->loadDefinition($id, $companyId);
        if ($row === null) {
            return null;
        }
        $copy = $row;
        $copy['id'] = Db::uuid();
        $copy['name'] = trim((string) ($row['name'] ?? 'Report')) . ' (copy)';
        $copy['owner_user_id'] = $ctx?->userId ?? ($row['owner_user_id'] ?? null);
        $copy['company_id'] = $companyId;
        $copy['run_count'] = 0;
        $copy['last_run_at'] = null;
        $copy['created_at'] = Db::now();
        $copy['updated_at'] = Db::now();
        $copy['visibility'] = 'private';
        $copy['visible_to_json'] = null;

        if ($this->pdo !== null) {
            $table = Db::requireTable($this->pdo, 'report_definitions');
            Db::insert($this->pdo, $table, array_intersect_key(
                $copy,
                array_flip(['id', 'company_id', 'owner_user_id', 'name', 'folder', 'description', 'definition_json', 'visibility', 'visible_to_json', 'run_count', 'last_run_at', 'created_at', 'updated_at'])
            ));
        }
        return (string) $copy['id'];
    }

    /**
     * Recent runs, read from {prefix}_audit via the injected AuditService.
     * Returns [] when no audit sink is wired.
     *
     * @return array<int,array<string,mixed>>
     */
    public function history(string $id, int $companyId, int $limit = 20): array
    {
        if ($this->audit === null) {
            return [];
        }
        $rows = $this->audit->query(['company_id' => $companyId, 'event' => 'report_run'], $limit, 0);
        $out = [];
        foreach ($rows as $row) {
            $refs = Db::decode($row['record_refs'] ?? null);
            if (in_array($id, array_map('strval', (array) $refs), true) || ($row['correlation_id'] ?? null) === $id) {
                $out[] = $row;
            }
        }
        return $out;
    }

    // ── pure logic (no PDO) ────────────────────────────────────────────────

    /**
     * Validate a definition + name. Returns a list of human-readable errors
     * (empty when valid). Pure & testable.
     *
     * @param array<string,mixed> $definition
     * @return string[]
     */
    public function validateDefinition(array $definition, string $name = ''): array
    {
        $errors = [];

        if (trim($name) === '' && trim((string) ($definition['name'] ?? '')) === '') {
            $errors[] = 'report name is required';
        }

        $chart = strtolower(trim((string) ($definition['chart'] ?? $definition['chart_type'] ?? 'table')));
        if ($chart !== '' && !in_array($chart, self::CHART_TYPES, true)) {
            $errors[] = "unknown chart type '{$chart}'";
        }

        // Datasets are host-specific; validate only when the host declares an allowlist.
        $allowedDatasets = array_map('strval', Config::list('reports.allowed_datasets', []));
        $dataset = trim((string) ($definition['dataset'] ?? ''));
        if ($allowedDatasets !== [] && $dataset !== '' && !in_array($dataset, $allowedDatasets, true)) {
            $errors[] = "unknown dataset '{$dataset}'";
        }
        if ($allowedDatasets !== [] && $dataset === '' && isset($definition['filters']) && $definition['filters'] !== []) {
            $errors[] = 'filters require a known dataset';
        }

        $intent = strtolower(trim((string) ($definition['intent'] ?? '')));
        if ($intent === '') {
            $errors[] = 'definition.intent is required';
        }

        if (isset($definition['columns']) && !is_array($definition['columns'])) {
            $errors[] = 'definition.columns must be a list';
        }

        return $errors;
    }

    /**
     * Build the data request a runner would execute. Pure & testable.
     *
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $params
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function compileRequest(array $definition, array $params, ?UserContext $ctx, array $row = []): array
    {
        $filters = array_merge((array) ($definition['filters'] ?? []), (array) ($params['filters'] ?? []));

        return [
            'report_id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ($definition['name'] ?? '')),
            'intent' => strtolower(trim((string) ($definition['intent'] ?? 'data_qa'))),
            'question' => (string) ($definition['question'] ?? ''),
            'dataset' => (string) ($definition['dataset'] ?? ''),
            'chart' => strtolower((string) ($definition['chart'] ?? $definition['chart_type'] ?? 'table')),
            'columns' => array_values(array_map('strval', (array) ($definition['columns'] ?? []))),
            'filters' => $filters,
            'params' => $params,
            'company_id' => (int) ($row['company_id'] ?? $ctx?->tenantId ?? 0),
            'user_id' => $ctx?->userId ?? null,
        ];
    }

    /** Whether a context may see a stored row (private/team/company/public). */
    public function canView(array $row, ?UserContext $ctx): bool
    {
        $visibility = strtolower((string) ($row['visibility'] ?? 'private'));
        if ($visibility === 'company' || $visibility === 'public') {
            return true;
        }
        if ($ctx === null) {
            return $visibility === 'private' ? false : true;
        }
        if ((string) ($row['owner_user_id'] ?? '') === $ctx->userId) {
            return true;
        }
        if ($visibility === 'team') {
            $visibleTo = Db::decode($row['visible_to_json'] ?? null);
            $roles = array_map('strval', (array) ($visibleTo['roles'] ?? []));
            $users = array_map('strval', (array) ($visibleTo['users'] ?? []));
            return $roles === [] || array_intersect($roles, $ctx->roles) !== [] || in_array($ctx->userId, $users, true);
        }
        return false;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function hydrate(array $row): array
    {
        $row['definition'] = Db::decode($row['definition_json'] ?? null);
        $row['visible_to'] = Db::decode($row['visible_to_json'] ?? null);
        unset($row['definition_json'], $row['visible_to_json']);
        return $row;
    }

    // ── storage seam ───────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    protected function loadDefinition(string $id, int $companyId): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        try {
            $table = Db::requireTable($this->pdo, 'report_definitions');
            $stmt = $this->pdo->prepare('SELECT * FROM `' . $table . '` WHERE `id` = :id AND `company_id` = :c LIMIT 1');
            $stmt->execute(['id' => $id, 'c' => $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            $this->report($e);
            return null;
        }
    }

    private function registerRun(string $id, int $companyId, ?UserContext $ctx): void
    {
        if ($this->pdo !== null) {
            try {
                $table = Db::requireTable($this->pdo, 'report_definitions');
                $stmt = $this->pdo->prepare(
                    'UPDATE `' . $table . '` SET `run_count` = `run_count` + 1, `last_run_at` = :t WHERE `id` = :id AND `company_id` = :c'
                );
                $stmt->execute(['t' => Db::now(), 'id' => $id, 'c' => $companyId]);
            } catch (Throwable $e) {
                $this->report($e);
            }
        }
        // Optional history via the injected audit sink — never required.
        if ($this->audit !== null) {
            $this->audit->emit([
                'company_id' => $companyId,
                'user_id' => $ctx?->userId ?? '',
                'event' => 'report_run',
                'channel' => $ctx?->channel ?? 'web',
                'correlation_id' => $id,
                'record_refs' => [$id],
                'detail' => ['report_id' => $id],
            ]);
        }
    }

    private function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function report(Throwable $e): void
    {
        if ($this->onError !== null) {
            try {
                ($this->onError)($e);
            } catch (Throwable) {
            }
        }
    }
}
