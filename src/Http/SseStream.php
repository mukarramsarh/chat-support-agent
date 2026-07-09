<?php

declare(strict_types=1);

namespace SupportAI\Http;

/**
 * Server-Sent Events writer. SSE (not WebSockets) is the streaming transport
 * because it works over plain HTTP on shared hosting. Each event is a named
 * message with a JSON payload the widget can dispatch on.
 *
 * Events used by the widget:
 *   - "token"  → { text }          incremental answer text
 *   - "meta"   → { conversation_id, citations, ... }
 *   - "done"   → { usage }         final stats
 *   - "error"  → { message }
 */
final class SseStream
{
    private bool $started = false;

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        // Disable buffering layers that would otherwise hold back the stream.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no'); // nginx: disable proxy buffering
        header('Connection: keep-alive');
        @ini_set('zlib.output_compression', '0');
        @set_time_limit(0);
        $this->comment('stream-open');
    }

    public function event(string $event, array $data): void
    {
        $this->start();
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        $this->flush();
    }

    public function token(string $text): void
    {
        $this->event('token', ['text' => $text]);
    }

    public function comment(string $text): void
    {
        $this->start();
        echo ": {$text}\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (function_exists('fastcgi_finish_request') === false) {
            @ob_flush();
        }
        @flush();
    }
}
