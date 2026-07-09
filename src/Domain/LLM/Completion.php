<?php

declare(strict_types=1);

namespace SupportAI\Domain\LLM;

/**
 * Result of a non-streaming completion. `json` is populated when the call was
 * made with a JSON response format (used by the eval loop and memory extraction).
 */
final class Completion
{
    public function __construct(
        public readonly string $text,
        public readonly Usage $usage,
        public readonly string $model,
        public readonly ?array $json = null,
    ) {
    }
}
