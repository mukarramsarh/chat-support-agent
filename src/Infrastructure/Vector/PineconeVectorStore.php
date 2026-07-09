<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Vector;

use SupportAI\Domain\Vector\VectorMatch;
use SupportAI\Domain\Vector\VectorStore;
use SupportAI\Support\Http\HttpClient;

/**
 * Tier 2 — Pinecone serverless. Chunk TEXT stays in MySQL; only vectors + a
 * light metadata copy live in Pinecone. The store's `namespace` maps directly
 * to a Pinecone namespace, keeping knowledge and memory partitioned. Vector ids
 * are the MySQL row ids as strings, so results map straight back to content.
 */
final class PineconeVectorStore implements VectorStore
{
    public function __construct(
        private HttpClient $http,
        private string $apiKey,
        private string $indexHost, // e.g. https://xxx.svc.region.pinecone.io
    ) {
    }

    public function driver(): string
    {
        return 'pinecone';
    }

    private function headers(): array
    {
        return ['Api-Key' => $this->apiKey, 'X-Pinecone-API-Version' => '2025-01'];
    }

    public function upsert(string $namespace, array $items): void
    {
        if ($items === []) {
            return;
        }
        $vectors = array_map(static fn (array $i) => [
            'id'       => (string) $i['id'],
            'values'   => $i['vector'],
            'metadata' => $i['metadata'] ?? [],
        ], $items);

        $res = $this->http->request('POST', $this->indexHost . '/vectors/upsert', $this->headers(), [
            'namespace' => $namespace,
            'vectors'   => $vectors,
        ]);
        $res->throwIfError('Pinecone upsert');
    }

    public function query(string $namespace, array $vector, int $topK, array $filter = [], ?array $candidateIds = null): array
    {
        $body = [
            'namespace'       => $namespace,
            'vector'          => $vector,
            'topK'            => $topK,
            'includeValues'   => false,
            'includeMetadata' => false,
        ];
        if ($filter !== []) {
            // Pinecone metadata filter (e.g. {agent_id: {$eq: 1}}).
            $body['filter'] = array_map(static fn ($v) => ['$eq' => $v], $filter);
        }

        $res = $this->http->request('POST', $this->indexHost . '/query', $this->headers(), $body);
        $res->throwIfError('Pinecone query');
        $data = $res->json();

        return array_map(
            static fn (array $m) => new VectorMatch((int) $m['id'], (float) ($m['score'] ?? 0)),
            $data['matches'] ?? []
        );
    }

    public function delete(string $namespace, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $res = $this->http->request('POST', $this->indexHost . '/vectors/delete', $this->headers(), [
            'namespace' => $namespace,
            'ids'       => array_map('strval', $ids),
        ]);
        $res->throwIfError('Pinecone delete');
    }
}
