<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Contracts\ExporterInterface;
use ExcelleInsights\AiAssistant\Contracts\PermissionResolverInterface;
use ExcelleInsights\AiAssistant\Dto\Answer;
use ExcelleInsights\AiAssistant\Dto\ExportRequest;
use ExcelleInsights\AiAssistant\Dto\ExportResult;
use ExcelleInsights\AiAssistant\Dto\QueryResult;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\CsvWriter;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\SchemaHints;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;
use PDO;

/**
 * Files from an answer. csv, tsv and json are produced here — dependency-free —
 * while xlsx, pdf or Google Sheets go through a host ExporterInterface
 * registered for that format, because only the host owns those libraries.
 *
 * Three gates run before a byte is written: the host's own canExport vote, the
 * schema policy's column rules (a field the user cannot read on screen must not
 * arrive in a spreadsheet), and the confirmation required for pii, secret or
 * financial columns. An attempt that gets past the format gate records a row in
 * {prefix}_exports — ready, queued, awaiting approval or failed — with a download
 * token that stays valid until the job expires, which is what "who exported
 * customer phone numbers, when" is answered from. A refusal at a gate is audited
 * only, and with no
 * database connection nothing is recorded at all: `meta.recorded` tells the host
 * which case it is in.
 *
 * A queued or awaiting-approval job stores the plan, never the data: rows are
 * fetched at run time through the injected runner under the requester's
 * permissions, so an approved file reflects the scope at approval time.
 */
final class ExportService
{
    private const BUILT_IN = ['csv', 'tsv', 'json'];
    private const APPROVAL_LEVELS = ['pii', 'secret', 'financial'];

    /** @var array<string,ExporterInterface|callable> */
    private array $exporters = [];

    /** @var callable(ExportRequest):mixed|null */
    private $rowsRunner;

    private SchemaPolicy $policy;

    /** @param callable(ExportRequest):mixed|null $rowsRunner */
    public function __construct(
        private ?PDO $pdo = null,
        ?SchemaPolicy $policy = null,
        private ?AuditService $audit = null,
        private ?PermissionResolverInterface $permissions = null,
        ?callable $rowsRunner = null,
    ) {
        $this->policy = $policy ?? new SchemaPolicy();
        $this->rowsRunner = $rowsRunner;
        /** @var array<string,mixed> $configured */
        $configured = (array) Config::get('exports.host_exporters', []);
        foreach ($configured as $format => $factory) {
            $exporter = self::makeExporter($factory);
            if ($exporter !== null && trim((string) $format) !== '') {
                $this->exporters[strtolower(trim((string) $format))] = $exporter;
            }
        }
    }

    /** @param ExporterInterface|callable $exporter */
    public function register(string $format, $exporter): static
    {
        $format = strtolower(trim($format));
        if ($format === '') {
            throw new \InvalidArgumentException('An exporter needs a format name, e.g. xlsx.');
        }
        $this->exporters[$format] = $exporter;

        return $this;
    }

    /** @return array<int,string> formats this install can actually produce */
    public function formats(): array
    {
        $enabled = array_map('strtolower', Config::list('exports.formats'));
        $builtIn = $enabled === [] ? self::BUILT_IN : array_values(array_intersect(self::BUILT_IN, $enabled));
        // Registering a host exporter is itself the opt-in for that format.
        return array_values(array_unique(array_merge($builtIn, array_keys($this->exporters))));
    }

    public function isEnabled(string $format): bool
    {
        return in_array(strtolower(trim($format)), $this->formats(), true);
    }

    // ── building the request ──────────────────────────────────────────────────

