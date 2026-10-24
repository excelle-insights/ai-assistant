#!/usr/bin/env php
<?php

/**
 * Run the package migrations against the HOST database — the one command a host
 * needs after `composer require` (or `composer update`), whatever its framework.
 *
 *   php vendor/excelle-insights/ai-assistant/migrate.php
 *   php vendor/excelle-insights/ai-assistant/migrate.php --dry-run   # print the SQL, change nothing
 *   php vendor/excelle-insights/ai-assistant/migrate.php --change 1
 *
 * Credentials come from the host .env (the DB_* keys the host already uses).
 * MySQL is what every host runs today; SQLite is verified too. The migration
 * classes use the adapter-agnostic Phinx API, so DB_ADAPTER can name another —
 * note the package's own runtime queries are written in MySQL/SQLite quoting.
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Support\EnvLoader;

// Resolve project root + autoload by walking up (robust — not CWD-dependent).
$dir = __DIR__;
while (!file_exists($dir . '/vendor/autoload.php')) {
    $parent = dirname($dir);
    if ($parent === $dir) {
        fwrite(STDERR, "Unable to find vendor/autoload.php\n");
        exit(1);
    }
    $dir = $parent;
}
$projectRoot = rtrim($dir, '/') . '/';

require_once $projectRoot . 'vendor/autoload.php';

EnvLoader::load($projectRoot);

/** Read a host env key however the host exposes it (dotenv, php-env, real env). */
function aiAssistantEnv(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return ($value === false || $value === null || $value === '') ? $default : (string) $value;
}

/** A PHP literal for the generated config file — a password with a quote stays a string. */
function aiAssistantLiteral(?string $value): string
{
    return var_export((string) $value, true);
}

$adapter = strtolower((string) aiAssistantEnv('DB_ADAPTER', 'mysql'));
$host = aiAssistantEnv('DB_HOST');
$dbname = aiAssistantEnv('DB_NAME');
$user = aiAssistantEnv('DB_USER');
$password = aiAssistantEnv('DB_PASSWORD', '');
$environment = strtolower((string) aiAssistantEnv('AI_ASSISTANT_DB_ENV', 'development'));

// The port each adapter assumes when the host does not name one.
$defaultPorts = ['mysql' => '3306', 'pgsql' => '5432', 'sqlsrv' => '1433'];
$port = (string) aiAssistantEnv('DB_PORT', $defaultPorts[$adapter] ?? '3306');

if ($dbname === null) {
    fwrite(STDERR, "Database config incomplete (.env missing DB_NAME).\n");
    exit(1);
}
// sqlite names its database as a file path and has no server to log into.
if ($adapter !== 'sqlite' && ($host === null || $user === null)) {
    fwrite(STDERR, "Database config incomplete (.env missing DB_HOST/DB_USER"
        . ($adapter === 'mysql' ? ', DB_PASSWORD' : '') . ").\n");
    exit(1);
}

$phinxPath = $projectRoot . 'vendor/bin/phinx';
if (!file_exists($phinxPath)) {
    fwrite(STDERR, "Phinx not found at {$phinxPath}\n"
        . "Install the dev dependency (composer require --dev robmorgan/phinx) "
        . "or run your own phinx with -c vendor/excelle-insights/ai-assistant/phinx.php.\n");
    exit(1);
}

$prefix = (string) aiAssistantEnv('AI_ASSISTANT_TABLE_PREFIX', (string) aiAssistantEnv('AI_WHATSAPP_TABLE_PREFIX', 'ai_assistant'));
$legacy = (string) aiAssistantEnv('AI_WHATSAPP_TABLE_PREFIX', 'ai_whatsapp');
$migrationsDir = __DIR__ . '/database/migrations';

$connection = $adapter === 'sqlite'
    ? "            'adapter' => 'sqlite',\n"
        . "            'name' => " . aiAssistantLiteral($dbname) . ",\n"
        . "            'suffix' => '',"
    : "            'adapter' => " . aiAssistantLiteral($adapter) . ",\n"
        . "            'host' => " . aiAssistantLiteral($host ?? 'localhost') . ",\n"
        . "            'name' => " . aiAssistantLiteral($dbname) . ",\n"
        . "            'user' => " . aiAssistantLiteral($user ?? '') . ",\n"
        . "            'pass' => " . aiAssistantLiteral($password ?? '') . ",\n"
        . "            'port' => " . aiAssistantLiteral($port) . ",\n"
        . ($adapter === 'mysql' ? "            'charset' => 'utf8mb4'," : "            'schema' => 'public',");

$tempConfig = sys_get_temp_dir() . '/ai_assistant_phinx_' . uniqid('', true) . '.php';

$prefixLiteral = aiAssistantLiteral($prefix);
$legacyLiteral = aiAssistantLiteral($legacy);
$migrationsLiteral = aiAssistantLiteral($migrationsDir);
$environmentLiteral = aiAssistantLiteral($environment);

file_put_contents($tempConfig, <<<PHP
<?php
\$_ENV['AI_ASSISTANT_TABLE_PREFIX'] = {$prefixLiteral};
\$_ENV['AI_WHATSAPP_TABLE_PREFIX'] = {$legacyLiteral};
return [
    'paths' => [
        'migrations' => {$migrationsLiteral},
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => {$environmentLiteral},
        {$environmentLiteral} => [
{$connection}
        ],
    ],
    'version_order' => 'creation',
];
PHP
);

/**
 * Everything after the script name travels to phinx, so --dry-run, --change and
 * the other phinx flags work here exactly as they do on the vendor binary. The
 * command is exec'd as an argument list (never through a shell), so a path with
 * spaces stays one argument and nothing needs quoting.
 *
 * @param array<int,string> $argv
 * @return array<int,string>
 */
function aiAssistantPhinxArgs(array $argv, string $phinxPath, string $tempConfig): array
{
    $flags = array_values(array_filter(
        array_slice($argv, 1),
        static fn (string $arg): bool => str_starts_with($arg, '-')
    ));

    return [PHP_BINARY !== '' ? PHP_BINARY : 'php', $phinxPath, 'migrate', '-c', $tempConfig, ...$flags];
}

try {
    $args = aiAssistantPhinxArgs($argv ?? [], $phinxPath, $tempConfig);
    $descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
    $process = proc_open($args, $descriptors, $pipes, $projectRoot);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start: ' . implode(' ', $args));
    }
    if (proc_close($process) !== 0) {
        throw new RuntimeException('phinx exited non-zero');
    }
    echo "AI Assistant migrations ran (prefix: {$prefix}, adapter: {$adapter}).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migrations failed: ' . $e->getMessage() . "\n");
    @unlink($tempConfig);
    exit(1);
}

@unlink($tempConfig);
