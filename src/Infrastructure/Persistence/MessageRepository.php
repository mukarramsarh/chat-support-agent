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
     * Older turns are represented by the conversation summary instead.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recentWindow(int $conversationId, int $limit = 10): array
    {
        // LIMIT is inlined as a sanitised int: PDO (emulation off) won't bind it
        // as a string without erroring, and casting makes it injection-safe.
        $limit = max(1, min(100, $limit));
        $rows = $this->db->all(
            'SELECT role, content FROM messages
              WHERE conversation_id = :id AND role IN (\'user\',\'assistant\')
           ORDER BY id DESC LIMIT ' . $limit,
            ['id' => $conversationId]
        );
        return array_reverse($rows);
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