    /**
     * Turn rows into the request a file is made from, without touching storage.
     * $meta: title, question, file_name, columns (selection and order),
     * applied_filters, entity, plan, conversation_id.
     *
     * @param UserContext|array<string,mixed> $user
     * @param array<string,mixed> $meta
     */
    public function request(mixed $data, string $format, UserContext|array $user, array $meta = []): ExportRequest
    {
        $context = is_array($user) ? UserContext::fromArray($user) : $user;
        $format = strtolower(trim($format)) !== '' ? strtolower(trim($format)) : 'csv';
        [$detected, $rows] = self::tabular($data);

        $wanted = [];
        foreach ((array) ($meta['columns'] ?? []) as $column) {
            $column = trim((string) $column);
            if ($column !== '') {
                $wanted[] = $column;
            }
        }
        $columns = $wanted !== [] ? $wanted : $detected;

        $shaped = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[$column] = is_array($row) ? ($row[$column] ?? null) : null;
            }
            $shaped[] = $line;
        }

        $max = Config::int('exports.max_rows', 100000);
        $truncated = $max > 0 && count($shaped) > $max;
        if ($truncated) {
            $shaped = array_slice($shaped, 0, $max);
        }

        $title = trim((string) ($meta['title'] ?? '')) ?: (string) ($meta['question'] ?? '') ?: 'export';
        $fileName = trim((string) ($meta['file_name'] ?? '')) !== ''
            ? self::safeFileName((string) $meta['file_name'], $format)
            : self::fileName($title, $format);

        return new ExportRequest(
            user: $context,
            format: $format,
            fileName: $fileName,
            columns: $columns,
            rows: $shaped,
            title: mb_substr($title, 0, 255),
            question: (string) ($meta['question'] ?? ''),
            appliedFilters: (array) ($meta['applied_filters'] ?? []),
            chart: (array) ($meta['chart'] ?? []),
            options: [
                'entity' => (string) ($meta['entity'] ?? ''),
                'plan' => (array) ($meta['plan'] ?? []),
                'conversation_id' => (string) ($meta['conversation_id'] ?? ''),
                'truncated' => $truncated,
                'delimiter' => $format === 'tsv' ? "\t" : CsvWriter::delimiter(),
            ],
        );
    }

    /**
     * Apply the permission and policy gates to a request.
     *
     * @return array{request:ExportRequest, forbidden:?string, sensitive:array<int,string>, masked:array<int,string>, dropped:array<int,string>}
     */
    public function prepare(ExportRequest $request): array
    {
        $entity = (string) ($request->options['entity'] ?? '');
        $forbidden = $this->permissions !== null && !$this->permissions->canExport($request->user, $entity)
            ? 'Your role is not allowed to export ' . ($entity !== '' ? '"' . $entity . '"' : 'these records') . '.'
            : null;

        $columns = [];
        $masked = [];
        $sensitive = [];
        $dropped = [];
        foreach ($request->columns as $column) {
            $column = (string) $column;
            if (!$this->policy->isColumnAllowed($entity, $column)) {
                $dropped[] = $column;
                continue;
            }
            $columns[] = $column;
            if ($this->policy->isColumnMasked($column)) {
                $masked[] = $column;
            }
            if (in_array(SchemaHints::sensitivity($column, '', $this->policy), self::APPROVAL_LEVELS, true)) {
                $sensitive[] = $column;
            }
        }

        $rows = [];
        foreach ($request->rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value = is_array($row) ? ($row[$column] ?? null) : null;
                $line[$column] = in_array($column, $masked, true) ? self::mask($value) : $value;
            }
            $rows[] = $line;
        }

        $kept = new ExportRequest(
            user: $request->user,
            format: $request->format,
            fileName: $request->fileName,
            columns: $columns,
            rows: $rows,
            title: $request->title,
            question: $request->question,
            appliedFilters: $request->appliedFilters,
            chart: $request->chart,
            options: $request->options,
        );

        return ['request' => $kept, 'forbidden' => $forbidden, 'sensitive' => $sensitive, 'masked' => $masked, 'dropped' => $dropped];
    }

    // ── the public entry points ───────────────────────────────────────────────

    /**
     * Produce the file, or queue it for approval/background work.
     *
     * @param UserContext|array<string,mixed> $user
     * @param array<string,mixed> $meta
     */
    public function create(mixed $data, string $format, UserContext|array $user, array $meta = []): ExportResult
    {
        $format = strtolower(trim($format)) !== '' ? strtolower(trim($format)) : 'csv';
        if (!$this->isEnabled($format)) {
            return ExportResult::failed('Format "' . $format . '" is not enabled here. Enabled: ' . implode(', ', $this->formats()) . '.', $format);
        }

        $context = is_array($user) ? UserContext::fromArray($user) : $user;
        $gates = $this->prepare($this->request($data, $format, $context, $meta));
        $request = $gates['request'];
        if ($gates['forbidden'] !== null) {
            $this->logEvent('ai.export_refused', $request, ['reason' => $gates['forbidden']]);
            return ExportResult::failed($gates['forbidden'], $format);
        }
        if ($request->rows === [] || $request->columns === []) {
            return ExportResult::failed($gates['dropped'] !== []
                ? 'Every column in that export is blocked for your role: ' . implode(', ', $gates['dropped']) . '.'
                : 'There is nothing to export: the query returned no rows.', $format);
        }

        // A service with no connection cannot hold a job, only make a file: an id it
        // quotes would be a link to a row that does not exist.
        $recorded = $this->pdo !== null;
        $id = $recorded ? Db::uuid() : '';
        $token = $recorded ? bin2hex(random_bytes(16)) : '';
        $ttl = max(1, Config::int('exports.download_ttl_minutes', 1440));
        $async = count($request->rows) > max(1, Config::int('exports.async_above_rows', 5000));
        $needsApproval = $gates['sensitive'] !== [] && Config::bool('exports.require_confirmation', true) && !$this->isApprover($context);

        $record = [
            'id' => $id,
            'company_id' => $context->tenantId,
            'user_id' => $context->userId,
            'conversation_id' => (string) ($request->options['conversation_id'] ?? '') ?: null,
            'format' => $request->format,
            'file_name' => $request->fileName,
            'title' => $request->title,
            'question' => $request->question !== '' ? $request->question : null,
            'columns_json' => Db::json($request->columns),
            'filters_json' => Db::json($request->appliedFilters),
            'plan_json' => Db::json((array) ($request->options['plan'] ?? [])),
            'row_count' => count($request->rows),
            'download_token' => $token,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl * 60),
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ];

        if ($needsApproval && !$recorded) {
            // Fails closed: the approval can only live in a job row, and there is
            // nowhere to put one, so the sensitive file is not made.
            return ExportResult::failed('This export carries sensitive columns (' . implode(', ', $gates['sensitive'])
                . ') and this export service has no database connection to keep the approval job, so nothing was written.', $format);
        }

        if ($needsApproval) {
            $this->store($record + ['status' => 'awaiting_approval']);
            $this->logEvent('ai.export_awaiting_approval', $request, ['columns' => $gates['sensitive']]);
            return ExportResult::external($format, 'export://' . $id, $request->fileName, [
                'id' => $id,
                'status' => 'awaiting_approval',
                'recorded' => true,
                'sensitive_columns' => $gates['sensitive'],
                'message' => 'This export carries sensitive columns (' . implode(', ', $gates['sensitive'])
                    . '), so it waits for an approval before the file is made.',
            ]);
        }

        if ($async) {
            $this->store($record + ['status' => 'queued']);
            $this->logEvent('ai.export_queued', $request, ['rows' => count($request->rows)]);
            return ExportResult::external($format, 'export://' . $id, $request->fileName, [
                'id' => $id,
                'status' => 'queued',
                'recorded' => true,
                'rows' => count($request->rows),
                'message' => 'That is a large export (' . count($request->rows) . ' rows), so it is queued and the '
                    . 'download link appears when it finishes.',
            ]);
        }

        $this->store($record + ['status' => 'pending']);
        $result = $this->generate($request, $id, $token);
        $this->logEvent($result->ok ? 'ai.export_ready' : 'ai.export_failed', $request, [
            'id' => $id, 'status' => $result->ok ? 'ready' : 'failed', 'error' => $result->error,
            'masked_columns' => $gates['masked'], 'dropped_columns' => $gates['dropped'],
        ]);

        return $result;
    }

    /**
     * Approve a waiting export and produce it now.
     *
     * @param UserContext|array<string,mixed> $approver
     * @param mixed $data rows to use when the job stored no re-runnable plan
     */
    public function approve(string $id, UserContext|array $approver, mixed $data = null): ExportResult
    {
        $context = is_array($approver) ? UserContext::fromArray($approver) : $approver;
        $row = $this->find($id);
        if ($row === null) {
            return ExportResult::failed('No export with that id.', 'csv');
        }
        if ((string) ($row['status'] ?? '') !== 'awaiting_approval') {
            return ExportResult::failed('That export is "' . (string) ($row['status'] ?? 'unknown') . '", not awaiting approval.', (string) ($row['format'] ?? 'csv'));
        }
        $sameOwner = (int) ($row['company_id'] ?? 0) === $context->tenantId && (string) ($row['user_id'] ?? '') === $context->userId;
        if (!$sameOwner && !$this->isApprover($context)) {
            return ExportResult::failed('Only the requester or an admin can approve this export.', (string) ($row['format'] ?? 'csv'));
        }

        $plan = Db::decode($row['plan_json'] ?? null);
        $base = $this->requestFromRow($row, $context, $plan);
        $rows = $data ?? $this->fetch($base);
        if ($rows === null) {
            return ExportResult::failed('This job kept no rows and no plan a runner can re-run, so the file cannot be made. '
                . 'Pass the rows to approve(), or export with a plan.', $base->format);
        }

        $fresh = $this->prepare($this->request($rows, $base->format, $context, [
            'title' => $base->title,
            'question' => $base->question,
            'file_name' => $base->fileName,
            'columns' => $base->columns,
            'applied_filters' => $base->appliedFilters,
            'plan' => $plan,
            'entity' => (string) ($plan['dataset'] ?? ''),
            'conversation_id' => (string) ($base->options['conversation_id'] ?? ''),
        ]));
        if ($fresh['forbidden'] !== null) {
            $this->update($id, ['status' => 'failed', 'error' => $fresh['forbidden']]);
            return ExportResult::failed($fresh['forbidden'], $base->format);
        }
        if ($fresh['request']->rows === [] || $fresh['request']->columns === []) {
            $this->update($id, ['status' => 'failed', 'error' => 'no rows at approval time']);
            return ExportResult::failed('At approval time the query returned no rows, so there is no file to make.', $base->format);
        }

        $this->update($id, ['status' => 'running', 'approved_by' => $context->userId, 'approved_at' => Db::now()]);
        $result = $this->generate($fresh['request'], $id, (string) ($row['download_token'] ?? ''));
        $this->logEvent($result->ok ? 'ai.export_approved' : 'ai.export_failed', $fresh['request'], [
            'id' => $id, 'approved_by' => $context->userId, 'error' => $result->error,
        ]);

        return $result;
    }

    /** Run queued jobs — call it from a worker, a cron entry or the HTTP tick. */
    public function runQueued(int $limit = 5, ?UserContext $as = null): array
    {
        if ($this->pdo === null) {
            return [];
        }
        $out = [];
        foreach ($this->queuedIds($limit) as $row) {
            $id = (string) $row['id'];
            $context = $as ?? new UserContext((string) ($row['user_id'] ?? ''), (int) ($row['company_id'] ?? 0), 'export');
            $plan = Db::decode($row['plan_json'] ?? null);
            $request = $this->requestFromRow($row, $context, $plan);
            $rows = $this->fetch($request);
            if ($rows === null) {
                $this->update($id, ['status' => 'failed', 'error' => 'No rows runner is registered for queued exports.']);
                $out[] = ExportResult::failed('Queued export "' . $id . '" has no rows runner.', $request->format);
                continue;
            }
            $fresh = $this->prepare($this->request($rows, $request->format, $context, [
                'title' => $request->title,
                'question' => $request->question,
                'file_name' => $request->fileName,
                'columns' => $request->columns,
                'applied_filters' => $request->appliedFilters,
                'plan' => $plan,
                'entity' => (string) ($plan['dataset'] ?? ''),
                'conversation_id' => (string) ($request->options['conversation_id'] ?? ''),
            ]));
            $this->update($id, ['status' => 'running']);
            $out[] = $this->generate($fresh['request'], $id, (string) ($row['download_token'] ?? ''));
        }

        return $out;
    }

    /**
     * Resolve a download token to a readable file.
     *
     * @param UserContext|array<string,mixed>|null $user when given, the file must belong to this user's company
     * @return array{id:string,file_name:string,mime:string,path:string,size:int,format:string}|null
     */
    public function resolve(string $token, UserContext|array|null $user = null): ?array
    {
        $token = trim($token);
        if ($token === '' || $this->pdo === null) {
            return null;
        }
        $table = Db::requireTable($this->pdo, 'exports');
        $stmt = $this->pdo->prepare('SELECT * FROM `' . $table . '` WHERE download_token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $context = $user === null ? null : (is_array($user) ? UserContext::fromArray($user) : $user);
        if ($context !== null && (int) ($row['company_id'] ?? 0) !== $context->tenantId) {
            return null;
        }
        if ((string) ($row['status'] ?? '') !== 'ready') {
            return null;
        }
        $expires = (string) ($row['expires_at'] ?? '');
        if ($expires !== '' && strtotime($expires . ' UTC') < time()) {
            return null;
        }
        $path = (string) ($row['storage_key'] ?? '');
        if ($path === '' || !is_file($path)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'file_name' => (string) ($row['file_name'] ?? basename($path)),
            'mime' => self::mime((string) ($row['format'] ?? 'csv')),
            'path' => $path,
            'size' => (int) ($row['size_bytes'] ?? filesize($path)),
            'format' => (string) ($row['format'] ?? 'csv'),
        ];
    }

    /**
     * The export history for this user, newest first. created_at is second
     * precision, so id breaks ties and keeps paging stable.
     *
     * @return array<int,array<string,mixed>>
     */
    public function history(UserContext|array $user, int $limit = 20): array
    {
        if ($this->pdo === null) {
            return [];
        }
        $context = is_array($user) ? UserContext::fromArray($user) : $user;

        return Db::select(
            $this->pdo,
            Db::requireTable($this->pdo, 'exports'),
            ['company_id' => $context->tenantId, 'user_id' => $context->userId],
            [],
            [['created_at', 'desc'], ['id', 'desc']],
            max(1, min(200, $limit)),
            '',
            ['id', 'format', 'file_name', 'title', 'row_count', 'size_bytes', 'status', 'created_at', 'expires_at', 'error']
        );
    }

    /** Delete expired records and their files; returns [rows deleted, files removed]. */
    public function purgeExpired(int $limit = 200): array
    {
        if ($this->pdo === null) {
            return [0, 0];
        }
        $table = Db::requireTable($this->pdo, 'exports');
        $stmt = $this->pdo->prepare('SELECT id, storage_key FROM `' . $table . '` WHERE expires_at IS NOT NULL AND expires_at < :now LIMIT :limit');
        $stmt->bindValue(':now', Db::now());
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $files = 0;
        foreach ($rows as $row) {
            $path = (string) ($row['storage_key'] ?? '');
            if ($path !== '' && is_file($path) && @unlink($path)) {
                $files++;
            }
            $this->delete((string) $row['id']);
        }

        return [count($rows), $files];
    }

    // ── file production ───────────────────────────────────────────────────────

    /** Write the file for a gated request and mark the record ready or failed. */
    public function generate(ExportRequest $request, string $id, ?string $token = null): ExportResult
    {
        $result = $this->render($request);
        if (!$result->ok) {
            $this->update($id, ['status' => 'failed', 'error' => $result->error]);
            return $result;
        }

        // Bytes mean we produced the file; a location with no bytes is the host's
        // own handle (a sheet id, a drive uri) and must never be treated as a path.
        if ($result->bytes === null) {
            $this->update($id, [
                'status' => 'ready', 'row_count' => count($request->rows),
                'completed_at' => Db::now(), 'error' => null,
            ]);

            return $result;
        }

        $path = $this->writeFile($request, $result->bytes);
        $size = strlen($result->bytes);
        if ($path === null) {
            $this->update($id, ['status' => 'failed', 'error' => 'The export directory is not writable.']);

            return ExportResult::failed('Cannot write the export file into ' . self::directory() . '.', $request->format);
        }

        $this->update($id, [
            'status' => 'ready',
            'storage_key' => $path,
            'size_bytes' => $size,
            'row_count' => count($request->rows),
            'completed_at' => Db::now(),
            'download_token' => $token,
            'error' => null,
        ]);

        // With no connection there is no job row, so no id to quote and no token a
        // link could ever resolve against. Hand back the file and say it is not on
        // record, rather than a download URL that 404s.
        $recorded = $this->pdo !== null;

        return new ExportResult(
            ok: true,
            format: $request->format,
            fileName: $request->fileName,
            location: $path,
            meta: array_merge([
                'rows' => count($request->rows),
                'bytes' => $size,
                'mime' => $request->mimeType(),
                'expires_in_minutes' => max(1, Config::int('exports.download_ttl_minutes', 1440)),
                'truncated' => (bool) ($request->options['truncated'] ?? false),
                'recorded' => $recorded,
            ], $recorded ? [
                'id' => $id,
                // The caller needs the token to build the link; resolve() checks it.
                'download_token' => (string) ($token ?? ''),
            ] : []),
        );
    }

    /** Produce the bytes (or hand off to a host exporter) with no record written. */
    public function render(ExportRequest $request): ExportResult
    {
        $format = $request->format;
        if (isset($this->exporters[$format])) {
            $exporter = self::makeExporter($this->exporters[$format]);
            if ($exporter instanceof ExporterInterface) {
                return $exporter->export($request);
            }
            if (is_callable($exporter)) {
                $result = $exporter($request);
                return $result instanceof ExportResult ? $result : ExportResult::failed('The "' . $format . '" exporter returned nothing usable.', $format);
            }

            return ExportResult::failed('The "' . $format . '" exporter is neither an ExporterInterface nor a callable.', $format);
        }

        return match ($format) {
            'csv', 'tsv' => self::delimited($request),
            'json' => ExportResult::file('json', $request->fileName, self::json($request)),
            default => ExportResult::failed('No exporter is registered for "' . $format . '".', $format),
        };
    }

    private static function delimited(ExportRequest $request): ExportResult
    {
        $delimiter = (string) ($request->options['delimiter'] ?? CsvWriter::delimiter());
        $newline = CsvWriter::newline();
        $quoting = CsvWriter::quotingForce();
        $out = CsvWriter::useBom() ? CsvWriter::BOM : '';
        $out .= CsvWriter::line($request->columns, $delimiter, $newline, $quoting);
        foreach ($request->rows as $row) {
            $line = [];
            foreach ($request->columns as $column) {
                $line[] = is_array($row) ? ($row[$column] ?? null) : null;
            }
            $out .= CsvWriter::line($line, $delimiter, $newline, $quoting);
        }

        return ExportResult::file($request->format, $request->fileName, $out);
    }

    private static function json(ExportRequest $request): string
    {
        $payload = [
            'title' => $request->title,
            'question' => $request->question,
            'generated_at' => Db::now(),
            'applied_filters' => $request->appliedFilters,
            'columns' => $request->columns,
            'rows' => $request->rows,
            'truncated' => (bool) ($request->options['truncated'] ?? false),
        ];
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '{"error":"could not encode the export"}' : $encoded;
    }

    private function writeFile(ExportRequest $request, string $bytes): ?string
    {
        $dir = self::directory();
        $path = rtrim($dir, '/') . '/' . $request->fileName;
        if (@file_put_contents($path, $bytes) === false) {
            return null;
        }

        return $path;
    }

    public static function directory(): string
    {
        $dir = trim(Config::string('exports.directory', ''));
        if ($dir === '') {
            $dir = rtrim(sys_get_temp_dir(), '/') . '/ai-assistant-exports';
        }
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the export directory: ' . $dir);
        }

        return $dir;
    }

    /** A file name that cannot escape the export directory. */
    public static function safeFileName(string $name, string $format): string
    {
        $name = trim($name);
        $base = preg_replace('/\.[a-z0-9]+$/i', '', $name) ?? $name;
        $clean = preg_replace('/[^A-Za-z0-9._-]+/u', '-', $base) ?? '';
        $clean = trim($clean, '-.');
        if ($clean === '' || $clean === '.') {
            $clean = Config::string('exports.file_name_prefix', 'ai-export');
        }

        return $clean . '.' . preg_replace('/[^a-z0-9]+/u', '', strtolower($format));
    }

    /**
     * Generated download name — and the storage key, so it must be unique. A
     * timestamp alone would let two exports made in the same second overwrite
     * each other, and the first row's token would then serve the second file.
     */
    public static function fileName(string $title, string $format): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/u', '-', strtolower($title)) ?? '', '-');
        if ($slug === '') {
            $slug = Config::string('exports.file_name_prefix', 'ai-export');
        }

        return self::safeFileName($slug . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)), $format);
    }

    public static function mime(string $format): string
    {
        return match (strtolower($format)) {
            'csv' => 'text/csv',
            'tsv' => 'text/tab-separated-values',
            'json' => 'application/json',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }

    private static function mask(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        if ($text === '') {
            return '';
        }

        return str_repeat('*', min(8, max(3, mb_strlen($text))));
    }

    private function isApprover(UserContext $user): bool
    {
        $roles = array_map('strtolower', Config::list('exports.approval_roles'));
        foreach ($user->roles as $role) {
            if (in_array(strtolower((string) $role), $roles, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param ExporterInterface|callable|class-string $factory */
    private static function makeExporter(mixed $factory): ExporterInterface|callable|null
    {
        if (is_string($factory) && class_exists($factory)) {
            $factory = new $factory();
        } elseif (is_array($factory) && isset($factory['class']) && is_string($factory['class']) && class_exists($factory['class'])) {
            $factory = new ($factory['class'])();
        }
        if ($factory instanceof ExporterInterface || is_callable($factory)) {
            return $factory;
        }

        return null;
    }

    // ── storage seams ─────────────────────────────────────────────────────────

    /** @param array<string,mixed> $values */
    protected function store(array $values): void
    {
        if ($this->pdo === null) {
            return;
        }
        Db::insert($this->pdo, Db::requireTable($this->pdo, 'exports'), $values);
    }

    /** @param array<string,mixed> $values */
    protected function update(string $id, array $values): void
    {
        if ($this->pdo === null || $id === '') {
            return;
        }
        $values['updated_at'] = Db::now();
        Db::updateWhere($this->pdo, Db::requireTable($this->pdo, 'exports'), $values, ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    protected function find(string $id): ?array
    {
        if ($this->pdo === null || $id === '') {
            return null;
        }
        $rows = Db::select($this->pdo, Db::requireTable($this->pdo, 'exports'), ['id' => $id], [], [], 1);

        return $rows[0] ?? null;
    }

    /** @return array<int,array<string,mixed>> */
    protected function queuedIds(int $limit): array
    {
        if ($this->pdo === null) {
            return [];
        }

        return Db::select($this->pdo, Db::requireTable($this->pdo, 'exports'), ['status' => 'queued'], [], [], max(1, $limit));
    }

    protected function delete(string $id): void
    {
        if ($this->pdo === null || $id === '') {
            return;
        }
        $table = Db::identifier(Db::requireTable($this->pdo, 'exports'));
        $stmt = $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    protected function fetch(ExportRequest $request): mixed
    {
        if ($this->rowsRunner === null) {
            return null;
        }

        return ($this->rowsRunner)($request);
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $plan */
    private function requestFromRow(array $row, UserContext $context, array $plan): ExportRequest
    {
        return new ExportRequest(
            user: $context,
            format: (string) ($row['format'] ?? 'csv'),
            fileName: (string) ($row['file_name'] ?? self::fileName((string) ($row['title'] ?? 'export'), (string) ($row['format'] ?? 'csv'))),
            columns: array_map('strval', Db::decode($row['columns_json'] ?? null)),
            rows: [],
            title: (string) ($row['title'] ?? ''),
            question: (string) ($row['question'] ?? ''),
            appliedFilters: Db::decode($row['filters_json'] ?? null),
            options: ['plan' => $plan, 'entity' => (string) ($plan['dataset'] ?? ''), 'conversation_id' => (string) ($row['conversation_id'] ?? '')],
        );
    }

    /**
     * @param array<string,mixed> $detail
     * @param array<string,mixed> $request
     */
    private function logEvent(string $event, ExportRequest $request, array $detail): void
    {
        if ($this->audit === null) {
            $this->audit ??= new AuditService($this->pdo);
        }
        $this->audit->log(
            ['company_id' => $request->user->tenantId, 'user_id' => $request->user->userId, 'channel' => $request->user->channel],
            $event,
            $detail + ['format' => $request->format, 'file_name' => $request->fileName, 'rows' => count($request->rows)]
        );
    }

    /** @return array{0:array<int,string>,1:array<int,array<string,mixed>>}} */
    private static function tabular(mixed $data): array
    {
        if ($data instanceof QueryResult) {
            return [array_map('strval', $data->columns), $data->rows];
        }
        if ($data instanceof Answer) {
            $columns = array_map('strval', (array) ($data->meta['columns'] ?? []));
            if ($columns === [] && $data->rows !== []) {
                $columns = array_map('strval', array_keys((array) $data->rows[0]));
            }

            return [$columns, $data->rows];
        }
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        $rows = [];
        foreach ((array) $data as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $columns[(string) $key] = (string) $key;
            }
        }

        return [array_values($columns), $rows];
    }
}
