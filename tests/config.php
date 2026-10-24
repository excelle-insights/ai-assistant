<?php

/**
 * Pure-logic test harness for DB-backed settings.
 *
 *   php tests/config.php
 *
 * Runs with NO database and NO network: ConfigService's storage seams are
 * swapped for an in-memory array, and Config's settings layer is a closure.
 * Prints "N passed, M failed" and exits non-zero when any check fails.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ExcelleInsights\AiAssistant\Services\ConfigService;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\ConfigCatalog;

final class Harness
{
    public static int $passed = 0;
    public static int $failed = 0;

    public static function ok(bool $condition, string $label): void
    {
        if ($condition) {
            self::$passed++;
        } else {
            self::$failed++;
            echo "  FAIL: {$label}\n";
        }
    }

    public static function eq(mixed $actual, mixed $expected, string $label): void
    {
        $ok = $actual === $expected;
        self::ok($ok, $label . ($ok ? '' : " (expected " . self::dump($expected) . ", got " . self::dump($actual) . ")"));
    }

    private static function dump(mixed $v): string
    {
        return is_scalar($v) || $v === null ? var_export($v, true) : (string) json_encode($v);
    }
}

function section(string $name): void
{
    echo "\n== {$name} ==\n";
}

/** In-memory config rows: company_id => [column => json]. */
final class MemoryConfig extends ConfigService
{
    /** @var array<int,array<string,string|null>> */
    public array $rows = [];
    /** @var array<int,int> */
    public array $writes = [];

    protected function hasTable(): bool
    {
        return true;
    }

    protected function loadRow(int $companyId): ?array
    {
        return $this->rows[$companyId] ?? null;
    }

    protected function saveRow(int $companyId, array $columns, ?string $updatedBy): void
    {
        $this->writes[] = $companyId;
        $this->rows[$companyId] = $columns + ['updated_by' => $updatedBy];
    }
}

// ── catalog integrity ────────────────────────────────────────────────────────
section('catalog');

$paths = ConfigCatalog::paths();
Harness::ok(count($paths) > 100, 'catalog lists the editable settings');
Harness::eq(count($paths), count(array_unique($paths)), 'no duplicate paths');

$badColumns = [];
$badDefaults = [];
$badEnums = [];
foreach ($paths as $path) {
    $meta = ConfigCatalog::get($path) ?? [];
    $column = (string) ($meta['column'] ?? '');
    if (!isset(ConfigCatalog::GROUPS[$column])) {
        $badColumns[] = $path;
    }
    $type = (string) ($meta['type'] ?? '');
    if (!in_array($type, ['bool', 'int', 'float', 'string', 'list', 'json', 'enum'], true)) {
        $badDefaults[] = $path . ' (type ' . $type . ')';
    }
    $default = $meta['default'] ?? null;
    if (in_array($type, ['bool', 'int', 'float', 'list', 'json'], true)) {
        $res = ConfigService::normalise($path, $default);
        if (isset($res['error'])) {
            $badDefaults[] = $path . ' (' . $res['error'] . ')';
        }
    }
    if ($type === 'enum') {
        $options = (array) ($meta['options'] ?? []);
        if ($options === [] || !in_array($default, $options, true)) {
            $badEnums[] = $path;
        }
    }
    if (!isset($meta['label']) || trim((string) $meta['label']) === '') {
        $badDefaults[] = $path . ' (no label)';
    }
}
Harness::eq($badColumns, [], 'every path names a known column');
Harness::eq($badDefaults, [], 'every default round-trips through normalise and has a label');
Harness::eq($badEnums, [], 'every enum default is one of its options');

$grouped = [];
foreach (ConfigCatalog::columns() as $column) {
    $grouped = array_merge($grouped, ConfigCatalog::pathsForColumn($column));
}
sort($grouped);
$sorted = $paths;
sort($sorted);
Harness::eq($grouped, $sorted, 'pathsForColumn covers every path exactly once');

// ── normalise ────────────────────────────────────────────────────────────────
section('normalise');

Harness::eq(ConfigService::normalise('schema_policy.mode', 'ALLOWLIST')['value'], 'allowlist', 'enum is case-insensitive and canonical');
Harness::ok(isset(ConfigService::normalise('schema_policy.mode', 'sometimes')['error']), 'unknown enum is rejected');
Harness::eq(ConfigService::normalise('exports.csv_newline', 'lf')['value'], 'LF', 'enum keeps the option casing');

foreach (['yes' => true, 'no' => false, 'on' => true, 'off' => false, '1' => true, '0' => false, 'TRUE' => true] as $input => $expected) {
    Harness::eq(ConfigService::normalise('schema.prune_stale', $input)['value'] ?? null, $expected, 'bool accepts ' . $input);
}
Harness::eq(ConfigService::normalise('schema.prune_stale', true)['value'], true, 'bool accepts a real boolean');
Harness::ok(isset(ConfigService::normalise('schema.prune_stale', 'maybe')['error']), 'bool rejects nonsense');

