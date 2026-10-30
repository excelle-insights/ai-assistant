<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * Single source of truth for everything the package needs to know about the
 * host application: domain wording, currency, table/column policy, metrics,
 * features, channel settings and limits.
 *
 * The package never hardcodes an application's tables, prompts or currency.
 * A host supplies them without editing vendor/ through, in increasing order
 * of precedence:
 *
 *   1. built-in defaults in this class
 *   2. config/ai-assistant.php  (package default; resolves env keys itself)
 *   3. host config file         (published copy, or AI_ASSISTANT_CONFIG_FILE)
 *      Config::load([...])      (host builds the array in its own boot)
 *   4. settings store           (Config::useSettings(); the package's
 *                                {prefix}_config row — behavioural settings a
 *                                host edits in its own settings screen)
 *   5. env variables listed in ENV_MAP (only when actually set)
 *   6. Config::set('path.to.key', $value)  (programmatic, wins over everything)
 *
 * So a host can either publish the config file, hand Config an array at boot,
 * store operational settings in the database, or just set environment
 * variables — all four work from `composer update`. Keeping secrets out of the
 * database (they belong in env) is a host choice, not a package requirement.
 */
final class Config
{
    /**
     * Path => env keys; first key that exists in the environment wins.
     *
     * Only explicit AI_ASSISTANT_* keys live here. Generic app keys (APP_NAME,
     * APP_CURRENCY, APP_TIMEZONE) and the pre-rename AI_WHATSAPP_* aliases are
     * resolved by the package default config file instead, so a host that
     * publishes its own config file wins over its own legacy environment.
     */
    private const ENV_MAP = [
        'table_prefix' => ['AI_ASSISTANT_TABLE_PREFIX'],
        'tenant.column' => ['AI_ASSISTANT_TENANT_COLUMN'],
        'tenant.id' => ['AI_ASSISTANT_TENANT_ID'],
        'tenant.legacy_table' => ['AI_ASSISTANT_TENANT_TABLE'],

        'domain.name' => ['AI_ASSISTANT_DOMAIN_NAME'],
        'domain.assistant_name' => ['AI_ASSISTANT_NAME'],
        'domain.currency' => ['AI_ASSISTANT_CURRENCY'],
        'domain.currency_position' => ['AI_ASSISTANT_CURRENCY_POSITION'],
        'domain.locale' => ['AI_ASSISTANT_LOCALE'],
        'domain.language' => ['AI_ASSISTANT_LANGUAGE'],
        'domain.multilingual' => ['AI_ASSISTANT_MULTILINGUAL'],
        'domain.system_prompt' => ['AI_ASSISTANT_SYSTEM_PROMPT'],
        'domain.query_prompt' => ['AI_ASSISTANT_SQL_PROMPT'],
        'domain.custom_instructions' => ['AI_ASSISTANT_CUSTOM_INSTRUCTIONS'],

        'llm.provider' => ['LLM_PROVIDER'],
        'llm.api_key' => ['LLM_API_KEY', 'OPENAI_API_KEY'],
        'llm.base_url' => ['LLM_BASE_URL', 'OPENAI_BASE_URL'],
        'llm.model' => ['LLM_MODEL', 'OPENAI_MODEL'],
        'llm.embedding_model' => ['LLM_EMBEDDING_MODEL'],
        'llm.vision_model' => ['LLM_VISION_MODEL'],
        'llm.timeout' => ['LLM_REQUEST_TIMEOUT', 'OPENAI_REQUEST_TIMEOUT'],

        'schema_policy.mode' => ['AI_ASSISTANT_TABLE_POLICY_MODE'],
        'schema_policy.allowed_tables' => ['AI_ASSISTANT_ALLOWED_TABLES'],
        'schema_policy.blocked_tables' => ['AI_ASSISTANT_BLOCKED_TABLES'],
        'schema_policy.blocked_columns' => ['AI_ASSISTANT_BLOCKED_COLUMNS'],
        'schema_policy.masked_columns' => ['AI_ASSISTANT_MASKED_COLUMNS'],
        'schema_policy.max_rows' => ['AI_ASSISTANT_MAX_ROWS'],
        'schema_policy.default_limit' => ['AI_ASSISTANT_DEFAULT_LIMIT'],
        'schema_policy.include_own_tables' => ['AI_ASSISTANT_INCLUDE_OWN_TABLES'],
        'schema_policy.require_tenant_scope' => ['AI_ASSISTANT_REQUIRE_TENANT_SCOPE'],

        'schema.prune_stale' => ['AI_ASSISTANT_SCHEMA_PRUNE'],
        'schema.auto_describe' => ['AI_ASSISTANT_SCHEMA_AUTO_DESCRIBE'],
        'schema.sample_rows' => ['AI_ASSISTANT_SCHEMA_SAMPLE_ROWS'],
        'schema.skip_tables' => ['AI_ASSISTANT_SCHEMA_SKIP_TABLES'],

        'semantic.enabled' => ['AI_ASSISTANT_SEMANTIC_ENABLED'],
        'semantic.auto_derive' => ['AI_ASSISTANT_SEMANTIC_AUTO'],
        'semantic.packs' => ['AI_ASSISTANT_SEMANTIC_PACKS'],
        'semantic.pack_file' => ['AI_ASSISTANT_SEMANTIC_PACK'],
        'semantic.max_dimensions' => ['AI_ASSISTANT_SEMANTIC_MAX_DIMENSIONS'],
        'semantic.max_datasets' => ['AI_ASSISTANT_SEMANTIC_MAX_DATASETS'],
        'semantic.allow_raw_sql' => ['AI_ASSISTANT_ALLOW_RAW_SQL'],
        'semantic.raw_sql_roles' => ['AI_ASSISTANT_RAW_SQL_ROLES'],

        'reference.enabled' => ['AI_ASSISTANT_REFERENCE_ENABLED'],
        'reference.tables' => ['AI_ASSISTANT_REFERENCE_TABLES'],
        'reference.auto_suffixes' => ['AI_ASSISTANT_REFERENCE_SUFFIXES'],
        'reference.name_columns' => ['AI_ASSISTANT_REFERENCE_NAME_COLUMNS'],
        'reference.price_columns' => ['AI_ASSISTANT_REFERENCE_PRICE_COLUMNS'],
        'reference.max_values' => ['AI_ASSISTANT_REFERENCE_MAX_VALUES'],
        'metrics.auto' => ['AI_ASSISTANT_METRICS_AUTO'],

        'knowledge.retrieval' => ['AI_ASSISTANT_RETRIEVAL'],
        'knowledge.top_k' => ['AI_ASSISTANT_KNOWLEDGE_TOP_K'],
        'knowledge.fallback_latest' => ['AI_ASSISTANT_KNOWLEDGE_FALLBACK_LATEST'],
        'knowledge.min_similarity' => ['AI_ASSISTANT_MIN_SIMILARITY'],
        'knowledge.self_training' => ['AI_ASSISTANT_SELF_TRAINING'],

        'features' => ['AI_ASSISTANT_FEATURES'],
        'limits.max_rows' => ['AI_ASSISTANT_MAX_ROWS'],
        'limits.max_upload_mb' => ['AI_ASSISTANT_MAX_UPLOAD_MB'],
        'limits.max_pages' => ['AI_ASSISTANT_MAX_PAGES'],
        'limits.retention_days' => ['AI_ASSISTANT_RETENTION_DAYS'],
        'limits.session_turns' => ['AI_ASSISTANT_SESSION_TURNS'],
        'limits.session_ttl_minutes' => ['AI_ASSISTANT_SESSION_TTL'],
        'limits.memory_turns' => ['AI_ASSISTANT_MEMORY_TURNS'],
        'limits.store_rows' => ['AI_ASSISTANT_STORE_ROWS'],

        'rate_limit.per_hour' => ['AI_ASSISTANT_RATE_LIMIT_PER_HOUR'],
        'rate_limit.per_day' => ['AI_ASSISTANT_RATE_LIMIT_PER_DAY'],

        'channels.whatsapp.enabled' => ['AI_ASSISTANT_WHATSAPP_ENABLED'],
        'channels.whatsapp.table_prefix' => ['AI_ASSISTANT_WHATSAPP_TABLE_PREFIX'],
        'channels.whatsapp.auto_reply' => ['AI_ASSISTANT_AUTO_REPLY'],
        'channels.whatsapp.fallback_template_id' => ['AI_ASSISTANT_FALLBACK_TEMPLATE'],
        'channels.whatsapp.booking_hook' => ['AI_ASSISTANT_BOOKING_HOOK'],
        'channels.whatsapp.session_window_hours' => ['AI_ASSISTANT_SESSION_WINDOW_HOURS'],
        'channels.whatsapp.max_message_chars' => ['AI_ASSISTANT_WHATSAPP_MAX_CHARS'],
        'channels.whatsapp.max_rows_in_message' => ['AI_ASSISTANT_WHATSAPP_MAX_ROWS'],
        'channels.whatsapp.show_sources' => ['AI_ASSISTANT_WHATSAPP_SOURCES'],
        'channels.web.enabled' => ['AI_ASSISTANT_WEB_ENABLED'],

        'exports.formats' => ['AI_ASSISTANT_EXPORT_FORMATS'],
        'exports.async_above_rows' => ['AI_ASSISTANT_EXPORT_ASYNC_ROWS'],
        'exports.directory' => ['AI_ASSISTANT_EXPORT_DIR'],
        'exports.file_name_prefix' => ['AI_ASSISTANT_EXPORT_PREFIX'],
        'exports.download_ttl_minutes' => ['AI_ASSISTANT_EXPORT_TTL'],
        'exports.require_confirmation' => ['AI_ASSISTANT_EXPORT_CONFIRM'],
        'exports.max_rows' => ['AI_ASSISTANT_EXPORT_MAX_ROWS'],

        'actions.confirmation_ttl_minutes' => ['AI_ASSISTANT_ACTION_TOKEN_TTL'],
        'outbox.max_attempts' => ['AI_ASSISTANT_OUTBOX_ATTEMPTS'],
        'outbox.batch' => ['AI_ASSISTANT_OUTBOX_BATCH'],
        'outbox.backoff_seconds' => ['AI_ASSISTANT_OUTBOX_BACKOFF'],
        'documents.allowed_mime' => ['AI_ASSISTANT_DOCUMENT_MIME'],
        'documents.chunk_tokens' => ['AI_ASSISTANT_CHUNK_TOKENS'],

        'audit.enabled' => ['AI_ASSISTANT_AUDIT_ENABLED'],
        'audit.store' => ['AI_ASSISTANT_AUDIT_STORE'],
        'timezone' => ['AI_ASSISTANT_TIMEZONE'],
    ];

