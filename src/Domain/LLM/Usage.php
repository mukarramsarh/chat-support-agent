<?php

declare(strict_types=1);

namespace SupportAI\Domain\LLM;

/**
 * Token accounting for a single provider call. cachedInputTokens is broken out
 * because cached prompt tokens are billed at a large discount and we want the
 * cost dashboard to reflect real spend.
 */
final class Usage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cachedInputTokens = 0,
    ) {
    }

    public function add(Usage $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cachedInputTokens + $other->cachedInputTokens,
        );
    }
}
