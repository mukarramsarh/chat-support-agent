<?php

declare(strict_types=1);

namespace SupportAI\Application\Chat;

/**
 * The knowledge + memory assembled for one answer. `contextBlock` is the ready
 * to-inject system text; `citations` map back to source documents; `topScore`
 * lets the chat loop apply the free "decline if nothing relevant" gate.
 */
final class RetrievedContext
{
    /** @param array<int,array{chunk_id:int,document_id:int,title:string,uri:?string}> $citations */
    public function __construct(
        public readonly string $contextBlock = '',
        public readonly array $citations = [],
        public readonly float $topScore = 0.0,
        public readonly bool $hasKnowledge = false,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }
}
