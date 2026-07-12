<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Infrastructure\Database\Database;

final class ChunkRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Insert a chunk row (embedding filled in afterwards by the VectorStore).
     * @param array<string,mixed> $meta
     */
    public function insert(int $documentId, int $agentId, int $ordinal, string $content, int $tokens, string $embedModel, int $embedDims, array $meta): int
    {
        $this->db->run(
            'INSERT INTO chunks (document_id, agent_id, ordinal, content, token_count, embed_model, embed_dims, metadata)
             VALUES (:d, :a, :o, :c, :t, :em, :ed, :m)',
            [
                'd' => $documentId, 'a' => $agentId, 'o' => $ordinal, 'c' => $content,
                't' => $tokens, 'em' => $embedModel, 'ed' => $embedDims,
                'm' => $meta ? json_encode($meta) : null,
            ]
        );
        return (int) $this->db->lastId();
    }

    /** @return int[] chunk ids belonging to a document (for vector-store cleanup) */
    public function idsForDocument(int $documentId): array
    {
        $rows = $this->db->all('SELECT id FROM chunks WHERE document_id = :d', ['d' => $documentId]);
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    /**
     * Delete all chunks for a document (used when re-indexing a refreshed source)
     * and return their ids so the caller can purge external vectors too.
     *
     * @return int[]
     */
    public function deleteForDocument(int $documentId): array
    {
        $ids = $this->idsForDocument($documentId);
        $this->db->run('DELETE FROM chunks WHERE document_id = :d', ['d' => $documentId]);
        return $ids;
    }

    /**
     * Fetch chunks by id (with their document title/uri for citations),
     * returned in the SAME order as $ids (i.e. ranked by the caller).
     *
     * @param int[] $ids
     * @return array<int,array{id:int,document_id:int,content:string,title:string,uri:?string}>
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $rows = $this->db->all(
            "SELECT c.id, c.document_id, c.content, d.title, d.source_uri AS uri
               FROM chunks c
               JOIN documents d ON d.id = c.document_id
              WHERE c.id IN ({$in})"
        );

        // Re-order to match the ranking in $ids.
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = [
                'id'          => (int) $r['id'],
                'document_id' => (int) $r['document_id'],
                'content'     => (string) $r['content'],
                'title'       => (string) $r['title'],
                'uri'         => $r['uri'],
            ];
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }
}
