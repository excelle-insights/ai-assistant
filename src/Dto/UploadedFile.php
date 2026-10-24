<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * An uploaded (or inline base64) file, already read into memory.
 *
 * The package never touches $_FILES or a framework request: a host route passes
 * its own upload through UploadedFile::fromFilesArray() ($_FILES shape) or
 * fromRaw(), and the package enforces size + MIME limits from configuration.
 */
final readonly class UploadedFile
{
    public function __construct(
        public string $name,
        public string $mime,
        public int $size,
        public string $bytes,
        public ?string $storageKey = null,
    ) {
    }

    /** @param array{name:string,type?:string,size?:int,tmp_name?:string,error?:int,contents?:string} $entry */
    public static function fromFilesArray(array $entry, int $maxMb = 25): self
    {
        $error = (int) ($entry['error'] ?? UPLOAD_ERR_OK);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Upload failed (php error code ' . $error . ')');
        }
        $name = self::sanitiseName((string) ($entry['name'] ?? 'upload.bin'));

        if (isset($entry['contents']) && is_string($entry['contents'])) {
            return self::fromRaw($entry['contents'], $name, (string) ($entry['type'] ?? ''));
        }

        $tmp = (string) ($entry['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) {
            throw new \InvalidArgumentException('Upload has no readable source');
        }
        $bytes = (string) file_get_contents($tmp);
        if ($maxMb > 0 && strlen($bytes) > $maxMb * 1048576) {
            throw new \InvalidArgumentException(sprintf('File is %.1f MB, over the %d MB limit', strlen($bytes) / 1048576, $maxMb));
        }
        return self::fromRaw($bytes, $name, (string) ($entry['type'] ?? ''));
    }

    public static function fromRaw(string $bytes, string $name, string $mime = ''): self
    {
        $name = self::sanitiseName($name);
        return new self(
            name: $name,
            mime: $mime !== '' ? $mime : self::detectMime($bytes, $name),
            size: strlen($bytes),
            bytes: $bytes,
        );
    }

    public static function fromPath(string $path): self
    {
        if (!is_readable($path)) {
            throw new \InvalidArgumentException('Cannot read file: ' . $path);
        }
        return self::fromRaw((string) file_get_contents($path), basename($path));
    }

    public static function fromBase64(string $base64, string $name, string $mime = ''): self
    {
        $decoded = base64_decode(preg_replace('#^data:[^;]+;base64,#', '', trim($base64)) ?? '', true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid base64 payload');
        }
        return self::fromRaw($decoded, $name, $mime);
    }

    public function extension(): string
    {
        return strtolower((string) pathinfo($this->name, PATHINFO_EXTENSION));
    }

    public function sha256(): string
    {
        return hash('sha256', $this->bytes);
    }

    /** A .sql / mysqldump payload — usable to train the schema catalog. */
    public function isSchemaDump(): bool
    {
        $head = strtolower(ltrim(substr($this->bytes, 0, 65535)));
        return in_array($this->extension(), ['sql', 'dump'], true)
            || str_contains($this->mime, 'sql')
            || str_contains($head, 'create table');
    }

    /** Guardrails a host route would otherwise have to re-implement. */
    public function check(int $maxMb, array $allowedMime = []): ?string
    {
        if ($this->bytes === '') {
            return 'File is empty';
        }
        if ($maxMb > 0 && $this->size > $maxMb * 1024 * 1024) {
            return sprintf('File is %.1f MB, over the %d MB limit', $this->size / 1048576, $maxMb);
        }
        if ($allowedMime !== []) {
            $norm = array_map(fn ($m) => trim((string) $m), $allowedMime);
            $exts = array_values(array_filter($norm, fn ($m) => !str_contains($m, '/')));
            $mimes = array_values(array_filter($norm, fn ($m) => str_contains($m, '/')));
            $ok = in_array($this->mime, $mimes, true);
            if (!$ok) {
                foreach ($mimes as $pattern) {
                    if (str_ends_with($pattern, '/*') && str_starts_with($this->mime, substr($pattern, 0, -1))) {
                        $ok = true;
                        break;
                    }
                }
            }
            if (!$ok && $exts !== [] && in_array($this->extension(), array_map('strtolower', $exts), true)) {
                $ok = true;
            }
            if (!$ok) {
                return 'Unsupported file type "' . $this->mime . '" (' . $this->name . ')';
            }
        }
        return null;
    }

    private static function detectMime(string $bytes, string $name): string
    {
        $byName = match (strtolower((string) pathinfo($name, PATHINFO_EXTENSION))) {
            'csv' => 'text/csv',
            'tsv' => 'text/tab-separated-values',
            'txt', 'log' => 'text/plain',
            'md', 'markdown' => 'text/markdown',
            'sql' => 'application/sql',
            'json' => 'application/json',
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'html' => 'text/html',
            default => '',
        };
        if ($byName !== '') {
            return $byName;
        }
        if (str_starts_with($bytes, "%PDF")) {
            return 'application/pdf';
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_buffer($finfo, substr($bytes, 0, 4096));
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }
        return 'application/octet-stream';
    }

    private static function sanitiseName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[^\w.\- ]+/u', '_', $base) ?? 'upload';
        return trim($clean) !== '' ? trim($clean) : 'upload';
    }
}
