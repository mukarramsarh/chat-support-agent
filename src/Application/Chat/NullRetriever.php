<?php

declare(strict_types=1);

namespace SupportAI\Application\Chat;

/** Phase-0 placeholder: no knowledge base yet, so answers come from persona only. */
final class NullRetriever implements ContextRetriever
{
    public function retrieve(int $agentId, ?string $visitorId, string $query): RetrievedContext
    {
        return RetrievedContext::empty();
    }
}
