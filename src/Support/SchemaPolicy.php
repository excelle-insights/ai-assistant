<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

use ExcelleInsights\AiAssistant\Contracts\TablePolicyInterface;

/**
 * Resolves which tables and columns the assistant may see or query, for THIS
 * host application, from configuration rather than package code.
 *
 *   schema_policy.mode             'allowlist' | 'denylist' | 'all'
 *   schema_policy.allowed_tables   the only queryable tables (allowlist mode)
 *   schema_policy.blocked_tables   never queryable, in every mode
 *   schema_policy.blocked_columns  stripped from context, results and SQL
 *   schema_policy.masked_columns   allowed, but shown redacted (e.g. phone/email)
 *
 * A host can override any decision per request by implementing
 * Contracts\TablePolicyInterface, which wins over the configured lists.
 */
final class SchemaPolicy
{
    /**
     * Floor for blocked columns: credentials and material that must never reach
     * a prompt, whatever the host configures. Contact details are deliberately
     * NOT here — a CRM often needs a masked phone number; those belong in
     * masked_columns.
     */
    private const SECRET_COLUMNS = [
        'password', 'password_hash', 'passwd', 'passhash', 'secret', 'api_key', 'apikey',
        'access_token', 'refresh_token', 'auth_token', 'token', 'tokens', 'otp', 'pin',
        'verification_code', 'reset_code', 'private_key', 'secret_key', 'client_secret',
        'ciphertext', 'encrypted', 'encrypted_data', 'session', 'session_id', 'cookie',
        'stripe_customer_id', 'paystack_authorization_code', 'cvv', 'card_number',
    ];

    /** Floor for blocked tables — the package's own tables and migration state. */
    private const INTERNAL_TABLES = ['phinxlog', 'migrations', 'migration_versions'];

    private array $cfg;
    private string $prefix;

    public function __construct(?array $config = null, private ?TablePolicyInterface $host = null, array $context = [])
    {
        EnvLoader::load();
        $this->cfg = $config ?? [];
        $this->prefix = TablePrefix::get();
    }

