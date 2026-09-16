<?php

foreach ([__DIR__ . '/../../api/.env', __DIR__ . '/.env', __DIR__ . '/../maintaina/api/.env'] as $envFile) {
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
        break;
    }
}

$prefix = $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? 'ai_whatsapp';

$db = [
    'adapter' => $_ENV['DB_DRIVER'] ?? 'mysql',
    'host'    => $_ENV['DB_HOST'] ?? 'localhost',
    'name'    => $_ENV['DB_NAME'] ?? 'maintaina',
    'user'    => $_ENV['DB_USER'] ?? 'root',
    'pass'    => $_ENV['DB_PASSWORD'] ?? '',
    'port'    => $_ENV['DB_PORT'] ?? '3306',
    'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
];

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/database/migrations',
        'seeds'      => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog_ai_whatsapp',
        'default_environment'     => $_ENV['APP_ENV'] ?? 'development',
        'production'              => $db,
        'development'             => $db,
        'testing'                 => $db,
    ],
    'version_order' => 'creation',
];
