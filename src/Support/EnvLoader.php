<?php
declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

use Dotenv\Dotenv;

final class EnvLoader
{
    private static bool $loaded = false;

    public static function load(?string $rootPath = null): void
    {
        if (self::$loaded) {
            return;
        }

        if ($rootPath && file_exists($rootPath . '/.env')) {
            Dotenv::createImmutable($rootPath)->safeLoad();
            self::$loaded = true;
            return;
        }

        $vendorDir = dirname(__DIR__, 4);
        $projectRoot = dirname($vendorDir);

        if (file_exists($projectRoot . '/.env')) {
            Dotenv::createImmutable($projectRoot)->safeLoad();
            self::$loaded = true;
            return;
        }

        $packageEnv = dirname(__DIR__, 2) . '/.env';
        if (file_exists($packageEnv)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
            self::$loaded = true;
        }
    }
}
