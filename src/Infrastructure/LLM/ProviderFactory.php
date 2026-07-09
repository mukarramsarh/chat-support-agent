<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\LLM;

use RuntimeException;
use SupportAI\Domain\LLM\EmbeddingProvider;
use SupportAI\Domain\LLM\LLMProvider;
use SupportAI\Support\Config;
use SupportAI\Support\Http\HttpClient;

/**
 * Builds concrete providers from configuration. This is the ONE place that
 * knows which vendors exist; the rest of the app depends only on the LLMProvider
 * / EmbeddingProvider interfaces. Instances are memoised per provider name.
 */
final class ProviderFactory
{
    /** @var array<string,LLMProvider> */
    private array $chatCache = [];

    private ?EmbeddingProvider $embedder = null;

    public function __construct(
        private Config $config,
        private HttpClient $http,
    ) {
    }

    public function chat(?string $provider = null, ?string $model = null): LLMProvider
    {
        $provider ??= $this->config->string('llm.chat_provider', 'gemini');
        if (isset($this->chatCache[$provider])) {
            return $this->chatCache[$provider];
        }

        $model ??= $this->config->string('llm.chat_model', 'gemini-flash-latest');

        $instance = match ($provider) {
            'gemini'    => new GeminiProvider($this->http, $this->config->string('llm.gemini_key'), $model),
            'openai'    => new OpenAIProvider($this->http, $this->config->string('llm.openai_key'), $model),
            'anthropic' => new AnthropicProvider($this->http, $this->config->string('llm.anthropic_key'), $model),
            default     => throw new RuntimeException("Unknown chat provider: {$provider}"),
        };

        return $this->chatCache[$provider] = $instance;
    }

    /** The cheap model used for rerank / summarize / eval / memory extraction. */
    public function utility(): LLMProvider
    {
        // Utility runs on the same provider family by default but a cheaper model.
        return $this->chat($this->config->string('llm.chat_provider', 'gemini'));
    }

    public function utilityModel(): string
    {
        return $this->config->string('llm.utility_model', 'gemini-flash-lite-latest');
    }

    public function embeddings(): EmbeddingProvider
    {
        if ($this->embedder instanceof EmbeddingProvider) {
            return $this->embedder;
        }

        $provider = $this->config->string('llm.embedding_provider', 'openai');
        $model = $this->config->string('llm.embedding_model', 'text-embedding-3-small');
        $dims = $this->config->int('llm.embedding_dims', 1536);

        return $this->embedder = match ($provider) {
            'openai' => new OpenAIEmbeddingProvider($this->http, $this->config->string('llm.openai_key'), $model, $dims),
            'gemini' => new GeminiEmbeddingProvider($this->http, $this->config->string('llm.gemini_key'), $model, $dims),
            default  => throw new RuntimeException("Unknown embedding provider: {$provider}"),
        };
    }
}
