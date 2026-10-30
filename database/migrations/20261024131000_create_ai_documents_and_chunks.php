<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

use ExcelleInsights\AiAssistant\Support\TablePrefix;

/**
 * Document Q&A and attachment understanding: metadata for the uploaded file
 * (bytes stay in the host's storage via DocumentStoreInterface) plus the chunked,
 * embedded text the retriever actually searches.
 *
 * Chunk embeddings are stored as JSON so a host with no vector database still
 * gets semantic retrieval; ChunkIndexInterface swaps in pgvector/Qdrant when the
 * scale demands it.
 */
final class CreateAiDocumentsAndChunks extends AbstractMigration
{
    public function change(): void
    {
        $prefix = TablePrefix::get();

        $documents = $this->table($prefix . '_documents', ['id' => false, 'primary_key' => ['id']]);
        if (!$documents->exists()) {
            $documents
                ->addColumn('id', 'string', ['limit' => 36, 'null' => false])
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('owner_user_id', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('scope', 'string', ['limit' => 20, 'default' => 'global'])
                ->addColumn('entity_type', 'string', ['limit' => 60, 'null' => true])
                ->addColumn('entity_id', 'string', ['limit' => 60, 'null' => true])
                ->addColumn('filename', 'string', ['limit' => 255])
                ->addColumn('mime', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('size', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('storage_key', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('sha256', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'uploaded'])
                ->addColumn('pages', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('chunk_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('language', 'string', ['limit' => 10, 'null' => true])
                ->addColumn('acl_json', 'text', ['null' => true])
                ->addColumn('metadata_json', 'text', ['null' => true])
                ->addColumn('error', 'text', ['null' => true])
                ->addColumn('indexed_at', 'datetime', ['null' => true])
                ->addColumn('expires_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['company_id', 'status'], ['name' => $prefix . '_doc_status'])
                ->addIndex(['company_id', 'entity_type', 'entity_id'], ['name' => $prefix . '_doc_entity'])
                ->addIndex(['company_id', 'sha256'], ['name' => $prefix . '_doc_hash'])
                ->create();
        }

        $chunks = $this->table($prefix . '_document_chunks');
        if (!$chunks->exists()) {
            $chunks
                ->addColumn('document_id', 'string', ['limit' => 36])
                ->addColumn('company_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('chunk_no', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('page_from', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('page_to', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('content', 'text')
                ->addColumn('token_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('embedding', 'text', ['null' => true])
                ->addColumn('metadata_json', 'text', ['null' => true])
                ->addTimestamps()
                ->addIndex(['document_id', 'chunk_no'], ['unique' => true, 'name' => $prefix . '_chunk_doc'])
                ->addIndex(['company_id'], ['name' => $prefix . '_chunk_tenant'])
                ->create();
        }
    }
}
