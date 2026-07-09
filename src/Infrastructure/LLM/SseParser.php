<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\LLM;

/**
 * Incremental parser for `text/event-stream` bodies coming BACK from providers.
 * cURL hands us arbitrary byte chunks; this buffers them and yields complete
 * `data:` payloads to a callback. Handles the "[DONE]" sentinel used by
 * OpenAI-compatible streams.
 */
final class SseParser
{
    private string $buffer = '';

    /** @param callable(string):void $onData receives each raw data payload (JSON string) */
    public function __construct(private $onData)
    {
    }

    public function feed(string $chunk): void
    {
        $this->buffer .= $chunk;

        // Events are separated by a blank line. Process every complete event.
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);
            $line = rtrim($line, "\r");

            if ($line === '' || str_starts_with($line, ':')) {
                continue; // keep-alive comment or separator
            }
            if (str_starts_with($line, 'data:')) {
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }
                ($this->onData)($data);
            }
            // "event:" lines are ignored — payload type is inferred from JSON.
        }
    }
}