Harness::eq(ConfigService::normalise('schema_policy.max_rows', '250')['value'], 250, 'int accepts a numeric string');
Harness::ok(isset(ConfigService::normalise('schema_policy.max_rows', 'many')['error']), 'int rejects text');
Harness::ok(isset(ConfigService::normalise('schema_policy.max_rows', 0)['error']), 'int honours its minimum');
Harness::ok(isset(ConfigService::normalise('knowledge.min_similarity', 1.5)['error']), 'float honours its maximum');
Harness::eq(ConfigService::normalise('knowledge.min_similarity', '0.35')['value'], 0.35, 'float accepts a numeric string');

Harness::eq(ConfigService::normalise('schema_policy.allowed_tables', 'orders, customers ,,')['value'], ['orders', 'customers'], 'list accepts CSV and trims');
Harness::eq(ConfigService::normalise('schema_policy.allowed_tables', ['orders', 'orders', ' '])['value'], ['orders'], 'list dedupes and drops blanks');
Harness::ok(isset(ConfigService::normalise('schema_policy.allowed_tables', [['orders']])['error']), 'list rejects nested arrays');

Harness::eq(ConfigService::normalise('metrics.custom', '{"a":1}')['value'], ['a' => 1], 'json decodes a string');
Harness::eq(ConfigService::normalise('metrics.custom', [])['value'], [], 'json accepts an empty array');
Harness::ok(isset(ConfigService::normalise('metrics.custom', '{bad')['error']), 'json rejects invalid JSON');
Harness::ok(isset(ConfigService::normalise('domain.currency', ['x'])['error']), 'string rejects arrays');
Harness::eq(ConfigService::normalise('domain.currency', '  KSh ')['value'], 'KSh', 'string trims');

// ── flatten ──────────────────────────────────────────────────────────────────
section('flatten');

Harness::eq(
    ConfigService::flatten(['schema_policy' => ['mode' => 'all', 'allowed_tables' => ['a', 'b']]]),
    ['schema_policy.mode' => 'all', 'schema_policy.allowed_tables' => ['a', 'b']],
    'nested input becomes dotted paths and lists stay leaves'
);
Harness::eq(ConfigService::flatten(['features' => ['data_qa']]), ['features' => ['data_qa']], 'a known list path stays one value');
Harness::eq(
    ConfigService::flatten(['metrics' => ['custom' => [['key' => 'orders', 'table' => 'orders']]]]),
    ['metrics.custom' => [['key' => 'orders', 'table' => 'orders']]],
    'a json path does not get walked into'
);

// ── save / read / clear ──────────────────────────────────────────────────────
section('storage');

$service = new MemoryConfig();
$result = $service->save([
    'schema_policy.mode' => 'allowlist',
    'schema_policy.allowed_tables' => 'orders, customers',
    'charts.max_rows' => '900',
], 0, 'admin');

Harness::eq($result['saved'], ['schema_policy.mode', 'schema_policy.allowed_tables', 'charts.max_rows'], 'save reports what it wrote');
Harness::eq($result['errors'], [], 'no errors for valid input');
Harness::eq($result['unknown'], [], 'no unknown paths');
Harness::eq($service->overrides(0), [
    'schema_policy.mode' => 'allowlist',
    'schema_policy.allowed_tables' => ['orders', 'customers'],
    'charts.max_rows' => 900,
], 'overrides reads back what was saved, coerced');
Harness::eq($service->writes, [0], 'one row written for company 0');

$bad = $service->save([
    'schema_policy.mode' => 'sometimes',
    'schema_policy.include_own_tables' => 'no',
    'not.a.setting' => 1,
    'schema_policy.default_limit' => '10',
], 0);
Harness::eq(array_keys($bad['errors']), ['schema_policy.mode'], 'invalid value is reported per path');
Harness::eq($bad['unknown'], ['not.a.setting'], 'unknown path is rejected, not stored');
Harness::eq($bad['saved'], ['schema_policy.include_own_tables', 'schema_policy.default_limit'], 'valid siblings still save');
Harness::eq($service->overrides(0)['schema_policy.mode'], 'allowlist', 'a rejected value leaves the old one in place');
Harness::eq($service->overrides(0)['schema_policy.include_own_tables'], false, '"no" is stored as false');
Harness::eq($service->overrides(0)['schema_policy.default_limit'], 10, 'a numeric string is stored as an int');

$cleared = $service->save(['charts.max_rows' => null], 0);
Harness::eq($cleared['cleared'], ['charts.max_rows'], 'null clears an override');
Harness::eq(array_key_exists('charts.max_rows', $service->overrides(0)), false, 'a cleared path disappears');
Harness::eq($service->overrides(0)['schema_policy.mode'], 'allowlist', 'clearing one path leaves the section alone');

$forgotten = $service->forget(['schema_policy.mode', 'nope'], 0);
Harness::eq($forgotten['cleared'], ['schema_policy.mode'], 'forget clears known paths');
Harness::eq($forgotten['unknown'], ['nope'], 'forget reports unknown paths');

