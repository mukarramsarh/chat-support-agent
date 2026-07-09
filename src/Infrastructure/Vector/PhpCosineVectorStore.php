<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Vector;

use InvalidArgumentException;
use SupportAI\Domain\Vector\VectorMatch;
use SupportAI\Domain\Vector\VectorStore;
use SupportAI\Infrastructure\Database\Database;

/**
 * Tier 3 — the universal fallback. Embeddings live as LONGBLOB in the same
 * table as the content; similarity is computed in PHP. O(n) per query, so it
 * relies on the caller's FULLTEXT prefilter ($candidateIds) to keep n small.
 * Comfortable to ~10–20k chunks per agent.
 */
final class PhpCosineVectorStore implements VectorStore
{
    /** Hard cap on rows scanned when no candidate set is supplied. */
    private const MAX_SCAN = 5000;

    /** @var array<string,string> namespace → physical table */
    private const TABLES = ['chunks' => 'chunks', 'memories' => 'memories'];

    public function __construct(private Database $db)
    {
    }

    public function driver(): string
    {
        return 'php-cosine';
    }

    public function upsert(string $namespace, array $items): void
    {
        $table = $this->table($namespace);
        foreach ($items as $item) {
            $dims = count($item['vector']);
            $this->db->run(
                "UPDATE {$table} SET embedding = :emb, embed_dims = :dims WHERE id = :id",
                [
                    'emb'  => VectorCodec::pack($item['vector']),
                    'dims' => $dims,
                    'id'   => $item['id'],
                ]
            );
        }
    }

    public function query(string $namespace, array $vector, int $topK, array $filter = [], ?array $candidateIds = null): array
    {
        $table = $this->table($namespace);

        [$where, $params] = $this->buildFilter($filter);
        $where[] = 'embedding IS NOT NULL';

        if ($candidateIds !== null) {
            if ($candidateIds === []) {
                return [];
            }
            $in = implode(',', array_map('intval', $candidateIds));
            $where[] = "id IN ({$in})";
        }

        $sql = "SELECT id, embedding FROM {$table} WHERE " . implode(' AND ', $where)
             . ' LIMIT ' . self::MAX_SCAN;

        $rows = $this->db->all($sql, $params);

        $scored = [];
        foreach ($rows as $row) {
            $stored = VectorCodec::unpack($row['embedding']);
            $scored[] = new VectorMatch((int) $row['id'], VectorCodec::cosine($vector, $stored));
        }

        usort($scored, static fn (VectorMatch $a, VectorMatch $b) => $b->score <=> $a->score);
        return array_slice($scored, 0, $topK);
    }

    public function delete(string $namespace, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $table = $this->table($namespace);
        $in = implode(',', array_map('intval', $ids));
        $this->db->run("UPDATE {$table} SET embedding = NULL WHERE id IN ({$in})");
    }

    /** @return array{0:string[],1:array<string,mixed>} */
    private function buildFilter(array $filter): array
    {
        $where = [];
        $params = [];
        foreach ($filter as $key => $value) {
            if (!preg_match('/^[a-z_]+$/', $key)) {
                continue; // guard against column injection
            }
            $where[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }
        return [$where, $params];
    }

    private function table(string $namespace): string
    {
        return self::TABLES[$namespace]
            ?? throw new InvalidArgumentException("Unknown vector namespace: {$namespace}");
    }
}
