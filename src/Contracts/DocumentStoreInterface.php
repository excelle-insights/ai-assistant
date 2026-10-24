<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

use ExcelleInsights\AiAssistant\Dto\UploadedFile;

/**
 * Byte storage for uploaded documents. The package stores metadata and chunks in
 * its own tables and never writes files itself: the host keeps blobs in its own
 * disk/S3/database and maps them to a storage key.
 */
interface DocumentStoreInterface
{
    /** Persist the bytes, return an opaque storage key. */
    public function put(UploadedFile $file, string $storageKey): string;

    public function read(string $storageKey): ?string;

    public function exists(string $storageKey): bool;

    public function delete(string $storageKey): bool;

    /** Absolute, host-authenticated path or stream the extractor can read. */
    public function localPath(string $storageKey): ?string;
}
