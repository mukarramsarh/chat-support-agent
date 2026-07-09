<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\LLM;

use RuntimeException;
use SupportAI\Domain\LLM\EmbeddingProvider;
use SupportAI\Domain\LLM\Usage;
use SupportAI\Support\Http\HttpClient;

/**
 * Gemini embeddings — the fallback provider (e.g. when you'd rather keep
 * everything on one vendor). gemini-embedding-001 defaults to 768 dims.
 */
final class GeminiEmbeddingProvider implements EmbeddingProvider
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private HttpClient $http,
        private string $apiKey,
        private string $model = 'text-embedding-004',
        private int $dimensions = 768,
    ) {
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is required for Gemini embeddings.');
        }
    }

    public function name(): string
    {
        return 'gemini';
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

        $requests = array_map(fn (string $t) => [
            'model'   => "models/{$this->model}",
            'content' => ['parts' => [['text' => $t]]],
        ], array_values($texts));

        $url = self::BASE . "/models/{$this->model}:batchEmbedContents?key={$this->apiKey}";
        $res = $this->http->request('POST', $url, [], ['requests' => $requests]);
        $res->throwIfError('Gemini embeddings');
        $data = $res->json();

        $vectors = array_map(
            static fn (array $e) => array_map('floatval', $e['values']),
            $data['embeddings'] ?? []
        );

        // Gemini's batch endpoint doesn't return token counts; estimate for the log.
        $estTokens = (int) ceil(array_sum(array_map('mb_strlen', $texts)) / 4);
        return ['vectors' => $vectors, 'usage' => new Usage($estTokens, 0)];
    }
}
