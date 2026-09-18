<?php

declare(strict_types=1);

use ExcelleInsights\AiWhatsapp\Support\EnvLoader;

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

EnvLoader::load(__DIR__);

if (!isset($_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'])) {
    fwrite(STDERR, "Database environment variables are not set. Please check your .env file.\n");
    exit(1);
}

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/database/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'production' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'name' => $_ENV['DB_NAME'] ?? 'production_db',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'pass' => $_ENV['DB_PASSWORD'] ?? '',
            'port' => '3306',
            'charset' => 'utf8mb4',
        ],
        'development' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'name' => $_ENV['DB_NAME'] ?? 'development_db',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'pass' => $_ENV['DB_PASSWORD'] ?? '',
            'port' => '3306',
            'charset' => 'utf8mb4',
        ],
        'testing' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'name' => $_ENV['DB_NAME'] ?? 'testing_db',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'pass' => $_ENV['DB_PASSWORD'] ?? '',
            'port' => '3306',
            'charset' => 'utf8mb4',
        ],
    ],
    'version_order' => 'creation',
];
