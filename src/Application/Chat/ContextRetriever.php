<?php

declare(strict_types=1);

namespace SupportAI\Application\Chat;

/**
 * Seam between the chat loop and RAG. Phase 0 wires NullRetriever (no knowledge);
 * Phase 2 swaps in the hybrid retrieve→rerank implementation without the chat
 * loop changing at all — clean architecture paying off.
 */
interface ContextRetriever
{
    public function retrieve(int $agentId, ?string $visitorId, string $query): RetrievedContext;
}
