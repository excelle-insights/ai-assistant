<?php

/**
 * Phinx config for the package migrations, for hosts that prefer to drive their
 * own phinx binary instead of the wrapper:
 *
 *   php vendor/bin/phinx migrate -c vendor/excelle-insights/ai-assistant/phinx.php
 *
 * It reads the same host .env keys as migrate.php does — DB_HOST / DB_NAME /
 * DB_USER / DB_PASSWORD / DB_PORT / DB_ADAPTER, plus AI_ASSISTANT_DB_ENV to
 * pick which of the three environments below phinx defaults to. mysql is the
 * adapter every host runs today; sqlite (DB_NAME = a file path) is verified.
 * The migration classes themselves use the adapter-agnostic Phinx API.
 *
 * Prefer `php vendor/excelle-insights/ai-assistant/migrate.php` — it needs no
 * knowledge of phinx and forwards every flag to it.
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Support\EnvLoader;

$dir = __DIR__;
while (!file_exists($dir . '/vendor/autoload.php')) {
    $parent = dirname($dir);
    if ($parent === $dir) {
        fwrite(STDERR, "Unable to find vendor/autoload.php\n");
        exit(1);
    }
    $dir = $parent;
}

require_once $dir . '/vendor/autoload.php';

EnvLoader::load($dir);

/** Read a host env key however the host exposes it (dotenv, php-env, real env). */
$read = static function (string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return ($value === false || $value === null || $value === '') ? $default : (string) $value;
};

$adapter = strtolower((string) $read('DB_ADAPTER', 'mysql'));
$environment = strtolower((string) $read('AI_ASSISTANT_DB_ENV', 'development'));
$port = $read('DB_PORT', ['mysql' => '3306', 'pgsql' => '5432', 'sqlsrv' => '1433'][$adapter] ?? '3306');

if ($read('DB_NAME') === null) {
    fwrite(STDERR, "Database config incomplete (.env missing DB_NAME).\n");
    exit(1);
}
// sqlite names its database as a file path and has no server to log into.
if ($adapter !== 'sqlite' && ($read('DB_HOST') === null || $read('DB_USER') === null)) {
    fwrite(STDERR, "Database config incomplete (.env missing DB_HOST/DB_USER).\n");
    exit(1);
}

/** One phinx environment, built from the host's own DB_* keys. */
$connection = static function (string $fallbackName) use ($read, $adapter, $port): array {
    if ($adapter === 'sqlite') {
        return [
            'adapter' => 'sqlite',
            'name' => (string) $read('DB_NAME', $fallbackName),
            'suffix' => '',
        ];
    }

    return array_filter([
        'adapter' => $adapter,
        'host' => $read('DB_HOST', 'localhost'),
        'name' => $read('DB_NAME', $fallbackName),
        'user' => $read('DB_USER', 'root'),
        'pass' => $read('DB_PASSWORD', ''),
        'port' => $port,
        'charset' => $adapter === 'mysql' ? 'utf8mb4' : null,
        'schema' => $adapter === 'mysql' ? null : 'public',
    ], static fn ($v): bool => $v !== null);
};

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/database/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => $environment,
        'production' => $connection('production_db'),
        'development' => $connection('development_db'),
        'testing' => $connection('testing_db'),
    ],
    'version_order' => 'creation',
];