    /** @var array<string,mixed> Hard floor — always available, even with no config file. */
    private const DEFAULTS = [
        'tenant' => ['column' => 'company_id', 'id' => null, 'legacy_table' => ''],
        'domain' => [
            'name' => '', 'assistant_name' => '', 'currency' => '', 'currency_position' => 'before',
            'locale' => 'en', 'language' => '', 'multilingual' => true,
            'system_prompt' => '', 'query_prompt' => '', 'custom_instructions' => '',
        ],
        'schema_policy' => [
            'mode' => 'denylist', 'allowed_tables' => [], 'blocked_tables' => [],
            'blocked_columns' => [], 'masked_columns' => [],
            'max_rows' => 200, 'default_limit' => 20,
            'include_own_tables' => false, 'require_tenant_scope' => true,
        ],
        'schema' => ['prune_stale' => false, 'auto_describe' => true, 'sample_rows' => 25],
        'semantic' => [
            'enabled' => true, 'auto_derive' => true, 'packs' => [], 'pack_file' => '',
            'max_dimensions' => 14, 'max_datasets' => 80, 'allow_raw_sql' => false,
            'raw_sql_roles' => ['admin', 'owner'],
        ],
        'reference' => ['enabled' => true, 'tables' => [], 'auto_suffixes' => [], 'max_values' => 50],
        'metrics' => [],
        'knowledge' => ['retrieval' => 'hybrid', 'top_k' => 5, 'fallback_latest' => false, 'min_similarity' => 0.2, 'self_training' => true],
        'features' => ['data_qa', 'documents', 'actions', 'charts', 'exports', 'reports', 'feedback', 'audit'],
        'limits' => ['max_rows' => 200, 'max_upload_mb' => 25, 'max_pages' => 300, 'retention_days' => 365, 'session_turns' => 10, 'session_ttl_minutes' => 60, 'memory_turns' => 10, 'store_rows' => 50],
        'rate_limit' => ['per_hour' => 60, 'per_day' => 0],
        'channels' => [
            'whatsapp' => ['enabled' => true, 'table_prefix' => 'whatsapp', 'auto_reply' => true, 'fallback_template_id' => 0, 'booking_hook' => true, 'session_window_hours' => 24, 'max_message_chars' => 4096, 'max_rows_in_message' => 15, 'show_sources' => true],
            'web' => ['enabled' => true],
        ],
        'exports' => [
            'formats' => ['csv', 'json'], 'async_above_rows' => 5000,
            'directory' => '', 'file_name_prefix' => 'ai-export', 'download_ttl_minutes' => 1440,
            'require_confirmation' => true, 'approval_roles' => ['admin', 'owner'],
            'host_exporters' => [], 'max_rows' => 100000,
        ],
        'documents' => ['allowed_mime' => [], 'chunk_tokens' => 800, 'chunk_overlap' => 100],
        'actions' => ['confirmation_ttl_minutes' => 30],
        'outbox' => ['max_attempts' => 5, 'backoff_seconds' => 60, 'batch' => 50],
        'audit' => ['enabled' => true],
        'session' => ['turns' => 10],
        'timezone' => 'UTC',
        'llm' => ['provider' => 'openai', 'model' => '', 'embedding_model' => '', 'vision_model' => '', 'base_url' => '', 'api_key' => '', 'timeout' => 30],
    ];

