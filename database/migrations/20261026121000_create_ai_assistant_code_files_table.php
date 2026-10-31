<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * {prefix}_code_files — what the package has already read of the CRM's code.
 *
 * One row per scanned file, with the fingerprint that decides whether it must
 * be read again: the content hash, the size and the modification time.
 *
 *   path      absolute path of the file
 *   hash      sha256 of its contents
 *   module    the module it belongs to, as the navigation map named it
 *   statements how many queries were found in it
 *
 * A second scan reads the fingerprint first and only parses the files that
 * changed, so the work a CRM's code causes is paid once, not every night.
 */
final class CreateAiAssistantCodeFilesTable extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();
        $files = $this->table($prefix . '_code_files');
        if ($files->exists()) {
            return;
        }

        $files
            ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('path', 'string', ['limit' => 400])
            ->addColumn('hash', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('size', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('mtime', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('module', 'string', ['limit' => 150, 'default' => ''])
            ->addColumn('statements', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('meta_json', 'text', ['null' => true])
            ->addColumn('updated_by', 'string', ['limit' => 100, 'null' => true])
            ->addTimestamps()
            ->addIndex(['company_id', 'path'], ['unique' => true, 'name' => $prefix . '_code_file_path'])
            ->addIndex(['company_id', 'module'], ['name' => $prefix . '_code_file_module'])
            ->create();
    }
}
