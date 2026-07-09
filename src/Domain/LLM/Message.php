<?php

declare(strict_types=1);

namespace SupportAI\Domain\LLM;

/**
 * Provider-agnostic chat message. Each provider adapter maps this to its own
 * wire format (roles, "parts", etc.).
 */
final class Message
{
    public const ROLE_SYSTEM = 'system';
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    public function __construct(
        public readonly string $role,
        public readonly string $content,
        /** When true, adapters that support prompt caching mark this block cacheable. */
        public readonly bool $cacheable = false,
    ) {
    }

    public static function system(string $content, bool $cacheable = false): self
    {
        return new self(self::ROLE_SYSTEM, $content, $cacheable);
    }

    public static function user(string $content): self
    {
        return new self(self::ROLE_USER, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ROLE_ASSISTANT, $content);
    }
}
