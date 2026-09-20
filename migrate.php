#!/usr/bin/env php
<?php

declare(strict_types=1);

use ExcelleInsights\AiWhatsapp\Support\EnvLoader;

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

$host     = $_ENV['DB_HOST']     ?? null;
$dbname   = $_ENV['DB_NAME']     ?? null;
$user     = $_ENV['DB_USER']     ?? null;
$password = $_ENV['DB_PASSWORD'] ?? '';

if (!$host || !$dbname || !$user) {
    fwrite(STDERR, "Database config incomplete (.env missing DB_HOST/DB_NAME/DB_USER).\n");
    exit(1);
}

$phinxPath = $projectRoot . 'vendor/bin/phinx';
if (!file_exists($phinxPath)) {
    fwrite(STDERR, "Phinx not found at {$phinxPath}\n");
    exit(1);
}

$prefix = $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? 'ai_whatsapp';
$migrationsDir = __DIR__ . '/database/migrations';

$tempConfig = sys_get_temp_dir() . '/ai_whatsapp_phinx_' . uniqid() . '.php';

file_put_contents($tempConfig, <<<PHP
<?php
\$_ENV['AI_WHATSAPP_TABLE_PREFIX'] = '{$prefix}';
return [
    'paths' => [
        'migrations' => '{$migrationsDir}',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => '{$host}',
            'name' => '{$dbname}',
            'user' => '{$user}',
            'pass' => '{$password}',
            'port' => '3306',
            'charset' => 'utf8mb4',
        ],
    ],
    'version_order' => 'creation',
];
PHP
);

function aiWhatsappRunCommand(string $command, string $cwd): void
{
    $descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException("Failed to execute: {$command}");
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Command exited with code {$exitCode}");
    }
}

try {
    aiWhatsappRunCommand("{$phinxPath} migrate -c {$tempConfig}", $projectRoot);
    echo "AI WhatsApp migrations ran successfully!\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migrations failed:\n{$e->getMessage()}\n");
    @unlink($tempConfig);
    exit(1);
}

@unlink($tempConfig);
