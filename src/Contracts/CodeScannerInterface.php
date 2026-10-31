<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

/**
 * Reads a CRM's own source code and reports the queries it finds.
 *
 * The package ships a PHP scanner; a host whose code is not PHP, or which
 * wants to hide part of it, supplies its own through
 * `services['code_scanner']`.
 *
 * Two steps on purpose: the files are listed first (with a fingerprint each),
 * so a second scan can skip everything that has not changed and never
 * re-parse it.
 */
interface CodeScannerInterface
{
    /**
     * The files a scan would read, with a fingerprint each.
     *
     * @param string[]               $paths   roots to scan
     * @param array<string,mixed>    $options max_files, max_size, max_depth, navigation
     * @return array<int,array{path:string,hash:string,size:int,mtime:int,module:string}>
     */
    public function files(array $paths, array $options = []): array;

    /**
     * The queries inside the given files.
     *
     * @param array<int,array<string,mixed>> $files   entries from files()
     * @param array<string,mixed>            $options
     * @return array<int,array<string,mixed>>
     */
    public function statements(array $files, array $options = []): array;
}
