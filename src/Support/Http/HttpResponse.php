<?php

declare(strict_types=1);

namespace SupportAI\Support\Http;

use RuntimeException;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Expected JSON response, got: " . substr($this->body, 0, 300));
        }
        return $decoded;
    }

    public function throwIfError(string $context): void
    {
        if (!$this->ok()) {
            throw new RuntimeException("{$context} failed [{$this->status}]: " . substr($this->body, 0, 500));
        }
    }
}
