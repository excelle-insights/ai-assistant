<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Extractors;

/**
 * Shared MIME / extension matching for extractors.
 *
 * Callers hand in a probe which may be a MIME type ('application/pdf',
 * 'text/plain; charset=utf-8') or a bare extension ('docx', '.docx').
 */
trait MatchesType
{
    /**
     * @param string[] $mimes
     * @param string[] $extensions
     */
    protected function matchesType(string $probe, array $mimes, array $extensions): bool
    {
        $probe = strtolower(trim($probe));
        if ($probe === '') {
            return false;
        }
        $probe = trim(explode(';', $probe)[0]);

        if (!str_contains($probe, '/')) {
            $probe = ltrim($probe, '.');
            $wanted = array_map(static fn ($e) => ltrim(strtolower(trim((string) $e)), '.'), $extensions);
            return in_array($probe, $wanted, true);
        }

        foreach ($mimes as $mime) {
            $mime = strtolower(trim(explode(';', (string) $mime)[0]));
            if ($mime === '') {
                continue;
            }
            if ($mime === $probe) {
                return true;
            }
            if (str_ends_with($mime, '/*') && str_starts_with($probe, substr($mime, 0, -1))) {
                return true;
            }
        }
        return false;
    }

    /** Write bytes to a private temp file and return its path. */
    protected function tempFile(string $bytes, string $suffix = ''): ?string
    {
        $path = @tempnam(sys_get_temp_dir(), 'aia-ext-');
        if (!is_string($path) || $path === '') {
            return null;
        }
        if ($suffix !== '' && !str_contains($suffix, '/')) {
            $path .= '.' . ltrim($suffix, '.');
        }
        if (@file_put_contents($path, $bytes) === false) {
            @unlink($path);
            return null;
        }
        return $path;
    }

    /** Clean text: decode nothing, just normalise whitespace runs sensibly. */
    protected function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\x00"], ["\n", "\n", ''], $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * Locate an executable on PATH without shelling out.
     * Absolute/relative paths are accepted as-is.
     */
    protected function executable(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        if (str_contains($name, DIRECTORY_SEPARATOR)) {
            return (is_file($name) && is_executable($name)) ? $name : null;
        }
        $path = (string) (getenv('PATH') ?: ($_SERVER['PATH'] ?? ''));
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $dir = rtrim(trim($dir), DIRECTORY_SEPARATOR);
            if ($dir === '') {
                continue;
            }
            $candidate = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Run a command and return its stdout, or null when exec is unavailable or
     * the command failed — callers then report a needs_extractor condition
     * instead of inventing empty text.
     */
    protected function runCommand(string $command): ?string
    {
        if (!function_exists('exec')) {
            return null;
        }
        $output = [];
        $status = 1;
        @exec($command, $output, $status);
        if ($status !== 0) {
            return null;
        }
        return trim(implode("\n", array_map(static fn ($line) => (string) $line, $output)));
    }
}
