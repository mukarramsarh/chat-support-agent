<?php

declare(strict_types=1);

namespace SupportAI\Domain\Vector;

/**
 * Abstraction over "find the nearest chunks to this query vector". Three
 * implementations share this contract and are chosen at runtime by a capability
 * probe (see VectorStoreFactory):
 *
 *     MySQL 9 native VECTOR  →  Pinecone (if configured)  →  PHP cosine (always)
 *
 * The retrieval service depends only on this interface. `namespace` scopes a
 * search to either the knowledge base ("chunks") or long-term memory ("memories").
 */
interface VectorStore
{
    public function driver(): string;

    /**
     * Insert or update vectors.
     *
     * @param array<int,array{id:int,vector:float[],metadata?:array}> $items
     */
    public function upsert(string $namespace, array $items): void;

    /**
     * Return the top-K nearest matches to $vector.
     *
     * @param float[] $vector
     * @param array<string,mixed> $filter   e.g. ['agent_id' => 1]
     * @param int[]|null $candidateIds  Optional pre-filtered id set (hybrid search);
     *                                  stores that do their own ANN may ignore it.
     * @return VectorMatch[]
     */
    public function query(string $namespace, array $vector, int $topK, array $filter = [], ?array $candidateIds = null): array;

    /** @param int[] $ids */
    public function delete(string $namespace, array $ids): void;
}
