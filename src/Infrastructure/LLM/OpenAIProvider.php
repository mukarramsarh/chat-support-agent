<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\LLM;

use RuntimeException;
use SupportAI\Domain\LLM\Completion;
use SupportAI\Domain\LLM\LLMProvider;
use SupportAI\Domain\LLM\Message;
use SupportAI\Domain\LLM\Usage;
use SupportAI\Support\Http\HttpClient;

/**
 * OpenAI (and OpenAI-compatible) chat adapter. Roles map 1:1. Structured output
 * uses response_format json_schema (strict). Usage on streams requires
 * stream_options.include_usage.
 */
final class OpenAIProvider implements LLMProvider
{
    private const BASE = 'https://api.openai.com/v1';

    public function __construct(
        private HttpClient $http,
        private string $apiKey,
        private string $defaultModel = 'gpt-4o-mini',
    ) {
        if ($this->apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }
    }

    public function name(): string
    {
        return 'openai';
    }

    public function listModels(): array
    {
        $res = $this->http->request('GET', self::BASE . '/models', $this->headers());
        $res->throwIfError('OpenAI list models');
        $data = $res->json();

        $out = [];
        foreach ($data['data'] ?? [] as $m) {
            $id = $m['id'] ?? '';
            // Keep chat-capable families; drop embeddings/audio/image/moderation.
            if (!preg_match('/^(gpt-|chatgpt|o\d)/i', $id)) {
                continue;
            }
            if (preg_match('/(embedding|whisper|tts|dall-e|image|audio|realtime|moderation|transcribe|search|instruct)/i', $id)) {
                continue;
            }
            $out[] = ['id' => $id, 'hint' => ModelHint::for($id)];
        }
        return ModelHint::sort($out);
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->apiKey}"];
    }

    public function streamChat(array $messages, array $options, callable $onToken): Usage
    {
        $body = $this->buildBody($messages, $options);
        $body['stream'] = true;
        $body['stream_options'] = ['include_usage' => true];

        $usage = new Usage();
        $parser = new SseParser(function (string $data) use ($onToken, &$usage): void {
            $json = json_decode($data, true);
            if (!is_array($json)) {
                return;
            }
            $delta = $json['choices'][0]['delta']['content'] ?? '';
            if ($delta !== '') {
                $onToken($delta);
            }
            if (isset($json['usage'])) {
                $usage = $this->usageFrom($json['usage']);
            }
        });

        $status = $this->http->stream('POST', self::BASE . '/chat/completions', $this->headers(), $body, fn (string $c) => $parser->feed($c));
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("OpenAI stream failed with HTTP {$status}");
        }
        return $usage;
    }

    public function complete(array $messages, array $options, ?array $jsonSchema = null): Completion
    {
        $body = $this->buildBody($messages, $options);
        if ($jsonSchema !== null) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'response', 'schema' => $jsonSchema, 'strict' => true],
            ];
        }

        $res = $this->http->request('POST', self::BASE . '/chat/completions', $this->headers(), $body);
        $res->throwIfError('OpenAI completion');
        $data = $res->json();

        $text = $data['choices'][0]['message']['content'] ?? '';
        $json = $jsonSchema !== null ? json_decode($text, true) : null;

        return new Completion(
            text: $text,
            usage: $this->usageFrom($data['usage'] ?? []),
            model: $data['model'] ?? ($options['model'] ?? $this->defaultModel),
            json: is_array($json) ? $json : null,
        );
    }

    /** @param Message[] $messages */
    private function buildBody(array $messages, array $options): array
    {
        return [
            'model'       => $options['model'] ?? $this->defaultModel,
            'temperature' => $options['temperature'] ?? 0.3,
            'max_tokens'  => $options['max_tokens'] ?? 800,
            'messages'    => array_map(
                static fn (Message $m) => ['role' => $m->role, 'content' => $m->content],
                $messages
            ),
        ];
    }

    private function usageFrom(array $u): Usage
    {
        return new Usage(
            inputTokens: (int) ($u['prompt_tokens'] ?? 0),
            outputTokens: (int) ($u['completion_tokens'] ?? 0),
            cachedInputTokens: (int) ($u['prompt_tokens_details']['cached_tokens'] ?? 0),
        );
    }
}
