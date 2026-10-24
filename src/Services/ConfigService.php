<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use PDO;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\ConfigCatalog;
use ExcelleInsights\AiAssistant\Support\Db;

/**
 * Behavioural settings stored in the host database, one row per company in
 * {prefix}_config (company_id = 0 is the deployment-wide row).
 *
 * Each column holds one section as JSON keyed by the setting's dotted path,
 * which keeps a section readable straight from SQL:
 *
 *   SELECT schema_policy_json FROM ai_assistant_config WHERE company_id = 0;
 *   {"schema_policy.mode":"allowlist","schema_policy.allowed_tables":["orders"]}
 *
 * ConfigCatalog is the whitelist: save() rejects a path it does not know, so a
 * typo cannot write a key no code reads. Values are coerced to the declared
 * type (yes/no/on/off/1/0 are accepted for booleans, CSV or arrays for lists),
 * then assistant code reads them through Config — AssistantApi attaches
 * overrides() with Config::useSettings() so nothing else has to know a
 * settings table exists.
 *
 * What never goes here: secrets and anything needed before the connection
 * exists (see the ConfigCatalog docblock). The table is plaintext JSON.
 *
 * Storage is three protected seams, so the pure parts (flatten, normalise,
 * merge, describe) run with no database.
 */
class ConfigService
{
    private ?string $table = null;
    private ?bool $present = null;

    public function __construct(private ?PDO $pdo = null)
    {
    }

    // ── reading ──────────────────────────────────────────────────────────────

