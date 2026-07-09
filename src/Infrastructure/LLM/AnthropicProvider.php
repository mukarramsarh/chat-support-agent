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
 * Anthropic Claude adapter (Messages API).
 *
 * Mapping notes:
 *   • The system prompt is a top-level param, not a message. Cacheable system
 *     blocks get cache_control:{type:'ephemeral'} for prompt caching — a big
 *     saving when the persona + knowledge repeat across turns.
 *   • Anthropic has no json_schema response_format, so structured output is
 *     requested by instruction and the first {...} block is parsed out.
 */
final class AnthropicProvider implements LLMProvider
{
    private const BASE = 'https://api.anthropic.com/v1';
    private const VERSION = '2023-06-01';

    public function __construct(
        private HttpClient $http,
        private string $apiKey,
        private string $defaultModel = 'claude-haiku-4-5-20251001',
    ) {
        if ($this->apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }
    }

    public function name(): string
    {
        return 'anthropic';
    }

    private function headers(): array
    {
        return [
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => self::VERSION,
        ];
    }

    public function streamChat(array $messages, array $options, callable $onToken): Usage
    {
        $body = $this->buildBody($messages, $options);
        $body['stream'] = true;

        $input = 0;
        $output = 0;
        $cached = 0;
        $parser = new SseParser(function (string $data) use ($onToken, &$input, &$output, &$cached): void {
            $json = json_decode($data, true);
            if (!is_array($json)) {
                return;
            }
            $type = $json['type'] ?? '';
            if ($type === 'content_block_delta') {
                $text = $json['delta']['text'] ?? '';
                if ($text !== '') {
                    $onToken($text);
                }
            } elseif ($type === 'message_start') {
                $u = $json['message']['usage'] ?? [];
                $input = (int) ($u['input_tokens'] ?? 0);
                $cached = (int) ($u['cache_read_input_tokens'] ?? 0);
            } elseif ($type === 'message_delta') {
                $output = (int) ($json['usage']['output_tokens'] ?? $output);
            }
        });

        $status = $this->http->stream('POST', self::BASE . '/messages', $this->headers(), $body, fn (string $c) => $parser->feed($c));
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Anthropic stream failed with HTTP {$status}");
        }
        return new Usage($input, $output, $cached);
    }

    public function complete(array $messages, array $options, ?array $jsonSchema = null): Completion
    {
        if ($jsonSchema !== null) {
            // Nudge Claude to emit strict JSON; we parse the first object out.
            $messages[] = Message::user('Respond with a single valid JSON object only, matching the requested fields. No prose, no code fences.');
        }
        $body = $this->buildBody($messages, $options);

        $res = $this->http->request('POST', self::BASE . '/messages', $this->headers(), $body);
        $res->throwIfError('Anthropic completion');
        $data = $res->json();

        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        $json = null;
        if ($jsonSchema !== null && preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            $json = is_array($decoded) ? $decoded : null;
        }

        $u = $data['usage'] ?? [];
        return new Completion(
            text: $text,
            usage: new Usage(
                (int) ($u['input_tokens'] ?? 0),
                (int) ($u['output_tokens'] ?? 0),
                (int) ($u['cache_read_input_tokens'] ?? 0),
            ),
            model: $data['model'] ?? ($options['model'] ?? $this->defaultModel),
            json: $json,
        );
    }

    /** @param Message[] $messages */
    private function buildBody(array $messages, array $options): array
    {
        $system = [];
        $turns = [];
        foreach ($messages as $m) {
            if ($m->role === Message::ROLE_SYSTEM) {
                $block = ['type' => 'text', 'text' => $m->content];
                if ($m->cacheable) {
                    $block['cache_control'] = ['type' => 'ephemeral'];
                }
                $system[] = $block;
                continue;
            }
            $turns[] = ['role' => $m->role, 'content' => $m->content];
        }

        $body = [
            'model'       => $options['model'] ?? $this->defaultModel,
            'max_tokens'  => $options['max_tokens'] ?? 800,
            'temperature' => $options['temperature'] ?? 0.3,
            'messages'    => $turns,
        ];
        if ($system !== []) {
            $body['system'] = $system;
        }
        return $body;
    }
}
