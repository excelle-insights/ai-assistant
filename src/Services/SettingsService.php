<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use PDO;
use Throwable;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;

/**
 * Per-tenant / per-user overrides of the package config, stored as JSON in
 * {prefix}_settings with the unique key (company_id, scope, scope_key, key_name).
 *
 * Scope precedence, lowest to highest:
 *   global  <  company (scope_key = '' , or a role/project id)  <  user
 * mergeConfig() flattens them into one map a host can hand to Config.
 *
 * Storage is a protected seam, so the pure parts (scope normalisation, ranking,
 * value encode/decode, precedence resolution) run with no database — a test
 * subclass just swaps the four read/write methods for an in-memory array.
 *
 * Secrets: this service does NOT treat any key specially. Do not store API keys
 * or tokens here — the {prefix}_settings rows are plaintext JSON. Anything
 * sensitive that does land in the audit trail is masked on read via AuditService.
 */
class SettingsService
{
    private const RANKS = [
        'global' => 0,
        'company' => 1,
        'role' => 1,
        'project' => 1,
        'tenant' => 1,
        'user' => 2,
    ];

    /** @var callable(Throwable):void|null */
    private $onError;

    /**
     * @param callable(Throwable):void|null $onError
     */
    public function __construct(private ?PDO $pdo = null, ?callable $onError = null)
    {
        $this->onError = $onError;
    }

    // ── public API ───────────────────────────────────────────────────────────

    /**
     * @param array{company_id?:int,scope?:string,scope_key?:string}|null $scope
     */
    public function get(string $key, mixed $default = null, ?array $scope = null): mixed
    {
        $sc = $this->normaliseScope($scope);
        try {
            $row = $this->readRow($sc['company_id'], $sc['scope'], $sc['scope_key'], $key);
        } catch (Throwable $e) {
            $this->report($e);
            return $default;
        }
        if ($row === null) {
            return $default;
        }
        return $this->decodeValue($row['value_json'] ?? null) ?? $default;
    }

    /**
     * @param array{company_id?:int,scope?:string,scope_key?:string}|null $scope
     */
    public function put(string $key, mixed $value, ?array $scope = null, ?string $updatedBy = null): bool
    {
        $sc = $this->normaliseScope($scope);
        try {
            return $this->writeRow([
                'company_id' => $sc['company_id'],
                'scope' => $sc['scope'],
                'scope_key' => $sc['scope_key'],
                'key_name' => $key,
                'value_json' => $this->encodeValue($value),
                'updated_by' => $updatedBy,
            ]);
        } catch (Throwable $e) {
            $this->report($e);
            return false;
        }
    }

    /**
     * @param array{company_id?:int,scope?:string,scope_key?:string}|null $scope
     */
    public function forget(string $key, ?array $scope = null): bool
    {
        $sc = $this->normaliseScope($scope);
        try {
            return $this->deleteRow($sc['company_id'], $sc['scope'], $sc['scope_key'], $key);
        } catch (Throwable $e) {
            $this->report($e);
            return false;
        }
    }

    /**
     * All key => value pairs for one exact scope.
     *
     * @param array{company_id?:int,scope?:string,scope_key?:string}|null $scope
     * @return array<string,mixed>
     */
    public function allFor(?array $scope = null): array
    {
        $sc = $this->normaliseScope($scope);
        try {
            $rows = $this->listRows($sc['company_id'], $sc['scope'], $sc['scope_key']);
        } catch (Throwable $e) {
            $this->report($e);
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key_name'] ?? '');
            if ($key !== '') {
                $out[$key] = $this->decodeValue($row['value_json'] ?? null);
            }
        }
        return $out;
    }

    /**
     * Flatten global + company + user overrides for a tenant into a single map,
     * highest-precedence last (so it wins). Hosts pass the result to Config::load()
     * or apply it key-by-key with Config::set().
     *
     * @return array<string,mixed>
     */
    public function mergeConfig(int $companyId, ?int $userId = null): array
    {
        try {
            $rows = $this->candidateRows($companyId, $userId);
        } catch (Throwable $e) {
            $this->report($e);
            return [];
        }
        return $this->resolve($rows, $companyId, $userId);
    }

    // ── pure logic (no PDO) — unit-test surface ──────────────────────────────

    /**
     * Fold a caller scope array into canonical (company_id, scope, scope_key).
     *
     * @param array<string,mixed>|null $scope
     * @return array{company_id:int,scope:string,scope_key:string}
     */
    public function normaliseScope(?array $scope): array
    {
        $scope ??= [];
        $s = strtolower(trim((string) ($scope['scope'] ?? 'global')));
        if ($s === '') {
            $s = 'global';
        }
        $companyId = (int) ($scope['company_id'] ?? ($s === 'global' ? 0 : Config::get('tenant.id', 0)));
        $scopeKey = (string) ($scope['scope_key'] ?? ($s === 'user' && $companyId === 0 ? '' : ''));

        // A global scope never carries a tenant; a tenant/user scope always does.
        if ($s === 'global') {
            $companyId = 0;
            $scopeKey = '';
        }

        return ['company_id' => $companyId, 'scope' => $s, 'scope_key' => $scopeKey];
    }

    /** Precedence weight for a scope name (unknown scopes rank as company). */
    public function scopeRank(string $scope): int
    {
        return self::RANKS[strtolower(trim($scope))] ?? 1;
    }

