<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Infrastructure\Database\Database;
use SupportAI\Infrastructure\Vector\VectorCodec;

/**
 * Long-term memories — durable facts about a visitor extracted from past chats
 * (e.g. "on the Pro plan", "prefers Arabic"). Stored with their embedding so a
 * later question can recall the relevant ones. Memories are few per visitor, so
 * similarity is computed in PHP directly here — no external vector store needed
 * (also keeps this personal data local, good for PDPL data residency).
 */
final class MemoryRepository
{
    public function __construct(private Database $db)
    {
    }

    public function addFact(int $agentId, ?string $visitorId, ?int $conversationId, string $content, array $embedding, int $importance = 3, string $kind = 'fact'): int
    {
        $this->db->run(
            'INSERT INTO memories (agent_id, visitor_id, conversation_id, kind, content, embedding, embed_dims, importance)
             VALUES (:a, :v, :c, :k, :content, :emb, :dims, :imp)',
            [
                'a' => $agentId, 'v' => $visitorId, 'c' => $conversationId, 'k' => $kind,
                'content' => $content,
                'emb' => $embedding ? VectorCodec::pack($embedding) : null,
                'dims' => count($embedding),
                'imp' => max(1, min(5, $importance)),
            ]
        );
        return (int) $this->db->lastId();
    }

    /** @return string[] existing fact texts for a visitor (dedupe) */
    public function factTexts(int $agentId, ?string $visitorId): array
    {
        $rows = $this->db->all(
            'SELECT content FROM memories WHERE agent_id = :a AND visitor_id <=> :v AND kind IN (\'fact\',\'preference\')',
            ['a' => $agentId, 'v' => $visitorId]
        );
        return array_map(static fn ($r) => (string) $r['content'], $rows);
    }

    /**
     * Semantic recall of a visitor's facts relevant to $queryVector.
     * Reuses the caller's query embedding — no extra embedding cost.
     *
     * @param float[] $queryVector
     * @return array<int,array{content:string,score:float}>
     */
    public function searchRelevant(int $agentId, ?string $visitorId, array $queryVector, int $limit = 3, float $minScore = 0.2): array
    {
        if ($queryVector === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT content, embedding FROM memories
              WHERE agent_id = :a AND visitor_id <=> :v AND kind IN (\'fact\',\'preference\') AND embedding IS NOT NULL
              LIMIT 500',
            ['a' => $agentId, 'v' => $visitorId]
        );

        $scored = [];
        foreach ($rows as $r) {
            $score = VectorCodec::cosine($queryVector, VectorCodec::unpack((string) $r['embedding']));
            if ($score >= $minScore) {
                $scored[] = ['content' => (string) $r['content'], 'score' => $score];
            }
        }
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }
}
