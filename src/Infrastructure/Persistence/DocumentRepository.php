<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Infrastructure\Database\Database;

final class DocumentRepository
{
    public function __construct(private Database $db)
    {
    }

    public function create(int $agentId, string $type, string $title, ?string $sourceUri, ?string $hash, int $byteSize): int
    {
        $this->db->run(
            'INSERT INTO documents (agent_id, source_type, title, source_uri, content_hash, byte_size, status)
             VALUES (:a, :t, :title, :uri, :hash, :size, \'processing\')',
            ['a' => $agentId, 't' => $type, 'title' => $title, 'uri' => $sourceUri, 'hash' => $hash, 'size' => $byteSize]
        );
        return (int) $this->db->lastId();
    }

    public function markReady(int $id, int $chunkCount, array $meta = []): void
    {
        $this->db->run(
            'UPDATE documents SET status = \'ready\', chunk_count = :c, metadata = :m, error_message = NULL WHERE id = :id',
            ['c' => $chunkCount, 'm' => $meta ? json_encode($meta) : null, 'id' => $id]
        );
    }

    public function markFailed(int $id, string $error): void
    {
        $this->db->run(
            'UPDATE documents SET status = \'failed\', error_message = :e WHERE id = :id',
            ['e' => mb_substr($error, 0, 500), 'id' => $id]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM documents WHERE id = :id', ['id' => $id]);
    }

    public function delete(int $id): void
    {
        // chunks cascade via FK; Pinecone vectors are cleaned by the caller.
        $this->db->run('DELETE FROM documents WHERE id = :id', ['id' => $id]);
    }

    public function existsByHash(int $agentId, string $hash): bool
    {
        return $this->db->first(
            'SELECT id FROM documents WHERE agent_id = :a AND content_hash = :h LIMIT 1',
            ['a' => $agentId, 'h' => $hash]
        ) !== null;
    }
}
