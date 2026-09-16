<?php

declare(strict_types=1);

namespace ExcelleInsights\AiWhatsapp\Support;

final class EnvLoader
{
    public static function load(?string $envRoot = null): void
    {
        $roots = [
            $envRoot,
            dirname(__DIR__, 5) . '/api', // host maintaina/api/.env when package is vendor
            dirname(__DIR__, 3), // package root
        ];
        foreach ($roots as $root) {
            if (!$root || !is_dir($root)) continue;
            $file = rtrim($root, '/') . '/.env';
            if (!file_exists($file)) continue;
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k); $v = trim($v);
                if (!isset($_ENV[$k])) $_ENV[$k] = $v;
                if (getenv($k) === false) putenv("$k=$v");
            }
            break;
        }
    }
}
