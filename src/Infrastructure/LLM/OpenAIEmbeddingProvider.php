<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\LLM;

use RuntimeException;
use SupportAI\Domain\LLM\EmbeddingProvider;
use SupportAI\Domain\LLM\Usage;
use SupportAI\Support\Http\HttpClient;

/**
 * OpenAI embeddings — the default. text-embedding-3-small is cheap, 1536-dim,
 * and supports the `dimensions` param for shrinking if desired.
 */
final class OpenAIEmbeddingProvider implements EmbeddingProvider
{
    private const URL = 'https://api.openai.com/v1/embeddings';

    public function __construct(
        private HttpClient $http,
        private string $apiKey,
        private string $model = 'text-embedding-3-small',
        private int $dimensions = 1536,
    ) {
        if ($this->apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is required for embeddings.');
        }
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return ['vectors' => [], 'usage' => new Usage()];
        }

        $body = ['model' => $this->model, 'input' => array_values($texts)];
        if ($this->dimensions > 0) {
            $body['dimensions'] = $this->dimensions;
        }

        $res = $this->http->request('POST', self::URL, ['Authorization' => "Bearer {$this->apiKey}"], $body);
        $res->throwIfError('OpenAI embeddings');
        $data = $res->json();

        $vectors = [];
        foreach ($data['data'] as $item) {
            $vectors[$item['index']] = array_map('floatval', $item['embedding']);
        }
        ksort($vectors);

        return [
            'vectors' => array_values($vectors),
            'usage'   => new Usage((int) ($data['usage']['prompt_tokens'] ?? 0), 0),
        ];
    }
}
