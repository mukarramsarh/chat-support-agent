<?php

declare(strict_types=1);

namespace SupportAI\Domain\LLM;

/**
 * The one interface the whole application talks to for text generation. Chat
 * orchestration, the eval loop, summarization and memory extraction all depend
 * on THIS, never on a concrete provider — so swapping Gemini↔OpenAI↔Anthropic
 * is a factory decision, not a code change.
 */
interface LLMProvider
{
    public function name(): string;

    /**
     * Stream a chat completion. $onToken is invoked with each text delta as it
     * arrives. Returns the final Usage for cost accounting.
     *
     * @param Message[] $messages
     * @param array{model?:string,temperature?:float,max_tokens?:int} $options
     * @param callable(string):void $onToken
     */
    public function streamChat(array $messages, array $options, callable $onToken): Usage;

    /**
     * Non-streaming completion. When $jsonSchema is provided the provider is
     * asked for structured JSON output (Completion::$json is populated).
     *
     * @param Message[] $messages
     * @param array{model?:string,temperature?:float,max_tokens?:int} $options
     * @param array<string,mixed>|null $jsonSchema
     */
    public function complete(array $messages, array $options, ?array $jsonSchema = null): Completion;
}
