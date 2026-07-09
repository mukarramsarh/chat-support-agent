<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Domain\LLM\Usage;
use SupportAI\Infrastructure\Database\Database;

final class MessageRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * The most recent turns for a conversation, oldest-first, capped to a window.
     * Older turns are represented by the conversation summary + relevant-memory
     * retrieval instead. Includes id so callers can compute the window boundary.
     *
     * @return array<int,array{id:int,role:string,content:string}>
     */
    public function recentWindow(int $conversationId, int $limit = 10): array
    {
        // LIMIT is inlined as a sanitised int: PDO (emulation off) won't bind it
        // as a string without erroring, and casting makes it injection-safe.
        $limit = max(1, min(100, $limit));
        $rows = $this->db->all(
            'SELECT id, role, content FROM messages
              WHERE conversation_id = :id AND role IN (\'user\',\'assistant\')
           ORDER BY id DESC LIMIT ' . $limit,
            ['id' => $conversationId]
        );
        return array_reverse($rows);
    }

    /**
     * Semantically-relevant OLDER messages from the SAME visitor (across their
     * past conversations), matched by MySQL FULLTEXT against the current query.
     * This is the cheap (no-token) "relevant memory" recall.
     *
     * Degrades safely: returns [] if the FULLTEXT index is missing or the query
     * has no matchable terms (natural-language mode drops very common words).
     *
     * @return array<int,array{role:string,content:string,created_at:string}>
     */
    public function relevantOlder(string $visitorId, string $query, int $beforeId, int $limit = 3): array
    {
        if (trim($query) === '' || $visitorId === '') {
            return [];
        }
        $limit = max(1, min(10, $limit));
        try {
            return $this->db->all(
                "SELECT m.role, m.content, m.created_at
                   FROM messages m
                   JOIN conversations c ON c.id = m.conversation_id
                  WHERE c.visitor_id = :vid
                    AND m.role IN ('user','assistant')
                    AND m.id < :before
                    AND MATCH(m.content) AGAINST (:q IN NATURAL LANGUAGE MODE)
               ORDER BY MATCH(m.content) AGAINST (:q2 IN NATURAL LANGUAGE MODE) DESC
                  LIMIT " . $limit,
                ['vid' => $visitorId, 'before' => $beforeId, 'q' => $query, 'q2' => $query]
            );
        } catch (\Throwable) {
            return []; // no FULLTEXT index / unsupported — memory recall is optional
        }
    }

    /**
     * Every message in a conversation, oldest-first, with full telemetry for the
     * admin session-detail view.
     *
     * @return array<int,array<string,mixed>>
     */
    public function allForConversation(int $conversationId): array
    {
        return $this->db->all(
            'SELECT id, role, content, citations, model, tokens_in, tokens_out, cost_usd, eval, latency_ms, created_at
               FROM messages WHERE conversation_id = :id ORDER BY id ASC',
            ['id' => $conversationId]
        );
    }

    public function addUser(int $conversationId, string $content): int
    {
        $this->db->run(
            'INSERT INTO messages (conversation_id, role, content) VALUES (:c, \'user\', :t)',
            ['c' => $conversationId, 't' => $content]
        );
        return (int) $this->db->lastId();
    }

    /** @param array<int,mixed> $citations @param array<string,mixed> $eval */
    public function addAssistant(
        int $conversationId,
        string $content,
        string $model,
        Usage $usage,
        float $cost,
        array $citations = [],
        array $eval = [],
        ?int $latencyMs = null,
    ): int {
        $this->db->run(
            'INSERT INTO messages
                (conversation_id, role, content, model, tokens_in, tokens_out, cost_usd, citations, eval, latency_ms)
             VALUES
                (:c, \'assistant\', :t, :m, :ti, :to, :cost, :cit, :eval, :lat)',
            [
                'c'    => $conversationId,
                't'    => $content,
                'm'    => $model,
                'ti'   => $usage->inputTokens,
                'to'   => $usage->outputTokens,
                'cost' => $cost,
                'cit'  => $citations ? json_encode($citations) : null,
                'eval' => $eval ? json_encode($eval) : null,
                'lat'  => $latencyMs,
            ]
        );
        return (int) $this->db->lastId();
    }
}
