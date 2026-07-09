<?php

declare(strict_types=1);

namespace SupportAI\Domain\LLM;

/**
 * Embeddings are a separate capability because not every chat provider offers
 * them (Anthropic has none). The active embedding provider + model are locked
 * at first ingest — see EmbeddingLock.
 */
interface EmbeddingProvider
{
    public function name(): string;

    public function model(): string;

    public function dimensions(): int;

    /**
     * Embed a batch of texts. Returns one float vector per input, in order.
     *
     * @param string[] $texts
     * @return array{vectors: float[][], usage: Usage}
     */
    public function embed(array $texts): array;
}
