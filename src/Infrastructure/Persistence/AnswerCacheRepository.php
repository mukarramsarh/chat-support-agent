<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Infrastructure\Database\Database;

/**
 * Exact-match FAQ answer cache. Skips the LLM entirely when the same question is
 * asked again — the single biggest lever for the ~$2/month budget. The cache key
 * folds in kb_version, so any knowledge ingest (which bumps kb_version)
 * transparently invalidates stale answers.
 */
final class AnswerCacheRepository
{
    public function __construct(private Database $db)
    {
    }

    /** Normalise a query so trivial variations hit the same cache entry. */
    public static function normalize(string $query): string
    {
        $q = mb_strtolower(trim($query));
        $q = preg_replace('/\s+/', ' ', $q) ?? $q;
        return rtrim($q, " ?!.،؟");
    }

    private static function key(int $agentId, string $normQuery, int $kbVersion): string
    {
        return hash('sha256', $agentId . '|' . $kbVersion . '|' . $normQuery);
    }

    /** @return array<string,mixed>|null cache row (and bumps hit_count) */
    public function get(int $agentId, string $normQuery, int $kbVersion): ?array
    {
        $row = $this->db->first(
            'SELECT * FROM answer_cache WHERE agent_id = :a AND key_hash = :h',
            ['a' => $agentId, 'h' => self::key($agentId, $normQuery, $kbVersion)]
        );
        if ($row !== null) {
            $this->db->run('UPDATE answer_cache SET hit_count = hit_count + 1, last_hit_at = NOW() WHERE id = :id', ['id' => $row['id']]);
        }
        return $row;
    }

    /** @param array<int,mixed> $citations */
    public function put(int $agentId, string $queryText, string $normQuery, int $kbVersion, string $answer, array $citations): void
    {
        $this->db->run(
            'INSERT INTO answer_cache (agent_id, key_hash, query_text, answer, citations, kb_version)
             VALUES (:a, :h, :q, :ans, :c, :k)
             ON DUPLICATE KEY UPDATE answer = VALUES(answer), citations = VALUES(citations), kb_version = VALUES(kb_version)',
            [
                'a'   => $agentId,
                'h'   => self::key($agentId, $normQuery, $kbVersion),
                'q'   => mb_substr($queryText, 0, 500),
                'ans' => $answer,
                'c'   => $citations ? json_encode($citations) : null,
                'k'   => $kbVersion,
            ]
        );
    }
}