// company row wins over the global row, key by key
$service->save(['schema_policy.mode' => 'allowlist', 'schema_policy.max_rows' => 111], 0);
$service->save(['schema_policy.mode' => 'denylist'], 7);
Harness::eq($service->overrides(7)['schema_policy.mode'], 'denylist', 'company row overrides global mode');
Harness::eq($service->overrides(7)['schema_policy.max_rows'], 111, 'global value still applies for keys the company did not set');
Harness::eq($service->overrides(8)['schema_policy.mode'], 'allowlist', 'another company keeps the global value');
Harness::eq($service->overrides(0)['schema_policy.mode'], 'allowlist', 'reading the global row is not affected');

// describe
Config::reset();
$desc = $service->describe(7);
Harness::eq($desc['company_id'], 7, 'describe is scoped to the company');
Harness::eq(count($desc['sections']), count(ConfigCatalog::GROUPS), 'describe returns one section per column');
Harness::eq($desc['editable'], count($paths), 'describe counts the editable paths');
$flat = [];
foreach ($desc['sections'] as $s) {
    foreach ($s['settings'] as $item) {
        $flat[$item['path']] = $item;
    }
}
Harness::eq(count($flat), count($paths), 'every setting appears in exactly one section');
Harness::eq($flat['schema_policy.mode']['source'], 'database', 'a stored path is marked database');
Harness::eq($flat['schema_policy.mode']['value'], 'denylist', 'describe shows the current value');
Harness::eq($flat['charts.max_rows']['source'], 'default', 'an unstored path falls back to default');

// ── Config layer precedence ─────────────────────────────────────────────────
section('config layer');

Config::reset();
Config::load(['schema_policy' => ['mode' => 'allowlist', 'max_rows' => 50]]);
Config::useSettings(static fn (): array => ['schema_policy.mode' => 'denylist', 'charts.max_rows' => 777]);
Harness::eq(Config::string('schema_policy.mode'), 'denylist', 'settings beat a host config array');
Harness::eq(Config::int('schema_policy.max_rows'), 50, 'untouched file values survive the settings layer');
Harness::eq(Config::int('charts.max_rows'), 777, 'settings reach nested paths');
Harness::eq(Config::sourceOf('schema_policy.mode'), 'database', 'sourceOf reports database');
Harness::eq(Config::sourceOf('schema_policy.max_rows'), 'file', 'sourceOf reports file');
Harness::eq(Config::settingsValues()['charts.max_rows'], 777, 'settingsValues exposes the loaded overrides');

Config::set('schema_policy.mode', 'all');
Harness::eq(Config::string('schema_policy.mode'), 'all', 'Config::set still wins over settings');

Config::reset();
Config::useSettings(static fn (): array => ['schema_policy.mode' => 'denylist']);
$_ENV['AI_ASSISTANT_TABLE_POLICY_MODE'] = 'allowlist';
Harness::eq(Config::string('schema_policy.mode'), 'allowlist', 'env still wins over settings');
Harness::eq(Config::sourceOf('schema_policy.mode'), 'env', 'sourceOf reports env');
unset($_ENV['AI_ASSISTANT_TABLE_POLICY_MODE'], $_SERVER['AI_ASSISTANT_TABLE_POLICY_MODE']);
putenv('AI_ASSISTANT_TABLE_POLICY_MODE');
Config::reset();
Config::useSettings(static fn (): array => ['schema_policy.mode' => 'denylist']);
Harness::eq(Config::string('schema_policy.mode'), 'denylist', 'removing the env key lets settings apply again');

// refresh + re-entrancy
$bag = ['charts.max_rows' => 10];
Config::reset();
Config::useSettings(static function () use (&$bag): array {
    // A loader that reads config (as a table-prefix lookup would) must not recurse.
    $seen = Config::int('charts.max_rows', -1);
    Harness::ok($seen !== -1, 'loader can read config without recursing');
    return $bag;
});
Harness::eq(Config::int('charts.max_rows'), 10, 'loader values apply');
$bag = ['charts.max_rows' => 20];
Harness::eq(Config::int('charts.max_rows'), 10, 'values are cached until refresh');
Config::refreshSettings();
Harness::eq(Config::int('charts.max_rows'), 20, 'refreshSettings picks up a new value');

// lists and bools from the database
Config::reset();
Config::useSettings(static fn (): array => [
    'features' => 'data_qa, charts',
    'schema_policy.require_tenant_scope' => 'no',
    'knowledge.top_k' => '9',
]);
Harness::eq(Config::list('features'), ['data_qa', 'charts'], 'a CSV list from the database reads as a list');
Harness::eq(Config::bool('schema_policy.require_tenant_scope'), false, 'a "no" from the database reads as false');
Harness::eq(Config::int('knowledge.top_k'), 9, 'an int from the database reads as an int');

Config::reset();
Harness::eq(Config::hasSettingsLayer(), false, 'reset removes the settings layer');
Harness::eq(Config::sourceOf('charts.max_rows'), 'default', 'reset returns to the packaged default');

echo "\n", Harness::$passed, ' passed, ', Harness::$failed, " failed\n";
exit(Harness::$failed === 0 ? 0 : 1);
