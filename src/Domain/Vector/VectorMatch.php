<?php

declare(strict_types=1);

namespace SupportAI\Domain\Vector;

/**
 * A single similarity hit: the stored item's id and its cosine score (1.0 =
 * identical, 0 = orthogonal). Higher is better regardless of the backing store.
 */
final class VectorMatch
{
    public function __construct(
        public readonly int $id,
        public readonly float $score,
    ) {
    }
}
