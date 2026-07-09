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
 * Google Gemini adapter (default chat provider — cheapest capable Flash models).
 *
 * Mapping notes:
 *   • Gemini uses roles "user" / "model"; the system prompt goes in
 *     systemInstruction, not the contents array.
 *   • Streaming uses :streamGenerateContent?alt=sse.
 *   • Structured output uses generationConfig.responseMimeType=application/json
 *     (+ responseSchema when provided).
 *   • Implicit context caching is automatic on Flash; no extra wiring needed.
 */
final class GeminiProvider implements LLMProvider
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private HttpClient $http,
        private string $apiKey,
        private string $defaultModel = 'gemini-flash-latest',
    ) {
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function streamChat(array $messages, array $options, callable $onToken): Usage
    {
        $model = $options['model'] ?? $this->defaultModel;
        $url = self::BASE . "/models/{$model}:streamGenerateContent?alt=sse&key={$this->apiKey}";
        $body = $this->buildBody($messages, $options);

        $usage = new Usage();
        $parser = new SseParser(function (string $data) use ($onToken, &$usage): void {
            $json = json_decode($data, true);
            if (!is_array($json)) {
                return;
            }
            $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if ($text !== '') {
                $onToken($text);
            }
            if (isset($json['usageMetadata'])) {
                $usage = $this->usageFrom($json['usageMetadata']);
            }
        });

        $status = $this->http->stream('POST', $url, [], $body, fn (string $c) => $parser->feed($c));
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Gemini stream failed with HTTP {$status}");
        }
        return $usage;
    }

    public function complete(array $messages, array $options, ?array $jsonSchema = null): Completion
    {
        $model = $options['model'] ?? $this->defaultModel;
        $url = self::BASE . "/models/{$model}:generateContent?key={$this->apiKey}";
        $body = $this->buildBody($messages, $options);

        if ($jsonSchema !== null) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseSchema'] = $jsonSchema;
        }

        $res = $this->http->request('POST', $url, [], $body);
        $res->throwIfError('Gemini completion');
        $data = $res->json();

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $json = $jsonSchema !== null ? json_decode($text, true) : null;

        return new Completion(
            text: $text,
            usage: $this->usageFrom($data['usageMetadata'] ?? []),
            model: $model,
            json: is_array($json) ? $json : null,
        );
    }

    /** @param Message[] $messages */
    private function buildBody(array $messages, array $options): array
    {
        $contents = [];
        $system = [];
        foreach ($messages as $m) {
            if ($m->role === Message::ROLE_SYSTEM) {
                $system[] = ['text' => $m->content];
                continue;
            }
            $contents[] = [
                'role'  => $m->role === Message::ROLE_ASSISTANT ? 'model' : 'user',
                'parts' => [['text' => $m->content]],
            ];
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature'     => $options['temperature'] ?? 0.3,
                'maxOutputTokens' => $options['max_tokens'] ?? 800,
            ],
        ];
        if ($system !== []) {
            $body['systemInstruction'] = ['parts' => $system];
        }
        return $body;
    }

    private function usageFrom(array $meta): Usage
    {
        return new Usage(
            inputTokens: (int) ($meta['promptTokenCount'] ?? 0),
            outputTokens: (int) ($meta['candidatesTokenCount'] ?? 0),
            cachedInputTokens: (int) ($meta['cachedContentTokenCount'] ?? 0),
        );
    }
}