    /**
     * The overrides this tenant has stored, as dotted paths. Global (company 0)
     * is read first and a company row wins key-by-key.
     *
     * @return array<string,mixed>
     */
    public function overrides(int $companyId = 0): array
    {
        if (!$this->present()) {
            return [];
        }

        $rows = [];
        if ($companyId > 0) {
            $global = $this->loadRow(0);
            if ($global !== null) {
                $rows[] = $global;
            }
        }
        $own = $this->loadRow($companyId);
        if ($own !== null) {
            $rows[] = $own;
        }

        $out = [];
        foreach ($rows as $row) {
            foreach (ConfigCatalog::columns() as $column) {
                foreach ($this->decodeColumn($row[$column] ?? null) as $path => $value) {
                    if (ConfigCatalog::columnFor((string) $path) === $column) {
                        $out[(string) $path] = $value;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Every editable setting with its current value and which layer supplies
     * it ('database', 'env', 'file', 'set' or 'default').
     *
     * `value` is the company's own override when it has one; otherwise it is
     * the process config, which is exact whenever the API runs as that tenant.
     *
     * @return array<string,array<string,mixed>> path => info
     */
    public function effective(int $companyId = 0): array
    {
        $overrides = $this->overrides($companyId);
        $out = [];
        foreach (ConfigCatalog::paths() as $path) {
            $has = array_key_exists($path, $overrides);
            $out[$path] = [
                'value' => $has ? $overrides[$path] : Config::get($path),
                'default' => ConfigCatalog::get($path)['default'] ?? null,
                'source' => $has ? 'database' : Config::sourceOf($path),
                'overridden' => $has,
            ];
        }

        return $out;
    }

    /**
     * The settings screen: sections, their settings, allowed values and what is
     * set today. Safe to expose to an admin UI — it contains no secrets.
     *
     * @return array<string,mixed>
     */
    public function describe(int $companyId = 0): array
    {
        $overrides = $this->overrides($companyId);
        $sections = [];
        foreach (ConfigCatalog::GROUPS as $column => $group) {
            $items = [];
            foreach (ConfigCatalog::pathsForColumn($column) as $path) {
                $meta = ConfigCatalog::get($path) ?? [];
                $has = array_key_exists($path, $overrides);
                $items[] = [
                    'path' => $path,
                    'label' => (string) ($meta['label'] ?? $path),
                    'help' => (string) ($meta['help'] ?? ''),
                    'type' => (string) ($meta['type'] ?? 'string'),
                    'options' => $meta['options'] ?? null,
                    'min' => $meta['min'] ?? null,
                    'max' => $meta['max'] ?? null,
                    'default' => $meta['default'] ?? null,
                    'value' => $has ? $overrides[$path] : Config::get($path),
                    'source' => $has ? 'database' : Config::sourceOf($path),
                ];
            }
            $sections[] = [
                'column' => $column,
                'label' => (string) ($group['label'] ?? $column),
                'help' => (string) ($group['help'] ?? ''),
                'settings' => $items,
            ];
        }

        return [
            'table' => $this->tableName(),
            'company_id' => $companyId,
            'editable' => count(ConfigCatalog::paths()),
            'sections' => $sections,
        ];
    }

    // ── writing ──────────────────────────────────────────────────────────────

    /**
     * Validate, coerce and store settings. A null value removes an override, so
     * the path falls back to the config file/env again. Nested input is accepted
     * (`['schema_policy' => ['mode' => 'allowlist']]`) and flattened.
     *
     * @param array<string,mixed> $settings
     * @return array{saved:array<int,string>,cleared:array<int,string>,errors:array<string,string>,unknown:array<int,string>}
     */
    public function save(array $settings, int $companyId = 0, ?string $updatedBy = null): array
    {
        $flat = self::flatten($settings);
        $patch = [];
        $cleared = [];
        $errors = [];
        $unknown = [];

        foreach ($flat as $path => $value) {
            if (!ConfigCatalog::has($path)) {
                $unknown[] = $path;
                continue;
            }
            if ($value === null) {
                $cleared[] = $path;
                continue;
            }
            $normalised = self::normalise($path, $value);
            if (isset($normalised['error'])) {
                $errors[$path] = (string) $normalised['error'];
                continue;
            }
            $patch[$path] = $normalised['value'];
        }

        if ($patch === [] && $cleared === []) {
            return ['saved' => [], 'cleared' => [], 'errors' => $errors, 'unknown' => $unknown];
        }

        $existing = $this->present() ? ($this->loadRow($companyId) ?? []) : [];
        $columns = [];
        foreach (ConfigCatalog::columns() as $column) {
            $values = $this->decodeColumn($existing[$column] ?? null);
            foreach (array_keys($values) as $path) {
                if (ConfigCatalog::columnFor((string) $path) !== $column) {
                    unset($values[$path]);
                }
            }
            foreach ($patch as $path => $value) {
                if (ConfigCatalog::columnFor($path) === $column) {
                    $values[$path] = $value;
                }
            }
            foreach ($cleared as $path) {
                if (ConfigCatalog::columnFor($path) === $column) {
                    unset($values[$path]);
                }
            }
            $columns[$column] = $values === [] ? null : self::encode($values);
        }

        $this->saveRow($companyId, $columns, $updatedBy);

        return [
            'saved' => array_keys($patch),
            'cleared' => $cleared,
            'errors' => $errors,
            'unknown' => $unknown,
        ];
    }

    /**
     * Remove overrides by path, leaving the rest of each section alone.
     *
     * @param array<int,string> $paths
     * @return array{cleared:array<int,string>,unknown:array<int,string>}
     */
    public function forget(array $paths, int $companyId = 0, ?string $updatedBy = null): array
    {
        $patch = [];
        $unknown = [];
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            if (!ConfigCatalog::has($path)) {
                $unknown[] = $path;
                continue;
            }
            $patch[$path] = null;
        }

        $result = $patch === []
            ? ['cleared' => [], 'unknown' => $unknown]
            : $this->save($patch, $companyId, $updatedBy);

        return ['cleared' => $result['cleared'], 'unknown' => array_values(array_unique(array_merge($unknown, $result['unknown'] ?? [])))];
    }

    // ── pure logic ───────────────────────────────────────────────────────────

    /**
     * Flatten nested input to dotted paths, stopping at any node that is itself
     * a known setting (a list or JSON value must stay one value).
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public static function flatten(array $settings): array
    {
        $out = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$out): void {
            foreach ($node as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                if (is_array($value) && !ConfigCatalog::has($path)) {
                    $walk($value, $path);
                } else {
                    $out[$path] = $value;
                }
            }
        };
        $walk($settings, '');

        return $out;
    }

    /**
     * Coerce one value to the type its catalog entry declares.
     *
     * @return array{value?:mixed,error?:string}
     */
    public static function normalise(string $path, mixed $value): array
    {
        $meta = ConfigCatalog::get($path);
        if ($meta === null) {
            return ['error' => 'Unknown setting "' . $path . '".'];
        }
        $type = (string) ($meta['type'] ?? 'string');

        switch ($type) {
            case 'bool':
                $bool = self::toBool($value);
                return $bool === null ? ['error' => 'Expected yes/no (or true/false, 1/0, on/off).'] : ['value' => $bool];

            case 'int':
                if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric(trim($value)))) {
                    return ['error' => 'Expected a whole number.'];
                }
                $int = (int) $value;
                return self::within($meta, $int) ?? ['value' => $int];

            case 'float':
                if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric(trim($value)))) {
                    return ['error' => 'Expected a number.'];
                }
                $float = (float) $value;
                return self::within($meta, $float) ?? ['value' => $float];

            case 'list':
                if (is_string($value)) {
                    $value = array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($x): bool => $x !== ''));
                }
                if (!is_array($value)) {
                    return ['error' => 'Expected a comma-separated list or an array.'];
                }
                $list = [];
                foreach ($value as $item) {
                    if (is_array($item) || is_object($item)) {
                        return ['error' => 'A list may only contain plain values.'];
                    }
                    $item = trim((string) $item);
                    if ($item !== '' && !in_array($item, $list, true)) {
                        $list[] = $item;
                    }
                }
                return ['value' => $list];