    private static ?array $merged = null;
    private static ?array $hostValues = null;
    private static array $overrides = [];
    private static ?string $sourceFile = null;
    private static bool $hostLoaded = false;
    private static bool $discovered = false;

    /** @var callable():array<string,mixed>|null dotted-path overrides from the host's settings store */
    private static $settingsLoader = null;
    /** @var array<string,mixed> */
    private static array $settingsValues = [];
    private static bool $settingsLoaded = false;
    /** Re-entrancy guard: the loader itself may read config (table prefix, tenant id). */
    private static bool $settingsLoading = false;

    /** Merge a host-supplied config array over the package defaults. */
    public static function load(array $values, bool $isHost = true): void
    {
        if ($isHost) {
            self::$hostValues = self::$hostValues === null ? $values : self::mergeDeep(self::$hostValues, $values);
            self::$hostLoaded = true;
        }
        self::$merged = null;
    }

    /** Read a host config file (published copy or an arbitrary path). */
    public static function loadFile(string $path, bool $isHost = true): void
    {
        if (!is_file($path)) {
            return;
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new \InvalidArgumentException('Config file "' . $path . '" must return an array.');
        }
        self::$sourceFile = $path;
        self::load($values, $isHost);
    }

    /** Highest precedence — wins over env and config files. */
    public static function set(string $path, mixed $value): void
    {
        self::pathSet(self::$overrides, $path, $value);
        self::$merged = null;
    }