    /**
     * Resolve precedence across raw setting rows. Rows nearer the top of the
     * list lose to rows nearer the bottom (global -> company -> user). Pure.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function resolve(array $rows, int $companyId, ?int $userId = null): array
    {
        // Keep only rows that legitimately apply to this company/user context.
        $applicable = [];
        foreach ($rows as $row) {
            $rowCompany = (int) ($row['company_id'] ?? 0);
            $scope = strtolower((string) ($row['scope'] ?? 'global'));
            $scopeKey = (string) ($row['scope_key'] ?? '');

            if ($scope === 'global') {
                if ($rowCompany !== 0) {
                    continue;
                }
            } elseif ($rowCompany !== $companyId) {
                continue;
            } elseif ($scope === 'user' && $userId !== null && $scopeKey !== '' && $scopeKey !== (string) $userId) {
                continue;
            } elseif ($scope === 'user' && $userId === null) {
                continue; // no user context -> user overrides don't apply
            }
            $applicable[] = $row;
        }

        // Sort ascending by rank; on equal rank, later updated_at wins (stable).
        usort($applicable, static function (array $a, array $b) use ($companyId): int {
            $ra = self::RANKS[strtolower((string) ($a['scope'] ?? 'global'))] ?? 1;
            $rb = self::RANKS[strtolower((string) ($b['scope'] ?? 'global'))] ?? 1;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            // within a rank: company-level beats bare 'tenant'; and global(0) < tenant(companyId)
            $ca = (int) ($a['company_id'] ?? 0) === $companyId ? 1 : 0;
            $cb = (int) ($b['company_id'] ?? 0) === $companyId ? 1 : 0;
            if ($ca !== $cb) {
                return $ca <=> $cb;
            }
            return strcmp((string) ($a['updated_at'] ?? ''), (string) ($b['updated_at'] ?? ''));
        });

        $out = [];
        foreach ($applicable as $row) {
            $key = (string) ($row['key_name'] ?? '');
            if ($key !== '') {
                $out[$key] = $this->decodeValue($row['value_json'] ?? null);
            }
        }
        return $out;
    }

    /** Encode a value for value_json (wrapped so nulls/arrays round-trip). */
    public function encodeValue(mixed $value): string
    {
        $json = json_encode(['v' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{"v":null}' : $json;
    }

    /** Decode value_json back to the original value; null on empty/invalid. */
    public function decodeValue(?string $json): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (is_array($decoded) && array_key_exists('v', $decoded)) {
            return $decoded['v'];
        }
        // Tolerate bare (un-wrapped) JSON written by other tooling.
        return $decoded;
    }

    /** Whether a key looks like a credential (for caller-side redaction hints). */
    public function isSecretKey(string $key): bool
    {
        return (bool) preg_match('/(password|secret|token|api_?key|credential|private_key|client_secret)/i', $key);
    }

    // ── storage seams (override these in tests) ──────────────────────────────

    /** @return array<string,mixed>|null */
    protected function readRow(int $companyId, string $scope, string $scopeKey, string $key): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        $table = Db::requireTable($this->pdo, 'settings');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `' . $table . '` WHERE `company_id` = :c AND `scope` = :s AND `scope_key` = :k AND `key_name` = :n LIMIT 1'
        );
        $stmt->execute(['c' => $companyId, 's' => $scope, 'k' => $scopeKey, 'n' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $row @return bool */
    protected function writeRow(array $row): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        $table = Db::requireTable($this->pdo, 'settings');
        Db::upsert($this->pdo, $table, [
            'company_id' => (int) $row['company_id'],
            'scope' => (string) $row['scope'],
            'scope_key' => (string) $row['scope_key'],
            'key_name' => (string) $row['key_name'],
            'value_json' => (string) $row['value_json'],
            'updated_by' => $row['updated_by'] ?? null,
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ], ['value_json', 'updated_by', 'updated_at']);
        return true;
    }

    protected function deleteRow(int $companyId, string $scope, string $scopeKey, string $key): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        $table = Db::requireTable($this->pdo, 'settings');
        $stmt = $this->pdo->prepare(
            'DELETE FROM `' . $table . '` WHERE `company_id` = :c AND `scope` = :s AND `scope_key` = :k AND `key_name` = :n'
        );
        $stmt->execute(['c' => $companyId, 's' => $scope, 'k' => $scopeKey, 'n' => $key]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<int,array<string,mixed>> */
    protected function listRows(int $companyId, string $scope, string $scopeKey): array
    {
        if ($this->pdo === null) {
            return [];
        }
        $table = Db::requireTable($this->pdo, 'settings');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `' . $table . '` WHERE `company_id` = :c AND `scope` = :s AND `scope_key` = :k'
        );
        $stmt->execute(['c' => $companyId, 's' => $scope, 'k' => $scopeKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> */
    protected function candidateRows(int $companyId, ?int $userId): array
    {
        if ($this->pdo === null) {
            return [];
        }
        $table = Db::requireTable($this->pdo, 'settings');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `' . $table . '` WHERE `company_id` IN (:g, :c) AND `scope` <> :user'
            . ' UNION ALL'
            . ' SELECT * FROM `' . $table . '` WHERE `company_id` = :c2 AND `scope` = :user AND (`scope_key` = :uid OR `scope_key` = \'\')'
        );
        $stmt->execute([
            'g' => 0,
            'c' => $companyId,
            'c2' => $companyId,
            'user' => 'user',
            'uid' => $userId !== null ? (string) $userId : '',
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
