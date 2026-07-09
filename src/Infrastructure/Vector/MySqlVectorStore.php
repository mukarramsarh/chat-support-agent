<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Vector;

use InvalidArgumentException;
use SupportAI\Domain\Vector\VectorMatch;
use SupportAI\Domain\Vector\VectorStore;
use SupportAI\Infrastructure\Database\Database;

/**
 * Tier 1 — MySQL 9 native VECTOR column with an ANN index. Search runs in the
 * database via DISTANCE(..., 'COSINE'); we also keep the LONGBLOB populated so
 * downgrading back to the PHP path never loses data. Cosine distance is
 * converted to a similarity score (1 - distance) so callers see a uniform scale.
 */
final class MySqlVectorStore implements VectorStore
{
    private const TABLES = ['chunks' => 'chunks', 'memories' => 'memories'];

    public function __construct(private Database $db)
    {
    }

    public function driver(): string
    {
        return 'mysql-vector';
    }

    public function upsert(string $namespace, array $items): void
    {
        $table = $this->table($namespace);
        foreach ($items as $item) {
            $json = VectorCodec::toJson($item['vector']);
            $this->db->run(
                "UPDATE {$table}
                    SET embedding = :emb,
                        embedding_vec = STRING_TO_VECTOR(:vec),
                        embed_dims = :dims
                  WHERE id = :id",
                [
                    'emb'  => VectorCodec::pack($item['vector']),
                    'vec'  => $json,
                    'dims' => count($item['vector']),
                    'id'   => $item['id'],
                ]
            );
        }
    }

    public function query(string $namespace, array $vector, int $topK, array $filter = [], ?array $candidateIds = null): array
    {
        $table = $this->table($namespace);
        $params = ['vec' => VectorCodec::toJson($vector)];

        $where = ['embedding_vec IS NOT NULL'];
        foreach ($filter as $key => $value) {
            if (!preg_match('/^[a-z_]+$/', $key)) {
                continue;
            }
            $where[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        // Native ANN does its own candidate selection; we ignore $candidateIds
        // here and let the index rank, which is faster and higher-recall.
        $sql = "SELECT id, DISTANCE(embedding_vec, STRING_TO_VECTOR(:vec), 'COSINE') AS dist
                  FROM {$table}
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY dist ASC
                 LIMIT " . (int) $topK;

        $rows = $this->db->all($sql, $params);

        return array_map(
            static fn (array $r) => new VectorMatch((int) $r['id'], 1.0 - (float) $r['dist']),
            $rows
        );
    }

    public function delete(string $namespace, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $table = $this->table($namespace);
        $in = implode(',', array_map('intval', $ids));
        $this->db->run("UPDATE {$table} SET embedding = NULL, embedding_vec = NULL WHERE id IN ({$in})");
    }

    private function table(string $namespace): string
    {
        return self::TABLES[$namespace]
            ?? throw new InvalidArgumentException("Unknown vector namespace: {$namespace}");
    }
}