    /** Host context handed to TablePolicyInterface (user id, role, tenant). */
    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->host = $this->host;
        $clone->cfg = array_merge($this->cfg, ['context' => $context]);
        return $clone;
    }

    public function mode(): string
    {
        $configured = $this->cfg['mode'] ?? null;
        $mode = strtolower(trim(Config::string('schema_policy.mode', is_string($configured) ? $configured : 'denylist')));
        return in_array($mode, ['allowlist', 'denylist', 'all'], true) ? $mode : 'denylist';
    }

    public function tenantColumn(): string
    {
        return Config::string('tenant.column', (string) ($this->cfg['tenant_column'] ?? 'company_id')) ?: 'company_id';
    }

    public function requireTenantScope(): bool
    {
        return Config::bool('schema_policy.require_tenant_scope', (bool) ($this->cfg['require_tenant_scope'] ?? true));
    }

    public function maxRows(): int
    {
        $cap = Config::int('schema_policy.max_rows', (int) ($this->cfg['max_rows'] ?? 200));
        $hardCap = Config::int('limits.max_rows', 200);
        $rows = $cap > 0 ? $cap : 200;
        return $hardCap > 0 ? min($rows, $hardCap) : $rows;
    }

    public function defaultLimit(): int
    {
        $limit = Config::int('schema_policy.default_limit', (int) ($this->cfg['default_limit'] ?? 20));
        return max(1, min($limit > 0 ? $limit : 20, $this->maxRows()));
    }

    /** @return string[] normalised table names, empty when unset */
    public function allowedTables(): array
    {
        return $this->normalise(Config::list(
            'schema_policy.allowed_tables',
            array_map('strval', (array) ($this->cfg['allowed_tables'] ?? []))
        ));
    }

    public function blockedTables(): array
    {
        $extra = array_map('strval', (array) ($this->cfg['blocked_tables'] ?? []));
        return $this->normalise(array_merge($extra, Config::list('schema_policy.blocked_tables', []), self::INTERNAL_TABLES));
    }

    /** Columns that must never appear in a prompt, a result set or generated SQL. */
    public function blockedColumns(): array
    {
        $configured = Config::list('schema_policy.blocked_columns', []);
        if ($configured === [] && isset($this->cfg['blocked_columns']) && is_array($this->cfg['blocked_columns'])) {
            $configured = array_map('strval', $this->cfg['blocked_columns']);
        }
        $fromHost = $this->host?->extraBlockedColumns($this->context()) ?? [];
        return $this->normalise(array_merge(self::SECRET_COLUMNS, $configured, array_map('strval', $fromHost)));
    }

    /** Columns that are readable but redacted before they reach a prompt. */
    public function maskedColumns(): array
    {
        $configured = Config::list('schema_policy.masked_columns', []);
        if ($configured === [] && isset($this->cfg['masked_columns']) && is_array($this->cfg['masked_columns'])) {
            $configured = array_map('strval', $this->cfg['masked_columns']);
        }
        if ($configured === []) {
            $configured = ['phone', 'mobile', 'tel', 'contact_phone', 'msisdn', 'email', 'email_address'];
        }
        return $this->normalise($configured);
    }

    public function isOwnTable(string $table): bool
    {
        return str_starts_with(strtolower($table), strtolower($this->prefix) . '_');
    }

    public function includesOwnTables(): bool
    {
        return Config::bool('schema_policy.include_own_tables', (bool) ($this->cfg['include_own_tables'] ?? false));
    }

    public function isTableAllowed(string $table, ?string $comment = null): bool
    {
        $t = strtolower(trim($table));
        if ($t === '') {
            return false;
        }
        if (!$this->includesOwnTables() && $this->isOwnTable($t)) {
            return false;
        }
        $blocked = $this->blockedTables();
        if (in_array($t, $blocked, true)) {
            return false;
        }
        $hostVote = $this->host?->allowsTable($t, $this->context());
        if ($hostVote === true) {
            return true;
        }
        if ($hostVote === false) {
            return false;
        }
        return match ($this->mode()) {
            'allowlist' => in_array($t, $this->allowedTables(), true),
            default => true,
        };
    }

    public function isColumnAllowed(string $table, string $column): bool
    {
        $c = strtolower(trim($column));
        $t = strtolower(trim($table));
        if ($c === '') {
            return false;
        }
        if (in_array($c, $this->blockedColumns(), true)) {
            return false;
        }
        $hostVote = $this->host?->allowsColumn($t, $c, $this->context());
        return $hostVote !== false;
    }

    public function isColumnMasked(string $column): bool
    {
        $c = strtolower(trim($column));
        foreach ($this->maskedColumns() as $masked) {
            if ($c === $masked) {
                return true;
            }
        }
        return false;
    }

    /** Drop blocked columns from a result row. */
    public function stripRow(array $row): array
    {
        $blocked = $this->blockedColumns();
        foreach ($blocked as $column) {
            foreach (array_keys($row) as $key) {
                if (is_string($key) && strtolower($key) === $column) {
                    unset($row[$key]);
                }
            }
        }
        return $row;
    }

    /** Redact masked columns in place so the prompt never sees the raw value. */
    public function maskRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_string($key) && $this->isColumnMasked($key)) {
                $row[$key] = self::maskValue((string) $value, $key);
            }
        }
        return $row;
    }

    /**
     * Partial redaction matching the documented formats:
     *   email   j***@example.com
     *   phone   +254 7** *** ***
     *   other   J***
     */
    public static function maskValue(string $value, string $column = ''): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if (str_contains($trimmed, '@')) {
            [$local, $domain] = explode('@', $trimmed, 2);
            return mb_substr($local, 0, 1) . '***@' . $domain;
        }
        $digits = preg_replace('/\D/', '', $trimmed) ?? '';
        if ($digits !== '' && (strlen($digits) >= 7) && (str_contains($column, 'phone')
                || str_contains($column, 'tel')
                || str_contains($column, 'msisdn')
                || str_starts_with($trimmed, '+'))) {
            $keep = substr($digits, 0, 3);
            return preg_replace('/^\+/', '+', $trimmed[0] === '+' ? '+' . $keep : $keep)
                . ' ** *** ***';
        }
        $first = preg_split('/\s+/', $trimmed)[0] ?? $trimmed;
        return mb_substr($first, 0, 1) . '***';
    }

    /** Table names referenced by a SQL string (FROM + JOIN, backtick tolerant). */
    public function tablesInSql(string $sql): array
    {
        $tables = [];
        if (preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO)\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            foreach ($m[1] as $t) {
                $tables[] = strtolower($t);
            }
        }
        return array_values(array_unique($tables));
    }

    /**
     * Check a generated statement against the policy. Returns an error string,
     * or null when the query may run.
     */
    public function checkSql(string $sql): ?string
    {
        foreach ($this->tablesInSql($sql) as $t) {
            if (!$this->isTableAllowed($t)) {
                return "Table '{$t}' is not allowed by this application's policy";
            }
        }
        foreach ($this->blockedColumns() as $column) {
            if (preg_match('/(?<![`a-z0-9_])' . preg_quote($column, '/') . '(?![`a-z0-9_])/i', $sql)) {
                return "Column '{$column}' is not allowed";
            }
        }
        return null;
    }

    /** Human-readable summary used by diagnostics and the admin API. */
    public function describe(): array
    {
        return [
            'mode' => $this->mode(),
            'allowed_tables' => $this->allowedTables(),
            'blocked_tables' => $this->blockedTables(),
            'blocked_columns' => $this->blockedColumns(),
            'masked_columns' => $this->maskedColumns(),
            'max_rows' => $this->maxRows(),
            'default_limit' => $this->defaultLimit(),
            'tenant_column' => $this->tenantColumn(),
            'require_tenant_scope' => $this->requireTenantScope(),
            'include_own_tables' => $this->includesOwnTables(),
            'host_policy' => $this->host !== null,
        ];
    }

    private function context(): array
    {
        return (array) ($this->cfg['context'] ?? []);
    }

    /** @return string[] */
    private function normalise(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $n = strtolower(trim((string) preg_replace('/[`"\']/', '', (string) $name)));
            if ($n !== '' && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        return $out;
    }
}
