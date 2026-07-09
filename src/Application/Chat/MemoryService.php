<?php

declare(strict_types=1);

namespace SupportAI\Application\Chat;

use SupportAI\Infrastructure\Persistence\MessageRepository;

/**
 * Builds the conversational memory for a turn — deliberately small to keep token
 * usage low (item #12):
 *
 *   • recent  — the last few turns verbatim (short-term working memory).
 *   • relevant — a handful of OLDER messages from this visitor's history that
 *     match the current question (long-term recall), fetched via cheap FULLTEXT
 *     so it costs no tokens to find them.
 *
 * The recent window already contains the just-persisted user message as its last
 * entry; relevant recall is scoped to messages strictly older than that window,
 * so nothing is duplicated.
 */
final class MemoryService
{
    public function __construct(
        private MessageRepository $messages,
        private int $recentTurns = 6,     // ~3 exchanges
        private int $relevantMax = 3,
    ) {
    }

    /**
     * @return array{recent:array<int,array{role:string,content:string}>, relevant:array<int,array{role:string,content:string}>}
     */
    public function build(int $conversationId, ?string $visitorId, string $query): array
    {
        $recent = $this->messages->recentWindow($conversationId, $this->recentTurns);

        $relevant = [];
        if ($visitorId !== null && $visitorId !== '') {
            // Boundary: only recall messages OLDER than the current window.
            $oldestRecentId = $recent[0]['id'] ?? PHP_INT_MAX;
            $relevant = $this->messages->relevantOlder($visitorId, $query, (int) $oldestRecentId, $this->relevantMax);
        }

        return [
            'recent'   => array_map(static fn ($r) => ['role' => $r['role'], 'content' => $r['content']], $recent),
            'relevant' => array_map(static fn ($r) => ['role' => $r['role'], 'content' => $r['content']], $relevant),
        ];
    }
}