            case 'enum':
                $options = array_map('strval', (array) ($meta['options'] ?? []));
                $given = is_scalar($value) ? trim((string) $value) : '';
                foreach ($options as $option) {
                    if (strcasecmp($option, $given) === 0) {
                        return ['value' => $option];
                    }
                }
                return ['error' => 'Expected one of: ' . implode(', ', $options) . '.'];

            case 'json':
                if (is_string($value)) {
                    $value = trim($value) === '' ? [] : json_decode($value, true);
                }
                if (!is_array($value)) {
                    return ['error' => 'Expected a JSON object or array.'];
                }
                return ['value' => $value];

            default:
                if (is_array($value) || is_object($value)) {
                    return ['error' => 'Expected plain text.'];
                }
                return ['value' => trim((string) $value)];
        }
    }

    // ── storage seams (override these in tests) ──────────────────────────────

    protected function loadRow(int $companyId): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        $table = Db::requireTable($this->pdo, 'config');
        $stmt = $this->pdo->prepare('SELECT * FROM `' . $table . '` WHERE `company_id` = :c ORDER BY `id` ASC LIMIT 1');
        $stmt->execute(['c' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param array<string,string|null> $columns column => encoded JSON (or null to clear) */
    protected function saveRow(int $companyId, array $columns, ?string $updatedBy): void
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('This config service has no database connection, so settings cannot be saved.');
        }
        $table = Db::requireTable($this->pdo, 'config');
        $insert = ['company_id' => $companyId] + $columns + [
            'updated_by' => $updatedBy,
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ];
        Db::upsert($this->pdo, $table, $insert, array_merge(array_keys($columns), ['updated_by', 'updated_at']));
    }

    protected function hasTable(): bool
    {
        return $this->pdo !== null && Db::tableExists($this->pdo, 'config');
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function present(): bool
    {
        if ($this->present === null) {
            $this->present = $this->hasTable();
        }

        return $this->present;
    }

    private function tableName(): string
    {
        return $this->table ??= Db::table('config');
    }

    /** @return array<string,mixed> */
    private function decodeColumn(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $values */
    private static function encode(array $values): string
    {
        $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }

    /** @return array{error:string}|null */
    private static function within(array $meta, int|float $value): ?array
    {
        if (isset($meta['min']) && $value < $meta['min']) {
            return ['error' => 'Must be at least ' . $meta['min'] . '.'];
        }
        if (isset($meta['max']) && $value > $meta['max']) {
            return ['error' => 'Must be at most ' . $meta['max'] . '.'];
        }

        return null;
    }

    private static function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value == 0 ? false : true;
        }
        if (!is_string($value)) {
            return null;
        }
        $s = strtolower(trim($value));
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }
}