    /**
     * Read behavioural settings from the host's own store (the package wires
     * its {prefix}_config table here; a host with another store can hand any
     * loader). The loader returns dotted paths, e.g.
     *   ['schema_policy.mode' => 'allowlist', 'charts.max_rows' => 500]
     * Loaded lazily on first read, then cached until refreshSettings().
     *
     * @param callable():array<string,mixed> $loader
     */
    public static function useSettings(callable $loader): void
    {
        self::$settingsLoader = $loader;
        self::$settingsLoaded = false;
        self::$settingsValues = [];
        self::$merged = null;
    }

    /** Drop the cached settings layer (call after the host saves settings). */
    public static function refreshSettings(): void
    {
        self::$settingsLoaded = false;
        self::$settingsValues = [];
        self::$merged = null;
    }

    public static function hasSettingsLayer(): bool
    {
        return self::$settingsLoader !== null;
    }

    /** @return array<string,mixed> what the settings store contributed (dotted paths) */
    public static function settingsValues(): array
    {
        return self::settingsLayer();
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $all = self::all();
        return self::pathGet($all, $path, $default ?? self::pathGet(self::DEFAULTS, $path));
    }

    public static function string(string $path, string $default = ''): string
    {
        $v = self::get($path, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public static function int(string $path, int $default = 0): int
    {
        $v = self::get($path, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function float(string $path, float $default = 0.0): float
    {
        $v = self::get($path, $default);
        return is_numeric($v) ? (float) $v : $default;
    }

    public static function bool(string $path, bool $default = false): bool
    {
        $v = self::get($path, null);
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));
        return !in_array($s, ['', '0', 'false', 'no', 'off'], true);
    }

    /** Comma-separated string or array — hosts can configure either way. */
    public static function list(string $path, array $default = []): array
    {
        $v = self::get($path, $default);
        if (is_string($v)) {
            $v = array_filter(array_map('trim', explode(',', $v)), fn ($x) => $x !== '');
        }
        return is_array($v) ? array_values($v) : $default;
    }

    public static function all(): array
    {
        if (self::$merged !== null) {
            return self::$merged;
        }

        self::discoverHostConfig();

        // Package default file resolves its own env fallbacks (legacy keys).
        $package = require dirname(__DIR__, 2) . '/config/ai-assistant.php';
        $merged = self::mergeDeep(self::DEFAULTS, is_array($package) ? $package : []);

        if (self::$hostValues !== null) {
            $merged = self::mergeDeep($merged, self::$hostValues);
        }

        foreach (self::settingsLayer() as $path => $value) {
            self::pathSet($merged, (string) $path, $value);
        }

        foreach (self::ENV_MAP as $path => $keys) {
            foreach ($keys as $key) {
                $raw = self::env($key);
                if ($raw === null) {
                    continue;
                }
                self::pathSet($merged, $path, self::castFromPackage($merged, $path, $raw));
                break;
            }
        }

        foreach (self::flattenOverrides() as $path => $value) {
            self::pathSet($merged, $path, $value);
        }

        return self::$merged = $merged;
    }

    /**
     * The settings-store layer, loaded once. A loader that throws is treated as
     * "no overrides": a broken settings row must not take the assistant down,
     * the values just fall back to the config file and env.
     *
     * @return array<string,mixed>
     */
    private static function settingsLayer(): array
    {
        if (self::$settingsLoader === null) {
            return [];
        }
        if (self::$settingsLoaded) {
            return self::$settingsValues;
        }
        // The loader reads config itself (table prefix, tenant id); answer those
        // reads from the layers already merged instead of recursing forever.
        if (self::$settingsLoading) {
            return [];
        }

        self::$settingsLoading = true;
        try {
            $values = (self::$settingsLoader)();
            self::$settingsValues = is_array($values) ? $values : [];
        } catch (\Throwable) {
            self::$settingsValues = [];
        } finally {
            self::$settingsLoading = false;
            self::$settingsLoaded = true;
        }

        return self::$settingsValues;
    }

    /**
     * Find the host's published copy of the config file, if any, so a host only
     * has to publish it — no boot call. Searched in order:
     *   AI_ASSISTANT_CONFIG_FILE, then <hostRoot>/config/ai-assistant.php where
     * hostRoot is the first ancestor directory that contains a vendor/ folder.
     * The package's own default file is never mistaken for a host override.
     */
    private static function discoverHostConfig(): void
    {
        if (self::$discovered) {
            return;
        }
        self::$discovered = true;

        EnvLoader::load();

        $packageReal = realpath(dirname(__DIR__, 2) . '/config/ai-assistant.php');
        $candidates = [];

        $explicit = self::env('AI_ASSISTANT_CONFIG_FILE');
        if ($explicit !== null) {
            $candidates[] = $explicit;
        }

        $dir = dirname(__DIR__, 3);
        for ($depth = 0; $depth < 12 && $dir !== '' && $dir !== '/'; $depth++) {
            if (is_dir($dir . '/vendor')) {
                $candidates[] = $dir . '/config/ai-assistant.php';
                break;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        foreach ($candidates as $path) {
            $real = realpath($path);
            if ($real === false || $real === $packageReal) {
                continue;
            }
            self::loadFile($real);
            return;
        }
    }

    /** Which file the host config came from, when a file was used. */
    public static function sourceFile(): ?string
    {
        return self::$sourceFile;
    }

    public static function isHostConfigLoaded(): bool
    {
        return self::$hostLoaded;
    }

    /** True when the merged value for a path came from an env variable. */
    public static function envIsSet(string $path): bool
    {
        foreach (self::ENV_MAP[$path] ?? [] as $key) {
            if (self::env($key) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Which layer supplies a path today: 'env', 'database', 'set', 'file' or
     * 'default'. Approximate for 'file' (the host file may not name the path),
     * exact for the other four — good enough for a settings screen badge.
     */
    public static function sourceOf(string $path): string
    {
        if (self::envIsSet($path)) {
            return 'env';
        }
        if (array_key_exists($path, self::settingsLayer())) {
            return 'database';
        }
        if (array_key_exists($path, self::flattenOverrides())) {
            return 'set';
        }
        return self::$hostLoaded ? 'file' : 'default';
    }

    /** Discard everything resolved so far — used by tests and by late host boot. */
    public static function reset(): void
    {
        self::$merged = null;
        self::$hostValues = null;
        self::$hostLoaded = false;
        self::$overrides = [];
        self::$sourceFile = null;
        self::$discovered = false;
        self::$settingsLoader = null;
        self::$settingsValues = [];
        self::$settingsLoaded = false;
        self::$settingsLoading = false;
    }

    /** @return array<string,mixed> */
    private static function flattenOverrides(): array
    {
        $out = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$out): void {
            foreach ($node as $k => $v) {
                $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
                if (is_array($v) && self::isAssoc($v)) {
                    $walk($v, $path);
                } else {
                    $out[$path] = $v;
                }
            }
        };
        $walk(self::$overrides, '');
        return $out;
    }

    private static function castFromPackage(array $merged, string $path, string $raw): mixed
    {
        $current = self::pathGet($merged, $path);
        if (is_bool($current)) {
            return !in_array(strtolower(trim($raw)), ['', '0', 'false', 'no', 'off'], true);
        }
        if (is_int($current)) {
            return (int) $raw;
        }
        if (is_float($current)) {
            return (float) $raw;
        }
        if (is_array($current)) {
            return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($x) => $x !== ''));
        }
        return $raw;
    }

    private static function env(string $key): ?string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null) {
            return null;
        }
        $v = (string) $v;
        return $v === '' ? null : $v;
    }

    private static function pathGet(array $node, string $path, mixed $default = null): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    private static function pathSet(array &$node, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $ref = &$node;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    private static function mergeDeep(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && self::isAssoc($v) && self::isAssoc($base[$k])) {
                $base[$k] = self::mergeDeep($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private static function isAssoc(array $a): bool
    {
        return $a !== [] && array_keys($a) !== range(0, count($a) - 1);
    }
}
